#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * CL-I4: optional RECAP/PACER fetch — dry-run by default (never spends).
 *
 *   php bin/recap-fetch.php [--limit=N] [--budget=N] [--live] [--verbose]
 */

use Project1960\Config;
use Project1960\CourtListener\ClientFactory;
use Project1960\CourtListener\RecapFetchJob;
use Project1960\CourtListener\SdkRecapFetchGateway;
use Project1960\Database;
use Project1960\Schema;

require dirname(__DIR__) . '/vendor/autoload.php';

$opts = getopt('', ['limit::', 'budget::', 'live', 'verbose', 'help']) ?: [];
if (array_key_exists('help', $opts)) {
    fwrite(STDOUT, "Usage: php bin/recap-fetch.php [--limit=20] [--budget=5] [--live] [--verbose]\n");
    fwrite(STDOUT, "Default is dry-run (no PACER spend). --live requires COURTLISTENER_API_TOKEN + PACER policy Doc #1313.\n");
    exit(0);
}
$limit = isset($opts['limit']) && is_numeric($opts['limit']) ? max(1, (int) $opts['limit']) : 20;
$budget = isset($opts['budget']) && is_numeric($opts['budget']) ? max(0, (int) $opts['budget']) : 5;
$live = array_key_exists('live', $opts);

$pdo = Database::connect(Config::databasePath());
Schema::migrate($pdo);
$client = ClientFactory::make();
$job = new RecapFetchJob(new SdkRecapFetchGateway($client), $pdo);
$stats = $job->run($limit, $budget, live: $live);
fwrite(STDOUT, json_encode($stats, JSON_PRETTY_PRINT) . "\n");
exit(($stats['errors'] ?? 0) > 0 ? 2 : 0);
