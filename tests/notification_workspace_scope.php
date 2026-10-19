<?php
declare(strict_types=1);

/**
 * Regression test: NotificationService::dispatch() must be scoped to the caller's workspace.
 *
 *   php tests/notification_workspace_scope.php        static checks only
 *   php tests/notification_workspace_scope.php --db   also runs the cross-workspace test against the
 *                                                     configured database (throwaway workspaces,
 *                                                     removed afterwards). Uses the log mail driver.
 */

$root = dirname(__DIR__);
$read = static function (string $relative) use ($root): string {
    $content = @file_get_contents($root . $relative);
    if ($content === false) {
        throw new RuntimeException('Unable to read ' . $relative);
    }
    return $content;
};
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
};

// ---- Static checks -------------------------------------------------------------------------
$svc = $read('/app/services/NotificationService.php');
$assert(str_contains($svc, 'public function dispatch(int $workspaceId, int $appointmentId, string $type'), 'dispatch() must take $workspaceId first.');
$assert(str_contains($svc, 'private function loadContext(int $workspaceId, int $appointmentId)'), 'loadContext() must take $workspaceId.');
$assert(str_contains($svc, 'WHERE a.id = ? AND e.workspace_id = ?'), 'loadContext() lookup must be constrained by e.workspace_id.');
$assert(str_contains($svc, '$stmt->execute([$appointmentId, $workspaceId]);'), 'loadContext() must bind the workspace id.');

$callers = [
    '/public/appointment_link.php' => '->dispatch($workspaceId, (int)$appointment[\'id\']',
    '/public/engagement_workflow.php' => '->dispatch($workspaceId, $appointmentId, \'cancellation\')',
    '/public/booking.php' => '->dispatch($workspaceId, (int)$result[\'appointment_id\']',
    '/cron/send_reminders.php' => '->dispatch((int)$row[\'workspace_id\'], (int)$row[\'id\']',
    '/public_booking/find.php' => '->dispatch((int)$workspace[\'id\'], (int)$found[\'appointment_id\']',
    '/public_booking/appointment.php' => '->dispatch((int)$appt[\'workspace_id\'], (int)$appt[\'appointment_id\']',
];
foreach ($callers as $file => $needle) {
    $assert(str_contains($read($file), $needle), $file . ' must pass the workspace id to dispatch().');
}

echo "Static notification-scope checks passed.\n";

if (!in_array('--db', $argv ?? [], true)) {
    echo "Skipped database test (run with --db to include it).\n";
    exit(0);
}

// ---- Database test -------------------------------------------------------------------------
require_once $root . '/app/database.php';
require_once $root . '/app/security.php';
require_once $root . '/app/helpers.php';
require_once $root . '/app/services/NotificationService.php';

$pdo = db();
$workspaceIds = [];
$suffix = bin2hex(random_bytes(4));
$mailDir = $root . '/storage/mail';
$mailBefore = glob($mailDir . '/*') ?: [];

$makeWorkspace = static function (string $label) use ($pdo, $suffix, &$workspaceIds): array {
    $pdo->prepare('INSERT INTO workspaces (name, email, booking_slug) VALUES (?, ?, ?)')
        ->execute(['Scope test ' . $label, $label . '-' . $suffix . '@example.test', 'scope-test-' . $label . '-' . $suffix]);
    $workspaceId = (int)$pdo->lastInsertId();
    $workspaceIds[] = $workspaceId;

    $pdo->prepare('INSERT INTO users (workspace_id, first_name, last_name, email, password_hash, role) VALUES (?, ?, ?, ?, ?, \'owner\')')
        ->execute([$workspaceId, 'Test', $label, 'owner-' . $label . '-' . $suffix . '@example.test', 'x']);
    $userId = (int)$pdo->lastInsertId();

    $pdo->prepare('INSERT INTO clients (workspace_id, first_name, last_name, email) VALUES (?, ?, ?, ?)')
        ->execute([$workspaceId, 'Client', $label, 'client-' . $label . '-' . $suffix . '@example.test']);
    $clientId = (int)$pdo->lastInsertId();

    $pdo->prepare('INSERT INTO services (workspace_id, name, duration_minutes) VALUES (?, ?, 30)')
        ->execute([$workspaceId, 'Scope test service']);
    $serviceId = (int)$pdo->lastInsertId();

    $pdo->prepare(
        'INSERT INTO engagements (workspace_id, client_id, service_id, assigned_user_id, title, problem_summary, status)
         VALUES (?, ?, ?, ?, ?, ?, \'scheduled\')'
    )->execute([$workspaceId, $clientId, $serviceId, $userId, 'Scope test engagement', 'Scope test']);
    $engagementId = (int)$pdo->lastInsertId();

    $pdo->prepare(
        'INSERT INTO appointments (engagement_id, assigned_user_id, starts_at, ends_at, status, client_token_hash)
         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY), DATE_ADD(NOW(), INTERVAL 7 DAY), \'scheduled\', ?)'
    )->execute([$engagementId, $userId, hash('sha256', 'scope-test-' . $label . '-' . $suffix)]);

    return [
        'workspace_id' => $workspaceId,
        'user_id' => $userId,
        'engagement_id' => $engagementId,
        'appointment_id' => (int)$pdo->lastInsertId(),
    ];
};

$count = static function (string $sql, array $params) use ($pdo): int {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
};

try {
    $a = $makeWorkspace('a');
    $b = $makeWorkspace('b');
    $notify = new NotificationService($pdo, new MailService([
        'driver' => 'log', 'host' => '', 'port' => 587, 'encryption' => 'tls', 'username' => '', 'password' => '',
        'from_email' => 'noreply@example.test', 'from_name' => 'AppointFlow test', 'timeout' => 15,
        'verify_peer' => true, 'base_url' => 'http://localhost',
    ]));

    // 1. Workspace B tries to send mail for workspace A's appointment.
    foreach (['cancellation', 'confirmation', 'reminder'] as $type) {
        $result = $notify->dispatch($b['workspace_id'], $a['appointment_id'], $type);
        $assert($result['ok'] === false, 'Cross-workspace dispatch (' . $type . ') must fail.');
        $assert($result['to'] === '', 'Cross-workspace dispatch must not resolve the recipient.');
    }
    $assert(
        $count('SELECT COUNT(*) FROM notification_log WHERE appointment_id = ?', [$a['appointment_id']]) === 0,
        'A blocked dispatch must not create notification_log rows.'
    );
    $assert(
        $count('SELECT COUNT(*) FROM notification_log WHERE workspace_id = ?', [$b['workspace_id']]) === 0,
        'A blocked dispatch must not log anything under the caller workspace.'
    );
    $assert(
        $count('SELECT COUNT(*) FROM appointments WHERE id = ? AND confirmation_sent_at IS NULL AND reminder_sent_at IS NULL', [$a['appointment_id']]) === 1,
        'A blocked dispatch must not mark the appointment as notified.'
    );

    // 2. The owning workspace can still dispatch.
    $result = $notify->dispatch($a['workspace_id'], $a['appointment_id'], 'confirmation');
    $assert($result['ok'] === true, 'Owning workspace dispatch must succeed.');
    $assert(
        $count('SELECT COUNT(*) FROM notification_log WHERE appointment_id = ? AND workspace_id = ? AND status = \'sent\'', [$a['appointment_id'], $a['workspace_id']]) === 1,
        'Owning workspace dispatch must log one sent notification under its own workspace.'
    );

    echo "Database cross-workspace notification test passed.\n";
} finally {
    foreach ($workspaceIds as $id) {
        $pdo->prepare('DELETE FROM notification_log WHERE workspace_id = ?')->execute([$id]);
        $pdo->prepare('DELETE ev FROM engagement_events ev INNER JOIN engagements e ON e.id = ev.engagement_id WHERE e.workspace_id = ?')->execute([$id]);
        $pdo->prepare('DELETE ap FROM appointments ap INNER JOIN engagements e ON e.id = ap.engagement_id WHERE e.workspace_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM engagements WHERE workspace_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM services WHERE workspace_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM clients WHERE workspace_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM users WHERE workspace_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM workspaces WHERE id = ?')->execute([$id]);
    }
    foreach (array_diff(glob($mailDir . '/*') ?: [], $mailBefore) as $file) {
        if (is_file($file) && basename($file) !== '.gitkeep') {
            @unlink($file);
        }
    }
}
