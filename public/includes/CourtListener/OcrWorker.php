<?php
declare(strict_types=1);

namespace Project1960\CourtListener;

use PDO;
use Project1960\ActivityLog;
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
            'SELECT d.* FROM courtlistener_documents d
             WHERE d.ocr_status = \'pending\'
             ORDER BY ' . LinkQueuePriority::deferRankSubquery('d.cl_docket_id') . ' ASC,
                      d.cl_document_id ASC
             LIMIT ' . $limit
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

            return $this->finishOcr($clDocumentId, 'failed', 'no local_path');
        }

        if ($dryRun) {
            return ['cl_document_id' => $clDocumentId, 'status' => 'would_ocr', 'method' => 'dry-run'];
        }

        $lock = $this->acquireLock($clDocumentId);
        if ($lock === null) {
            return $this->finishOcr($clDocumentId, 'locked', 'lock held', log: true);
        }
        try {
            $result = $this->engine->extractText($path);
            if (!$result['ok'] || trim($result['text']) === '') {
                $this->docs->setOcrStatus($clDocumentId, CourtListenerDocumentStore::OCR_FAILED);

                return $this->finishOcr(
                    $clDocumentId,
                    'failed',
                    $result['error'] ?? 'empty OCR text',
                    $result['method'] ?? null
                );
            }
            $this->docs->upsertFullText($clDocumentId, $result['text']);
            $this->docs->setOcrStatus($clDocumentId, CourtListenerDocumentStore::OCR_DONE);

            return $this->finishOcr(
                $clDocumentId,
                'done',
                'chars=' . strlen($result['text']),
                $result['method'] ?? 'ocr'
            );
        } finally {
            $this->releaseLock($lock);
        }
    }

    /**
     * @return array{cl_document_id: int, status: string, method?: string, error?: string}
     */
    private function finishOcr(
        int $clDocumentId,
        string $status,
        string $detail,
        ?string $method = null,
        bool $log = true,
    ): array {
        if ($log && !str_starts_with($status, 'would_')) {
            try {
                $alog = new ActivityLog($this->pdo);
                $caseId = $alog->caseIdForDocument($clDocumentId);
                $alog->record(
                    ActivityLog::STAGE_CL_OCR,
                    ActivityLog::normalizeStatus($status),
                    trim(sprintf('doc #%d %s %s', $clDocumentId, $detail, $method ?? '')),
                    $caseId
                );
            } catch (\Throwable) {
            }
        }
        $out = ['cl_document_id' => $clDocumentId, 'status' => $status];
        if ($method !== null) {
            $out['method'] = $method;
        }
        if ($status === 'failed') {
            $out['error'] = $detail;
        }

        return $out;
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
