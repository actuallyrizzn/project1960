<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

[$app, $request] = project1960_boot('/admin/users');
project1960_send($app->handle($request));
