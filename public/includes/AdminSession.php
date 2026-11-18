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
        require_once __DIR__ . '/https_request.php';
        session_name(self::COOKIE_NAME);
        session_start([
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax',
            'cookie_secure' => dsc_session_cookie_secure(),
        ]);
    }
}
