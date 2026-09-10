<?php
declare(strict_types=1);

namespace Project1960\CourtListener;

use Project1960\Config;
use Project1960\CourtListenerDocumentStore;
use PDO;

/**
 * Download free CL/RECAP document bytes to storage/cl-docs/ (CL-I3).
 * Skips PACER-only / paywalled URLs for CL-I4.
 */
final class DocumentDownloader
{
    public const STATUS_NONE = 'none';
    public const STATUS_DONE = 'done';
    public const STATUS_SKIPPED_PACER = 'skipped_pacer';
    public const STATUS_FAILED = 'failed';

    public function __construct(
        private HttpFetcher $http,
        private CourtListenerDocumentStore $docs,
        private PDO $pdo,
        private string $storageDir,
    ) {
    }

    public static function defaultStorageDir(?string $projectRoot = null): string
    {
        return Config::clDocsDir($projectRoot);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pendingDocuments(int $limit): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT cl_document_id, filepath_or_url, local_path, download_status
             FROM courtlistener_documents
             WHERE (download_status IS NULL OR download_status IN ('none', 'failed'))
               AND filepath_or_url IS NOT NULL
               AND filepath_or_url != ''
             ORDER BY cl_document_id
             LIMIT :lim"
        );
        $stmt->bindValue(':lim', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function isFreeUrl(string $url): bool
    {
        $u = strtolower($url);
        if ($u === '' || str_starts_with($u, 'pacer:') || str_contains($u, 'pacer.gov')) {
            return false;
        }
        // Relative storage paths from CL API need https host — treat as not directly fetchable
        if (!str_starts_with($u, 'http://') && !str_starts_with($u, 'https://')) {
            return false;
        }
        // CourtListener / IA free mirrors
        if (str_contains($u, 'courtlistener.com') || str_contains($u, 'archive.org')
            || str_contains($u, 'storage.courtlistener.com')) {
            return true;
        }
        // Unknown https — try (may 403 → failed)
        return str_starts_with($u, 'https://');
    }

    public function looksLikePacerOnly(string $url): bool
    {
        $u = strtolower($url);

        return str_contains($u, 'pacer.gov') || str_starts_with($u, 'pacer:');
    }

    /**
     * @return array{status: string, cl_document_id: int, path?: string, sha256?: string, error?: string}
     */
    public function downloadOne(int $clDocumentId, bool $dryRun = false): array
    {
        $row = $this->docs->getDocument($clDocumentId);
        if ($row === null) {
            throw new \InvalidArgumentException('document not found: ' . $clDocumentId);
        }
        $url = trim((string) ($row['filepath_or_url'] ?? ''));
        if ($url === '') {
            return ['status' => self::STATUS_FAILED, 'cl_document_id' => $clDocumentId, 'error' => 'no_url'];
        }
        if ($this->looksLikePacerOnly($url) || !$this->isFreeUrl($url)) {
            if (!$dryRun) {
                $this->setDownloadMeta($clDocumentId, self::STATUS_SKIPPED_PACER, null, null);
            }

            return ['status' => self::STATUS_SKIPPED_PACER, 'cl_document_id' => $clDocumentId];
        }

        if ($dryRun) {
            return ['status' => self::STATUS_DONE, 'cl_document_id' => $clDocumentId, 'path' => '(dry-run)'];
        }

        $resp = $this->http->get($url);
        if (!$resp['ok'] || $resp['body'] === '') {
            $this->setDownloadMeta($clDocumentId, self::STATUS_FAILED, null, null);

            return [
                'status' => self::STATUS_FAILED,
                'cl_document_id' => $clDocumentId,
                'error' => $resp['error'] ?? ('http_' . $resp['status']),
            ];
        }

        if (!is_dir($this->storageDir) && !mkdir($this->storageDir, 0755, true) && !is_dir($this->storageDir)) {
            throw new \RuntimeException('Cannot create storage dir: ' . $this->storageDir);
        }

        $sha = hash('sha256', $resp['body']);
        $ext = $this->guessExtension($url, $resp['body']);
        $rel = $clDocumentId . '_' . substr($sha, 0, 12) . $ext;
        $abs = rtrim($this->storageDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $rel;
        if (file_put_contents($abs, $resp['body']) === false) {
            $this->setDownloadMeta($clDocumentId, self::STATUS_FAILED, null, null);

            return ['status' => self::STATUS_FAILED, 'cl_document_id' => $clDocumentId, 'error' => 'write_failed'];
        }

        $this->setDownloadMeta($clDocumentId, self::STATUS_DONE, $abs, $sha);

        return [
            'status' => self::STATUS_DONE,
            'cl_document_id' => $clDocumentId,
            'path' => $abs,
            'sha256' => $sha,
        ];
    }

    private function setDownloadMeta(int $id, string $status, ?string $path, ?string $sha): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE courtlistener_documents
             SET download_status = :st,
                 local_path = COALESCE(:path, local_path),
                 content_sha256 = COALESCE(:sha, content_sha256),
                 updated_at = :updated
             WHERE cl_document_id = :id'
        );
        $stmt->bindValue(':st', $status);
        if ($path === null) {
            $stmt->bindValue(':path', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':path', $path);
        }
        if ($sha === null) {
            $stmt->bindValue(':sha', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':sha', $sha);
        }
        $stmt->bindValue(':updated', gmdate('c'));
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    private function guessExtension(string $url, string $body): string
    {
        if (str_starts_with($body, '%PDF')) {
            return '.pdf';
        }
        $path = parse_url($url, PHP_URL_PATH);
        if (is_string($path) && preg_match('/\.(pdf|txt|html?)$/i', $path, $m)) {
            return '.' . strtolower($m[1]);
        }

        return '.bin';
    }
}
