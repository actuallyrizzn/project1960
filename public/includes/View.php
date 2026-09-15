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

    /**
     * Human-readable date for case tables (legacy Flask human_date).
     * Accepts Unix timestamps or common date strings → Y-m-d.
     */
    public static function humanDate(mixed $value): string
    {
        if ($value === null) {
            return 'N/A';
        }
        if (is_string($value)) {
            $value = trim($value);
        }
        if ($value === '' || $value === false) {
            return 'N/A';
        }

        if (is_numeric($value)) {
            $ts = (int) $value;
            // Digits-only strings under 8 chars are unlikely Unix seconds (e.g. "20240115")
            if (is_string($value) && !str_contains($value, '.') && strlen(ltrim($value, '-')) < 9) {
                // fall through to string parse
            } else {
                return gmdate('Y-m-d', $ts);
            }
        }

        $raw = (string) $value;
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $raw) === 1) {
            return substr($raw, 0, 10);
        }

        $parsed = strtotime($raw);
        if ($parsed !== false) {
            return gmdate('Y-m-d', $parsed);
        }

        return $raw;
    }
}
