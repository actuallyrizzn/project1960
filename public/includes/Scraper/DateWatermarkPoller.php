<?php
declare(strict_types=1);

namespace Project1960\Scraper;

use PDO;
use RuntimeException;

/**
 * Newest-first DOJ poll that stops at a date watermark (no full-corpus crawl).
 *
 * Uses sort_by=date&sort_order=DESC. Stops when an item date is strictly older
 * than the watermark (typically MAX(cases.date)).
 */
final class DateWatermarkPoller
{
    /** @var list<string> */
    private array $log = [];

    /** @var callable(int): void */
    private $sleeper;

    /**
     * @param (callable(int): void)|null $sleeper
     * @param (callable(int, list<array<string, mixed>>, array{newer: int, stored: int, duplicate: int, no_match: int}): void)|null $onPage
     */
    public function __construct(
        private readonly DojClient $client,
        private readonly CaseStore $store,
        private readonly int $waitSeconds = 2,
        $sleeper = null,
        private $onPage = null,
    ) {
        $this->sleeper = $sleeper ?? static function (int $seconds): void {
            unset($seconds);
        };
    }

    /**
     * Watermark from SQLite tip (0 if empty).
     */
    public static function watermarkFromCases(PDO $pdo): int
    {
        $max = $pdo->query('SELECT MAX(CAST(date AS INTEGER)) FROM cases')->fetchColumn();
        if ($max === false || $max === null || $max === '') {
            return 0;
        }

        return (int) $max;
    }

    /**
     * @return array{
     *   pages: int,
     *   fetched: int,
     *   stored: int,
     *   duplicate: int,
     *   no_match: int,
     *   stopped: string,
     *   watermark: int,
     *   tip_date: int|null,
     *   log: list<string>
     * }
     */
    public function run(int $watermark, ?int $maxPages = null, bool $dryRun = false, int $pagesize = 50): array
    {
        $pages = 0;
        $fetched = 0;
        $stored = 0;
        $duplicate = 0;
        $noMatch = 0;
        $stopped = 'unknown';
        $tipDate = null;
        $seen = [];
        $page = 0;
        $limit = $maxPages ?? 50;

        while ($page < $limit) {
            try {
                $results = $this->client->fetchPage($page, $pagesize, [
                    'sort_by' => 'date',
                    'sort_order' => 'DESC',
                ]);
            } catch (RuntimeException $e) {
                $this->log[] = $e->getMessage();
                $stopped = 'error';
                break;
            }

            if ($results === []) {
                $stopped = 'empty';
                $this->log[] = "No more results at page {$page}.";
                break;
            }

            $pages++;
            $pageNewer = 0;
            $pageStored = 0;
            $pageDup = 0;
            $pageNoMatch = 0;
            $hitOlder = false;

            foreach ($results as $row) {
                $fetched++;
                $date = isset($row['date']) && is_numeric($row['date']) ? (int) $row['date'] : 0;
                if ($tipDate === null && $date > 0) {
                    $tipDate = $date;
                }

                if ($watermark > 0 && $date > 0 && $date < $watermark) {
                    $hitOlder = true;
                    $this->log[] = sprintf(
                        'Watermark reached on page %d (item date %d < watermark %d).',
                        $page,
                        $date,
                        $watermark
                    );
                    break;
                }

                $uuid = isset($row['uuid']) ? (string) $row['uuid'] : '';
                if ($uuid !== '' && isset($seen[$uuid])) {
                    continue;
                }
                if ($uuid !== '') {
                    $seen[$uuid] = true;
                }

                $pageNewer++;
                if ($dryRun) {
                    $title = (string) ($row['title'] ?? '');
                    $body = (string) ($row['body'] ?? '');
                    $match = KeywordFilters::mentions1960($body) || KeywordFilters::mentions1960($title)
                        || KeywordFilters::mentionsCrypto($body) || KeywordFilters::mentionsCrypto($title);
                    if (!$match) {
                        $pageNoMatch++;
                        $noMatch++;
                    } else {
                        $pageStored++;
                        $stored++;
                    }
                    continue;
                }

                $result = $this->store->store($row);
                if ($result === CaseStore::RESULT_STORED) {
                    $pageStored++;
                    $stored++;
                } elseif ($result === CaseStore::RESULT_DUPLICATE) {
                    $pageDup++;
                    $duplicate++;
                } else {
                    $pageNoMatch++;
                    $noMatch++;
                }
            }

            if ($this->onPage !== null) {
                ($this->onPage)($page, $results, [
                    'newer' => $pageNewer,
                    'stored' => $pageStored,
                    'duplicate' => $pageDup,
                    'no_match' => $pageNoMatch,
                ]);
            }

            if ($hitOlder) {
                $stopped = 'watermark';
                break;
            }

            $page++;
            ($this->sleeper)($this->waitSeconds);
        }

        if ($stopped === 'unknown') {
            $stopped = $pages >= $limit ? 'max_pages' : 'unknown';
        }

        return [
            'pages' => $pages,
            'fetched' => $fetched,
            'stored' => $stored,
            'duplicate' => $duplicate,
            'no_match' => $noMatch,
            'stopped' => $stopped,
            'watermark' => $watermark,
            'tip_date' => $tipDate,
            'log' => $this->log,
        ];
    }
}
