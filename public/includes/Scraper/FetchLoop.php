<?php
declare(strict_types=1);

namespace Project1960\Scraper;

use RuntimeException;

/**
 * Page loop: resume from ScraperState, polite sleep, stop on empty results.
 * SC1 focuses on fetch/retry; storage hooks arrive in later SC slices.
 */
final class FetchLoop
{
    /** @var list<string> */
    private array $log = [];

    /** @var callable(int): void */
    private $sleeper;

    /**
     * @param (callable(int): void)|null $sleeper
     * @param (callable(int, list<array<string, mixed>>): void)|null $onPage
     */
    public function __construct(
        private readonly DojClient $client,
        private readonly ScraperState $state,
        private readonly int $waitSeconds = 2,
        $sleeper = null,
        private $onPage = null,
    ) {
        $this->sleeper = $sleeper ?? static function (int $seconds): void {
            // No-op default keeps unit tests fast; CLI injects sleep().
            unset($seconds);
        };
    }

    /**
     * @return array{pages: int, fetched: int, stopped: string, next_page: int, log: list<string>}
     */
    public function run(?int $maxPages = null): array
    {
        $page = $this->state->lastPage() + 1;
        $pagesDone = 0;
        $fetched = 0;
        $stopped = 'unknown';

        while (true) {
            if ($maxPages !== null && $pagesDone >= $maxPages) {
                $stopped = 'max_pages';
                break;
            }

            try {
                $results = $this->client->fetchPage($page);
            } catch (RuntimeException $e) {
                $this->log[] = $e->getMessage();
                $stopped = 'error';
                break;
            }

            if ($results === []) {
                $this->log[] = "No more results at page {$page}.";
                $stopped = 'empty';
                break;
            }

            $fetched += count($results);
            $this->log[] = 'Fetched ' . count($results) . " results on page {$page}";
            if ($this->onPage !== null) {
                ($this->onPage)($page, $results);
            }
            $this->state->saveLastPage($page);
            $pagesDone++;
            $page++;
            ($this->sleeper)($this->waitSeconds);
        }

        return [
            'pages' => $pagesDone,
            'fetched' => $fetched,
            'stopped' => $stopped,
            'next_page' => $page,
            'log' => $this->log,
        ];
    }
}
