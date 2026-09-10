<?php
declare(strict_types=1);

namespace Project1960\Tests\Unit\CourtListener;

use PHPUnit\Framework\TestCase;
use Project1960\CourtListener\OcrEngine;
use Project1960\CourtListener\OcrWorker;
use Project1960\CourtListenerDocumentStore;
use Project1960\CourtListenerDocketStore;
use Project1960\Schema;
use PDO;

final class OcrWorkerTest extends TestCase
{
    private string $dbPath;
    private string $docFile;
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/p1960_ocr_' . bin2hex(random_bytes(4)) . '.db';
        $this->docFile = sys_get_temp_dir() . '/p1960_ocr_doc_' . bin2hex(random_bytes(4)) . '.txt';
        file_put_contents($this->docFile, "INDICTMENT\nUnited States v. Jane Doe\n");
        $this->pdo = new PDO('sqlite:' . $this->dbPath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        Schema::migrate($this->pdo);
        (new CourtListenerDocketStore($this->pdo))->upsertDocket(['cl_docket_id' => 1, 'case_name' => 'X']);
        $store = new CourtListenerDocumentStore($this->pdo);
        $store->upsertDocument([
            'cl_document_id' => 100,
            'cl_docket_id' => 1,
            'ocr_status' => CourtListenerDocumentStore::OCR_PENDING,
            'filepath_or_url' => 'https://example.test/100.pdf',
        ]);
        $this->pdo->exec(
            "UPDATE courtlistener_documents SET local_path = " . $this->pdo->quote($this->docFile) .
            " WHERE cl_document_id = 100"
        );
        $store->upsertDocument([
            'cl_document_id' => 101,
            'cl_docket_id' => 1,
            'ocr_status' => CourtListenerDocumentStore::OCR_PENDING,
        ]);
    }

    protected function tearDown(): void
    {
        if (is_file($this->dbPath)) {
            unlink($this->dbPath);
        }
        if (is_file($this->docFile)) {
            unlink($this->docFile);
        }
    }

    private function fakeEngine(bool $ok = true, string $text = 'OCR TEXT'): OcrEngine
    {
        return new class ($ok, $text) implements OcrEngine {
            public function __construct(private bool $ok, private string $text)
            {
            }

            public function extractText(string $absolutePath): array
            {
                if (!$this->ok) {
                    return ['ok' => false, 'text' => '', 'error' => 'boom', 'method' => 'fake'];
                }

                return ['ok' => true, 'text' => $this->text . ' from ' . basename($absolutePath), 'method' => 'fake'];
            }
        };
    }

    public function testProcessesPendingIntoFullText(): void
    {
        $worker = new OcrWorker($this->fakeEngine(), new CourtListenerDocumentStore($this->pdo), $this->pdo);
        $r = $worker->processOne(100);
        self::assertSame('done', $r['status']);
        $row = (new CourtListenerDocumentStore($this->pdo))->getDocument(100);
        self::assertSame('done', $row['ocr_status']);
        $text = $this->pdo->query(
            'SELECT full_text FROM courtlistener_document_text WHERE cl_document_id=100'
        )->fetchColumn();
        self::assertStringContainsString('OCR TEXT', (string) $text);
    }

    public function testFailsWithoutLocalPath(): void
    {
        $worker = new OcrWorker($this->fakeEngine(), new CourtListenerDocumentStore($this->pdo), $this->pdo);
        $r = $worker->processOne(101);
        self::assertSame('failed', $r['status']);
        self::assertSame('failed', (new CourtListenerDocumentStore($this->pdo))->getDocument(101)['ocr_status']);
    }

    public function testDryRunDoesNotMutate(): void
    {
        $worker = new OcrWorker($this->fakeEngine(), new CourtListenerDocumentStore($this->pdo), $this->pdo);
        $r = $worker->processOne(100, dryRun: true);
        self::assertSame('would_ocr', $r['status']);
        self::assertSame('pending', (new CourtListenerDocumentStore($this->pdo))->getDocument(100)['ocr_status']);
    }

    public function testEngineFailureMarksFailed(): void
    {
        $worker = new OcrWorker($this->fakeEngine(false), new CourtListenerDocumentStore($this->pdo), $this->pdo);
        $r = $worker->processOne(100);
        self::assertSame('failed', $r['status']);
    }

    public function testMetricsAndPendingList(): void
    {
        $worker = new OcrWorker($this->fakeEngine(), new CourtListenerDocumentStore($this->pdo), $this->pdo);
        $pending = $worker->pendingDocuments(10);
        self::assertCount(2, $pending);
        $m = $worker->metrics();
        self::assertSame(2, $m['pending']);
        $worker->processOne(100);
        $m2 = $worker->metrics();
        self::assertSame(1, $m2['done']);
        self::assertSame(1, $m2['pending']);
    }

    public function testIdempotentSkipWhenNotPending(): void
    {
        $store = new CourtListenerDocumentStore($this->pdo);
        $store->setOcrStatus(100, CourtListenerDocumentStore::OCR_DONE);
        $worker = new OcrWorker($this->fakeEngine(), $store, $this->pdo);
        $r = $worker->processOne(100);
        self::assertSame('done', $r['status']);
    }
}
