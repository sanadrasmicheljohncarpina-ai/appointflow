<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';

function appTimezone(): DateTimeZone
{
    static $timezone;
    if ($timezone instanceof DateTimeZone) {
        return $timezone;
    }

    $configured = getenv('APP_TIMEZONE');
    $name = is_string($configured) && trim($configured) !== ''
        ? trim($configured)
        : APP_TIMEZONE_DEFAULT;

    try {
        $timezone = new DateTimeZone($name);
    } catch (Throwable) {
        $timezone = new DateTimeZone(APP_TIMEZONE_DEFAULT);
    }

    date_default_timezone_set($timezone->getName());
    return $timezone;
}

function workspaceTimezone(string $timezone): DateTimeZone
{
    try {
        return new DateTimeZone($timezone);
    } catch (Throwable) {
        return new DateTimeZone(APP_TIMEZONE_DEFAULT);
    }
}

function appUrl(string $path = ''): string
{
    $base = rtrim(APP_BASE_PATH, '/');
    $path = '/' . ltrim($path, '/');
    return $base . $path;
}

appTimezone();
