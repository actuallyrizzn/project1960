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
 */
final class DocketMatcher
{
    public const ACCEPT_MIN = 0.65;
    public const AMBIGUOUS_GAP = 0.12;
    public const REVIEW_MIN = 0.40;

    /** Core docket + matching court → enough to auto-link (press parties often ≠ lead caption). */
    public const DOCKET_COURT_ACCEPT = 0.70;

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

        $q = $this->buildQuery($seed);
        $courtId = $this->courtIdFromDistrict((string) ($seed['district_office'] ?? ''));
        $results = $this->collectSearchResults($q, $courtId);

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
                $method = $bestScore >= self::DOCKET_COURT_ACCEPT ? 'auto_docket_court' : 'auto';
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
     * Docket-first query for courtish numbers (normalized YY-cr-NNNN).
     * Party-first diluted multi-defendant cases and burned rate limit on weak hits.
     *
     * @param array{case_id?: string, title?: ?string, case_number?: ?string, district_office?: ?string, date?: ?string, party_names?: list<string>} $seed
     */
    public function buildQuery(array $seed): string
    {
        $caseNumber = trim((string) ($seed['case_number'] ?? ''));
        if ($caseNumber !== '' && $this->looksLikeCourtDocketNumber($caseNumber)) {
            $q = $this->hyphenatedDocketQuery($caseNumber);
            if ($q !== '') {
                return $q;
            }
        }

        $parts = [];
        if (!empty($seed['party_names'][0])) {
            $parts[] = trim((string) $seed['party_names'][0]);
        } elseif (!empty($seed['title'])) {
            $title = trim((string) $seed['title']);
            if (preg_match('/\bv\.?\s+(.+)$/i', $title, $m)) {
                $parts[] = trim($m[1]);
            } else {
                $parts[] = mb_substr($title, 0, 60);
            }
        }
        $q = trim(implode(' ', $parts));

        return $q !== '' ? $q : '1960';
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

        foreach ($seed['party_names'] ?? [] as $party) {
            $party = trim((string) $party);
            if ($party === '' || $caseName === '') {
                continue;
            }
            if (stripos($caseName, $party) !== false) {
                $score += 0.45;
                break;
            }
            $last = $this->lastName($party);
            if ($last !== '' && preg_match('/\b' . preg_quote($last, '/') . '\b/i', $caseName)) {
                $score += 0.30;
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

        return min(1.0, round($score, 4));
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
