#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Match DOJ seed cases → CourtListener dockets (CL-M1/M2).
 *
 *   php bin/match.php [--limit=N] [--wait=2] [--dry-run] [--verbose] [--all]
 *
 * See docs/courtlistener.md
 */

use CourtListener\Exceptions\RateLimitException;
use Project1960\Config;
use Project1960\CourtListener\ClientFactory;
use Project1960\CourtListener\DocketMatcher;
use Project1960\CourtListener\MatchBatchStats;
use Project1960\CourtListener\MatchCliOptions;
use Project1960\CourtListener\SdkSearchGateway;
use Project1960\CourtListenerDocketStore;
use Project1960\CourtListenerMatchReviewStore;
use Project1960\Database;
use Project1960\Schema;

require dirname(__DIR__) . '/vendor/autoload.php';

$options = MatchCliOptions::fromArgv($argv);
if ($options->help) {
    fwrite(STDOUT, MatchCliOptions::helpText());
    exit(0);
}

try {
    $dbPath = Config::databasePath();
    if (!is_file($dbPath)) {
        fwrite(STDERR, "Database missing: {$dbPath}\n");
        exit(1);
    }
    $pdo = Database::connect($dbPath);
    Schema::migrate($pdo);
} catch (Throwable $e) {
    fwrite(STDERR, 'Database error: ' . $e->getMessage() . "\n");
    exit(1);
}

try {
    $client = ClientFactory::make();
} catch (Throwable $e) {
    fwrite(STDERR, 'CourtListener client: ' . $e->getMessage() . "\n");
    exit(1);
}

$matcher = new DocketMatcher(
    new SdkSearchGateway($client),
    new CourtListenerDocketStore($pdo),
    new CourtListenerMatchReviewStore($pdo),
    $pdo,
);

$seeds = $matcher->loadSeeds($options->limit, verifiedOnly: !$options->allCases);
fwrite(STDOUT, sprintf(
    "Matching %d seed(s)%s wait=%ds…\n",
    count($seeds),
    $options->dryRun ? ' [dry-run]' : '',
    $options->waitSeconds
));

$stats = new MatchBatchStats();
foreach ($seeds as $i => $seed) {
    if ($i > 0 && $options->waitSeconds > 0) {
        sleep($options->waitSeconds);
    }
    try {
        $result = $matcher->matchOne($seed, dryRun: $options->dryRun);
        $stats->record($result['outcome']);
        if ($options->verbose) {
            fwrite(STDOUT, sprintf(
                "  %s → %s conf=%.3f cl=%s\n",
                $seed['case_id'],
                $result['outcome'],
                $result['confidence'],
                $result['cl_docket_id'] !== null ? (string) $result['cl_docket_id'] : '-'
            ));
        }
    } catch (RateLimitException $e) {
        $stats->recordError();
        fwrite(STDERR, 'Rate limited on ' . $seed['case_id'] . ': ' . $e->getMessage() . "\n");
        fwrite(STDERR, "Backing off 30s then continuing…\n");
        sleep(30);
    } catch (Throwable $e) {
        $stats->recordError();
        fwrite(STDERR, 'Error on ' . $seed['case_id'] . ': ' . $e->getMessage() . "\n");
    }
}

fwrite(STDOUT, $stats->summaryLine() . "\n");
exit($stats->toArray()['errors'] > 0 ? 2 : 0);
