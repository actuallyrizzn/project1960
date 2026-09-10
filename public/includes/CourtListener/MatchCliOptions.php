<?php
declare(strict_types=1);

namespace Project1960\CourtListener;

/** Parsed CLI options for bin/match.php (CL-M1/M2). */
final class MatchCliOptions
{
    public function __construct(
        public readonly int $limit,
        public readonly int $waitSeconds,
        public readonly bool $dryRun,
        public readonly bool $verbose,
        public readonly bool $allCases,
        public readonly bool $help,
    ) {
    }

    /**
     * @param array<string, string|false> $opts
     */
    public static function fromGetopt(array $opts): self
    {
        $limit = self::optionalInt($opts, 'limit') ?? 25;
        $wait = self::optionalInt($opts, 'wait') ?? 2;

        return new self(
            limit: max(1, $limit),
            waitSeconds: max(0, $wait),
            dryRun: array_key_exists('dry-run', $opts),
            verbose: array_key_exists('verbose', $opts),
            allCases: array_key_exists('all', $opts),
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
            if ($arg === '--all') {
                $opts['all'] = false;
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
Usage: php bin/match.php [options]

Match verified_1960 seed cases to CourtListener dockets via courtlistener-sdk Search.

Options:
  --limit=N     Max cases to process (default 25)
  --wait=N      Seconds between CL searches (default 2; raise if rate-limited)
  --dry-run     Search + score only; do not write dockets/links/reviews
  --all         Include cases that are not verified_1960
  --verbose     Extra logging
  --help        This help

Env:
  COURTLISTENER_API_TOKEN  (or load ~/.ssh/courtlistener-api.pass)
  DATABASE_PATH            SQLite path (same as site)

Cron example (dry-run first):
  php bin/match.php --limit=10 --wait=3 --dry-run --verbose

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
