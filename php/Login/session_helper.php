<?php

const MAP_PUMP_REMEMBER_SECONDS = 2592000;
const MAP_PUMP_REMEMBER_EMAIL_COOKIE = 'map_pump_remember_email';
const MAP_PUMP_LOGIN_PREFILL_EMAIL_COOKIE = 'map_pump_login_prefill_email';

function map_pump_secure_cookie(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
}

function map_pump_configure_session(bool $remember = false): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $lifetime = $remember ? MAP_PUMP_REMEMBER_SECONDS : 0;
    ini_set('session.gc_maxlifetime', (string) ($remember ? MAP_PUMP_REMEMBER_SECONDS : 1440));
    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path' => '/',
        'domain' => '',
        'secure' => map_pump_secure_cookie(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function map_pump_set_remembered_email(string $email, bool $remember): void
{
    $expires = $remember ? time() + MAP_PUMP_REMEMBER_SECONDS : time() - 3600;
    setcookie(MAP_PUMP_REMEMBER_EMAIL_COOKIE, $remember ? $email : '', [
        'expires' => $expires,
        'path' => '/',
        'domain' => '',
        'secure' => map_pump_secure_cookie(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function map_pump_clear_session_cookie(): void
{
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'] ?: '/',
            'domain' => $params['domain'] ?? '',
            'secure' => (bool) ($params['secure'] ?? map_pump_secure_cookie()),
            'httponly' => (bool) ($params['httponly'] ?? true),
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }
}

function map_pump_destroy_login_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        map_pump_configure_session();
        session_start();
    }

    $_SESSION = [];
    map_pump_clear_session_cookie();
    session_unset();
    session_destroy();
}
