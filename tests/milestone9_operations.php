<?php
declare(strict_types=1);

/**
 * Milestone 9 - Operations Center.
 *
 *   php tests/milestone9_operations.php        static checks (no database needed)
 *   php tests/milestone9_operations.php --db   also runs the OperationsService scenario against the
 *                                              configured MySQL database. It creates two throwaway
 *                                              workspaces and deletes everything it created.
 */

$root = dirname(__DIR__);
$read = static function (string $relative) use ($root): string {
    $content = @file_get_contents($root . $relative);
    if ($content === false) {
        throw new RuntimeException('Missing required Milestone 9 file: ' . $relative);
    }
    return $content;
};
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
};
$expect = static function (string $label, string $haystack, array $needles): void {
    foreach ($needles as $needle) {
        if (!str_contains($haystack, $needle)) {
            throw new RuntimeException($label . ' is missing expected logic: ' . $needle);
        }
    }
};

// ---- Static checks -------------------------------------------------------------------------
$service = $read('/app/services/OperationsService.php');
$expect('OperationsService', $service, [
    'function getDashboardSummary', 'function getTodayAppointments', 'function getUpcomingAppointments',
    'function getPendingPreparation', 'function getOverdueFollowUps', 'function getRecentEngagements',
    'function getFollowUps', 'function getFollowUpCounts', 'function getAppointmentsBetween', 'function viewRange',
    'e.workspace_id = ?',
]);
$assert(!str_contains($service, 'NOW()'), 'OperationsService must not use NOW(); times are workspace-local and computed in PHP.');
$assert(!preg_match('/\b(INSERT|UPDATE|DELETE)\b/', $service), 'OperationsService must be read-only.');

foreach (['/public/dashboard.php', '/public/calendar.php', '/public/followups.php'] as $page) {
    $source = $read($page);
    $expect($page, $source, ['requireLogin', 'OperationsService', 'workspaceTimezone']);
    $assert(!str_contains($source, 'NOW()'), $page . ' must not use NOW().');
    $assert(!str_contains($source, '$_POST'), $page . ' must be read-only (no POST handling).');
}
$expect('Dashboard', $read('/public/dashboard.php'), ['engagementUrl', 'getTodayAppointments', 'getUpcomingAppointments', 'getOverdueFollowUps', 'getPendingPreparation']);
$expect('Calendar', $read('/public/calendar.php'), ["'day'", "'week'", 'viewRange', 'requireLogin']);
$expect('Follow-up center', $read('/public/followups.php'), ['FOLLOW_UP_FILTERS', 'getFollowUpCounts', 'engagementUrl']);
$expect('Header navigation', $read('/app/views/layouts/header.php'), ['public/calendar.php', 'public/followups.php']);
$expect('Pretty URLs', $read('/.htaccess'), ['calendar|followups']);

[$ws, $we] = OperationsServiceRangeProbe::week();
$assert($ws === '2026-10-05' && $we === '2026-10-12', 'Week view must run Monday to the following Monday (got ' . $ws . ' to ' . $we . ').');

echo "Milestone 9 static checks passed.\n";

if (!in_array('--db', $argv ?? [], true)) {
    echo "Skipped database scenario (run with --db to include it).\n";
    exit(0);
}

// ---- Database scenario ---------------------------------------------------------------------
require_once $root . '/app/database.php';
require_once $root . '/app/services/FollowUpService.php';
require_once $root . '/app/services/PreparationService.php';
require_once $root . '/app/services/OperationsService.php';

$pdo = db();
$suffix = bin2hex(random_bytes(4));
$workspaceIds = [];

// A timezone far from the usual database/server clock, to prove "today" is the workspace's today.
$tz = new DateTimeZone('Pacific/Auckland');
$now = new DateTimeImmutable('now', $tz);
$todayStart = $now->setTime(0, 0, 0);
$fmt = static fn (DateTimeImmutable $d): string => $d->format('Y-m-d H:i:s');
$endOfDay = static fn (DateTimeImmutable $d): string => $d->format('Y-m-d') . ' 23:59:59';

$insert = static function (string $sql, array $params) use ($pdo): int {
    $pdo->prepare($sql)->execute($params);
    return (int)$pdo->lastInsertId();
};

$makeWorkspace = static function (string $label) use ($insert, $suffix, &$workspaceIds): int {
    $id = $insert(
        'INSERT INTO workspaces (name, email, timezone, booking_slug) VALUES (?, ?, \'Pacific/Auckland\', ?)',
        ['Ops test ' . $label, $label . '-' . $suffix . '@example.test', 'ops-test-' . $label . '-' . $suffix]
    );
    $workspaceIds[] = $id;
    return $id;
};
$makeUser = static fn (int $ws, string $first, string $role) => $insert(
    'INSERT INTO users (workspace_id, first_name, last_name, email, password_hash, role) VALUES (?, ?, \'Tester\', ?, \'x\', ?)',
    [$ws, $first, strtolower($first) . '-' . $ws . '@example.test', $role]
);
$makeClient = static fn (int $ws, string $first) => $insert(
    'INSERT INTO clients (workspace_id, first_name, last_name, email) VALUES (?, ?, \'Client\', ?)',
    [$ws, $first, strtolower($first) . '-' . $ws . '@example.test']
);
$makeService = static fn (int $ws) => $insert(
    'INSERT INTO services (workspace_id, name, duration_minutes) VALUES (?, \'Discovery Consultation\', 30)',
    [$ws]
);
$makeEngagement = static fn (int $ws, int $client, int $service, ?int $user, string $title, string $status) => $insert(
    'INSERT INTO engagements (workspace_id, client_id, service_id, assigned_user_id, title, problem_summary, status) VALUES (?, ?, ?, ?, ?, \'x\', ?)',
    [$ws, $client, $service, $user, $title, $status]
);
$makeAppointment = static fn (int $engagement, int $user, string $start, string $end, string $status) => $insert(
    'INSERT INTO appointments (engagement_id, assigned_user_id, starts_at, ends_at, status, client_token_hash) VALUES (?, ?, ?, ?, ?, ?)',
    [$engagement, $user, $start, $end, $status, hash('sha256', $suffix . $start . $engagement . $status)]
);
$makeFollowUp = static fn (int $engagement, int $user, string $title, string $due, string $status) => $insert(
    'INSERT INTO follow_ups (engagement_id, assigned_user_id, type, title, due_at, status, completed_at) VALUES (?, ?, \'call\', ?, ?, ?, ?)',
    [$engagement, $user, $title, $due, $status, $status === 'completed' ? $due : null]
);
$makeTask = static fn (int $engagement, int $user, string $title, string $status) => $insert(
    'INSERT INTO preparation_tasks (engagement_id, assigned_user_id, title, status, sort_order) VALUES (?, ?, ?, ?, 1)',
    [$engagement, $user, $title, $status]
);
$names = static fn (array $rows, string $key = 'client_first'): array => array_values(array_map(static fn (array $r) => $r[$key], $rows));

try {
    // Workspace A: owner, two consultants (Alice, Bob), several engagements.
    $a = $makeWorkspace('a');
    $owner = $makeUser($a, 'Olivia', 'owner');
    $alice = $makeUser($a, 'Alice', 'consultant');
    $bob = $makeUser($a, 'Bob', 'consultant');
    $svc = $makeService($a);

    // Sarah: consultation tomorrow at 10:00 (workspace-local) with a half-finished checklist.
    $sarah = $makeEngagement($a, $makeClient($a, 'Sarah'), $svc, $alice, 'Sarah engagement', 'scheduled');
    $tomorrow10 = $todayStart->modify('+1 day')->setTime(10, 0, 0);
    $sarahAppt = $makeAppointment($sarah, $alice, $fmt($tomorrow10), $fmt($tomorrow10->modify('+30 minutes')), 'scheduled');
    $sarahTaskDone = $makeTask($sarah, $alice, 'Review inquiry', 'completed');
    $sarahTaskOpen = $makeTask($sarah, $alice, 'Prepare questions', 'pending');

    // Mark: consultation earlier today (already started, outcome not yet recorded), no checklist opened yet.
    $mark = $makeEngagement($a, $makeClient($a, 'Mark'), $svc, $bob, 'Mark engagement', 'scheduled');
    $markStart = $todayStart->setTime(0, 0, 30);
    $makeAppointment($mark, $bob, $fmt($markStart), $fmt($markStart->modify('+30 minutes')), 'scheduled');

    // Boundary cases: last second of yesterday is not "today"; first second of tomorrow is not "today".
    $yEdge = $makeEngagement($a, $makeClient($a, 'Yesterday'), $svc, $bob, 'Yesterday edge', 'completed');
    $makeAppointment($yEdge, $bob, $fmt($todayStart->modify('-1 second')), $fmt($todayStart->modify('+29 minutes')), 'completed');
    $tEdge = $makeEngagement($a, $makeClient($a, 'Tomorrow'), $svc, $alice, 'Tomorrow edge', 'scheduled');
    $makeAppointment($tEdge, $alice, $fmt($todayStart->modify('+1 day')), $fmt($todayStart->modify('+1 day +30 minutes')), 'scheduled');

    // A cancelled consultation next week (hidden from the calendar unless asked for).
    $cancelled = $makeEngagement($a, $makeClient($a, 'Cancelled'), $svc, $alice, 'Cancelled engagement', 'qualified');
    $cancelledStart = $todayStart->modify('+2 days')->setTime(15, 0, 0);
    $makeAppointment($cancelled, $alice, $fmt($cancelledStart), $fmt($cancelledStart->modify('+30 minutes')), 'cancelled');

    // Follow-ups on a completed engagement assigned to Alice: overdue, due today, upcoming, completed, cancelled.
    $done = $makeEngagement($a, $makeClient($a, 'Dana'), $svc, $alice, 'Dana engagement', 'follow_up');
    $fuOverdue = $makeFollowUp($done, $alice, 'Overdue call', $endOfDay($todayStart->modify('-1 day')), 'pending');
    $fuToday = $makeFollowUp($done, $alice, 'Today call', $endOfDay($todayStart), 'pending');
    $fuUpcoming = $makeFollowUp($done, $alice, 'Upcoming call', $endOfDay($todayStart->modify('+3 days')), 'pending');
    $fuCompleted = $makeFollowUp($done, $alice, 'Completed call', $endOfDay($todayStart->modify('-2 days')), 'completed');
    $fuCancelled = $makeFollowUp($done, $alice, 'Cancelled call', $endOfDay($todayStart->modify('+1 day')), 'cancelled');
    // Bob has one overdue follow-up of his own.
    $bobDone = $makeEngagement($a, $makeClient($a, 'Ben'), $svc, $bob, 'Ben engagement', 'follow_up');
    $makeFollowUp($bobDone, $bob, 'Bob overdue', $endOfDay($todayStart->modify('-3 days')), 'pending');

    // An unreviewed inquiry.
    $inquiry = $makeEngagement($a, $makeClient($a, 'Nina'), $svc, null, 'Nina inquiry', 'new');

    // Workspace B: must never leak into A's results.
    $b = $makeWorkspace('b');
    $bUser = $makeUser($b, 'Zed', 'consultant');
    $bEng = $makeEngagement($b, $makeClient($b, 'Outsider'), $makeService($b), $bUser, 'Other workspace', 'scheduled');
    $makeAppointment($bEng, $bUser, $fmt($tomorrow10), $fmt($tomorrow10->modify('+30 minutes')), 'scheduled');
    $makeFollowUp($bEng, $bUser, 'Other overdue', $endOfDay($todayStart->modify('-1 day')), 'pending');
    $makeTask($bEng, $bUser, 'Other task', 'pending');

    $ops = new OperationsService($pdo);

    // 1. Dashboard: Sarah appears under Upcoming at the workspace-local time.
    $upcoming = $ops->getUpcomingAppointments($a, null, $tz, 10);
    $assert($names($upcoming) === ['Tomorrow', 'Sarah'], 'Upcoming must list the tomorrow-00:00 edge, then Sarah (got ' . implode(',', $names($upcoming)) . ').');
    $sarahRow = array_values(array_filter($upcoming, static fn ($r) => (int)$r['appointment_id'] === $sarahAppt))[0];
    $assert((string)$sarahRow['starts_at'] === $fmt($tomorrow10), 'Sarah must be reported at her stored workspace-local start time.');
    $assert((int)$sarahRow['engagement_id'] === $sarah, 'Upcoming rows must carry the engagement id for linking.');

    // 2. Today: only Mark. Yesterday 23:59:59 and tomorrow 00:00:00 are outside today.
    $today = $ops->getTodayAppointments($a, null, $tz);
    $assert($names($today) === ['Mark'], 'Today must contain only Mark (got ' . implode(',', $names($today)) . ').');

    // 3. Summary counts for the owner.
    $s = $ops->getDashboardSummary($a, null, $tz);
    $assert($s['today_consultations'] === 1, 'today_consultations (got ' . $s['today_consultations'] . ')');
    $assert($s['upcoming_consultations'] === 2, 'upcoming_consultations counts Sarah and the tomorrow edge (got ' . $s['upcoming_consultations'] . ')');
    $assert($s['new_inquiries'] === 1, 'new_inquiries');
    $assert($s['open_preparation_tasks'] === 1, 'open_preparation_tasks counts only pending tasks on upcoming consultations (got ' . $s['open_preparation_tasks'] . ')');
    $assert($s['pending_followups'] === 4, 'pending_followups (got ' . $s['pending_followups'] . ')');
    $assert($s['due_today_followups'] === 1, 'due_today_followups (got ' . $s['due_today_followups'] . ')');
    $assert($s['overdue_followups'] === 2, 'overdue_followups includes Alice\'s and Bob\'s (got ' . $s['overdue_followups'] . ')');

    // 4. Consultants only see their own engagements; nobody sees workspace B.
    $aliceSummary = $ops->getDashboardSummary($a, $alice, $tz);
    $assert($aliceSummary['overdue_followups'] === 1 && $aliceSummary['today_consultations'] === 0 && $aliceSummary['upcoming_consultations'] === 2, 'Alice must see only her own work.');
    $assert($names($ops->getTodayAppointments($a, $alice, $tz)) === [], 'Alice has nothing today.');
    $assert($names($ops->getTodayAppointments($a, $bob, $tz)) === ['Mark'], 'Bob sees Mark today.');
    $assert(!in_array('Outsider', $names($ops->getUpcomingAppointments($a, null, $tz, 50)), true), 'Workspace B appointment leaked into A.');
    $assert($names($ops->getOverdueFollowUps($a, null, $tz, 50), 'title') === ['Bob overdue', 'Overdue call'], 'Overdue list (oldest first, workspace A only).');

    // 5. Preparation: Sarah is 1/2 done; Mark has no checklist yet ("Not started"); Alice's view excludes Mark.
    $prep = $ops->getPendingPreparation($a, null, $tz, 10);
    $byName = [];
    foreach ($prep as $row) {
        $byName[$row['first_name']] = $row;
    }
    $assert(isset($byName['Sarah']) && (int)$byName['Sarah']['tasks_total'] === 2 && (int)$byName['Sarah']['tasks_done'] === 1, 'Sarah preparation must be 1 / 2.');
    $assert(!isset($byName['Mark']), 'Mark\'s consultation already started today, so it is not "upcoming" preparation.');
    $assert(isset($byName['Tomorrow']) && (int)$byName['Tomorrow']['tasks_total'] === 0, 'Engagements without a checklist show as not started.');
    $assert(!isset($byName['Outsider']), 'Workspace B preparation leaked into A.');
    $alicePrep = $ops->getPendingPreparation($a, $bob, $tz, 10);
    $assert($alicePrep === [], 'Bob has no upcoming consultations to prepare for.');

    // 6. Calendar: week/day windows, cancelled hidden by default, consultant scoping.
    [$wkStart, $wkEnd] = OperationsService::viewRange('week', $todayStart);
    $weekAppts = $ops->getAppointmentsBetween($a, null, $fmt($wkStart), $fmt($wkEnd));
    $assert(!in_array('Cancelled', $names($weekAppts), true), 'Cancelled appointments must be hidden by default.');
    $withCancelled = $ops->getAppointmentsBetween($a, null, $fmt($todayStart->modify('-7 days')), $fmt($todayStart->modify('+14 days')), true);
    $assert(in_array('Cancelled', $names($withCancelled), true), 'Cancelled appointments must appear when requested.');
    $dayAppts = $ops->getAppointmentsBetween($a, null, $fmt($todayStart), $fmt($todayStart->modify('+1 day')));
    $assert($names($dayAppts) === ['Mark'], 'Day view must hold only today\'s consultation (got ' . implode(',', $names($dayAppts)) . ').');
    $tomorrowAppts = $ops->getAppointmentsBetween($a, $alice, $fmt($todayStart->modify('+1 day')), $fmt($todayStart->modify('+2 days')));
    $assert($names($tomorrowAppts) === ['Tomorrow', 'Sarah'], 'Alice\'s tomorrow, ordered by time.');
    $assert($tomorrowAppts[1]['consultant_first'] === 'Alice' && $tomorrowAppts[1]['service_name'] === 'Discovery Consultation', 'Calendar rows carry consultant and service.');
    $assert($ops->getAppointmentsBetween($a, $bob, $fmt($todayStart->modify('+1 day')), $fmt($todayStart->modify('+2 days'))) === [], 'Bob must not see Alice\'s consultations.');

    // 7. Follow-up Center filters and counts.
    $titles = static fn (array $rows) => array_values(array_map(static fn ($r) => $r['title'], $rows));
    $assert($titles($ops->getFollowUps($a, null, $tz, 'overdue')) === ['Bob overdue', 'Overdue call'], 'overdue filter');
    $assert($titles($ops->getFollowUps($a, null, $tz, 'today')) === ['Today call'], 'today filter');
    $assert($titles($ops->getFollowUps($a, null, $tz, 'upcoming')) === ['Upcoming call'], 'upcoming filter');
    $assert($titles($ops->getFollowUps($a, null, $tz, 'completed')) === ['Completed call'], 'completed filter');
    $assert($titles($ops->getFollowUps($a, null, $tz, 'all')) === ['Bob overdue', 'Overdue call', 'Today call', 'Upcoming call', 'Completed call', 'Cancelled call'], 'all filter: pending first by due date, then completed, then cancelled.');
    $assert($titles($ops->getFollowUps($a, $alice, $tz, 'all', null)) === ['Overdue call', 'Today call', 'Upcoming call', 'Completed call', 'Cancelled call'], 'consultant scoping on the follow-up center');
    $assert($titles($ops->getFollowUps($a, null, $tz, 'all', $bob)) === ['Bob overdue'], 'owner assignee filter');
    $assert($titles($ops->getFollowUps($a, null, $tz, 'nonsense')) === $titles($ops->getFollowUps($a, null, $tz, 'all')), 'unknown filter falls back to all');
    $counts = $ops->getFollowUpCounts($a, null, $tz);
    $assert($counts === ['all' => 6, 'today' => 1, 'upcoming' => 1, 'overdue' => 2, 'completed' => 1], 'follow-up counts (got ' . json_encode($counts) . ')');
    $overdueRow = $ops->getFollowUps($a, null, $tz, 'all')[1];
    $assert($overdueRow['is_overdue'] === true, 'Overdue rows are flagged.');

    // 8. Acceptance: completing work changes the dashboard automatically.
    $followUps = new FollowUpService($pdo);
    $followUps->complete($a, $done, $fuToday, $alice);
    $s2 = $ops->getDashboardSummary($a, null, $tz);
    $assert($s2['pending_followups'] === 3 && $s2['due_today_followups'] === 0 && $s2['overdue_followups'] === 2, 'Completing today\'s follow-up must update the dashboard counts.');
    $assert($ops->getFollowUpCounts($a, null, $tz)['completed'] === 2, 'Completed tab count must grow.');
    $followUps->complete($a, $done, $fuOverdue, $alice);
    $assert($titles($ops->getOverdueFollowUps($a, null, $tz, 50)) === ['Bob overdue'], 'Completed follow-up leaves the overdue list.');

    (new PreparationService($pdo))->setCompleted($a, $sarah, $sarahTaskOpen, $alice, true);
    $prepAfter = $ops->getPendingPreparation($a, null, $tz, 10);
    $assert(!in_array('Sarah', array_map(static fn ($r) => $r['first_name'], $prepAfter), true), 'Finished preparation leaves the pending list.');
    $assert($ops->getDashboardSummary($a, null, $tz)['open_preparation_tasks'] === 0, 'Open preparation task count drops to zero.');

    echo "Milestone 9 database scenario passed.\n";
} finally {
    foreach ($workspaceIds as $id) {
        $pdo->prepare('DELETE ev FROM engagement_events ev INNER JOIN engagements e ON e.id = ev.engagement_id WHERE e.workspace_id = ?')->execute([$id]);
        $pdo->prepare('DELETE f FROM follow_ups f INNER JOIN engagements e ON e.id = f.engagement_id WHERE e.workspace_id = ?')->execute([$id]);
        $pdo->prepare('DELETE p FROM preparation_tasks p INNER JOIN engagements e ON e.id = p.engagement_id WHERE e.workspace_id = ?')->execute([$id]);
        $pdo->prepare('DELETE ap FROM appointments ap INNER JOIN engagements e ON e.id = ap.engagement_id WHERE e.workspace_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM notification_log WHERE workspace_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM engagements WHERE workspace_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM services WHERE workspace_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM clients WHERE workspace_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM users WHERE workspace_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM workspaces WHERE id = ?')->execute([$id]);
    }
}

/** Static probe for the Monday-based week window (2026-10-07 is a Wednesday). */
final class OperationsServiceRangeProbe
{
    public static function week(): array
    {
        require_once dirname(__DIR__) . '/app/services/OperationsService.php';
        [$start, $end] = OperationsService::viewRange('week', new DateTimeImmutable('2026-10-07 15:30:00', new DateTimeZone('Asia/Manila')));
        return [$start->format('Y-m-d'), $end->format('Y-m-d')];
    }
}
