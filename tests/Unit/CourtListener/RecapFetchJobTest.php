<?php
declare(strict_types=1);

namespace Project1960\Tests\Unit\CourtListener;

use PHPUnit\Framework\TestCase;
use Project1960\CourtListener\RecapFetchGateway;
use Project1960\CourtListener\RecapFetchJob;
use Project1960\CourtListenerDocketStore;
use Project1960\CourtListenerDocumentStore;
use Project1960\Schema;
use PDO;

final class RecapFetchJobTest extends TestCase
{
    private string $path;
    private PDO $pdo;
    /** @var list<array<string, mixed>> */
    public array $calls = [];

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/p1960_rf_' . bin2hex(random_bytes(4)) . '.db';
        $this->pdo = new PDO('sqlite:' . $this->path);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        Schema::migrate($this->pdo);
        (new CourtListenerDocketStore($this->pdo))->upsertDocket(['cl_docket_id' => 1]);
        $store = new CourtListenerDocumentStore($this->pdo);
        $store->upsertDocument([
            'cl_document_id' => 1,
            'cl_docket_id' => 1,
            'description' => 'Indictment',
            'filepath_or_url' => 'pacer:1',
        ]);
        $store->upsertDocument([
            'cl_document_id' => 2,
            'cl_docket_id' => 1,
            'description' => 'Random notice',
            'filepath_or_url' => 'pacer:2',
        ]);
        $this->pdo->exec("UPDATE courtlistener_documents SET download_status='skipped_pacer'");
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    private function job(): RecapFetchJob
    {
        $gw = new class ($this) implements RecapFetchGateway {
            public function __construct(private RecapFetchJobTest $t)
            {
            }

            public function requestFetch(array $payload): array
            {
                $this->t->calls[] = $payload;

                return ['id' => 99];
            }
        };

        return new RecapFetchJob($gw, $this->pdo);
    }

    public function testDryRunNeverCallsGateway(): void
    {
        $stats = $this->job()->run(10, budget: 5, live: false);
        self::assertSame('dry-run', $stats['mode']);
        self::assertSame(1, $stats['would_fetch']);
        self::assertSame(0, $stats['fetched']);
        self::assertSame(1, $stats['skipped_allowlist']);
        self::assertSame([], $this->calls);
    }

    public function testLiveRespectsBudgetAndAllowlist(): void
    {
        $stats = $this->job()->run(10, budget: 1, live: true);
        self::assertSame(1, $stats['fetched']);
        self::assertCount(1, $this->calls);
        $row = (new CourtListenerDocumentStore($this->pdo))->getDocument(1);
        self::assertSame('fetch_requested', $row['download_status']);
    }

    public function testAllowlistHelper(): void
    {
        $j = $this->job();
        self::assertTrue($j->isAllowedDescription('Superseding Indictment'));
        self::assertFalse($j->isAllowedDescription('Certificate of Service'));
    }
}
