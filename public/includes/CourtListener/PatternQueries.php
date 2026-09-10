<?php
declare(strict_types=1);

namespace Project1960\CourtListener;

use PDO;

/**
 * Cross-case pattern queries (CL-P1 / CL-P2).
 */
final class PatternQueries
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Persons linked to ≥ $minCases cases.
     *
     * @return list<array{person_id: int, display_name: string, role: string, case_count: int, case_ids: list<string>}>
     */
    public function multiCasePersons(int $minCases = 2, int $limit = 100): array
    {
        $minCases = max(2, $minCases);
        $limit = max(1, $limit);
        $sql = <<<SQL
SELECT p.id AS person_id, p.display_name, p.role,
       COUNT(DISTINCT e.case_id) AS case_count,
       GROUP_CONCAT(DISTINCT e.case_id) AS case_ids
FROM cl_persons p
JOIN cl_person_case_edges e ON e.person_id = p.id
GROUP BY p.id
HAVING case_count >= {$minCases}
ORDER BY case_count DESC, p.display_name ASC
LIMIT {$limit}
SQL;
        try {
            $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\PDOException) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $ids = array_values(array_filter(explode(',', (string) ($row['case_ids'] ?? ''))));
            $out[] = [
                'person_id' => (int) $row['person_id'],
                'display_name' => (string) ($row['display_name'] ?? ''),
                'role' => (string) ($row['role'] ?? 'other'),
                'case_count' => (int) $row['case_count'],
                'case_ids' => $ids,
            ];
        }

        return $out;
    }

    /**
     * Search person by name (normalized / alias) → cases.
     *
     * @return list<array{person_id: int, display_name: string, role: string, case_id: string, edge_role: ?string}>
     */
    public function casesForPersonName(string $name): array
    {
        $n = \Project1960\CourtListenerPersonStore::normalizeName($name);
        if ($n === '') {
            return [];
        }
        try {
            $stmt = $this->pdo->prepare(
                'SELECT p.id AS person_id, p.display_name, p.role, e.case_id, e.role AS edge_role
                 FROM cl_persons p
                 JOIN cl_person_case_edges e ON e.person_id = p.id
                 WHERE p.id IN (
                    SELECT id FROM cl_persons WHERE normalized_name = :n
                    UNION
                    SELECT person_id FROM cl_person_aliases WHERE alias_normalized = :n2
                 )
                 ORDER BY e.case_id'
            );
            $stmt->bindValue(':n', $n);
            $stmt->bindValue(':n2', $n);
            $stmt->execute();
        } catch (\PDOException) {
            return [];
        }

        return array_map(static function (array $r): array {
            return [
                'person_id' => (int) $r['person_id'],
                'display_name' => (string) $r['display_name'],
                'role' => (string) $r['role'],
                'case_id' => (string) $r['case_id'],
                'edge_role' => $r['edge_role'] !== null ? (string) $r['edge_role'] : null,
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Attorneys appearing on ≥2 cases.
     *
     * @return list<array{person_id: int, display_name: string, organization: ?string, case_count: int, case_ids: list<string>}>
     */
    public function sharedAttorneys(int $minCases = 2, int $limit = 100): array
    {
        $minCases = max(2, $minCases);
        $limit = max(1, $limit);
        $sql = <<<SQL
SELECT p.id AS person_id, p.display_name, p.organization,
       COUNT(DISTINCT e.case_id) AS case_count,
       GROUP_CONCAT(DISTINCT e.case_id) AS case_ids
FROM cl_persons p
JOIN cl_person_case_edges e ON e.person_id = p.id
WHERE p.role = 'attorney'
GROUP BY p.id
HAVING case_count >= {$minCases}
ORDER BY case_count DESC
LIMIT {$limit}
SQL;
        try {
            $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\PDOException) {
            return [];
        }

        return $this->mapShared($rows);
    }

    /**
     * Districts / courts appearing across linked dockets (by court_id).
     *
     * @return list<array{court_id: string, docket_count: int, case_count: int}>
     */
    public function sharedDistricts(int $limit = 100): array
    {
        $limit = max(1, $limit);
        $sql = <<<SQL
SELECT d.court_id,
       COUNT(DISTINCT d.cl_docket_id) AS docket_count,
       COUNT(DISTINCT l.case_id) AS case_count
FROM courtlistener_dockets d
LEFT JOIN case_courtlistener_links l ON l.cl_docket_id = d.cl_docket_id
WHERE d.court_id IS NOT NULL AND TRIM(d.court_id) != ''
GROUP BY d.court_id
ORDER BY case_count DESC, docket_count DESC
LIMIT {$limit}
SQL;
        try {
            $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\PDOException) {
            return [];
        }

        return array_map(static fn (array $r): array => [
            'court_id' => (string) $r['court_id'],
            'docket_count' => (int) $r['docket_count'],
            'case_count' => (int) $r['case_count'],
        ], $rows);
    }

    /**
     * Agencies from seed case_agencies for cases that have CL people edges.
     *
     * @return list<array{agency_name: string, case_count: int}>
     */
    public function sharedAgencies(int $minCases = 2, int $limit = 100): array
    {
        $minCases = max(2, $minCases);
        $limit = max(1, $limit);
        $sql = <<<SQL
SELECT a.agency_name, COUNT(DISTINCT a.case_id) AS case_count
FROM case_agencies a
WHERE a.case_id IN (SELECT DISTINCT case_id FROM cl_person_case_edges)
  AND a.agency_name IS NOT NULL AND TRIM(a.agency_name) != ''
GROUP BY a.agency_name
HAVING case_count >= {$minCases}
ORDER BY case_count DESC
LIMIT {$limit}
SQL;
        try {
            $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\PDOException) {
            return [];
        }

        return array_map(static fn (array $r): array => [
            'agency_name' => (string) $r['agency_name'],
            'case_count' => (int) $r['case_count'],
        ], $rows);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{person_id: int, display_name: string, organization: ?string, case_count: int, case_ids: list<string>}>
     */
    private function mapShared(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $ids = array_values(array_filter(explode(',', (string) ($row['case_ids'] ?? ''))));
            $out[] = [
                'person_id' => (int) $row['person_id'],
                'display_name' => (string) ($row['display_name'] ?? ''),
                'organization' => isset($row['organization']) && $row['organization'] !== null
                    ? (string) $row['organization']
                    : null,
                'case_count' => (int) $row['case_count'],
                'case_ids' => $ids,
            ];
        }

        return $out;
    }
}
