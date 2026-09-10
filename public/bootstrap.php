<?php
declare(strict_types=1);

$vendor = dirname(__DIR__) . '/vendor/autoload.php';
$local = __DIR__ . '/includes/autoload.php';
if (is_file($vendor)) {
    require $vendor;
} else {
    require $local;
}

use Project1960\App;
use Project1960\Database;
use Project1960\Request;
use Project1960\Response;

/**
 * Shared front-controller bootstrap for multihost (try_files =404 — real PHP files only).
 *
 * @return array{0: App, 1: Request}
 */
function project1960_boot(?string $forcePath = null): array
{
    $pdo = null;
    try {
        $pdo = Database::connectFromEnv();
    } catch (Throwable) {
        $pdo = null;
    }

    $app = new App(null, null, $pdo);
    $request = Request::fromGlobals($_SERVER, $_GET);
    if ($forcePath !== null) {
        $request = new Request($request->method, $forcePath, $request->query, $request->attrs);
    }

    return [$app, $request];
}

function project1960_send(Response $response): void
{
    $response->send();
}
