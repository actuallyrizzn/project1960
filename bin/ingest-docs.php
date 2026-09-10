#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Slow-drip CL document metadata ingest (CL-I2).
 *
 *   php bin/ingest-docs.php [--limit=N] [--wait=3] [--dry-run] [--verbose]
 */

use CourtListener\Exceptions\RateLimitException;
use Project1960\Config;
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
        fwrite(STDERR, "Rate limited on docket {$docketId}; backing off 30s\n");
        sleep(30);
    } catch (Throwable $e) {
        $errors++;
        fwrite(STDERR, "Error on docket {$docketId}: " . $e->getMessage() . "\n");
    }
}

fwrite(STDOUT, sprintf("Done: dockets=%d documents=%d errors=%d\n", count($dockets), $totalDocs, $errors));
exit($errors > 0 ? 2 : 0);
