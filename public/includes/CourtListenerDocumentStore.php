<?php
declare(strict_types=1);

namespace Project1960;

use InvalidArgumentException;
use PDO;

/**
 * CourtListener / RECAP document rows + OCR status transitions (CL-S2).
 */
final class CourtListenerDocumentStore
{
    public const OCR_NONE = 'none';
    public const OCR_PENDING = 'pending';
    public const OCR_DONE = 'done';
    public const OCR_FAILED = 'failed';

    /** @var list<string> */
    public const OCR_STATUSES = [
        self::OCR_NONE,
        self::OCR_PENDING,
        self::OCR_DONE,
        self::OCR_FAILED,
    ];

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param array{
     *   cl_document_id: int|string,
     *   cl_docket_id?: int|string|null,
     *   entry_number?: ?string,
     *   description?: ?string,
     *   filepath_or_url?: ?string,
     *   mime?: ?string,
     *   has_plaintext?: bool|int|null,
     *   ocr_status?: ?string,
     *   byte_size?: int|null,
     *   fetched_at?: ?string,
     *   raw_json?: ?string
     * } $doc
     */
    public function upsertDocument(array $doc): void
    {
        $id = (int) ($doc['cl_document_id'] ?? 0);
        if ($id <= 0) {
            throw new InvalidArgumentException('cl_document_id must be a positive integer');
        }
        $ocr = $doc['ocr_status'] ?? self::OCR_NONE;
        if (!is_string($ocr) || !in_array($ocr, self::OCR_STATUSES, true)) {
            throw new InvalidArgumentException('invalid ocr_status');
        }

        $docketId = $doc['cl_docket_id'] ?? null;
        $stmt = $this->pdo->prepare(
            'INSERT INTO courtlistener_documents (
                cl_document_id, cl_docket_id, entry_number, description, filepath_or_url,
                mime, has_plaintext, ocr_status, byte_size, fetched_at, raw_json, updated_at
             ) VALUES (
                :id, :docket_id, :entry_number, :description, :filepath_or_url,
                :mime, :has_plaintext, :ocr_status, :byte_size, :fetched_at, :raw_json, :updated_at
             )
             ON CONFLICT(cl_document_id) DO UPDATE SET
                cl_docket_id = excluded.cl_docket_id,
                entry_number = excluded.entry_number,
                description = excluded.description,
                filepath_or_url = excluded.filepath_or_url,
                mime = excluded.mime,
                has_plaintext = excluded.has_plaintext,
                ocr_status = excluded.ocr_status,
                byte_size = excluded.byte_size,
                fetched_at = COALESCE(excluded.fetched_at, courtlistener_documents.fetched_at),
                raw_json = COALESCE(excluded.raw_json, courtlistener_documents.raw_json),
                updated_at = excluded.updated_at'
        );
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        if ($docketId === null || $docketId === '') {
            $stmt->bindValue(':docket_id', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':docket_id', (int) $docketId, PDO::PARAM_INT);
        }
        $stmt->bindValue(':entry_number', $doc['entry_number'] ?? null);
        $stmt->bindValue(':description', $doc['description'] ?? null);
        $stmt->bindValue(':filepath_or_url', $doc['filepath_or_url'] ?? null);
        $stmt->bindValue(':mime', $doc['mime'] ?? null);
        $stmt->bindValue(':has_plaintext', !empty($doc['has_plaintext']) ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':ocr_status', $ocr);
        if (!isset($doc['byte_size']) || $doc['byte_size'] === null) {
            $stmt->bindValue(':byte_size', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':byte_size', (int) $doc['byte_size'], PDO::PARAM_INT);
        }
        $stmt->bindValue(':fetched_at', $doc['fetched_at'] ?? null);
        $stmt->bindValue(':raw_json', $doc['raw_json'] ?? null);
        $stmt->bindValue(':updated_at', gmdate('c'));
        $stmt->execute();
    }

    public function setOcrStatus(int $clDocumentId, string $status): void
    {
        if (!in_array($status, self::OCR_STATUSES, true)) {
            throw new InvalidArgumentException('invalid ocr_status');
        }
        $stmt = $this->pdo->prepare(
            'UPDATE courtlistener_documents
             SET ocr_status = :status, updated_at = :updated_at
             WHERE cl_document_id = :id'
        );
        $stmt->bindValue(':status', $status);
        $stmt->bindValue(':updated_at', gmdate('c'));
        $stmt->bindValue(':id', $clDocumentId, PDO::PARAM_INT);
        $stmt->execute();
        if ($stmt->rowCount() === 0) {
            throw new InvalidArgumentException('document not found');
        }
    }

    /** @return array<string, mixed>|null */
    public function getDocument(int $clDocumentId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM courtlistener_documents WHERE cl_document_id = :id'
        );
        $stmt->bindValue(':id', $clDocumentId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function upsertFullText(int $clDocumentId, string $fullText): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO courtlistener_document_text (cl_document_id, full_text, updated_at)
             VALUES (:id, :full_text, :updated_at)
             ON CONFLICT(cl_document_id) DO UPDATE SET
                full_text = excluded.full_text,
                updated_at = excluded.updated_at'
        );
        $stmt->bindValue(':id', $clDocumentId, PDO::PARAM_INT);
        $stmt->bindValue(':full_text', $fullText);
        $stmt->bindValue(':updated_at', gmdate('c'));
        $stmt->execute();
    }

    public function upsertPage(int $clDocumentId, int $pageNumber, string $pageText): void
    {
        if ($pageNumber < 1) {
            throw new InvalidArgumentException('page_number must be >= 1');
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO courtlistener_document_pages (cl_document_id, page_number, page_text)
             VALUES (:id, :page_number, :page_text)
             ON CONFLICT(cl_document_id, page_number) DO UPDATE SET
                page_text = excluded.page_text'
        );
        $stmt->bindValue(':id', $clDocumentId, PDO::PARAM_INT);
        $stmt->bindValue(':page_number', $pageNumber, PDO::PARAM_INT);
        $stmt->bindValue(':page_text', $pageText);
        $stmt->execute();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pagesForDocument(int $clDocumentId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT page_number, page_text FROM courtlistener_document_pages
             WHERE cl_document_id = :id ORDER BY page_number ASC'
        );
        $stmt->bindValue(':id', $clDocumentId, PDO::PARAM_INT);
        $stmt->execute();
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows;
    }
}
