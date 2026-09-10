<?php
declare(strict_types=1);

namespace Project1960;

use PDO;

final class App
{
    private Router $router;
    private View $view;
    private ?PDO $pdo;

    public function __construct(?Router $router = null, ?View $view = null, ?PDO $pdo = null)
    {
        $this->router = $router ?? new Router();
        $this->view = $view ?? new View();
        $this->pdo = $pdo;
        $this->registerRoutes();
    }

    public function handle(Request $request): Response
    {
        return $this->router->dispatch($request);
    }

    private function registerRoutes(): void
    {
        $view = $this->view;
        $pdo = $this->pdo;

        $this->router->get('/', static function (Request $request) use ($view, $pdo): Response {
            $stats = $pdo instanceof PDO
                ? Stats::collect($pdo)
                : [
                    'total_cases' => 0,
                    'mentions_1960' => 0,
                    'mentions_crypto' => 0,
                    'verified_yes' => 0,
                    'verified_no' => 0,
                    'unprocessed_1960' => 0,
                    'enrichment' => array_fill_keys(Stats::ENRICHMENT_TABLES, 0),
                ];

            $html = $view->renderInLayout('pages/home', [
                'title' => 'Dashboard — Project 1960',
                'currentPath' => $request->path,
                'stats' => $stats,
            ]);

            return Response::html($html);
        });

        $this->router->get('/cases', static function (Request $request) use ($view, $pdo): Response {
            if (!$pdo instanceof PDO) {
                return Response::html($view->renderInLayout('pages/shell', [
                    'title' => 'Cases — Project 1960',
                    'currentPath' => $request->path,
                    'heading' => 'Cases',
                    'blurb' => 'Database not configured.',
                ]));
            }

            $filters = CaseListFilters::fromRequest($request);
            $result = (new CaseRepository($pdo))->list($filters);

            return Response::html($view->renderInLayout('pages/cases', [
                'title' => 'Cases — Project 1960',
                'currentPath' => $request->path,
                'cases' => $result['cases'],
                'total' => $result['total'],
                'page' => $result['page'],
                'total_pages' => $result['total_pages'],
                'filters' => $result['filters'],
            ]));
        });

        $this->router->get('/case/{id}', static function (Request $request) use ($view, $pdo): Response {
            if (!$pdo instanceof PDO) {
                return Response::text('Database not configured', 503);
            }
            $id = $request->attr('id');
            $loaded = (new CaseDetailLoader($pdo))->load($id);
            if ($loaded === null) {
                return Response::text('Case not found', 404);
            }

            return Response::html($view->renderInLayout('pages/case_detail', [
                'title' => ($loaded['case']['title'] ?? 'Case') . ' — Project 1960',
                'currentPath' => '/cases',
                'case' => $loaded['case'],
                'enrichment' => $loaded['enrichment'],
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
