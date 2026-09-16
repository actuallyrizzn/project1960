#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Slow-drip CL document metadata ingest (CL-I2).
 *
 *   php bin/ingest-docs.php [--limit=N] [--wait=3] [--dry-run] [--verbose]
 *
 * Shares the free-auth daily quota with match.php — check api-usage first.
 */

use CourtListener\Exceptions\RateLimitException;
use Project1960\ActivityLog;
use Project1960\Config;
use Project1960\CourtListener\ApiUsageGate;
use Project1960\CourtListener\ClientFactory;
use Project1960\CourtListener\DocumentIngestor;
use Project1960\CourtListener\IngestCliOptions;
use Project1960\CourtListener\SdkIngestGateway;
use Project1960\CourtListenerDocumentStore;
use Project1960\Database;
use Project1960\Schema;

require dirname(__DIR__) . '/vendor/autoload.php';

$options = IngestCliOptions::fromArgv($argv);
if ($options->help) {
    fwrite(STDOUT, IngestCliOptions::helpText());
    exit(0);
}

$lockPath = sys_get_temp_dir() . '/p1960-cl-ingest.lock';
$lockFh = fopen($lockPath, 'c');
if ($lockFh === false || !flock($lockFh, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Another ingest-docs.php is running; exiting.\n");
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
    // Each docket ≈ 1 API call (docket-entries with nested docs).
    if ($gate->shouldSkip(1)) {
        $msg = 'skip ingest: ' . $gate->summary();
        fwrite(STDERR, $msg . "\n");
        $activity->record(ActivityLog::STAGE_CL_INGEST, ActivityLog::STATUS_SKIPPED, $msg);
        exit(0);
    }
}

try {
    $client = ClientFactory::make();
} catch (Throwable $e) {
    fwrite(STDERR, 'CourtListener client: ' . $e->getMessage() . "\n");
    exit(1);
}

$ingestor = new DocumentIngestor(
    new SdkIngestGateway($client),
    new CourtListenerDocumentStore($pdo),
    $pdo,
);

$dockets = $ingestor->linkedDocketIds($options->limit);
fwrite(STDOUT, sprintf(
    "Ingesting %d linked docket(s)%s wait=%ds…\n",
    count($dockets),
    $options->dryRun ? ' [dry-run]' : '',
    $options->waitSeconds
));

$totalDocs = 0;
$errors = 0;
foreach ($dockets as $i => $docketId) {
    if ($i > 0 && $options->waitSeconds > 0) {
        sleep($options->waitSeconds);
    }
    try {
        $result = $ingestor->ingestDocket($docketId, dryRun: $options->dryRun);
        $totalDocs += $result['documents_upserted'];
        if ($options->verbose) {
            fwrite(STDOUT, sprintf(
                "  docket %d: entries=%d docs=%d\n",
                $result['docket_id'],
                $result['entries_seen'],
                $result['documents_upserted']
            ));
        }
    } catch (RateLimitException $e) {
        $errors++;
        fwrite(STDERR, "Rate limited on docket {$docketId}; aborting ingest batch\n");
        if (!$options->dryRun) {
            $caseId = $activity->caseIdForDocket((int) $docketId);
            $activity->record(
                ActivityLog::STAGE_CL_INGEST,
                ActivityLog::STATUS_ERROR,
                'rate_limited abort batch docket #' . $docketId . ': ' . $e->getMessage(),
                $caseId
            );
        }
        break;
    } catch (Throwable $e) {
        $errors++;
        fwrite(STDERR, "Error on docket {$docketId}: " . $e->getMessage() . "\n");
        if (!$options->dryRun) {
            $caseId = $activity->caseIdForDocket((int) $docketId);
            $activity->record(
                ActivityLog::STAGE_CL_INGEST,
                ActivityLog::STATUS_ERROR,
                'error docket #' . $docketId . ': ' . $e->getMessage(),
                $caseId
            );
        }
    }
}

fwrite(STDOUT, sprintf("Done: dockets=%d documents=%d errors=%d\n", count($dockets), $totalDocs, $errors));
exit($errors > 0 ? 2 : 0);
