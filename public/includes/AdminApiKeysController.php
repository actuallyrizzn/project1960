<?php
declare(strict_types=1);

namespace Project1960;

use InvalidArgumentException;
use PDO;

/**
 * Admin API keys UI + JSON API handlers (AD-A4).
 */
final class AdminApiKeysController
{
    public function __construct(
        private PDO $pdo,
        private View $view = new View(),
    ) {
    }

    public function pageGet(?string $plaintextOnce = null, ?string $flash = null, ?string $error = null): Response
    {
        return AdminShell::renderPage($this->view, 'admin/api_keys', [
            'title' => 'API Keys',
            'currentPath' => '/admin/api-keys',
            'adminUser' => (new AdminAuth($this->pdo))->user(),
            'keys' => (new ApiKeys($this->pdo))->listAll(true),
            'allScopes' => ApiKeys::ALL_SCOPES,
            'plaintextOnce' => $plaintextOnce,
            'flash' => $flash,
            'error' => $error,
        ]);
    }

    /** @param array<string, mixed> $post */
    public function pagePost(array $post): Response
    {
        if (!Csrf::verify(Csrf::tokenFromRequest($post, []))) {
            return $this->pageGet(null, null, 'Invalid security token.');
        }
        $keys = new ApiKeys($this->pdo);
        $uid = (new AdminAuth($this->pdo))->userId();
        if ($uid === null) {
            return new Response('', 302, ['Location' => '/admin/login']);
        }
        try {
            $action = (string) ($post['action'] ?? '');
            if ($action === 'mint') {
                $scopesRaw = $post['scopes'] ?? [];
                $scopes = is_array($scopesRaw) ? array_map('strval', $scopesRaw) : null;
                if ($scopes === []) {
                    $scopes = null;
                }
                $minted = $keys->mint(
                    $uid,
                    (string) ($post['key_name'] ?? 'admin'),
                    $scopes,
                    $uid
                );

                return $this->pageGet(
                    $minted['plaintext'],
                    'Key minted — copy it now; it will not be shown again.'
                );
            }
            if ($action === 'revoke') {
                $ok = $keys->revoke((int) ($post['key_id'] ?? 0));
                if (!$ok) {
                    return $this->pageGet(null, null, 'Key not found.');
                }

                return $this->pageGet(null, 'Key revoked.');
            }

            return $this->pageGet(null, null, 'Unknown action.');
        } catch (InvalidArgumentException $e) {
            return $this->pageGet(null, null, $e->getMessage());
        }
    }

    public function apiList(): Response
    {
        return Response::json(['keys' => (new ApiKeys($this->pdo))->listAll(true)]);
    }

    /**
     * @param array<string, mixed> $body
     */
    public function apiMint(array $body, int $actorUserId): Response
    {
        try {
            $targetUserId = isset($body['user_id']) ? (int) $body['user_id'] : $actorUserId;
            if ($targetUserId <= 0) {
                $targetUserId = $actorUserId;
            }
            $scopes = $body['scopes'] ?? null;
            if (is_array($scopes)) {
                $scopes = array_map('strval', $scopes);
            } else {
                $scopes = null;
            }
            $minted = (new ApiKeys($this->pdo))->mint(
                $targetUserId,
                (string) ($body['key_name'] ?? 'api'),
                $scopes,
                $actorUserId
            );

            return Response::json([
                'plaintext' => $minted['plaintext'],
                'key' => $minted['key'],
            ], 201);
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        }
    }

    public function apiRevoke(int $keyId): Response
    {
        $ok = (new ApiKeys($this->pdo))->revoke($keyId);
        if (!$ok) {
            return Response::json(['error' => 'not found'], 404);
        }

        return Response::json(['revoked' => true]);
    }
}
