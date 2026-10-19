<?php
declare(strict_types=1);

/** Usage: php scripts/send_test_email.php you@example.com  — checks your config/mail.php settings. */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Command line only.');
}
require_once __DIR__ . '/../app/services/MailService.php';

$to = $argv[1] ?? '';
if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php scripts/send_test_email.php you@example.com\n");
    exit(1);
}
$mailer = new MailService();
echo 'Mail driver: ' . $mailer->driver() . "\n";
try {
    $mailer->send(
        $to,
        '',
        'AppointFlow test email',
        '<p>If you can read this, AppointFlow email delivery works.</p>',
        "If you can read this, AppointFlow email delivery works.\n",
        null,
        'AppointFlow',
        'no-reply@localhost.localdomain'
    );
    echo $mailer->driver() === 'log'
        ? "Saved to storage/mail/ (test mode, nothing was sent). Set driver to 'smtp' in config/mail.php to send for real.\n"
        : "Sent to {$to}.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}
