#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Match DOJ seed cases → CourtListener dockets (CL-M1).
 *
 *   php bin/match.php [--limit=N] [--dry-run] [--verbose] [--all]
 *
 * See docs/courtlistener.md
 */

use Project1960\Config;
use Project1960\CourtListener\ClientFactory;
use Project1960\CourtListener\DocketMatcher;
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
    "Matching %d seed(s)%s…\n",
    count($seeds),
    $options->dryRun ? ' [dry-run]' : ''
));

$counts = ['matched' => 0, 'ambiguous' => 0, 'no_match' => 0];
foreach ($seeds as $seed) {
    $result = $matcher->matchOne($seed, dryRun: $options->dryRun);
    $out = $result['outcome'];
    if (str_contains($out, 'matched')) {
        $counts['matched']++;
    } elseif (str_contains($out, 'ambiguous')) {
        $counts['ambiguous']++;
    } else {
        $counts['no_match']++;
    }
    if ($options->verbose) {
        fwrite(STDOUT, sprintf(
            "  %s → %s conf=%.3f cl=%s\n",
            $seed['case_id'],
            $out,
            $result['confidence'],
            $result['cl_docket_id'] !== null ? (string) $result['cl_docket_id'] : '-'
        ));
    }
}

fwrite(STDOUT, sprintf(
    "Done: matched=%d ambiguous=%d no_match=%d\n",
    $counts['matched'],
    $counts['ambiguous'],
    $counts['no_match']
));
exit(0);
