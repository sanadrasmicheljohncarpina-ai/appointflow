<?php
declare(strict_types=1);

/**
 * Milestone 9 integration test — runs OperationsService (and the Milestone 8 follow-up service it
 * reads from) against a real MySQL/MariaDB database.
 *
 * OPTIONAL and DESTRUCTIVE to the target database: it wipes the tables it touches, so it refuses
 * to run unless the database name ends in "_test". Setup is the same as milestone8_integration.php:
 *
 *   mysql -u root -e "CREATE DATABASE appointflow_test CHARACTER SET utf8mb4"
 *   for f in database/migrations/*.sql; do mysql -u root appointflow_test < $f; done
 *   AF_TEST_DB=appointflow_test php tests/milestone9_integration.php
 */
if (PHP_SAPI !== 'cli') { exit("CLI only.\n"); }
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/services/EngagementService.php';
require_once __DIR__ . '/../app/services/FollowUpService.php';
require_once __DIR__ . '/../app/services/OperationsService.php';

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
$insert = static function (string $sql, array $params) use ($pdo): int {
    $pdo->prepare($sql)->execute($params);
    return (int)$pdo->lastInsertId();
};

// The workspace deliberately uses a timezone far from the application default (Asia/Manila), so a
// "today"/"overdue" computed with the wrong clock would fail these checks.
$wsTzName = 'Pacific/Auckland';
$tz = new DateTimeZone($wsTzName);
$now = new DateTimeImmutable('now', $tz);
$startToday = $now->setTime(0, 0, 0);
$endToday = $startToday->modify('+1 day');
$fmt = static fn(DateTimeImmutable $d): string => $d->format('Y-m-d H:i:s');
// A moment later today (halfway to midnight) so "due today, not yet overdue" is stable at any hour.
$todayLater = $now->modify('+' . intdiv($endToday->getTimestamp() - $now->getTimestamp(), 2) . ' seconds');

$ws = $insert("INSERT INTO workspaces (name, email, booking_slug, timezone) VALUES ('Northstar', 'n@example.com', 'northstar', ?)", [$wsTzName]);
$ws2 = $insert("INSERT INTO workspaces (name, email, booking_slug, timezone) VALUES ('Other', 'o@example.com', 'other', ?)", [$wsTzName]);
$userSql = "INSERT INTO users (workspace_id, first_name, last_name, email, password_hash, role) VALUES (?, ?, ?, ?, 'x', ?)";
$owner = $insert($userSql, [$ws, 'Maya', 'Owner', 'maya@example.com', 'owner']);
$carl = $insert($userSql, [$ws, 'Carl', 'Consultant', 'carl@example.com', 'consultant']);
$dana = $insert($userSql, [$ws, 'Dana', 'Consultant', 'dana@example.com', 'consultant']);
$otherOwner = $insert($userSql, [$ws2, 'Olga', 'Other', 'olga@example.com', 'owner']);

$clientSql = "INSERT INTO clients (workspace_id, first_name, last_name, email) VALUES (?, ?, ?, ?)";
$sarah = $insert($clientSql, [$ws, 'Sarah', 'Lane', 's@example.com']);
$mark = $insert($clientSql, [$ws, 'Mark', 'Reed', 'm@example.com']);
$john = $insert($clientSql, [$ws, 'John', 'Park', 'j@example.com']);
$ghost = $insert($clientSql, [$ws2, 'Gina', 'Ghost', 'g@example.com']);

$service = $insert("INSERT INTO services (workspace_id, name, duration_minutes) VALUES (?, 'Discovery Consultation', 60)", [$ws]);
$engSql = "INSERT INTO engagements (workspace_id, client_id, service_id, assigned_user_id, title, problem_summary, status) VALUES (?, ?, ?, ?, ?, 'Needs help', ?)";
$apptSql = "INSERT INTO appointments (engagement_id, assigned_user_id, starts_at, ends_at, status, client_token_hash) VALUES (?, ?, ?, ?, ?, 'hash')";
$appt = static fn(int $eng, int $user, DateTimeImmutable $start, string $status = 'scheduled') => $insert($apptSql, [$eng, $user, $fmt($start), $fmt($start->modify('+1 hour')), $status]);

// Sarah: consultation tomorrow 10:00 with Carl, preparation 2/4
$eSarah = $insert($engSql, [$ws, $sarah, $service, $carl, 'Sarah engagement', 'scheduled']);
$sarahStart = $startToday->modify('+1 day')->setTime(10, 0);
$apptSarah = $appt($eSarah, $carl, $sarahStart);
foreach ([['Research', 'completed'], ['Read intake', 'completed'], ['Draft agenda', 'pending'], ['Prepare deck', 'pending']] as $i => [$title, $st]) {
    $insert("INSERT INTO preparation_tasks (engagement_id, title, status, sort_order) VALUES (?, ?, ?, ?)", [$eSarah, $title, $st, $i]);
}
// Mark: consultation later today with Dana, no preparation tasks other than one open task
$eMark = $insert($engSql, [$ws, $mark, $service, $dana, 'Mark engagement', 'scheduled']);
$apptMark = $appt($eMark, $dana, $todayLater);
$insert("INSERT INTO preparation_tasks (engagement_id, title, status, sort_order) VALUES (?, 'Pull account notes', 'pending', 0)", [$eMark]);
// John: meeting yesterday that was never concluded (needs an outcome), plus a cancelled one tomorrow
$eJohn = $insert($engSql, [$ws, $john, $service, $carl, 'John engagement', 'scheduled']);
$apptJohnPast = $appt($eJohn, $carl, $startToday->modify('-1 day')->setTime(9, 0));
$apptJohnCancelled = $appt($eJohn, $carl, $startToday->modify('+1 day')->setTime(15, 0), 'cancelled');
// Inquiry pipeline
$eNew = $insert($engSql, [$ws, $john, null, null, 'New inquiry', 'new']);
$eQualified = $insert($engSql, [$ws, $mark, $service, $carl, 'Qualified lead', 'qualified']);
// A completed engagement with follow-ups of every kind (Carl's)
$eDone = $insert($engSql, [$ws, $sarah, $service, $carl, 'Finished consultation', 'follow_up']);
$fuSql = "INSERT INTO follow_ups (engagement_id, assigned_user_id, type, title, due_at, status, completed_at) VALUES (?, ?, 'proposal', ?, ?, ?, ?)";
$fuOverdue2 = $insert($fuSql, [$eDone, $carl, 'Prepare proposal', $fmt($now->modify('-2 days')), 'pending', null]);
$fuOverdue1 = $insert($fuSql, [$eDone, $owner, 'Request document', $fmt($now->modify('-1 day')), 'pending', null]);
$fuToday = $insert($fuSql, [$eDone, $carl, 'Send recap', $fmt($todayLater), 'pending', null]);
$fuUpcoming = $insert($fuSql, [$eDone, $carl, 'Check in', $fmt($now->modify('+3 days')), 'pending', null]);
$fuDone = $insert($fuSql, [$eDone, $carl, 'Old task', $fmt($now->modify('-5 days')), 'completed', $fmt($now->modify('-4 days'))]);
$fuCancelled = $insert($fuSql, [$eDone, $carl, 'Dropped task', $fmt($now->modify('-3 days')), 'cancelled', null]);
// Dana's engagement follow-up (to prove consultant scoping)
$eDana = $insert($engSql, [$ws, $mark, $service, $dana, 'Dana follow-up case', 'follow_up']);
$fuDana = $insert($fuSql, [$eDana, $dana, 'Dana task', $fmt($now->modify('-1 day')), 'pending', null]);
// Another workspace with overlapping dates: must never leak
$eGhost = $insert($engSql, [$ws2, $ghost, null, $otherOwner, 'Ghost engagement', 'scheduled']);
$appt($eGhost, $otherOwner, $sarahStart);
$insert($fuSql, [$eGhost, $otherOwner, 'Ghost task', $fmt($now->modify('-9 days')), 'pending', null]);
$insert("INSERT INTO preparation_tasks (engagement_id, title, status, sort_order) VALUES (?, 'Ghost prep', 'pending', 0)", [$eGhost]);

$ops = new OperationsService($pdo);

// ---- time helpers
$check('workspace timezone is used', $ops->timezone($ws)->getName() === $wsTzName);
$check('dayLabel Today', OperationsService::dayLabel($fmt($todayLater), $now) === 'Today');
$check('dayLabel Tomorrow', OperationsService::dayLabel($fmt($sarahStart), $now) === 'Tomorrow');
$check('overdueLabel days', OperationsService::overdueLabel($fmt($now->modify('-2 days')), $now) === '2 days overdue');
$check('overdueLabel 1 day', OperationsService::overdueLabel($fmt($now->modify('-1 day')), $now) === '1 day overdue');
$check('overdueLabel not overdue is empty', OperationsService::overdueLabel($fmt($now->modify('+1 hour')), $now) === '');

// ---- owner dashboard summary
$s = $ops->getDashboardSummary($ws, null);
$check('new inquiries = 1', $s['new_inquiries'] === 1);
$check('qualified = 1', $s['qualified'] === 1);
$check('upcoming = Sarah + Mark (not past, not cancelled)', $s['upcoming'] === 2);
$check('today consultations = Mark only', $s['today_consultations'] === 1);
$check('awaiting outcome = John (yesterday)', $s['awaiting_outcome'] === 1);
$check('pending follow-ups = 5', $s['pending_followups'] === 5);
$check('overdue follow-ups = 3', $s['overdue_followups'] === 3);
$check('follow-ups due today = 1', $s['followups_due_today'] === 1);
$check('today prep open = Mark\'s task only', $s['today_prep_open'] === 1);

// ---- acceptance scenario: Sarah has a consultation tomorrow at 10:00
$upcoming = $ops->getUpcomingAppointments($ws, null, 10);
$check('upcoming lists Mark then Sarah, soonest first', count($upcoming) === 2 && (int)$upcoming[0]['engagement_id'] === $eMark && (int)$upcoming[1]['engagement_id'] === $eSarah);
$sarahRow = $upcoming[1];
$check('Sarah row has client, service, consultant', $sarahRow['first_name'] === 'Sarah' && $sarahRow['service_name'] === 'Discovery Consultation' && $sarahRow['consultant_first'] === 'Carl');
$check('Sarah appears at the workspace-local time (10:00 tomorrow)', $sarahRow['starts_at'] === $fmt($sarahStart));
$check('Sarah opens Engagement via engagement_id', (int)$sarahRow['engagement_id'] === $eSarah);
$check('Sarah preparation progress 2/4', $sarahRow['prep_done'] === 2 && $sarahRow['prep_total'] === 4);
$check('Mark has no completed prep (0/1)', $upcoming[0]['prep_done'] === 0 && $upcoming[0]['prep_total'] === 1);
$check('upcoming limit respected', count($ops->getUpcomingAppointments($ws, null, 1)) === 1);

// ---- today
$todayAppts = $ops->getTodayAppointments($ws, null);
$check('today view = Mark only', count($todayAppts) === 1 && (int)$todayAppts[0]['engagement_id'] === $eMark);
$todayPrep = $ops->getTodayPreparationTasks($ws, null);
$check('today preparation tasks = Mark\'s open task', count($todayPrep) === 1 && $todayPrep[0]['title'] === 'Pull account notes');
$awaiting = $ops->getAwaitingOutcome($ws, null);
$check('awaiting outcome lists John', count($awaiting) === 1 && (int)$awaiting[0]['engagement_id'] === $eJohn);

// ---- calendar ranges
$weekStart = $startToday->modify('-' . ((int)$startToday->format('N') - 1) . ' days');
$cal = $ops->getCalendarAppointments($ws, null, $fmt($sarahStart->setTime(0, 0)), $fmt($sarahStart->setTime(0, 0)->modify('+1 day')));
$check('tomorrow\'s day range holds Sarah only (cancelled hidden)', count($cal) === 1 && (int)$cal[0]['engagement_id'] === $eSarah);
$cal = $ops->getCalendarAppointments($ws, null, $fmt($sarahStart->setTime(0, 0)), $fmt($sarahStart->setTime(0, 0)->modify('+1 day')), null, true);
$check('include cancelled shows the cancelled appointment too', count($cal) === 2);
$cal = $ops->getCalendarAppointments($ws, null, $fmt($sarahStart->setTime(0, 0)), $fmt($sarahStart->setTime(0, 0)->modify('+1 day')), $dana);
$check('consultant filter narrows the calendar', $cal === []);
$cal = $ops->getCalendarAppointments($ws, null, $fmt($startToday->modify('-7 days')), $fmt($startToday->modify('+7 days')));
$check('wide range includes past meeting, today and tomorrow', count($cal) === 3);
$check('range end is exclusive', $ops->getCalendarAppointments($ws, null, $fmt($sarahStart->modify('-1 hour')), $fmt($sarahStart)) === []);

// ---- follow-up filters partition pending work
$all = $ops->getFollowUps($ws, null, 'all');
$overdue = $ops->getFollowUps($ws, null, 'overdue');
$today = $ops->getFollowUps($ws, null, 'today');
$upcomingFu = $ops->getFollowUps($ws, null, 'upcoming');
$completed = $ops->getFollowUps($ws, null, 'completed');
$ids = static fn(array $rows): array => array_map(static fn(array $r): int => (int)$r['id'], $rows);
$check('all = 5 pending (no completed / cancelled)', count($all) === 5);
$check('overdue = 3, oldest first', $ids($overdue) === [$fuOverdue2, $fuOverdue1, $fuDana]);
$check('today = Send recap', $ids($today) === [$fuToday]);
$check('upcoming = Check in', $ids($upcomingFu) === [$fuUpcoming]);
$check('overdue + today + upcoming = all', count($overdue) + count($today) + count($upcomingFu) === count($all));
$check('completed = Old task only', $ids($completed) === [$fuDone]);
$mine = $ops->getFollowUps($ws, null, 'mine', $owner);
$check('mine = items assigned to the viewer', $ids($mine) === [$fuOverdue1]);
$check('owner assignee filter', $ids($ops->getFollowUps($ws, null, 'all', null, $dana)) === [$fuDana]);
$check('bad filter falls back to all', count($ops->getFollowUps($ws, null, 'nonsense')) === 5);
$row = $overdue[0];
$check('follow-up row carries client / engagement / assignee', $row['first_name'] === 'Sarah' && (int)$row['engagement_id'] === $eDone && $row['assigned_first'] === 'Carl' && $row['title'] === 'Prepare proposal');
$counts = $ops->getFollowUpCounts($ws, null, $owner);
$check('tab counts match lists', $counts === ['mine' => 1, 'all' => 5, 'today' => 1, 'upcoming' => 1, 'overdue' => 3, 'completed' => 1]);
$check('getOverdueFollowUps / getPendingFollowUps agree with filters', $ids($ops->getOverdueFollowUps($ws, null)) === $ids($overdue) && count($ops->getPendingFollowUps($ws, null)) === 5);

// ---- consultant scope: only engagements assigned to them
$cs = $ops->getDashboardSummary($ws, $carl);
$check('Carl: qualified 1, new 0', $cs['qualified'] === 1 && $cs['new_inquiries'] === 0);
$check('Carl: upcoming = Sarah only', $cs['upcoming'] === 1);
$check('Carl: pending 4, overdue 2, today 1', $cs['pending_followups'] === 4 && $cs['overdue_followups'] === 2 && $cs['followups_due_today'] === 1);
$check('Carl sees no consultation today (Mark is Dana\'s)', $cs['today_consultations'] === 0 && $ops->getTodayAppointments($ws, $carl) === []);
$check('Carl\'s calendar excludes Dana\'s appointments', count($ops->getCalendarAppointments($ws, $carl, $fmt($startToday->modify('-7 days')), $fmt($startToday->modify('+7 days')))) === 2);
$check('Carl cannot list Dana\'s follow-up', !in_array($fuDana, $ids($ops->getFollowUps($ws, $carl, 'all')), true));
$check('Carl scope tab counts', $ops->getFollowUpCounts($ws, $carl, $carl)['all'] === 4);
$check('Dana: sees only her follow-up', $ids($ops->getFollowUps($ws, $dana, 'all')) === [$fuDana]);

// ---- workspace isolation
$gs = $ops->getDashboardSummary($ws2, null);
$check('other workspace counts only its own data', $gs['upcoming'] === 1 && $gs['pending_followups'] === 1 && $gs['new_inquiries'] === 0);
$check('other workspace upcoming is Ghost only', count($ops->getUpcomingAppointments($ws2, null)) === 1);
$check('first workspace never shows Ghost', !in_array('Ghost task', array_column($all, 'title'), true));
$check('ghost preparation task not counted in workspace 1', count(array_filter($ops->getPreparationProgress([$eSarah, $eMark]), static fn($p) => $p['total'] === 0)) === 0);

// ---- acceptance: completing a follow-up elsewhere updates the dashboard automatically
(new FollowUpService($pdo))->complete($ws, $eDone, $carl, $fuOverdue2);
$s2 = $ops->getDashboardSummary($ws, null);
$check('after completing: pending 4, overdue 2', $s2['pending_followups'] === 4 && $s2['overdue_followups'] === 2);
$check('after completing: item left Overdue and joined Completed', !in_array($fuOverdue2, $ids($ops->getFollowUps($ws, null, 'overdue')), true) && in_array($fuOverdue2, $ids($ops->getFollowUps($ws, null, 'completed')), true));
$check('tab counts follow', $ops->getFollowUpCounts($ws, null, $owner)['completed'] === 2);
(new FollowUpService($pdo))->cancel($ws, $eDone, $owner, $fuUpcoming);
$check('cancelling removes it from pending', $ops->getDashboardSummary($ws, null)['pending_followups'] === 3);

// ---- preparation progress helper
$check('progress ignores bad ids and empty input', $ops->getPreparationProgress([]) === [] && $ops->getPreparationProgress([0, -1]) === []);
$check('engagement without tasks has no entry', !isset($ops->getPreparationProgress([$eJohn])[$eJohn]));

echo "Milestone 9 integration checks passed ($passed assertions).\n";
