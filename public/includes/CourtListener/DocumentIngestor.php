<?php
declare(strict_types=1);

namespace Project1960\CourtListener;

use Project1960\CourtListenerDocumentStore;
use Project1960\ActivityLog;
use PDO;

/**
 * Pull docket-entry + RECAP document metadata into CL-S2 tables (CL-I2).
 * Idempotent by cl_document_id. Slow-drip friendly (--limit / --wait in CLI).
 *
 * For every docket entry returned on a queued (linked) case we store the entry
 * description (synthetic id -entry_id). When nested RECAP documents exist we
 * also store those rows so download/OCR can run. Descriptions are free once we
 * have fetched entries; docs are opportunistic.
 */
final class DocumentIngestor
{
    public function __construct(
        private IngestGateway $gateway,
        private CourtListenerDocumentStore $docs,
        private PDO $pdo,
    ) {
    }

    /**
     * Linked dockets needing doc metadata first: chokepoint (crypto+verified),
     * strong matches with zero docs, then others. Weak accepts deferred.
     *
     * @return list<int>
     */
    public function linkedDocketIds(int $limit): array
    {
        $choke = LinkQueuePriority::chokepointRankExpr('c');
        $stmt = $this->pdo->prepare(
            'SELECT l.cl_docket_id FROM (
                SELECT cl.cl_docket_id,
                       MIN(CASE WHEN cl.match_method = \'' . LinkQueuePriority::WEAK_METHOD . '\' THEN 1 ELSE 0 END) AS defer_rank,
                       MIN(' . $choke . ') AS choke_rank
                FROM case_courtlistener_links cl
                LEFT JOIN cases c ON c.id = cl.case_id
                GROUP BY cl.cl_docket_id
             ) l
             LEFT JOIN (
                SELECT cl_docket_id, COUNT(*) AS doc_count
                FROM courtlistener_documents
                GROUP BY cl_docket_id
             ) d ON d.cl_docket_id = l.cl_docket_id
             ORDER BY CASE WHEN COALESCE(d.doc_count, 0) = 0 THEN 0 ELSE 1 END ASC,
                      l.choke_rank ASC,
                      l.defer_rank ASC,
                      l.cl_docket_id ASC
             LIMIT :lim'
        );
        $stmt->bindValue(':lim', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

        return array_map('intval', $rows);
    }

    /**
     * @return array{docket_id: int, entries_seen: int, documents_upserted: int, dry_run: bool}
     */
    public function ingestDocket(int $clDocketId, bool $dryRun = false, int $pageSize = 50): array
    {
        if ($clDocketId <= 0) {
            throw new \InvalidArgumentException('cl_docket_id must be positive');
        }

        // One API call: docket-entries/?docket= includes nested recap_documents.
        $entries = $this->gateway->listDocketEntries([
            'docket' => $clDocketId,
            'page_size' => $pageSize,
        ]);
        $entryRows = $entries['results'] ?? [];
        if (!is_array($entryRows)) {
            $entryRows = [];
        }

        // Queue = linked cases only (linkedDocketIds). For every entry we already
        // fetched: always keep the description; also keep RECAP docs when present.
        $mapped = [];
        foreach ($entryRows as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $stub = $this->mapEntryDescriptionStub($entry, $clDocketId);
            if ($stub !== null) {
                $mapped[(int) $stub['cl_document_id']] = $stub;
            }
            $embedded = $entry['recap_documents'] ?? $entry['recapDocuments'] ?? null;
            if (!is_array($embedded)) {
                continue;
            }
            foreach ($embedded as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $doc = $this->mapRecapRow($row, $clDocketId, $entry);
                if ($doc !== null) {
                    $mapped[(int) $doc['cl_document_id']] = $doc;
                }
            }
        }

        $upserted = 0;
        if (!$dryRun) {
            foreach ($mapped as $doc) {
                $this->docs->upsertDocument($doc);
                $text = trim((string) ($doc['description'] ?? ''));
                if ($text !== '' && !empty($doc['store_description_as_text'])) {
                    $this->docs->upsertFullText((int) $doc['cl_document_id'], $text);
                }
                $upserted++;
            }
            $this->logActivity($clDocketId, $upserted, count($entryRows));
        } else {
            $upserted = count($mapped);
        }

        return [
            'docket_id' => $clDocketId,
            'entries_seen' => count($entryRows),
            'documents_upserted' => $upserted,
            'dry_run' => $dryRun,
        ];
    }

    private function logActivity(int $clDocketId, int $upserted, int $entriesSeen): void
    {
        try {
            $log = new ActivityLog($this->pdo);
            $caseId = $log->caseIdForDocket($clDocketId);
            $log->record(
                ActivityLog::STAGE_CL_INGEST,
                ActivityLog::STATUS_SUCCESS,
                sprintf('CL docket #%d entries=%d docs=%d', $clDocketId, $entriesSeen, $upserted),
                $caseId
            );
        } catch (\Throwable) {
        }
    }

    /**
     * Entry with no RECAP documents: synthetic negative id from CL entry id.
     *
     * @param array<string, mixed> $entry
     * @return array<string, mixed>|null
     */
    public function mapEntryDescriptionStub(array $entry, int $clDocketId): ?array
    {
        $entryId = $entry['id'] ?? $entry['pk'] ?? null;
        if (!is_numeric($entryId) || (int) $entryId <= 0) {
            return null;
        }
        $description = $this->pickDescription(null, $entry);
        if ($description === null || $description === '') {
            return null;
        }
        $entryNum = $entry['entry_number'] ?? $entry['entryNumber'] ?? null;

        return [
            'cl_document_id' => -1 * (int) $entryId,
            'cl_docket_id' => $clDocketId,
            'entry_number' => $entryNum !== null ? (string) $entryNum : null,
            'description' => $description,
            'filepath_or_url' => null,
            'mime' => 'text/plain',
            'has_plaintext' => 1,
            'ocr_status' => CourtListenerDocumentStore::OCR_NONE,
            'byte_size' => null,
            'raw_json' => json_encode($entry, JSON_THROW_ON_ERROR),
            'store_description_as_text' => true,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed>|null $entry
     * @return array<string, mixed>|null
     */
    public function mapRecapRow(array $row, int $clDocketId, ?array $entry = null): ?array
    {
        $id = $row['id'] ?? $row['pk'] ?? null;
        if (!is_numeric($id) || (int) $id <= 0) {
            return null;
        }
        $filepath = $this->resolveFileUrl(
            $row['filepath_local'] ?? $row['filepath_ia'] ?? $row['absolute_url'] ?? null
        );
        $hasText = !empty($row['plain_text']);
        $entryNum = $row['entry_number'] ?? $entry['entry_number'] ?? $entry['entryNumber'] ?? null;
        $bytes = $row['file_size'] ?? $row['size'] ?? null;
        $docDesc = isset($row['description']) ? trim((string) $row['description']) : '';
        $description = $this->pickDescription($docDesc !== '' ? $docDesc : null, $entry);

        return [
            'cl_document_id' => (int) $id,
            'cl_docket_id' => $clDocketId,
            'entry_number' => $entryNum !== null ? (string) $entryNum : null,
            'description' => $description,
            'filepath_or_url' => $filepath,
            'mime' => isset($row['mimetype']) ? (string) $row['mimetype'] : null,
            'has_plaintext' => $hasText ? 1 : 0,
            'ocr_status' => CourtListenerDocumentStore::OCR_NONE,
            'byte_size' => is_numeric($bytes) ? (int) $bytes : null,
            'raw_json' => json_encode($row, JSON_THROW_ON_ERROR),
        ];
    }

    /**
     * Prefer the richer docket-entry description when the RECAP row is thin/empty.
     *
     * @param array<string, mixed>|null $entry
     */
    public function pickDescription(?string $docDescription, ?array $entry): ?string
    {
        $doc = $docDescription !== null ? trim($docDescription) : '';
        $entryDesc = '';
        if ($entry !== null && isset($entry['description'])) {
            $entryDesc = trim((string) $entry['description']);
        }
        if ($entryDesc !== '' && ($doc === '' || strlen($entryDesc) > strlen($doc))) {
            return $entryDesc;
        }
        if ($doc !== '') {
            return $doc;
        }

        return $entryDesc !== '' ? $entryDesc : null;
    }

    /**
     * CL filepath_local is often a relative storage key (recap/…); download needs HTTPS.
     */
    public function resolveFileUrl(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $s = trim((string) $raw);
        if ($s === '') {
            return null;
        }
        if (str_starts_with($s, 'http://') || str_starts_with($s, 'https://')) {
            return $s;
        }
        $s = ltrim($s, '/');
        if (str_starts_with($s, 'storage/')) {
            $s = substr($s, strlen('storage/'));
        }

        return 'https://storage.courtlistener.com/' . $s;
    }
}
