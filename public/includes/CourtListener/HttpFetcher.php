<?php
declare(strict_types=1);

namespace Project1960\CourtListener;

/** Fetch bytes for free CL/RECAP URLs (injectable). */
interface HttpFetcher
{
    /**
     * @return array{ok: bool, status: int, body: string, error?: string}
     */
    public function get(string $url): array;
}
