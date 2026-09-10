<?php
declare(strict_types=1);

namespace Project1960\CourtListener;

/**
 * Parse OCR CLI flags (CL-O3).
 */
final class OcrCliOptions
{
    public function __construct(
        public readonly int $limit,
        public readonly int $wait,
        public readonly bool $dryRun,
        public readonly bool $metricsOnly,
        public readonly bool $verbose,
        public readonly bool $help,
    ) {
    }

    /**
     * @param array<string, mixed> $opts from getopt
     */
    public static function fromGetopt(array $opts): self
    {
        $limit = isset($opts['limit']) && is_numeric($opts['limit']) ? max(1, (int) $opts['limit']) : 5;
        $wait = isset($opts['wait']) && is_numeric($opts['wait']) ? max(0, (int) $opts['wait']) : 1;

        return new self(
            limit: $limit,
            wait: $wait,
            dryRun: array_key_exists('dry-run', $opts),
            metricsOnly: array_key_exists('metrics', $opts),
            verbose: array_key_exists('verbose', $opts),
            help: array_key_exists('help', $opts),
        );
    }
}
