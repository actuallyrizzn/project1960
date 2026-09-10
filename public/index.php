<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

[$app, $request] = project1960_boot('/');
project1960_send($app->handle($request));
