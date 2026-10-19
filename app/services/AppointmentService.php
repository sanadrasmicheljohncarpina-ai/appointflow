<?php
declare(strict_types=1);

final class AppointmentService
{
    private const ACTIVE_STATUS = 'scheduled';

    public function __construct(private PDO $pdo) {}

    /**
     * Cancel a scheduled appointment. The Engagement is never deleted: if it was "scheduled"
     * it returns to "qualified" so it can be booked again. Records an engagement event and
     * queues a cancellation notification in the same transaction.
     *
     * The appointment is looked up by id AND workspace, so an id from another workspace behaves
     * exactly like a missing appointment ("Appointment not found.") and nothing is touched.
     *
     * $actorUserId is null for client-initiated cancellations.
     */
    public function cancel(int $workspaceId, int $appointmentId, ?int $actorUserId, string $reason = ''): void
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'SELECT a.id, a.engagement_id, a.status, a.starts_at,
                        e.workspace_id, c.email AS client_email
                 FROM appointments a
                 INNER JOIN engagements e ON e.id = a.engagement_id
                 INNER JOIN clients c ON c.id = e.client_id
                 WHERE a.id = ? AND e.workspace_id = ?
                 FOR UPDATE'
            );
            $stmt->execute([$appointmentId, $workspaceId]);
            $appointment = $stmt->fetch();
            if (!$appointment) {
                throw new RuntimeException('Appointment not found.');
            }
            if ($appointment['status'] !== self::ACTIVE_STATUS) {
                throw new InvalidArgumentException('Only scheduled appointments can be cancelled.');
            }

            $stmt = $this->pdo->prepare(
                'UPDATE appointments
                 SET status = \'cancelled\', cancelled_at = CURRENT_TIMESTAMP, cancellation_reason = ?
                 WHERE id = ? AND status = \'scheduled\''
            );
            $stmt->execute([$reason !== '' ? $reason : null, $appointmentId]);

            $engagementId = (int)$appointment['engagement_id'];
            $stmt = $this->pdo->prepare('SELECT status FROM engagements WHERE id = ? FOR UPDATE');
            $stmt->execute([$engagementId]);
            $engagementStatus = (string)$stmt->fetchColumn();
            if ($engagementStatus === 'scheduled') {
                $stmt = $this->pdo->prepare('UPDATE engagements SET status = \'qualified\' WHERE id = ?');
                $stmt->execute([$engagementId]);
            }

            $description = $actorUserId === null ? 'Client cancelled the consultation.' : 'Consultation cancelled.';
            if ($reason !== '') {
                $description .= ' Reason: ' . $reason;
            }
            $stmt = $this->pdo->prepare(
                'INSERT INTO engagement_events (engagement_id, actor_user_id, event_type, description, metadata_json)
                 VALUES (?, ?, \'appointment.cancelled\', ?, ?)'
            );
            $stmt->execute([
                $engagementId,
                $actorUserId,
                $description,
                json_encode([
                    'appointment_id' => $appointmentId,
                    'starts_at' => (string)$appointment['starts_at'],
                    'by' => $actorUserId === null ? 'client' : 'staff',
                ], JSON_THROW_ON_ERROR),
            ]);

            $stmt = $this->pdo->prepare(
                'INSERT INTO notification_log
                    (workspace_id, engagement_id, appointment_id, recipient_email, notification_type, status)
                 VALUES (?, ?, ?, ?, \'cancellation\', \'pending\')'
            );
            $stmt->execute([
                $workspaceId,
                $engagementId,
                $appointmentId,
                (string)$appointment['client_email'],
            ]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Mark a scheduled consultation as completed. It cannot be completed before its scheduled
     * start time. The Engagement moves scheduled -> completed in the same transaction.
     */
    public function complete(int $workspaceId, int $appointmentId, int $actorUserId, DateTimeZone $timezone): void
    {
        $this->recordOutcome('completed', $workspaceId, $appointmentId, $actorUserId, $timezone);
    }

    /**
     * Mark a scheduled consultation as a no-show (the client did not attend). Like completion this is
     * only possible once the start time has passed. The Engagement returns to "qualified" so the
     * consultation can be booked again; nothing is deleted.
     */
    public function markNoShow(int $workspaceId, int $appointmentId, int $actorUserId, DateTimeZone $timezone): void
    {
        $this->recordOutcome('no_show', $workspaceId, $appointmentId, $actorUserId, $timezone);
    }

    private function recordOutcome(string $outcome, int $workspaceId, int $appointmentId, int $actorUserId, DateTimeZone $timezone): void
    {
        $isCompletion = $outcome === 'completed';
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'SELECT a.id, a.engagement_id, a.status, a.starts_at
                 FROM appointments a
                 INNER JOIN engagements e ON e.id = a.engagement_id
                 WHERE a.id = ? AND e.workspace_id = ?
                 FOR UPDATE'
            );
            $stmt->execute([$appointmentId, $workspaceId]);
            $appointment = $stmt->fetch();
            if (!$appointment) {
                throw new RuntimeException('Appointment not found.');
            }
            if ($appointment['status'] !== self::ACTIVE_STATUS) {
                throw new InvalidArgumentException(
                    $isCompletion
                        ? 'Only scheduled consultations can be completed.'
                        : 'Only scheduled consultations can be marked as a no-show.'
                );
            }

            // Appointment times are stored as workspace-local wall-clock time.
            $startsAt = new DateTimeImmutable((string)$appointment['starts_at'], $timezone);
            if ($startsAt > new DateTimeImmutable('now', $timezone)) {
                throw new InvalidArgumentException(
                    $isCompletion
                        ? 'A consultation cannot be completed before its scheduled start time.'
                        : 'A consultation cannot be marked as a no-show before its scheduled start time.'
                );
            }

            if ($isCompletion) {
                $stmt = $this->pdo->prepare(
                    'UPDATE appointments SET status = \'completed\', completed_at = CURRENT_TIMESTAMP
                     WHERE id = ? AND status = \'scheduled\''
                );
            } else {
                $stmt = $this->pdo->prepare(
                    'UPDATE appointments SET status = \'no_show\'
                     WHERE id = ? AND status = \'scheduled\''
                );
            }
            $stmt->execute([$appointmentId]);

            $engagementId = (int)$appointment['engagement_id'];
            $stmt = $this->pdo->prepare('SELECT status FROM engagements WHERE id = ? FOR UPDATE');
            $stmt->execute([$engagementId]);
            $engagementStatus = (string)$stmt->fetchColumn();
            $newEngagementStatus = $engagementStatus;
            if ($engagementStatus === 'scheduled') {
                $newEngagementStatus = $isCompletion ? 'completed' : 'qualified';
                $stmt = $this->pdo->prepare('UPDATE engagements SET status = ? WHERE id = ?');
                $stmt->execute([$newEngagementStatus, $engagementId]);
            }

            $stmt = $this->pdo->prepare(
                'INSERT INTO engagement_events (engagement_id, actor_user_id, event_type, description, metadata_json)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $engagementId,
                $actorUserId,
                $isCompletion ? 'appointment.completed' : 'appointment.no_show',
                $isCompletion
                    ? 'Consultation completed. Engagement is now ' . $newEngagementStatus . '.'
                    : 'Client did not attend (no-show). Engagement returned to ' . $newEngagementStatus . ' so it can be rebooked.',
                json_encode([
                    'appointment_id' => $appointmentId,
                    'starts_at' => (string)$appointment['starts_at'],
                    'engagement_status' => $newEngagementStatus,
                ], JSON_THROW_ON_ERROR),
            ]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Read model for the Engagement page. An engagement can have many appointments over time, and
     * after completion or a no-show none of them is "scheduled", so the page needs more than
     * BookingService::getScheduledAppointmentForEngagement().
     *
     * @return array{current: ?array, latest: ?array, latest_completed: ?array}
     */
    public function overviewForEngagement(int $workspaceId, int $engagementId): array
    {
        return [
            'current' => $this->findForEngagement($workspaceId, $engagementId, 'scheduled'),
            'latest' => $this->findForEngagement($workspaceId, $engagementId, null),
            'latest_completed' => $this->findForEngagement($workspaceId, $engagementId, 'completed'),
        ];
    }

    private function findForEngagement(int $workspaceId, int $engagementId, ?string $status): ?array
    {
        $sql =
            'SELECT a.id, a.engagement_id, a.status, a.starts_at, a.ends_at, a.completed_at,
                    a.cancelled_at, a.cancellation_reason,
                    u.first_name AS consultant_first_name, u.last_name AS consultant_last_name
             FROM appointments a
             INNER JOIN engagements e ON e.id = a.engagement_id AND e.workspace_id = ?
             LEFT JOIN users u ON u.id = a.assigned_user_id
             WHERE a.engagement_id = ?';
        $params = [$workspaceId, $engagementId];
        if ($status !== null) {
            $sql .= ' AND a.status = ?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY a.starts_at DESC, a.id DESC LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
