<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

[$app, $request] = project1960_boot('/api/admin/keys');
project1960_send($app->handle($request));
