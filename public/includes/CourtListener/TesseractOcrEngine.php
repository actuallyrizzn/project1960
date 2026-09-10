<?php
declare(strict_types=1);

namespace Project1960\CourtListener;

/**
 * Prefer pdftotext; fall back to pdftoppm + tesseract (CL-O1).
 */
final class TesseractOcrEngine implements OcrEngine
{
    public function __construct(
        private string $pdftotextBin = 'pdftotext',
        private string $pdftoppmBin = 'pdftoppm',
        private string $tesseractBin = 'tesseract',
        private int $dpi = 200,
    ) {
    }

    public function extractText(string $absolutePath): array
    {
        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            return ['ok' => false, 'text' => '', 'error' => 'file missing or unreadable'];
        }

        $ext = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
        if (in_array($ext, ['txt', 'text'], true)) {
            $text = (string) file_get_contents($absolutePath);

            return ['ok' => true, 'text' => $text, 'method' => 'plaintext'];
        }

        if ($ext === 'pdf' || $ext === '') {
            $layered = $this->runPdftotext($absolutePath);
            if ($layered['ok'] && trim($layered['text']) !== '') {
                return $layered;
            }
            $ocr = $this->runTesseractPdf($absolutePath);
            if ($ocr['ok'] && trim($ocr['text']) !== '') {
                return $ocr;
            }
            if (!($ocr['ok'] ?? false)) {
                return $ocr;
            }

            return [
                'ok' => false,
                'text' => '',
                'error' => 'no extractable text',
                'method' => $ocr['method'] ?? 'tesseract',
            ];
        }

        return $this->runTesseractImage($absolutePath);
    }

    /** @return array{ok: bool, text: string, error?: string, method?: string} */
    private function runPdftotext(string $path): array
    {
        $cmd = sprintf(
            '%s -layout %s - 2>/dev/null',
            escapeshellcmd($this->pdftotextBin),
            escapeshellarg($path)
        );
        $out = [];
        $code = 0;
        exec($cmd, $out, $code);
        $text = implode("\n", $out);
        if ($code !== 0 && trim($text) === '') {
            return ['ok' => false, 'text' => '', 'error' => 'pdftotext failed', 'method' => 'pdftotext'];
        }

        return ['ok' => true, 'text' => $text, 'method' => 'pdftotext'];
    }

    /** @return array{ok: bool, text: string, error?: string, method?: string} */
    private function runTesseractPdf(string $path): array
    {
        $tmp = sys_get_temp_dir() . '/p1960_ocr_' . bin2hex(random_bytes(4));
        if (!mkdir($tmp) && !is_dir($tmp)) {
            return ['ok' => false, 'text' => '', 'error' => 'cannot create temp dir'];
        }
        try {
            $prefix = $tmp . '/page';
            $cmd = sprintf(
                '%s -png -r %d %s %s 2>/dev/null',
                escapeshellcmd($this->pdftoppmBin),
                $this->dpi,
                escapeshellarg($path),
                escapeshellarg($prefix)
            );
            exec($cmd, $discard, $code);
            $pages = glob($prefix . '-*.png') ?: [];
            sort($pages, SORT_NATURAL);
            if ($pages === []) {
                return ['ok' => false, 'text' => '', 'error' => 'pdftoppm produced no pages', 'method' => 'tesseract'];
            }
            $chunks = [];
            foreach ($pages as $page) {
                $r = $this->runTesseractImage($page);
                if (!$r['ok']) {
                    return $r;
                }
                $chunks[] = $r['text'];
            }

            return ['ok' => true, 'text' => implode("\n\n", $chunks), 'method' => 'tesseract'];
        } finally {
            foreach (glob($tmp . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($tmp);
        }
    }

    /** @return array{ok: bool, text: string, error?: string, method?: string} */
    private function runTesseractImage(string $path): array
    {
        $cmd = sprintf(
            '%s %s stdout -l eng 2>/dev/null',
            escapeshellcmd($this->tesseractBin),
            escapeshellarg($path)
        );
        $out = [];
        $code = 0;
        exec($cmd, $out, $code);
        $text = implode("\n", $out);
        if ($code !== 0 && trim($text) === '') {
            return ['ok' => false, 'text' => '', 'error' => 'tesseract failed', 'method' => 'tesseract'];
        }

        return ['ok' => true, 'text' => $text, 'method' => 'tesseract'];
    }
}
