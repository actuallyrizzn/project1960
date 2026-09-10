<?php
declare(strict_types=1);

namespace Project1960\CourtListener;

use Project1960\CourtListenerDocumentStore;

/**
 * Prefer API plaintext when present; otherwise queue OCR (CL-I5).
 */
final class TextIntakeRouter
{
    public function __construct(private CourtListenerDocumentStore $docs)
    {
    }

    /**
     * @param array{
     *   cl_document_id: int,
     *   plain_text?: ?string,
     *   has_plaintext?: bool|int|null
     * } $input
     * @return array{cl_document_id: int, path: 'plaintext'|'ocr_queue', ocr_status: string}
     */
    public function route(array $input): array
    {
        $id = (int) ($input['cl_document_id'] ?? 0);
        if ($id <= 0) {
            throw new \InvalidArgumentException('cl_document_id required');
        }
        $text = isset($input['plain_text']) ? trim((string) $input['plain_text']) : '';
        $flag = !empty($input['has_plaintext']);

        if ($text !== '' || $flag) {
            if ($text !== '') {
                $this->docs->upsertFullText($id, $text);
            }
            $this->docs->setOcrStatus($id, CourtListenerDocumentStore::OCR_DONE);
            // Keep has_plaintext bit via upsert of minimal fields if needed — status is enough for queue
            return [
                'cl_document_id' => $id,
                'path' => 'plaintext',
                'ocr_status' => CourtListenerDocumentStore::OCR_DONE,
            ];
        }

        $this->docs->setOcrStatus($id, CourtListenerDocumentStore::OCR_PENDING);

        return [
            'cl_document_id' => $id,
            'path' => 'ocr_queue',
            'ocr_status' => CourtListenerDocumentStore::OCR_PENDING,
        ];
    }
}
