<?php
declare(strict_types=1);

require_once __DIR__ . '/security.php';

function loginUser(int $userId, int $workspaceId, string $role): void
{
    ensureSession();
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    $_SESSION['workspace_id'] = $workspaceId;
    $_SESSION['role'] = $role;
}

function logoutUser(): void
{
    ensureSession();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'] ?? '',
            'secure' => (bool)$params['secure'],
            'httponly' => (bool)$params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }
    session_destroy();
}

function currentUser(PDO $pdo): ?array
{
    ensureSession();
    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($userId < 1) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT u.id, u.workspace_id, u.first_name, u.last_name, u.email, u.role, u.status,
                w.name AS workspace_name, w.timezone AS workspace_timezone
         FROM users u
         INNER JOIN workspaces w ON w.id = u.workspace_id
         WHERE u.id = ?
           AND u.status = \'active\'
           AND w.status = \'active\'
         LIMIT 1'
    );
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        unset($_SESSION['user_id'], $_SESSION['workspace_id'], $_SESSION['role']);
        return null;
    }

    $_SESSION['workspace_id'] = (int)$user['workspace_id'];
    $_SESSION['role'] = (string)$user['role'];
    return $user;
}

function requireLogin(PDO $pdo): array
{
    $user = currentUser($pdo);
    if (!$user) {
        $next = $_SERVER['REQUEST_URI'] ?? appUrl('public/dashboard.php');
        header('Location: ' . appUrl('public/login.php?next=' . rawurlencode($next)));
        exit;
    }
    return $user;
}
