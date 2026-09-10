<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . '/bootstrap.php';

[$app, $request] = project1960_boot('/admin/help/pipeline-cli');
project1960_send($app->handle($request));
