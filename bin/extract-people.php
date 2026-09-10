#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Slow-drip Venice people extract (CL-X4).
 *
 *   php bin/extract-people.php [--limit=N] [--wait=2] [--dry-run] [--verbose]
 */

use Project1960\Config;
use Project1960\CourtListener\ExtractOrchestrator;
use Project1960\CourtListener\VeniceClient;
use Project1960\CourtListenerPersonStore;
use Project1960\Database;
use Project1960\Schema;

require dirname(__DIR__) . '/vendor/autoload.php';

$opts = getopt('', ['limit::', 'wait::', 'dry-run', 'verbose', 'help']);
if ($opts === false) {
    $opts = [];
}
if (array_key_exists('help', $opts)) {
    fwrite(STDOUT, "Usage: php bin/extract-people.php [--limit=N] [--wait=2] [--dry-run] [--verbose]\n");
    exit(0);
}

$limit = isset($opts['limit']) && is_numeric($opts['limit']) ? max(1, (int) $opts['limit']) : 5;
$wait = isset($opts['wait']) && is_numeric($opts['wait']) ? max(0, (int) $opts['wait']) : 2;
$dryRun = array_key_exists('dry-run', $opts);
$verbose = array_key_exists('verbose', $opts);

try {
    $pdo = Database::connect(Config::databasePath());
    Schema::migrate($pdo);
} catch (Throwable $e) {
    fwrite(STDERR, 'Database error: ' . $e->getMessage() . "\n");
    exit(1);
}

try {
    $llm = VeniceClient::fromEnv();
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$orch = new ExtractOrchestrator($llm, new CourtListenerPersonStore($pdo), $pdo);
$pending = $orch->pendingDocuments($limit);
fwrite(STDOUT, sprintf("Extract %d doc(s)%s\n", count($pending), $dryRun ? ' [dry-run]' : ''));

$counts = ['done' => 0, 'failed' => 0, 'no_text' => 0, 'would_extract' => 0];
foreach ($pending as $i => $row) {
    if ($i > 0 && $wait > 0) {
        sleep($wait);
    }
    try {
        $r = $orch->extractDocument((int) $row['cl_document_id'], (string) $row['case_id'], dryRun: $dryRun);
        $counts[$r['status']] = ($counts[$r['status']] ?? 0) + 1;
        if ($verbose) {
            fwrite(STDOUT, sprintf(
                "  %d case=%s → %s people=%d\n",
                $r['cl_document_id'],
                $row['case_id'],
                $r['status'],
                $r['people']
            ));
        }
    } catch (Throwable $e) {
        $counts['failed']++;
        fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    }
}

fwrite(STDOUT, sprintf(
    "Done: done=%d failed=%d no_text=%d dry=%d\n",
    $counts['done'] ?? 0,
    $counts['failed'] ?? 0,
    $counts['no_text'] ?? 0,
    $counts['would_extract'] ?? 0
));
exit(($counts['failed'] ?? 0) > 0 ? 2 : 0);
