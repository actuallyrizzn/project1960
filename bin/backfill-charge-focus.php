#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Backfill charge-level §1960 focus from press bodies.
 *
 *   php bin/backfill-charge-focus.php --cohort=chokepoint [--dry-run] [--limit=N] [--case-id=UUID]
 *
 * Cohorts: chokepoint (verified+crypto, default), verified, crypto, all
 */

use Project1960\CaseChargeFocusBackfill;
use Project1960\Config;
use Project1960\Database;
use Project1960\Schema;

require dirname(__DIR__) . '/vendor/autoload.php';

$opts = getopt('', ['cohort::', 'limit::', 'case-id::', 'dry-run', 'verbose', 'help']);
if (isset($opts['help'])) {
    fwrite(STDOUT, "Usage: php bin/backfill-charge-focus.php [--cohort=drip|chokepoint|verified|crypto|all] [--limit=N] [--case-id=UUID] [--dry-run] [--verbose]\n");
    exit(0);
}

$cohort = (string) ($opts['cohort'] ?? 'drip');
$limit = isset($opts['limit']) ? (int) $opts['limit'] : null;
$caseId = isset($opts['case-id']) ? (string) $opts['case-id'] : null;
$dryRun = array_key_exists('dry-run', $opts);
$verbose = array_key_exists('verbose', $opts);

try {
    $pdo = Database::connect(Config::databasePath());
    Schema::migrate($pdo);
} catch (Throwable $e) {
    fwrite(STDERR, 'Database error: ' . $e->getMessage() . "\n");
    exit(1);
}

$backfill = new CaseChargeFocusBackfill($pdo);
$ids = $backfill->selectCaseIds($cohort, $limit, $caseId);
fwrite(STDOUT, sprintf(
    "Backfill charge focus cohort=%s cases=%d%s\n",
    $cohort,
    count($ids),
    $dryRun ? ' [dry-run]' : ''
));

$tot = [
    'charges_upserted' => 0,
    'charges_1960' => 0,
    'docket_refs' => 0,
    'existing_flagged' => 0,
    'links_tagged' => 0,
    'structured' => 0,
    'prose' => 0,
    'errors' => 0,
];

foreach ($ids as $id) {
    try {
        $r = $backfill->processCase($id, $dryRun);
        $tot['charges_upserted'] += $r['charges_upserted'];
        $tot['charges_1960'] += $r['charges_1960'];
        $tot['docket_refs'] += $r['docket_refs'];
        $tot['existing_flagged'] += $r['existing_flagged'];
        $tot['links_tagged'] += $r['links_tagged'];
        if ($r['mode'] === 'structured') {
            $tot['structured']++;
        } else {
            $tot['prose']++;
        }
        if ($verbose) {
            fwrite(STDOUT, sprintf(
                "  %s mode=%s charges=%d is1960=%d refs=%d flagged=%d links=%d\n",
                $id,
                $r['mode'],
                $r['charges_upserted'],
                $r['charges_1960'],
                $r['docket_refs'],
                $r['existing_flagged'],
                $r['links_tagged']
            ));
        }
    } catch (Throwable $e) {
        $tot['errors']++;
        fwrite(STDERR, "Error {$id}: " . $e->getMessage() . "\n");
    }
}

fwrite(STDOUT, sprintf(
    "Done: structured=%d prose=%d charges=%d is1960=%d refs=%d flagged=%d links=%d errors=%d\n",
    $tot['structured'],
    $tot['prose'],
    $tot['charges_upserted'],
    $tot['charges_1960'],
    $tot['docket_refs'],
    $tot['existing_flagged'],
    $tot['links_tagged'],
    $tot['errors']
));
exit($tot['errors'] > 0 ? 1 : 0);
