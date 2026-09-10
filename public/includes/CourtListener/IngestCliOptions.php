<?php
declare(strict_types=1);

namespace Project1960\CourtListener;

/** CLI options for bin/ingest-docs.php (CL-I2). */
final class IngestCliOptions
{
    public function __construct(
        public readonly int $limit,
        public readonly int $waitSeconds,
        public readonly bool $dryRun,
        public readonly bool $verbose,
        public readonly bool $help,
    ) {
    }

    /**
     * @param array<string, string|false> $opts
     */
    public static function fromGetopt(array $opts): self
    {
        $limit = self::optionalInt($opts, 'limit') ?? 10;
        $wait = self::optionalInt($opts, 'wait') ?? 3;

        return new self(
            limit: max(1, $limit),
            waitSeconds: max(0, $wait),
            dryRun: array_key_exists('dry-run', $opts),
            verbose: array_key_exists('verbose', $opts),
            help: array_key_exists('help', $opts),
        );
    }

    /**
     * @param list<string> $argv
     */
    public static function fromArgv(array $argv): self
    {
        $args = array_values(array_slice($argv, 1));
        $opts = [];
        $i = 0;
        while ($i < count($args)) {
            $arg = $args[$i];
            if ($arg === '--help' || $arg === '-h') {
                $opts['help'] = false;
                $i++;
                continue;
            }
            if ($arg === '--dry-run') {
                $opts['dry-run'] = false;
                $i++;
                continue;
            }
            if ($arg === '--verbose' || $arg === '-v') {
                $opts['verbose'] = false;
                $i++;
                continue;
            }
            foreach (['limit', 'wait'] as $key) {
                $prefix = '--' . $key . '=';
                if (str_starts_with($arg, $prefix)) {
                    $opts[$key] = substr($arg, strlen($prefix));
                    $i++;
                    continue 2;
                }
                if ($arg === '--' . $key) {
                    $opts[$key] = $args[$i + 1] ?? '';
                    $i += 2;
                    continue 2;
                }
            }
            $i++;
        }

        return self::fromGetopt($opts);
    }

    public static function helpText(): string
    {
        return <<<TXT
Usage: php bin/ingest-docs.php [options]

Slow-drip ingest of RECAP/document metadata for linked dockets (CL-I2).
Uses courtlistener-sdk DocketEntries + RecapDocuments — not raw HTTP.

Options:
  --limit=N     Max linked dockets to process (default 10)
  --wait=N      Seconds between dockets (default 3)
  --dry-run     Fetch/map only; do not upsert documents
  --verbose
  --help

Env: COURTLISTENER_API_TOKEN, DATABASE_PATH

TXT;
    }

    /**
     * @param array<string, string|false> $opts
     */
    private static function optionalInt(array $opts, string $key): ?int
    {
        if (!array_key_exists($key, $opts) || $opts[$key] === false || $opts[$key] === '') {
            return null;
        }
        if (!is_numeric($opts[$key])) {
            return null;
        }

        return (int) $opts[$key];
    }
}
