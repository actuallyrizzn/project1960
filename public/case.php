<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$id = isset($_GET['id']) ? (string) $_GET['id'] : '';
[$app, $base] = project1960_boot('/case/' . rawurlencode($id));
project1960_send($app->handle($base));
