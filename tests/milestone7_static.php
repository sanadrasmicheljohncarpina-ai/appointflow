<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $relative) use ($root): string {
    $path = $root . $relative;
    if (!is_file($path)) {
        throw new RuntimeException('Missing required Milestone 7 file: ' . $relative);
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

$mail = $read('/app/services/MailService.php');
$expect('MailService', $mail, ['storage/mail', 'STARTTLS', 'AUTH LOGIN', 'DATA', 'FILTER_VALIDATE_EMAIL', 'cleanHeader']);

$notify = $read('/app/services/NotificationService.php');
$expect('NotificationService', $notify, [
    'function dispatch', "'confirmation'", "'reschedule'", "'cancellation'", "'reminder'", "'link'",
    'confirmation_sent_at', 'reminder_sent_at', "status = \\'failed\\'", 'catch (Throwable',
]);

$booking = $read('/app/services/BookingService.php');
$expect('BookingService', $booking, ['reissueClientLinkForEmail', 'client_link_reissued', 'INTERVAL 1 HOUR']);

$find = $read('/public_booking/find.php');
$expect('Find page', $find, ['verifyCsrf', 'reissueClientLinkForEmail', 'If we found appointments']);
if (str_contains($find, 'requireLogin')) {
    throw new RuntimeException('The find-my-appointments page must not require a login.');
}

$expect('Staff booking page', $read('/public/booking.php'), ["dispatch(", "'confirmation'"]);
$client = $read('/public_booking/appointment.php');
$expect('Client page', $client, ["'reschedule', \$token", "'cancellation', \$token"]);
$expect('Reminders cron', $read('/cron/send_reminders.php'), ["PHP_SAPI !== 'cli'", 'reminder', 'dispatch(']);
$expect('Mail config example', $read('/config/mail.example.php'), ["'driver'", 'smtp', 'log']);

$htaccess = $read('/.htaccess');
$expect('.htaccess', $htaccess, ['my-appointments', '^(app|config|database|storage|scripts|cron|tests)']);
$expect('helpers', $read('/app/helpers.php'), ['function findAppointmentsUrl']);

$migrations = glob($root . '/database/migrations/*.sql');
if ($migrations === false || count($migrations) < 15) {
    throw new RuntimeException('Milestone 7 requires the 15 core migrations; found ' . (is_array($migrations) ? count($migrations) : 0) . '.');
}

echo "Milestone 7 static checks passed.\n";
