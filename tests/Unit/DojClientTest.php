<?php
declare(strict_types=1);

namespace Project1960\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Project1960\FixtureDatabase;
use Project1960\Scraper\DojClient;
use Project1960\Scraper\FetchLoop;
use Project1960\Scraper\HttpTransport;
use Project1960\Scraper\ScraperState;
use RuntimeException;

final class MockTransport implements HttpTransport
{
    /** @var list<callable(string, array<string, scalar>, int): array{status: int, body: string}> */
    public array $queue = [];
    public int $calls = 0;
    /** @var list<int> */
    public array $sleeps = [];

    public function get(string $url, array $query, int $timeoutSeconds): array
    {
        $this->calls++;
        if ($this->queue === []) {
            throw new RuntimeException('MockTransport queue empty');
        }
        $fn = array_shift($this->queue);

        return $fn($url, $query, $timeoutSeconds);
    }
}

final class DojClientTest extends TestCase
{
    public function testFetchPageSuccess(): void
    {
        $transport = new MockTransport();
        $transport->queue[] = static function (string $url, array $query, int $timeout): array {
            self::assertSame(50, $query['pagesize']);
            self::assertSame(3, $query['page']);
            self::assertSame(30, $timeout);

            return [
                'status' => 200,
                'body' => json_encode([
                    'results' => [
                        ['uuid' => 'a', 'title' => 'One'],
                        ['uuid' => 'b', 'title' => 'Two'],
                    ],
                ], JSON_THROW_ON_ERROR),
            ];
        };

        $client = new DojClient($transport, retrySleepSeconds: 0);
        $rows = $client->fetchPage(3);
        self::assertCount(2, $rows);
        self::assertSame('a', $rows[0]['uuid']);
        self::assertSame(1, $transport->calls);
    }

    public function testFetchPageEmptyResults(): void
    {
        $transport = new MockTransport();
        $transport->queue[] = static fn (): array => [
            'status' => 200,
            'body' => '{"results":[]}',
        ];
        $client = new DojClient($transport, retrySleepSeconds: 0);
        self::assertSame([], $client->fetchPage(1));
    }

    public function testTimeoutRetriesThenSucceeds(): void
    {
        $transport = new MockTransport();
        $sleeps = [];
        $transport->queue[] = static function (): array {
            throw new RuntimeException('timeout');
        };
        $transport->queue[] = static function (): array {
            throw new RuntimeException('timeout');
        };
        $transport->queue[] = static fn (): array => [
            'status' => 200,
            'body' => '{"results":[{"uuid":"ok"}]}',
        ];

        $client = new DojClient(
            $transport,
            maxRetries: 3,
            retrySleepSeconds: 1,
            sleeper: static function (int $s) use (&$sleeps): void {
                $sleeps[] = $s;
            }
        );
        $rows = $client->fetchPage(0);
        self::assertCount(1, $rows);
        self::assertSame(3, $transport->calls);
        self::assertSame([1, 1], $sleeps);
    }

    public function testTimeoutRetryExhaustThrows(): void
    {
        $transport = new MockTransport();
        $transport->queue[] = static function (): array {
            throw new RuntimeException('timeout');
        };
        $transport->queue[] = static function (): array {
            throw new RuntimeException('timeout');
        };
        $transport->queue[] = static function (): array {
            throw new RuntimeException('timeout');
        };

        $client = new DojClient($transport, maxRetries: 3, retrySleepSeconds: 0);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('after 3 attempts');
        $client->fetchPage(9);
    }

    public function testNon200ExhaustsRetries(): void
    {
        $transport = new MockTransport();
        for ($i = 0; $i < 3; $i++) {
            $transport->queue[] = static fn (): array => ['status' => 503, 'body' => 'nope'];
        }
        $client = new DojClient($transport, maxRetries: 3, retrySleepSeconds: 0);
        $this->expectException(RuntimeException::class);
        $client->fetchPage(1);
    }

    public function testFetchLoopEmptyStopsAndPersistsState(): void
    {
        $fixture = new FixtureDatabase();
        $pdo = $fixture->pdo();
        $state = new ScraperState($pdo);
        self::assertSame(0, $state->lastPage());

        $transport = new MockTransport();
        $transport->queue[] = static fn (): array => [
            'status' => 200,
            'body' => json_encode([
                'results' => [['uuid' => 'p1']],
            ], JSON_THROW_ON_ERROR),
        ];
        $transport->queue[] = static fn (): array => [
            'status' => 200,
            'body' => '{"results":[]}',
        ];

        $client = new DojClient($transport, retrySleepSeconds: 0);
        $loop = new FetchLoop($client, $state, waitSeconds: 0);
        $result = $loop->run();

        self::assertSame(1, $result['pages']);
        self::assertSame(1, $result['fetched']);
        self::assertSame('empty', $result['stopped']);
        self::assertSame(1, $state->lastPage());

        $fixture->destroy();
    }

    public function testFetchLoopResumeFromState(): void
    {
        $fixture = new FixtureDatabase();
        $pdo = $fixture->pdo();
        $state = new ScraperState($pdo);
        $state->saveLastPage(4);

        $seenPage = null;
        $transport = new MockTransport();
        $transport->queue[] = static function (string $url, array $query) use (&$seenPage): array {
            $seenPage = (int) $query['page'];

            return ['status' => 200, 'body' => '{"results":[]}'];
        };

        $loop = new FetchLoop(new DojClient($transport, retrySleepSeconds: 0), $state, waitSeconds: 0);
        $loop->run();
        self::assertSame(5, $seenPage);

        $fixture->destroy();
    }

    public function testInvalidJsonThrowsAfterRetries(): void
    {
        $transport = new MockTransport();
        for ($i = 0; $i < 2; $i++) {
            $transport->queue[] = static fn (): array => ['status' => 200, 'body' => 'not-json'];
        }
        $client = new DojClient($transport, maxRetries: 2, retrySleepSeconds: 0);
        $this->expectException(RuntimeException::class);
        $client->fetchPage(1);
    }

    public function testNonArrayResultsReturnsEmpty(): void
    {
        $transport = new MockTransport();
        $transport->queue[] = static fn (): array => [
            'status' => 200,
            'body' => '{"results":"oops"}',
        ];
        $client = new DojClient($transport, retrySleepSeconds: 0);
        self::assertSame([], $client->fetchPage(0));
    }

    public function testFetchLoopMaxPages(): void
    {
        $fixture = new FixtureDatabase();
        $state = new ScraperState($fixture->pdo());
        $transport = new MockTransport();
        $transport->queue[] = static fn (): array => [
            'status' => 200,
            'body' => '{"results":[{"uuid":"1"}]}',
        ];
        $transport->queue[] = static fn (): array => [
            'status' => 200,
            'body' => '{"results":[{"uuid":"2"}]}',
        ];
        $sleeps = [];
        $pages = [];
        $loop = new FetchLoop(
            new DojClient($transport, retrySleepSeconds: 0),
            $state,
            waitSeconds: 2,
            sleeper: static function (int $s) use (&$sleeps): void {
                $sleeps[] = $s;
            },
            onPage: static function (int $page) use (&$pages): void {
                $pages[] = $page;
            }
        );
        $result = $loop->run(1);
        self::assertSame('max_pages', $result['stopped']);
        self::assertSame(1, $result['pages']);
        self::assertSame([1], $pages);
        self::assertSame([2], $sleeps);
        $fixture->destroy();
    }

    public function testFetchLoopStopsOnExhaustedRetries(): void
    {
        $fixture = new FixtureDatabase();
        $state = new ScraperState($fixture->pdo());
        $transport = new MockTransport();
        for ($i = 0; $i < 3; $i++) {
            $transport->queue[] = static function (): array {
                throw new RuntimeException('timeout');
            };
        }
        $loop = new FetchLoop(
            new DojClient($transport, maxRetries: 3, retrySleepSeconds: 0),
            $state,
            waitSeconds: 0,
            sleeper: static function (int $s): void {
            }
        );
        $result = $loop->run();
        self::assertSame('error', $result['stopped']);
        self::assertSame(0, $result['pages']);
        self::assertNotEmpty($result['log']);
        $fixture->destroy();
    }
}