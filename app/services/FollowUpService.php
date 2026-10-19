<?php
declare(strict_types=1);

require_once __DIR__ . '/PreparationService.php';

final class FollowUpService
{
    /** Labels for the follow_ups.type enum, in the order shown in the UI. */
    public const TYPE_LABELS = [
        'proposal' => 'Send proposal / estimate',
        'call' => 'Call client',
        'email' => 'Send email',
        'document' => 'Request document',
        'meeting' => 'Schedule another meeting',
        'other' => 'Other',
    ];

    /** Engagement statuses in which new follow-ups may be created. */
    private const CREATABLE_STATUSES = ['completed', 'follow_up'];

    public function __construct(private PDO $pdo) {}

    public function overdueCount(int $workspaceId, ?int $assignedUserId = null): int
    {
        $sql =
            'SELECT COUNT(*)
             FROM follow_ups f
             INNER JOIN engagements e ON e.id = f.engagement_id
             WHERE e.workspace_id = ?
               AND f.status = \'pending\'
               AND f.due_at < NOW()';
        $params = [$workspaceId];
        if ($assignedUserId !== null) {
            $sql .= ' AND f.assigned_user_id = ?';
            $params[] = $assignedUserId;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    public function listForEngagement(int $workspaceId, int $engagementId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT f.id, f.type, f.title, f.notes, f.due_at, f.status, f.completed_at,
                    u.first_name AS assigned_first, u.last_name AS assigned_last
             FROM follow_ups f
             INNER JOIN engagements e ON e.id = f.engagement_id AND e.workspace_id = ?
             LEFT JOIN users u ON u.id = f.assigned_user_id
             WHERE f.engagement_id = ?
             ORDER BY CASE f.status WHEN \'pending\' THEN 0 ELSE 1 END, f.due_at ASC, f.id ASC'
        );
        $stmt->execute([$workspaceId, $engagementId]);
        return $stmt->fetchAll();
    }

    /**
     * Create a follow-up. Allowed once the consultation has been completed. When the engagement is
     * "completed" it moves to "follow_up" in the same transaction.
     */
    public function create(
        int $workspaceId,
        int $engagementId,
        int $actorUserId,
        string $type,
        string $title,
        string $notes,
        string $dueDate
    ): int {
        [$type, $title, $notes, $dueAt] = $this->validateFields($type, $title, $notes, $dueDate);

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT status, assigned_user_id FROM engagements WHERE id = ? AND workspace_id = ? FOR UPDATE');
            $stmt->execute([$engagementId, $workspaceId]);
            $engagement = $stmt->fetch();
            if (!$engagement) {
                throw new RuntimeException('Engagement not found.');
            }
            if (!in_array($engagement['status'], self::CREATABLE_STATUSES, true)) {
                throw new InvalidArgumentException('Follow-ups can be added once the consultation has been completed.');
            }
            $assigned = $engagement['assigned_user_id'] !== null ? (int)$engagement['assigned_user_id'] : $actorUserId;

            $this->pdo->prepare(
                'INSERT INTO follow_ups (engagement_id, assigned_user_id, type, title, notes, due_at)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([$engagementId, $assigned, $type, $title, $notes !== '' ? $notes : null, $dueAt]);
            $followUpId = (int)$this->pdo->lastInsertId();

            $this->event($engagementId, $actorUserId, 'follow_up.created', 'Follow-up created: ' . $title, ['follow_up_id' => $followUpId]);

            if ($engagement['status'] === 'completed') {
                $this->pdo->prepare('UPDATE engagements SET status = \'follow_up\' WHERE id = ?')->execute([$engagementId]);
                $this->event($engagementId, $actorUserId, 'engagement.follow_up', 'Engagement moved from completed to follow_up.', null);
            }

            $this->pdo->commit();
            return $followUpId;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Edit a pending follow-up. */
    public function update(
        int $workspaceId,
        int $engagementId,
        int $followUpId,
        int $actorUserId,
        string $type,
        string $title,
        string $notes,
        string $dueDate
    ): void {
        [$type, $title, $notes, $dueAt] = $this->validateFields($type, $title, $notes, $dueDate);
        $this->requirePending($workspaceId, $engagementId, $followUpId);

        $this->pdo->prepare(
            'UPDATE follow_ups SET type = ?, title = ?, notes = ?, due_at = ?
             WHERE id = ? AND engagement_id = ? AND status = \'pending\''
        )->execute([$type, $title, $notes !== '' ? $notes : null, $dueAt, $followUpId, $engagementId]);

        $this->event($engagementId, $actorUserId, 'follow_up.updated', 'Follow-up updated: ' . $title, ['follow_up_id' => $followUpId]);
    }

    public function complete(int $workspaceId, int $engagementId, int $followUpId, int $actorUserId): void
    {
        $followUp = $this->requirePending($workspaceId, $engagementId, $followUpId);
        $this->pdo->prepare(
            'UPDATE follow_ups SET status = \'completed\', completed_at = CURRENT_TIMESTAMP
             WHERE id = ? AND engagement_id = ? AND status = \'pending\''
        )->execute([$followUpId, $engagementId]);
        $this->event($engagementId, $actorUserId, 'follow_up.completed', 'Follow-up completed: ' . $followUp['title'], ['follow_up_id' => $followUpId]);
    }

    public function cancel(int $workspaceId, int $engagementId, int $followUpId, int $actorUserId): void
    {
        $followUp = $this->requirePending($workspaceId, $engagementId, $followUpId);
        $this->pdo->prepare(
            'UPDATE follow_ups SET status = \'cancelled\'
             WHERE id = ? AND engagement_id = ? AND status = \'pending\''
        )->execute([$followUpId, $engagementId]);
        $this->event($engagementId, $actorUserId, 'follow_up.cancelled', 'Follow-up cancelled: ' . $followUp['title'], ['follow_up_id' => $followUpId]);
    }

    private function requirePending(int $workspaceId, int $engagementId, int $followUpId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT f.id, f.title, f.status
             FROM follow_ups f
             INNER JOIN engagements e ON e.id = f.engagement_id AND e.workspace_id = ?
             WHERE f.id = ? AND f.engagement_id = ?
             LIMIT 1'
        );
        $stmt->execute([$workspaceId, $followUpId, $engagementId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException('Follow-up not found.');
        }
        if ($row['status'] !== 'pending') {
            throw new InvalidArgumentException('Only pending follow-ups can be changed.');
        }
        return $row;
    }

    /** @return array{0:string,1:string,2:string,3:string} type, title, notes, due_at */
    private function validateFields(string $type, string $title, string $notes, string $dueDate): array
    {
        if (!array_key_exists($type, self::TYPE_LABELS)) {
            throw new InvalidArgumentException('Please choose a follow-up type.');
        }
        $title = validateRequired($title, 'Follow-up title');
        if (mb_strlen($title) > 200) {
            throw new InvalidArgumentException('Follow-up title must be 200 characters or fewer.');
        }
        $notes = trim($notes);
        if (mb_strlen($notes) > 5000) {
            throw new InvalidArgumentException('Notes must be 5000 characters or fewer.');
        }
        $dueAt = PreparationService::dueDateToDateTime($dueDate);
        if ($dueAt === null) {
            throw new InvalidArgumentException('Due date is required.');
        }
        return [$type, $title, $notes, $dueAt];
    }

    private function event(int $engagementId, ?int $actorUserId, string $eventType, string $description, ?array $metadata): void
    {
        $this->pdo->prepare(
            'INSERT INTO engagement_events (engagement_id, actor_user_id, event_type, description, metadata_json)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([
            $engagementId,
            $actorUserId,
            $eventType,
            $description,
            $metadata === null ? null : json_encode($metadata, JSON_THROW_ON_ERROR),
        ]);
    }
}
