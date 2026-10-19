<?php
declare(strict_types=1);

final class EngagementService
{
    private const TRANSITIONS = [
        'new' => ['qualified', 'closed_lost'],
        'qualified' => ['scheduled', 'closed_lost'],
        'scheduled' => ['qualified', 'completed', 'follow_up'],
        'completed' => ['follow_up', 'converted', 'closed_lost'],
        'follow_up' => ['converted', 'closed_lost'],
        'converted' => [],
        'closed_lost' => [],
    ];

    public function __construct(private PDO $pdo) {}

    public function create(
        int $workspaceId,
        int $clientId,
        ?int $serviceId,
        string $title,
        string $problemSummary,
        ?string $source = null
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO engagements
                (workspace_id, client_id, service_id, title, source, problem_summary, status)
             VALUES (?, ?, ?, ?, ?, ?, \'new\')'
        );
        $stmt->execute([$workspaceId, $clientId, $serviceId, $title, $source, $problemSummary]);
        $id = (int)$this->pdo->lastInsertId();
        $this->event($id, null, 'engagement.created', 'Engagement created.');
        return $id;
    }

    public function findOrCreateClient(
        int $workspaceId,
        string $firstName,
        string $lastName,
        string $email,
        ?string $phone,
        ?string $companyName
    ): int {
        $stmt = $this->pdo->prepare(
            'SELECT id
             FROM clients
             WHERE workspace_id = ? AND LOWER(email) = LOWER(?)
             ORDER BY id ASC
             LIMIT 1'
        );
        $stmt->execute([$workspaceId, $email]);
        $clientId = $stmt->fetchColumn();

        if ($clientId !== false) {
            $update = $this->pdo->prepare(
                'UPDATE clients
                 SET first_name = ?, last_name = ?, phone = ?, company_name = ?
                 WHERE id = ? AND workspace_id = ?'
            );
            $update->execute([$firstName, $lastName, $phone, $companyName, (int)$clientId, $workspaceId]);
            return (int)$clientId;
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO clients (workspace_id, first_name, last_name, email, phone, company_name)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([$workspaceId, $firstName, $lastName, $email, $phone, $companyName]);
        return (int)$this->pdo->lastInsertId();
    }

    public function listForUser(int $workspaceId, bool $isOwner, int $userId, array $filters = []): array
    {
        $sql =
            'SELECT e.id, e.title, e.status, e.priority, e.source, e.updated_at, e.next_action,
                    c.first_name, c.last_name, c.company_name, c.email,
                    s.name AS service_name,
                    u.first_name AS assigned_first, u.last_name AS assigned_last,
                    a.starts_at, a.status AS appointment_status
             FROM engagements e
             INNER JOIN clients c ON c.id = e.client_id
             LEFT JOIN services s ON s.id = e.service_id
             LEFT JOIN users u ON u.id = e.assigned_user_id
             LEFT JOIN appointments a ON a.engagement_id = e.id AND a.status = \'scheduled\'
             WHERE e.workspace_id = :workspace_id';
        $params = [':workspace_id' => $workspaceId];

        if (!$isOwner) {
            $sql .= ' AND e.assigned_user_id = :assigned_user_id';
            $params[':assigned_user_id'] = $userId;
        }

        if (($filters['status'] ?? '') !== '') {
            $sql .= ' AND e.status = :status';
            $params[':status'] = (string)$filters['status'];
        }

        if (($filters['assigned_user_id'] ?? '') !== '' && $isOwner) {
            $sql .= ' AND e.assigned_user_id = :filter_assigned_user_id';
            $params[':filter_assigned_user_id'] = (int)$filters['assigned_user_id'];
        }

        if (($filters['service_id'] ?? '') !== '' && $isOwner) {
            $sql .= ' AND e.service_id = :filter_service_id';
            $params[':filter_service_id'] = (int)$filters['service_id'];
        }

        if (($filters['q'] ?? '') !== '') {
            $sql .= ' AND (CONCAT(c.first_name, \' \', c.last_name) LIKE :q
                          OR COALESCE(c.company_name, \'\') LIKE :q
                          OR CAST(e.id AS CHAR) LIKE :q
                          OR e.title LIKE :q)';
            $params[':q'] = '%' . (string)$filters['q'] . '%';
        }

        $sql .= ' ORDER BY
            CASE e.status
                WHEN \'new\' THEN 1
                WHEN \'qualified\' THEN 2
                WHEN \'scheduled\' THEN 3
                WHEN \'completed\' THEN 4
                WHEN \'follow_up\' THEN 5
                WHEN \'converted\' THEN 6
                WHEN \'closed_lost\' THEN 7
                ELSE 9
            END,
            e.updated_at DESC,
            e.id DESC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function get(int $workspaceId, int $engagementId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT e.*, c.first_name, c.last_name, c.email, c.phone, c.company_name, c.notes AS client_notes,
                    s.name AS service_name, s.description AS service_description,
                    s.duration_minutes,
                    u.first_name AS assigned_first, u.last_name AS assigned_last, u.email AS assigned_email
             FROM engagements e
             INNER JOIN clients c ON c.id = e.client_id
             LEFT JOIN services s ON s.id = e.service_id
             LEFT JOIN users u ON u.id = e.assigned_user_id
             WHERE e.id = ? AND e.workspace_id = ?
             LIMIT 1'
        );
        $stmt->execute([$engagementId, $workspaceId]);
        $engagement = $stmt->fetch();
        if (!$engagement) {
            throw new RuntimeException('Engagement not found.');
        }
        return $engagement;
    }

    public function getEvents(int $workspaceId, int $engagementId): array
    {
        $this->get($workspaceId, $engagementId);
        $stmt = $this->pdo->prepare(
            'SELECT ee.id, ee.event_type, ee.description, ee.metadata_json, ee.created_at,
                    u.first_name AS actor_first, u.last_name AS actor_last
             FROM engagement_events ee
             INNER JOIN engagements e ON e.id = ee.engagement_id AND e.workspace_id = ?
             LEFT JOIN users u ON u.id = ee.actor_user_id
             WHERE ee.engagement_id = ?
             ORDER BY ee.created_at DESC, ee.id DESC'
        );
        $stmt->execute([$workspaceId, $engagementId]);
        return $stmt->fetchAll();
    }

    public function transition(int $engagementId, string $newStatus, ?int $actorUserId, ?string $description = null): void
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT status FROM engagements WHERE id = ? FOR UPDATE');
            $stmt->execute([$engagementId]);
            $current = $stmt->fetchColumn();
            if (!is_string($current)) {
                throw new RuntimeException('Engagement not found.');
            }

            $allowed = self::TRANSITIONS[$current] ?? [];
            if (!in_array($newStatus, $allowed, true)) {
                throw new InvalidArgumentException("Invalid engagement transition: {$current} → {$newStatus}.");
            }

            $stmt = $this->pdo->prepare('UPDATE engagements SET status = ? WHERE id = ?');
            $stmt->execute([$newStatus, $engagementId]);
            $this->event(
                $engagementId,
                $actorUserId,
                'engagement.' . $newStatus,
                $description ?? 'Engagement moved from ' . $current . ' to ' . $newStatus . '.'
            );
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function qualify(int $workspaceId, int $engagementId, int $actorUserId, ?int $assignedUserId, ?string $qualificationNotes): void
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'SELECT status
                 FROM engagements
                 WHERE id = ? AND workspace_id = ?
                 FOR UPDATE'
            );
            $stmt->execute([$engagementId, $workspaceId]);
            $current = $stmt->fetchColumn();
            if ($current !== 'new') {
                throw new InvalidArgumentException('Only new inquiries can be qualified.');
            }

            if ($assignedUserId !== null) {
                $check = $this->pdo->prepare(
                    'SELECT id FROM users
                     WHERE id = ? AND workspace_id = ? AND role = \'consultant\' AND status = \'active\'
                     LIMIT 1'
                );
                $check->execute([$assignedUserId, $workspaceId]);
                if ($check->fetchColumn() === false) {
                    throw new InvalidArgumentException('Selected consultant is not active in this workspace.');
                }
            }

            $update = $this->pdo->prepare(
                'UPDATE engagements
                 SET status = \'qualified\', assigned_user_id = ?, qualification_notes = ?
                 WHERE id = ? AND workspace_id = ?'
            );
            $update->execute([$assignedUserId, $qualificationNotes, $engagementId, $workspaceId]);

            $this->event(
                $engagementId,
                $actorUserId,
                'engagement.qualified',
                'Engagement qualified.' . ($assignedUserId !== null ? ' Consultant assigned.' : ''),
                ['assigned_user_id' => $assignedUserId]
            );
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function assign(int $workspaceId, int $engagementId, int $actorUserId, ?int $assignedUserId): void
    {
        $this->get($workspaceId, $engagementId);
        if ($assignedUserId !== null) {
            $stmt = $this->pdo->prepare(
                'SELECT id FROM users
                 WHERE id = ? AND workspace_id = ? AND role = \'consultant\' AND status = \'active\'
                 LIMIT 1'
            );
            $stmt->execute([$assignedUserId, $workspaceId]);
            if ($stmt->fetchColumn() === false) {
                throw new InvalidArgumentException('Selected consultant is not active in this workspace.');
            }
        }

        $stmt = $this->pdo->prepare(
            'UPDATE engagements SET assigned_user_id = ? WHERE id = ? AND workspace_id = ?'
        );
        $stmt->execute([$assignedUserId, $engagementId, $workspaceId]);
        $this->event(
            $engagementId,
            $actorUserId,
            'engagement.assigned',
            $assignedUserId === null ? 'Consultant assignment cleared.' : 'Consultant assigned.',
            ['assigned_user_id' => $assignedUserId]
        );
    }

    public function updateQualificationNotes(int $workspaceId, int $engagementId, int $actorUserId, ?string $notes): void
    {
        $this->get($workspaceId, $engagementId);
        $stmt = $this->pdo->prepare(
            'UPDATE engagements SET qualification_notes = ? WHERE id = ? AND workspace_id = ?'
        );
        $stmt->execute([$notes, $engagementId, $workspaceId]);
        $this->event($engagementId, $actorUserId, 'engagement.qualification_updated', 'Qualification notes updated.');
    }

    public function updateNextAction(int $workspaceId, int $engagementId, int $actorUserId, ?string $nextAction, ?string $dueAt): void
    {
        $this->get($workspaceId, $engagementId);
        $stmt = $this->pdo->prepare(
            'UPDATE engagements
             SET next_action = ?, next_action_due_at = ?
             WHERE id = ? AND workspace_id = ?'
        );
        $stmt->execute([$nextAction, $dueAt, $engagementId, $workspaceId]);
        $this->event($engagementId, $actorUserId, 'engagement.next_action_updated', 'Next action updated.');
    }

    private function event(int $engagementId, ?int $actorUserId, string $eventType, string $description, ?array $metadata = null): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO engagement_events (engagement_id, actor_user_id, event_type, description, metadata_json)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $engagementId,
            $actorUserId,
            $eventType,
            $description,
            $metadata === null ? null : json_encode($metadata, JSON_THROW_ON_ERROR),
        ]);
    }
}
