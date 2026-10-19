<?php
declare(strict_types=1);

function ensureSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_name('APPFLOWSESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function csrfToken(): string
{
    ensureSession();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['csrf_token'];
}

function verifyCsrf(?string $token): void
{
    ensureSession();
    $expected = (string)($_SESSION['csrf_token'] ?? '');
    if ($expected === '' || !is_string($token) || !hash_equals($expected, $token)) {
        throw new RuntimeException('Invalid security token. Please refresh the page and try again.');
    }
}

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function generateSecureToken(int $bytes = 32): string
{
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

function hashSecureToken(string $token): string
{
    return hash('sha256', $token);
}
