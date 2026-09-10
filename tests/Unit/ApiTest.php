<?php
declare(strict_types=1);

namespace Project1960\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Project1960\Api;
use Project1960\App;
use Project1960\FixtureDatabase;
use Project1960\Request;

final class ApiTest extends TestCase
{
    public function testStatsShapeMatchesDashboard(): void
    {
        $fixture = new FixtureDatabase();
        $response = (new Api($fixture->pdo()))->stats();
        self::assertSame(200, $response->status);
        /** @var array<string, mixed> $data */
        $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('total_cases', $data);
        self::assertArrayHasKey('mentions_1960', $data);
        self::assertArrayHasKey('enrichment', $data);
        self::assertSame(2, $data['total_cases']);
        self::assertIsArray($data['enrichment']);
        $fixture->destroy();
    }

    public function testCasesReturnsListOfSummaries(): void
    {
        $fixture = new FixtureDatabase();
        $response = (new Api($fixture->pdo()))->cases();
        self::assertSame(200, $response->status);
        /** @var list<array<string, mixed>> $rows */
        $rows = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(2, $rows);
        self::assertArrayHasKey('id', $rows[0]);
        self::assertArrayHasKey('title', $rows[0]);
        self::assertArrayHasKey('verified_1960', $rows[0]);
        self::assertArrayNotHasKey('body', $rows[0]);
        $fixture->destroy();
    }

    public function testEnrichmentForKnownAndUnknownCase(): void
    {
        $fixture = new FixtureDatabase();
        $api = new Api($fixture->pdo());

        $ok = $api->enrichment('fixture-case-1');
        self::assertSame(200, $ok->status);
        /** @var array<string, mixed> $data */
        $data = json_decode($ok->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('metadata', $data);
        self::assertArrayHasKey('participants', $data);
        self::assertArrayHasKey('charges', $data);
        self::assertNotNull($data['metadata']);
        self::assertCount(1, $data['participants']);

        $missing = $api->enrichment('no-such');
        self::assertSame(200, $missing->status);
        /** @var array<string, mixed> $empty */
        $empty = json_decode($missing->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertNull($empty['metadata']);
        self::assertSame([], $empty['participants']);

        $fixture->destroy();
    }

    public function testApiWithoutDatabaseReturns503(): void
    {
        $api = new Api(null);
        self::assertSame(503, $api->stats()->status);
        self::assertSame(503, $api->cases()->status);
        self::assertSame(503, $api->enrichment('x')->status);
    }

    public function testAppRoutesWireApiAndAbout(): void
    {
        $fixture = new FixtureDatabase();
        $app = new App(null, null, $fixture->pdo());

        $stats = $app->handle(new Request('GET', '/api/stats'));
        self::assertSame(200, $stats->status);
        self::assertStringContainsString('application/json', $stats->headers['Content-Type']);

        $cases = $app->handle(new Request('GET', '/api/cases'));
        self::assertSame(200, $cases->status);
        self::assertStringContainsString('fixture-case-1', $cases->body);

        $enrich = $app->handle(new Request('GET', '/api/enrichment/fixture-case-1'));
        self::assertSame(200, $enrich->status);
        self::assertStringContainsString('Jane Fixture', $enrich->body);

        $about = $app->handle(new Request('GET', '/about'));
        self::assertSame(200, $about->status);
        self::assertStringContainsString('Independent research disclaimer', $about->body);
        self::assertStringContainsString('Methodology', $about->body);
        self::assertStringContainsString('>2</h3>', $about->body);

        $fixture->destroy();
    }
}
