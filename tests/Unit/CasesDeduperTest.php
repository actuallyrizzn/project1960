<?php
declare(strict_types=1);

namespace Project1960\Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use Project1960\CasesDeduper;
use Project1960\Schema;

final class CasesDeduperTest extends TestCase
{
    private string $path;
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/p1960_dedupe_' . bin2hex(random_bytes(4)) . '.db';
        $this->pdo = new PDO('sqlite:' . $this->path);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // Legacy shape: no PRIMARY KEY (like prod before fix)
        $this->pdo->exec(
            'CREATE TABLE cases (
                id TEXT,
                title TEXT,
                date TEXT,
                body TEXT,
                url TEXT,
                teaser TEXT,
                number TEXT,
                component TEXT,
                topic TEXT,
                changed TEXT,
                created TEXT,
                mentions_1960 NUM,
                mentions_crypto NUM,
                verified_1960,
                verified_crypto,
                classification TEXT
            )'
        );
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testAnalyzesAndDedupesPreferringVerified(): void
    {
        $this->pdo->exec(
            "INSERT INTO cases (id, title, body, url, verified_1960, classification) VALUES
             ('c1', 'A', 'short', 'https://example.com/a', 0, ''),
             ('c1', 'A', 'longer body here', 'https://example.com/a', 0, ''),
             ('c1', 'A', 'x', 'https://example.com/a', 1, 'yes'),
             ('c2', 'B', 'only', 'https://example.com/b', 0, '')"
        );

        $deduper = new CasesDeduper($this->pdo);
        $before = $deduper->analyze();
        self::assertSame(4, $before['total_rows']);
        self::assertSame(2, $before['distinct_ids']);
        self::assertSame(1, $before['duplicate_groups']);
        self::assertSame(2, $before['rows_to_delete']);

        $dry = $deduper->dedupeById(dryRun: true);
        self::assertTrue($dry['dry_run']);
        self::assertSame(2, $dry['deleted']);
        self::assertSame(4, (int) $this->pdo->query('SELECT COUNT(*) FROM cases')->fetchColumn());

        $live = $deduper->dedupeById(dryRun: false);
        self::assertFalse($live['dry_run']);
        self::assertSame(2, $live['deleted']);
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM cases')->fetchColumn());

        $kept = $this->pdo->query("SELECT verified_1960, classification FROM cases WHERE id='c1'")->fetch(PDO::FETCH_ASSOC);
        self::assertSame('1', (string) $kept['verified_1960']);
        self::assertSame('yes', $kept['classification']);

        Schema::migrate($this->pdo);
        $pk = false;
        foreach ($this->pdo->query('PRAGMA table_info(cases)') as $col) {
            if ($col['name'] === 'id' && (int) $col['pk'] > 0) {
                $pk = true;
            }
        }
        self::assertTrue($pk);
        $idx = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM sqlite_master WHERE name='idx_cases_url_unique'"
        )->fetchColumn();
        self::assertSame(1, $idx);
    }

    public function testDedupeRollsBackWhenDeleteAborted(): void
    {
        $this->pdo->exec(
            "INSERT INTO cases (id, title, body, url) VALUES
             ('c1', 'A', 'one', 'https://example.com/a'),
             ('c1', 'A', 'two', 'https://example.com/a')"
        );
        $this->pdo->exec(
            'CREATE TRIGGER abort_cases_delete BEFORE DELETE ON cases
             BEGIN SELECT RAISE(ABORT, \'blocked\'); END'
        );
        $deduper = new CasesDeduper($this->pdo);
        try {
            $deduper->dedupeById(dryRun: false);
            self::fail('Expected exception');
        } catch (\Throwable $e) {
            self::assertStringContainsString('blocked', $e->getMessage());
        }
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM cases')->fetchColumn());
    }

    public function testNoopWhenAlreadyUnique(): void
    {
        $this->pdo->exec(
            "INSERT INTO cases (id, title, body, url) VALUES ('c1', 'A', 'b', 'https://example.com/a')"
        );
        $deduper = new CasesDeduper($this->pdo);
        $result = $deduper->dedupeById(dryRun: false);
        self::assertSame(0, $result['deleted']);
        self::assertSame(1, $result['kept']);
    }
}
