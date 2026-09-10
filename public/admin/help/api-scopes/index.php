<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . '/bootstrap.php';

[$app, $request] = project1960_boot('/admin/help/api-scopes');
project1960_send($app->handle($request));
