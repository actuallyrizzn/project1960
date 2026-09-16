<?php
declare(strict_types=1);

namespace Project1960\CourtListener;

use Project1960\CourtListener\ClientFactory;
use RuntimeException;

/**
 * Read CourtListener api-usage (own throttle) so drips stop before burning 429s.
 *
 * Default free authenticated caps (FLP docs): 5/min, 50/hour, 125/day.
 */
final class ApiUsageGate
{
    /** @param array<string, mixed>|null $usage */
    public function __construct(private ?array $usage = null)
    {
    }

    public static function fromClient(): self
    {
        $client = ClientFactory::make();
        // SDK may not wrap api-usage — raw GET via makeRequest if available
        if (method_exists($client, 'makeRequest')) {
            /** @var array<string, mixed> $data */
            $data = $client->makeRequest('GET', 'api-usage/', []);
            return new self(is_array($data) ? $data : null);
        }

        return new self(null);
    }

    /**
     * Fetch via curl-ish fopen so we work even when SDK has no helper.
     *
     * @return self
     */
    public static function fetch(string $token): self
    {
        $token = trim($token);
        if ($token === '') {
            throw new RuntimeException('COURTLISTENER_API_TOKEN missing');
        }
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "Authorization: Token {$token}\r\nUser-Agent: project1960-usage-gate\r\nAccept: application/json\r\n",
                'timeout' => 30,
                'ignore_errors' => true,
            ],
        ]);
        $raw = @file_get_contents('https://www.courtlistener.com/api/rest/v4/api-usage/', false, $ctx);
        if ($raw === false) {
            return new self(null);
        }
        $data = json_decode($raw, true);

        return new self(is_array($data) ? $data : null);
    }

    /** Lowest remaining among user/api_usage scopes; null if unknown. */
    public function minRemaining(): ?int
    {
        $rows = $this->usage['current_usage'] ?? null;
        if (!is_array($rows) || $rows === []) {
            return null;
        }
        $min = null;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $scope = (string) ($row['scope'] ?? '');
            if (!in_array($scope, ['user', 'api_usage'], true)) {
                continue;
            }
            if (!isset($row['remaining'])) {
                continue;
            }
            $rem = (int) $row['remaining'];
            $min = $min === null ? $rem : min($min, $rem);
        }

        return $min;
    }

    public function dayRemaining(): ?int
    {
        foreach ($this->usage['current_usage'] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (($row['scope'] ?? '') === 'user' && ($row['rate'] ?? '') === '125/day') {
                return (int) ($row['remaining'] ?? 0);
            }
            // Some accounts may report different day rates — take any */day under user
            if (($row['scope'] ?? '') === 'user' && is_string($row['rate'] ?? null) && str_ends_with((string) $row['rate'], '/day')) {
                return (int) ($row['remaining'] ?? 0);
            }
        }

        return null;
    }

    public function dayResetAt(): ?string
    {
        foreach ($this->usage['current_usage'] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (($row['scope'] ?? '') === 'user' && is_string($row['rate'] ?? null) && str_ends_with((string) $row['rate'], '/day')) {
                $r = $row['reset_at'] ?? null;

                return is_string($r) ? $r : null;
            }
        }

        return null;
    }

    /** True when we should not start another search/ingest batch. */
    public function shouldSkip(int $needAtLeast = 2): bool
    {
        $day = $this->dayRemaining();
        if ($day !== null && $day < $needAtLeast) {
            return true;
        }
        $min = $this->minRemaining();
        if ($min !== null && $min < $needAtLeast) {
            return true;
        }

        return false;
    }

    public function summary(): string
    {
        $day = $this->dayRemaining();
        $reset = $this->dayResetAt() ?? '?';
        $min = $this->minRemaining();

        return sprintf(
            'CL quota day_remaining=%s min_remaining=%s reset_at=%s',
            $day === null ? '?' : (string) $day,
            $min === null ? '?' : (string) $min,
            $reset
        );
    }
}
