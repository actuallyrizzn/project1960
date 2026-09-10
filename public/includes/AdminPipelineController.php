<?php
declare(strict_types=1);

namespace Project1960;

use PDO;

/** Admin Pipeline status panel (AD-P1 read-only). */
final class AdminPipelineController
{
    public function __construct(
        private PDO $pdo,
        private View $view = new View(),
    ) {
    }

    public function pageGet(): Response
    {
        return AdminShell::renderPage($this->view, 'admin/pipeline', [
            'title' => 'Pipeline',
            'currentPath' => '/admin/pipeline',
            'adminUser' => (new AdminAuth($this->pdo))->user(),
            'status' => (new PipelineStatus($this->pdo))->collect(),
        ], $this->pdo);
    }

    public function apiStatus(): Response
    {
        return Response::json(['pipeline' => (new PipelineStatus($this->pdo))->collect()]);
    }
}
