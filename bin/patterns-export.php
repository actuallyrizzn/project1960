#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Export pattern query results as JSON or CSV (CL-P2).
 *
 *   php bin/patterns-export.php --type=multi-case|attorneys|districts|agencies [--format=json|csv] [--min=2]
 */

use Project1960\Config;
use Project1960\CourtListener\PatternQueries;
use Project1960\Database;
use Project1960\Schema;

require dirname(__DIR__) . '/vendor/autoload.php';

$opts = getopt('', ['type::', 'format::', 'min::', 'limit::', 'help']);
if ($opts === false) {
    $opts = [];
}
if (array_key_exists('help', $opts)) {
    fwrite(STDOUT, "Usage: php bin/patterns-export.php --type=multi-case|attorneys|districts|agencies [--format=json|csv]\n");
    exit(0);
}

$type = (string) ($opts['type'] ?? 'multi-case');
$format = strtolower((string) ($opts['format'] ?? 'json'));
$min = isset($opts['min']) && is_numeric($opts['min']) ? max(2, (int) $opts['min']) : 2;
$limit = isset($opts['limit']) && is_numeric($opts['limit']) ? max(1, (int) $opts['limit']) : 100;

try {
    $pdo = Database::connect(Config::databasePath());
    Schema::migrate($pdo);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$q = new PatternQueries($pdo);
$rows = match ($type) {
    'attorneys' => $q->sharedAttorneys($min, $limit),
    'districts' => $q->sharedDistricts($limit),
    'agencies' => $q->sharedAgencies($min, $limit),
    default => $q->multiCasePersons($min, $limit),
};

if ($format === 'csv') {
    if ($rows === []) {
        exit(0);
    }
    $headers = array_keys($rows[0]);
    fputcsv(STDOUT, $headers);
    foreach ($rows as $row) {
        $line = [];
        foreach ($headers as $h) {
            $v = $row[$h] ?? '';
            $line[] = is_array($v) ? implode('|', $v) : $v;
        }
        fputcsv(STDOUT, $line);
    }
    exit(0);
}

fwrite(STDOUT, json_encode($rows, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
