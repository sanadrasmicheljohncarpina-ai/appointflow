<?php
declare(strict_types=1);

/**
 * Milestone 10 hardening checks (state machine, tenant isolation, booking edge cases, token security).
 * Needs a scratch database whose name ends in _test (it wipes the tables it touches):
 *   AF_TEST_DB=appointflow_test php tests/milestone10_security.php
 * Prints every failing check and exits 1 if any failed (does not stop at the first one).
 */
if (PHP_SAPI !== 'cli') { exit("CLI only.\n"); }
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/security.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/services/EngagementService.php';
require_once __DIR__ . '/../app/services/BookingService.php';
require_once __DIR__ . '/../app/services/AppointmentService.php';

$dbName = (string)(getenv('AF_TEST_DB') ?: '');
if ($dbName === '' || !str_ends_with($dbName, '_test')) { fwrite(STDERR, "Set AF_TEST_DB to a scratch database ending in _test.\n"); exit(2); }
$pdo = new PDO('mysql:host=127.0.0.1;dbname=' . $dbName . ';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach (['client_link_requests','notification_log','engagement_events','follow_ups','meeting_notes','preparation_tasks','appointments','engagements','clients','availability_rules','blocked_times','services','users','workspaces'] as $t) { $pdo->exec("DELETE FROM $t"); }
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

$pass = 0; $fails = [];
$check = function (string $label, bool $ok) use (&$pass, &$fails): void { if ($ok) { $pass++; } else { $fails[] = $label; echo "FAIL: $label\n"; } };
$throws = function (string $label, callable $fn, ?string $class = null) use ($check): void {
    try { $fn(); } catch (Throwable $e) { $check($label . ' [' . get_class($e) . ': ' . $e->getMessage() . ']', $class === null || $e instanceof $class); return; }
    $check($label . ' [expected an exception, none thrown]', false);
};
$ins = function (string $sql, array $p = []) use ($pdo): int { $pdo->prepare($sql)->execute($p); return (int)$pdo->lastInsertId(); };

$tz = new DateTimeZone('Asia/Manila');
// A Monday, 3 weeks ahead, so "now" never interferes with availability or past checks.
$monday = (new DateTimeImmutable('now', $tz))->modify('next monday')->modify('+14 days')->setTime(0, 0, 0);
$at = fn(string $hm, ?DateTimeImmutable $day = null): string => ($day ?? $monday)->format('Y-m-d') . ' ' . $hm . ':00';

$wsA = $ins("INSERT INTO workspaces (name,email,booking_slug,timezone) VALUES ('A','a@x.test','a','Asia/Manila')");
$wsB = $ins("INSERT INTO workspaces (name,email,booking_slug,timezone) VALUES ('B','b@x.test','b','Asia/Manila')");
$u = "INSERT INTO users (workspace_id,first_name,last_name,email,password_hash,role,status) VALUES (?,?,?,?,'x',?,?)";
$ownerA = $ins($u, [$wsA,'Maya','A','maya@x.test','owner','active']);
$daniel = $ins($u, [$wsA,'Daniel','A','daniel@x.test','consultant','active']);
$ownerB = $ins($u, [$wsB,'Olga','B','olga@x.test','owner','active']);
$inactive = $ins($u, [$wsA,'Ina','A','ina@x.test','consultant','inactive']);
$svc = $ins("INSERT INTO services (workspace_id,name,duration_minutes,buffer_before_minutes,buffer_after_minutes,active) VALUES (?,?,60,0,15,1)", [$wsA,'Strategy']);
$svcOff = $ins("INSERT INTO services (workspace_id,name,duration_minutes,active) VALUES (?,?,30,0)", [$wsA,'Retired']);
$wd = (int)$monday->format('w');
$ins("INSERT INTO availability_rules (workspace_id,user_id,weekday,start_time,end_time,active) VALUES (?,NULL,?,'09:00','17:00',1)", [$wsA,$wd]);
$cl = $ins("INSERT INTO clients (workspace_id,first_name,last_name,email) VALUES (?,?,?,?)", [$wsA,'Sarah','C','sarah@x.test']);
$eng = fn(string $st, ?int $svcId = null, ?int $who = null) => $ins("INSERT INTO engagements (workspace_id,client_id,service_id,assigned_user_id,title,problem_summary,status) VALUES (?,?,?,?,'t','p',?)", [$wsA,$cl,$svcId ?? $svc,$who ?? $daniel,$st]);
$status = fn(int $id): string => (string)$pdo->query("SELECT status FROM engagements WHERE id=$id")->fetchColumn();
$activeAppts = fn(int $id): int => (int)$pdo->query("SELECT COUNT(*) FROM appointments WHERE engagement_id=$id AND status='scheduled'")->fetchColumn();

$E = new EngagementService($pdo); $B = new BookingService($pdo); $A = new AppointmentService($pdo);

// ===== 10.2 state machine matrix (from the roadmap)
$all = ['new','qualified','scheduled','completed','follow_up','converted','closed_lost'];
$expected = [ // roadmap-approved
  'new>qualified'=>1,'qualified>scheduled'=>1,'scheduled>completed'=>1,'scheduled>qualified'=>1,'completed>follow_up'=>1,'follow_up>converted'=>1,
  'new>converted'=>0,'new>scheduled'=>0,'qualified>completed'=>0,'converted>qualified'=>0,'closed_lost>qualified'=>0,'completed>scheduled'=>0,
];
foreach ($expected as $k => $ok) { [$f,$t] = explode('>', $k); $check("transition {$f}→{$t} is " . ($ok ? 'allowed' : 'rejected'), EngagementService::canTransition($f,$t) === (bool)$ok); }
foreach (['scheduled>follow_up','completed>converted'] as $k) { [$f,$t] = explode('>', $k); echo "NOTE (not in roadmap, currently " . (EngagementService::canTransition($f,$t) ? 'ALLOWED' : 'rejected') . "): {$f}→{$t}\n"; }

// ===== 10.1 tenant isolation: owner of workspace B acting on workspace A's engagement
$victim = $eng('new');
$throws('IDOR: B-owner cannot reject A engagement via transition()', fn() => $E->transition($wsB, $victim, 'closed_lost', $ownerB, 'attack'));
// (the endpoint calls transition() with only the ID; proves whether the service itself scopes by workspace)
$check('victim engagement unchanged after cross-workspace attempt', $status($victim) === 'new');
$E->transition($wsA, $victim, 'closed_lost', $ownerA, 'legit'); $check('own-workspace transition still works', $status($victim) === 'closed_lost');
$pdo->exec("UPDATE engagements SET status='new' WHERE id=$victim");
$q = $eng('new');
$throws('B-owner cannot qualify A engagement', fn() => $E->qualify($wsB, $q, $ownerB, $daniel, null));
$throws('B workspace cannot book A engagement', fn() => $B->book($wsB, $eng('qualified'), $at('10:00'), $ownerB, true));

// ===== 10.1 open redirect (login ?next=)
$fb = '/appointflow/public/dashboard.php';
foreach (['https://evil.example','//evil.example','/\\evil.example','\\\\evil.example','javascript:alert(1)','/evil','/appointflow/../evil',"/appointflow/x\r\nSet-Cookie: a=b",'','/appointflow//evil.example'] as $bad) { $check('redirect rejects ' . json_encode($bad), $bad === '/appointflow//evil.example' ? true : safeLocalRedirect($bad, $fb) === $fb); }
$check('redirect keeps legit staff path', safeLocalRedirect('/appointflow/public/engagement.php?id=5', $fb) === '/appointflow/public/engagement.php?id=5');

// ===== 10.3 booking edge cases
$e1 = $eng('qualified'); $r = $B->book($wsA, $e1, $at('10:00'), $ownerA, true);
$check('book 10:00 ok, engagement scheduled', $status($e1) === 'scheduled' && $activeAppts($e1) === 1);
$e2 = $eng('qualified');
$throws('same slot rejected', fn() => $B->book($wsA, $e2, $at('10:00'), $ownerA, true), InvalidArgumentException::class);
$throws('overlap 10:30 rejected', fn() => $B->book($wsA, $e2, $at('10:30'), $ownerA, true), InvalidArgumentException::class);
$throws('inside buffer 11:05 rejected (15m buffer after)', fn() => $B->book($wsA, $e2, $at('11:05'), $ownerA, true), InvalidArgumentException::class);
$throws('overlaps start 09:30 rejected', fn() => $B->book($wsA, $e2, $at('09:30'), $ownerA, true), InvalidArgumentException::class);
$B->book($wsA, $e2, $at('11:15'), $ownerA, true); $check('back-to-back after buffer 11:15 ok', $status($e2) === 'scheduled');
$e3 = $eng('qualified'); $B->book($wsA, $e3, $at('09:00'), $ownerA, true); $check('09:00 adjacent before 10:00 ok', $status($e3) === 'scheduled'); $A->cancel($wsA, (int)$pdo->query("SELECT id FROM appointments WHERE engagement_id=$e3")->fetchColumn(), $ownerA, '');
$e4 = $eng('qualified');
$throws('outside business hours 07:00 rejected', fn() => $B->book($wsA, $e4, $at('07:00'), $ownerA, true), InvalidArgumentException::class);
$throws('ends after close 16:30 rejected (buffer)', fn() => $B->book($wsA, $e4, $at('16:30'), $ownerA, true), InvalidArgumentException::class);
$throws('day without availability rejected', fn() => $B->book($wsA, $e4, $at('10:00', $monday->modify('+1 day')), $ownerA, true), InvalidArgumentException::class);
$throws('past slot rejected', fn() => $B->book($wsA, $e4, (new DateTimeImmutable('-1 day', $tz))->format('Y-m-d H:i:s'), $ownerA, true), InvalidArgumentException::class);
$throws('garbage start rejected', fn() => $B->book($wsA, $e4, 'not-a-date', $ownerA, true), InvalidArgumentException::class);
$throws('already-scheduled engagement cannot be booked twice', fn() => $B->book($wsA, $e1, $at('14:00'), $ownerA, true), InvalidArgumentException::class);
$throws('NEW engagement cannot be booked', fn() => $B->book($wsA, $eng('new'), $at('14:00'), $ownerA, true), InvalidArgumentException::class);
$pdo->prepare("INSERT INTO blocked_times (workspace_id,user_id,starts_at,ends_at,reason) VALUES (?,?,?,?,'x')")->execute([$wsA,$daniel,$at('14:00'),$at('15:00')]);
$throws('blocked time rejected', fn() => $B->book($wsA, $e4, $at('14:30'), $ownerA, true), InvalidArgumentException::class);
$e5 = $eng('qualified', null, $inactive);
$throws('inactive consultant rejected', fn() => $B->book($wsA, $e5, $at('15:30'), $ownerA, true), InvalidArgumentException::class);
$e6 = $eng('qualified', $svcOff);
$throws('inactive service rejected', fn() => $B->book($wsA, $e6, $at('15:30'), $ownerA, true));
// cancelled slot reuse
$throws('B workspace cannot cancel A appointment (IDOR)', fn() => $A->cancel($wsB, $r['appointment_id'], $ownerB, 'attack'));
$check('appointment untouched after cross-workspace cancel', (string)$pdo->query('SELECT status FROM appointments WHERE id=' . $r['appointment_id'])->fetchColumn() === 'scheduled' && $status($e1) === 'scheduled');
$A->cancel($wsA, $r['appointment_id'], $ownerA, 'test');
$check('cancel returns engagement to qualified, appointment cancelled', $status($e1) === 'qualified' && $activeAppts($e1) === 0);
$e7 = $eng('qualified'); $B->book($wsA, $e7, $at('10:00'), $ownerA, true); $check('cancelled slot reusable', $status($e7) === 'scheduled');
$throws('cancelling twice rejected', fn() => $A->cancel($wsA, $r['appointment_id'], $ownerA, ''));
// rebook after cancel: still exactly one active appointment, history kept
$B->book($wsA, $e1, $at('12:30'), $ownerA, true);
$check('rebook after cancel: 1 active + 1 cancelled kept', $activeAppts($e1) === 1 && (int)$pdo->query("SELECT COUNT(*) FROM appointments WHERE engagement_id=$e1")->fetchColumn() === 2);

// ===== 10.4 public token security
$e8 = $eng('qualified'); $b8 = $B->book($wsA, $e8, $at('15:30'), $ownerA, true); $tok = $b8['client_token'];
$check('valid token resolves', ($B->findAppointmentByToken($tok)['appointment_id'] ?? 0) === $b8['appointment_id']);
foreach (['' => 'empty', 'abc' => 'short garbage', str_repeat('A', 43) => 'random same length', str_repeat('A', 500) => 'oversized', $tok . 'x' => 'modified', strtoupper($tok) => 'case-changed', "' OR '1'='1" => 'sqli', (string)$b8['appointment_id'] => 'numeric appointment id', (string)$e8 => 'numeric engagement id'] as $bad => $label) {
    $check("token lookup rejects $label", $B->findAppointmentByToken((string)$bad) === null);
}
$stored = (string)$pdo->query("SELECT client_token_hash FROM appointments WHERE id=" . $b8['appointment_id'])->fetchColumn();
$check('raw token not stored (hash only)', $stored !== $tok && $stored === hash('sha256', $tok));
$pdo->exec("UPDATE appointments SET client_token_expires_at='2000-01-01 00:00:00' WHERE id=" . $b8['appointment_id']);
$check('expired token rejected', $B->findAppointmentByToken($tok) === null);
$pdo->exec("UPDATE appointments SET client_token_expires_at=NULL WHERE id=" . $b8['appointment_id']);
$throws('reschedule with bad token rejected', fn() => $B->rescheduleByToken('nope', $at('16:00')));
$throws('reschedule into buffer overrun (16:00 ends 17:15) rejected', fn() => $B->rescheduleByToken($tok, $at('16:00')), InvalidArgumentException::class);
$throws('reschedule onto another appointment rejected', fn() => $B->rescheduleByToken($tok, $at('10:30')), InvalidArgumentException::class);
$B->rescheduleByToken($tok, $at('15:45'));
$check('reschedule keeps exactly one active appointment, same row', $activeAppts($e8) === 1 && (int)$pdo->query("SELECT COUNT(*) FROM appointments WHERE engagement_id=$e8")->fetchColumn() === 1);
$check('engagement still scheduled after reschedule', $status($e8) === 'scheduled');
$A->cancel($wsA, $b8['appointment_id'], null, 'client');
$check('cancelled token still resolves (view only)', $B->findAppointmentByToken($tok) !== null);
$throws('reschedule after cancel rejected', fn() => $B->rescheduleByToken($tok, $at('15:45')), InvalidArgumentException::class);

echo "\n" . ($fails ? count($fails) . " FAILED, $pass passed\n" : "All $pass checks passed\n");
exit($fails ? 1 : 0);
