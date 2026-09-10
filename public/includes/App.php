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
                'courtlistener' => $loaded['courtlistener'] ?? [
                    'dockets' => [],
                    'documents' => [],
                    'people' => [],
                    'charges' => [],
                    'outcomes' => [],
                ],
            ]));
        });

        $this->router->get('/patterns', static function (Request $request) use ($view, $pdo): Response {
            $q = trim($request->query('q'));
            $results = [];
            $multi = [];
            if ($pdo instanceof PDO) {
                $pq = new \Project1960\CourtListener\PatternQueries($pdo);
                if ($q !== '') {
                    $results = $pq->casesForPersonName($q);
                }
                $multi = $pq->multiCasePersons(2, 50);
            }

            return Response::html($view->renderInLayout('pages/patterns', [
                'title' => 'Patterns — Project 1960',
                'currentPath' => $request->path,
                'q' => $q,
                'results' => $results,
                'multi' => $multi,
            ]));
        });

        $this->router->get('/enrichment', static function (Request $request) use ($view, $pdo): Response {
            if ($pdo instanceof PDO) {
                $dash = (new EnrichmentDashboard($pdo))->collect();
            } else {
                $dash = [
                    'stats' => [
                        'total_cases' => 0,
                        'mentions_1960' => 0,
                        'mentions_crypto' => 0,
                        'verified_yes' => 0,
                        'verified_no' => 0,
                        'unprocessed_1960' => 0,
                        'enrichment' => [],
                    ],
                    'activity_log' => [],
                    'cards' => array_map(
                        static fn (array $card): array => $card + ['count' => 0, 'percent' => 0.0],
                        EnrichmentDashboard::TABLE_CARDS
                    ),
                ];
            }

            return Response::html($view->renderInLayout('pages/enrichment', [
                'title' => 'Enrichment — Project 1960',
                'currentPath' => $request->path,
                'stats' => $dash['stats'],
                'activity_log' => $dash['activity_log'],
                'cards' => $dash['cards'],
            ]));
        });

        $this->router->get('/about', static function (Request $request) use ($view, $pdo): Response {
            $aboutStats = [
                'total_cases' => 0,
                'cases_1960' => 0,
                'cases_crypto' => 0,
            ];
            if ($pdo instanceof PDO) {
                $aboutStats['total_cases'] = (int) $pdo->query('SELECT COUNT(*) FROM cases')->fetchColumn();
                $aboutStats['cases_1960'] = (int) $pdo->query(
                    'SELECT COUNT(*) FROM cases WHERE mentions_1960 = 1'
                )->fetchColumn();
                $aboutStats['cases_crypto'] = (int) $pdo->query(
                    'SELECT COUNT(*) FROM cases WHERE mentions_crypto = 1'
                )->fetchColumn();
            }

            return Response::html($view->renderInLayout('pages/about', [
                'title' => 'About — Project 1960',
                'currentPath' => $request->path,
                'stats' => $aboutStats,
            ]));
        });

        $api = new Api($pdo);
        $gate = $pdo instanceof PDO ? ApiGate::fromEnv($pdo) : null;
        $this->router->get('/api/stats', static function (Request $request) use ($api, $gate): Response {
            if ($gate instanceof ApiGate) {
                $deny = $gate->authorizePublic($request->path, $request->server);
                if ($deny instanceof Response) {
                    return $deny;
                }
            }

            return $api->stats();
        });
        $this->router->get('/api/cases', static function (Request $request) use ($api, $gate): Response {
            if ($gate instanceof ApiGate) {
                $deny = $gate->authorizePublic($request->path, $request->server);
                if ($deny instanceof Response) {
                    return $deny;
                }
            }

            return $api->cases();
        });
        $this->router->get('/api/enrichment/{id}', static function (Request $request) use ($api, $gate): Response {
            if ($gate instanceof ApiGate) {
                $deny = $gate->authorizePublic($request->path, $request->server);
                if ($deny instanceof Response) {
                    return $deny;
                }
            }

            return $api->enrichment($request->attr('id'));
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
