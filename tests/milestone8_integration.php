<?php
declare(strict_types=1);

/**
 * Milestone 8 integration test — runs the real services against a real MySQL/MariaDB database.
 *
 * OPTIONAL and DESTRUCTIVE to the target database: it wipes and re-creates every table row it
 * touches. It therefore refuses to run unless the database name ends in "_test".
 *
 *   1. mysql -u root -e "CREATE DATABASE appointflow_test CHARACTER SET utf8mb4"
 *   2. for f in database/migrations/*.sql; do mysql -u root appointflow_test < $f; done
 *   3. AF_TEST_DB=appointflow_test php tests/milestone8_integration.php
 *
 * Optional env vars: AF_TEST_HOST (127.0.0.1), AF_TEST_USER (root), AF_TEST_PASS ('').
 */
if (PHP_SAPI !== 'cli') { exit("CLI only.\n"); }
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/services/EngagementService.php';
require_once __DIR__ . '/../app/services/ConsultationService.php';
require_once __DIR__ . '/../app/services/FollowUpService.php';

$dbName = (string)(getenv('AF_TEST_DB') ?: '');
if ($dbName === '' || !str_ends_with($dbName, '_test')) {
    fwrite(STDERR, "Set AF_TEST_DB to a scratch database whose name ends in _test.\n");
    exit(2);
}
$pdo = new PDO(
    'mysql:host=' . (getenv('AF_TEST_HOST') ?: '127.0.0.1') . ';dbname=' . $dbName . ';charset=utf8mb4',
    (string)(getenv('AF_TEST_USER') ?: 'root'),
    (string)(getenv('AF_TEST_PASS') ?: ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
);

$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach (['client_link_requests', 'notification_log', 'engagement_events', 'follow_ups', 'meeting_notes', 'preparation_tasks', 'appointments', 'engagements', 'clients', 'users', 'workspaces'] as $table) {
    $pdo->exec('DELETE FROM ' . $table);
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

$passed = 0;
$check = static function (string $label, bool $ok) use (&$passed): void {
    if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    $passed++;
};
$throws = static function (string $label, string $class, callable $fn, ?string $contains = null) use ($check): void {
    try { $fn(); } catch (Throwable $e) {
        $check($label . ' (threw ' . get_class($e) . ': ' . $e->getMessage() . ')', $e instanceof $class && ($contains === null || str_contains($e->getMessage(), $contains)));
        return;
    }
    $check($label . ' (expected an exception)', false);
};
$insert = static function (string $sql, array $params) use ($pdo): int {
    $pdo->prepare($sql)->execute($params);
    return (int)$pdo->lastInsertId();
};

$tz = new DateTimeZone('Asia/Manila');
$now = new DateTimeImmutable('now', $tz);
$fmt = static fn(DateTimeImmutable $d): string => $d->format('Y-m-d H:i:s');

// ---- seed: two workspaces so tenant isolation can be checked
$ws = $insert("INSERT INTO workspaces (name, email, booking_slug, timezone) VALUES ('Northstar', 'n@example.com', 'northstar', 'Asia/Manila')", []);
$ws2 = $insert("INSERT INTO workspaces (name, email, booking_slug, timezone) VALUES ('Other', 'o@example.com', 'other', 'Asia/Manila')", []);
$userSql = "INSERT INTO users (workspace_id, first_name, last_name, email, password_hash, role) VALUES (?, ?, ?, ?, 'x', ?)";
$owner = $insert($userSql, [$ws, 'Maya', 'Owner', 'maya@example.com', 'owner']);
$consultant = $insert($userSql, [$ws, 'Carl', 'Consultant', 'carl@example.com', 'consultant']);
$otherOwner = $insert($userSql, [$ws2, 'Olga', 'Other', 'olga@example.com', 'owner']);
$client = $insert("INSERT INTO clients (workspace_id, first_name, last_name, email) VALUES (?, 'Cora', 'Client', 'cora@example.com')", [$ws]);
$newEngagement = static fn(string $status) => $insert(
    "INSERT INTO engagements (workspace_id, client_id, assigned_user_id, title, problem_summary, status) VALUES (?, ?, ?, 'Northstar case', 'Needs help', ?)",
    [$ws, $client, $consultant, $status]
);
$newAppointment = static fn(int $engagementId, DateTimeImmutable $start) => $insert(
    "INSERT INTO appointments (engagement_id, assigned_user_id, starts_at, ends_at, client_token_hash) VALUES (?, ?, ?, ?, 'hash')",
    [$engagementId, $GLOBALS['consultant'], $GLOBALS['fmt']($start), $GLOBALS['fmt']($start->modify('+1 hour'))]
);
$status = static fn(int $id): string => (string)$pdo->query("SELECT status FROM engagements WHERE id = $id")->fetchColumn();
$events = static fn(int $id): array => $pdo->query("SELECT event_type FROM engagement_events WHERE engagement_id = $id ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);

$consult = new ConsultationService($pdo);
$follow = new FollowUpService($pdo);

// ---- preparation checklist
$e1 = $newEngagement('new');
$throws('prep on a new inquiry is rejected', InvalidArgumentException::class, fn() => $consult->addPreparationTask($ws, $e1, $owner, 'Too early', '', ''));
$pdo->exec("UPDATE engagements SET status = 'qualified' WHERE id = $e1");
$check('starter checklist adds 4 tasks', $consult->addStarterChecklist($ws, $e1, $owner) === 4);
$throws('starter checklist only once', InvalidArgumentException::class, fn() => $consult->addStarterChecklist($ws, $e1, $owner));
$taskId = $consult->addPreparationTask($ws, $e1, $consultant, 'Read their website', 'Pricing page', $now->modify('+1 day')->format('Y-m-d\TH:i'));
$throws('bad due date rejected', InvalidArgumentException::class, fn() => $consult->addPreparationTask($ws, $e1, $owner, 'x', '', 'tomorrow-ish'));
$throws('empty title rejected', InvalidArgumentException::class, fn() => $consult->addPreparationTask($ws, $e1, $owner, '  ', '', ''));
$consult->setPreparationTaskDone($ws, $e1, $consultant, $taskId, true);
$tasks = $consult->listPreparationTasks($ws, $e1);
$check('5 tasks, completed one sorted last', count($tasks) === 5 && $tasks[4]['status'] === 'completed' && $tasks[4]['completed_at'] !== null);
$consult->setPreparationTaskDone($ws, $e1, $consultant, $taskId, false);
$check('reopen clears completed_at', (string)$pdo->query("SELECT status FROM preparation_tasks WHERE id = $taskId")->fetchColumn() === 'pending' && $pdo->query("SELECT completed_at FROM preparation_tasks WHERE id = $taskId")->fetchColumn() === null);
$consult->removePreparationTask($ws, $e1, $owner, $taskId);
$check('task removed', count($consult->listPreparationTasks($ws, $e1)) === 4);
$throws('other workspace cannot see engagement', RuntimeException::class, fn() => $consult->addPreparationTask($ws2, $e1, $otherOwner, 'Sneaky', '', ''), 'not found');
$check('other workspace lists nothing', $consult->listPreparationTasks($ws2, $e1) === []);

// ---- meeting: notes + completion
$e2 = $newEngagement('scheduled');
$future = $newAppointment($e2, $now->modify('+2 days'));
$throws('cannot complete a future meeting', InvalidArgumentException::class, fn() => $consult->completeAppointment($ws, $e2, $consultant, $future), 'not started');
$throws('cannot no-show a future meeting', InvalidArgumentException::class, fn() => $consult->markNoShow($ws, $e2, $consultant, $future), 'before it starts');
$pdo->prepare('UPDATE appointments SET starts_at = ?, ends_at = ? WHERE id = ?')->execute([$fmt($now->modify('-2 hours')), $fmt($now->modify('-1 hour')), $future]);
$throws('cannot complete without notes', InvalidArgumentException::class, fn() => $consult->completeAppointment($ws, $e2, $consultant, $future), 'meeting notes');
$note = ['summary' => 'Good call', 'problem' => 'Slow site', 'requirements' => 'Rebuild', 'decisions' => '', 'concerns' => 'Budget', 'next_step' => 'Send proposal'];
$throws('required note fields enforced', InvalidArgumentException::class, fn() => $consult->saveMeetingNote($ws, $e2, $consultant, $future, ['summary' => ''] + $note));
$consult->saveMeetingNote($ws, $e2, $consultant, $future, $note);
$consult->saveMeetingNote($ws, $e2, $consultant, $future, ['summary' => 'Updated summary'] + $note);
$saved = $consult->getMeetingNote($ws, $future);
$check('notes upserted (one row, updated text, optional field null)', $saved !== null && $saved['summary'] === 'Updated summary' && $saved['decisions'] === null && (int)$pdo->query("SELECT COUNT(*) FROM meeting_notes WHERE appointment_id = $future")->fetchColumn() === 1);
$check('notes hidden from other workspace', $consult->getMeetingNote($ws2, $future) === null);
$eOther = $newEngagement('scheduled');
$throws('notes for another engagement\'s appointment rejected', RuntimeException::class, fn() => $consult->saveMeetingNote($ws, $eOther, $owner, $future, $note), 'Appointment not found');
$throws('notes rejected while engagement is only qualified', InvalidArgumentException::class, fn() => $consult->saveMeetingNote($ws, $e1, $owner, $future, $note), 'no longer');
$consult->completeAppointment($ws, $e2, $consultant, $future);
$check('appointment completed', (string)$pdo->query("SELECT status FROM appointments WHERE id = $future")->fetchColumn() === 'completed');
$check('engagement completed', $status($e2) === 'completed');
$throws('cannot complete twice', InvalidArgumentException::class, fn() => $consult->completeAppointment($ws, $e2, $consultant, $future));
$check('meeting workspace finds the completed appointment', ($consult->meetingAppointment($ws, $e2)['id'] ?? 0) === $future);

// ---- follow-ups
$throws('no follow-up before completion', InvalidArgumentException::class, fn() => $follow->create($ws, $e1, $owner, null, 'email', 'Too soon', '', $now->modify('+1 day')->format('Y-m-d\TH:i')), 'completed');
$throws('past due date rejected', InvalidArgumentException::class, fn() => $follow->create($ws, $e2, $owner, null, 'email', 'Old', '', $now->modify('-3 days')->format('Y-m-d\TH:i')), 'past');
$throws('invalid type rejected', InvalidArgumentException::class, fn() => $follow->create($ws, $e2, $owner, null, 'carrier-pigeon', 'x', '', $now->modify('+1 day')->format('Y-m-d\TH:i')));
$throws('assignee from another workspace rejected', InvalidArgumentException::class, fn() => $follow->create($ws, $e2, $owner, $otherOwner, 'email', 'x', '', $now->modify('+1 day')->format('Y-m-d\TH:i')), 'not active');
$f1 = $follow->create($ws, $e2, $consultant, null, 'proposal', 'Send proposal', 'PDF', $now->modify('+1 day')->format('Y-m-d\TH:i'));
$check('first follow-up moves engagement to follow_up', $status($e2) === 'follow_up');
$check('assignee defaults to engagement consultant', (int)$pdo->query("SELECT assigned_user_id FROM follow_ups WHERE id = $f1")->fetchColumn() === $consultant);
$f2 = $follow->create($ws, $e2, $owner, $owner, 'call', 'Check in', '', $now->modify('+5 days')->format('Y-m-d\TH:i'));
$follow->update($ws, $e2, $owner, $f2, null, 'meeting', 'Check-in meeting', 'Zoom', $now->modify('+6 days')->format('Y-m-d\TH:i'));
$updated = $pdo->query("SELECT type, title FROM follow_ups WHERE id = $f2")->fetch();
$check('follow-up edited', $updated['type'] === 'meeting' && $updated['title'] === 'Check-in meeting');
$follow->complete($ws, $e2, $consultant, $f1);
$throws('completed follow-up cannot be completed again', InvalidArgumentException::class, fn() => $follow->complete($ws, $e2, $consultant, $f1));
$throws('completed follow-up cannot be edited', InvalidArgumentException::class, fn() => $follow->update($ws, $e2, $owner, $f1, null, 'email', 'x', '', $now->modify('+1 day')->format('Y-m-d\TH:i')));
$throws('follow-up of another engagement not reachable', RuntimeException::class, fn() => $follow->cancel($ws, $e1, $owner, $f2), 'not found');
$f3 = $follow->create($ws, $e2, $owner, null, 'email', 'To be cancelled', '', $now->modify('+2 days')->format('Y-m-d\TH:i'));
$follow->cancel($ws, $e2, $owner, $f3);
$check('cancelled follow-up kept as history', (string)$pdo->query("SELECT status FROM follow_ups WHERE id = $f3")->fetchColumn() === 'cancelled');
$check('list shows pending first', $follow->listForEngagement($ws, $e2)[0]['id'] == $f2);

// overdue counting uses workspace-local time
$pdo->prepare("INSERT INTO follow_ups (engagement_id, assigned_user_id, type, title, due_at) VALUES (?, ?, 'email', 'Late', ?)")->execute([$e2, $consultant, $fmt($now->modify('-1 hour'))]);
$check('overdue count sees the late follow-up only', $follow->overdueCount($ws) === 1 && $follow->overdueCount($ws, $owner) === 0 && $follow->overdueCount($ws2) === 0);

// ---- outcome
$throws('cannot conclude a scheduled engagement', InvalidArgumentException::class, fn() => $consult->conclude($ws, $e1, $owner, 'converted', ''), 'after the consultation');
$throws('unknown outcome rejected', InvalidArgumentException::class, fn() => $consult->conclude($ws, $e2, $owner, 'won', ''));
$consult->conclude($ws, $e2, $consultant, 'converted', 'Signed the retainer');
$check('engagement converted', $status($e2) === 'converted');
$check('pending follow-ups auto-cancelled', (int)$pdo->query("SELECT COUNT(*) FROM follow_ups WHERE engagement_id = $e2 AND status = 'pending'")->fetchColumn() === 0);
$check('overdue count drops to zero', $follow->overdueCount($ws) === 0);
$throws('cannot conclude twice', InvalidArgumentException::class, fn() => $consult->conclude($ws, $e2, $owner, 'closed_lost', ''));
$throws('no follow-ups after conversion', InvalidArgumentException::class, fn() => $follow->create($ws, $e2, $owner, null, 'email', 'x', '', $now->modify('+1 day')->format('Y-m-d\TH:i')));
$throws('notes frozen after conversion', InvalidArgumentException::class, fn() => $consult->saveMeetingNote($ws, $e2, $owner, $future, $note), 'no longer');

// ---- no-show returns the engagement to qualified, appointment kept as history
$e3 = $newEngagement('scheduled');
$missed = $newAppointment($e3, $now->modify('-3 hours'));
$consult->markNoShow($ws, $e3, $consultant, $missed);
$check('no-show: appointment kept', (string)$pdo->query("SELECT status FROM appointments WHERE id = $missed")->fetchColumn() === 'no_show');
$check('no-show: engagement back to qualified', $status($e3) === 'qualified');
$check('no-show: not offered as meeting workspace', $consult->meetingAppointment($ws, $e3) === null);

// ---- closed lost path from completed
$e4 = $newEngagement('scheduled');
$a4 = $newAppointment($e4, $now->modify('-2 hours'));
$consult->saveMeetingNote($ws, $e4, $owner, $a4, $note);
$consult->completeAppointment($ws, $e4, $owner, $a4);
$consult->conclude($ws, $e4, $owner, 'closed_lost', 'Went with a competitor');
$check('closed lost straight from completed', $status($e4) === 'closed_lost');

// ---- activity history
$types = $events($e2);
foreach (['meeting.notes_saved', 'meeting.notes_updated', 'appointment.completed', 'engagement.completed', 'engagement.follow_up', 'follow_up.created', 'follow_up.updated', 'follow_up.completed', 'follow_up.cancelled', 'follow_up.auto_cancelled', 'engagement.converted'] as $type) {
    $check("activity history has $type", in_array($type, $types, true));
}
$check('preparation events recorded', in_array('preparation.starter_added', $events($e1), true) && in_array('preparation.task_removed', $events($e1), true));
$check('no-show event recorded', in_array('appointment.no_show', $events($e3), true));

echo "Milestone 8 integration checks passed ($passed assertions).\n";
