<?php
declare(strict_types=1);

namespace Project1960\Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use Project1960\AdminUsers;
use Project1960\AdminUsersController;
use Project1960\ApiKeys;
use Project1960\App;
use Project1960\Csrf;
use Project1960\Request;
use Project1960\Schema;

final class AdminUsersTest extends TestCase
{
    private string $path;
    private PDO $pdo;
    private int $opsId;
    private string $opsKey;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/p1960_users_' . bin2hex(random_bytes(4)) . '.db';
        $this->pdo = new PDO('sqlite:' . $this->path);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        Schema::migrate($this->pdo);
        $hash = password_hash('long-enough-secret', PASSWORD_DEFAULT);
        $this->pdo->exec(
            "INSERT INTO admin_users (username, email, password_hash, role)
             VALUES ('ops', 'ops@ex.com', " . $this->pdo->quote($hash) . ", 'operator')"
        );
        $this->opsId = (int) $this->pdo->query('SELECT id FROM admin_users')->fetchColumn();
        $this->opsKey = (new ApiKeys($this->pdo))->mint($this->opsId, 't', null, $this->opsId)['plaintext'];
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testCrudService(): void
    {
        $svc = new AdminUsers($this->pdo);
        $u = $svc->create([
            'username' => 'ada',
            'email' => 'ada@ex.com',
            'password' => 'long-enough-secret',
            'role' => 'readonly',
        ]);
        self::assertSame('ada', $u['username']);
        $off = $svc->setActive((int) $u['id'], false);
        self::assertSame(0, $off['is_active']);
        $svc->resetPassword((int) $u['id'], 'another-long-pw');
        self::assertCount(2, $svc->list());
    }

    public function testApiRequiresScope(): void
    {
        $app = new App(null, null, $this->pdo);
        $deny = $app->handle(new Request('GET', '/api/admin/users'));
        self::assertSame(401, $deny->status);

        $ok = $app->handle(new Request(
            'GET',
            '/api/admin/users',
            [],
            [],
            ['HTTP_X_API_KEY' => $this->opsKey]
        ));
        self::assertSame(200, $ok->status);
        self::assertStringContainsString('ops', $ok->body);
    }

    public function testPageCreateWithCsrf(): void
    {
        $ctl = new AdminUsersController($this->pdo);
        $auth = new \Project1960\AdminAuth($this->pdo);
        $auth->login('ops', 'long-enough-secret');
        $token = Csrf::token();
        $resp = $ctl->pagePost([
            'csrf_token' => $token,
            'action' => 'create',
            'username' => 'newop',
            'email' => 'new@ex.com',
            'password' => 'long-enough-secret',
            'role' => 'operator',
        ]);
        self::assertSame(200, $resp->status);
        self::assertStringContainsString('User created', $resp->body);
        self::assertStringContainsString('newop', $resp->body);
    }

    public function testValidationAndPageActions(): void
    {
        $svc = new AdminUsers($this->pdo);
        try {
            $svc->create(['username' => '', 'email' => 'x@y.com', 'password' => 'long-enough-secret']);
            self::fail('expected');
        } catch (\InvalidArgumentException) {
        }
        try {
            $svc->create(['username' => 'a', 'email' => 'a@b.c', 'password' => 'short']);
            self::fail('expected');
        } catch (\InvalidArgumentException) {
        }
        try {
            $svc->create(['username' => 'a', 'email' => 'a@b.c', 'password' => 'long-enough-secret', 'role' => 'nope']);
            self::fail('expected');
        } catch (\InvalidArgumentException) {
        }
        try {
            $svc->get(9999);
            self::fail('expected');
        } catch (\InvalidArgumentException) {
        }
        try {
            $svc->create(['username' => 'ops', 'email' => 'dup@ex.com', 'password' => 'long-enough-secret']);
            self::fail('expected');
        } catch (\InvalidArgumentException) {
        }
        try {
            $svc->resetPassword($this->opsId, 'short');
            self::fail('expected');
        } catch (\InvalidArgumentException) {
        }

        $ctl = new AdminUsersController($this->pdo);
        (new \Project1960\AdminAuth($this->pdo))->login('ops', 'long-enough-secret');
        $get = $ctl->pageGet();
        self::assertStringContainsString('Users', $get->body);

        $badCsrf = $ctl->pagePost(['csrf_token' => 'x', 'action' => 'create']);
        self::assertStringContainsString('Invalid security token', $badCsrf->body);

        $token = Csrf::token();
        $toggle = $ctl->pagePost([
            'csrf_token' => $token,
            'action' => 'toggle',
            'user_id' => (string) $this->opsId,
            'is_active' => '0',
        ]);
        self::assertStringContainsString('User updated', $toggle->body);

        $token = Csrf::token();
        $reset = $ctl->pagePost([
            'csrf_token' => $token,
            'action' => 'reset_password',
            'user_id' => (string) $this->opsId,
            'password' => 'brand-new-long-password',
        ]);
        self::assertStringContainsString('Password reset', $reset->body);

        $token = Csrf::token();
        $unknown = $ctl->pagePost(['csrf_token' => $token, 'action' => 'nope']);
        self::assertStringContainsString('Unknown action', $unknown->body);

        $created = $ctl->apiCreate([
            'username' => 'apiuser',
            'email' => 'api@ex.com',
            'password' => 'long-enough-secret',
            'role' => 'readonly',
        ]);
        self::assertSame(201, $created->status);
        $badApi = $ctl->apiCreate(['username' => 'x', 'email' => 'y', 'password' => 'short']);
        self::assertSame(400, $badApi->status);
    }
}
