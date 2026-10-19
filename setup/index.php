<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../app/security.php';

if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('Setup is available only from localhost.');
}

$lockFile = __DIR__ . '/../storage/install.lock';
if (is_file($lockFile)) {
    http_response_code(410);
    exit('AppointFlow has already been installed. Remove storage/install.lock only if you intentionally need to reinstall the local development environment.');
}

$error = null;
$done = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $business = trim((string)($_POST['business_name'] ?? ''));
        $email = strtolower(trim((string)($_POST['owner_email'] ?? '')));
        $first = trim((string)($_POST['owner_first'] ?? ''));
        $last = trim((string)($_POST['owner_last'] ?? ''));
        $password = (string)($_POST['owner_password'] ?? '');
        $phone = trim((string)($_POST['phone'] ?? ''));
        $slug = trim((string)($_POST['booking_slug'] ?? ''));
        if ($business === '' || $first === '' || $last === '' || !filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
            throw new InvalidArgumentException('Business name, owner details, a valid email, and an 8+ character password are required.');
        }
        if ($slug === '') $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i','-', $business), '-')) ?: 'business';
        $pdo = new PDO('mysql:host=' . APP_DB_HOST . ';charset=' . APP_DB_CHARSET, APP_DB_USER, APP_DB_PASSWORD, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
        $pdo->exec('CREATE DATABASE IF NOT EXISTS appointflow CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $pdo->exec('USE appointflow');
        $migrations=glob(__DIR__.'/../database/migrations/*.sql'); sort($migrations,SORT_STRING);
        foreach($migrations as $migration){$sql=file_get_contents($migration); if($sql===false) throw new RuntimeException('Cannot read migration.'); $pdo->exec($sql);}
        $pdo->beginTransaction();
        $stmt=$pdo->prepare('INSERT INTO workspaces(name,email,phone,timezone,booking_slug,status) VALUES(?,?,?,?,?,\'active\')');
        $stmt->execute([$business,$email,$phone!==''?$phone:null,'Asia/Manila',$slug]); $wid=(int)$pdo->lastInsertId();
        $stmt=$pdo->prepare('INSERT INTO users(workspace_id,first_name,last_name,email,password_hash,role,status) VALUES(?,?,?,?,?,\'owner\',\'active\')');
        $stmt->execute([$wid,$first,$last,$email,password_hash($password,PASSWORD_DEFAULT)]); $pdo->commit();
        file_put_contents($lockFile, 'Installed at ' . date('c') . PHP_EOL);
        $done=true;
    } catch(Throwable $e){ if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack(); $error=$e->getMessage(); }
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>AppointFlow Setup</title><link rel="stylesheet" href="<?=h(APP_BASE_PATH.'/assets/css/app.css')?>"></head><body class="auth-page"><main class="auth-shell"><section class="auth-card"><div class="auth-heading"><p class="eyebrow">LOCAL SETUP</p><h1>AppointFlow</h1><p>Create the first workspace and owner account.</p></div>
<?php if($done): ?><div class="success-box">Installation complete. <a href="<?=h(APP_BASE_PATH.'/public/login.php')?>">Open AppointFlow</a>.</div><?php else: ?>
<?php if($error): ?><div class="error-box"><?=h($error)?></div><?php endif; ?>
<form method="post" class="form-stack"><label>Business name<input name="business_name" value="Northstar Digital Consulting" required></label><label>Business email<input type="email" name="owner_email" value="owner@example.com" required></label><label>Phone<input name="phone"></label><label>Booking slug<input name="booking_slug" placeholder="northstar-digital-consulting"></label><label>Owner first name<input name="owner_first" value="Maya" required></label><label>Owner last name<input name="owner_last" value="Owner" required></label><label>Owner password<input type="password" name="owner_password" minlength="8" required></label><button class="button wide" type="submit">Install AppointFlow</button></form><p style="color:#9fb5c5;font-size:12px;line-height:1.5;margin-top:16px">Local development only. The setup route locks itself after installation.</p>
<?php endif; ?></section></main></body></html>
