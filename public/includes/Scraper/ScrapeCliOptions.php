<?php
declare(strict_types=1);

namespace Project1960\Scraper;

/**
 * Parsed CLI options for bin/scrape.php.
 */
final class ScrapeCliOptions
{
    public function __construct(
        public readonly ?int $maxPages,
        public readonly ?int $limit,
        public readonly ?int $pageStart,
        public readonly int $waitSeconds,
        public readonly bool $dryRun,
        public readonly bool $verbose,
        public readonly bool $help,
    ) {
    }

    /**
     * @param array<string, string|false> $opts from getopt()
     */
    public static function fromGetopt(array $opts): self
    {
        $maxPages = self::optionalInt($opts, 'max-pages');
        $limit = self::optionalInt($opts, 'limit');
        // --limit N is an alias for capping pages when max-pages unset
        if ($maxPages === null && $limit !== null) {
            $maxPages = $limit;
        }

        return new self(
            maxPages: $maxPages,
            limit: $limit,
            pageStart: self::optionalInt($opts, 'page-start'),
            waitSeconds: self::optionalInt($opts, 'wait') ?? 2,
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
        // Drop script name
        $args = array_values(array_slice($argv, 1));
        $longopts = ['max-pages::', 'limit::', 'page-start::', 'wait::', 'dry-run', 'verbose', 'help'];
        // getopt reads $argv globally; for tests we parse manually
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
            foreach (['max-pages', 'limit', 'page-start', 'wait'] as $key) {
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
Usage: php bin/scrape.php [options]

  --max-pages=N     Stop after N pages (optional)
  --limit=N         Alias for --max-pages=N
  --page-start=N    Override scraper_state; fetch starting at page N
  --wait=SECONDS    Polite delay between pages (default 2)
  --dry-run         Fetch and filter counts only; do not INSERT
  --verbose         Extra logging
  --help            Show this help

Cron example (multihost — Ada installs; do not invent host crontab from Otto):
  # every 6 hours, 2 pages max, wait 2s
  15 */6 * * * cd /var/www/project1960.rizzn.net && \\
    DATABASE_PATH=/var/www/project1960.rizzn.net/../db/doj_cases.db \\
    php bin/scrape.php --max-pages=2 --wait=2 >> /var/log/project1960-scrape.log 2>&1

TXT;
    }

    /**
     * @param array<string, string|false> $opts
     */
    private static function optionalInt(array $opts, string $key): ?int
    {
        if (!array_key_exists($key, $opts)) {
            return null;
        }
        $raw = $opts[$key];
        if ($raw === false || $raw === '') {
            return null;
        }
        if (!is_numeric($raw)) {
            return null;
        }

        return (int) $raw;
    }
}
