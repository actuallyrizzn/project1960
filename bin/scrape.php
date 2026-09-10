#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Project 1960 DOJ scraper CLI (SC1: fetch + retry).
 *
 *   php bin/scrape.php [--max-pages=N] [--wait=2] [--dry-run]
 *
 * Uses DATABASE_PATH / db/doj_cases.db for scraper_state.
 */

use Project1960\Config;
use Project1960\Database;
use Project1960\Schema;
use Project1960\Scraper\CaseStore;
use Project1960\Scraper\CurlTransport;
use Project1960\Scraper\DojClient;
use Project1960\Scraper\FetchLoop;
use Project1960\Scraper\ScraperState;

require dirname(__DIR__) . '/vendor/autoload.php';

$opts = getopt('', ['max-pages::', 'wait::', 'dry-run', 'help']);
if (isset($opts['help'])) {
    fwrite(STDOUT, "Usage: php bin/scrape.php [--max-pages=N] [--wait=2] [--dry-run]\n");
    exit(0);
}

$maxPages = isset($opts['max-pages']) ? (int) $opts['max-pages'] : null;
$wait = isset($opts['wait']) ? (int) $opts['wait'] : 2;
$dryRun = array_key_exists('dry-run', $opts);

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
$state = new ScraperState($pdo);
$loop = new FetchLoop(
    $client,
    $state,
    waitSeconds: $wait,
    sleeper: static function (int $seconds): void {
        if ($seconds > 0) {
            sleep($seconds);
        }
    },
    onPage: static function (int $page, array $results) use ($dryRun, $pdo): void {
        $prefix = $dryRun ? '[dry-run] ' : '';
        if ($dryRun) {
            fwrite(STDOUT, $prefix . "page {$page}: " . count($results) . " items (not stored)\n");
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
$result = $loop->run($maxPages);
foreach ($result['log'] as $line) {
    fwrite(STDOUT, $line . "\n");
}
fwrite(STDOUT, sprintf(
    "Done: pages=%d fetched=%d stopped=%s\n",
    $result['pages'],
    $result['fetched'],
    $result['stopped']
));
exit($result['stopped'] === 'error' ? 2 : 0);
