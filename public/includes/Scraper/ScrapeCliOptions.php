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
        public readonly bool $incremental,
        public readonly bool $legacyPages,
        public readonly ?int $since,
    ) {
    }

    /**
     * @param array<string, string|false> $opts from getopt()
     */
    public static function fromGetopt(array $opts): self
    {
        $maxPages = self::optionalInt($opts, 'max-pages');
        $limit = self::optionalInt($opts, 'limit');
        if ($maxPages === null && $limit !== null) {
            $maxPages = $limit;
        }
        $legacyPages = array_key_exists('legacy-pages', $opts)
            || self::optionalInt($opts, 'page-start') !== null;
        $incremental = !$legacyPages;
        if (array_key_exists('incremental', $opts)) {
            $incremental = true;
            $legacyPages = false;
        }

        return new self(
            maxPages: $maxPages,
            limit: $limit,
            pageStart: self::optionalInt($opts, 'page-start'),
            waitSeconds: self::optionalInt($opts, 'wait') ?? 2,
            dryRun: array_key_exists('dry-run', $opts),
            verbose: array_key_exists('verbose', $opts),
            help: array_key_exists('help', $opts),
            incremental: $incremental,
            legacyPages: $legacyPages,
            since: self::optionalInt($opts, 'since'),
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
            if ($arg === '--incremental') {
                $opts['incremental'] = false;
                $i++;
                continue;
            }
            if ($arg === '--legacy-pages') {
                $opts['legacy-pages'] = false;
                $i++;
                continue;
            }
            foreach (['max-pages', 'limit', 'page-start', 'wait', 'since'] as $key) {
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

Default mode is **incremental** (newest-first, stop at MAX(cases.date) watermark).

  --incremental     Newest-first date watermark poll (default)
  --since=UNIX      Override watermark (incremental mode)
  --legacy-pages    Old oldest-first page cursor (scraper_state.last_page)
  --max-pages=N     Stop after N pages (optional; incremental default cap 50)
  --limit=N         Alias for --max-pages=N
  --page-start=N    Legacy mode: override scraper_state; start at page N
  --wait=SECONDS    Polite delay between pages (default 2)
  --dry-run         Fetch/filter only; do not INSERT
  --verbose         Extra logging
  --help            Show this help

Cron example (multihost — Ada installs):
  17 3,15 * * * DATABASE_PATH=/var/www/project1960.rizzn.net/db/doj_cases.db \\
    php /root/repos/project1960.rizzn.net/bin/scrape.php --max-pages=5 --wait=2 \\
    >> /var/log/project1960-scrape.log 2>&1

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
