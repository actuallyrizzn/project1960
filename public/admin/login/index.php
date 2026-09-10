<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

[$app, $request] = project1960_boot('/admin/login');
project1960_send($app->handle($request));
