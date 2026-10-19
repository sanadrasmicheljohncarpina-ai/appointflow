<?php
declare(strict_types=1);

/**
 * Meeting notes (meeting_notes table). Notes belong to an APPOINTMENT, not directly to the
 * engagement, so an engagement with several consultations keeps one set of notes per meeting.
 */
final class MeetingNoteService
{
    public const MAX_LENGTH = 5000;

    public function __construct(private PDO $pdo) {}

    public function get(int $workspaceId, int $appointmentId): ?array
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

    /**
     * Whether notes may be written for this appointment right now: it has been completed, or it is
     * scheduled and its start time has already passed (the meeting is happening or just ended).
     */
    public static function canRecord(array $appointment, DateTimeZone $timezone): bool
    {
        $status = (string)($appointment['status'] ?? '');
        if ($status === 'completed') {
            return true;
        }
        if ($status !== 'scheduled') {
            return false;
        }
        return new DateTimeImmutable((string)$appointment['starts_at'], $timezone) <= new DateTimeImmutable('now', $timezone);
    }

    /**
     * Create or update the notes for one appointment. Returns true when the notes were created.
     *
     * @param array{summary?:string,problem?:string,requirements?:string,decisions?:string,concerns?:string,next_step?:string} $input
     */
    public function save(int $workspaceId, int $engagementId, int $appointmentId, int $userId, array $input, DateTimeZone $timezone): bool
    {
        $summary = $this->required($input['summary'] ?? '', 'Summary');
        $problem = $this->required($input['problem'] ?? '', 'Problem');
        $requirements = $this->required($input['requirements'] ?? '', 'Requirements');
        $nextStep = $this->required($input['next_step'] ?? '', 'Next step');
        $decisions = $this->optional($input['decisions'] ?? '', 'Decisions');
        $concerns = $this->optional($input['concerns'] ?? '', 'Concerns');

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'SELECT a.id, a.status, a.starts_at
                 FROM appointments a
                 INNER JOIN engagements e ON e.id = a.engagement_id
                 WHERE a.id = ? AND a.engagement_id = ? AND e.workspace_id = ?
                 FOR UPDATE'
            );
            $stmt->execute([$appointmentId, $engagementId, $workspaceId]);
            $appointment = $stmt->fetch();
            if (!$appointment) {
                throw new RuntimeException('Consultation not found.');
            }
            if (!self::canRecord($appointment, $timezone)) {
                throw new InvalidArgumentException(
                    $appointment['status'] === 'scheduled'
                        ? 'Meeting notes can be recorded once the consultation has started.'
                        : 'Meeting notes can only be recorded for a consultation that took place.'
                );
            }

            $stmt = $this->pdo->prepare('SELECT id FROM meeting_notes WHERE appointment_id = ? FOR UPDATE');
            $stmt->execute([$appointmentId]);
            $existingId = $stmt->fetchColumn();
            $isNew = $existingId === false;

            if ($isNew) {
                $this->pdo->prepare(
                    'INSERT INTO meeting_notes (appointment_id, created_by, summary, problem, requirements, decisions, concerns, next_step)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([$appointmentId, $userId, $summary, $problem, $requirements, $decisions, $concerns, $nextStep]);
            } else {
                $this->pdo->prepare(
                    'UPDATE meeting_notes
                     SET summary = ?, problem = ?, requirements = ?, decisions = ?, concerns = ?, next_step = ?
                     WHERE id = ?'
                )->execute([$summary, $problem, $requirements, $decisions, $concerns, $nextStep, (int)$existingId]);
            }

            $this->pdo->prepare(
                'INSERT INTO engagement_events (engagement_id, actor_user_id, event_type, description, metadata_json)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([
                $engagementId,
                $userId,
                $isNew ? 'meeting.notes_saved' : 'meeting.notes_updated',
                $isNew ? 'Meeting notes saved.' : 'Meeting notes updated.',
                json_encode(['appointment_id' => $appointmentId], JSON_THROW_ON_ERROR),
            ]);

            $this->pdo->commit();
            return $isNew;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function required(mixed $value, string $label): string
    {
        $value = validateRequired((string)$value, $label);
        if (mb_strlen($value) > self::MAX_LENGTH) {
            throw new InvalidArgumentException($label . ' must be ' . self::MAX_LENGTH . ' characters or fewer.');
        }
        return $value;
    }

    private function optional(mixed $value, string $label): ?string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > self::MAX_LENGTH) {
            throw new InvalidArgumentException($label . ' must be ' . self::MAX_LENGTH . ' characters or fewer.');
        }
        return $value;
    }
}
