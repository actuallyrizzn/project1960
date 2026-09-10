<?php
declare(strict_types=1);

namespace Project1960;

final class Router
{
    /** @var array<string, callable(Request): Response> */
    private array $routes = [];

    public function get(string $path, callable $handler): void
    {
        $this->routes['GET ' . $this->normalize($path)] = $handler;
    }

    public function dispatch(Request $request): Response
    {
        $key = $request->method . ' ' . $this->normalize($request->path);
        if (!isset($this->routes[$key])) {
            return Response::text('Not Found', 404);
        }

        return ($this->routes[$key])($request);
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
