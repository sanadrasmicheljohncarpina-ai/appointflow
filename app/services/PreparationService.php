<?php
declare(strict_types=1);

/**
 * Preparation checklist for an engagement (preparation_tasks table).
 *
 * Progress is derived from the tasks (Not started / In progress / Ready); there is no separate
 * "preparation status" stored anywhere.
 */
final class PreparationService
{
    public const DEFAULT_TASKS = [
        'Review client inquiry',
        'Review intake responses',
        'Prepare consultation questions',
        'Review submitted information',
    ];

    public function __construct(private PDO $pdo) {}

    /**
     * Create the standard checklist the first time an engagement has a scheduled consultation.
     * Guarded by an activity event, so removing tasks never causes them to reappear.
     */
    public function ensureDefaults(int $workspaceId, int $engagementId, ?int $actorUserId): void
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT assigned_user_id FROM engagements WHERE id = ? AND workspace_id = ? FOR UPDATE');
            $stmt->execute([$engagementId, $workspaceId]);
            $row = $stmt->fetch();
            if (!$row) {
                throw new RuntimeException('Engagement not found.');
            }

            $stmt = $this->pdo->prepare('SELECT 1 FROM engagement_events WHERE engagement_id = ? AND event_type = \'preparation.seeded\' LIMIT 1');
            $stmt->execute([$engagementId]);
            $alreadySeeded = $stmt->fetchColumn() !== false;

            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM preparation_tasks WHERE engagement_id = ?');
            $stmt->execute([$engagementId]);
            $hasTasks = (int)$stmt->fetchColumn() > 0;

            if ($alreadySeeded || $hasTasks) {
                $this->pdo->commit();
                return;
            }

            $assigned = $row['assigned_user_id'] !== null ? (int)$row['assigned_user_id'] : null;
            $insert = $this->pdo->prepare(
                'INSERT INTO preparation_tasks (engagement_id, assigned_user_id, title, sort_order) VALUES (?, ?, ?, ?)'
            );
            foreach (self::DEFAULT_TASKS as $index => $title) {
                $insert->execute([$engagementId, $assigned, $title, $index + 1]);
            }
            $this->event($engagementId, $actorUserId, 'preparation.seeded', 'Preparation checklist created.');
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function list(int $workspaceId, int $engagementId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.id, t.title, t.status, t.due_at, t.completed_at, t.assigned_user_id,
                    u.first_name AS assigned_first, u.last_name AS assigned_last
             FROM preparation_tasks t
             INNER JOIN engagements e ON e.id = t.engagement_id AND e.workspace_id = ?
             LEFT JOIN users u ON u.id = t.assigned_user_id
             WHERE t.engagement_id = ?
             ORDER BY t.sort_order ASC, t.id ASC'
        );
        $stmt->execute([$workspaceId, $engagementId]);
        return $stmt->fetchAll();
    }

    /** @return array{total:int, done:int, state:string} state: not_started | in_progress | ready */
    public static function summarize(array $tasks): array
    {
        $total = count($tasks);
        $done = 0;
        foreach ($tasks as $task) {
            if (($task['status'] ?? '') === 'completed') {
                $done++;
            }
        }
        $state = 'not_started';
        if ($total > 0 && $done === $total) {
            $state = 'ready';
        } elseif ($done > 0) {
            $state = 'in_progress';
        }
        return ['total' => $total, 'done' => $done, 'state' => $state];
    }

    public function add(int $workspaceId, int $engagementId, int $actorUserId, string $title, ?string $dueDate): void
    {
        $title = validateRequired($title, 'Task title');
        if (mb_strlen($title) > 200) {
            throw new InvalidArgumentException('Task title must be 200 characters or fewer.');
        }
        $dueAt = self::dueDateToDateTime($dueDate);

        $stmt = $this->pdo->prepare('SELECT assigned_user_id FROM engagements WHERE id = ? AND workspace_id = ? LIMIT 1');
        $stmt->execute([$engagementId, $workspaceId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException('Engagement not found.');
        }
        $assigned = $row['assigned_user_id'] !== null ? (int)$row['assigned_user_id'] : $actorUserId;

        $stmt = $this->pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM preparation_tasks WHERE engagement_id = ?');
        $stmt->execute([$engagementId]);
        $sortOrder = (int)$stmt->fetchColumn();

        $this->pdo->prepare(
            'INSERT INTO preparation_tasks (engagement_id, assigned_user_id, title, due_at, sort_order) VALUES (?, ?, ?, ?, ?)'
        )->execute([$engagementId, $assigned, $title, $dueAt, $sortOrder]);

        $this->event($engagementId, $actorUserId, 'preparation.task_added', 'Preparation task added: ' . $title);
    }

    /** Complete a task, or reopen it when $done is false. */
    public function setCompleted(int $workspaceId, int $engagementId, int $taskId, int $actorUserId, bool $done): void
    {
        $task = $this->findTask($workspaceId, $engagementId, $taskId);
        if ($task === null) {
            throw new RuntimeException('Preparation task not found.');
        }
        $target = $done ? 'completed' : 'pending';
        if ($task['status'] === $target) {
            return;
        }

        if ($done) {
            $this->pdo->prepare('UPDATE preparation_tasks SET status = \'completed\', completed_at = CURRENT_TIMESTAMP WHERE id = ? AND engagement_id = ?')
                ->execute([$taskId, $engagementId]);
        } else {
            $this->pdo->prepare('UPDATE preparation_tasks SET status = \'pending\', completed_at = NULL WHERE id = ? AND engagement_id = ?')
                ->execute([$taskId, $engagementId]);
        }

        if ($done) {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM preparation_tasks WHERE engagement_id = ? AND status = \'pending\'');
            $stmt->execute([$engagementId]);
            if ((int)$stmt->fetchColumn() === 0) {
                $this->event($engagementId, $actorUserId, 'preparation.ready', 'Preparation complete: all checklist tasks are done.');
            }
        }
    }

    public function remove(int $workspaceId, int $engagementId, int $taskId): void
    {
        if ($this->findTask($workspaceId, $engagementId, $taskId) === null) {
            throw new RuntimeException('Preparation task not found.');
        }
        $this->pdo->prepare('DELETE FROM preparation_tasks WHERE id = ? AND engagement_id = ?')->execute([$taskId, $engagementId]);
    }

    private function findTask(int $workspaceId, int $engagementId, int $taskId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.id, t.status
             FROM preparation_tasks t
             INNER JOIN engagements e ON e.id = t.engagement_id AND e.workspace_id = ?
             WHERE t.id = ? AND t.engagement_id = ?
             LIMIT 1'
        );
        $stmt->execute([$workspaceId, $taskId, $engagementId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** "YYYY-MM-DD" (or empty) -> end of that day as a DATETIME string, or null. */
    public static function dueDateToDateTime(?string $dueDate): ?string
    {
        $dueDate = trim((string)$dueDate);
        if ($dueDate === '') {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dueDate);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new InvalidArgumentException('Please enter a valid due date.');
        }
        return $date->format('Y-m-d') . ' 23:59:59';
    }

    private function event(int $engagementId, ?int $actorUserId, string $eventType, string $description): void
    {
        $this->pdo->prepare(
            'INSERT INTO engagement_events (engagement_id, actor_user_id, event_type, description, metadata_json) VALUES (?, ?, ?, ?, NULL)'
        )->execute([$engagementId, $actorUserId, $eventType, $description]);
    }
}
