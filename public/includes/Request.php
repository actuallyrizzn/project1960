<?php
declare(strict_types=1);

namespace Project1960;

final class Request
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
    ) {
    }

    /** @param array<string, mixed> $server */
    public static function fromGlobals(array $server): self
    {
        $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
        $uri = (string) ($server['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = '/';
        }

        return new self($method, $path);
    }
}
