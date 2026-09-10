<?php
declare(strict_types=1);

namespace Project1960\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Project1960\FixtureDatabase;
use Project1960\Scraper\CaseStore;
use Project1960\Scraper\ScraperState;

final class CaseStoreTest extends TestCase
{
    public function testStoresMatchingCase(): void
    {
        $fixture = new FixtureDatabase();
        $pdo = $fixture->pdo();
        $store = new CaseStore($pdo);

        $result = $store->store([
            'uuid' => 'new-1960-case',
            'title' => 'Money transmitter charged',
            'date' => '2024-02-01',
            'body' => 'Defendant charged under 18 U.S.C. 1960 for unlicensed activity.',
            'url' => 'https://www.justice.gov/example/new',
            'teaser' => 'Teaser',
            'number' => '1:24-cr-9',
            'component' => [
                ['name' => 'Criminal Division'],
                ['name' => 'USAO'],
            ],
            'topic' => ['Financial Fraud', ['ignored' => true]],
            'changed' => '2024-02-01',
            'created' => '2024-02-01',
        ]);

        self::assertSame(CaseStore::RESULT_STORED, $result);
        $row = $pdo->query("SELECT * FROM cases WHERE id = 'new-1960-case'")->fetch();
        self::assertNotFalse($row);
        self::assertSame('Criminal Division, USAO', $row['component']);
        self::assertSame('Financial Fraud', $row['topic']);
        self::assertSame(1, (int) $row['mentions_1960']);

        $fixture->destroy();
    }

    public function testSkipsNonMatch(): void
    {
        $fixture = new FixtureDatabase();
        $store = new CaseStore($fixture->pdo());
        $result = $store->store([
            'uuid' => 'boring-case',
            'title' => 'Routine sentencing',
            'body' => 'Wire fraud only, no relevant keywords.',
        ]);
        self::assertSame(CaseStore::RESULT_NO_MATCH, $result);
        $count = (int) $fixture->pdo()->query(
            "SELECT COUNT(*) FROM cases WHERE id = 'boring-case'"
        )->fetchColumn();
        self::assertSame(0, $count);
        $fixture->destroy();
    }

    public function testDuplicateIdIgnored(): void
    {
        $fixture = new FixtureDatabase();
        $store = new CaseStore($fixture->pdo());
        $item = [
            'uuid' => 'dup-crypto',
            'title' => 'Crypto case',
            'body' => 'Seized Bitcoin wallets.',
        ];
        self::assertSame(CaseStore::RESULT_STORED, $store->store($item));
        self::assertSame(CaseStore::RESULT_DUPLICATE, $store->store($item));
        $count = (int) $fixture->pdo()->query(
            "SELECT COUNT(*) FROM cases WHERE id = 'dup-crypto'"
        )->fetchColumn();
        self::assertSame(1, $count);
        $fixture->destroy();
    }

    public function testMissingId(): void
    {
        $fixture = new FixtureDatabase();
        $store = new CaseStore($fixture->pdo());
        self::assertSame(CaseStore::RESULT_MISSING_ID, $store->store(['title' => 'No uuid', 'body' => 'Bitcoin']));
        $fixture->destroy();
    }

    public function testStoreAllCounts(): void
    {
        $fixture = new FixtureDatabase();
        $store = new CaseStore($fixture->pdo());
        $counts = $store->storeAll([
            ['uuid' => 'a1', 'body' => '18 USC 1960 charge'],
            ['uuid' => 'a1', 'body' => '18 USC 1960 charge'],
            ['uuid' => 'a2', 'body' => 'nothing interesting'],
            ['title' => 'no id', 'body' => 'Bitcoin'],
            'not-an-array',
        ]);
        self::assertSame(1, $counts['stored']);
        self::assertSame(1, $counts['duplicate']);
        self::assertSame(1, $counts['no_match']);
        self::assertSame(2, $counts['missing_id']);
        $fixture->destroy();
    }

    public function testJoinHelpers(): void
    {
        $fixture = new FixtureDatabase();
        $store = new CaseStore($fixture->pdo());
        self::assertSame('', $store->joinListField(null, 'name'));
        self::assertSame('Solo', $store->joinListField('Solo', 'name'));
        self::assertSame('A, B', $store->joinListField([['name' => 'A'], ['name' => 'B']], 'name'));
        self::assertSame('', $store->joinTopicField(null));
        self::assertSame('plain', $store->joinTopicField('plain'));
        self::assertSame('x, y', $store->joinTopicField(['x', 'y', ['dict' => 1]]));
        $fixture->destroy();
    }

    public function testScraperStatePersistsAcrossInstances(): void
    {
        $fixture = new FixtureDatabase();
        $pdo = $fixture->pdo();
        $state = new ScraperState($pdo);
        self::assertSame(0, $state->lastPage());
        $state->saveLastPage(12);
        $again = new ScraperState($pdo);
        self::assertSame(12, $again->lastPage());
        $again->saveLastPage(13);
        self::assertSame(13, $again->lastPage());
        $fixture->destroy();
    }

    public function testCryptoOnlyStored(): void
    {
        $fixture = new FixtureDatabase();
        $store = new CaseStore($fixture->pdo());
        self::assertSame(
            CaseStore::RESULT_STORED,
            $store->store(['uuid' => 'crypto-only', 'title' => 'Exchange hack', 'body' => 'Ethereum theft ring.'])
        );
        $row = $fixture->pdo()->query("SELECT mentions_1960, mentions_crypto FROM cases WHERE id='crypto-only'")->fetch();
        self::assertSame(0, (int) $row['mentions_1960']);
        self::assertSame(1, (int) $row['mentions_crypto']);
        $fixture->destroy();
    }
}
