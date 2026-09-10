<?php
declare(strict_types=1);

namespace Project1960\CourtListener;

use PDO;
use Project1960\CourtListenerDocumentStore;
use RuntimeException;

/**
 * Process ocr_status=pending → full_text (CL-O2).
 */
final class OcrWorker
{
    public function __construct(
        private OcrEngine $engine,
        private CourtListenerDocumentStore $docs,
        private PDO $pdo,
        private ?string $lockPath = null,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pendingDocuments(int $limit = 10): array
    {
        $limit = max(1, $limit);
        $stmt = $this->pdo->query(
            "SELECT * FROM courtlistener_documents
             WHERE ocr_status = 'pending'
             ORDER BY cl_document_id ASC
             LIMIT {$limit}"
        );
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    /** @return array{pending: int, done: int, failed: int, none: int} */
    public function metrics(): array
    {
        $out = ['pending' => 0, 'done' => 0, 'failed' => 0, 'none' => 0];
        $stmt = $this->pdo->query(
            'SELECT ocr_status, COUNT(*) AS c FROM courtlistener_documents GROUP BY ocr_status'
        );
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $k = (string) $row['ocr_status'];
            if (isset($out[$k])) {
                $out[$k] = (int) $row['c'];
            }
        }

        return $out;
    }

    /**
     * @return array{cl_document_id: int, status: string, method?: string, error?: string}
     */
    public function processOne(int $clDocumentId, bool $dryRun = false): array
    {
        $row = $this->docs->getDocument($clDocumentId);
        if ($row === null) {
            throw new RuntimeException('document not found: ' . $clDocumentId);
        }
        if (($row['ocr_status'] ?? '') !== CourtListenerDocumentStore::OCR_PENDING) {
            return [
                'cl_document_id' => $clDocumentId,
                'status' => (string) ($row['ocr_status'] ?? 'none'),
            ];
        }

        $path = (string) ($row['local_path'] ?? '');
        if ($path === '' || !is_file($path)) {
            if ($dryRun) {
                return ['cl_document_id' => $clDocumentId, 'status' => 'would_fail', 'error' => 'no local_path'];
            }
            $this->docs->setOcrStatus($clDocumentId, CourtListenerDocumentStore::OCR_FAILED);

            return ['cl_document_id' => $clDocumentId, 'status' => 'failed', 'error' => 'no local_path'];
        }

        if ($dryRun) {
            return ['cl_document_id' => $clDocumentId, 'status' => 'would_ocr', 'method' => 'dry-run'];
        }

        $lock = $this->acquireLock($clDocumentId);
        if ($lock === null) {
            return ['cl_document_id' => $clDocumentId, 'status' => 'locked'];
        }
        try {
            $result = $this->engine->extractText($path);
            if (!$result['ok'] || trim($result['text']) === '') {
                $this->docs->setOcrStatus($clDocumentId, CourtListenerDocumentStore::OCR_FAILED);

                return [
                    'cl_document_id' => $clDocumentId,
                    'status' => 'failed',
                    'error' => $result['error'] ?? 'empty OCR text',
                    'method' => $result['method'] ?? null,
                ];
            }
            $this->docs->upsertFullText($clDocumentId, $result['text']);
            $this->docs->setOcrStatus($clDocumentId, CourtListenerDocumentStore::OCR_DONE);

            return [
                'cl_document_id' => $clDocumentId,
                'status' => 'done',
                'method' => $result['method'] ?? 'ocr',
            ];
        } finally {
            $this->releaseLock($lock);
        }
    }

    /** @return resource|null */
    private function acquireLock(int $id)
    {
        $base = $this->lockPath ?? (sys_get_temp_dir() . '/p1960-ocr.lock');
        $fh = fopen($base . '.' . $id, 'c');
        if ($fh === false) {
            return null;
        }
        if (!flock($fh, LOCK_EX | LOCK_NB)) {
            fclose($fh);

            return null;
        }

        return $fh;
    }

    /** @param resource $fh */
    private function releaseLock($fh): void
    {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}
