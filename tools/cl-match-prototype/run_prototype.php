#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Local CourtListener match-confidence prototype (known-good dockets).
 *
 *   set -a && . ~/.ssh/courtlistener-api.pass && set +a
 *   php tools/cl-match-prototype/run_prototype.php [--limit=5] [--wait=3]
 *
 * Reads tools/cl-match-prototype/known-good-seeds.json
 * Writes tools/cl-match-prototype/results.json
 */

use Project1960\CourtListener\ClientFactory;
use Project1960\CourtListener\DocketMatcher;
use Project1960\CourtListener\SdkSearchGateway;
use Project1960\CourtListenerDocketStore;
use Project1960\CourtListenerMatchReviewStore;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$limit = 5;
$wait = 3;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $limit = max(1, (int) substr($arg, 8));
    }
    if (str_starts_with($arg, '--wait=')) {
        $wait = max(0, (int) substr($arg, 7));
    }
}

$seedPath = __DIR__ . '/known-good-seeds.json';
$raw = json_decode((string) file_get_contents($seedPath), true, 512, JSON_THROW_ON_ERROR);
/** @var list<array<string, mixed>> $all */
$all = $raw['seeds'] ?? [];

// Prefer single clean docket strings for the first prototype pass
$clean = array_values(array_filter($all, static function (array $s): bool {
    $n = (string) ($s['case_number'] ?? '');
    if ($n === '' || str_contains($n, ',')) {
        return false;
    }
    // Drop "E.D.N.Y. Docket No. …" wrappers for a second pass; keep simple forms first
    if (preg_match('/docket\s+no/i', $n)) {
        return false;
    }

    return true;
}));

$seeds = array_slice($clean !== [] ? $clean : $all, 0, $limit);
fwrite(STDOUT, sprintf("Prototyping %d seed(s) wait=%ds…\n", count($seeds), $wait));

$client = ClientFactory::make();
$gateway = new SdkSearchGateway($client);
// Throwaway in-memory SQLite so we can reuse DocketMatcher scoring helpers
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE cases (id TEXT PRIMARY KEY)');
$pdo->exec('CREATE TABLE courtlistener_dockets (cl_docket_id INTEGER PRIMARY KEY, court_id TEXT, docket_number TEXT, case_name TEXT, raw_json TEXT, updated_at TEXT)');
$pdo->exec('CREATE TABLE case_courtlistener_links (case_id TEXT, cl_docket_id INTEGER, match_confidence REAL, match_method TEXT, matched_at TEXT, raw_json TEXT, updated_at TEXT, PRIMARY KEY(case_id, cl_docket_id))');
$pdo->exec('CREATE TABLE cl_match_reviews (case_id TEXT PRIMARY KEY, status TEXT, reason TEXT, candidates_json TEXT, updated_at TEXT)');
$matcher = new DocketMatcher(
    $gateway,
    new CourtListenerDocketStore($pdo),
    new CourtListenerMatchReviewStore($pdo),
    $pdo,
);

/**
 * @param list<array<string, mixed>> $results
 * @return list<array{cl_docket_id: ?int, docket_number: string, case_name: string, court_id: string, score_current: float, docket_exact: bool, docket_core: bool}>
 */
function score_all(DocketMatcher $matcher, array $seed, array $results): array
{
    $out = [];
    $seen = [];
    foreach ($results as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = null;
        foreach (['docket_id', 'id'] as $k) {
            if (isset($row[$k]) && is_numeric($row[$k]) && (int) $row[$k] > 0) {
                $id = (int) $row[$k];
                break;
            }
        }
        if ($id !== null && isset($seen[$id])) {
            continue;
        }
        if ($id !== null) {
            $seen[$id] = true;
        }
        $docketNumber = (string) ($row['docketNumber'] ?? $row['docket_number'] ?? '');
        $seedNumber = trim((string) ($seed['case_number'] ?? ''));
        $exact = $seedNumber !== '' && $docketNumber !== ''
            && $matcher->normalizeDocketNumber($seedNumber) === $matcher->normalizeDocketNumber($docketNumber);
        $coreA = $matcher->docketCore($seedNumber);
        $coreB = $matcher->docketCore($docketNumber);
        $core = $coreA !== '' && $coreA === $coreB;
        $out[] = [
            'cl_docket_id' => $id,
            'docket_number' => $docketNumber,
            'case_name' => (string) ($row['caseName'] ?? $row['case_name'] ?? ''),
            'court_id' => (string) ($row['court_id'] ?? $row['court'] ?? ''),
            'score_current' => $matcher->scoreCandidate($seed, $row),
            'docket_exact' => $exact,
            'docket_core' => $core,
        ];
    }
    usort($out, static fn ($a, $b) => $b['score_current'] <=> $a['score_current']);

    return $out;
}

/**
 * @return list<array<string, mixed>>
 */
function search_type(SdkSearchGateway $gateway, string $q, string $type): array
{
    $resp = $gateway->search([
        'q' => $q,
        'type' => $type,
        'page_size' => 10,
    ]);
    $chunk = $resp['results'] ?? [];

    return is_array($chunk) ? $chunk : [];
}

$report = [];
foreach ($seeds as $i => $seed) {
    if ($i > 0 && $wait > 0) {
        sleep($wait);
    }
    $caseNumber = trim((string) ($seed['case_number'] ?? ''));
    $party = trim((string) (($seed['party_names'][0] ?? '') ?: ''));
    $queries = [
        'docket_only' => $caseNumber,
        'party_docket' => trim($party . ' ' . $caseNumber),
        'current_buildQuery' => $matcher->buildQuery($seed),
    ];
    // Also try stripped judge suffix: 18-cr-1129-GPC → 18-cr-1129
    if (preg_match('/^(\d{1,2}[-:]?\s*cr[-:]?\s*\d+)/i', $caseNumber, $m)) {
        $queries['docket_stripped'] = $m[1];
    }

    $rowOut = [
        'case_id' => $seed['case_id'],
        'title' => $seed['title'],
        'case_number' => $caseNumber,
        'party' => $party,
        'queries' => [],
    ];

    foreach ($queries as $label => $q) {
        if ($q === '') {
            continue;
        }
        $combined = [];
        foreach (['d', 'r'] as $type) {
            try {
                $hits = search_type($gateway, $q, $type);
            } catch (Throwable $e) {
                $rowOut['queries'][$label][$type] = ['error' => $e->getMessage()];
                continue;
            }
            foreach ($hits as $h) {
                $combined[] = $h;
            }
            usleep(250000);
        }
        $scored = score_all($matcher, $seed, $combined);
        $best = $scored[0] ?? null;
        $second = $scored[1] ?? null;
        $gap = ($best && $second) ? ($best['score_current'] - $second['score_current']) : 1.0;
        $wouldAcceptCurrent = $best
            && $best['score_current'] >= DocketMatcher::ACCEPT_MIN
            && $gap >= DocketMatcher::AMBIGUOUS_GAP;
        $wouldAcceptDocketRule = $best && ($best['docket_exact'] || $best['docket_core']);

        $rowOut['queries'][$label] = [
            'q' => $q,
            'hit_count' => count($scored),
            'best' => $best,
            'second_score' => $second['score_current'] ?? null,
            'gap' => round($gap, 4),
            'would_accept_current' => $wouldAcceptCurrent,
            'would_accept_docket_rule' => $wouldAcceptDocketRule,
            'top3' => array_slice($scored, 0, 3),
        ];

        fwrite(STDOUT, sprintf(
            "  %s | %s | q=%s | hits=%d | best=%.3f exact=%s core=%s | cur=%s docketRule=%s\n",
            substr((string) $seed['case_id'], 0, 8),
            $label,
            $q,
            count($scored),
            $best['score_current'] ?? 0,
            ($best['docket_exact'] ?? false) ? 'Y' : 'n',
            ($best['docket_core'] ?? false) ? 'Y' : 'n',
            $wouldAcceptCurrent ? 'ACCEPT' : 'no',
            $wouldAcceptDocketRule ? 'ACCEPT' : 'no'
        ));
    }
    $report[] = $rowOut;
}

$outPath = __DIR__ . '/results.json';
file_put_contents($outPath, json_encode([
    'generated_at' => gmdate('c'),
    'accept_min' => DocketMatcher::ACCEPT_MIN,
    'ambiguous_gap' => DocketMatcher::AMBIGUOUS_GAP,
    'seeds_tested' => count($report),
    'results' => $report,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
fwrite(STDOUT, "Wrote {$outPath}\n");
