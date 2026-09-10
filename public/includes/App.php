<?php
declare(strict_types=1);

namespace Project1960;

final class App
{
    public function __construct(
        private readonly Router $router = new Router(),
    ) {
        $this->registerRoutes();
    }

    public function handle(Request $request): Response
    {
        return $this->router->dispatch($request);
    }

    private function registerRoutes(): void
    {
        $this->router->get('/', static function (Request $request): Response {
            unset($request);

            return Response::html(
                '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
                . '<title>Project 1960</title></head><body>'
                . '<h1>Project 1960</h1>'
                . '<p>PHP scaffold for project1960.rizzn.net — explorer coming in later slices.</p>'
                . '<p><a href="/health">health</a></p>'
                . '</body></html>'
            );
        });

        $this->router->get('/health', static function (Request $request): Response {
            unset($request);

            return Response::json([
                'ok' => true,
                'service' => 'project1960',
                'host' => 'project1960.rizzn.net',
            ]);
        });
    }
}
