<?php
declare(strict_types=1);

namespace Project1960\CourtListener;

use Project1960\CourtListenerDocketStore;
use Project1960\CourtListenerMatchReviewStore;
use PDO;

/**
 * Match DOJ seed cases → CourtListener dockets via SDK search (CL-M1/M2).
 * Designed for slow-drip CLI (--limit / --wait), not bulk burns.
 *
 * Prototype (tools/cl-match-prototype/, Tasks Doc #1358): known-good court
 * docket numbers should query as normalized YY-cr-NNNN with court filter,
 * auto-accept on docket-core + court match, and collapse duplicate CL ids
 * for the same PACER docket before the ambiguity gap check.
 *
 * No-docket lane (Tasks #3992/#3993): multi-defendant queries, party+court
 * accept on US criminal captions, year proximity, corporate query shape.
 */
final class DocketMatcher
{
    public const ACCEPT_MIN = 0.65;
    public const AMBIGUOUS_GAP = 0.12;
    public const REVIEW_MIN = 0.40;

    /** Core docket + matching court → enough to auto-link (press parties often ≠ lead caption). */
    public const DOCKET_COURT_ACCEPT = 0.70;

    /** Last-name/party + matching court + US criminal caption (no docket token). */
    public const PARTY_COURT_ACCEPT = 0.68;

    /** Max defendant-name searches when no courtish docket (rate-limit friendly). */
    public const MAX_PARTY_QUERIES = 3;

    /** @var array<string, string> district phrase → court_id fragment */
    private const COURT_HINTS = [
        'district of columbia' => 'dcd',
        'southern district of new york' => 'nysd',
        'eastern district of new york' => 'nyed',
        'southern district of california' => 'casd',
        'central district of california' => 'cacd',
        'northern district of california' => 'cand',
        'southern district of texas' => 'txsd',
        'northern district of texas' => 'txnd',
        'eastern district of texas' => 'txed',
        'western district of texas' => 'txwd',
        'southern district of florida' => 'flsd',
        'middle district of florida' => 'flmd',
        'northern district of illinois' => 'ilnd',
        'district of massachusetts' => 'mad',
        'district of new jersey' => 'njd',
        'eastern district of pennsylvania' => 'paed',
        'northern district of georgia' => 'gand',
        'western district of washington' => 'wawd',
        'district of arizona' => 'azd',
        'district of colorado' => 'cod',
        'eastern district of virginia' => 'vaed',
        'district of maryland' => 'mdd',
        'district of minnesota' => 'mnd',
    ];

    public function __construct(
        private SearchGateway $search,
        private CourtListenerDocketStore $dockets,
        private CourtListenerMatchReviewStore $reviews,
        private PDO $pdo,
    ) {
    }

    /**
     * @param array{
     *   case_id: string,
     *   title?: ?string,
     *   case_number?: ?string,
     *   district_office?: ?string,
     *   date?: ?string,
     *   party_names?: list<string>
     * } $seed
     * @return array{
     *   outcome: 'matched'|'ambiguous'|'no_match'|'dry_run_matched'|'dry_run_ambiguous'|'dry_run_no_match',
     *   confidence: float,
     *   cl_docket_id: ?int,
     *   candidates: list<array{cl_docket_id: int, score: float, case_name: ?string, docket_number: ?string}>
     * }
     */
    public function matchOne(array $seed, bool $dryRun = false): array
    {
        $caseId = trim((string) ($seed['case_id'] ?? ''));
        if ($caseId === '') {
            throw new \InvalidArgumentException('case_id required');
        }

        $queries = $this->buildQueries($seed);
        $courtId = $this->courtIdFromDistrict((string) ($seed['district_office'] ?? ''));
        $results = [];
        foreach ($queries as $q) {
            foreach ($this->collectSearchResults($q, $courtId) as $row) {
                $results[] = $row;
            }
        }

        $scored = [];
        $seen = [];
        foreach ($results as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = $this->extractDocketId($row);
            if ($id === null || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $score = $this->scoreCandidate($seed, $row);
            $scored[] = [
                'cl_docket_id' => $id,
                'score' => $score,
                'case_name' => isset($row['caseName']) ? (string) $row['caseName'] : (isset($row['case_name']) ? (string) $row['case_name'] : null),
                'docket_number' => isset($row['docketNumber']) ? (string) $row['docketNumber'] : (isset($row['docket_number']) ? (string) $row['docket_number'] : null),
                'court_id' => isset($row['court_id']) ? (string) $row['court_id'] : (isset($row['court']) ? (string) $row['court'] : null),
                'raw' => $row,
            ];
        }

        // Same PACER docket often has multiple CL ids — collapse before gap check.
        $scored = $this->collapseByDocketCourt($scored);
        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        $candidates = array_map(static function (array $c): array {
            return [
                'cl_docket_id' => $c['cl_docket_id'],
                'score' => $c['score'],
                'case_name' => $c['case_name'],
                'docket_number' => $c['docket_number'],
            ];
        }, $scored);

        $best = $scored[0] ?? null;
        $second = $scored[1] ?? null;
        $bestScore = $best['score'] ?? 0.0;
        $gap = $best && $second ? ($best['score'] - $second['score']) : 1.0;

        $accept = $best !== null && $bestScore >= self::ACCEPT_MIN && $gap >= self::AMBIGUOUS_GAP;
        if ($accept) {
            if (!$dryRun) {
                $method = 'auto';
                $hasCourtish = trim((string) ($seed['case_number'] ?? '')) !== ''
                    && $this->looksLikeCourtDocketNumber((string) $seed['case_number']);
                if ($hasCourtish && $bestScore >= self::DOCKET_COURT_ACCEPT) {
                    $method = 'auto_docket_court';
                } elseif ($this->isPartyCourtAccept($seed, $best)) {
                    $method = 'auto_party_court';
                }
                $this->persistMatch($caseId, $best, $method);
                $this->reviews->clear($caseId);
            }

            return [
                'outcome' => $dryRun ? 'dry_run_matched' : 'matched',
                'confidence' => $bestScore,
                'cl_docket_id' => $best['cl_docket_id'],
                'candidates' => $candidates,
            ];
        }

        $reason = 'no_match';
        if ($best !== null && $bestScore >= self::REVIEW_MIN) {
            $reason = ($gap < self::AMBIGUOUS_GAP) ? 'ambiguous' : 'low_confidence';
        }

        if (!$dryRun) {
            $this->reviews->flag($caseId, $reason, $candidates);
        }

        $prefix = $dryRun ? 'dry_run_' : '';
        $outcome = $reason === 'ambiguous' ? $prefix . 'ambiguous' : $prefix . 'no_match';
        if ($reason === 'low_confidence') {
            $outcome = $prefix . 'ambiguous';
        }

        return [
            'outcome' => $outcome,
            'confidence' => $bestScore,
            'cl_docket_id' => null,
            'candidates' => $candidates,
        ];
    }

    /**
     * @param list<array{cl_docket_id: int, score: float, case_name: ?string, docket_number: ?string, court_id: ?string, raw: array}> $scored
     * @return list<array{cl_docket_id: int, score: float, case_name: ?string, docket_number: ?string, court_id: ?string, raw: array}>
     */
    public function collapseByDocketCourt(array $scored): array
    {
        $collapsed = [];
        foreach ($scored as $c) {
            $dn = (string) ($c['docket_number'] ?? '');
            $core = $this->docketCore($dn);
            $court = strtolower((string) ($c['court_id'] ?? ''));
            $key = ($core !== '' ? $core : 'id:' . $c['cl_docket_id']) . '|' . $court;
            if (!isset($collapsed[$key]) || $c['score'] > $collapsed[$key]['score']) {
                $collapsed[$key] = $c;
            }
        }

        return array_values($collapsed);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collectSearchResults(string $q, ?string $courtId = null): array
    {
        $out = [];
        foreach (['d', 'r'] as $type) {
            $params = [
                'q' => $q,
                'type' => $type,
                'page_size' => 10,
            ];
            if ($courtId !== null && $courtId !== '') {
                $params['court'] = $courtId;
            }
            $resp = $this->search->search($params);
            $chunk = $resp['results'] ?? [];
            if (is_array($chunk)) {
                foreach ($chunk as $row) {
                    $out[] = $row;
                }
            }
        }

        return $out;
    }

    /**
     * Unmatched seeds for slow-drip: skips cases already linked to a CL docket
     * or already flagged in cl_match_reviews (so cron advances instead of re-hitting the same set).
     * Prefer verified rows whose case_number looks like a court docket (known-good first).
     *
     * @return list<array{case_id: string, title: ?string, case_number: ?string, district_office: ?string, date: ?string, party_names: list<string>}>
     */
    public function loadSeeds(int $limit, bool $verifiedOnly = true): array
    {
        $fetch = max($limit * 25, 40);
        $sql = 'SELECT c.id AS case_id, c.title, c.date, c.number AS case_number_fallback,
                       m.case_number, m.district_office
                FROM cases c
                LEFT JOIN case_metadata m ON m.case_id = c.id
                LEFT JOIN case_courtlistener_links l ON l.case_id = c.id
                LEFT JOIN cl_match_reviews r ON r.case_id = c.id
                WHERE l.case_id IS NULL AND r.case_id IS NULL';
        if ($verifiedOnly) {
            $sql .= ' AND c.verified_1960 = 1';
        }
        $sql .= ' ORDER BY c.date DESC LIMIT :lim';
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':lim', $fetch, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $out = [];
        foreach ($rows as $row) {
            $parties = $this->pdo->prepare(
                "SELECT name FROM participants WHERE case_id = :id AND role = 'defendant' LIMIT 5"
            );
            $parties->bindValue(':id', $row['case_id']);
            $parties->execute();
            $names = $parties->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $caseNumber = $row['case_number'] !== null && trim((string) $row['case_number']) !== ''
                ? (string) $row['case_number']
                : ($row['case_number_fallback'] !== null ? (string) $row['case_number_fallback'] : null);
            $out[] = [
                'case_id' => (string) $row['case_id'],
                'title' => $row['title'] !== null ? (string) $row['title'] : null,
                'case_number' => $caseNumber,
                'district_office' => $row['district_office'] !== null ? (string) $row['district_office'] : null,
                'date' => $row['date'] !== null ? (string) $row['date'] : null,
                'party_names' => array_map('strval', $names),
            ];
        }

        usort($out, function (array $a, array $b): int {
            $aGood = $this->looksLikeCourtDocketNumber((string) ($a['case_number'] ?? '')) ? 0 : 1;
            $bGood = $this->looksLikeCourtDocketNumber((string) ($b['case_number'] ?? '')) ? 0 : 1;
            if ($aGood !== $bGood) {
                return $aGood <=> $bGood;
            }

            return strcmp((string) ($b['date'] ?? ''), (string) ($a['date'] ?? ''));
        });

        return array_slice($out, 0, $limit);
    }

    /**
     * Docket-first when courtish; otherwise one query per defendant (capped) /
     * corporate shape / title fallback. Prefer buildQueries for matching.
     *
     * @param array{case_id?: string, title?: ?string, case_number?: ?string, district_office?: ?string, date?: ?string, party_names?: list<string>} $seed
     */
    public function buildQuery(array $seed): string
    {
        $queries = $this->buildQueries($seed);

        return $queries[0] ?? '1960';
    }

    /**
     * @param array{case_id?: string, title?: ?string, case_number?: ?string, district_office?: ?string, date?: ?string, party_names?: list<string>} $seed
     * @return list<string>
     */
    public function buildQueries(array $seed): array
    {
        $caseNumber = trim((string) ($seed['case_number'] ?? ''));
        if ($caseNumber !== '' && $this->looksLikeCourtDocketNumber($caseNumber)) {
            $q = $this->hyphenatedDocketQuery($caseNumber);
            if ($q !== '') {
                return [$q];
            }
        }

        $out = [];
        $seen = [];
        $parties = $seed['party_names'] ?? [];
        if (!is_array($parties)) {
            $parties = [];
        }
        $n = 0;
        foreach ($parties as $party) {
            $party = trim((string) $party);
            if ($party === '') {
                continue;
            }
            $q = $this->isCorporateParty($party)
                ? $this->corporateQuery($party)
                : $party;
            $key = strtolower($q);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = $q;
                $n++;
            }
            if ($n >= self::MAX_PARTY_QUERIES) {
                break;
            }
        }

        if ($out === [] && !empty($seed['title'])) {
            $title = trim((string) $seed['title']);
            if (preg_match('/\bv\.?\s+(.+)$/i', $title, $m)) {
                $out[] = trim($m[1]);
            } else {
                $out[] = mb_substr($title, 0, 60);
            }
        }

        return $out !== [] ? $out : ['1960'];
    }

    public function isCorporateParty(string $name): bool
    {
        return (bool) preg_match(
            '/\b(inc\.?|llc|l\.l\.c\.|corp\.?|corporation|ltd\.?|limited|company|co\.|services|bank|group|holdings|plc)\b/i',
            $name
        );
    }

    /** Quoted firm name for CL search (bare names pull civil noise). */
    public function corporateQuery(string $name): string
    {
        $name = trim($name);
        // Strip curly apostrophes for search
        $name = str_replace(["\u{2019}", '’'], "'", $name);
        $core = preg_replace('/,?\s+(inc\.?|llc|l\.l\.c\.|corp\.?|corporation|ltd\.?|limited|co\.)\s*$/i', '', $name) ?? $name;
        $core = trim($core, " \t\"'");
        if ($core === '') {
            $core = $name;
        }

        return '"' . $core . '"';
    }

    public function looksLikeCourtDocketNumber(string $n): bool
    {
        if ($this->docketCore($n) !== '') {
            return true;
        }

        return (bool) preg_match('/\d+:\d+|\d+\s*[-.]?\s*(cr|cv|misc|md)/i', $n);
    }

    public function courtIdFromDistrict(string $district): ?string
    {
        $district = strtolower(trim($district));
        if ($district === '') {
            return null;
        }
        if (isset(self::COURT_HINTS[$district])) {
            return self::COURT_HINTS[$district];
        }
        foreach (self::COURT_HINTS as $phrase => $id) {
            if (str_contains($district, $phrase)) {
                return $id;
            }
        }

        return null;
    }

    /**
     * Strip wrappers / judge initials / take first of a multi-docket list.
     */
    public function primaryDocketToken(string $n): string
    {
        $n = trim($n);
        if ($n === '') {
            return '';
        }
        if (str_contains($n, ',')) {
            $n = trim(explode(',', $n, 2)[0]);
        }
        $n = preg_replace('/^[A-Z](?:\.?[A-Z]){1,3}\.?\s*Docket\s+No\.?\s*/i', '', $n) ?? $n;
        $n = preg_replace('/\([^)]*\)/', '', $n) ?? $n;
        $n = trim($n);
        // 18-cr-1129-GPC / 20-CR-369-JLS
        if (preg_match('/^(.+?)-([A-Z]{2,4})$/', $n, $m) && $this->docketCoreRaw($m[1]) !== '') {
            $n = $m[1];
        }
        // 22cr1551RBM glued initials
        if (preg_match('/^(\d+\s*(?:cr|cv|misc|md)\.?\s*\d+)[A-Za-z]{2,4}$/i', $n, $m)) {
            $n = $m[1];
        }

        return trim($n);
    }

    /** Rebuild searchable YY-cr-NNNN from any seed form. */
    public function hyphenatedDocketQuery(string $n): string
    {
        $token = $this->primaryDocketToken($n);
        $core = $this->docketCoreRaw($token !== '' ? $token : $n);
        if ($core !== '' && preg_match('/^(cr|cv|misc|md)(\d{1,2})(\d+)$/i', $core, $m)) {
            return sprintf('%d-%s-%d', (int) $m[2], strtolower($m[1]), (int) $m[3]);
        }

        return $token;
    }

    /**
     * @param array<string, mixed> $row
     */
    public function scoreCandidate(array $seed, array $row): float
    {
        $score = 0.0;
        $docketNumber = (string) ($row['docketNumber'] ?? $row['docket_number'] ?? '');
        $caseName = (string) ($row['caseName'] ?? $row['case_name'] ?? '');
        $court = strtolower((string) ($row['court_id'] ?? $row['court'] ?? ''));

        $seedNumber = trim((string) ($seed['case_number'] ?? ''));
        $seedCore = $seedNumber !== '' ? $this->docketCore($seedNumber) : '';
        $hitCore = $docketNumber !== '' ? $this->docketCore($docketNumber) : '';
        $courtHint = $this->courtIdFromDistrict((string) ($seed['district_office'] ?? ''));
        $courtMatch = $courtHint !== null && $court !== '' && str_contains($court, $courtHint);
        $usCriminal = $this->isUsCriminalCaption($caseName);
        $nature = $this->docketNature($docketNumber);

        if ($seedNumber !== '' && $this->looksLikeCourtDocketNumber($seedNumber) && $docketNumber !== '') {
            $a = $this->normalizeDocketNumber($this->primaryDocketToken($seedNumber));
            $b = $this->normalizeDocketNumber($docketNumber);
            $coreHit = $seedCore !== '' && $seedCore === $hitCore;

            if ($coreHit && $courtMatch) {
                // Known-good path: exact/core docket in the right court.
                $score = max($score, self::DOCKET_COURT_ACCEPT);
            } elseif ($a !== '' && $a === $b) {
                $score += 0.50;
            } elseif ($a !== '' && (str_contains($b, $a) || str_contains($a, $b))) {
                $score += 0.35;
            } elseif ($coreHit) {
                $score += 0.40;
            }
        }

        $partyHit = 'none';
        foreach ($seed['party_names'] ?? [] as $party) {
            $party = trim((string) $party);
            if ($party === '' || $caseName === '') {
                continue;
            }
            if (stripos($caseName, $party) !== false) {
                $score += 0.45;
                $partyHit = 'full';
                break;
            }
            $last = $this->lastName($party);
            if ($last !== '' && preg_match('/\b' . preg_quote($last, '/') . '\b/i', $caseName)) {
                // Last-name alone is weaker unless it's a US criminal caption in the right court.
                $score += ($usCriminal && $courtMatch) ? 0.40 : 0.30;
                $partyHit = 'last';
                break;
            }
        }

        $title = trim((string) ($seed['title'] ?? ''));
        if ($title !== '' && $caseName !== '') {
            similar_text(strtolower($title), strtolower($caseName), $pct);
            $score += min(0.15, $pct / 100.0 * 0.15);
        }

        if ($courtMatch && $score < self::DOCKET_COURT_ACCEPT) {
            $score += 0.15;
        } elseif (!$courtMatch && $court !== '') {
            $district = strtolower(trim((string) ($seed['district_office'] ?? '')));
            if ($district !== '' && strlen($court) >= 3 && str_contains($district, substr($court, 0, 2))) {
                $score += 0.05;
            }
        }

        // Nature: prefer felony/criminal docket numbers over civil / bare appeals.
        if ($nature === 'cr') {
            $score += 0.08;
        } elseif ($nature === 'cv') {
            $score -= 0.12;
        } elseif ($nature === 'mj') {
            $score -= 0.03;
        } elseif ($nature === 'other' && $docketNumber !== '') {
            // Appellate-style bare numbers (18-10116) — weak for DOJ press matches.
            $score -= 0.10;
        }

        $seedYear = $this->yearFromSeedDate(isset($seed['date']) ? (string) $seed['date'] : null);
        $candYear = $this->yearFromCandidate($row, $docketNumber);
        if ($seedYear !== null && $candYear !== null) {
            $delta = abs($seedYear - $candYear);
            if ($delta === 0) {
                $score += 0.12;
            } elseif ($delta === 1) {
                $score += 0.08;
            } elseif ($delta === 2) {
                $score += 0.04;
            } elseif ($delta >= 4) {
                $score -= 0.18;
            }
        }

        // No-docket strong path: party in caption + right court + US criminal + criminal nature.
        $hasCourtish = $seedNumber !== '' && $this->looksLikeCourtDocketNumber($seedNumber);
        if (
            !$hasCourtish
            && $partyHit !== 'none'
            && $courtMatch
            && $usCriminal
            && $nature === 'cr'
            && ($seedYear === null || $candYear === null || abs($seedYear - $candYear) <= 2)
        ) {
            $score = max($score, self::PARTY_COURT_ACCEPT);
        }

        return min(1.0, max(0.0, round($score, 4)));
    }

    public function isUsCriminalCaption(string $caseName): bool
    {
        return (bool) preg_match('/^\s*(united\s+states|u\.?\s*s\.?a?\.?)\s+v\.?\s+/i', $caseName);
    }

    /** @return 'cr'|'cv'|'mj'|'misc'|'md'|'other'|'' */
    public function docketNature(string $docketNumber): string
    {
        if ($docketNumber === '') {
            return '';
        }
        if (preg_match('/\bcr\b/i', $docketNumber) || preg_match('/\d+cr\d+/i', $docketNumber)) {
            return 'cr';
        }
        if (preg_match('/\bcv\b/i', $docketNumber) || preg_match('/\d+cv\d+/i', $docketNumber)) {
            return 'cv';
        }
        if (preg_match('/\bmj\b/i', $docketNumber) || preg_match('/\d+mj\d+/i', $docketNumber)) {
            return 'mj';
        }
        if (preg_match('/\bmisc\b/i', $docketNumber)) {
            return 'misc';
        }
        if (preg_match('/\bmd\b/i', $docketNumber)) {
            return 'md';
        }

        return 'other';
    }

    public function yearFromSeedDate(?string $date): ?int
    {
        if ($date === null || trim($date) === '') {
            return null;
        }
        $date = trim($date);
        if (ctype_digit($date) && strlen($date) >= 9) {
            $ts = (int) $date;
            if ($ts > 1_000_000_000) {
                return (int) gmdate('Y', $ts);
            }
        }
        if (preg_match('/^(19|20)\d{2}/', $date, $m)) {
            return (int) substr($date, 0, 4);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     */
    public function yearFromCandidate(array $row, string $docketNumber = ''): ?int
    {
        foreach (['dateFiled', 'date_filed', 'dateArgued', 'date_argued'] as $k) {
            if (!empty($row[$k]) && is_string($row[$k]) && preg_match('/^(19|20)\d{2}/', $row[$k])) {
                return (int) substr($row[$k], 0, 4);
            }
        }
        // PACER office:YY-cr-NNNN → 20YY (federal dockets post-2000 for our corpus)
        if (preg_match('/(?:^|[^\d])(\d{2})-(?:cr|cv|mj|misc|md)-/i', $docketNumber, $m)) {
            $yy = (int) $m[1];

            return $yy >= 70 ? 1900 + $yy : 2000 + $yy;
        }
        if (preg_match('/:(\d{2})-(?:cr|cv|mj)/i', $docketNumber, $m)) {
            $yy = (int) $m[1];

            return $yy >= 70 ? 1900 + $yy : 2000 + $yy;
        }

        return null;
    }

    /**
     * @param array{case_name: ?string, docket_number: ?string, court_id: ?string, score?: float} $best
     */
    public function isPartyCourtAccept(array $seed, array $best): bool
    {
        $seedNumber = trim((string) ($seed['case_number'] ?? ''));
        if ($seedNumber !== '' && $this->looksLikeCourtDocketNumber($seedNumber)) {
            return false;
        }
        $caseName = (string) ($best['case_name'] ?? '');
        $docketNumber = (string) ($best['docket_number'] ?? '');
        $court = strtolower((string) ($best['court_id'] ?? ''));
        $courtHint = $this->courtIdFromDistrict((string) ($seed['district_office'] ?? ''));
        if ($courtHint === null || $court === '' || !str_contains($court, $courtHint)) {
            return false;
        }
        if (!$this->isUsCriminalCaption($caseName) || $this->docketNature($docketNumber) !== 'cr') {
            return false;
        }
        foreach ($seed['party_names'] ?? [] as $party) {
            $party = trim((string) $party);
            if ($party === '') {
                continue;
            }
            if (stripos($caseName, $party) !== false) {
                return true;
            }
            $last = $this->lastName($party);
            if ($last !== '' && preg_match('/\b' . preg_quote($last, '/') . '\b/i', $caseName)) {
                return true;
            }
        }

        return false;
    }

    public function normalizeDocketNumber(string $n): string
    {
        return strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $n) ?? '');
    }

    public function docketCore(string $n): string
    {
        $cleaned = $this->primaryDocketToken($n);

        return $this->docketCoreRaw($cleaned !== '' ? $cleaned : $n);
    }

    /** Regex-only core parse (no primaryDocketToken — avoids recursion). */
    private function docketCoreRaw(string $n): string
    {
        // Allow "19 Cr. 838", "18-cr-1129", "1:19-cr-00838"
        if (preg_match('/(\d+)\s*[-:]?\s*(cr|cv|misc|md)\.?\s*[-:]?\s*0*(\d+)/i', $n, $m)) {
            return strtolower($m[2] . $m[1] . (int) $m[3]);
        }
        if (preg_match('/(\d+)\s*(cr|cv|misc|md)\.?\s*0*(\d+)/i', $n, $m)) {
            return strtolower($m[2] . $m[1] . (int) $m[3]);
        }

        return '';
    }

    public function lastName(string $full): string
    {
        $full = trim(preg_replace('/\s+(jr\.?|sr\.?|ii|iii|iv)$/i', '', $full) ?? $full);
        $parts = preg_split('/\s+/', $full) ?: [];
        $last = (string) end($parts);

        return strtolower($last);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function extractDocketId(array $row): ?int
    {
        foreach (['docket_id', 'id'] as $key) {
            if (isset($row[$key]) && is_numeric($row[$key])) {
                $id = (int) $row[$key];
                if ($id > 0) {
                    return $id;
                }
            }
        }
        if (isset($row['docket']) && is_string($row['docket']) && preg_match('#/(\d+)/?#', $row['docket'], $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * @param array{cl_docket_id: int, score: float, case_name: ?string, docket_number: ?string, court_id: ?string, raw: array} $best
     */
    private function persistMatch(string $caseId, array $best, string $method): void
    {
        $this->dockets->upsertDocket([
            'cl_docket_id' => $best['cl_docket_id'],
            'court_id' => $best['court_id'],
            'docket_number' => $best['docket_number'],
            'case_name' => $best['case_name'],
            'raw_json' => json_encode($best['raw'], JSON_THROW_ON_ERROR),
        ]);
        $this->dockets->upsertCaseLink([
            'case_id' => $caseId,
            'cl_docket_id' => $best['cl_docket_id'],
            'match_confidence' => $best['score'],
            'match_method' => $method,
        ]);
    }
}
