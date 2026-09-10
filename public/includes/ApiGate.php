<?php
declare(strict_types=1);

namespace Project1960;

use InvalidArgumentException;
use PDO;

/**
 * API access policy (AD-S5 / AD-R2 lean):
 * - Public explorer JSON stays anonymous-OK by default.
 * - If a key is presented, it must be valid and carry the route scope.
 * - Admin JSON always requires a valid key with the given scope.
 * - Set P1960_API_REQUIRE_KEY=1 to require keys on public routes too.
 */
final class ApiGate
{
    public function __construct(
        private PDO $pdo,
        private bool $requireKeyForPublic = false,
    ) {
    }

    public static function fromEnv(PDO $pdo, array $env = []): self
    {
        $raw = $env['P1960_API_REQUIRE_KEY'] ?? getenv('P1960_API_REQUIRE_KEY') ?: '';
        $require = is_string($raw) && in_array(strtolower($raw), ['1', 'true', 'yes'], true);

        return new self($pdo, $require);
    }

    /** @return Response|null Null means allow. */
    public function authorizePublic(string $path, array $server): ?Response
    {
        $scope = $this->scopeForPath($path);
        $keys = new ApiKeys($this->pdo);
        $presented = $this->headerPresent($server);
        $row = $keys->authenticateFromHeaders($server);

        if ($row === null) {
            if ($presented) {
                return Response::json(['error' => 'Invalid API key'], 401);
            }
            if ($this->requireKeyForPublic) {
                return Response::json(['error' => 'API key required'], 401);
            }

            return null;
        }

        try {
            $keys->requireScope($row, $scope);
        } catch (InvalidArgumentException) {
            return Response::json(['error' => 'Forbidden', 'missing_scope' => $scope], 403);
        }

        return null;
    }

    /** @return Response|null Null means allow. */
    public function authorizeAdmin(string $scope, array $server): ?Response
    {
        $keys = new ApiKeys($this->pdo);
        $presented = $this->headerPresent($server);
        $row = $keys->authenticateFromHeaders($server);
        if ($row === null) {
            return Response::json([
                'error' => $presented ? 'Invalid API key' : 'API key required',
            ], 401);
        }
        try {
            $keys->requireScope($row, $scope);
        } catch (InvalidArgumentException) {
            return Response::json(['error' => 'Forbidden', 'missing_scope' => $scope], 403);
        }

        return null;
    }

    public function scopeForPath(string $path): string
    {
        if (str_starts_with($path, '/api/enrichment')) {
            return ApiKeys::SCOPE_ENRICHMENT_READ;
        }
        if (str_starts_with($path, '/api/cases')) {
            return ApiKeys::SCOPE_CASES_READ;
        }
        if (str_starts_with($path, '/api/patterns')) {
            return ApiKeys::SCOPE_PATTERNS_READ;
        }
        if (str_starts_with($path, '/api/stats')) {
            return ApiKeys::SCOPE_STATS_READ;
        }

        return ApiKeys::SCOPE_STATS_READ;
    }

    private function headerPresent(array $server): bool
    {
        if (!empty($server['HTTP_X_API_KEY'])) {
            return true;
        }
        $auth = $server['HTTP_AUTHORIZATION'] ?? '';

        return is_string($auth) && str_starts_with($auth, 'Bearer ');
    }
}
