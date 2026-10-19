<?php
declare(strict_types=1);
/**
 * SMTP failure-injection test. Needs a scratch DB named *_test (migrations loaded) and fake_smtp.py
 * listening on ports 2501-2509 (see tests/smtp_failure_run.sh). DESTRUCTIVE to the scratch DB.
 */
if (PHP_SAPI !== 'cli') { exit("CLI only.\n"); }
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/services/NotificationService.php';

$dbName = (string)(getenv('AF_TEST_DB') ?: '');
if ($dbName === '' || !str_ends_with($dbName, '_test')) { fwrite(STDERR, "Set AF_TEST_DB to a *_test database.\n"); exit(2); }
$pdo = new PDO('mysql:host=127.0.0.1;dbname=' . $dbName . ';charset=utf8mb4', 'root', '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach (['notification_log','engagement_events','appointments','engagements','clients','users','workspaces'] as $t) { $pdo->exec("DELETE FROM $t"); }
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
$ins = function (string $sql, array $p = []) use ($pdo): int { $pdo->prepare($sql)->execute($p); return (int)$pdo->lastInsertId(); };

$ws = $ins("INSERT INTO workspaces (name,email,booking_slug,timezone) VALUES ('Northstar','n@example.com','northstar','Asia/Manila')");
$user = $ins("INSERT INTO users (workspace_id,first_name,last_name,email,password_hash,role) VALUES (?, 'Olivia','Owner','o@example.com','x','owner')", [$ws]);
$svc = $ins("INSERT INTO services (workspace_id,name,duration_minutes) VALUES (?, 'Strategy', 60)", [$ws]);
$mk = function (string $email) use ($ins, $ws, $svc, $user): int {
    $c = $ins("INSERT INTO clients (workspace_id,first_name,last_name,email) VALUES (?, 'Cora','Client',?)", [$ws, $email]);
    $e = $ins("INSERT INTO engagements (workspace_id,client_id,service_id,assigned_user_id,title,problem_summary,status) VALUES (?,?,?,?, 'Case','Needs help','scheduled')", [$ws, $c, $svc, $user]);
    return $ins("INSERT INTO appointments (engagement_id,assigned_user_id,starts_at,ends_at,client_token_hash,status) VALUES (?,?, DATE_ADD(NOW(), INTERVAL 5 HOUR), DATE_ADD(NOW(), INTERVAL 6 HOUR),'h','scheduled')", [$e, $user, ]);
};

$fails = 0; $n = 0;
$check = function (string $label, bool $ok, string $extra = '') use (&$fails, &$n): void {
    $n++; if (!$ok) { $fails++; fwrite(STDERR, "FAIL: $label $extra\n"); } else { echo "ok   $label\n"; }
};

$cases = [
    // label, mailer config overrides, recipient, expected max seconds
    'connection refused'      => [['host'=>'127.0.0.1','port'=>2599], 'cora@example.com', 5],
    'unresolvable host'       => [['host'=>'no-such-host.invalid','port'=>25], 'cora@example.com', 12],
    'auth failure 535'        => [['port'=>2502,'username'=>'u','password'=>'S3cretPW!'], 'cora@example.com', 5],
    'rejected recipient 550'  => [['port'=>2503], 'cora@example.com', 5],
    'greylisted 450'          => [['port'=>2504], 'cora@example.com', 5],
    'data rejected 554'       => [['port'=>2505], 'cora@example.com', 5],
    'server closes at once'   => [['port'=>2506], 'cora@example.com', 5],
    'silent server (no banner)' => [['port'=>2507,'timeout'=>5], 'cora@example.com', 12],
    'silent after banner'     => [['port'=>2508,'timeout'=>5], 'cora@example.com', 12],
    'slow-drip banner'        => [['port'=>2509,'timeout'=>5], 'cora@example.com', 8],
    'malformed recipient'     => [['port'=>2501], 'not an address', 3],
    'success'                 => [['port'=>2501], 'cora@example.com', 5],
];
foreach ($cases as $label => [$over, $email, $maxSecs]) {
    $appt = $mk($email);
    $before = $pdo->query("SELECT status, starts_at FROM appointments WHERE id=$appt")->fetch();
    $mailer = new MailService(array_merge(['driver'=>'smtp','host'=>'127.0.0.1','port'=>2501,'encryption'=>'none','username'=>'','password'=>'','from_email'=>'','from_name'=>'','timeout'=>5,'verify_peer'=>true,'base_url'=>'http://localhost'], $over));
    $svcN = new NotificationService($pdo, $mailer);
    $pdo->prepare("INSERT INTO notification_log (workspace_id,engagement_id,appointment_id,recipient_email,notification_type,status) SELECT e.workspace_id,e.id,a.id,?, 'confirmation','pending' FROM appointments a JOIN engagements e ON e.id=a.engagement_id WHERE a.id=?")->execute([$email, $appt]);
    $t = microtime(true);
    $r = $svcN->dispatch($appt, 'confirmation', 'rawtoken');
    $dt = microtime(true) - $t;
    $after = $pdo->query("SELECT status, starts_at, confirmation_sent_at FROM appointments WHERE id=$appt")->fetch();
    $log = $pdo->query("SELECT status, error_message FROM notification_log WHERE appointment_id=$appt ORDER BY id")->fetchAll();
    $expectOk = $label === 'success';
    $check("$label: dispatch result ok=" . ($expectOk ? 'true' : 'false'), $r['ok'] === $expectOk);
    $check("$label: appointment unchanged", $after['status'] === $before['status'] && $after['starts_at'] === $before['starts_at']);
    $check("$label: returned within {$maxSecs}s (took " . round($dt, 1) . "s)", $dt <= $maxSecs);
    $check("$label: exactly one notification row", count($log) === 1, json_encode($log));
    $st = $log[0]['status'] ?? '?';
    $check("$label: row status " . ($expectOk ? 'sent' : 'failed') . " (got $st)", $st === ($expectOk ? 'sent' : 'failed'));
    $check("$label: confirmation_sent_at " . ($expectOk ? 'set' : 'not set'), $expectOk ? $after['confirmation_sent_at'] !== null : $after['confirmation_sent_at'] === null);
    if (!$expectOk) {
        $check("$label: error stored without credentials", !str_contains((string)$log[0]['error_message'], 'S3cretPW') && !str_contains((string)$log[0]['error_message'], 'rawtoken'));
        echo "     error_message: " . substr((string)$log[0]['error_message'], 0, 90) . "\n";
    }
}
echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
