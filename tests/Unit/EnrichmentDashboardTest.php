<?php
declare(strict_types=1);

namespace Project1960\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Project1960\App;
use Project1960\EnrichmentDashboard;
use Project1960\FixtureDatabase;
use Project1960\Request;

final class EnrichmentDashboardTest extends TestCase
{
    public function testPercentCompleteZeroVerified(): void
    {
        self::assertSame(0.0, EnrichmentDashboard::percentComplete([
            'verified_yes' => 0,
            'enrichment' => ['charges' => 5],
        ], 'charges'));
    }

    public function testPercentCompleteRounds(): void
    {
        self::assertSame(100.0, EnrichmentDashboard::percentComplete([
            'verified_yes' => 1,
            'enrichment' => ['case_metadata' => 1],
        ], 'case_metadata'));
        self::assertSame(50.0, EnrichmentDashboard::percentComplete([
            'verified_yes' => 2,
            'enrichment' => ['participants' => 1],
        ], 'participants'));
    }

    public function testEmptyActivityLogOnFixture(): void
    {
        $fixture = new FixtureDatabase();
        $dash = new EnrichmentDashboard($fixture->pdo());
        $data = $dash->collect();

        self::assertSame([], $data['activity_log']);
        self::assertCount(8, $data['cards']);
        self::assertSame(100.0, $data['cards'][0]['percent']); // case_metadata / verified_yes
        self::assertStringContainsString('Case Metadata', $data['cards'][0]['label']);

        $fixture->destroy();
    }

    public function testPopulatedActivityLogAndAppRoute(): void
    {
        $fixture = new FixtureDatabase();
        $pdo = $fixture->pdo();
        $dash = new EnrichmentDashboard($pdo);
        $dash->ensureActivityLogTable();
        $pdo->exec(
            "INSERT INTO enrichment_activity_log (timestamp, case_id, table_name, status, notes)
             VALUES
               ('2024-05-01T12:00:00Z', 'fixture-case-1', 'charges', 'success', 'Extracted 1 charge'),
               ('2024-05-01T11:00:00Z', 'fixture-case-1', 'quotes', 'skipped', 'No quotes'),
               ('2024-05-01T10:00:00Z', 'fixture-case-2', 'themes', 'error', 'Model failed')"
        );

        $rows = $dash->recentActivity(10);
        self::assertCount(3, $rows);
        self::assertSame('fixture-case-1', $rows[0]['case_id']);
        self::assertSame('success', $rows[0]['status']);

        $app = new App(null, null, $pdo);
        $response = $app->handle(new Request('GET', '/enrichment'));
        self::assertSame(200, $response->status);
        self::assertStringContainsString('Data Enrichment Progress', $response->body);
        self::assertStringContainsString('fixture-case-1', $response->body);
        self::assertStringContainsString('Extracted 1 charge', $response->body);
        self::assertStringContainsString('href="/case.php?id=fixture-case-1"', $response->body);
        self::assertStringContainsString('Success', $response->body);
        self::assertStringContainsString('Skipped', $response->body);
        self::assertStringContainsString('Error', $response->body);

        $fixture->destroy();
    }

    public function testAppEnrichmentWithoutDatabaseRendersEmptyDashboard(): void
    {
        $app = new App();
        $response = $app->handle(new Request('GET', '/enrichment'));
        self::assertSame(200, $response->status);
        self::assertStringContainsString('No recent activity.', $response->body);
        self::assertStringContainsString('Data Enrichment Progress', $response->body);
    }

    public function testEmptyLogRendersNoRecentActivity(): void
    {
        $fixture = new FixtureDatabase();
        $app = new App(null, null, $fixture->pdo());
        $response = $app->handle(new Request('GET', '/enrichment'));
        self::assertSame(200, $response->status);
        self::assertStringContainsString('No recent activity.', $response->body);
        $fixture->destroy();
    }
}
