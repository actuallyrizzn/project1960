<?php
declare(strict_types=1);

namespace Project1960;

use PDO;

/**
 * Collapse duplicate cases rows (legacy DB had no PRIMARY KEY on id).
 * Keeps one row per id — prefers verified, then classified, then longest body.
 */
final class CasesDeduper
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array{
     *   total_rows: int,
     *   distinct_ids: int,
     *   duplicate_groups: int,
     *   rows_to_delete: int
     * }
     */
    public function analyze(): array
    {
        $total = (int) $this->pdo->query('SELECT COUNT(*) FROM cases')->fetchColumn();
        $distinct = (int) $this->pdo->query('SELECT COUNT(DISTINCT id) FROM cases')->fetchColumn();
        $groups = (int) $this->pdo->query(
            'SELECT COUNT(*) FROM (SELECT id FROM cases GROUP BY id HAVING COUNT(*) > 1)'
        )->fetchColumn();

        return [
            'total_rows' => $total,
            'distinct_ids' => $distinct,
            'duplicate_groups' => $groups,
            'rows_to_delete' => max(0, $total - $distinct),
        ];
    }

    /**
     * @return array{deleted: int, kept: int, dry_run: bool}
     */
    public function dedupeById(bool $dryRun = false): array
    {
        $stats = $this->analyze();
        if ($stats['rows_to_delete'] === 0) {
            return ['deleted' => 0, 'kept' => $stats['distinct_ids'], 'dry_run' => $dryRun];
        }

        $keepers = $this->keeperRowids();
        $deleted = $stats['total_rows'] - count($keepers);
        if ($keepers === [] || $deleted <= 0) {
            return ['deleted' => 0, 'kept' => $stats['distinct_ids'], 'dry_run' => $dryRun];
        }

        if ($dryRun) {
            return ['deleted' => $deleted, 'kept' => count($keepers), 'dry_run' => true];
        }

        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec('CREATE TEMP TABLE cases_keep_rowids (rowid INTEGER PRIMARY KEY)');
            $ins = $this->pdo->prepare('INSERT INTO cases_keep_rowids (rowid) VALUES (?)');
            foreach ($keepers as $rowid) {
                $ins->execute([$rowid]);
            }
            $this->pdo->exec(
                'DELETE FROM cases WHERE rowid NOT IN (SELECT rowid FROM cases_keep_rowids)'
            );
            $this->pdo->exec('DROP TABLE cases_keep_rowids');
            $this->pdo->commit();
        } catch (\Throwable $e) {
            try {
                $this->pdo->rollBack();
            } catch (\Throwable) {
                // SQLite may already have aborted the transaction
            }
            throw $e;
        }

        return ['deleted' => $deleted, 'kept' => count($keepers), 'dry_run' => false];
    }

    /**
     * @return list<int>
     */
    private function keeperRowids(): array
    {
        // Prefer verified → non-empty classification → longest body → highest rowid
        $sql = <<<'SQL'
SELECT rowid FROM (
  SELECT rowid,
         ROW_NUMBER() OVER (
           PARTITION BY id
           ORDER BY
             CASE WHEN CAST(verified_1960 AS TEXT) IN ('1', 'true') OR verified_1960 = 1 THEN 0 ELSE 1 END,
             CASE WHEN classification IS NOT NULL AND TRIM(CAST(classification AS TEXT)) != '' THEN 0 ELSE 1 END,
             COALESCE(length(body), 0) DESC,
             rowid DESC
         ) AS rn
  FROM cases
) WHERE rn = 1
SQL;
        $stmt = $this->pdo->query($sql);
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[] = (int) $row['rowid'];
        }

        return $out;
    }
}
