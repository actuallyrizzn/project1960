#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Slow-drip download of free CL/RECAP document bytes (CL-I3).
 *
 *   php bin/download-docs.php [--limit=N] [--wait=2] [--dry-run] [--verbose]
 */

use Project1960\Config;
use Project1960\CourtListener\CurlHttpFetcher;
use Project1960\CourtListener\DocumentDownloader;
use Project1960\CourtListenerDocumentStore;
use Project1960\Database;
use Project1960\Schema;

require dirname(__DIR__) . '/vendor/autoload.php';

$opts = getopt('', ['limit::', 'wait::', 'dry-run', 'verbose', 'help']);
if ($opts === false) {
    $opts = [];
}
if (array_key_exists('help', $opts)) {
    fwrite(STDOUT, "Usage: php bin/download-docs.php [--limit=N] [--wait=2] [--dry-run] [--verbose]\n");
    fwrite(STDOUT, "Stores under storage/cl-docs/ (or CL_DOCS_PATH). Skips PACER-only URLs.\n");
    exit(0);
}
$limit = isset($opts['limit']) && is_numeric($opts['limit']) ? max(1, (int) $opts['limit']) : 10;
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

$storage = DocumentDownloader::defaultStorageDir();
$dl = new DocumentDownloader(new CurlHttpFetcher(), new CourtListenerDocumentStore($pdo), $pdo, $storage);
$pending = $dl->pendingDocuments($limit);
fwrite(STDOUT, sprintf("Downloading %d doc(s)%s → %s\n", count($pending), $dryRun ? ' [dry-run]' : '', $storage));

$counts = ['done' => 0, 'skipped_pacer' => 0, 'failed' => 0];
foreach ($pending as $i => $row) {
    if ($i > 0 && $wait > 0) {
        sleep($wait);
    }
    $id = (int) $row['cl_document_id'];
    try {
        $result = $dl->downloadOne($id, dryRun: $dryRun);
        $st = $result['status'];
        $counts[$st] = ($counts[$st] ?? 0) + 1;
        if ($verbose) {
            fwrite(STDOUT, sprintf("  %d → %s\n", $id, $st));
        }
    } catch (Throwable $e) {
        $counts['failed']++;
        fwrite(STDERR, "Error {$id}: " . $e->getMessage() . "\n");
    }
}

fwrite(STDOUT, sprintf(
    "Done: done=%d skipped_pacer=%d failed=%d\n",
    $counts['done'],
    $counts['skipped_pacer'],
    $counts['failed']
));
exit($counts['failed'] > 0 ? 2 : 0);
