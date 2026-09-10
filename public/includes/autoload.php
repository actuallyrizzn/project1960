<?php
declare(strict_types=1);

/**
 * Composer-free PSR-4 loader for multihost deploys that only rsync public/.
 * Dev/tests still use repo-root vendor/autoload.php when present.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'Project1960\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});
