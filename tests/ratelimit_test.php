<?php
declare(strict_types=1);

/** Unit tests for app/ratelimit.php. No database needed:  php tests/ratelimit_test.php */
if (PHP_SAPI !== 'cli') { exit("CLI only.\n"); }
require_once __DIR__ . '/../app/ratelimit.php';

$pass = 0; $fails = [];
$check = function (string $label, bool $ok) use (&$pass, &$fails): void { if ($ok) { $pass++; } else { $fails[] = $label; echo "FAIL: $label\n"; } };
$tmp = sys_get_temp_dir() . '/af_rl_' . bin2hex(random_bytes(4));
$t = 1_800_000_000;
$clock = function () use (&$t): int { return $t; };
$rl = new RateLimiter($tmp, $clock);

// ---- core window behaviour
for ($i = 1; $i <= 5; $i++) { $r = $rl->consume('b', 'k', 5, 60); $check("attempt $i of 5 allowed", $r['allowed'] && $r['count'] === $i && $r['remaining'] === 5 - $i); }
$r = $rl->consume('b', 'k', 5, 60); $check('6th attempt refused', !$r['allowed'] && $r['remaining'] === 0 && $r['retry_after'] === 60);
$t += 10; $r = $rl->consume('b', 'k', 5, 60); $check('refused attempts are not recorded; retry_after counts down from the OLDEST attempt', !$r['allowed'] && $r['retry_after'] === 50 && $r['count'] === 5);
$t += 50; $r = $rl->consume('b', 'k', 5, 60); $check('allowed again exactly when the window has passed', $r['allowed'] && $r['count'] === 1);
$check('peek does not count', $rl->peek('b', 'k', 5, 60)['count'] === 1 && $rl->peek('b', 'k', 5, 60)['count'] === 1);

// ---- sliding, not fixed: spread-out attempts expire one by one
$rl->clear('s', 'k'); $t = 2_000_000_000;
foreach ([0, 20, 40] as $off) { $t = 2_000_000_000 + $off; $rl->consume('s', 'k', 3, 60); }
$t = 2_000_000_000 + 59; $check('3 attempts inside the window -> refused', !$rl->consume('s', 'k', 3, 60)['allowed']);
$t = 2_000_000_000 + 61; $check('first one expired -> exactly one slot frees up', $rl->consume('s', 'k', 3, 60)['allowed'] && !$rl->consume('s', 'k', 3, 60)['allowed']);

// ---- isolation between buckets and keys
$rl->clear('i', 'a'); $rl->clear('i', 'b'); $rl->clear('j', 'a');
$rl->consume('i', 'a', 1, 60);
$check('same bucket+key is now blocked', !$rl->consume('i', 'a', 1, 60)['allowed']);
$check('other key unaffected', $rl->consume('i', 'b', 1, 60)['allowed']);
$check('other bucket unaffected', $rl->consume('j', 'a', 1, 60)['allowed']);

// ---- forgive / clear
$rl->clear('f', 'k'); $rl->consume('f', 'k', 2, 60); $rl->consume('f', 'k', 2, 60);
$check('blocked after 2', !$rl->consume('f', 'k', 2, 60)['allowed']);
$rl->forgive('f', 'k', 60);
$check('forgive gives exactly one attempt back', $rl->consume('f', 'k', 2, 60)['allowed'] && !$rl->consume('f', 'k', 2, 60)['allowed']);
$rl->clear('f', 'k'); $check('clear resets', $rl->peek('f', 'k', 2, 60)['count'] === 0);
$rl->forgive('never', 'used'); $check('forgive on an unused key is harmless', $rl->peek('never', 'used', 1, 60)['count'] === 0);

// ---- storage hygiene and robustness
$files = glob($tmp . '/*.rl');
$check('files are named by hash only (no readable key)', $files && !array_filter($files, fn($f) => str_contains($f, 'k') && !preg_match('/^[0-9a-f]{64}\.rl$/', basename($f))));
$one = $tmp . '/' . hash('sha256', 'c|k') . '.rl';
file_put_contents($one, "garbage\n-5\n99999999999999999999\n" . ($t + 99999) . "\n{$t}");
$r = $rl->consume('c', 'k', 3, 60); $check('corrupt/future entries ignored, valid one kept', $r['allowed'] && $r['count'] === 2);
$check('window is capped at 24h (a huge window cannot hoard state)', $rl->consume('w', 'k', 1, 10 ** 9)['allowed'] && !$rl->consume('w', 'k', 1, 10 ** 9)['allowed']);
touch($files[0], $t - 3 * 86400); $check('purge removes stale files', $rl->purge() >= 1);
$bad = new RateLimiter('/proc/nope/ratelimit', $clock);
$prev = ini_set('error_log', '/dev/null');
$r = $bad->consume('x', 'y', 1, 60); $r2 = $bad->consume('x', 'y', 1, 60);
$check('unwritable storage fails OPEN (never locks everyone out)', $r['allowed'] && $r2['allowed']);

// ---- client address: spoofing and proxies
$ip = fn(array $srv, array $trusted = []) => clientIp($srv, $trusted);
$check('plain REMOTE_ADDR used', $ip(['REMOTE_ADDR' => '198.51.100.7']) === '198.51.100.7');
$check('X-Forwarded-For IGNORED when no trusted proxies (header spoofing)', $ip(['REMOTE_ADDR' => '198.51.100.7', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4']) === '198.51.100.7');
$check('X-Forwarded-For ignored when peer is not a trusted proxy', $ip(['REMOTE_ADDR' => '198.51.100.7', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4'], ['10.0.0.0/8']) === '198.51.100.7');
$check('behind a trusted proxy the proxy-appended address is used', $ip(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9'], ['10.0.0.0/8']) === '203.0.113.9');
$check('client-forged left entries are skipped (right-most untrusted wins)', $ip(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 203.0.113.9, 10.0.0.7'], ['10.0.0.0/8']) === '203.0.113.9');
$check('garbage forwarded header falls back to the peer', $ip(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => 'nonsense'], ['10.0.0.0/8']) === '10.0.0.5');
$check('exact-IP trusted proxy works', $ip(['REMOTE_ADDR' => '192.0.2.1', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9'], ['192.0.2.1']) === '203.0.113.9');
$check('missing/invalid REMOTE_ADDR -> "unknown" bucket, not a crash', $ip([]) === 'unknown' && $ip(['REMOTE_ADDR' => 'zzz']) === 'unknown');
$check('IPv6 grouped by /64', $ip(['REMOTE_ADDR' => '2001:db8:1:2:aaaa:bbbb:cccc:dddd']) === $ip(['REMOTE_ADDR' => '2001:db8:1:2::1']) && $ip(['REMOTE_ADDR' => '2001:db8:1:2::1']) !== $ip(['REMOTE_ADDR' => '2001:db8:1:3::1']));
$check('IPv4-mapped IPv6 equals the IPv4 address', $ip(['REMOTE_ADDR' => '::ffff:198.51.100.7']) === '198.51.100.7');
$check('CIDR edge: /25 boundaries', ipInRange('192.0.2.127', '192.0.2.0/25') && !ipInRange('192.0.2.128', '192.0.2.0/25') && ipInRange('2001:db8::5', '2001:db8::/32') && !ipInRange('2001:db9::5', '2001:db8::/32') && !ipInRange('1.2.3.4', '2001:db8::/32'));

// ---- concurrency: 24 parallel processes, limit 10 -> exactly 10 allowed
$cdir = $tmp . '_conc'; $worker = $tmp . '_w.php';
file_put_contents($worker, '<?php require ' . var_export(__DIR__ . '/../app/ratelimit.php', true) . '; $r=(new RateLimiter($argv[1]))->consume("c","k",10,300); echo $r["allowed"]?"Y":"N";');
$procs = [];
for ($i = 0; $i < 24; $i++) { $procs[] = proc_open([PHP_BINARY, $worker, $cdir], [1 => ['pipe', 'w']], $pipes[$i]); }
$yes = 0; foreach ($procs as $i => $p) { $yes += stream_get_contents($pipes[$i][1]) === 'Y' ? 1 : 0; proc_close($p); }
$check("24 parallel attempts, limit 10 -> exactly 10 allowed (got $yes)", $yes === 10);

foreach ([$tmp, $cdir] as $d) { foreach (glob($d . '/*') ?: [] as $f) { @unlink($f); } @rmdir($d); } @unlink($worker);
echo "\n" . ($fails ? count($fails) . " FAILED, $pass passed\n" : "All $pass rate-limit checks passed\n");
exit($fails ? 1 : 0);
