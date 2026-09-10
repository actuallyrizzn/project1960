<?php
declare(strict_types=1);

namespace Project1960;

use RuntimeException;

/**
 * Deploy-safe configuration. Live SQLite lives outside public/ (default ../db/).
 */
final class Config
{
    /**
     * Resolve absolute path to the SQLite database.
     *
     * Priority:
     * 1. DATABASE_PATH (absolute or relative to process cwd)
     * 2. DATABASE_NAME under dbDir()
     * 3. doj_cases.db under dbDir()
     *
     * @param array<string, string|null> $env
     */
    public static function databasePath(array $env = [], ?string $projectRoot = null): string
    {
        $root = $projectRoot ?? self::defaultProjectRoot();
        $fromEnv = $env['DATABASE_PATH'] ?? getenv('DATABASE_PATH') ?: null;
        if (is_string($fromEnv) && $fromEnv !== '') {
            return self::absolutize($fromEnv, $root);
        }

        $name = $env['DATABASE_NAME'] ?? getenv('DATABASE_NAME') ?: 'doj_cases.db';
        if (!is_string($name) || $name === '') {
            $name = 'doj_cases.db';
        }

        return self::dbDir($root) . DIRECTORY_SEPARATOR . $name;
    }

    public static function dbDir(?string $projectRoot = null): string
    {
        $root = $projectRoot ?? self::defaultProjectRoot();

        return $root . DIRECTORY_SEPARATOR . 'db';
    }

    public static function defaultProjectRoot(): string
    {
        // public/includes → public → repo root
        return dirname(__DIR__, 2);
    }

    private static function absolutize(string $path, string $root): string
    {
        if ($path[0] === '/' || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1) {
            return $path;
        }

        return $root . DIRECTORY_SEPARATOR . $path;
    }
}
