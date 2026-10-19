<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/database.php';
require_once __DIR__ . '/../app/security.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/permissions.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/validation.php';
require_once __DIR__ . '/../app/services/EngagementService.php';

$pdo = db();
$currentUser = requireLogin($pdo);
requireOwner($currentUser);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method Not Allowed'); }
$engagementId = validatePositiveInt($_POST['engagement_id'] ?? null, 'Engagement');
try {
    verifyCsrf($_POST['csrf_token'] ?? null);
    $service = new EngagementService($pdo);
    $action = (string)($_POST['action'] ?? '');
    $workspaceId = (int)$currentUser['workspace_id'];
    $userId = (int)$currentUser['id'];

    if ($action === 'qualify') {
        $assignedRaw = trim((string)($_POST['assigned_user_id'] ?? ''));
        $assigned = $assignedRaw === '' ? null : validatePositiveInt($assignedRaw, 'Consultant');
        $notes = trim((string)($_POST['qualification_notes'] ?? ''));
        $service->qualify($workspaceId, $engagementId, $userId, $assigned, $notes !== '' ? $notes : null);
        flash('success', 'Inquiry qualified.');
    } elseif ($action === 'reject') {
        $service->transition($engagementId, 'closed_lost', $userId, 'Inquiry rejected by owner.');
        flash('success', 'Inquiry rejected.');
    } elseif ($action === 'assign') {
        $assignedRaw = trim((string)($_POST['assigned_user_id'] ?? ''));
        $assigned = $assignedRaw === '' ? null : validatePositiveInt($assignedRaw, 'Consultant');
        $service->assign($workspaceId, $engagementId, $userId, $assigned);
        flash('success', 'Assignment updated.');
    } else {
        throw new InvalidArgumentException('Unknown engagement action.');
    }
} catch (InvalidArgumentException | RuntimeException $e) {
    flash('error', $e->getMessage());
} catch (Throwable $e) {
    error_log('[engagement action] ' . $e->getMessage());
    flash('error', 'Unable to complete that engagement action.');
}
redirect(appUrl('public/engagement.php?id=' . $engagementId));
