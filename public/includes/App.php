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

        // AD-A1/A2 — admin shell + login/logout
        if ($pdo instanceof PDO) {
            $this->router->get('/admin', static function (Request $request) use ($view, $pdo): Response {
                $auth = new AdminAuth($pdo);
                $deny = AdminShell::requireAuth($auth);
                if ($deny instanceof Response) {
                    return $deny;
                }
                unset($request, $view);

                return (new AdminHomeController($pdo))->pageGet();
            });
            $login = new AdminLogin($pdo, $view);
            $this->router->get('/admin/login', static function (Request $request) use ($login, $pdo): Response {
                $auth = new AdminAuth($pdo);
                if ($auth->check()) {
                    return new Response('', 302, ['Location' => '/admin']);
                }
                unset($request);

                return $login->show();
            });
            $this->router->post('/admin/login', static function (Request $request) use ($login): Response {
                return $login->attempt($request->post);
            });
            $this->router->get('/admin/logout', static function (Request $request) use ($login): Response {
                unset($request);

                return $login->logout();
            });
            $this->router->post('/admin/logout', static function (Request $request) use ($login): Response {
                unset($request);

                return $login->logout();
            });

            $usersCtl = new AdminUsersController($pdo, $view);
            $this->router->get('/admin/users', static function (Request $request) use ($usersCtl, $pdo): Response {
                $auth = new AdminAuth($pdo);
                $deny = AdminShell::requireAuth($auth);
                if ($deny instanceof Response) {
                    return $deny;
                }
                unset($request);

                return $usersCtl->pageGet();
            });
            $this->router->post('/admin/users', static function (Request $request) use ($usersCtl, $pdo): Response {
                $auth = new AdminAuth($pdo);
                $deny = AdminShell::requireAuth($auth);
                if ($deny instanceof Response) {
                    return $deny;
                }

                return $usersCtl->pagePost($request->post);
            });

            $appearanceCtl = new AdminAppearanceController($pdo, $view);
            $this->router->get('/admin/appearance', static function (Request $request) use ($appearanceCtl, $pdo): Response {
                $auth = new AdminAuth($pdo);
                $deny = AdminShell::requireAuth($auth);
                if ($deny instanceof Response) {
                    return $deny;
                }
                unset($request);

                return $appearanceCtl->pageGet();
            });
            $this->router->post('/admin/appearance', static function (Request $request) use ($appearanceCtl, $pdo): Response {
                $auth = new AdminAuth($pdo);
                $deny = AdminShell::requireAuth($auth);
                if ($deny instanceof Response) {
                    return $deny;
                }

                return $appearanceCtl->pagePost($request->post);
            });

            $helpCtl = new AdminHelpController($pdo, $view);
            $this->router->get('/admin/help', static function (Request $request) use ($helpCtl, $pdo): Response {
                $auth = new AdminAuth($pdo);
                $deny = AdminShell::requireAuth($auth);
                if ($deny instanceof Response) {
                    return $deny;
                }
                unset($request);

                return $helpCtl->index();
            });
            $this->router->get('/admin/help/{slug}', static function (Request $request) use ($helpCtl, $pdo): Response {
                $auth = new AdminAuth($pdo);
                $deny = AdminShell::requireAuth($auth);
                if ($deny instanceof Response) {
                    return $deny;
                }

                return $helpCtl->show($request->attr('slug'));
            });

            $pipelineCtl = new AdminPipelineController($pdo, $view);
            $this->router->get('/admin/pipeline', static function (Request $request) use ($pipelineCtl, $pdo): Response {
                $auth = new AdminAuth($pdo);
                $deny = AdminShell::requireAuth($auth);
                if ($deny instanceof Response) {
                    return $deny;
                }
                unset($request);

                return $pipelineCtl->pageGet();
            });

            $keysCtl = new AdminApiKeysController($pdo, $view);
            $this->router->get('/admin/api-keys', static function (Request $request) use ($keysCtl, $pdo): Response {
                $auth = new AdminAuth($pdo);
                $deny = AdminShell::requireAuth($auth);
                if ($deny instanceof Response) {
                    return $deny;
                }
                unset($request);

                return $keysCtl->pageGet();
            });
            $this->router->post('/admin/api-keys', static function (Request $request) use ($keysCtl, $pdo): Response {
                $auth = new AdminAuth($pdo);
                $deny = AdminShell::requireAuth($auth);
                if ($deny instanceof Response) {
                    return $deny;
                }

                return $keysCtl->pagePost($request->post);
            });

            $gateAdmin = ApiGate::fromEnv($pdo);
            $this->router->get('/api/admin/users', static function (Request $request) use ($usersCtl, $gateAdmin): Response {
                $deny = $gateAdmin->authorizeAdmin(ApiKeys::SCOPE_ADMIN_USERS, $request->server);
                if ($deny instanceof Response) {
                    return $deny;
                }

                return $usersCtl->apiList();
            });
            $this->router->post('/api/admin/users', static function (Request $request) use ($usersCtl, $gateAdmin): Response {
                $deny = $gateAdmin->authorizeAdmin(ApiKeys::SCOPE_ADMIN_USERS, $request->server);
                if ($deny instanceof Response) {
                    return $deny;
                }

                return $usersCtl->apiCreate($request->post);
            });
            $this->router->get('/api/admin/pipeline', static function (Request $request) use ($pipelineCtl, $gateAdmin): Response {
                $deny = $gateAdmin->authorizeAdmin(ApiKeys::SCOPE_PIPELINE_STATUS, $request->server);
                if ($deny instanceof Response) {
                    return $deny;
                }

                return $pipelineCtl->apiStatus();
            });
            $this->router->get('/api/admin/keys', static function (Request $request) use ($keysCtl, $gateAdmin): Response {
                $deny = $gateAdmin->authorizeAdmin(ApiKeys::SCOPE_ADMIN_KEYS, $request->server);
                if ($deny instanceof Response) {
                    return $deny;
                }

                return $keysCtl->apiList();
            });
            $this->router->post('/api/admin/keys', static function (Request $request) use ($keysCtl, $gateAdmin, $pdo): Response {
                $deny = $gateAdmin->authorizeAdmin(ApiKeys::SCOPE_ADMIN_KEYS, $request->server);
                if ($deny instanceof Response) {
                    return $deny;
                }
                $row = (new ApiKeys($pdo))->authenticateFromHeaders($request->server);
                $actorId = is_array($row) ? (int) $row['user_id'] : 0;

                return $keysCtl->apiMint($request->post, $actorId);
            });
            $this->router->post('/api/admin/keys/revoke', static function (Request $request) use ($keysCtl, $gateAdmin): Response {
                $deny = $gateAdmin->authorizeAdmin(ApiKeys::SCOPE_ADMIN_KEYS, $request->server);
                if ($deny instanceof Response) {
                    return $deny;
                }

                return $keysCtl->apiRevoke((int) ($request->post['key_id'] ?? 0));
            });
        }
    }
}
