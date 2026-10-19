<?php
declare(strict_types=1);
/**
 * Milestone 10.8 time/boundary tests for BookingService. Run through tests/timezone_boundary_run.sh,
 * which freezes the clock (libfaketime) so "now" can sit exactly at midnight or just before a DST change.
 * DESTRUCTIVE to a scratch DB whose name ends in _test.
 *
 *   SCEN=<name> AF_TEST_DB=appointflow_test faketime '2026-10-02 15:40:00' php tests/timezone_boundary_test.php
 * Times given to faketime are UTC (the machine clock); everything the app does is in workspace time.
 */
if (PHP_SAPI !== 'cli') { exit("CLI only.\n"); }
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/services/BookingService.php';
require_once __DIR__ . '/../app/services/AvailabilityService.php';

$dbName = (string)(getenv('AF_TEST_DB') ?: '');
if ($dbName === '' || !str_ends_with($dbName, '_test')) { fwrite(STDERR, "Set AF_TEST_DB to a *_test database.\n"); exit(2); }
$scen = (string)(getenv('SCEN') ?: '');
$pdo = new PDO('mysql:host=127.0.0.1;dbname=' . $dbName . ';charset=utf8mb4', 'root', '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
if (getenv('DB_TZ')) { $pdo->exec("SET time_zone = " . $pdo->quote((string)getenv('DB_TZ'))); }
if (getenv('PHP_TZ')) { date_default_timezone_set((string)getenv('PHP_TZ')); }

$fails = 0; $n = 0;
$check = function (string $label, bool $ok, string $detail = '') use (&$fails, &$n, $scen): void {
    $n++; if ($ok) { echo "ok   [$scen] $label\n"; } else { $fails++; echo "FAIL [$scen] $label $detail\n"; }
};
$throws = function (callable $f): ?string { try { $f(); return null; } catch (Throwable $e) { return $e->getMessage(); } };

/** Fresh workspace + owner + service + 7-day rules. Returns [ws, user, svc]. */
function seedWorkspace(PDO $pdo, string $tz, int $dur, int $bufB, int $bufA, string $from, string $to): array {
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach (['notification_log','engagement_events','appointments','engagements','clients','blocked_times','availability_rules','services','users','workspaces'] as $t) { $pdo->exec("DELETE FROM $t"); }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    $pdo->prepare("INSERT INTO workspaces (name,email,booking_slug,timezone) VALUES ('W','w@example.com','w',?)")->execute([$tz]);
    $ws = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO users (workspace_id,first_name,last_name,email,password_hash,role) VALUES (?, 'O','O','o@example.com','x','consultant')")->execute([$ws]);
    $u = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO services (workspace_id,name,duration_minutes,buffer_before_minutes,buffer_after_minutes) VALUES (?, 'S', ?, ?, ?)")->execute([$ws, $dur, $bufB, $bufA]);
    $s = (int)$pdo->lastInsertId();
    $av = new AvailabilityService($pdo);
    for ($d = 0; $d < 7; $d++) { $av->addRule($ws, null, $d, $from, $to); }
    return [$ws, $u, $s];
}
function newEngagement(PDO $pdo, int $ws, int $u, int $s): int {
    $pdo->prepare("INSERT INTO clients (workspace_id,first_name,last_name,email) VALUES (?, 'C','C','c@example.com')")->execute([$ws]);
    $c = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO engagements (workspace_id,client_id,service_id,assigned_user_id,title,problem_summary,status) VALUES (?,?,?,?, 'T','P','qualified')")->execute([$ws, $c, $s, $u]);
    return (int)$pdo->lastInsertId();
}
$starts = fn(array $slots): array => array_column($slots, 'start');
$localNow = fn(string $tz): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone($tz));

switch ($scen) {
case 'tz_independence': {
    // Same frozen instant, several workspace zones. "Today" and "now" must come from the workspace zone,
    // regardless of PHP's default zone or the MariaDB session zone (DB_TZ / PHP_TZ env).
    foreach (['Asia/Manila', 'America/New_York', 'Pacific/Kiritimati', 'Pacific/Pago_Pago', 'UTC'] as $tz) {
        [$ws, $u, $s] = seedWorkspace($pdo, $tz, 30, 0, 0, '00:00', '23:59');
        $svc = new BookingService($pdo);
        $now = $localNow($tz);
        $today = $now->format('Y-m-d');
        $yesterday = $now->modify('-1 day')->format('Y-m-d');
        $eng = newEngagement($pdo, $ws, $u, $s);
        $slots = $starts($svc->getAvailableSlots($ws, $eng, $today, $u, true));
        $nowStr = $now->format('Y-m-d H:i:s');
        $firstExpected = $now->setTime((int)$now->format('H'), (int)(ceil((int)$now->format('i') / 15) * 15) % 60, 0);
        if ((int)ceil((int)$now->format('i') / 15) * 15 >= 60) { $firstExpected = $now->setTime((int)$now->format('H'), 0, 0)->modify('+1 hour'); }
        if ($firstExpected <= $now) { $firstExpected = $firstExpected->modify('+15 minutes'); }
        $check("$tz: 'today' is the workspace's today ($today, now $nowStr)", $slots !== [] || $now->format('H:i') >= '23:15');
        $check("$tz: no slot is in the past", $slots === [] || $slots[0] > $nowStr, 'first=' . ($slots[0] ?? '-'));
        $check("$tz: first slot is the next quarter-hour after now (" . $firstExpected->format('H:i') . ")",
            $slots === [] || $slots[0] === $firstExpected->format('Y-m-d H:i:s'), 'first=' . ($slots[0] ?? '-'));
        $check("$tz: yesterday has no slots", $svc->getAvailableSlots($ws, $eng, $yesterday, $u, true) === []);
        $past = $now->modify('-1 minute')->format('Y-m-d H:i:s');
        $err = $throws(fn() => $svc->book($ws, $eng, $past, $u, true));
        $check("$tz: booking 1 minute ago is refused", $err !== null && str_contains($err, 'past'), (string)$err);
        if ($slots !== []) {
            $r = $svc->book($ws, $eng, $slots[0], $u, true);
            $check("$tz: booking the first offered slot works and stores workspace wall time", $r['starts_at'] === $slots[0]);
        }
    }
    break;
}
case 'midnight': {
    // Frozen at 23:40 workspace time (Asia/Manila).
    [$ws, $u, $s] = seedWorkspace($pdo, 'Asia/Manila', 30, 0, 0, '22:00', '23:59');
    $svc = new BookingService($pdo); $av = new AvailabilityService($pdo);
    $now = $localNow('Asia/Manila');
    $check('clock is 23:40 Manila', $now->format('H:i') === '23:40', $now->format('c'));
    $today = $now->format('Y-m-d'); $tomorrow = $now->modify('+1 day')->format('Y-m-d');
    $eng = newEngagement($pdo, $ws, $u, $s);
    $check('23:40 now: nothing left today (30-min meeting cannot finish before 23:59)', $svc->getAvailableSlots($ws, $eng, $today, $u, true) === []);
    $t = $starts($svc->getAvailableSlots($ws, $eng, $tomorrow, $u, true));
    $check('tomorrow opens at 22:00 and last start is 23:15 (ends 23:45)', ($t[0] ?? '') === "$tomorrow 22:00:00" && end($t) === "$tomorrow 23:15:00", json_encode([$t[0] ?? null, end($t)]));
    $err = $throws(fn() => $svc->book($ws, $eng, "$tomorrow 23:45:00", $u, true));
    $check('23:45 + 30 min would end after midnight: refused', $err !== null && str_contains($err, 'availability'), (string)$err);
    $err = $throws(fn() => $av->addRule($ws, null, 1, '22:00', '02:00'));
    $check('availability window crossing midnight is rejected cleanly', $err !== null && str_contains($err, 'end after'), (string)$err);
    // 15-min service: the last quarter-hour of the day
    $pdo->exec("UPDATE services SET duration_minutes = 15");
    $t = $starts($svc->getAvailableSlots($ws, $eng, $tomorrow, $u, true));
    echo "info [$scen] 15-min service, last offered start: " . end($t) . " (window ends 23:59, so 23:45-00:00 is not offerable)\n";
    $check('no slot is offered that ends on the next calendar day', substr((string)end($t), 0, 10) === $tomorrow && end($t) <= "$tomorrow 23:44:59");
    break;
}
case 'blocked_midnight': {
    [$ws, $u, $s] = seedWorkspace($pdo, 'Asia/Manila', 60, 0, 0, '00:00', '23:59');
    $svc = new BookingService($pdo); $av = new AvailabilityService($pdo);
    $eng = newEngagement($pdo, $ws, $u, $s);
    $d1 = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->modify('+3 days')->format('Y-m-d');
    $d2 = (new DateTimeImmutable($d1))->modify('+1 day')->format('Y-m-d');
    $av->addBlockedTime($ws, null, "$d1 22:00:00", "$d2 02:00:00", 'overnight');
    $a = $starts($svc->getAvailableSlots($ws, $eng, $d1, $u, true));
    $b = $starts($svc->getAvailableSlots($ws, $eng, $d2, $u, true));
    $check('evening before the block: 20:45 -> 21:45 fits, 21:15 -> 22:15 does not', in_array("$d1 20:45:00", $a, true) && !in_array("$d1 21:15:00", $a, true));
    $check('day 1 offers nothing at/after 21:00 start', array_filter($a, fn($x) => $x >= "$d1 21:01:00") === []);
    $check('day 2 offers nothing before 02:00', array_filter($b, fn($x) => $x < "$d2 02:00:00") === [], json_encode(array_slice($b, 0, 3)));
    $check('day 2 first slot is exactly 02:00', ($b[0] ?? '') === "$d2 02:00:00", (string)($b[0] ?? '-'));
    $e1 = $throws(fn() => $svc->book($ws, $eng, "$d2 01:30:00", $u, true));
    $check('booking inside the second half of the block is refused', $e1 !== null && str_contains($e1, 'blocked'), (string)$e1);
    $e2 = $throws(fn() => $svc->book($ws, $eng, "$d1 21:30:00", $u, true));
    $check('booking that runs into the block from before midnight is refused', $e2 !== null, 'was accepted');
    $r = $svc->book($ws, $eng, "$d2 02:00:00", $u, true);
    $check('booking right after the block ends is accepted', $r['starts_at'] === "$d2 02:00:00");
    break;
}
case 'buffer_midnight': {
    // A buffer can only reach past midnight if the service's buffer is edited after bookings exist.
    [$ws, $u, $s] = seedWorkspace($pdo, 'Asia/Manila', 30, 0, 0, '00:00', '23:59');
    $svc = new BookingService($pdo);
    $d1 = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->modify('+3 days')->format('Y-m-d');
    $d2 = (new DateTimeImmutable($d1))->modify('+1 day')->format('Y-m-d');
    $e1 = newEngagement($pdo, $ws, $u, $s);
    $svc->book($ws, $e1, "$d1 23:15:00", $u, true);                       // 23:15 - 23:45
    $pdo->exec('UPDATE services SET buffer_after_minutes = 30');           // now blocks until 00:15
    $e2 = newEngagement($pdo, $ws, $u, $s);
    $b = $starts($svc->getAvailableSlots($ws, $e2, $d2, $u, true));
    $check('next day 00:00 is blocked by last night\'s buffer', !in_array("$d2 00:00:00", $b, true));
    $check('next day 00:15 is free', in_array("$d2 00:15:00", $b, true), json_encode(array_slice($b, 0, 3)));
    $err = $throws(fn() => $svc->book($ws, $e2, "$d2 00:00:00", $u, true));
    $check('direct booking at 00:00 is refused too', $err !== null, 'was accepted');
    break;
}
case 'dst_fall_back':
case 'dst_fall_back_london':
case 'dst_spring_forward':
case 'dst_spring_sydney': {
    $cfg = [
        'dst_fall_back'        => ['America/New_York', '2026-11-01'],
        'dst_fall_back_london' => ['Europe/London',    '2026-10-25'],
        'dst_spring_forward'   => ['America/New_York', '2027-03-14'],
        'dst_spring_sydney'    => ['Australia/Sydney', '2026-10-04'],
    ][$scen];
    [$tz, $day] = $cfg; $zone = new DateTimeZone($tz);
    $fall = str_starts_with($scen, 'dst_fall');
    [$ws, $u, $s] = seedWorkspace($pdo, $tz, 30, 0, 0, '00:00', '23:59');
    $svc = new BookingService($pdo);
    $e1 = newEngagement($pdo, $ws, $u, $s);
    $slots = $svc->getAvailableSlots($ws, $e1, $day, $u, true);
    $st = $starts($slots);
    $check("$tz $day: slot list has no duplicate wall times", count($st) === count(array_unique($st)));
    $check("$tz $day: slots are in ascending wall-clock order", $st === array_values($st) && $st === (function ($a) { sort($a); return $a; })($st));
    // every offered slot must round-trip: wall time -> instant -> wall time, and end after start in real time
    $bad = [];
    foreach ($slots as $sl) {
        $a = new DateTimeImmutable($sl['start'], $zone); $b = new DateTimeImmutable($sl['end'], $zone);
        if ($a->format('Y-m-d H:i:s') !== $sl['start'] || $b->getTimestamp() - $a->getTimestamp() !== 1800) { $bad[] = $sl['start'] . '->' . $sl['end']; }
    }
    $check("$tz $day: every offered slot is exactly 30 real minutes when read back", $bad === [], implode(', ', array_slice($bad, 0, 4)));
    if (!$fall) {
        $gapHour = $scen === 'dst_spring_forward' ? '02' : '02';
        $inGap = array_filter($st, fn($x) => str_starts_with(substr($x, 11), $gapHour));
        $check("$tz $day: no slot starts in the skipped hour", $inGap === [], implode(',', $inGap));
        $err = $throws(fn() => $svc->book($ws, $e1, "$day 02:30:00", $u, true));
        $check("$tz $day: booking a nonexistent 02:30 is refused", $err !== null && str_contains($err, 'valid'), (string)$err);
        // 01:30 + 30 real minutes crosses the gap; then 03:00 must conflict with a meeting that really runs to 03:00
        $r = $svc->book($ws, $e1, "$day 01:30:00", $u, true);
        $check("$tz $day: 01:30 meeting ends at 03:00 wall time (30 real minutes)", $r['ends_at'] === "$day 03:00:00", $r['ends_at']);
        $e2 = newEngagement($pdo, $ws, $u, $s);
        $err = $throws(fn() => $svc->book($ws, $e2, "$day 01:45:00", $u, true));
        $check("$tz $day: 01:45 overlaps that meeting and is refused", $err !== null, 'accepted -> double booking');
        $r2 = $throws(fn() => $svc->book($ws, $e2, "$day 03:00:00", $u, true));
        $check("$tz $day: 03:00 (right after it) is accepted", $r2 === null, (string)$r2);
    } else {
        // PHP resolves a repeated wall time to the first pass in some zones (New York) and the second in
        // others (London), so assert the property that matters, not a particular half of the hour.
        $all = ['01:00:00', '01:15:00', '01:30:00', '01:45:00'];
        $offered = array_values(array_filter($all, fn($t) => in_array("$day $t", $st, true)));
        echo "info [$scen] offered in the repeated hour: " . implode(',', array_map(fn($t) => substr($t, 0, 5), $offered)) . "\n";
        $check("$tz $day: the repeated hour still offers some slots, none twice", count($offered) >= 1 && count($offered) === count(array_unique($offered)));
        foreach (array_diff($all, $offered) as $t) {
            $err = $throws(fn() => $svc->book($ws, $e1, "$day $t", $u, true));
            $check("$tz $day: booking the withheld time $t is refused with the daylight-saving message", $err !== null && str_contains($err, 'daylight'), (string)$err);
        }
        $last = end($offered);
        $r = $svc->book($ws, $e1, "$day $last", $u, true);
        $a = new DateTimeImmutable($r['starts_at'], $zone); $b = new DateTimeImmutable($r['ends_at'], $zone);
        $check("$tz $day: meeting booked at $last reads back as 30 real minutes (stored {$r['starts_at']} -> {$r['ends_at']})", $b->getTimestamp() - $a->getTimestamp() === 1800);
        $e2 = newEngagement($pdo, $ws, $u, $s);
        $before = $a->setTimestamp($a->getTimestamp() - 900)->format('Y-m-d H:i:s');
        if (in_array($before, $st, true)) {
            $err = $throws(fn() => $svc->book($ws, $e2, $before, $u, true));
            $check("$tz $day: the overlapping slot 15 minutes earlier is refused (no double-booking)", $err !== null, 'accepted');
        }
        $err = $throws(fn() => $svc->book($ws, $e2, $r['starts_at'], $u, true));
        $check("$tz $day: the identical time is refused", $err !== null, 'accepted');
        if (in_array($r['ends_at'], $st, true)) {
            $err = $throws(fn() => $svc->book($ws, $e2, $r['ends_at'], $u, true));
            $check("$tz $day: the slot starting exactly when it ends is accepted", $err === null, (string)$err);
        }
        $ok = true;
        foreach ($pdo->query("SELECT starts_at, ends_at FROM appointments")->fetchAll() as $rw) {
            $ok = $ok && (new DateTimeImmutable($rw['ends_at'], $zone))->getTimestamp() - (new DateTimeImmutable($rw['starts_at'], $zone))->getTimestamp() === 1800;
        }
        $check("$tz $day: every stored meeting reads back as a positive 30-minute interval", $ok);
        $e3 = newEngagement($pdo, $ws, $u, $s);
        $err = $throws(fn() => $svc->book($ws, $e3, "$day 10:00:00", $u, true));
        $check("$tz $day: an ordinary 10:00 booking still works", $err === null, (string)$err);
    }
    break;
}
default:
    fwrite(STDERR, "Unknown SCEN '$scen'\n"); exit(2);
}
echo "[$scen] $n checks, $fails failed\n";
exit($fails ? 1 : 0);
