<?php
declare(strict_types=1);

/**
 * Prototype: incremental DOJ press-release poll by date watermark.
 *
 * Walks press_releases.json newest-first (sort_by=date&sort_order=DESC) and
 * STOP when item dates fall below the watermark (MAX(cases.date) or --since=).
 * Does not download the full archive.
 *
 *   php tools/doj-incremental-poll/prototype.php [--db=PATH] [--since=UNIX] \
 *     [--max-pages=N] [--pagesize=50] [--wait=1] [--dry-run] [--verbose]
 *
 * Proof notes: Tasks #4000. Not wired into bin/scrape.php yet.
 */

use Project1960\Config;
use Project1960\Database;
use Project1960\Schema;
use Project1960\Scraper\CurlTransport;
use Project1960\Scraper\KeywordFilters;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$opts = getopt('', ['db::', 'since::', 'max-pages::', 'pagesize::', 'wait::', 'dry-run', 'verbose', 'help']);
if ($opts === false) {
    $opts = [];
}
if (array_key_exists('help', $opts)) {
    fwrite(STDOUT, "Usage: php tools/doj-incremental-poll/prototype.php [--db=PATH] [--since=UNIX] [--max-pages=N] [--pagesize=50] [--wait=1] [--dry-run] [--verbose]\n");
    exit(0);
}

$dryRun = array_key_exists('dry-run', $opts);
$verbose = array_key_exists('verbose', $opts);
$maxPages = isset($opts['max-pages']) && is_numeric($opts['max-pages']) ? max(1, (int) $opts['max-pages']) : 20;
$pageSize = isset($opts['pagesize']) && is_numeric($opts['pagesize']) ? max(1, min(100, (int) $opts['pagesize'])) : 50;
$wait = isset($opts['wait']) && is_numeric($opts['wait']) ? max(0, (int) $opts['wait']) : 1;

$dbPath = null;
if (isset($opts['db']) && is_string($opts['db']) && $opts['db'] !== '') {
    $dbPath = $opts['db'];
} else {
    $dbPath = Config::databasePath();
}

$watermark = null;
if (isset($opts['since']) && is_numeric($opts['since'])) {
    $watermark = (int) $opts['since'];
}

$knownIds = [];
if (is_file($dbPath)) {
    $pdo = Database::connect($dbPath);
    Schema::migrate($pdo);
    if ($watermark === null) {
        $max = $pdo->query('SELECT MAX(CAST(date AS INTEGER)) FROM cases')->fetchColumn();
        $watermark = ($max !== false && $max !== null && $max !== '') ? (int) $max : 0;
    }
    $stmt = $pdo->query('SELECT id FROM cases');
    if ($stmt !== false) {
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $knownIds[(string) $id] = true;
        }
    }
} elseif ($watermark === null) {
    fwrite(STDERR, "No database at {$dbPath} and no --since=; refusing to run without a watermark.\n");
    exit(2);
}

fwrite(STDOUT, sprintf(
    "Prototype incremental poll watermark=%d (%s) db=%s known_ids=%d dry_run=%s\n",
    $watermark,
    $watermark > 0 ? gmdate('Y-m-d', $watermark) : 'none',
    $dbPath,
    count($knownIds),
    $dryRun ? 'yes' : 'no'
));

$transport = new CurlTransport();
$api = 'https://www.justice.gov/api/v1/press_releases.json';

$pages = 0;
$fetched = 0;
$newer = 0;
$wouldStore = 0;
$dupSameDay = 0;
$noMatch = 0;
$stopped = 'unknown';
$stopDate = null;
$seenThisRun = [];

for ($page = 0; $page < $maxPages; $page++) {
    $resp = $transport->get(
        $api,
        [
            'pagesize' => $pageSize,
            'page' => $page,
            'sort_by' => 'date',
            'sort_order' => 'DESC',
        ],
        45
    );
    if ($resp['status'] !== 200) {
        fwrite(STDERR, "HTTP {$resp['status']} on page {$page}\n");
        $stopped = 'http_error';
        break;
    }
    /** @var array<string, mixed> $data */
    $data = json_decode($resp['body'], true, 512, JSON_THROW_ON_ERROR);
    $results = $data['results'] ?? [];
    if (!is_array($results) || $results === []) {
        $stopped = 'empty';
        break;
    }

    $pages++;
    $pageMin = null;
    $hitOlder = false;
    foreach ($results as $row) {
        if (!is_array($row)) {
            continue;
        }
        $fetched++;
        $date = isset($row['date']) && is_numeric($row['date']) ? (int) $row['date'] : 0;
        if ($pageMin === null || ($date > 0 && $date < $pageMin)) {
            $pageMin = $date;
        }

        // Past the frontier into older corpus → stop after this item.
        if ($watermark > 0 && $date > 0 && $date < $watermark) {
            $hitOlder = true;
            $stopDate = $date;
            break;
        }

        $uuid = isset($row['uuid']) ? (string) $row['uuid'] : '';
        if ($uuid !== '' && isset($seenThisRun[$uuid])) {
            continue;
        }
        if ($uuid !== '') {
            $seenThisRun[$uuid] = true;
        }

        $title = (string) ($row['title'] ?? '');
        $body = (string) ($row['body'] ?? '');
        $match = KeywordFilters::mentions1960($body) || KeywordFilters::mentions1960($title)
            || KeywordFilters::mentionsCrypto($body) || KeywordFilters::mentionsCrypto($title);

        if ($date > $watermark) {
            $newer++;
            if (!$match) {
                $noMatch++;
            } elseif ($uuid !== '' && isset($knownIds[$uuid])) {
                $dupSameDay++;
            } else {
                $wouldStore++;
                if ($verbose) {
                    fwrite(STDOUT, sprintf(
                        "  NEW %s %s %s\n",
                        gmdate('Y-m-d', $date),
                        $uuid !== '' ? substr($uuid, 0, 8) : 'no-uuid',
                        substr(strip_tags($title), 0, 70)
                    ));
                }
            }
        } else {
            // date == watermark (same calendar frontier): only count unknown uuids
            if ($uuid !== '' && isset($knownIds[$uuid])) {
                $dupSameDay++;
            } elseif ($match) {
                $wouldStore++;
                $newer++;
                if ($verbose) {
                    fwrite(STDOUT, sprintf(
                        "  SAME-DAY-NEW %s %s %s\n",
                        gmdate('Y-m-d', $date),
                        $uuid !== '' ? substr($uuid, 0, 8) : 'no-uuid',
                        substr(strip_tags($title), 0, 70)
                    ));
                }
            } else {
                $noMatch++;
            }
        }
    }

    if ($verbose) {
        fwrite(STDOUT, sprintf(
            "page %d: items=%d page_min_date=%s\n",
            $page,
            count($results),
            $pageMin ? gmdate('Y-m-d', $pageMin) : '?'
        ));
    }

    if ($hitOlder) {
        $stopped = 'watermark';
        break;
    }

    if ($wait > 0 && $page + 1 < $maxPages) {
        sleep($wait);
    }
}

if ($stopped === 'unknown' && $pages >= $maxPages) {
    $stopped = 'max_pages';
}

fwrite(STDOUT, sprintf(
    "Done: pages=%d fetched=%d newer_or_frontier=%d would_store_keyword_hits=%d no_match=%d known_dup=%d stopped=%s%s\n",
    $pages,
    $fetched,
    $newer,
    $wouldStore,
    $noMatch,
    $dupSameDay,
    $stopped,
    $stopDate !== null ? (' first_older=' . gmdate('Y-m-d', $stopDate)) : ''
));

exit($stopped === 'http_error' ? 1 : 0);
