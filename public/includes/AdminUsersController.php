<?php
declare(strict_types=1);

namespace Project1960;

use InvalidArgumentException;
use PDO;

/**
 * Admin users UI + JSON API handlers (AD-A3).
 */
final class AdminUsersController
{
    public function __construct(
        private PDO $pdo,
        private View $view = new View(),
    ) {
    }

    public function pageGet(): Response
    {
        return AdminShell::renderPage($this->view, 'admin/users', [
            'title' => 'Users',
            'currentPath' => '/admin/users',
            'adminUser' => (new AdminAuth($this->pdo))->user(),
            'users' => (new AdminUsers($this->pdo))->list(),
        ]);
    }

    /** @param array<string, mixed> $post */
    public function pagePost(array $post): Response
    {
        if (!Csrf::verify(Csrf::tokenFromRequest($post, []))) {
            return AdminShell::renderPage($this->view, 'admin/users', [
                'title' => 'Users',
                'currentPath' => '/admin/users',
                'adminUser' => (new AdminAuth($this->pdo))->user(),
                'users' => (new AdminUsers($this->pdo))->list(),
                'error' => 'Invalid security token.',
            ]);
        }
        $users = new AdminUsers($this->pdo);
        $flash = null;
        $error = null;
        try {
            $action = (string) ($post['action'] ?? '');
            if ($action === 'create') {
                $users->create([
                    'username' => (string) ($post['username'] ?? ''),
                    'email' => (string) ($post['email'] ?? ''),
                    'password' => (string) ($post['password'] ?? ''),
                    'role' => (string) ($post['role'] ?? 'operator'),
                ]);
                $flash = 'User created.';
            } elseif ($action === 'toggle') {
                $users->setActive((int) ($post['user_id'] ?? 0), ((string) ($post['is_active'] ?? '0')) === '1');
                $flash = 'User updated.';
            } elseif ($action === 'reset_password') {
                $users->resetPassword((int) ($post['user_id'] ?? 0), (string) ($post['password'] ?? ''));
                $flash = 'Password reset.';
            } else {
                $error = 'Unknown action.';
            }
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
        }

        return AdminShell::renderPage($this->view, 'admin/users', [
            'title' => 'Users',
            'currentPath' => '/admin/users',
            'adminUser' => (new AdminAuth($this->pdo))->user(),
            'users' => $users->list(),
            'flash' => $flash,
            'error' => $error,
        ]);
    }

    public function apiList(): Response
    {
        return Response::json(['users' => (new AdminUsers($this->pdo))->list()]);
    }

    /** @param array<string, mixed> $body */
    public function apiCreate(array $body): Response
    {
        try {
            $user = (new AdminUsers($this->pdo))->create([
                'username' => (string) ($body['username'] ?? ''),
                'email' => (string) ($body['email'] ?? ''),
                'password' => (string) ($body['password'] ?? ''),
                'role' => (string) ($body['role'] ?? 'operator'),
            ]);

            return Response::json(['user' => $user], 201);
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        }
    }
}
