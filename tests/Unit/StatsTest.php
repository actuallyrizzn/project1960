<?php
declare(strict_types=1);

namespace Project1960\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Project1960\App;
use Project1960\FixtureDatabase;
use Project1960\Request;
use Project1960\Stats;

final class StatsTest extends TestCase
{
    public function testCollectMatchesFixtureCounts(): void
    {
        $fixture = new FixtureDatabase();
        $stats = Stats::collect($fixture->pdo());

        self::assertSame(2, $stats['total_cases']);
        self::assertSame(1, $stats['mentions_1960']);
        self::assertSame(1, $stats['mentions_crypto']);
        self::assertSame(1, $stats['verified_yes']);
        self::assertSame(0, $stats['verified_no']);
        self::assertSame(0, $stats['unprocessed_1960']);
        self::assertSame(1, $stats['enrichment']['case_metadata']);
        self::assertSame(1, $stats['enrichment']['participants']);
        self::assertSame(1, $stats['enrichment']['charges']);

        $fixture->destroy();
    }

    public function testDashboardRendersFixtureStats(): void
    {
        $fixture = new FixtureDatabase();
        $app = new App(null, null, $fixture->pdo());
        $response = $app->handle(new Request('GET', '/'));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Project 1960 Dashboard', $response->body);
        self::assertStringContainsString('>2</h2>', $response->body); // total cases
        self::assertStringContainsString('Verified 1960', $response->body);
        self::assertStringContainsString('case_metadata', $response->body);

        $fixture->destroy();
    }
}
