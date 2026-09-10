<?php
declare(strict_types=1);

namespace Project1960\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Project1960\FixtureDatabase;
use Project1960\Schema;
use PDO;

final class SchemaFixtureTest extends TestCase
{
    public function testMigrateIsIdempotent(): void
    {
        $path = sys_get_temp_dir() . '/p1960_mig_' . bin2hex(random_bytes(4)) . '.db';
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        Schema::migrate($pdo);
        Schema::migrate($pdo);
        $tables = $pdo->query(
            "SELECT name FROM sqlite_master WHERE type='table' ORDER BY name"
        )->fetchAll(PDO::FETCH_COLUMN);
        self::assertContains('cases', $tables);
        self::assertContains('case_metadata', $tables);
        self::assertContains('participants', $tables);
        self::assertContains('scraper_state', $tables);
        self::assertContains('courtlistener_dockets', $tables);
        self::assertContains('case_courtlistener_links', $tables);
        self::assertContains('courtlistener_documents', $tables);
        self::assertContains('courtlistener_document_pages', $tables);
        self::assertContains('courtlistener_document_text', $tables);
        self::assertContains('cl_persons', $tables);
        self::assertContains('cl_person_aliases', $tables);
        self::assertContains('cl_person_case_edges', $tables);
        unlink($path);
    }

    public function testFixtureSeedsExplorerRowsAndDestroys(): void
    {
        $fixture = new FixtureDatabase();
        $path = $fixture->path();
        self::assertFileExists($path);

        $pdo = $fixture->pdo();
        $count = (int) $pdo->query('SELECT COUNT(*) FROM cases')->fetchColumn();
        self::assertSame(2, $count);

        $verified = (int) $pdo->query(
            'SELECT COUNT(*) FROM cases WHERE verified_1960 = 1'
        )->fetchColumn();
        self::assertSame(1, $verified);

        $meta = $pdo->query(
            "SELECT district_office FROM case_metadata WHERE case_id = 'fixture-case-1'"
        )->fetchColumn();
        self::assertSame('Southern District of New York', $meta);

        $people = (int) $pdo->query('SELECT COUNT(*) FROM participants')->fetchColumn();
        self::assertSame(1, $people);

        $fixture->destroy();
        self::assertFileDoesNotExist($path);
    }

    public function testFixtureAcceptsCustomPath(): void
    {
        $path = sys_get_temp_dir() . '/p1960_custom_' . bin2hex(random_bytes(4)) . '.db';
        $fixture = new FixtureDatabase($path);
        self::assertSame($path, $fixture->path());
        self::assertFileExists($path);
        $fixture->destroy();
    }
}
