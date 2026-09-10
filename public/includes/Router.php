<?php
declare(strict_types=1);

namespace Project1960;

final class Router
{
    /** @var list<array{method: string, pattern: string, regex: string, keys: list<string>, handler: callable}> */
    private array $routes = [];

    public function get(string $path, callable $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, callable $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    public function add(string $method, string $path, callable $handler): void
    {
        $normalized = $this->normalize($path);
        $keys = [];
        $regex = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            static function (array $m) use (&$keys): string {
                $keys[] = $m[1];

                return '([^/]+)';
            },
            $normalized
        );
        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $normalized,
            'regex' => '#^' . $regex . '$#',
            'keys' => $keys,
            'handler' => $handler,
        ];
    }

    public function dispatch(Request $request): Response
    {
        $path = $this->normalize($request->path);
        foreach ($this->routes as $route) {
            if ($route['method'] !== $request->method) {
                continue;
            }
            if (!preg_match($route['regex'], $path, $matches)) {
                continue;
            }
            $attrs = [];
            foreach ($route['keys'] as $i => $key) {
                $attrs[$key] = rawurldecode((string) ($matches[$i + 1] ?? ''));
            }
            $matched = new Request(
                $request->method,
                $request->path,
                $request->query,
                $attrs,
                $request->server,
                $request->post
            );

            return ($route['handler'])($matched);
        }

        return Response::text('Not Found', 404);
    }

    private function normalize(string $path): string
    {
        $path = parse_url($path, PHP_URL_PATH) ?: '/';
        if ($path === '') {
            return '/';
        }
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        return $path;
    }
}
