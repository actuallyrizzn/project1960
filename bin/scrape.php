#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Project 1960 DOJ scraper CLI.
 *
 *   php bin/scrape.php [--max-pages=N] [--limit=N] [--page-start=N] [--wait=2] [--dry-run] [--verbose]
 *
 * See docs/scraper.md
 */

use Project1960\Config;
use Project1960\Database;
use Project1960\Schema;
use Project1960\Scraper\CaseStore;
use Project1960\Scraper\CurlTransport;
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

$state = new ScraperState($pdo);
if ($options->pageStart !== null) {
    $start = max(0, $options->pageStart - 1);
    $state->saveLastPage($start);
    if ($options->verbose) {
        fwrite(STDOUT, "page-start override: next page will be {$options->pageStart}\n");
    }
}

$client = new DojClient(new CurlTransport(), retrySleepSeconds: 5);
$verbose = $options->verbose;
$dryRun = $options->dryRun;

$loop = new FetchLoop(
    $client,
    $state,
    waitSeconds: max(0, $options->waitSeconds),
    sleeper: static function (int $seconds): void {
        if ($seconds > 0) {
            sleep($seconds);
        }
    },
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

fwrite(STDOUT, 'Starting from page ' . ($state->lastPage() + 1) . "…\n");
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
exit($result['stopped'] === 'error' ? 2 : 0);
