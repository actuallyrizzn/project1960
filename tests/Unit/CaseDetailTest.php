<?php
declare(strict_types=1);

namespace Project1960\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Project1960\App;
use Project1960\CaseDetailLoader;
use Project1960\FixtureDatabase;
use Project1960\Request;
use Project1960\Router;
use Project1960\Response;

final class CaseDetailTest extends TestCase
{
    public function testLoaderReturnsCaseAndEnrichment(): void
    {
        $fixture = new FixtureDatabase();
        $loader = new CaseDetailLoader($fixture->pdo());
        $loaded = $loader->load('fixture-case-1');

        self::assertNotNull($loaded);
        self::assertSame('United States v. Fixture', $loaded['case']['title']);
        self::assertNotNull($loaded['enrichment']['metadata']);
        self::assertCount(1, $loaded['enrichment']['participants']);
        self::assertCount(1, $loaded['enrichment']['charges']);
        self::assertSame([], $loaded['enrichment']['quotes']);
        $fixture->destroy();
    }

    public function testLoaderMissingReturnsNull(): void
    {
        $fixture = new FixtureDatabase();
        $loader = new CaseDetailLoader($fixture->pdo());
        self::assertNull($loader->load('no-such-case'));
        $fixture->destroy();
    }

    public function testAppCaseDetailAnd404(): void
    {
        $fixture = new FixtureDatabase();
        $app = new App(null, null, $fixture->pdo());

        $ok = $app->handle(new Request('GET', '/case/fixture-case-1'));
        self::assertSame(200, $ok->status);
        self::assertStringContainsString('Jane Fixture', $ok->body);
        self::assertStringContainsString('Unlicensed money transmission', $ok->body);
        self::assertStringContainsString('Case metadata', $ok->body);

        $missing = $app->handle(new Request('GET', '/case/missing'));
        self::assertSame(404, $missing->status);

        $fixture->destroy();
    }

    public function testAppCaseDetailWithoutDatabaseReturns503(): void
    {
        $app = new App();
        $response = $app->handle(new Request('GET', '/case/fixture-case-1'));
        self::assertSame(503, $response->status);
    }

    public function testLoaderMissingTablesYieldEmptyEnrichment(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $pdo->exec(
            'CREATE TABLE cases (
                id TEXT PRIMARY KEY,
                title TEXT,
                date TEXT,
                body TEXT,
                url TEXT,
                mentions_1960 INTEGER DEFAULT 0,
                verified_1960 INTEGER
            )'
        );
        $pdo->exec("INSERT INTO cases (id, title) VALUES ('solo', 'Solo case')");

        $loader = new CaseDetailLoader($pdo);
        $loaded = $loader->load('solo');
        self::assertNotNull($loaded);
        self::assertNull($loaded['enrichment']['metadata']);
        self::assertSame([], $loaded['enrichment']['participants']);
        self::assertSame([], $loaded['enrichment']['charges']);
    }

    public function testFetchRowsRejectsUnknownTableViaReflection(): void
    {
        $fixture = new FixtureDatabase();
        $loader = new CaseDetailLoader($fixture->pdo());
        $method = new \ReflectionMethod(CaseDetailLoader::class, 'fetchRows');
        $method->setAccessible(true);
        /** @var list<array<string, mixed>> $rows */
        $rows = $method->invoke($loader, 'not_a_real_table', 'fixture-case-1');
        self::assertSame([], $rows);
        $fixture->destroy();
    }
}
