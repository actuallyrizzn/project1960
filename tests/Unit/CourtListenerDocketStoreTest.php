<?php
declare(strict_types=1);

namespace Project1960\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Project1960\CourtListenerDocketStore;
use Project1960\Schema;
use PDO;

final class CourtListenerDocketStoreTest extends TestCase
{
    private string $path;
    private PDO $pdo;
    private CourtListenerDocketStore $store;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/p1960_cl_' . bin2hex(random_bytes(4)) . '.db';
        $this->pdo = new PDO('sqlite:' . $this->path);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        Schema::migrate($this->pdo);
        $this->pdo->exec(
            "INSERT INTO cases (id, title) VALUES ('case-a', 'Alpha'), ('case-b', 'Beta')"
        );
        $this->store = new CourtListenerDocketStore($this->pdo);
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testSchemaCreatesCourtListenerTablesIdempotently(): void
    {
        Schema::migrate($this->pdo);
        $tables = $this->pdo->query(
            "SELECT name FROM sqlite_master WHERE type='table' ORDER BY name"
        )->fetchAll(PDO::FETCH_COLUMN);
        self::assertContains('courtlistener_dockets', $tables);
        self::assertContains('case_courtlistener_links', $tables);
    }

    public function testUpsertDocketAndGet(): void
    {
        $this->store->upsertDocket([
            'cl_docket_id' => 42,
            'court_id' => 'nysd',
            'docket_number' => '1:24-cr-001',
            'case_name' => 'United States v. Example',
            'raw_json' => '{"id":42}',
        ]);
        $row = $this->store->getDocket(42);
        self::assertNotNull($row);
        self::assertSame(42, (int) $row['cl_docket_id']);
        self::assertSame('nysd', $row['court_id']);
        self::assertSame('1:24-cr-001', $row['docket_number']);

        $this->store->upsertDocket([
            'cl_docket_id' => 42,
            'court_id' => 'nysd',
            'docket_number' => '1:24-cr-001',
            'case_name' => 'United States v. Example Updated',
            'raw_json' => null,
        ]);
        $row2 = $this->store->getDocket(42);
        self::assertSame('United States v. Example Updated', $row2['case_name']);
        self::assertSame('{"id":42}', $row2['raw_json']);
    }

    public function testUpsertCaseLinkAndQueryJoined(): void
    {
        $this->store->upsertDocket([
            'cl_docket_id' => 100,
            'court_id' => 'cacd',
            'docket_number' => '2:23-cr-9',
            'case_name' => 'US v. Linked',
        ]);
        $this->store->upsertCaseLink([
            'case_id' => 'case-a',
            'cl_docket_id' => 100,
            'match_confidence' => 0.91,
            'match_method' => 'search_docket_caption',
            'raw_json' => '{"q":"1960"}',
        ]);
        $this->store->upsertCaseLink([
            'case_id' => 'case-a',
            'cl_docket_id' => 100,
            'match_confidence' => 0.95,
            'match_method' => 'search_docket_caption',
        ]);

        $links = $this->store->linksForCase('case-a');
        self::assertCount(1, $links);
        self::assertSame(100, (int) $links[0]['cl_docket_id']);
        self::assertEqualsWithDelta(0.95, (float) $links[0]['match_confidence'], 0.001);
        self::assertSame('cacd', $links[0]['court_id']);
        self::assertSame('US v. Linked', $links[0]['cl_case_name']);
        self::assertSame('{"q":"1960"}', $links[0]['raw_json']);
    }

    public function testLinksOrderedByConfidence(): void
    {
        $this->store->upsertDocket(['cl_docket_id' => 1, 'case_name' => 'Low']);
        $this->store->upsertDocket(['cl_docket_id' => 2, 'case_name' => 'High']);
        $this->store->upsertCaseLink([
            'case_id' => 'case-b',
            'cl_docket_id' => 1,
            'match_confidence' => 0.2,
            'match_method' => 'fuzzy',
        ]);
        $this->store->upsertCaseLink([
            'case_id' => 'case-b',
            'cl_docket_id' => 2,
            'match_confidence' => 0.8,
            'match_method' => 'exact_number',
        ]);
        $links = $this->store->linksForCase('case-b');
        self::assertSame(2, (int) $links[0]['cl_docket_id']);
        self::assertSame(1, (int) $links[1]['cl_docket_id']);
    }

    public function testRejectsInvalidIds(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->store->upsertDocket(['cl_docket_id' => 0]);
    }

    public function testRejectsInvalidCaseLink(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->store->upsertCaseLink([
            'case_id' => '',
            'cl_docket_id' => 1,
        ]);
    }

    public function testGetMissingDocketReturnsNull(): void
    {
        self::assertNull($this->store->getDocket(99999));
    }
}
