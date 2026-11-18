<?php
/**
 * DSC / SanctumOS — HTTPS request detection for Secure session cookies.
 *
 * Multihost php.ini defaults session.cookie_secure=0. Apps that call session_start()
 * without Secure on HTTPS emit cookies mobile Safari/Chrome often drop, which breaks
 * CSRF login ("Invalid security token") while desktop still works.
 */
if (!function_exists('dsc_request_is_https')) {
    function dsc_request_is_https(): bool
    {
        $https = $_SERVER['HTTPS'] ?? '';
        if ($https !== '' && strtolower((string) $https) !== 'off') {
            return true;
        }
        if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
            return true;
        }
        if (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
            return true;
        }

        return false;
    }
}

if (!function_exists('dsc_session_cookie_secure')) {
    function dsc_session_cookie_secure(): bool
    {
        if (dsc_request_is_https()) {
            return true;
        }
        foreach (['SITE_URL', 'APP_URL'] as $const) {
            if (defined($const) && strpos((string) constant($const), 'https://') === 0) {
                return true;
            }
        }

        return false;
    }
}
