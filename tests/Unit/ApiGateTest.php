<?php
declare(strict_types=1);

namespace Project1960\Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use Project1960\ApiGate;
use Project1960\ApiKeys;
use Project1960\App;
use Project1960\Request;
use Project1960\Schema;

final class ApiGateTest extends TestCase
{
    private string $path;
    private PDO $pdo;
    private int $userId;
    private string $goodKey;
    private string $statsOnlyKey;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/p1960_gate_' . bin2hex(random_bytes(4)) . '.db';
        $this->pdo = new PDO('sqlite:' . $this->path);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        Schema::migrate($this->pdo);
        $hash = password_hash('x', PASSWORD_DEFAULT);
        $this->pdo->exec(
            "INSERT INTO admin_users (username, email, password_hash, role)
             VALUES ('ops', 'ops@ex.com', " . $this->pdo->quote($hash) . ", 'operator')"
        );
        $this->userId = (int) $this->pdo->query('SELECT id FROM admin_users')->fetchColumn();
        $keys = new ApiKeys($this->pdo);
        $this->goodKey = $keys->mint($this->userId, 'full', null, $this->userId)['plaintext'];
        $this->statsOnlyKey = $keys->mint(
            $this->userId,
            'stats',
            [ApiKeys::SCOPE_STATS_READ],
            $this->userId
        )['plaintext'];
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testAnonymousPublicAllowedByDefault(): void
    {
        $gate = new ApiGate($this->pdo, false);
        self::assertNull($gate->authorizePublic('/api/stats', []));
    }

    public function testAnonymousDeniedWhenRequireKey(): void
    {
        $gate = ApiGate::fromEnv($this->pdo, ['P1960_API_REQUIRE_KEY' => '1']);
        $deny = $gate->authorizePublic('/api/stats', []);
        self::assertNotNull($deny);
        self::assertSame(401, $deny->status);
    }

    public function testInvalidKey401(): void
    {
        $gate = new ApiGate($this->pdo);
        $deny = $gate->authorizePublic('/api/stats', ['HTTP_X_API_KEY' => 'nope']);
        self::assertNotNull($deny);
        self::assertSame(401, $deny->status);
    }

    public function testValidKeyWrongScope403(): void
    {
        $gate = new ApiGate($this->pdo);
        $deny = $gate->authorizePublic('/api/cases', ['HTTP_X_API_KEY' => $this->statsOnlyKey]);
        self::assertNotNull($deny);
        self::assertSame(403, $deny->status);
    }

    public function testValidKeyOk(): void
    {
        $gate = new ApiGate($this->pdo);
        self::assertNull($gate->authorizePublic('/api/stats', ['HTTP_X_API_KEY' => $this->statsOnlyKey]));
        self::assertNull($gate->authorizePublic('/api/cases', ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->goodKey]));
        self::assertNull($gate->authorizePublic('/api/enrichment/x', ['HTTP_X_API_KEY' => $this->goodKey]));
    }

    public function testAdminAlwaysRequiresKey(): void
    {
        $gate = new ApiGate($this->pdo);
        $deny = $gate->authorizeAdmin(ApiKeys::SCOPE_ADMIN_READ, []);
        self::assertNotNull($deny);
        self::assertSame(401, $deny->status);

        self::assertNull($gate->authorizeAdmin(
            ApiKeys::SCOPE_ADMIN_READ,
            ['HTTP_X_API_KEY' => $this->goodKey]
        ));

        $forbid = $gate->authorizeAdmin(
            ApiKeys::SCOPE_ADMIN_KEYS,
            ['HTTP_X_API_KEY' => $this->statsOnlyKey]
        );
        self::assertNotNull($forbid);
        self::assertSame(403, $forbid->status);
    }

    public function testAppRouteHonorsGate(): void
    {
        $app = new App(null, null, $this->pdo);
        $bad = $app->handle(new Request('GET', '/api/stats', [], [], ['HTTP_X_API_KEY' => 'bad']));
        self::assertSame(401, $bad->status);

        $ok = $app->handle(new Request('GET', '/api/stats', [], [], []));
        self::assertSame(200, $ok->status);
    }

    public function testScopeForPath(): void
    {
        $gate = new ApiGate($this->pdo);
        self::assertSame(ApiKeys::SCOPE_ENRICHMENT_READ, $gate->scopeForPath('/api/enrichment/1'));
        self::assertSame(ApiKeys::SCOPE_CASES_READ, $gate->scopeForPath('/api/cases'));
        self::assertSame(ApiKeys::SCOPE_PATTERNS_READ, $gate->scopeForPath('/api/patterns'));
        self::assertSame(ApiKeys::SCOPE_STATS_READ, $gate->scopeForPath('/api/stats'));
        self::assertSame(ApiKeys::SCOPE_STATS_READ, $gate->scopeForPath('/api/other'));
    }
}
