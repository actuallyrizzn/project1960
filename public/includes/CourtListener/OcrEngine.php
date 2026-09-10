<?php
declare(strict_types=1);

namespace Project1960\CourtListener;

/**
 * Extract text from a local document file (CL-O1/O2).
 */
interface OcrEngine
{
    /**
     * @return array{ok: bool, text: string, error?: string, method?: string}
     */
    public function extractText(string $absolutePath): array;
}
