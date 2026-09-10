<?php
declare(strict_types=1);

namespace Project1960\CourtListener;

use Project1960\CourtListenerDocumentStore;
use PDO;

/**
 * Pull docket-entry + RECAP document metadata into CL-S2 tables (CL-I2).
 * Idempotent by cl_document_id. Slow-drip friendly (--limit / --wait in CLI).
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
     * @return list<int>
     */
    public function linkedDocketIds(int $limit): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT cl_docket_id FROM case_courtlistener_links
             ORDER BY cl_docket_id LIMIT :lim'
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

        $entries = $this->gateway->listDocketEntries([
            'docket' => $clDocketId,
            'page_size' => $pageSize,
        ]);
        $entryRows = $entries['results'] ?? [];
        if (!is_array($entryRows)) {
            $entryRows = [];
        }

        $recap = $this->gateway->listRecapDocuments([
            'docket' => $clDocketId,
            'page_size' => $pageSize,
        ]);
        $docRows = $recap['results'] ?? [];
        if (!is_array($docRows)) {
            $docRows = [];
        }

        // Prefer RECAP documents list; also harvest embedded docs from entries.
        $mapped = [];
        foreach ($docRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $doc = $this->mapRecapRow($row, $clDocketId);
            if ($doc !== null) {
                $mapped[(int) $doc['cl_document_id']] = $doc;
            }
        }
        foreach ($entryRows as $entry) {
            if (!is_array($entry)) {
                continue;
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
                $upserted++;
            }
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
        $filepath = $row['filepath_local'] ?? $row['filepath_ia'] ?? $row['absolute_url'] ?? null;
        $hasText = !empty($row['plain_text']);
        $entryNum = $row['entry_number'] ?? $entry['entry_number'] ?? $entry['entryNumber'] ?? null;
        $bytes = $row['file_size'] ?? $row['size'] ?? null;

        return [
            'cl_document_id' => (int) $id,
            'cl_docket_id' => $clDocketId,
            'entry_number' => $entryNum !== null ? (string) $entryNum : null,
            'description' => isset($row['description']) ? (string) $row['description'] : (isset($entry['description']) ? (string) $entry['description'] : null),
            'filepath_or_url' => $filepath !== null ? (string) $filepath : null,
            'mime' => isset($row['mimetype']) ? (string) $row['mimetype'] : null,
            'has_plaintext' => $hasText ? 1 : 0,
            'ocr_status' => CourtListenerDocumentStore::OCR_NONE,
            'byte_size' => is_numeric($bytes) ? (int) $bytes : null,
            'raw_json' => json_encode($row, JSON_THROW_ON_ERROR),
        ];
    }
}
