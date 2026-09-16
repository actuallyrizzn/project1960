#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Match DOJ seed cases → CourtListener dockets (CL-M1/M2).
 *
 *   php bin/match.php [--limit=N] [--wait=2] [--dry-run] [--verbose] [--all]
 *
 * Free-auth CourtListener caps are tiny (~5/min, 50/hour, 125/day). Cron must
 * stay under that; see docs/courtlistener.md.
 */

use CourtListener\Exceptions\RateLimitException;
use Project1960\ActivityLog;
use Project1960\Config;
use Project1960\CourtListener\ApiUsageGate;
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

$lockPath = sys_get_temp_dir() . '/p1960-cl-match.lock';
$lockFh = fopen($lockPath, 'c');
if ($lockFh === false || !flock($lockFh, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Another match.php is running; exiting.\n");
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

$activity = new ActivityLog($pdo);
$token = (string) (getenv('COURTLISTENER_API_TOKEN') ?: getenv('COURTLISTENER_TOKEN') ?: '');
if ($token !== '' && !$options->dryRun) {
    $gate = ApiUsageGate::fetch($token);
    fwrite(STDOUT, $gate->summary() . "\n");
    if ($gate->shouldSkip(2)) {
        $msg = 'skip drip: ' . $gate->summary();
        fwrite(STDERR, $msg . "\n");
        $activity->record(ActivityLog::STAGE_CL_MATCH, ActivityLog::STATUS_SKIPPED, $msg);
        exit(0);
    }
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
if (!$options->dryRun && $seeds === []) {
    $activity->record(
        ActivityLog::STAGE_CL_MATCH,
        ActivityLog::STATUS_SKIPPED,
        'drip tick: no matchable seeds (all linked or in review)'
    );
}

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
        if (!$options->dryRun) {
            $activity->record(
                ActivityLog::STAGE_CL_MATCH,
                ActivityLog::STATUS_ERROR,
                'rate_limited abort batch: ' . $e->getMessage(),
                (string) $seed['case_id']
            );
        }
        fwrite(STDERR, "Aborting match batch after rate limit.\n");
        break;
    } catch (Throwable $e) {
        $stats->recordError();
        fwrite(STDERR, 'Error on ' . $seed['case_id'] . ': ' . $e->getMessage() . "\n");
        if (!$options->dryRun) {
            $activity->record(
                ActivityLog::STAGE_CL_MATCH,
                ActivityLog::STATUS_ERROR,
                'error: ' . $e->getMessage(),
                (string) $seed['case_id']
            );
        }
    }
}

fwrite(STDOUT, $stats->summaryLine() . "\n");
exit($stats->toArray()['errors'] > 0 ? 2 : 0);
