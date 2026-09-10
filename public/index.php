<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Project1960\App;
use Project1960\Database;
use Project1960\Request;

$pdo = null;
try {
    $pdo = Database::connectFromEnv();
} catch (Throwable) {
    $pdo = null;
}

$app = new App(null, null, $pdo);
$response = $app->handle(Request::fromGlobals($_SERVER));
$response->send();
