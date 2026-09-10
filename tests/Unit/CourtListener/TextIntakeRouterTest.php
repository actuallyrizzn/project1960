<?php
declare(strict_types=1);

namespace Project1960\Tests\Unit\CourtListener;

use PHPUnit\Framework\TestCase;
use Project1960\CourtListener\TextIntakeRouter;
use Project1960\CourtListenerDocumentStore;
use Project1960\CourtListenerDocketStore;
use Project1960\Schema;
use PDO;

final class TextIntakeRouterTest extends TestCase
{
    private string $path;
    private PDO $pdo;
    private TextIntakeRouter $router;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/p1960_ti_' . bin2hex(random_bytes(4)) . '.db';
        $this->pdo = new PDO('sqlite:' . $this->path);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        Schema::migrate($this->pdo);
        (new CourtListenerDocketStore($this->pdo))->upsertDocket(['cl_docket_id' => 1]);
        $store = new CourtListenerDocumentStore($this->pdo);
        $store->upsertDocument(['cl_document_id' => 5, 'cl_docket_id' => 1]);
        $store->upsertDocument(['cl_document_id' => 6, 'cl_docket_id' => 1]);
        $this->router = new TextIntakeRouter($store);
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testPlaintextPathStoresTextAndMarksDone(): void
    {
        $r = $this->router->route([
            'cl_document_id' => 5,
            'plain_text' => 'Hello filing',
        ]);
        self::assertSame('plaintext', $r['path']);
        self::assertSame('done', $r['ocr_status']);
        $text = $this->pdo->query(
            'SELECT full_text FROM courtlistener_document_text WHERE cl_document_id=5'
        )->fetchColumn();
        self::assertSame('Hello filing', $text);
        $doc = (new CourtListenerDocumentStore($this->pdo))->getDocument(5);
        self::assertSame('done', $doc['ocr_status']);
    }

    public function testMissingTextQueuesOcr(): void
    {
        $r = $this->router->route(['cl_document_id' => 6]);
        self::assertSame('ocr_queue', $r['path']);
        self::assertSame('pending', $r['ocr_status']);
        $doc = (new CourtListenerDocumentStore($this->pdo))->getDocument(6);
        self::assertSame('pending', $doc['ocr_status']);
    }
}
