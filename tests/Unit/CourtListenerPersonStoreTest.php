<?php
declare(strict_types=1);

namespace Project1960\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Project1960\CourtListenerPersonStore;
use Project1960\Schema;
use PDO;

final class CourtListenerPersonStoreTest extends TestCase
{
    private string $path;
    private PDO $pdo;
    private CourtListenerPersonStore $store;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/p1960_clperson_' . bin2hex(random_bytes(4)) . '.db';
        $this->pdo = new PDO('sqlite:' . $this->path);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        Schema::migrate($this->pdo);
        $this->pdo->exec(
            "INSERT INTO cases (id, title) VALUES ('case-a', 'A'), ('case-b', 'B')"
        );
        $this->store = new CourtListenerPersonStore($this->pdo);
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testSchemaHasPersonTables(): void
    {
        $tables = $this->pdo->query(
            "SELECT name FROM sqlite_master WHERE type='table'"
        )->fetchAll(PDO::FETCH_COLUMN);
        self::assertContains('cl_persons', $tables);
        self::assertContains('cl_person_aliases', $tables);
        self::assertContains('cl_person_case_edges', $tables);
    }

    public function testNormalizeName(): void
    {
        self::assertSame(
            'john q. public',
            CourtListenerPersonStore::normalizeName('  John   Q. Public  ')
        );
    }

    public function testUpsertAndFindByNormalizedName(): void
    {
        $id = $this->store->upsertPerson([
            'display_name' => 'Jane Doe',
            'role' => 'defendant',
            'organization' => 'Acme LLC',
            'confidence' => 0.91,
        ]);
        self::assertGreaterThan(0, $id);

        $hits = $this->store->findByNormalizedName('JANE DOE');
        self::assertCount(1, $hits);
        self::assertSame('jane doe', $hits[0]['normalized_name']);
        self::assertSame('defendant', $hits[0]['role']);
        self::assertSame('Acme LLC', $hits[0]['organization']);

        $id2 = $this->store->upsertPerson([
            'display_name' => 'Jane Doe',
            'role' => 'defendant',
            'organization' => 'Acme Corp',
            'confidence' => 0.95,
        ]);
        self::assertSame($id, $id2);
        $hits = $this->store->findByNormalizedName('jane doe');
        self::assertSame('Acme Corp', $hits[0]['organization']);
    }

    public function testRejectsInvalidRole(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->store->upsertPerson([
            'display_name' => 'X',
            'role' => 'villain',
        ]);
    }

    public function testAliasAndCrossCaseQuery(): void
    {
        $pid = $this->store->upsertPerson([
            'display_name' => 'Robert Smith',
            'role' => 'attorney',
        ]);
        $this->store->addAlias($pid, 'Bob Smith');
        $this->store->linkCase([
            'person_id' => $pid,
            'case_id' => 'case-a',
            'role' => 'attorney',
            'confidence' => 0.8,
        ]);
        $this->store->linkCase([
            'person_id' => $pid,
            'case_id' => 'case-b',
            'role' => 'attorney',
        ]);

        $cases = $this->store->caseIdsForNormalizedName('bob smith');
        self::assertSame(['case-a', 'case-b'], $cases);

        $cases2 = $this->store->caseIdsForNormalizedName('Robert Smith');
        self::assertSame(['case-a', 'case-b'], $cases2);
    }

    public function testEmptyNameFindReturnsEmpty(): void
    {
        self::assertSame([], $this->store->findByNormalizedName('   '));
        self::assertSame([], $this->store->caseIdsForNormalizedName(''));
    }
}
