<?php
declare(strict_types=1);

namespace Project1960\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Project1960\App;
use Project1960\CaseListFilters;
use Project1960\CaseRepository;
use Project1960\FixtureDatabase;
use Project1960\Request;

final class CaseListTest extends TestCase
{
    public function testFiltersFromRequestClampPage(): void
    {
        $req = new Request('GET', '/cases', ['page' => '0', 'search' => '  foo  ']);
        $filters = CaseListFilters::fromRequest($req);
        self::assertSame(1, $filters->page);
        self::assertSame('foo', $filters->search);
        self::assertSame(0, $filters->offset());
    }

    public function testListReturnsAllFixtureCases(): void
    {
        $fixture = new FixtureDatabase();
        $repo = new CaseRepository($fixture->pdo());
        $result = $repo->list(new CaseListFilters());

        self::assertSame(2, $result['total']);
        self::assertCount(2, $result['cases']);
        self::assertSame(1, $result['total_pages']);
        $fixture->destroy();
    }

    public function testFilterMentions1960(): void
    {
        $fixture = new FixtureDatabase();
        $repo = new CaseRepository($fixture->pdo());
        $result = $repo->list(new CaseListFilters(mentions1960: '1'));

        self::assertSame(1, $result['total']);
        self::assertSame('fixture-case-1', $result['cases'][0]['id']);
        $fixture->destroy();
    }

    public function testFilterVerifiedAndSearch(): void
    {
        $fixture = new FixtureDatabase();
        $repo = new CaseRepository($fixture->pdo());

        $verified = $repo->list(new CaseListFilters(verified1960: '1'));
        self::assertSame(1, $verified['total']);

        $search = $repo->list(new CaseListFilters(search: 'Unverified'));
        self::assertSame(1, $search['total']);
        self::assertSame('fixture-case-2', $search['cases'][0]['id']);

        $classification = $repo->list(new CaseListFilters(classification: 'verified'));
        self::assertSame(1, $classification['total']);

        $none = $repo->list(new CaseListFilters(search: 'zzzz-no-match'));
        self::assertSame(0, $none['total']);
        self::assertSame(1, $none['total_pages']);

        $fixture->destroy();
    }

    public function testPaginationEdge(): void
    {
        $fixture = new FixtureDatabase();
        $repo = new CaseRepository($fixture->pdo());
        $page1 = $repo->list(new CaseListFilters(page: 1, perPage: 1));
        $page2 = $repo->list(new CaseListFilters(page: 2, perPage: 1));

        self::assertSame(2, $page1['total']);
        self::assertSame(2, $page1['total_pages']);
        self::assertCount(1, $page1['cases']);
        self::assertCount(1, $page2['cases']);
        self::assertNotSame($page1['cases'][0]['id'], $page2['cases'][0]['id']);
        $fixture->destroy();
    }

    public function testAppCasesPageRendersTable(): void
    {
        $fixture = new FixtureDatabase();
        $app = new App(null, null, $fixture->pdo());
        $response = $app->handle(new Request('GET', '/cases', ['mentions_1960' => '1']));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Cases Database', $response->body);
        self::assertStringContainsString('United States v. Fixture', $response->body);
        self::assertStringContainsString('name="verified_1960"', $response->body);
        $fixture->destroy();
    }
}
