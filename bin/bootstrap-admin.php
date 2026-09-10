#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Bootstrap first operator into admin_users (AD-S4).
 *
 *   php bin/bootstrap-admin.php [--dry-run] [--pass-file=~/.ssh/project1960-admin.pass]
 *
 * Env / pass file (never commit):
 *   P1960_ADMIN_USERNAME=
 *   P1960_ADMIN_EMAIL=
 *   P1960_ADMIN_PASSWORD=
 *   P1960_ADMIN_ROLE=operator   # optional
 */

use Project1960\AdminBootstrap;
use Project1960\Config;
use Project1960\Database;
use Project1960\Schema;

require dirname(__DIR__) . '/vendor/autoload.php';

$dryRun = in_array('--dry-run', $argv, true);
$passFile = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--pass-file=')) {
        $passFile = substr($arg, strlen('--pass-file='));
        if (str_starts_with($passFile, '~/')) {
            $home = getenv('HOME') ?: '';
            $passFile = $home . substr($passFile, 1);
        }
    }
}
if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
    fwrite(STDOUT, "Usage: php bin/bootstrap-admin.php [--dry-run] [--pass-file=PATH]\n");
    exit(0);
}

try {
    $dbPath = Config::databasePath();
    if (!is_file($dbPath)) {
        fwrite(STDERR, "Database missing: {$dbPath}\n");
        exit(1);
    }
    $pdo = Database::connect($dbPath);
    Schema::migrate($pdo);
    $boot = new AdminBootstrap($pdo);
    $creds = AdminBootstrap::credentialsFromEnv([], $passFile);
    $result = $boot->create($creds, $dryRun);
    $mode = $dryRun ? 'dry-run' : 'created';
    fwrite(STDOUT, sprintf(
        "OK %s id=%d username=%s email=%s role=%s operators_now=%d\n",
        $mode,
        $result['id'],
        $result['username'],
        $result['email'],
        $result['role'],
        $boot->countOperators()
    ));
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'bootstrap-admin: ' . $e->getMessage() . "\n");
    exit(1);
}
