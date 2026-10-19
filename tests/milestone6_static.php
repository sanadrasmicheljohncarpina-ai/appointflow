<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $relative) use ($root): string {
    $path = $root . $relative;
    if (!is_file($path)) {
        throw new RuntimeException('Missing required Milestone 6 file: ' . $relative);
    }
    $content = file_get_contents($path);
    if ($content === false) {
        throw new RuntimeException('Unable to read ' . $relative);
    }
    return $content;
};
$expect = static function (string $label, string $haystack, array $needles): void {
    foreach ($needles as $needle) {
        if (!str_contains($haystack, $needle)) {
            throw new RuntimeException($label . ' is missing expected logic: ' . $needle);
        }
    }
};

$booking = $read('/app/services/BookingService.php');
$expect('BookingService', $booking, [
    'findAppointmentByToken', 'hashSecureToken', 'issueClientToken', 'getRescheduleSlots',
    'rescheduleByToken', 'appointment.rescheduled', 'appointment.client_link_issued',
    "\\'reschedule\\'", 'GET_LOCK', 'buildSlots', 'client_token_expires_at',
]);
if (str_contains($booking, 'INSERT INTO appointments') && substr_count($booking, 'INSERT INTO appointments') !== 1) {
    throw new RuntimeException('Reschedule must update the existing appointment, not insert a new one.');
}

$appointment = $read('/app/services/AppointmentService.php');
$expect('AppointmentService', $appointment, [
    'appointment.cancelled', "\\'cancellation\\'", "status = \\'qualified\\'", 'FOR UPDATE',
]);
if (str_contains($appointment, 'DELETE FROM engagements')) {
    throw new RuntimeException('Cancelling must never delete the Engagement.');
}

$client = $read('/public_booking/appointment.php');
$expect('Client appointment page', $client, [
    'verifyCsrf', 'findAppointmentByToken', 'rescheduleByToken', 'cancel(',
    'Referrer-Policy: no-referrer', 'noindex', 'This link is invalid or has expired.',
]);
if (str_contains($client, 'requireLogin')) {
    throw new RuntimeException('The client appointment page must not require a login.');
}

$link = $read('/public/appointment_link.php');
$expect('Link endpoint', $link, ['requireLogin', 'verifyCsrf', 'issueClientToken', "flash('client_link'"]);

$engagement = $read('/public/engagement.php');
$expect('Engagement detail', $engagement, ['appointment_link.php', 'Generate client link']);

$expect('Client overview', $booking . $client, ['listClientAppointments', 'Your appointments']);
if (preg_match('/function listClientAppointments.*?\n    }\n/s', $booking, $m) && (str_contains($m[0], 'consultant') || str_contains($m[0], 'email') || str_contains($m[0], 'notes'))) {
    throw new RuntimeException('Client appointment list must not expose consultant, email or notes.');
}

// --- Friendly URLs ---------------------------------------------------------------
$htaccess = $read('/.htaccess');
$expect('.htaccess', $htaccess, [
    '^book/', 'request', 'received', '^appointment/', '^login/?$', 'public_booking/appointment.php?token=$1',
]);
$helpers = $read('/app/helpers.php');
$expect('helpers', $helpers, [
    'function bookingUrl', 'function inquiryUrl', 'function confirmationUrl',
    'function clientAppointmentUrl', 'function absoluteUrl', 'APP_PRETTY_URLS', 'APP_PUBLIC_URL',
]);
// No page may hardcode the long public URLs any more; all links go through the helpers.
foreach (['/public_booking/index.php', '/public_booking/inquiry.php', '/public_booking/confirmation.php', '/public/appointment_link.php', '/public/settings.php'] as $file) {
    $source = $read($file);
    if (preg_match('/appUrl\(\s*\'public_booking\/[a-z]+\.php\?/', $source)) {
        throw new RuntimeException($file . ' still hardcodes a long public_booking URL; use the URL helpers.');
    }
}
if (preg_match('/Location:\s*(confirmation|login)\.php/', $read('/public_booking/inquiry.php') . $read('/app/auth.php'))) {
    throw new RuntimeException('Relative redirects break friendly URLs; use absolute app URLs.');
}
$expect('Settings page', $read('/public/settings.php'), ['Public booking link', 'Copy booking link']);

$migrations = glob($root . '/database/migrations/*.sql');
if ($migrations === false || count($migrations) < 15) {
    throw new RuntimeException('Milestone 6 requires the 15 core migrations; found ' . (is_array($migrations) ? count($migrations) : 0) . '.');
}

echo "Milestone 6 static checks passed.\n";
