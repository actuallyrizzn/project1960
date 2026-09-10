<?php
declare(strict_types=1);

namespace Project1960;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    /**
     * @param (callable(string): PDO)|null $pdoFactory Inject for tests
     */
    public static function connect(string $path, ?callable $pdoFactory = null): PDO
    {
        if ($path === '' || !is_file($path)) {
            throw new RuntimeException(
                'SQLite database not found at "' . $path . '". '
                . 'Set DATABASE_PATH (or place doj_cases.db under db/ outside public/).'
            );
        }

        $factory = $pdoFactory ?? static function (string $dbPath): PDO {
            return new PDO('sqlite:' . $dbPath, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        };

        try {
            return $factory($path);
        } catch (PDOException $e) {
            throw new RuntimeException('Failed to open SQLite at "' . $path . '": ' . $e->getMessage(), 0, $e);
        }
    }

    public static function connectFromEnv(array $env = [], ?string $projectRoot = null): PDO
    {
        return self::connect(Config::databasePath($env, $projectRoot));
    }
}
