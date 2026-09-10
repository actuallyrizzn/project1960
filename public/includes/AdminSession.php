<?php
declare(strict_types=1);

namespace Project1960;

/** Shared admin session bootstrap (name must match AdminAuth + Csrf). */
final class AdminSession
{
    public const COOKIE_NAME = 'p1960_admin';

    public static function start(): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }
        session_name(self::COOKIE_NAME);
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443')
            || (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
        session_start([
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax',
            'cookie_secure' => $secure,
        ]);
    }
}
