<?php
declare(strict_types=1);

namespace Project1960\Tests\Unit\CourtListener;

use PHPUnit\Framework\TestCase;
use Project1960\CourtListener\TesseractOcrEngine;

final class TesseractOcrEngineTest extends TestCase
{
    private string $binDir;

    protected function setUp(): void
    {
        $this->binDir = sys_get_temp_dir() . '/p1960_ocrbin_' . bin2hex(random_bytes(4));
        mkdir($this->binDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->binDir . '/*') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->binDir);
    }

    private function writeBin(string $name, string $body): string
    {
        $path = $this->binDir . '/' . $name;
        file_put_contents($path, "#!/bin/sh\n" . $body . "\n");
        chmod($path, 0755);

        return $path;
    }

    public function testReadsPlaintextFiles(): void
    {
        $path = sys_get_temp_dir() . '/p1960_tess_' . bin2hex(random_bytes(4)) . '.txt';
        file_put_contents($path, "Hello OCR fixture\n");
        try {
            $engine = new TesseractOcrEngine();
            $r = $engine->extractText($path);
            self::assertTrue($r['ok']);
            self::assertSame('plaintext', $r['method']);
            self::assertStringContainsString('Hello OCR fixture', $r['text']);
        } finally {
            unlink($path);
        }
    }

    public function testMissingFileFails(): void
    {
        $engine = new TesseractOcrEngine();
        $r = $engine->extractText('/tmp/does-not-exist-' . bin2hex(random_bytes(4)) . '.pdf');
        self::assertFalse($r['ok']);
        self::assertSame('', $r['text']);
    }

    public function testPdfUsesPdftotextWhenNonEmpty(): void
    {
        $pdf = $this->binDir . '/doc.pdf';
        file_put_contents($pdf, '%PDF-1.4');
        $pdftotext = $this->writeBin('pdftotext', 'echo "LAYER TEXT"');
        $engine = new TesseractOcrEngine($pdftotext, $this->binDir . '/pdftoppm', $this->binDir . '/tesseract');
        $r = $engine->extractText($pdf);
        self::assertTrue($r['ok']);
        self::assertSame('pdftotext', $r['method']);
        self::assertStringContainsString('LAYER TEXT', $r['text']);
    }

    public function testPdfFallsBackToTesseractWhenPdftotextEmpty(): void
    {
        $pdf = $this->binDir . '/scan.pdf';
        file_put_contents($pdf, '%PDF-1.4');
        $pdftotext = $this->writeBin('pdftotext', 'exit 0');
        $pdftoppm = $this->writeBin('pdftoppm', 'touch "${5}-1.png"');
        $tesseract = $this->writeBin('tesseract', 'echo "SCANNED TEXT"');
        $engine = new TesseractOcrEngine($pdftotext, $pdftoppm, $tesseract);
        $r = $engine->extractText($pdf);
        self::assertTrue($r['ok']);
        self::assertSame('tesseract', $r['method']);
        self::assertStringContainsString('SCANNED TEXT', $r['text']);
    }

    public function testImagePathUsesTesseract(): void
    {
        $img = $this->binDir . '/page.png';
        file_put_contents($img, 'png');
        $tesseract = $this->writeBin('tesseract', 'echo "IMG TEXT"');
        $engine = new TesseractOcrEngine('pdftotext', 'pdftoppm', $tesseract);
        $r = $engine->extractText($img);
        self::assertTrue($r['ok']);
        self::assertSame('tesseract', $r['method']);
        self::assertStringContainsString('IMG TEXT', $r['text']);
    }

    public function testPdftoppmNoPagesFails(): void
    {
        $pdf = $this->binDir . '/empty.pdf';
        file_put_contents($pdf, '%PDF');
        $pdftotext = $this->writeBin('pdftotext', 'exit 0');
        $pdftoppm = $this->writeBin('pdftoppm', 'exit 0');
        $tesseract = $this->writeBin('tesseract', 'echo hi');
        $engine = new TesseractOcrEngine($pdftotext, $pdftoppm, $tesseract);
        $r = $engine->extractText($pdf);
        self::assertFalse($r['ok']);
        self::assertStringContainsString('no pages', (string) ($r['error'] ?? ''));
    }

    public function testTesseractImageFailure(): void
    {
        $img = $this->binDir . '/bad.png';
        file_put_contents($img, 'x');
        $tesseract = $this->writeBin('tesseract', 'exit 1');
        $engine = new TesseractOcrEngine('pdftotext', 'pdftoppm', $tesseract);
        $r = $engine->extractText($img);
        self::assertFalse($r['ok']);
    }

    public function testEmptyOcrAfterRasterizeFails(): void
    {
        $pdf = $this->binDir . '/blank.pdf';
        file_put_contents($pdf, '%PDF');
        $pdftotext = $this->writeBin('pdftotext', 'exit 0');
        $pdftoppm = $this->writeBin('pdftoppm', 'touch "${5}-1.png"');
        $tesseract = $this->writeBin('tesseract', 'exit 0');
        $engine = new TesseractOcrEngine($pdftotext, $pdftoppm, $tesseract);
        $r = $engine->extractText($pdf);
        self::assertFalse($r['ok']);
        self::assertStringContainsString('no extractable', (string) ($r['error'] ?? ''));
    }

    public function testPdftotextHardFailStillTriesOcr(): void
    {
        $pdf = $this->binDir . '/broken.pdf';
        file_put_contents($pdf, '%PDF');
        $pdftotext = $this->writeBin('pdftotext', 'echo err 1>&2; exit 1');
        $pdftoppm = $this->writeBin('pdftoppm', 'touch "${5}-1.png"');
        $tesseract = $this->writeBin('tesseract', 'echo recovered');
        $engine = new TesseractOcrEngine($pdftotext, $pdftoppm, $tesseract);
        $r = $engine->extractText($pdf);
        self::assertTrue($r['ok']);
        self::assertStringContainsString('recovered', $r['text']);
    }
}
