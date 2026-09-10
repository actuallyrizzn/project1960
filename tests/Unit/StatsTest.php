<?php
declare(strict_types=1);

namespace Project1960\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Project1960\App;
use Project1960\EnrichmentDashboard;
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

    public function testEnrichmentCountsOnlyVerifiedCohort(): void
    {
        $fixture = new FixtureDatabase();
        $pdo = $fixture->pdo();
        // Unverified case with enrichment must NOT inflate public progress numerators
        $pdo->exec(
            "INSERT INTO cases (id, title, date, body, url, mentions_1960, mentions_crypto, verified_1960)
             VALUES ('unverified-enriched', 'Other', '2024-02-01', 'body', 'http://x', 1, 0, 0)"
        );
        $pdo->exec(
            "INSERT INTO case_metadata (case_id, district_office, event_type)
             VALUES ('unverified-enriched', 'Somewhere', 'Plea')"
        );
        $pdo->exec(
            "INSERT INTO participants (case_id, name, role)
             VALUES ('unverified-enriched', 'Someone', 'defendant')"
        );

        $stats = Stats::collect($pdo);
        self::assertSame(1, $stats['verified_yes']);
        self::assertSame(1, $stats['verified_no']);
        // Still only the verified fixture case — not 2
        self::assertSame(1, $stats['enrichment']['case_metadata']);
        self::assertSame(1, $stats['enrichment']['participants']);
        self::assertSame(100.0, EnrichmentDashboard::percentComplete($stats, 'case_metadata'));

        $fixture->destroy();
    }

    public function testPercentCompleteCapsAt100(): void
    {
        self::assertSame(100.0, EnrichmentDashboard::percentComplete([
            'verified_yes' => 10,
            'enrichment' => ['charges' => 50],
        ], 'charges'));
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
