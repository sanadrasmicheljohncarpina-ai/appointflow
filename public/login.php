<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/database.php';
require_once __DIR__ . '/../app/security.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/validation.php';

$pdo = db();
ensureSession();
if (!empty($_SESSION['user_id'])) {
    header('Location: ' . appUrl('public/dashboard.php'));
    exit;
}

$error = null;
$email = '';
$next = (string)($_GET['next'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verifyCsrf($_POST['csrf_token'] ?? null);
        $email = validateEmail((string)($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        if ($password === '') {
            throw new InvalidArgumentException('Password is required.');
        }

        $stmt = $pdo->prepare(
            'SELECT id, workspace_id, first_name, last_name, email, password_hash, role
             FROM users
             WHERE email = ? AND status = \'active\'
             LIMIT 1'
        );
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, (string)$user['password_hash'])) {
            throw new InvalidArgumentException('Invalid email or password.');
        }

        loginUser((int)$user['id'], (int)$user['workspace_id'], (string)$user['role']);
        $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([(int)$user['id']]);
        $target = $next !== '' && !str_contains($next, '://') && !str_starts_with($next, '//') ? $next : appUrl('public/dashboard.php');
        header('Location: ' . $target);
        exit;
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('[AppointFlow login] ' . $e->getMessage());
        $error = 'Login is temporarily unavailable.';
    }
}
$csrf = csrfToken();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Team Sign In · AppointFlow</title>
    <link rel="stylesheet" href="<?= h(appUrl('assets/css/app.css')) ?>">
</head>
<body class="auth-page">
<main class="auth-shell">
    <section class="auth-card">
        <a class="auth-brand" href="<?= h(appUrl('public/index.php')) ?>"><span class="brand-mark">AF</span> APPOINTFLOW</a>
        <div class="auth-heading">
            <p class="eyebrow">WORKSPACE ACCESS</p>
            <h1>Sign in</h1>
            <p>Use your AppointFlow owner or consultant account.</p>
        </div>
        <?php if ($error): ?><div class="error-box"><?= h($error) ?></div><?php endif; ?>
        <form method="post" class="form-stack">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <label>Email<input type="email" name="email" value="<?= h($email) ?>" autocomplete="username" required></label>
            <label>Password<input type="password" name="password" autocomplete="current-password" required></label>
            <button class="button wide" type="submit">Sign in</button>
        </form>
    </section>
</main>
</body>
</html>
