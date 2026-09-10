<?php
declare(strict_types=1);

namespace Project1960;

use RuntimeException;

final class View
{
    private readonly string $viewsRoot;

    public function __construct(?string $viewsRoot = null)
    {
        $this->viewsRoot = $viewsRoot ?? (dirname(__DIR__) . '/views');
    }

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = []): string
    {
        $path = $this->viewsRoot . '/' . ltrim($template, '/');
        if (!str_ends_with($path, '.php')) {
            $path .= '.php';
        }
        if (!is_file($path)) {
            throw new RuntimeException('View not found: ' . $template);
        }

        extract($data, EXTR_SKIP);
        ob_start();
        include $path;

        return (string) ob_get_clean();
    }

    /** @param array<string, mixed> $data */
    public function renderInLayout(string $pageTemplate, array $data = []): string
    {
        $data['content'] = $this->render($pageTemplate, $data);

        return $this->render('layout', $data);
    }

    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
