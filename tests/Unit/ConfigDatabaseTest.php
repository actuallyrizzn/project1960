<?php
declare(strict_types=1);

namespace Project1960\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Project1960\Config;
use Project1960\Database;
use RuntimeException;

final class ConfigDatabaseTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/p1960_' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir . '/db', 0777, true);
        mkdir($this->tmpDir . '/public/includes', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmpDir);
    }

    public function testDatabasePathDefaultsUnderDbDir(): void
    {
        $path = Config::databasePath([], $this->tmpDir);
        self::assertSame($this->tmpDir . '/db/doj_cases.db', $path);
        self::assertSame($this->tmpDir . '/db', Config::dbDir($this->tmpDir));
    }

    public function testDatabasePathHonorsDatabaseName(): void
    {
        $path = Config::databasePath(['DATABASE_NAME' => 'fixture.db'], $this->tmpDir);
        self::assertSame($this->tmpDir . '/db/fixture.db', $path);
    }

    public function testDatabasePathHonorsAbsoluteDatabasePath(): void
    {
        $abs = $this->tmpDir . '/custom.sqlite';
        $path = Config::databasePath(['DATABASE_PATH' => $abs], $this->tmpDir);
        self::assertSame($abs, $path);
    }

    public function testDatabasePathHonorsRelativeDatabasePath(): void
    {
        $path = Config::databasePath(['DATABASE_PATH' => 'db/rel.db'], $this->tmpDir);
        self::assertSame($this->tmpDir . '/db/rel.db', $path);
    }

    public function testConnectOpensFixtureSqlite(): void
    {
        $dbFile = $this->tmpDir . '/db/doj_cases.db';
        $pdoCreate = new \PDO('sqlite:' . $dbFile);
        $pdoCreate->exec('CREATE TABLE smoke (id INTEGER PRIMARY KEY)');
        $pdoCreate = null;

        $pdo = Database::connect($dbFile);
        $pdo->exec('INSERT INTO smoke DEFAULT VALUES');
        $count = (int) $pdo->query('SELECT COUNT(*) FROM smoke')->fetchColumn();
        self::assertSame(1, $count);
    }

    public function testDatabasePathEmptyNameFallsBack(): void
    {
        $path = Config::databasePath(['DATABASE_NAME' => ''], $this->tmpDir);
        self::assertSame($this->tmpDir . '/db/doj_cases.db', $path);
    }

    public function testDefaultProjectRootPointsAtRepo(): void
    {
        $root = Config::defaultProjectRoot();
        self::assertDirectoryExists($root . '/public');
        self::assertFileExists($root . '/public/includes/Config.php');
    }

    public function testConnectFromEnvUsesConfigPath(): void
    {
        $dbFile = $this->tmpDir . '/db/doj_cases.db';
        (new \PDO('sqlite:' . $dbFile))->exec('CREATE TABLE t (x INTEGER)');

        $pdo = Database::connectFromEnv([], $this->tmpDir);
        self::assertNotFalse($pdo->query('SELECT 1'));
    }

    public function testConnectEmptyPathFails(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SQLite database not found');
        Database::connect('');
    }

    public function testConnectInvalidSqliteFailsClearly(): void
    {
        $dbFile = $this->tmpDir . '/db/broken.db';
        touch($dbFile);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to open SQLite');
        Database::connect($dbFile, static function (): \PDO {
            throw new \PDOException('simulated open failure');
        });
    }

    public function testWindowsStyleAbsolutePathPassedThrough(): void
    {
        $win = 'C:\\data\\doj_cases.db';
        $path = Config::databasePath(['DATABASE_PATH' => $win], $this->tmpDir);
        self::assertSame($win, $path);
    }

    public function testConnectMissingDbFailsClearly(): void
    {
        $missing = $this->tmpDir . '/db/missing.db';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SQLite database not found');
        Database::connect($missing);
    }

    public function testDbNeverDefaultsUnderPublic(): void
    {
        $path = Config::databasePath([], $this->tmpDir);
        self::assertStringNotContainsString('/public/', $path);
        self::assertStringContainsString('/db/', $path);
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
}
