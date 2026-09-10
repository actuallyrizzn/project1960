<?php
declare(strict_types=1);

namespace Project1960\Scraper;

use RuntimeException;

/**
 * HTTP transport contract for DOJ API fetches (injectable for tests).
 */
interface HttpTransport
{
    /**
     * @param array<string, scalar> $query
     * @return array{status: int, body: string}
     * @throws RuntimeException on transport failure (timeout, connection)
     */
    public function get(string $url, array $query, int $timeoutSeconds): array;
}
