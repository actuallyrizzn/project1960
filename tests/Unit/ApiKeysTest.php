<?php
declare(strict_types=1);

namespace Project1960\Tests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Project1960\ApiKeys;
use Project1960\Schema;

final class ApiKeysTest extends TestCase
{
    private string $path;
    private PDO $pdo;
    private int $userId;
    private int $readonlyId;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/p1960_keys_' . bin2hex(random_bytes(4)) . '.db';
        $this->pdo = new PDO('sqlite:' . $this->path);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        Schema::migrate($this->pdo);
        $hash = password_hash('x', PASSWORD_DEFAULT);
        $this->pdo->exec(
            "INSERT INTO admin_users (username, email, password_hash, role)
             VALUES ('ops', 'ops@ex.com', " . $this->pdo->quote($hash) . ", 'operator')"
        );
        $this->userId = (int) $this->pdo->query('SELECT id FROM admin_users WHERE username=\'ops\'')->fetchColumn();
        $this->pdo->exec(
            "INSERT INTO admin_users (username, email, password_hash, role)
             VALUES ('ro', 'ro@ex.com', " . $this->pdo->quote($hash) . ", 'readonly')"
        );
        $this->readonlyId = (int) $this->pdo->query('SELECT id FROM admin_users WHERE username=\'ro\'')->fetchColumn();
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testMintResolveRevokeAndScope(): void
    {
        $keys = new ApiKeys($this->pdo);
        $minted = $keys->mint($this->userId, 'ci', [ApiKeys::SCOPE_STATS_READ], $this->userId);
        self::assertStringStartsWith('p1960_', $minted['plaintext']);
        self::assertSame([ApiKeys::SCOPE_STATS_READ], $minted['key']['scopes']);

        $row = $keys->resolvePlaintext($minted['plaintext']);
        self::assertNotNull($row);
        self::assertSame('ops', $row['username']);
        $keys->requireScope($row, ApiKeys::SCOPE_STATS_READ);

        $this->expectException(InvalidArgumentException::class);
        $keys->requireScope($row, ApiKeys::SCOPE_ADMIN_KEYS);
    }

    public function testRevokeStopsResolve(): void
    {
        $keys = new ApiKeys($this->pdo);
        $minted = $keys->mint($this->userId, 'tmp', null, $this->userId);
        self::assertTrue($keys->revoke((int) $minted['key']['id']));
        self::assertNull($keys->resolvePlaintext($minted['plaintext']));
        self::assertTrue($keys->revoke((int) $minted['key']['id'])); // idempotent
        self::assertFalse($keys->revoke(99999));
    }

    public function testAuthenticateBearerAndXApiKey(): void
    {
        $keys = new ApiKeys($this->pdo);
        $minted = $keys->mint($this->userId, 'hdr', [ApiKeys::SCOPE_CASES_READ], $this->userId);
        $pt = $minted['plaintext'];

        $viaHeader = $keys->authenticateFromHeaders(['HTTP_X_API_KEY' => $pt]);
        self::assertNotNull($viaHeader);

        $viaBearer = $keys->authenticateFromHeaders(['HTTP_AUTHORIZATION' => 'Bearer ' . $pt]);
        self::assertNotNull($viaBearer);

        self::assertNull($keys->authenticateFromHeaders([]));
        self::assertNull($keys->resolvePlaintext(''));
    }

    public function testReadonlyCannotHoldAdminScopes(): void
    {
        $keys = new ApiKeys($this->pdo);
        $this->expectException(InvalidArgumentException::class);
        $keys->mint($this->readonlyId, 'bad', [ApiKeys::SCOPE_ADMIN_KEYS], $this->readonlyId);
    }

    public function testNormalizeUnknownScopesRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ApiKeys::normalizeScopes(['not:a:scope'], 'operator');
    }

    public function testListForUserAndPresets(): void
    {
        $keys = new ApiKeys($this->pdo);
        $keys->mint($this->userId, 'a', [ApiKeys::SCOPE_STATS_READ], $this->userId);
        $keys->mint($this->userId, 'b', [ApiKeys::SCOPE_CASES_READ], $this->userId);
        $list = $keys->listForUser($this->userId);
        self::assertCount(2, $list);
        self::assertSame(ApiKeys::ALL_SCOPES, ApiKeys::presetsForRole('operator'));
        self::assertContains(ApiKeys::SCOPE_STATS_READ, ApiKeys::presetsForRole('readonly'));
        self::assertNotContains(ApiKeys::SCOPE_ADMIN_KEYS, ApiKeys::presetsForRole('readonly'));
    }

    public function testInactiveUserCannotMint(): void
    {
        $this->pdo->exec('UPDATE admin_users SET is_active = 0 WHERE id = ' . $this->userId);
        $keys = new ApiKeys($this->pdo);
        $this->expectException(InvalidArgumentException::class);
        $keys->mint($this->userId, 'x', null, $this->userId);
    }

    public function testScopesFromRowHandlesJunk(): void
    {
        self::assertSame([], ApiKeys::scopesFromRow(['scopes_json' => '{']));
        self::assertSame(['a'], ApiKeys::scopesFromRow(['scopes' => ['a']]));
        self::assertSame([], ApiKeys::scopesFromRow(['scopes_json' => '']));
        self::assertSame([], ApiKeys::scopesFromRow(['scopes_json' => 'null']));
    }

    public function testNameDefaultsAndTruncate(): void
    {
        $keys = new ApiKeys($this->pdo);
        $a = $keys->mint($this->userId, '   ', [ApiKeys::SCOPE_STATS_READ], $this->userId);
        self::assertSame('Unnamed Key', $a['key']['key_name']);
        $long = str_repeat('k', 100);
        $b = $keys->mint($this->userId, $long, [ApiKeys::SCOPE_STATS_READ], $this->userId);
        self::assertSame(80, strlen($b['key']['key_name']));
    }
}
