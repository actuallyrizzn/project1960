<?php
declare(strict_types=1);

namespace Project1960\CourtListener;

use Project1960\CourtListenerDocketStore;
use Project1960\CourtListenerMatchReviewStore;
use PDO;

/**
 * Match DOJ seed cases → CourtListener dockets via SDK search (CL-M1).
 */
final class DocketMatcher
{
    public const ACCEPT_MIN = 0.70;
    public const AMBIGUOUS_GAP = 0.15;
    public const REVIEW_MIN = 0.45;

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
        $resp = $this->search->search([
            'q' => $q,
            'type' => 'd',
            'page_size' => 10,
        ]);
        $results = $resp['results'] ?? [];
        if (!is_array($results)) {
            $results = [];
        }

        $scored = [];
        foreach ($results as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = $this->extractDocketId($row);
            if ($id === null) {
                continue;
            }
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

        if ($best !== null && $bestScore >= self::ACCEPT_MIN && $gap >= self::AMBIGUOUS_GAP) {
            if (!$dryRun) {
                $this->persistMatch($caseId, $best, 'auto');
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
     * @return list<array{case_id: string, title: ?string, case_number: ?string, district_office: ?string, date: ?string, party_names: list<string>}>
     */
    public function loadSeeds(int $limit, bool $verifiedOnly = true): array
    {
        $sql = 'SELECT c.id AS case_id, c.title, c.date, m.case_number, m.district_office
                FROM cases c
                LEFT JOIN case_metadata m ON m.case_id = c.id';
        if ($verifiedOnly) {
            $sql .= ' WHERE c.verified_1960 = 1';
        }
        $sql .= ' ORDER BY c.date DESC LIMIT :lim';
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
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
            $out[] = [
                'case_id' => (string) $row['case_id'],
                'title' => $row['title'] !== null ? (string) $row['title'] : null,
                'case_number' => $row['case_number'] !== null ? (string) $row['case_number'] : null,
                'district_office' => $row['district_office'] !== null ? (string) $row['district_office'] : null,
                'date' => $row['date'] !== null ? (string) $row['date'] : null,
                'party_names' => array_map('strval', $names),
            ];
        }

        return $out;
    }

    /**
     * @param array{case_id: string, title?: ?string, case_number?: ?string, district_office?: ?string, date?: ?string, party_names?: list<string>} $seed
     */
    public function buildQuery(array $seed): string
    {
        $parts = [];
        if (!empty($seed['case_number'])) {
            $parts[] = trim((string) $seed['case_number']);
        }
        if (!empty($seed['party_names'][0])) {
            $parts[] = trim((string) $seed['party_names'][0]);
        } elseif (!empty($seed['title'])) {
            $parts[] = trim((string) $seed['title']);
        }
        if (!empty($seed['district_office'])) {
            $parts[] = trim((string) $seed['district_office']);
        }
        $q = trim(implode(' ', $parts));

        return $q !== '' ? $q : '1960';
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
        if ($seedNumber !== '' && $docketNumber !== '') {
            $a = $this->normalizeDocketNumber($seedNumber);
            $b = $this->normalizeDocketNumber($docketNumber);
            if ($a !== '' && $a === $b) {
                $score += 0.55;
            } elseif ($a !== '' && (str_contains($b, $a) || str_contains($a, $b))) {
                $score += 0.35;
            }
        }

        $title = trim((string) ($seed['title'] ?? ''));
        if ($title !== '' && $caseName !== '') {
            similar_text(strtolower($title), strtolower($caseName), $pct);
            $score += min(0.30, $pct / 100.0 * 0.30);
        }

        foreach ($seed['party_names'] ?? [] as $party) {
            $party = trim((string) $party);
            if ($party !== '' && $caseName !== '' && stripos($caseName, $party) !== false) {
                $score += 0.15;
                break;
            }
        }

        $district = strtolower((string) ($seed['district_office'] ?? ''));
        if ($district !== '' && $court !== '') {
            if (str_contains($district, 'southern') && str_contains($court, 'nysd')) {
                $score += 0.10;
            } elseif (str_contains($district, 'eastern') && str_contains($court, 'nyed')) {
                $score += 0.10;
            } elseif (strlen($court) >= 3 && str_contains($district, substr($court, 0, 2))) {
                $score += 0.05;
            }
        }

        return min(1.0, round($score, 4));
    }

    public function normalizeDocketNumber(string $n): string
    {
        return strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $n) ?? '');
    }

    /**
     * @param array<string, mixed> $row
     */
    private function extractDocketId(array $row): ?int
    {
        foreach (['docket_id', 'id', 'cluster_id'] as $key) {
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
