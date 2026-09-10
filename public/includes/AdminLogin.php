<?php
declare(strict_types=1);

namespace Project1960;

use PDO;

/**
 * Admin login / logout handlers (AD-A2).
 */
final class AdminLogin
{
    public function __construct(
        private PDO $pdo,
        private View $view = new View(),
    ) {
    }

    public function show(?string $error = null): Response
    {
        $html = $this->view->render('admin/login', ['error' => $error]);

        return Response::html($html);
    }

    /** @param array<string, mixed> $post */
    public function attempt(array $post): Response
    {
        $token = Csrf::tokenFromRequest($post, []);
        if (!Csrf::verify($token)) {
            return $this->show('Invalid security token. Try again.');
        }
        $user = trim((string) ($post['username'] ?? ''));
        $pass = (string) ($post['password'] ?? '');
        $auth = new AdminAuth($this->pdo);
        if (!$auth->login($user, $pass)) {
            return $this->show('Invalid username or password.');
        }

        return new Response('', 302, ['Location' => '/admin']);
    }

    public function logout(): Response
    {
        $auth = new AdminAuth($this->pdo);
        $auth->logout();

        return new Response('', 302, ['Location' => '/admin/login']);
    }
}
