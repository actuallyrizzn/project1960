<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$id = isset($_GET['id']) ? (string) $_GET['id'] : '';
[$app, $base] = project1960_boot('/api/enrichment/' . rawurlencode($id));
project1960_send($app->handle($base));
