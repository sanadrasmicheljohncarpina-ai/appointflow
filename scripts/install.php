<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once __DIR__ . '/../config/app.php';

$options = getopt('', ['business:', 'email:', 'first:', 'last:', 'password:', 'phone::', 'slug::']);
$business = trim((string)($options['business'] ?? ''));
$email = strtolower(trim((string)($options['email'] ?? '')));
$first = trim((string)($options['first'] ?? ''));
$last = trim((string)($options['last'] ?? ''));
$password = (string)($options['password'] ?? '');
$phone = trim((string)($options['phone'] ?? ''));
$slug = trim((string)($options['slug'] ?? ''));

if ($business === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $first === '' || $last === '' || strlen($password) < 8) {
    fwrite(STDERR, "Usage:\n  php scripts/install.php --business=\"Northstar Digital Consulting\" --email=owner@example.com --first=Maya --last=Owner --password=ChangeMe123! [--phone=...] [--slug=northstar]\n");
    exit(2);
}

if ($slug === '') {
    $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $business), '-'));
}
if ($slug === '') $slug = 'business';

$serverDsn = 'mysql:host=' . APP_DB_HOST . ';charset=' . APP_DB_CHARSET;
$pdo = new PDO($serverDsn, APP_DB_USER, APP_DB_PASSWORD, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$pdo->exec('CREATE DATABASE IF NOT EXISTS appointflow CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo->exec('USE appointflow');

$migrations = glob(__DIR__ . '/../database/migrations/*.sql');
sort($migrations, SORT_STRING);
foreach ($migrations as $migration) {
    $sql = file_get_contents($migration);
    if ($sql === false) throw new RuntimeException('Unable to read migration: ' . basename($migration));
    $pdo->exec($sql);
    fwrite(STDOUT, 'Applied ' . basename($migration) . "\n");
}

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare('SELECT id FROM workspaces WHERE booking_slug = ? LIMIT 1 FOR UPDATE');
    $stmt->execute([$slug]);
    $workspaceId = $stmt->fetchColumn();
    if (!$workspaceId) {
        $stmt = $pdo->prepare('INSERT INTO workspaces(name,email,phone,timezone,booking_slug,status) VALUES(?,?,?,?,?,\'active\')');
        $stmt->execute([$business, $email, $phone !== '' ? $phone : null, 'Asia/Manila', $slug]);
        $workspaceId = (int)$pdo->lastInsertId();
    } else {
        $workspaceId = (int)$workspaceId;
        $pdo->prepare('UPDATE workspaces SET name=?, email=?, phone=? WHERE id=?')->execute([$business,$email,$phone!==''?$phone:null,$workspaceId]);
    }

    $stmt = $pdo->prepare('SELECT id FROM users WHERE workspace_id=? AND email=? LIMIT 1 FOR UPDATE');
    $stmt->execute([$workspaceId, $email]);
    $userId = $stmt->fetchColumn();
    $hash = password_hash($password, PASSWORD_DEFAULT);
    if ($userId) {
        $pdo->prepare('UPDATE users SET first_name=?,last_name=?,password_hash=?,role=\'owner\',status=\'active\' WHERE id=?')->execute([$first,$last,$hash,(int)$userId]);
    } else {
        $pdo->prepare('INSERT INTO users(workspace_id,first_name,last_name,email,password_hash,role,status) VALUES(?,?,?,?,?,\'owner\',\'active\')')->execute([$workspaceId,$first,$last,$email,$hash]);
        $userId = (int)$pdo->lastInsertId();
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}

echo "\nAppointFlow is installed.\nWorkspace: {$business}\nOwner: {$email}\nURL: http://localhost/appointflow/public/login.php\n";
