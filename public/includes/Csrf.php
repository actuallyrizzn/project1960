<?php
declare(strict_types=1);

namespace Project1960;

/**
 * CSRF helpers for admin forms — Tasks/Environment pattern.
 */
final class Csrf
{
    private const SESSION_KEY = 'csrf_token';

    public static function token(): string
    {
        self::ensureSession();
        if (empty($_SESSION[self::SESSION_KEY]) || !is_string($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::SESSION_KEY];
    }

    public static function verify(?string $token): bool
    {
        self::ensureSession();
        if (empty($_SESSION[self::SESSION_KEY]) || !is_string($_SESSION[self::SESSION_KEY])) {
            return false;
        }
        if ($token === null || $token === '') {
            return false;
        }

        return hash_equals($_SESSION[self::SESSION_KEY], $token);
    }

    public static function inputField(): string
    {
        return '<input type="hidden" name="csrf_token" value="'
            . htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8')
            . '">';
    }

    /** Prefer POST body, then X-CSRF-Token header. */
    public static function tokenFromRequest(array $post, array $server): ?string
    {
        if (isset($post['csrf_token']) && is_string($post['csrf_token'])) {
            return $post['csrf_token'];
        }
        $header = $server['HTTP_X_CSRF_TOKEN'] ?? null;

        return is_string($header) ? $header : null;
    }

    public static function requireValid(?string $token): void
    {
        if (!self::verify($token)) {
            throw new \RuntimeException('CSRF validation failed');
        }
    }

    private static function ensureSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start([
                'cookie_httponly' => true,
                'cookie_samesite' => 'Lax',
            ]);
        }
    }
}
