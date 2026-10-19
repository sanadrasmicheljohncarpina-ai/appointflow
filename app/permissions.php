<?php
declare(strict_types=1);

function requireOwner(array $user): void
{
    if (($user['role'] ?? '') !== 'owner') {
        http_response_code(403);
        exit('Forbidden');
    }
}

function requireConsultant(array $user): void
{
    if (!in_array(($user['role'] ?? ''), ['owner', 'consultant'], true)) {
        http_response_code(403);
        exit('Forbidden');
    }
}

function requireWorkspaceAccess(array $user, int $workspaceId): void
{
    if ((int)$user['workspace_id'] !== $workspaceId) {
        http_response_code(403);
        exit('Forbidden');
    }
}

function requireEngagementAccess(PDO $pdo, array $user, int $engagementId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, workspace_id, assigned_user_id
         FROM engagements
         WHERE id = ?
         LIMIT 1'
    );
    $stmt->execute([$engagementId]);
    $engagement = $stmt->fetch();
    if (!$engagement) {
        http_response_code(404);
        exit('Engagement not found.');
    }

    requireWorkspaceAccess($user, (int)$engagement['workspace_id']);
    if ($user['role'] === 'consultant' && (int)$engagement['assigned_user_id'] !== (int)$user['id']) {
        http_response_code(403);
        exit('Forbidden');
    }
    return $engagement;
}
