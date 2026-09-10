<?php
declare(strict_types=1);

namespace Project1960\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Project1960\CourtListenerDocumentStore;
use Project1960\CourtListenerDocketStore;
use Project1960\Schema;
use PDO;

final class CourtListenerDocumentStoreTest extends TestCase
{
    private string $path;
    private PDO $pdo;
    private CourtListenerDocumentStore $store;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/p1960_cldoc_' . bin2hex(random_bytes(4)) . '.db';
        $this->pdo = new PDO('sqlite:' . $this->path);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        Schema::migrate($this->pdo);
        (new CourtListenerDocketStore($this->pdo))->upsertDocket([
            'cl_docket_id' => 10,
            'case_name' => 'US v. Doc',
        ]);
        $this->store = new CourtListenerDocumentStore($this->pdo);
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testSchemaHasDocumentTables(): void
    {
        $tables = $this->pdo->query(
            "SELECT name FROM sqlite_master WHERE type='table'"
        )->fetchAll(PDO::FETCH_COLUMN);
        self::assertContains('courtlistener_documents', $tables);
        self::assertContains('courtlistener_document_pages', $tables);
        self::assertContains('courtlistener_document_text', $tables);
    }

    public function testUpsertAndOcrStatusTransitions(): void
    {
        $this->store->upsertDocument([
            'cl_document_id' => 55,
            'cl_docket_id' => 10,
            'entry_number' => '1',
            'description' => 'Indictment',
            'filepath_or_url' => 'https://example.test/doc.pdf',
            'mime' => 'application/pdf',
            'ocr_status' => CourtListenerDocumentStore::OCR_NONE,
            'byte_size' => 1024,
        ]);
        $row = $this->store->getDocument(55);
        self::assertNotNull($row);
        self::assertSame('none', $row['ocr_status']);

        $this->store->setOcrStatus(55, CourtListenerDocumentStore::OCR_PENDING);
        self::assertSame('pending', $this->store->getDocument(55)['ocr_status']);

        $this->store->setOcrStatus(55, CourtListenerDocumentStore::OCR_DONE);
        self::assertSame('done', $this->store->getDocument(55)['ocr_status']);

        $this->store->setOcrStatus(55, CourtListenerDocumentStore::OCR_FAILED);
        self::assertSame('failed', $this->store->getDocument(55)['ocr_status']);
    }

    public function testRejectsInvalidOcrStatus(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->store->upsertDocument([
            'cl_document_id' => 1,
            'ocr_status' => 'bogus',
        ]);
    }

    public function testPagesAndFullText(): void
    {
        $this->store->upsertDocument([
            'cl_document_id' => 77,
            'cl_docket_id' => 10,
            'has_plaintext' => true,
            'ocr_status' => CourtListenerDocumentStore::OCR_DONE,
        ]);
        $this->store->upsertPage(77, 1, 'page one');
        $this->store->upsertPage(77, 2, 'page two');
        $this->store->upsertPage(77, 1, 'page one revised');
        $pages = $this->store->pagesForDocument(77);
        self::assertCount(2, $pages);
        self::assertSame('page one revised', $pages[0]['page_text']);

        $this->store->upsertFullText(77, "page one revised\npage two");
        $text = $this->pdo->query(
            'SELECT full_text FROM courtlistener_document_text WHERE cl_document_id = 77'
        )->fetchColumn();
        self::assertSame("page one revised\npage two", $text);
    }

    public function testSetOcrStatusMissingDocument(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->store->setOcrStatus(999, CourtListenerDocumentStore::OCR_PENDING);
    }
}
