<?php
declare(strict_types=1);

// Seed a fixture SQLite for design-smoke. Usage: php seed-fixture.php /abs/path.db

$target = $argv[1] ?? '';
if ($target === '') {
    fwrite(STDERR, "usage: php seed-fixture.php /path/to.db\n");
    exit(1);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

if (is_file($target)) {
    unlink($target);
}

new Project1960\FixtureDatabase($target);
if (!is_file($target)) {
    fwrite(STDERR, "fixture not created\n");
    exit(1);
}
echo "ok\n";
