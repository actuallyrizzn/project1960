<?php
declare(strict_types=1);

namespace Project1960;

use InvalidArgumentException;
use JsonException;
use PDO;

/**
 * Safe pipeline controls — dry-run / enqueue-only (AD-P2).
 * Live burn requires P1960_PIPELINE_ALLOW_BURN=1 AND Mark go (never unlimited from UI).
 */
final class PipelineControls
{
    public const KEY_LAST_DRY_RUN = 'pipeline.last_dry_run_json';
    public const KEY_LAST_ENQUEUE = 'pipeline.last_enqueue_json';

    /** @var list<string> */
    public const ACTIONS = ['scrape', 'match', 'ingest', 'ocr', 'extract'];

    public function __construct(private PDO $pdo)
    {
    }

    public static function burnAllowed(array $env = []): bool
    {
        $raw = $env['P1960_PIPELINE_ALLOW_BURN'] ?? getenv('P1960_PIPELINE_ALLOW_BURN') ?: '';

        return is_string($raw) && in_array(strtolower($raw), ['1', 'true', 'yes'], true);
    }

    /**
     * @return array{mode: string, action: string, limit: int, message: string, recorded_at: string}
     */
    public function dryRun(string $action, int $limit = 10): array
    {
        $action = $this->normalizeAction($action);
        $limit = max(1, min(100, $limit));
        $payload = [
            'mode' => 'dry_run',
            'action' => $action,
            'limit' => $limit,
            'message' => sprintf('Would run %s with limit=%d (no CLI invoked).', $action, $limit),
            'recorded_at' => gmdate('c'),
        ];
        (new SiteSettings($this->pdo))->set(
            self::KEY_LAST_DRY_RUN,
            json_encode($payload, JSON_THROW_ON_ERROR)
        );

        return $payload;
    }

    /**
     * @return array{mode: string, action: string, limit: int, message: string, recorded_at: string}
     */
    public function enqueue(string $action, int $limit = 10, bool $confirmBurn = false): array
    {
        $action = $this->normalizeAction($action);
        $limit = max(1, min(50, $limit));
        if ($confirmBurn) {
            if (!self::burnAllowed()) {
                throw new InvalidArgumentException(
                    'Live burn blocked. Set P1960_PIPELINE_ALLOW_BURN=1 only with Mark go.'
                );
            }
            throw new InvalidArgumentException(
                'Live burn is not wired from the admin UI. Use host CLIs after Mark go.'
            );
        }
        $payload = [
            'mode' => 'enqueue_dry',
            'action' => $action,
            'limit' => $limit,
            'message' => sprintf('Queued dry intent for %s limit=%d (no worker burn).', $action, $limit),
            'recorded_at' => gmdate('c'),
        ];
        (new SiteSettings($this->pdo))->set(
            self::KEY_LAST_ENQUEUE,
            json_encode($payload, JSON_THROW_ON_ERROR)
        );

        return $payload;
    }

    /** @return array<string, mixed>|null */
    public function lastDryRun(): ?array
    {
        return $this->decode((new SiteSettings($this->pdo))->get(self::KEY_LAST_DRY_RUN));
    }

    /** @return array<string, mixed>|null */
    public function lastEnqueue(): ?array
    {
        return $this->decode((new SiteSettings($this->pdo))->get(self::KEY_LAST_ENQUEUE));
    }

    private function normalizeAction(string $action): string
    {
        $action = strtolower(trim($action));
        if (!in_array($action, self::ACTIONS, true)) {
            throw new InvalidArgumentException('Unknown pipeline action');
        }

        return $action;
    }

    /** @return array<string, mixed>|null */
    private function decode(?string $raw): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
