<?php
declare(strict_types=1);

final class WorkspaceService
{
    public function __construct(private PDO $pdo) {}

    public function get(int $workspaceId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, email, phone, timezone, booking_slug, status
             FROM workspaces
             WHERE id = ?
             LIMIT 1'
        );
        $stmt->execute([$workspaceId]);
        $workspace = $stmt->fetch();

        if (!$workspace) {
            throw new RuntimeException('Workspace not found.');
        }

        return $workspace;
    }

    public function updateProfile(
        int $workspaceId,
        string $name,
        string $email,
        ?string $phone,
        string $timezone
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE workspaces
             SET name = ?, email = ?, phone = ?, timezone = ?
             WHERE id = ?'
        );
        $stmt->execute([$name, $email, $phone, $timezone, $workspaceId]);

        if ($stmt->rowCount() === 0) {
            // UPDATE may legitimately affect zero rows when the submitted
            // values are identical. Verify the workspace still exists.
            $this->get($workspaceId);
        }
    }
}
