#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Dedupe cases table (same id repeated because legacy DB lacked PRIMARY KEY).
 *
 *   php bin/dedupe-cases.php [--dry-run] [--verbose]
 *
 * Env: DATABASE_PATH (prod: /var/www/project1960.rizzn.net/db/doj_cases.db)
 */

use Project1960\CasesDeduper;
use Project1960\Config;
use Project1960\Database;
use Project1960\Schema;

require dirname(__DIR__) . '/vendor/autoload.php';

$dryRun = in_array('--dry-run', $argv, true);
$verbose = in_array('--verbose', $argv, true) || in_array('-v', $argv, true);
$help = in_array('--help', $argv, true) || in_array('-h', $argv, true);

if ($help) {
    fwrite(STDOUT, "Usage: php bin/dedupe-cases.php [--dry-run] [--verbose]\n");
    fwrite(STDOUT, "Keeps one row per cases.id (verified > classified > longest body).\n");
    exit(0);
}

try {
    $dbPath = Config::databasePath();
    if (!is_file($dbPath)) {
        fwrite(STDERR, "Database missing: {$dbPath}\n");
        exit(1);
    }
    $pdo = Database::connect($dbPath);
} catch (Throwable $e) {
    fwrite(STDERR, 'Database error: ' . $e->getMessage() . "\n");
    exit(1);
}

$deduper = new CasesDeduper($pdo);
$before = $deduper->analyze();
fwrite(STDOUT, sprintf(
    "Before: total=%d distinct_ids=%d dup_groups=%d rows_to_delete=%d%s\n",
    $before['total_rows'],
    $before['distinct_ids'],
    $before['duplicate_groups'],
    $before['rows_to_delete'],
    $dryRun ? ' [dry-run]' : ''
));

if ($before['rows_to_delete'] === 0) {
    fwrite(STDOUT, "Nothing to delete.\n");
    Schema::migrate($pdo);
    fwrite(STDOUT, "Schema migrate OK (PK / unique url).\n");
    exit(0);
}

try {
    $result = $deduper->dedupeById(dryRun: $dryRun);
} catch (Throwable $e) {
    fwrite(STDERR, 'Dedupe failed: ' . $e->getMessage() . "\n");
    exit(1);
}

fwrite(STDOUT, sprintf(
    "Result: deleted=%d kept=%d dry_run=%s\n",
    $result['deleted'],
    $result['kept'],
    $result['dry_run'] ? 'yes' : 'no'
));

if (!$dryRun) {
    Schema::migrate($pdo);
    $after = $deduper->analyze();
    fwrite(STDOUT, sprintf(
        "After: total=%d distinct_ids=%d dup_groups=%d\n",
        $after['total_rows'],
        $after['distinct_ids'],
        $after['duplicate_groups']
    ));
    if ($verbose) {
        fwrite(STDOUT, "Schema migrate applied (cases PRIMARY KEY + unique url index).\n");
    }
}

exit(0);
