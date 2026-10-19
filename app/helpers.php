<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security.php';

function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function flash(string $key, ?string $value = null): ?string
{
    ensureSession();
    if ($value !== null) {
        $_SESSION['_flash'][$key] = $value;
        return null;
    }
    $message = $_SESSION['_flash'][$key] ?? null;
    unset($_SESSION['_flash'][$key]);
    return is_string($message) ? $message : null;
}

/**
 * Friendly public URLs. They rely on the rewrite rules in /.htaccess.
 * Set APP_PRETTY_URLS to false in config/app.php to fall back to the original long URLs
 * (for servers without mod_rewrite). Set APP_PUBLIC_URL (e.g. 'https://book.example.com') to
 * force the origin used in links shown to staff.
 */
function prettyUrlsEnabled(): bool
{
    return !defined('APP_PRETTY_URLS') || (bool)APP_PRETTY_URLS;
}

function bookingUrl(string $slug): string
{
    return prettyUrlsEnabled()
        ? appUrl('book/' . rawurlencode($slug))
        : appUrl('public_booking/index.php?business=' . rawurlencode($slug));
}

function inquiryUrl(string $slug, ?int $serviceId = null): string
{
    if (prettyUrlsEnabled()) {
        return appUrl('book/' . rawurlencode($slug) . '/request' . ($serviceId ? '/' . $serviceId : ''));
    }
    return appUrl('public_booking/inquiry.php?business=' . rawurlencode($slug) . ($serviceId ? '&service=' . $serviceId : ''));
}

function confirmationUrl(string $slug, int $engagementId): string
{
    return prettyUrlsEnabled()
        ? appUrl('book/' . rawurlencode($slug) . '/received/' . $engagementId)
        : appUrl('public_booking/confirmation.php?business=' . rawurlencode($slug) . '&engagement=' . $engagementId);
}

function clientAppointmentUrl(string $token): string
{
    return prettyUrlsEnabled()
        ? appUrl('appointment/' . rawurlencode($token))
        : appUrl('public_booking/appointment.php?token=' . rawurlencode($token));
}

function loginUrl(): string
{
    return prettyUrlsEnabled() ? appUrl('login') : appUrl('public/login.php');
}

/** Turn an app path into a full URL (scheme + host) for links that are copied or emailed. */
function absoluteUrl(string $path): string
{
    if (defined('APP_PUBLIC_URL') && APP_PUBLIC_URL !== '') {
        // APP_PUBLIC_URL replaces scheme+host; the app base path stays in $path.
        return rtrim((string)APP_PUBLIC_URL, '/') . $path;
    }
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    if (!preg_match('/^[A-Za-z0-9.\-]+(:\d{1,5})?$/', $host)) {
        $host = 'localhost';
    }
    return ($https ? 'https://' : 'http://') . $host . $path;
}

/**
 * True when a URL could only be opened from this machine or its private network
 * (localhost, 127.x, 192.168.x, 10.x, *.local ...), so clients on the internet cannot use it.
 */
function isLocalOnlyUrl(string $url): bool
{
    $host = strtolower(trim((string)parse_url($url, PHP_URL_HOST), '[]'));
    if ($host === '') {
        return true;
    }
    if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
        return true;
    }
    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
    return false;
}

function findAppointmentsUrl(string $slug): string
{
    return prettyUrlsEnabled()
        ? appUrl('book/' . rawurlencode($slug) . '/my-appointments')
        : appUrl('public_booking/find.php?business=' . rawurlencode($slug));
}

/** Format a stored workspace-local DATETIME string for display (no timezone conversion needed). */
function formatLocalDateTime(?string $value, DateTimeZone $timezone, string $format = 'M j, Y g:i A'): string
{
    if ($value === null || $value === '') {
        return '—';
    }
    try {
        return (new DateTimeImmutable($value, $timezone))->format($format);
    } catch (Throwable) {
        return '—';
    }
}

function personName(?string $first, ?string $last, string $fallback = '—'): string
{
    $name = trim((string)$first . ' ' . (string)$last);
    return $name !== '' ? $name : $fallback;
}

/** Link to an engagement page, optionally to one of its sections (appointment, preparation, meeting, follow-up). */
function engagementUrl(int $engagementId, string $section = ''): string
{
    return appUrl('public/engagement.php?id=' . $engagementId) . ($section !== '' ? '#' . $section : '');
}
