<?php
declare(strict_types=1);

namespace Project1960\CourtListener;

/**
 * Thin port over courtlistener-sdk search — injectable for tests.
 * Implementations must call CourtListenerClient->search (not raw curl).
 */
interface SearchGateway
{
    /**
     * @param array<string, mixed> $params e.g. q, type=d|r, page_size
     * @return array{results?: list<array<string, mixed>>, count?: int}
     */
    public function search(array $params): array;
}
