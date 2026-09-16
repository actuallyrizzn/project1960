<?php
declare(strict_types=1);

namespace Project1960\Tests\Unit\Scraper;

use PDO;
use PHPUnit\Framework\TestCase;
use Project1960\Schema;
use Project1960\Scraper\CaseStore;
use Project1960\Scraper\DateWatermarkPoller;
use Project1960\Scraper\DojClient;
use Project1960\Scraper\HttpTransport;

final class DateWatermarkPollerTest extends TestCase
{
    private string $path;
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/p1960_wm_' . bin2hex(random_bytes(4)) . '.db';
        $this->pdo = new PDO('sqlite:' . $this->path);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        Schema::migrate($this->pdo);
        $this->pdo->exec(
            "INSERT INTO cases (id, title, date, body, url, mentions_1960, mentions_crypto)
             VALUES ('old', 'Old crypto case', '1700000000', 'crypto bitcoin', 'https://ex/old', 0, 1)"
        );
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testWatermarkFromCases(): void
    {
        self::assertSame(1700000000, DateWatermarkPoller::watermarkFromCases($this->pdo));
    }

    public function testStopsWhenDatesFallBelowWatermark(): void
    {
        $pages = [
            0 => [
                [
                    'uuid' => 'new-1',
                    'date' => 1700001000,
                    'title' => 'New crypto indictment',
                    'body' => 'Defendant used bitcoin to launder funds.',
                    'url' => 'https://ex/new-1',
                ],
                [
                    'uuid' => 'new-2',
                    'date' => 1700000500,
                    'title' => 'Unrelated press',
                    'body' => 'No keywords here.',
                    'url' => 'https://ex/new-2',
                ],
            ],
            1 => [
                [
                    'uuid' => 'oldish',
                    'date' => 1699999999,
                    'title' => 'Older crypto',
                    'body' => 'bitcoin',
                    'url' => 'https://ex/oldish',
                ],
            ],
        ];
        $lastQuery = [];
        $transport = new class ($pages, $lastQuery) implements HttpTransport {
            /** @param array<int, list<array<string, mixed>>> $pages */
            public function __construct(private array $pages, private array &$lastQuery)
            {
            }

            public function get(string $url, array $query, int $timeoutSeconds): array
            {
                $this->lastQuery = $query;
                $page = (int) ($query['page'] ?? 0);
                $results = $this->pages[$page] ?? [];

                return [
                    'status' => 200,
                    'body' => json_encode(['results' => $results], JSON_THROW_ON_ERROR),
                ];
            }
        };

        $poller = new DateWatermarkPoller(
            new DojClient($transport, retrySleepSeconds: 0),
            new CaseStore($this->pdo),
            waitSeconds: 0,
        );
        $result = $poller->run(1700000000, maxPages: 5, dryRun: false);

        self::assertSame('date', $lastQuery['sort_by'] ?? null);
        self::assertSame('DESC', $lastQuery['sort_order'] ?? null);
        self::assertSame('watermark', $result['stopped']);
        self::assertSame(1, $result['stored']);
        self::assertSame(1, $result['no_match']);
        self::assertGreaterThanOrEqual(2, $result['pages']);
        self::assertNotNull($this->pdo->query("SELECT 1 FROM cases WHERE id='new-1'")->fetchColumn());
        self::assertFalse((bool) $this->pdo->query("SELECT 1 FROM cases WHERE id='oldish'")->fetchColumn());
    }
}
