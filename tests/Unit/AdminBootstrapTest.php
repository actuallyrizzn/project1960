<?php
declare(strict_types=1);

namespace Project1960\Tests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Project1960\AdminBootstrap;
use Project1960\Schema;

final class AdminBootstrapTest extends TestCase
{
    private string $path;
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/p1960_boot_' . bin2hex(random_bytes(4)) . '.db';
        $this->pdo = new PDO('sqlite:' . $this->path);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        Schema::migrate($this->pdo);
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testDryRunDoesNotInsert(): void
    {
        $boot = new AdminBootstrap($this->pdo);
        $r = $boot->create([
            'username' => 'mark',
            'email' => 'mark@example.com',
            'password' => 'long-enough-secret',
        ], true);
        self::assertFalse($r['created']);
        self::assertSame(0, $boot->countOperators());
    }

    public function testCreateAndDuplicateRejected(): void
    {
        $boot = new AdminBootstrap($this->pdo);
        $r = $boot->create([
            'username' => 'mark',
            'email' => 'mark@example.com',
            'password' => 'long-enough-secret',
        ]);
        self::assertTrue($r['created']);
        self::assertSame(1, $boot->countOperators());
        self::assertGreaterThan(0, $r['id']);

        $this->expectException(InvalidArgumentException::class);
        $boot->create([
            'username' => 'mark',
            'email' => 'other@example.com',
            'password' => 'long-enough-secret',
        ]);
    }

    public function testShortPasswordRejected(): void
    {
        $boot = new AdminBootstrap($this->pdo);
        $this->expectException(InvalidArgumentException::class);
        $boot->create([
            'username' => 'x',
            'email' => 'x@y.com',
            'password' => 'short',
        ]);
    }

    public function testCredentialsFromPassFile(): void
    {
        $pf = sys_get_temp_dir() . '/p1960_pass_' . bin2hex(random_bytes(4)) . '.pass';
        file_put_contents($pf, "P1960_ADMIN_USERNAME=ops\nP1960_ADMIN_EMAIL=ops@ex.com\nP1960_ADMIN_PASSWORD=twelvechars!!\n");
        $creds = AdminBootstrap::credentialsFromEnv([], $pf);
        self::assertSame('ops', $creds['username']);
        self::assertSame('ops@ex.com', $creds['email']);
        self::assertSame('twelvechars!!', $creds['password']);
        unlink($pf);
    }

    public function testMissingPassFileThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AdminBootstrap::credentialsFromEnv([], '/tmp/no-such-p1960-pass-' . bin2hex(random_bytes(4)));
    }

    public function testInvalidRoleAndEmptyUsername(): void
    {
        $boot = new AdminBootstrap($this->pdo);
        try {
            $boot->create([
                'username' => 'ok',
                'email' => 'ok@ex.com',
                'password' => 'long-enough-secret',
                'role' => 'god',
            ]);
            self::fail('expected role error');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('role', $e->getMessage());
        }
        $this->expectException(InvalidArgumentException::class);
        $boot->create([
            'username' => '  ',
            'email' => 'ok@ex.com',
            'password' => 'long-enough-secret',
        ]);
    }

    public function testCredentialsFromEnvArray(): void
    {
        $creds = AdminBootstrap::credentialsFromEnv([
            'P1960_ADMIN_USERNAME' => 'a',
            'P1960_ADMIN_EMAIL' => 'a@b.c',
            'P1960_ADMIN_PASSWORD' => 'twelvechars!!',
            'P1960_ADMIN_ROLE' => 'readonly',
        ]);
        self::assertSame('readonly', $creds['role']);
    }
}
