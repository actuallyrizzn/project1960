<?php
declare(strict_types=1);

namespace Project1960\Scraper;

use JsonException;
use RuntimeException;

/**
 * DOJ press_releases.json page fetch with timeout/retry (legacy scraper.py parity).
 */
final class DojClient
{
    public const DEFAULT_API_URL = 'https://www.justice.gov/api/v1/press_releases.json';
    public const DEFAULT_PAGESIZE = 50;

    /** @param (callable(int): void)|null $sleeper */
    public function __construct(
        private readonly HttpTransport $transport,
        private readonly string $apiUrl = self::DEFAULT_API_URL,
        private readonly int $timeoutSeconds = 30,
        private readonly int $maxRetries = 3,
        private readonly int $retrySleepSeconds = 5,
        private $sleeper = null,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchPage(int $page, int $pagesize = self::DEFAULT_PAGESIZE): array
    {
        $page = max(0, $page);
        $pagesize = max(1, min(100, $pagesize));
        $attempt = 0;
        $lastError = null;

        while ($attempt < $this->maxRetries) {
            $attempt++;
            try {
                $response = $this->transport->get(
                    $this->apiUrl,
                    ['pagesize' => $pagesize, 'page' => $page],
                    $this->timeoutSeconds
                );
                if ($response['status'] !== 200) {
                    throw new RuntimeException(
                        'DOJ API HTTP ' . $response['status'] . ': ' . substr($response['body'], 0, 200)
                    );
                }

                try {
                    /** @var array<string, mixed> $data */
                    $data = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException $e) {
                    throw new RuntimeException('DOJ API returned invalid JSON: ' . $e->getMessage(), 0, $e);
                }

                $results = $data['results'] ?? [];
                if (!is_array($results)) {
                    return [];
                }
                /** @var list<array<string, mixed>> $out */
                $out = [];
                foreach ($results as $row) {
                    if (is_array($row)) {
                        $out[] = $row;
                    }
                }

                return $out;
            } catch (RuntimeException $e) {
                $lastError = $e;
                if ($attempt < $this->maxRetries) {
                    $this->sleep($this->retrySleepSeconds);
                }
            }
        }

        throw new RuntimeException(
            'Failed to fetch DOJ page ' . $page . ' after ' . $this->maxRetries . ' attempts',
            0,
            $lastError
        );
    }

    private function sleep(int $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }
        $fn = $this->sleeper ?? static function (int $s): void {
            sleep($s);
        };
        $fn($seconds);
    }
}
