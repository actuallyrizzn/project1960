<?php
declare(strict_types=1);

namespace Project1960;

use InvalidArgumentException;
use PDO;

/** Admin Help pages (AD-A6). */
final class AdminHelpController
{
    public function __construct(
        private PDO $pdo,
        private View $view = new View(),
    ) {
    }

    public function index(): Response
    {
        return AdminShell::renderPage($this->view, 'admin/help', [
            'title' => 'Help',
            'currentPath' => '/admin/help',
            'adminUser' => (new AdminAuth($this->pdo))->user(),
            'topics' => AdminHelp::topics(),
        ], $this->pdo);
    }

    public function show(string $slug): Response
    {
        try {
            $topic = AdminHelp::topic($slug);
        } catch (InvalidArgumentException) {
            return new Response('', 302, ['Location' => '/admin/help']);
        }

        return AdminShell::renderPage($this->view, 'admin/help_topic', [
            'title' => $topic['title'],
            'currentPath' => '/admin/help/' . $topic['slug'],
            'adminUser' => (new AdminAuth($this->pdo))->user(),
            'topic' => $topic,
        ], $this->pdo);
    }
}
