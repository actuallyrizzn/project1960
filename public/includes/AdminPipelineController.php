<?php
declare(strict_types=1);

namespace Project1960;

use InvalidArgumentException;
use PDO;

/** Admin Pipeline status + safe controls (AD-P1/P2). */
final class AdminPipelineController
{
    public function __construct(
        private PDO $pdo,
        private View $view = new View(),
    ) {
    }

    public function pageGet(?string $flash = null, ?string $error = null, ?array $result = null): Response
    {
        $controls = new PipelineControls($this->pdo);

        return AdminShell::renderPage($this->view, 'admin/pipeline', [
            'title' => 'Pipeline',
            'currentPath' => '/admin/pipeline',
            'adminUser' => (new AdminAuth($this->pdo))->user(),
            'status' => (new PipelineStatus($this->pdo))->collect(),
            'actions' => PipelineControls::ACTIONS,
            'burnAllowed' => PipelineControls::burnAllowed(),
            'lastDryRun' => $controls->lastDryRun(),
            'lastEnqueue' => $controls->lastEnqueue(),
            'result' => $result,
            'flash' => $flash,
            'error' => $error,
        ], $this->pdo);
    }

    /** @param array<string, mixed> $post */
    public function pagePost(array $post): Response
    {
        if (!Csrf::verify(Csrf::tokenFromRequest($post, []))) {
            return $this->pageGet(null, 'Invalid security token.');
        }
        $controls = new PipelineControls($this->pdo);
        try {
            $mode = (string) ($post['mode'] ?? '');
            $action = (string) ($post['action'] ?? '');
            $limit = (int) ($post['limit'] ?? 10);
            if ($mode === 'dry_run') {
                $result = $controls->dryRun($action, $limit);

                return $this->pageGet('Dry-run recorded.', null, $result);
            }
            if ($mode === 'enqueue') {
                $confirmBurn = ((string) ($post['confirm_burn'] ?? '')) === '1';
                $result = $controls->enqueue($action, $limit, $confirmBurn);

                return $this->pageGet('Enqueue dry intent recorded.', null, $result);
            }

            return $this->pageGet(null, 'Unknown mode.');
        } catch (InvalidArgumentException $e) {
            return $this->pageGet(null, $e->getMessage());
        }
    }

    public function apiStatus(): Response
    {
        return Response::json(['pipeline' => (new PipelineStatus($this->pdo))->collect()]);
    }

    /** @param array<string, mixed> $body */
    public function apiEnqueue(array $body): Response
    {
        try {
            $controls = new PipelineControls($this->pdo);
            $result = $controls->enqueue(
                (string) ($body['action'] ?? ''),
                (int) ($body['limit'] ?? 10),
                ((string) ($body['confirm_burn'] ?? '')) === '1'
            );

            return Response::json(['result' => $result], 202);
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        }
    }
}
