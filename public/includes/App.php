<?php
declare(strict_types=1);

namespace Project1960;

final class App
{
    private Router $router;
    private View $view;

    public function __construct(?Router $router = null, ?View $view = null)
    {
        $this->router = $router ?? new Router();
        $this->view = $view ?? new View();
        $this->registerRoutes();
    }

    public function handle(Request $request): Response
    {
        return $this->router->dispatch($request);
    }

    private function registerRoutes(): void
    {
        $view = $this->view;

        $this->router->get('/', static function (Request $request) use ($view): Response {
            $html = $view->renderInLayout('pages/home', [
                'title' => 'Dashboard — Project 1960',
                'currentPath' => $request->path,
            ]);

            return Response::html($html);
        });

        $this->router->get('/cases', static function (Request $request) use ($view): Response {
            return Response::html($view->renderInLayout('pages/shell', [
                'title' => 'Cases — Project 1960',
                'currentPath' => $request->path,
                'heading' => 'Cases',
                'blurb' => 'Case list arrives in a later slice.',
            ]));
        });

        $this->router->get('/enrichment', static function (Request $request) use ($view): Response {
            return Response::html($view->renderInLayout('pages/shell', [
                'title' => 'Enrichment — Project 1960',
                'currentPath' => $request->path,
                'heading' => 'Enrichment',
                'blurb' => 'Enrichment dashboard arrives in a later slice.',
            ]));
        });

        $this->router->get('/about', static function (Request $request) use ($view): Response {
            return Response::html($view->renderInLayout('pages/shell', [
                'title' => 'About — Project 1960',
                'currentPath' => $request->path,
                'heading' => 'About',
                'blurb' => 'About page content arrives in a later slice.',
            ]));
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
