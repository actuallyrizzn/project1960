<?php
declare(strict_types=1);

namespace Project1960\CourtListener;

/** Thin port over courtlistener-sdk RecapFetch (CL-I4). */
interface RecapFetchGateway
{
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function requestFetch(array $payload): array;
}
