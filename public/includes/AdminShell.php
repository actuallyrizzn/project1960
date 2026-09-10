<?php
declare(strict_types=1);

namespace Project1960;

/**
 * Admin chrome — nav from AD-R3 / Doc #1315 (AD-A1).
 */
final class AdminShell
{
    /** @return list<array{key: string, label: string, href: string, icon: string}> */
    public static function navItems(): array
    {
        return [
            ['key' => 'home', 'label' => 'Home', 'href' => '/admin', 'icon' => 'bi-house-door'],
            ['key' => 'users', 'label' => 'Users', 'href' => '/admin/users', 'icon' => 'bi-people'],
            ['key' => 'api-keys', 'label' => 'API Keys', 'href' => '/admin/api-keys', 'icon' => 'bi-key'],
            ['key' => 'pipeline', 'label' => 'Pipeline', 'href' => '/admin/pipeline', 'icon' => 'bi-diagram-3'],
            ['key' => 'appearance', 'label' => 'Appearance', 'href' => '/admin/appearance', 'icon' => 'bi-palette'],
            ['key' => 'help', 'label' => 'Help', 'href' => '/admin/help', 'icon' => 'bi-question-circle'],
        ];
    }

    public static function isActive(string $href, string $currentPath): bool
    {
        $path = rtrim($currentPath, '/') ?: '/';
        $href = rtrim($href, '/') ?: '/';
        if ($href === '/admin') {
            return $path === '/admin';
        }

        return $path === $href || str_starts_with($path, $href . '/');
    }

    /** Auth gate stub: redirect to login when not authenticated. */
    public static function requireAuth(AdminAuth $auth): ?Response
    {
        if ($auth->check()) {
            return null;
        }

        return new Response('', 302, ['Location' => '/admin/login']);
    }

    /** @param array<string, mixed> $data */
    public static function renderPage(View $view, string $pageTemplate, array $data): Response
    {
        $data['adminNav'] = self::navItems();
        $data['content'] = $view->render($pageTemplate, $data);

        return Response::html($view->render('admin/layout', $data));
    }
}
