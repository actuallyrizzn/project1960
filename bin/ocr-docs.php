#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Slow-drip OCR for pending CourtListener docs (CL-O3).
 *
 *   php bin/ocr-docs.php [--limit=N] [--wait=1] [--dry-run] [--metrics] [--verbose]
 */

use Project1960\Config;
use Project1960\CourtListener\OcrCliOptions;
use Project1960\CourtListener\OcrWorker;
use Project1960\CourtListener\TesseractOcrEngine;
use Project1960\CourtListenerDocumentStore;
use Project1960\Database;
use Project1960\Schema;

require dirname(__DIR__) . '/vendor/autoload.php';

$raw = getopt('', ['limit::', 'wait::', 'dry-run', 'metrics', 'verbose', 'help']);
$cli = OcrCliOptions::fromGetopt($raw === false ? [] : $raw);
if ($cli->help) {
    fwrite(STDOUT, "Usage: php bin/ocr-docs.php [--limit=N] [--wait=1] [--dry-run] [--metrics] [--verbose]\n");
    fwrite(STDOUT, "Requires pdftotext/pdftoppm/tesseract on PATH (see docs/ocr.md).\n");
    exit(0);
}

try {
    $pdo = Database::connect(Config::databasePath());
    Schema::migrate($pdo);
} catch (Throwable $e) {
    fwrite(STDERR, 'Database error: ' . $e->getMessage() . "\n");
    exit(1);
}

$worker = new OcrWorker(new TesseractOcrEngine(), new CourtListenerDocumentStore($pdo), $pdo);

if ($cli->metricsOnly) {
    $m = $worker->metrics();
    fwrite(STDOUT, sprintf(
        "ocr metrics: pending=%d done=%d failed=%d none=%d\n",
        $m['pending'],
        $m['done'],
        $m['failed'],
        $m['none']
    ));
    exit(0);
}

$limit = $cli->limit;
$wait = $cli->wait;
$dryRun = $cli->dryRun;
$verbose = $cli->verbose;

$pending = $worker->pendingDocuments($limit);
fwrite(STDOUT, sprintf("OCR %d doc(s)%s\n", count($pending), $dryRun ? ' [dry-run]' : ''));

$counts = ['done' => 0, 'failed' => 0, 'locked' => 0, 'would_ocr' => 0, 'would_fail' => 0];
foreach ($pending as $i => $row) {
    if ($i > 0 && $wait > 0) {
        sleep($wait);
    }
    $id = (int) $row['cl_document_id'];
    try {
        $r = $worker->processOne($id, dryRun: $dryRun);
        $st = $r['status'];
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
    "Done: done=%d failed=%d locked=%d dry=%d\n",
    $counts['done'] ?? 0,
    $counts['failed'] ?? 0,
    $counts['locked'] ?? 0,
    ($counts['would_ocr'] ?? 0) + ($counts['would_fail'] ?? 0)
));
exit(($counts['failed'] ?? 0) > 0 ? 2 : 0);
