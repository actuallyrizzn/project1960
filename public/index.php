<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Project1960\App;
use Project1960\Request;

$app = new App();
$response = $app->handle(Request::fromGlobals($_SERVER));
$response->send();
