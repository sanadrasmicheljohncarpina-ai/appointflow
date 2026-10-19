<?php
declare(strict_types=1);

/**
 * Sends reminder emails for appointments starting within the next 24 hours.
 * Run from the command line every 10-15 minutes.
 *
 * Windows (Task Scheduler), program "php", arguments:
 *   C:\xampp\htdocs\appointflow\cron\send_reminders.php
 *
 * Linux/macOS (crontab), every 15 minutes: minute field "star slash 15", then four stars, then
 *   php /path/to/appointflow/cron/send_reminders.php
 * (The literal crontab expression is not written here: a star followed by a slash would
 * close this comment block and break the script.)
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Command line only.');
}

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/database.php';
require_once __DIR__ . '/../app/security.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/services/NotificationService.php';

const REMINDER_WINDOW_HOURS = 24;

$pdo = db();
$notifications = new NotificationService($pdo);

$stmt = $pdo->prepare(
    'SELECT a.id, e.workspace_id, a.starts_at, w.timezone
     FROM appointments a
     INNER JOIN engagements e ON e.id = a.engagement_id
     INNER JOIN workspaces w ON w.id = e.workspace_id AND w.status = \'active\'
     WHERE a.status = \'scheduled\'
       AND a.reminder_sent_at IS NULL
       AND a.starts_at >= ? AND a.starts_at <= ?
       AND NOT EXISTS (
            SELECT 1 FROM notification_log n
            WHERE n.appointment_id = a.id AND n.notification_type = \'reminder\'
              AND n.created_at > (NOW() - INTERVAL 1 HOUR)
       )
     ORDER BY a.starts_at ASC
     LIMIT 200'
);
$stmt->execute([date('Y-m-d H:i:s', time() - 86400), date('Y-m-d H:i:s', time() + 3 * 86400)]);

$sent = 0;
$failed = 0;
foreach ($stmt->fetchAll() as $row) {
    $tz = workspaceTimezone((string)$row['timezone']);
    $now = new DateTimeImmutable('now', $tz);
    $start = new DateTimeImmutable((string)$row['starts_at'], $tz);
    if ($start <= $now || $start > $now->modify('+' . REMINDER_WINDOW_HOURS . ' hours')) {
        continue;
    }
    $result = $notifications->dispatch((int)$row['workspace_id'], (int)$row['id'], 'reminder');
    $result['ok'] ? $sent++ : $failed++;
}

echo date('c') . " reminders sent: {$sent}, failed: {$failed} (mail driver: " . $notifications->driver() . ")\n";
