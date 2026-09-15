<?php
declare(strict_types=1);

namespace Project1960;

use PDO;
use PDOException;

/**
 * Pipeline / enrichment activity log (same table the Enrichment page reads).
 *
 * Legacy Venice enrichment wrote here; CourtListener + scraper stages must too
 * so the public activity feed is not frozen at the last enrichment run.
 */
final class ActivityLog
{
    public const STAGE_CL_MATCH = 'cl_match';
    public const STAGE_CL_INGEST = 'cl_ingest';
    public const STAGE_CL_DOWNLOAD = 'cl_download';
    public const STAGE_CL_OCR = 'cl_ocr';
    public const STAGE_CL_EXTRACT = 'cl_extract';
    public const STAGE_SCRAPE = 'scrape';

    public const STATUS_SUCCESS = 'success';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_ERROR = 'error';
    public const STATUS_WEAK = 'weak_accept';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function ensureTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS enrichment_activity_log (
                log_id INTEGER PRIMARY KEY AUTOINCREMENT,
                timestamp TEXT,
                case_id TEXT,
                table_name TEXT,
                status TEXT,
                notes TEXT
            )'
        );
        $this->pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_enrichment_activity_ts
             ON enrichment_activity_log(timestamp)'
        );
    }

    public function record(
        string $stage,
        string $status,
        string $notes = '',
        ?string $caseId = null,
    ): void {
        $this->ensureTable();
        $stmt = $this->pdo->prepare(
            'INSERT INTO enrichment_activity_log (timestamp, case_id, table_name, status, notes)
             VALUES (:ts, :case_id, :stage, :status, :notes)'
        );
        $stmt->execute([
            ':ts' => gmdate('c'),
            ':case_id' => $caseId ?? '',
            ':stage' => $stage,
            ':status' => $status,
            ':notes' => $notes,
        ]);
    }

    /**
     * Map pipeline result strings onto log statuses the UI already understands.
     */
    public static function normalizeStatus(string $raw): string
    {
        $s = strtolower(trim($raw));

        return match (true) {
            in_array($s, ['success', 'matched', 'done', 'ok'], true) => self::STATUS_SUCCESS,
            $s === 'weak_accept' || $s === self::STATUS_WEAK => self::STATUS_WEAK,
            in_array($s, ['skipped', 'skipped_pacer', 'locked', 'ambiguous', 'no_match', 'low_confidence'], true)
                => self::STATUS_SKIPPED,
            in_array($s, ['error', 'failed', 'would_fail'], true) => self::STATUS_ERROR,
            default => $s !== '' ? $s : self::STATUS_ERROR,
        };
    }

    public function caseIdForDocket(int $clDocketId): ?string
    {
        if ($clDocketId <= 0) {
            return null;
        }
        try {
            $stmt = $this->pdo->prepare(
                'SELECT case_id FROM case_courtlistener_links
                 WHERE cl_docket_id = :id
                 ORDER BY CASE WHEN match_method = \'weak_accept\' THEN 1 ELSE 0 END ASC,
                          COALESCE(match_confidence, 0) DESC
                 LIMIT 1'
            );
            $stmt->bindValue(':id', $clDocketId, PDO::PARAM_INT);
            $stmt->execute();
            $id = $stmt->fetchColumn();

            return is_string($id) && $id !== '' ? $id : null;
        } catch (PDOException) {
            return null;
        }
    }

    public function caseIdForDocument(int $clDocumentId): ?string
    {
        if ($clDocumentId <= 0) {
            return null;
        }
        try {
            $stmt = $this->pdo->prepare(
                'SELECT l.case_id
                 FROM courtlistener_documents d
                 JOIN case_courtlistener_links l ON l.cl_docket_id = d.cl_docket_id
                 WHERE d.cl_document_id = :id
                 ORDER BY CASE WHEN l.match_method = \'weak_accept\' THEN 1 ELSE 0 END ASC,
                          COALESCE(l.match_confidence, 0) DESC
                 LIMIT 1'
            );
            $stmt->bindValue(':id', $clDocumentId, PDO::PARAM_INT);
            $stmt->execute();
            $id = $stmt->fetchColumn();

            return is_string($id) && $id !== '' ? $id : null;
        } catch (PDOException) {
            return null;
        }
    }

    /**
     * @return list<array{timestamp: string, case_id: string, table_name: string, status: string, notes: string}>
     */
    public function recent(int $limit = 100): array
    {
        $this->ensureTable();
        $limit = max(1, min(500, $limit));
        try {
            $stmt = $this->pdo->query(
                'SELECT timestamp, case_id, table_name, status, notes
                 FROM enrichment_activity_log
                 ORDER BY timestamp DESC, log_id DESC
                 LIMIT ' . $limit
            );
            if ($stmt === false) {
                return [];
            }
            /** @var list<array{timestamp: string, case_id: string, table_name: string, status: string, notes: string}> */
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException) {
            return [];
        }
    }
}
