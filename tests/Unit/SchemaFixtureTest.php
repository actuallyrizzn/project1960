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
        self::assertContains('cl_match_reviews', $tables);
        self::assertContains('admin_users', $tables);
        self::assertContains('api_keys', $tables);
        self::assertContains('site_settings', $tables);
        unlink($path);
    }

    public function testAdminControlTablesAcceptRows(): void
    {
        $path = sys_get_temp_dir() . '/p1960_admin_' . bin2hex(random_bytes(4)) . '.db';
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        Schema::migrate($pdo);

        $pdo->exec(
            "INSERT INTO admin_users (username, email, password_hash, role)
             VALUES ('ops', 'ops@example.com', 'hash', 'operator')"
        );
        $uid = (int) $pdo->query('SELECT id FROM admin_users')->fetchColumn();
        $pdo->prepare(
            'INSERT INTO api_keys (user_id, key_name, api_key_hash, key_preview, scopes_json)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$uid, 'test', 'hashhex', 'p1960_xxxx', '["stats:read"]']);
        $pdo->exec(
            "INSERT INTO site_settings (key, value) VALUES ('appearance.skin', 'default')"
        );

        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn());
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM api_keys')->fetchColumn());
        self::assertSame(
            'default',
            $pdo->query("SELECT value FROM site_settings WHERE key = 'appearance.skin'")->fetchColumn()
        );

        Schema::migrate($pdo); // idempotent
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
