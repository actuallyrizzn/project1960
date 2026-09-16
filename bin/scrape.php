#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Project 1960 DOJ scraper CLI.
 *
 * Default: incremental newest-first poll stopping at MAX(cases.date).
 * Legacy:  --legacy-pages / --page-start for oldest-first archive crawl.
 *
 *   php bin/scrape.php [--incremental] [--since=UNIX] [--max-pages=N] [--wait=2] [--dry-run] [--verbose]
 *   php bin/scrape.php --legacy-pages [--page-start=N] …
 *
 * See docs/scraper.md
 */

use Project1960\Config;
use Project1960\Database;
use Project1960\Schema;
use Project1960\Scraper\CaseStore;
use Project1960\Scraper\CurlTransport;
use Project1960\Scraper\DateWatermarkPoller;
use Project1960\Scraper\DojClient;
use Project1960\Scraper\FetchLoop;
use Project1960\Scraper\ScrapeCliOptions;
use Project1960\Scraper\ScraperState;

require dirname(__DIR__) . '/vendor/autoload.php';

$options = ScrapeCliOptions::fromArgv($argv);
if ($options->help) {
    fwrite(STDOUT, ScrapeCliOptions::helpText());
    exit(0);
}

try {
    $dbPath = Config::databasePath();
    if (!is_file($dbPath)) {
        $dir = dirname($dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        touch($dbPath);
    }
    $pdo = Database::connect($dbPath);
    Schema::migrate($pdo);
} catch (Throwable $e) {
    fwrite(STDERR, 'Database error: ' . $e->getMessage() . "\n");
    exit(1);
}

$client = new DojClient(new CurlTransport(), retrySleepSeconds: 5);
$verbose = $options->verbose;
$dryRun = $options->dryRun;
$sleeper = static function (int $seconds): void {
    if ($seconds > 0) {
        sleep($seconds);
    }
};

if ($options->incremental && !$options->legacyPages) {
    $store = new CaseStore($pdo);
    $watermark = $options->since ?? DateWatermarkPoller::watermarkFromCases($pdo);
    fwrite(STDOUT, sprintf(
        "Incremental scrape watermark=%d (%s) max_pages=%s%s…\n",
        $watermark,
        $watermark > 0 ? gmdate('Y-m-d', $watermark) : 'empty-db',
        $options->maxPages !== null ? (string) $options->maxPages : '50',
        $dryRun ? ' [dry-run]' : ''
    ));
    $poller = new DateWatermarkPoller(
        $client,
        $store,
        waitSeconds: max(0, $options->waitSeconds),
        sleeper: $sleeper,
        onPage: static function (int $page, array $results, array $counts) use ($dryRun, $verbose): void {
            $prefix = $dryRun ? '[dry-run] ' : '';
            fwrite(STDOUT, sprintf(
                "%spage %d: fetched=%d stored=%d duplicate=%d no_match=%d\n",
                $prefix,
                $page,
                count($results),
                $counts['stored'],
                $counts['duplicate'],
                $counts['no_match']
            ));
            if ($verbose && isset($results[0]['title'])) {
                fwrite(STDOUT, $prefix . 'sample: ' . substr(strip_tags((string) $results[0]['title']), 0, 80) . "\n");
            }
        }
    );
    $result = $poller->run(
        $watermark,
        maxPages: $options->maxPages ?? 50,
        dryRun: $dryRun,
    );
    if ($verbose) {
        foreach ($result['log'] as $line) {
            fwrite(STDOUT, $line . "\n");
        }
    }
    fwrite(STDOUT, sprintf(
        "Done: pages=%d fetched=%d stored=%d duplicate=%d no_match=%d stopped=%s tip=%s\n",
        $result['pages'],
        $result['fetched'],
        $result['stored'],
        $result['duplicate'],
        $result['no_match'],
        $result['stopped'],
        $result['tip_date'] !== null ? gmdate('Y-m-d', $result['tip_date']) : '-'
    ));
    exit($result['stopped'] === 'error' ? 1 : 0);
}

// Legacy oldest-first page cursor
$state = new ScraperState($pdo);
if ($options->pageStart !== null) {
    $start = max(0, $options->pageStart - 1);
    $state->saveLastPage($start);
    if ($options->verbose) {
        fwrite(STDOUT, "page-start override: next page will be {$options->pageStart}\n");
    }
}

$loop = new FetchLoop(
    $client,
    $state,
    waitSeconds: max(0, $options->waitSeconds),
    sleeper: $sleeper,
    onPage: static function (int $page, array $results) use ($dryRun, $pdo, $verbose): void {
        $prefix = $dryRun ? '[dry-run] ' : '';
        if ($dryRun) {
            fwrite(STDOUT, $prefix . "page {$page}: " . count($results) . " items (not stored)\n");
            if ($verbose && isset($results[0]['title'])) {
                fwrite(STDOUT, $prefix . 'sample title: ' . (string) $results[0]['title'] . "\n");
            }
            return;
        }
        $counts = (new CaseStore($pdo))->storeAll($results);
        fwrite(STDOUT, sprintf(
            "%spage %d: fetched=%d stored=%d duplicate=%d no_match=%d\n",
            $prefix,
            $page,
            count($results),
            $counts['stored'],
            $counts['duplicate'],
            $counts['no_match']
        ));
    }
);

fwrite(STDOUT, 'Legacy page scrape starting from page ' . ($state->lastPage() + 1) . "…\n");
$result = $loop->run($options->maxPages);
if ($verbose) {
    foreach ($result['log'] as $line) {
        fwrite(STDOUT, $line . "\n");
    }
}
fwrite(STDOUT, sprintf(
    "Done: pages=%d fetched=%d stopped=%s\n",
    $result['pages'],
    $result['fetched'],
    $result['stopped']
));
exit($result['stopped'] === 'error' ? 1 : 0);
