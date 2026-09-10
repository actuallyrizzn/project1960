<?php
declare(strict_types=1);

namespace Project1960\Tests\Unit\CourtListener;

use PHPUnit\Framework\TestCase;
use Project1960\CourtListener\DocumentIngestor;
use Project1960\CourtListener\IngestCliOptions;
use Project1960\CourtListener\IngestGateway;
use Project1960\CourtListenerDocumentStore;
use Project1960\CourtListenerDocketStore;
use Project1960\Schema;
use PDO;

final class DocumentIngestorTest extends TestCase
{
    private string $path;
    private PDO $pdo;
    /** @var array<string, array{results: list<array<string, mixed>>}> */
    private array $responses = [];

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/p1960_ingest_' . bin2hex(random_bytes(4)) . '.db';
        $this->pdo = new PDO('sqlite:' . $this->path);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        Schema::migrate($this->pdo);
        $this->pdo->exec("INSERT INTO cases (id, title) VALUES ('c1', 'Test')");
        (new CourtListenerDocketStore($this->pdo))->upsertDocket([
            'cl_docket_id' => 100,
            'case_name' => 'US v. Test',
        ]);
        (new CourtListenerDocketStore($this->pdo))->upsertCaseLink([
            'case_id' => 'c1',
            'cl_docket_id' => 100,
            'match_confidence' => 0.9,
            'match_method' => 'auto',
        ]);
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    private function ingestor(): DocumentIngestor
    {
        $gw = new class ($this) implements IngestGateway {
            public function __construct(private DocumentIngestorTest $t)
            {
            }

            public function listDocketEntries(array $params): array
            {
                return $this->t->getResponse('entries');
            }

            public function listRecapDocuments(array $params): array
            {
                return $this->t->getResponse('docs');
            }
        };

        return new DocumentIngestor($gw, new CourtListenerDocumentStore($this->pdo), $this->pdo);
    }

    /** @return array{results: list<array<string, mixed>>} */
    public function getResponse(string $key): array
    {
        return $this->responses[$key] ?? ['results' => []];
    }

    public function testLinkedDocketIds(): void
    {
        $ids = $this->ingestor()->linkedDocketIds(10);
        self::assertSame([100], $ids);
    }

    public function testIngestUpsertsFromRecapAndEmbeddedEntries(): void
    {
        $this->responses['docs'] = [
            'results' => [
                [
                    'id' => 501,
                    'description' => 'Indictment',
                    'filepath_local' => '/storage/501.pdf',
                    'plain_text' => 'text',
                    'file_size' => 2048,
                ],
            ],
        ];
        $this->responses['entries'] = [
            'results' => [
                [
                    'entry_number' => '3',
                    'description' => 'Motion',
                    'recap_documents' => [
                        [
                            'id' => 502,
                            'description' => 'Motion PDF',
                            'filepath_ia' => 'https://example.test/502.pdf',
                        ],
                    ],
                ],
            ],
        ];

        $result = $this->ingestor()->ingestDocket(100, dryRun: false);
        self::assertSame(2, $result['documents_upserted']);
        self::assertSame(1, $result['entries_seen']);

        $store = new CourtListenerDocumentStore($this->pdo);
        $a = $store->getDocument(501);
        self::assertNotNull($a);
        self::assertSame('100', (string) $a['cl_docket_id']);
        self::assertSame(1, (int) $a['has_plaintext']);

        $b = $store->getDocument(502);
        self::assertNotNull($b);
        self::assertSame('3', $b['entry_number']);
    }

    public function testDryRunDoesNotWrite(): void
    {
        $this->responses['docs'] = [
            'results' => [['id' => 777, 'description' => 'X']],
        ];
        $result = $this->ingestor()->ingestDocket(100, dryRun: true);
        self::assertSame(1, $result['documents_upserted']);
        self::assertNull((new CourtListenerDocumentStore($this->pdo))->getDocument(777));
    }

    public function testIdempotentUpsert(): void
    {
        $this->responses['docs'] = [
            'results' => [['id' => 9, 'description' => 'First']],
        ];
        $this->ingestor()->ingestDocket(100);
        $this->responses['docs'] = [
            'results' => [['id' => 9, 'description' => 'Updated']],
        ];
        $this->ingestor()->ingestDocket(100);
        $row = (new CourtListenerDocumentStore($this->pdo))->getDocument(9);
        self::assertSame('Updated', $row['description']);
    }

    public function testMapRecapRowSkipsInvalidId(): void
    {
        self::assertNull($this->ingestor()->mapRecapRow(['description' => 'x'], 100));
    }

    public function testIngestEmptyResults(): void
    {
        $this->responses['docs'] = ['results' => []];
        $this->responses['entries'] = ['results' => 'bad'];
        $result = $this->ingestor()->ingestDocket(100);
        self::assertSame(0, $result['documents_upserted']);
        self::assertSame(0, $result['entries_seen']);
    }

    public function testRejectsBadDocketId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ingestor()->ingestDocket(0);
    }

    public function testIngestCliOptions(): void
    {
        $o = IngestCliOptions::fromArgv(['ingest-docs.php', '--limit=4', '--wait=6', '--dry-run']);
        self::assertSame(4, $o->limit);
        self::assertSame(6, $o->waitSeconds);
        self::assertTrue($o->dryRun);

        $o2 = IngestCliOptions::fromArgv(['ingest-docs.php', '--limit', '2', '--wait', '1', '-v', '-h', 'noise']);
        self::assertSame(2, $o2->limit);
        self::assertSame(1, $o2->waitSeconds);
        self::assertTrue($o2->verbose);
        self::assertTrue($o2->help);

        $o3 = IngestCliOptions::fromGetopt(['limit' => '0', 'wait' => 'nope']);
        self::assertSame(1, $o3->limit);
        self::assertSame(3, $o3->waitSeconds);

        self::assertStringContainsString('--wait', IngestCliOptions::helpText());
    }

    public function testSdkIngestGatewayDelegates(): void
    {
        $entriesApi = $this->getMockBuilder(\CourtListener\Api\DocketEntries::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['listDocketEntries'])
            ->getMock();
        $entriesApi->method('listDocketEntries')->willReturn(['results' => [['id' => 1]]]);

        $docsApi = $this->getMockBuilder(\CourtListener\Api\RecapDocuments::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['listRecapDocuments'])
            ->getMock();
        $docsApi->method('listRecapDocuments')->willReturn(['results' => [['id' => 2]]]);

        $client = $this->getMockBuilder(\CourtListener\CourtListenerClient::class)
            ->disableOriginalConstructor()
            ->getMock();
        $client->docketEntries = $entriesApi;
        $client->recapDocuments = $docsApi;

        $gw = new \Project1960\CourtListener\SdkIngestGateway($client);
        self::assertSame(1, $gw->listDocketEntries(['docket' => 1])['results'][0]['id']);
        self::assertSame(2, $gw->listRecapDocuments(['docket' => 1])['results'][0]['id']);
    }
}
