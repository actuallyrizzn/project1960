<?php
declare(strict_types=1);

namespace Project1960;

final class Request
{
    /**
     * @param array<string, string> $query
     * @param array<string, string> $attrs
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $attrs = [],
    ) {
    }

    public function query(string $key, string $default = ''): string
    {
        return $this->query[$key] ?? $default;
    }

    public function attr(string $key, string $default = ''): string
    {
        return $this->attrs[$key] ?? $default;
    }

    public function queryInt(string $key, int $default): int
    {
        $raw = $this->query[$key] ?? null;
        if ($raw === null || $raw === '') {
            return $default;
        }
        if (!is_numeric($raw)) {
            return $default;
        }

        return (int) $raw;
    }

    /**
     * @param array<string, mixed> $server
     * @param array<string, mixed>|null $get
     */
    public static function fromGlobals(array $server, ?array $get = null): self
    {
        $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
        $uri = (string) ($server['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = '/';
        }

        $query = [];
        $source = $get ?? [];
        if ($get === null && isset($server['QUERY_STRING']) && is_string($server['QUERY_STRING']) && $server['QUERY_STRING'] !== '') {
            parse_str($server['QUERY_STRING'], $source);
        }
        foreach ($source as $k => $v) {
            if (is_string($k) && (is_string($v) || is_numeric($v))) {
                $query[$k] = (string) $v;
            }
        }

        return new self($method, $path, $query);
    }
}
