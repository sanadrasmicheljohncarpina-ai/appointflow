<?php
declare(strict_types=1);

require_once __DIR__ . '/EngagementService.php';

/**
 * Milestone 8 — Consultation execution.
 *
 * Preparation checklist → meeting notes → mark completed (or no-show) → conclude the Engagement.
 * Follow-ups live in FollowUpService. Every mutation is written to engagement_events so it shows
 * in the Activity History, and every query is scoped to the caller's workspace through the
 * engagements table (the child tables carry no workspace_id of their own).
 *
 * Date/times are stored as naive workspace-local strings, exactly like appointments.
 */
final class ConsultationService
{
    /** Engagement statuses in which the preparation checklist can be changed. */
    private const PREPARATION_STATUSES = ['qualified', 'scheduled'];
    /** Engagement statuses in which meeting notes can be written or edited. */
    private const NOTE_STATUSES = ['scheduled', 'completed', 'follow_up'];

    private const STARTER_TASKS = [
        'Review the original inquiry and intake answers',
        'Research the client and their company',
        'Prepare discovery questions for the meeting',
        'Confirm meeting logistics (link, location, materials)',
    ];

    private EngagementService $engagements;

    public function __construct(private PDO $pdo)
    {
        $this->engagements = new EngagementService($pdo);
    }

    // ------------------------------------------------------------------ preparation

    public function listPreparationTasks(int $workspaceId, int $engagementId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.id, t.title, t.description, t.status, t.due_at, t.completed_at, t.sort_order
             FROM preparation_tasks t
             INNER JOIN engagements e ON e.id = t.engagement_id AND e.workspace_id = ?
             WHERE t.engagement_id = ?
             ORDER BY (t.status = \'completed\') ASC, t.sort_order ASC, t.id ASC'
        );
        $stmt->execute([$workspaceId, $engagementId]);
        return $stmt->fetchAll();
    }

    public function addPreparationTask(
        int $workspaceId,
        int $engagementId,
        int $actorUserId,
        string $title,
        string $description,
        string $dueRaw
    ): int {
        $title = $this->cleanText($title, 'Task title', 200, true);
        $description = $this->cleanText($description, 'Task details', 5000, false);
        $tz = $this->workspaceTz($workspaceId);
        $due = trim($dueRaw) === '' ? null : $this->parseLocal($dueRaw, $tz, 'Due date')->format('Y-m-d H:i:s');

        return $this->inTransaction(function () use ($workspaceId, $engagementId, $actorUserId, $title, $description, $due): int {
            $engagement = $this->lockEngagement($workspaceId, $engagementId);
            $this->assertStatus($engagement, self::PREPARATION_STATUSES, 'Preparation tasks can only be changed before the consultation takes place.');

            $stmt = $this->pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM preparation_tasks WHERE engagement_id = ?');
            $stmt->execute([$engagementId]);
            $sort = (int)$stmt->fetchColumn();

            $stmt = $this->pdo->prepare(
                'INSERT INTO preparation_tasks (engagement_id, assigned_user_id, title, description, due_at, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$engagementId, $engagement['assigned_user_id'] ?: null, $title, $description !== '' ? $description : null, $due, $sort]);
            $taskId = (int)$this->pdo->lastInsertId();

            $this->engagements->recordEvent($engagementId, $actorUserId, 'preparation.task_added', 'Preparation task added: ' . $title, ['task_id' => $taskId]);
            return $taskId;
        });
    }

    /** Adds the standard checklist, but only to an engagement that has no tasks yet. */
    public function addStarterChecklist(int $workspaceId, int $engagementId, int $actorUserId): int
    {
        return $this->inTransaction(function () use ($workspaceId, $engagementId, $actorUserId): int {
            $engagement = $this->lockEngagement($workspaceId, $engagementId);
            $this->assertStatus($engagement, self::PREPARATION_STATUSES, 'Preparation tasks can only be changed before the consultation takes place.');

            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM preparation_tasks WHERE engagement_id = ?');
            $stmt->execute([$engagementId]);
            if ((int)$stmt->fetchColumn() > 0) {
                throw new InvalidArgumentException('This engagement already has preparation tasks.');
            }

            $insert = $this->pdo->prepare(
                'INSERT INTO preparation_tasks (engagement_id, assigned_user_id, title, sort_order) VALUES (?, ?, ?, ?)'
            );
            foreach (self::STARTER_TASKS as $index => $title) {
                $insert->execute([$engagementId, $engagement['assigned_user_id'] ?: null, $title, $index + 1]);
            }

            $this->engagements->recordEvent($engagementId, $actorUserId, 'preparation.starter_added', 'Starter preparation checklist added.');
            return count(self::STARTER_TASKS);
        });
    }

    public function setPreparationTaskDone(int $workspaceId, int $engagementId, int $actorUserId, int $taskId, bool $done): void
    {
        $this->inTransaction(function () use ($workspaceId, $engagementId, $actorUserId, $taskId, $done): void {
            $engagement = $this->lockEngagement($workspaceId, $engagementId);
            $this->assertStatus($engagement, self::PREPARATION_STATUSES, 'Preparation tasks can only be changed before the consultation takes place.');
            $task = $this->lockTask($engagementId, $taskId);

            $newStatus = $done ? 'completed' : 'pending';
            if ($task['status'] === $newStatus) {
                return;
            }
            $stmt = $this->pdo->prepare(
                'UPDATE preparation_tasks SET status = ?, completed_at = ? WHERE id = ? AND engagement_id = ?'
            );
            $stmt->execute([$newStatus, $done ? $this->nowLocal($workspaceId) : null, $taskId, $engagementId]);

            $this->engagements->recordEvent(
                $engagementId,
                $actorUserId,
                $done ? 'preparation.task_completed' : 'preparation.task_reopened',
                ($done ? 'Preparation task completed: ' : 'Preparation task reopened: ') . $task['title'],
                ['task_id' => $taskId]
            );
        });
    }

    public function removePreparationTask(int $workspaceId, int $engagementId, int $actorUserId, int $taskId): void
    {
        $this->inTransaction(function () use ($workspaceId, $engagementId, $actorUserId, $taskId): void {
            $engagement = $this->lockEngagement($workspaceId, $engagementId);
            $this->assertStatus($engagement, self::PREPARATION_STATUSES, 'Preparation tasks can only be changed before the consultation takes place.');
            $task = $this->lockTask($engagementId, $taskId);

            $stmt = $this->pdo->prepare('DELETE FROM preparation_tasks WHERE id = ? AND engagement_id = ?');
            $stmt->execute([$taskId, $engagementId]);
            $this->engagements->recordEvent($engagementId, $actorUserId, 'preparation.task_removed', 'Preparation task removed: ' . $task['title'], ['task_id' => $taskId]);
        });
    }

    // ------------------------------------------------------------------ meeting

    /**
     * The appointment the meeting workspace is about: the scheduled one if there is one,
     * otherwise the most recent completed one. Cancelled and missed appointments are history.
     */
    public function meetingAppointment(int $workspaceId, int $engagementId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.id, a.engagement_id, a.assigned_user_id, a.starts_at, a.ends_at, a.status, a.completed_at,
                    u.first_name AS consultant_first_name, u.last_name AS consultant_last_name
             FROM appointments a
             INNER JOIN engagements e ON e.id = a.engagement_id AND e.workspace_id = ?
             LEFT JOIN users u ON u.id = a.assigned_user_id
             WHERE a.engagement_id = ? AND a.status IN (\'scheduled\', \'completed\')
             ORDER BY (a.status = \'scheduled\') DESC, a.starts_at DESC, a.id DESC
             LIMIT 1'
        );
        $stmt->execute([$workspaceId, $engagementId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function getMeetingNote(int $workspaceId, int $appointmentId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT n.id, n.appointment_id, n.summary, n.problem, n.requirements, n.decisions, n.concerns,
                    n.next_step, n.created_at, n.updated_at,
                    u.first_name AS author_first, u.last_name AS author_last
             FROM meeting_notes n
             INNER JOIN appointments a ON a.id = n.appointment_id
             INNER JOIN engagements e ON e.id = a.engagement_id AND e.workspace_id = ?
             LEFT JOIN users u ON u.id = n.created_by
             WHERE n.appointment_id = ?
             LIMIT 1'
        );
        $stmt->execute([$workspaceId, $appointmentId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @param array<string,mixed> $input summary, problem, requirements, decisions, concerns, next_step */
    public function saveMeetingNote(int $workspaceId, int $engagementId, int $actorUserId, int $appointmentId, array $input): void
    {
        $summary = $this->cleanText((string)($input['summary'] ?? ''), 'Summary', 10000, true);
        $problem = $this->cleanText((string)($input['problem'] ?? ''), 'Problem discussed', 10000, true);
        $requirements = $this->cleanText((string)($input['requirements'] ?? ''), 'Requirements', 10000, true);
        $decisions = $this->cleanText((string)($input['decisions'] ?? ''), 'Decisions', 10000, false);
        $concerns = $this->cleanText((string)($input['concerns'] ?? ''), 'Concerns', 10000, false);
        $nextStep = $this->cleanText((string)($input['next_step'] ?? ''), 'Next step', 5000, true);

        $this->inTransaction(function () use ($workspaceId, $engagementId, $actorUserId, $appointmentId, $summary, $problem, $requirements, $decisions, $concerns, $nextStep): void {
            $engagement = $this->lockEngagement($workspaceId, $engagementId);
            $this->assertStatus($engagement, self::NOTE_STATUSES, 'Meeting notes can no longer be edited for this engagement.');
            $appointment = $this->lockAppointment($engagementId, $appointmentId);
            if (!in_array($appointment['status'], ['scheduled', 'completed'], true)) {
                throw new InvalidArgumentException('Notes can only be written for a scheduled or completed consultation.');
            }

            $stmt = $this->pdo->prepare('SELECT id FROM meeting_notes WHERE appointment_id = ? FOR UPDATE');
            $stmt->execute([$appointmentId]);
            $existing = $stmt->fetchColumn();

            if ($existing !== false) {
                $stmt = $this->pdo->prepare(
                    'UPDATE meeting_notes
                     SET summary = ?, problem = ?, requirements = ?, decisions = ?, concerns = ?, next_step = ?
                     WHERE id = ?'
                );
                $stmt->execute([$summary, $problem, $requirements, $decisions !== '' ? $decisions : null, $concerns !== '' ? $concerns : null, $nextStep, (int)$existing]);
                $this->engagements->recordEvent($engagementId, $actorUserId, 'meeting.notes_updated', 'Meeting notes updated.', ['appointment_id' => $appointmentId]);
                return;
            }

            $stmt = $this->pdo->prepare(
                'INSERT INTO meeting_notes (appointment_id, created_by, summary, problem, requirements, decisions, concerns, next_step)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$appointmentId, $actorUserId, $summary, $problem, $requirements, $decisions !== '' ? $decisions : null, $concerns !== '' ? $concerns : null, $nextStep]);
            $this->engagements->recordEvent($engagementId, $actorUserId, 'meeting.notes_saved', 'Meeting notes saved.', ['appointment_id' => $appointmentId]);
        });
    }

    /**
     * Appointment → completed and Engagement scheduled → completed, in one transaction.
     * Requires the meeting to have started and meeting notes to exist.
     */
    public function completeAppointment(int $workspaceId, int $engagementId, int $actorUserId, int $appointmentId): void
    {
        $tz = $this->workspaceTz($workspaceId);
        $this->inTransaction(function () use ($workspaceId, $engagementId, $actorUserId, $appointmentId, $tz): void {
            $engagement = $this->lockEngagement($workspaceId, $engagementId);
            $this->assertStatus($engagement, ['scheduled'], 'Only a scheduled engagement can be completed.');
            $appointment = $this->lockAppointment($engagementId, $appointmentId);
            if ($appointment['status'] !== 'scheduled') {
                throw new InvalidArgumentException('Only a scheduled consultation can be marked completed.');
            }
            $this->assertStarted($appointment, $tz, 'This consultation has not started yet.');

            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM meeting_notes WHERE appointment_id = ?');
            $stmt->execute([$appointmentId]);
            if ((int)$stmt->fetchColumn() === 0) {
                throw new InvalidArgumentException('Save the meeting notes before marking the consultation completed.');
            }

            $nowLocal = (new DateTimeImmutable('now', $tz))->format('Y-m-d H:i:s');
            $stmt = $this->pdo->prepare(
                'UPDATE appointments SET status = \'completed\', completed_at = ? WHERE id = ? AND status = \'scheduled\''
            );
            $stmt->execute([$nowLocal, $appointmentId]);

            $this->moveEngagement($engagementId, 'scheduled', 'completed');
            $this->engagements->recordEvent($engagementId, $actorUserId, 'appointment.completed', 'Consultation marked completed.', ['appointment_id' => $appointmentId]);
            $this->engagements->recordEvent($engagementId, $actorUserId, 'engagement.completed', 'Engagement moved from scheduled to completed.');
        });
    }

    /**
     * The client did not attend. The appointment is kept as history (status no_show) and the
     * Engagement returns to "qualified" so a new consultation can be booked.
     */
    public function markNoShow(int $workspaceId, int $engagementId, int $actorUserId, int $appointmentId): void
    {
        $tz = $this->workspaceTz($workspaceId);
        $this->inTransaction(function () use ($workspaceId, $engagementId, $actorUserId, $appointmentId, $tz): void {
            $engagement = $this->lockEngagement($workspaceId, $engagementId);
            $this->assertStatus($engagement, ['scheduled'], 'Only a scheduled engagement can have a no-show.');
            $appointment = $this->lockAppointment($engagementId, $appointmentId);
            if ($appointment['status'] !== 'scheduled') {
                throw new InvalidArgumentException('Only a scheduled consultation can be marked as a no-show.');
            }
            $this->assertStarted($appointment, $tz, 'A consultation cannot be marked as a no-show before it starts.');

            $stmt = $this->pdo->prepare('UPDATE appointments SET status = \'no_show\' WHERE id = ? AND status = \'scheduled\'');
            $stmt->execute([$appointmentId]);

            $this->moveEngagement($engagementId, 'scheduled', 'qualified');
            $this->engagements->recordEvent(
                $engagementId,
                $actorUserId,
                'appointment.no_show',
                'Client did not attend the consultation. Engagement returned to qualified so it can be rebooked.',
                ['appointment_id' => $appointmentId]
            );
        });
    }

    // ------------------------------------------------------------------ outcome

    /**
     * Final business outcome. Allowed from completed or follow_up only. Pending follow-ups are
     * cancelled so nothing stays "overdue" on a finished engagement.
     */
    public function conclude(int $workspaceId, int $engagementId, int $actorUserId, string $outcome, string $note): void
    {
        if (!in_array($outcome, ['converted', 'closed_lost'], true)) {
            throw new InvalidArgumentException('Unknown outcome.');
        }
        $note = $this->cleanText($note, 'Outcome note', 5000, false);

        $this->inTransaction(function () use ($workspaceId, $engagementId, $actorUserId, $outcome, $note): void {
            $engagement = $this->lockEngagement($workspaceId, $engagementId);
            $this->assertStatus($engagement, ['completed', 'follow_up'], 'An outcome can only be recorded after the consultation is completed.');

            $stmt = $this->pdo->prepare(
                'UPDATE follow_ups SET status = \'cancelled\' WHERE engagement_id = ? AND status = \'pending\''
            );
            $stmt->execute([$engagementId]);
            $cancelled = $stmt->rowCount();
            if ($cancelled > 0) {
                $this->engagements->recordEvent(
                    $engagementId,
                    $actorUserId,
                    'follow_up.auto_cancelled',
                    $cancelled . ' pending follow-up' . ($cancelled === 1 ? '' : 's') . ' cancelled because the engagement was concluded.',
                    ['count' => $cancelled]
                );
            }

            $this->moveEngagement($engagementId, (string)$engagement['status'], $outcome);
            $label = $outcome === 'converted' ? 'Engagement converted to business.' : 'Engagement closed as lost.';
            $this->engagements->recordEvent(
                $engagementId,
                $actorUserId,
                'engagement.' . $outcome,
                $label . ($note !== '' ? ' ' . $note : '')
            );
        });
    }

    // ------------------------------------------------------------------ internals

    private function moveEngagement(int $engagementId, string $from, string $to): void
    {
        if (!EngagementService::canTransition($from, $to)) {
            throw new InvalidArgumentException("Invalid engagement transition: {$from} → {$to}.");
        }
        $stmt = $this->pdo->prepare('UPDATE engagements SET status = ? WHERE id = ? AND status = ?');
        $stmt->execute([$to, $engagementId, $from]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('The engagement changed while you were working. Please refresh and try again.');
        }
    }

    private function assertStarted(array $appointment, DateTimeZone $tz, string $message): void
    {
        $start = new DateTimeImmutable((string)$appointment['starts_at'], $tz);
        if ($start > new DateTimeImmutable('now', $tz)) {
            throw new InvalidArgumentException($message);
        }
    }

    private function assertStatus(array $engagement, array $allowed, string $message): void
    {
        if (!in_array($engagement['status'], $allowed, true)) {
            throw new InvalidArgumentException($message);
        }
    }

    private function lockEngagement(int $workspaceId, int $engagementId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, status, assigned_user_id FROM engagements WHERE id = ? AND workspace_id = ? FOR UPDATE'
        );
        $stmt->execute([$engagementId, $workspaceId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException('Engagement not found.');
        }
        return $row;
    }

    private function lockAppointment(int $engagementId, int $appointmentId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, status, starts_at, ends_at FROM appointments WHERE id = ? AND engagement_id = ? FOR UPDATE'
        );
        $stmt->execute([$appointmentId, $engagementId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException('Appointment not found.');
        }
        return $row;
    }

    private function lockTask(int $engagementId, int $taskId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, title, status FROM preparation_tasks WHERE id = ? AND engagement_id = ? FOR UPDATE'
        );
        $stmt->execute([$taskId, $engagementId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException('Preparation task not found.');
        }
        return $row;
    }

    private function workspaceTz(int $workspaceId): DateTimeZone
    {
        $stmt = $this->pdo->prepare('SELECT timezone FROM workspaces WHERE id = ? LIMIT 1');
        $stmt->execute([$workspaceId]);
        return workspaceTimezone((string)$stmt->fetchColumn());
    }

    private function nowLocal(int $workspaceId): string
    {
        return (new DateTimeImmutable('now', $this->workspaceTz($workspaceId)))->format('Y-m-d H:i:s');
    }

    private function parseLocal(string $value, DateTimeZone $tz, string $label): DateTimeImmutable
    {
        $value = trim($value);
        foreach (['!Y-m-d\TH:i', '!Y-m-d H:i', '!Y-m-d\TH:i:s', '!Y-m-d H:i:s'] as $format) {
            $parsed = DateTimeImmutable::createFromFormat($format, $value, $tz);
            if ($parsed !== false && $parsed->format(substr($format, 1)) === $value) {
                return $parsed;
            }
        }
        throw new InvalidArgumentException($label . ' is not a valid date and time.');
    }

    private function cleanText(string $value, string $label, int $max, bool $required): string
    {
        $value = trim(str_replace("\r\n", "\n", $value));
        if ($required && $value === '') {
            throw new InvalidArgumentException($label . ' is required.');
        }
        if ((function_exists('mb_strlen') ? mb_strlen($value) : strlen($value)) > $max) {
            throw new InvalidArgumentException($label . ' must be ' . $max . ' characters or fewer.');
        }
        return $value;
    }

    /**
     * Runs $work in a transaction. Services here never nest transactions, so a failure
     * always rolls the whole action back (e.g. a status change and its activity event).
     *
     * @template T
     * @param callable():T $work
     * @return T
     */
    private function inTransaction(callable $work): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $work();
            $this->pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
