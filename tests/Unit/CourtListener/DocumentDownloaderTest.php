<?php
declare(strict_types=1);

namespace Project1960\Tests\Unit\CourtListener;

use PHPUnit\Framework\TestCase;
use Project1960\CourtListener\DocumentDownloader;
use Project1960\CourtListener\HttpFetcher;
use Project1960\CourtListenerDocumentStore;
use Project1960\CourtListenerDocketStore;
use Project1960\Schema;
use PDO;

final class DocumentDownloaderTest extends TestCase
{
    private string $dbPath;
    private string $storage;
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/p1960_dl_' . bin2hex(random_bytes(4)) . '.db';
        $this->storage = sys_get_temp_dir() . '/p1960_cldocs_' . bin2hex(random_bytes(4));
        $this->pdo = new PDO('sqlite:' . $this->dbPath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        Schema::migrate($this->pdo);
        (new CourtListenerDocketStore($this->pdo))->upsertDocket(['cl_docket_id' => 1, 'case_name' => 'X']);
        $store = new CourtListenerDocumentStore($this->pdo);
        $store->upsertDocument([
            'cl_document_id' => 10,
            'cl_docket_id' => 1,
            'filepath_or_url' => 'https://storage.courtlistener.com/docs/10.pdf',
            'description' => 'Free',
        ]);
        $store->upsertDocument([
            'cl_document_id' => 11,
            'cl_docket_id' => 1,
            'filepath_or_url' => 'https://pacer.gov/doc/11',
            'description' => 'PACER',
        ]);
    }

    protected function tearDown(): void
    {
        if (is_file($this->dbPath)) {
            unlink($this->dbPath);
        }
        if (is_dir($this->storage)) {
            foreach (glob($this->storage . '/*') ?: [] as $f) {
                unlink($f);
            }
            rmdir($this->storage);
        }
    }

    private function downloader(HttpFetcher $http): DocumentDownloader
    {
        return new DocumentDownloader(
            $http,
            new CourtListenerDocumentStore($this->pdo),
            $this->pdo,
            $this->storage,
        );
    }

    public function testSchemaHasDownloadColumns(): void
    {
        $cols = $this->pdo->query('PRAGMA table_info(courtlistener_documents)')
            ->fetchAll(PDO::FETCH_ASSOC);
        $names = array_column($cols, 'name');
        self::assertContains('local_path', $names);
        self::assertContains('content_sha256', $names);
        self::assertContains('download_status', $names);
    }

    public function testDownloadsFreeUrlAndRecordsHash(): void
    {
        $http = new class implements HttpFetcher {
            public function get(string $url): array
            {
                return ['ok' => true, 'status' => 200, 'body' => '%PDF-1.4 fake'];
            }
        };
        $dl = $this->downloader($http);
        $result = $dl->downloadOne(10);
        self::assertSame(DocumentDownloader::STATUS_DONE, $result['status']);
        self::assertFileExists($result['path']);
        self::assertSame(hash('sha256', '%PDF-1.4 fake'), $result['sha256']);

        $row = (new CourtListenerDocumentStore($this->pdo))->getDocument(10);
        self::assertSame('done', $row['download_status']);
        self::assertSame($result['path'], $row['local_path']);
    }

    public function testSkipsPacerUrl(): void
    {
        $http = new class implements HttpFetcher {
            public function get(string $url): array
            {
                self::fail('should not fetch PACER');
            }
        };
        $dl = $this->downloader($http);
        $result = $dl->downloadOne(11);
        self::assertSame(DocumentDownloader::STATUS_SKIPPED_PACER, $result['status']);
        $row = (new CourtListenerDocumentStore($this->pdo))->getDocument(11);
        self::assertSame('skipped_pacer', $row['download_status']);
    }

    public function testFailedHttp(): void
    {
        $http = new class implements HttpFetcher {
            public function get(string $url): array
            {
                return ['ok' => false, 'status' => 403, 'body' => '', 'error' => 'forbidden'];
            }
        };
        $result = $this->downloader($http)->downloadOne(10);
        self::assertSame(DocumentDownloader::STATUS_FAILED, $result['status']);
    }

    public function testPendingListAndDryRun(): void
    {
        $http = new class implements HttpFetcher {
            public function get(string $url): array
            {
                self::fail('dry-run');
            }
        };
        $dl = $this->downloader($http);
        $pending = $dl->pendingDocuments(10);
        self::assertGreaterThanOrEqual(1, count($pending));
        $dry = $dl->downloadOne(10, dryRun: true);
        self::assertSame(DocumentDownloader::STATUS_DONE, $dry['status']);
        self::assertNull((new CourtListenerDocumentStore($this->pdo))->getDocument(10)['local_path'] ?? null);
    }

    public function testIsFreeUrlHelpers(): void
    {
        $dl = $this->downloader(new class implements HttpFetcher {
            public function get(string $url): array
            {
                return ['ok' => true, 'status' => 200, 'body' => 'x'];
            }
        });
        self::assertTrue($dl->isFreeUrl('https://www.courtlistener.com/x'));
        self::assertFalse($dl->isFreeUrl('/relative/path.pdf'));
        self::assertTrue($dl->looksLikePacerOnly('https://ecf.pacer.gov/x'));
        self::assertStringContainsString('cl-docs', DocumentDownloader::defaultStorageDir('/tmp/proj'));
    }

    public function testEmptyUrlAndMissingDoc(): void
    {
        $store = new CourtListenerDocumentStore($this->pdo);
        $store->upsertDocument([
            'cl_document_id' => 12,
            'cl_docket_id' => 1,
            'filepath_or_url' => '',
        ]);
        // Force empty via SQL (upsert may store '')
        $this->pdo->exec("UPDATE courtlistener_documents SET filepath_or_url = '' WHERE cl_document_id = 12");
        $dl = $this->downloader(new class implements HttpFetcher {
            public function get(string $url): array
            {
                return ['ok' => true, 'status' => 200, 'body' => 'x'];
            }
        });
        $r = $dl->downloadOne(12);
        self::assertSame(DocumentDownloader::STATUS_FAILED, $r['status']);

        $this->expectException(\InvalidArgumentException::class);
        $dl->downloadOne(999);
    }

    public function testRelativeUrlSkippedAsNonFree(): void
    {
        $store = new CourtListenerDocumentStore($this->pdo);
        $store->upsertDocument([
            'cl_document_id' => 13,
            'cl_docket_id' => 1,
            'filepath_or_url' => 'storage/foo.pdf',
        ]);
        $dl = $this->downloader(new class implements HttpFetcher {
            public function get(string $url): array
            {
                self::fail('no fetch');
            }
        });
        self::assertSame(
            DocumentDownloader::STATUS_SKIPPED_PACER,
            $dl->downloadOne(13)['status']
        );
    }

    public function testHtmlExtensionGuess(): void
    {
        $store = new CourtListenerDocumentStore($this->pdo);
        $store->upsertDocument([
            'cl_document_id' => 14,
            'cl_docket_id' => 1,
            'filepath_or_url' => 'https://www.courtlistener.com/docket/1/doc.html',
        ]);
        $dl = $this->downloader(new class implements HttpFetcher {
            public function get(string $url): array
            {
                return ['ok' => true, 'status' => 200, 'body' => '<html></html>'];
            }
        });
        $r = $dl->downloadOne(14);
        self::assertSame(DocumentDownloader::STATUS_DONE, $r['status']);
        self::assertStringEndsWith('.html', $r['path']);
    }
}
