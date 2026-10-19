<?php
declare(strict_types=1);

/**
 * Regression test: AppointmentService::cancel() must be scoped to the caller's workspace.
 *
 *   php tests/cancel_workspace_scope.php        static checks only (no database needed)
 *   php tests/cancel_workspace_scope.php --db   also runs the cross-workspace test against the
 *                                               configured MySQL database. It creates two throwaway
 *                                               workspaces, then deletes everything it created.
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
$service = $read('/app/services/AppointmentService.php');
$assert(
    str_contains($service, 'public function cancel(int $workspaceId, int $appointmentId, ?int $actorUserId'),
    'cancel() must take $workspaceId as its first argument.'
);
$start = strpos($service, 'public function cancel(');
$end = strpos($service, 'public function complete(');
$assert($start !== false && $end !== false && $end > $start, 'Could not isolate cancel().');
$cancelBody = substr($service, $start, $end - $start);
$assert(
    str_contains($cancelBody, 'WHERE a.id = ? AND e.workspace_id = ?'),
    'cancel() lookup must be constrained by e.workspace_id.'
);
$assert(
    str_contains($cancelBody, '$stmt->execute([$appointmentId, $workspaceId]);'),
    'cancel() lookup must bind the workspace id.'
);
$assert(
    !str_contains($cancelBody, "(int)\$appointment['workspace_id']"),
    'cancel() must use the caller-supplied workspace id, not one read back from the row.'
);

$staff = $read('/public/engagement_workflow.php');
$assert(
    str_contains($staff, '->cancel($workspaceId, $appointmentId, $userId, $reason)'),
    'Staff endpoint must pass the workspace id to cancel().'
);
$client = $read('/public_booking/appointment.php');
$assert(
    str_contains($client, "->cancel((int)\$appt['workspace_id'], (int)\$appt['appointment_id'], null, \$reason)"),
    'Client appointment page must pass the appointment\'s workspace id to cancel().'
);

echo "Static cancel-scope checks passed.\n";

if (!in_array('--db', $argv ?? [], true)) {
    echo "Skipped database test (run with --db to include it).\n";
    exit(0);
}

// ---- Database test -------------------------------------------------------------------------
require_once $root . '/app/database.php';
require_once $root . '/app/services/AppointmentService.php';

$pdo = db();
$workspaceIds = [];
$suffix = bin2hex(random_bytes(4));

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
    $svc = new AppointmentService($pdo);

    // 1. Workspace B tries to cancel workspace A's appointment (staff and client-style actors).
    foreach ([$b['user_id'], null] as $actor) {
        $blocked = false;
        try {
            $svc->cancel($b['workspace_id'], $a['appointment_id'], $actor, 'cross-workspace attempt');
        } catch (RuntimeException $e) {
            $blocked = $e->getMessage() === 'Appointment not found.';
        }
        $assert($blocked, 'Cross-workspace cancel must throw "Appointment not found."');
    }
    $assert(
        $count('SELECT COUNT(*) FROM appointments WHERE id = ? AND status = \'scheduled\'', [$a['appointment_id']]) === 1,
        'Workspace A appointment must still be scheduled after a cross-workspace cancel attempt.'
    );
    $assert(
        $count('SELECT COUNT(*) FROM engagements WHERE id = ? AND status = \'scheduled\'', [$a['engagement_id']]) === 1,
        'Workspace A engagement status must be unchanged.'
    );
    $assert(
        $count('SELECT COUNT(*) FROM notification_log WHERE appointment_id = ?', [$a['appointment_id']]) === 0,
        'No notification may be queued by a blocked cancel.'
    );
    $assert(
        $count('SELECT COUNT(*) FROM engagement_events WHERE engagement_id = ?', [$a['engagement_id']]) === 0,
        'No engagement event may be written by a blocked cancel.'
    );

    // 2. The owning workspace can still cancel it.
    $svc->cancel($a['workspace_id'], $a['appointment_id'], $a['user_id'], 'legitimate cancel');
    $assert(
        $count('SELECT COUNT(*) FROM appointments WHERE id = ? AND status = \'cancelled\'', [$a['appointment_id']]) === 1,
        'Owning workspace cancel must mark the appointment cancelled.'
    );
    $assert(
        $count('SELECT COUNT(*) FROM engagements WHERE id = ? AND status = \'qualified\'', [$a['engagement_id']]) === 1,
        'Engagement must return to qualified after a legitimate cancel.'
    );
    $assert(
        $count('SELECT COUNT(*) FROM notification_log WHERE appointment_id = ? AND workspace_id = ? AND notification_type = \'cancellation\'', [$a['appointment_id'], $a['workspace_id']]) === 1,
        'One cancellation notification must be queued under the owning workspace.'
    );

    // 3. Workspace B's own appointment is unaffected throughout.
    $assert(
        $count('SELECT COUNT(*) FROM appointments WHERE id = ? AND status = \'scheduled\'', [$b['appointment_id']]) === 1,
        'Workspace B appointment must be untouched.'
    );

    echo "Database cross-workspace cancel test passed.\n";
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
}
