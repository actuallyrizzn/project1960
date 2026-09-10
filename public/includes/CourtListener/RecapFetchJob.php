<?php
declare(strict_types=1);

namespace Project1960\CourtListener;

use PDO;

/**
 * Optional PACER/RECAP fetch for skipped_pacer docs (CL-I4).
 * Dry-run never calls the SDK spend path. Live requires --live + budget.
 */
final class RecapFetchJob
{
    public const ALLOWED_TYPES = ['pdf', 'docket', 'document'];

    public function __construct(
        private RecapFetchGateway $gateway,
        private PDO $pdo,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function candidates(int $limit): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT cl_document_id, filepath_or_url, description, download_status
             FROM courtlistener_documents
             WHERE download_status = 'skipped_pacer'
             ORDER BY cl_document_id
             LIMIT :lim"
        );
        $stmt->bindValue(':lim', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function isAllowedDescription(?string $description): bool
    {
        $d = strtolower((string) $description);
        foreach (['indictment', 'complaint', 'information', 'plea', 'judgment', 'sentence', 'superseding'] as $needle) {
            if ($d !== '' && str_contains($d, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{
     *   mode: 'dry-run'|'live',
     *   considered: int,
     *   would_fetch: int,
     *   fetched: int,
     *   skipped_budget: int,
     *   skipped_allowlist: int,
     *   errors: int
     * }
     */
    public function run(int $limit, int $budget, bool $live = false): array
    {
        $rows = $this->candidates($limit);
        $stats = [
            'mode' => $live ? 'live' : 'dry-run',
            'considered' => count($rows),
            'would_fetch' => 0,
            'fetched' => 0,
            'skipped_budget' => 0,
            'skipped_allowlist' => 0,
            'errors' => 0,
        ];
        $spent = 0;
        foreach ($rows as $row) {
            if (!$this->isAllowedDescription($row['description'] ?? null)) {
                $stats['skipped_allowlist']++;
                continue;
            }
            if ($spent >= $budget) {
                $stats['skipped_budget']++;
                continue;
            }
            $stats['would_fetch']++;
            if (!$live) {
                // Dry-run: never call gateway create/fetch
                continue;
            }
            try {
                $this->gateway->requestFetch([
                    'request_type' => 1,
                    'document' => (int) $row['cl_document_id'],
                ]);
                $spent++;
                $stats['fetched']++;
                $upd = $this->pdo->prepare(
                    "UPDATE courtlistener_documents
                     SET download_status = 'fetch_requested', updated_at = :u
                     WHERE cl_document_id = :id"
                );
                $upd->bindValue(':u', gmdate('c'));
                $upd->bindValue(':id', (int) $row['cl_document_id'], PDO::PARAM_INT);
                $upd->execute();
            } catch (\Throwable) {
                $stats['errors']++;
            }
        }

        return $stats;
    }
}
