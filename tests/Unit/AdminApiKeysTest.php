<?php
declare(strict_types=1);

namespace Project1960\Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use Project1960\AdminApiKeysController;
use Project1960\AdminAuth;
use Project1960\ApiKeys;
use Project1960\App;
use Project1960\Csrf;
use Project1960\Request;
use Project1960\Schema;

final class AdminApiKeysTest extends TestCase
{
    private string $path;
    private PDO $pdo;
    private int $opsId;
    private string $opsKey;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/p1960_apikeys_ui_' . bin2hex(random_bytes(4)) . '.db';
        $this->pdo = new PDO('sqlite:' . $this->path);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        Schema::migrate($this->pdo);
        $hash = password_hash('long-enough-secret', PASSWORD_DEFAULT);
        $this->pdo->exec(
            "INSERT INTO admin_users (username, email, password_hash, role)
             VALUES ('ops', 'ops@ex.com', " . $this->pdo->quote($hash) . ", 'operator')"
        );
        $this->opsId = (int) $this->pdo->query('SELECT id FROM admin_users')->fetchColumn();
        $this->opsKey = (new ApiKeys($this->pdo))->mint($this->opsId, 'boot', null, $this->opsId)['plaintext'];
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

    public function testApiRequiresScope(): void
    {
        $app = new App(null, null, $this->pdo);
        $deny = $app->handle(new Request('GET', '/api/admin/keys'));
        self::assertSame(401, $deny->status);

        $ok = $app->handle(new Request(
            'GET',
            '/api/admin/keys',
            [],
            [],
            ['HTTP_X_API_KEY' => $this->opsKey]
        ));
        self::assertSame(200, $ok->status);
        self::assertStringContainsString('boot', $ok->body);
    }

    public function testApiMintAndRevoke(): void
    {
        $app = new App(null, null, $this->pdo);
        $mint = $app->handle(new Request(
            'POST',
            '/api/admin/keys',
            [],
            [],
            ['HTTP_X_API_KEY' => $this->opsKey],
            [
                'key_name' => 'ci-mint',
                'scopes' => [ApiKeys::SCOPE_STATS_READ],
            ]
        ));
        self::assertSame(201, $mint->status);
        $payload = json_decode($mint->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('plaintext', $payload);
        self::assertStringStartsWith('p1960_', $payload['plaintext']);
        $keyId = (int) $payload['key']['id'];

        $rev = $app->handle(new Request(
            'POST',
            '/api/admin/keys/revoke',
            [],
            [],
            ['HTTP_X_API_KEY' => $this->opsKey],
            ['key_id' => (string) $keyId]
        ));
        self::assertSame(200, $rev->status);
        self::assertStringContainsString('revoked', $rev->body);

        $missing = $app->handle(new Request(
            'POST',
            '/api/admin/keys/revoke',
            [],
            [],
            ['HTTP_X_API_KEY' => $this->opsKey],
            ['key_id' => '99999']
        ));
        self::assertSame(404, $missing->status);
    }

    public function testPageMintRevokeAndCsrf(): void
    {
        $ctl = new AdminApiKeysController($this->pdo);
        (new AdminAuth($this->pdo))->login('ops', 'long-enough-secret');

        $get = $ctl->pageGet();
        self::assertSame(200, $get->status);
        self::assertStringContainsString('API Keys', $get->body);
        self::assertStringContainsString('Mint key', $get->body);

        $bad = $ctl->pagePost(['csrf_token' => 'nope', 'action' => 'mint']);
        self::assertStringContainsString('Invalid security token', $bad->body);

        $token = Csrf::token();
        $mint = $ctl->pagePost([
            'csrf_token' => $token,
            'action' => 'mint',
            'key_name' => 'ui-key',
            'scopes' => [ApiKeys::SCOPE_CASES_READ],
        ]);
        self::assertStringContainsString('Key minted', $mint->body);
        self::assertStringContainsString('p1960_', $mint->body);
        self::assertStringContainsString('ui-key', $mint->body);

        $id = (int) $this->pdo->query(
            "SELECT id FROM api_keys WHERE key_name = 'ui-key' ORDER BY id DESC LIMIT 1"
        )->fetchColumn();

        $token = Csrf::token();
        $rev = $ctl->pagePost([
            'csrf_token' => $token,
            'action' => 'revoke',
            'key_id' => (string) $id,
        ]);
        self::assertStringContainsString('Key revoked', $rev->body);

        $token = Csrf::token();
        $unknown = $ctl->pagePost(['csrf_token' => $token, 'action' => 'nope']);
        self::assertStringContainsString('Unknown action', $unknown->body);

        $token = Csrf::token();
        $missing = $ctl->pagePost([
            'csrf_token' => $token,
            'action' => 'revoke',
            'key_id' => '99999',
        ]);
        self::assertStringContainsString('Key not found', $missing->body);
    }

    public function testApiMintValidationAndListAll(): void
    {
        $ctl = new AdminApiKeysController($this->pdo);
        $bad = $ctl->apiMint(['key_name' => 'x', 'scopes' => ['not-a-scope']], $this->opsId);
        self::assertSame(400, $bad->status);

        $ok = $ctl->apiMint([
            'key_name' => 'listed',
            'scopes' => [ApiKeys::SCOPE_ADMIN_KEYS],
            'user_id' => $this->opsId,
        ], $this->opsId);
        self::assertSame(201, $ok->status);

        $list = $ctl->apiList();
        self::assertSame(200, $list->status);
        self::assertStringContainsString('listed', $list->body);

        $keys = new ApiKeys($this->pdo);
        self::assertNotEmpty($keys->listAll(true));
        self::assertNotEmpty($keys->listForUser($this->opsId, true));
    }

    public function testAdminPageRequiresAuth(): void
    {
        $app = new App(null, null, $this->pdo);
        $deny = $app->handle(new Request('GET', '/admin/api-keys'));
        self::assertSame(302, $deny->status);
        self::assertSame('/admin/login', $deny->headers['Location'] ?? null);
    }

    public function testPagePostWithoutSessionRedirects(): void
    {
        $_SESSION = [];
        $ctl = new AdminApiKeysController($this->pdo);
        $token = Csrf::token();
        $resp = $ctl->pagePost([
            'csrf_token' => $token,
            'action' => 'mint',
            'key_name' => 'orphan',
        ]);
        self::assertSame(302, $resp->status);
        self::assertSame('/admin/login', $resp->headers['Location'] ?? null);
    }

    public function testMintEmptyScopesAndApiEdgePaths(): void
    {
        $ctl = new AdminApiKeysController($this->pdo);
        (new AdminAuth($this->pdo))->login('ops', 'long-enough-secret');
        $token = Csrf::token();
        $mint = $ctl->pagePost([
            'csrf_token' => $token,
            'action' => 'mint',
            'key_name' => 'default-scopes',
            'scopes' => [],
        ]);
        self::assertStringContainsString('Key minted', $mint->body);

        $token = Csrf::token();
        $badScopes = $ctl->pagePost([
            'csrf_token' => $token,
            'action' => 'mint',
            'key_name' => 'bad',
            'scopes' => ['not:real'],
        ]);
        self::assertStringContainsString('scope', strtolower($badScopes->body));

        $zeroUser = $ctl->apiMint([
            'user_id' => 0,
            'key_name' => 'actor-fallback',
            'scopes' => ApiKeys::SCOPE_STATS_READ,
        ], $this->opsId);
        self::assertSame(201, $zeroUser->status);

        $nullScopes = $ctl->apiMint([
            'key_name' => 'null-scopes',
            'scopes' => 'ignore-me',
        ], $this->opsId);
        self::assertSame(201, $nullScopes->status);
    }
}
