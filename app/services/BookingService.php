<?php
declare(strict_types=1);

final class BookingService
{
    private const SLOT_INTERVAL_MINUTES = 15;
    private const LOCK_TIMEOUT_SECONDS = 5;

    public function __construct(private PDO $pdo) {}

    /**
     * Return the booking context for an engagement that is eligible for scheduling.
     * A consultant is mandatory before an appointment can be created.
     */
    public function getBookingContext(int $workspaceId, int $engagementId, ?int $viewerUserId = null, bool $viewerIsOwner = false): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT e.id AS engagement_id,
                    e.workspace_id,
                    e.client_id,
                    e.service_id,
                    e.assigned_user_id,
                    e.status AS engagement_status,
                    e.title,
                    c.first_name AS client_first_name,
                    c.last_name AS client_last_name,
                    c.email AS client_email,
                    c.phone AS client_phone,
                    c.company_name,
                    s.name AS service_name,
                    s.description AS service_description,
                    s.duration_minutes,
                    s.buffer_before_minutes,
                    s.buffer_after_minutes,
                    u.first_name AS consultant_first_name,
                    u.last_name AS consultant_last_name,
                    u.email AS consultant_email,
                    u.status AS consultant_status
             FROM engagements e
             INNER JOIN clients c ON c.id = e.client_id
             INNER JOIN services s ON s.id = e.service_id
             LEFT JOIN users u ON u.id = e.assigned_user_id
             WHERE e.id = ?
               AND e.workspace_id = ?
             LIMIT 1'
        );
        $stmt->execute([$engagementId, $workspaceId]);
        $context = $stmt->fetch();
        if (!$context) {
            throw new RuntimeException('Engagement not found.');
        }

        if (!$viewerIsOwner && $viewerUserId !== null && (int)($context['assigned_user_id'] ?? 0) !== $viewerUserId) {
            throw new RuntimeException('You are not authorized to schedule this engagement.');
        }

        return $context;
    }

    /**
     * Return available 15-minute start slots for the engagement on a local workspace date.
     * Availability is the intersection of business availability and consultant-specific
     * availability when consultant-specific rules exist. Business/consultant blocked
     * times and appointment buffers are respected.
     */
    public function getAvailableSlots(int $workspaceId, int $engagementId, string $date, ?int $viewerUserId = null, bool $viewerIsOwner = false): array
    {
        $context = $this->getBookingContext($workspaceId, $engagementId, $viewerUserId, $viewerIsOwner);
        if ($context['engagement_status'] !== 'qualified') {
            return [];
        }
        if ((int)$context['assigned_user_id'] < 1 || $context['consultant_status'] !== 'active') {
            return [];
        }
        return $this->buildSlots($workspaceId, $context, $date, null);
    }

    private function buildSlots(int $workspaceId, array $context, string $date, ?int $ignoreAppointmentId): array
    {
        $timezone = $this->workspaceTimezone($workspaceId);
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $timezone);
        if (!$day || $day->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('Please choose a valid booking date.');
        }

        $dayStart = $day->setTime(0, 0, 0);
        $dayEnd = $dayStart->modify('+1 day');
        $weekday = (int)$dayStart->format('w');
        $consultantId = (int)$context['assigned_user_id'];

        if ($dayStart < $this->todayStart($timezone)) {
            return [];
        }

        $businessRules = $this->listAvailabilityRules($workspaceId, null, $weekday);
        if (!$businessRules) {
            return [];
        }

        $consultantRules = $this->listAvailabilityRules($workspaceId, $consultantId, $weekday);
        $effectiveWindows = $this->intersectWindows($businessRules, $consultantRules ?: null, $dayStart, $timezone);
        if (!$effectiveWindows) {
            return [];
        }

        $blocked = $this->listBlockedIntervals($workspaceId, $consultantId, $dayStart, $dayEnd);
        $appointments = $this->listScheduledAppointments($workspaceId, $consultantId, $dayStart, $dayEnd, $ignoreAppointmentId);

        $duration = (int)$context['duration_minutes'];
        $bufferBefore = (int)$context['buffer_before_minutes'];
        $bufferAfter = (int)$context['buffer_after_minutes'];
        $now = new DateTimeImmutable('now', $timezone);
        $slots = [];
        $seen = [];

        foreach ($effectiveWindows as [$windowStart, $windowEnd]) {
            $cursor = $this->ceilToInterval($windowStart, self::SLOT_INTERVAL_MINUTES, $timezone);
            while ($cursor < $windowEnd) {
                $meetingEnd = $this->addMinutes($cursor, $duration);
                $blockedStart = $this->addMinutes($cursor, -($bufferBefore));
                $blockedEnd = $this->addMinutes($meetingEnd, $bufferAfter);

                if (!$this->readsBackExactly($cursor) || !$this->readsBackExactly($meetingEnd)
                    || !$this->readsBackExactly($blockedStart) || !$this->readsBackExactly($blockedEnd)) {
                    $cursor = $this->addMinutes($cursor, self::SLOT_INTERVAL_MINUTES);
                    continue;
                }

                if ($blockedEnd > $windowEnd || $meetingEnd <= $cursor) {
                    $cursor = $this->addMinutes($cursor, self::SLOT_INTERVAL_MINUTES);
                    continue;
                }

                if ($blockedStart < $now) {
                    $cursor = $this->addMinutes($cursor, self::SLOT_INTERVAL_MINUTES);
                    continue;
                }

                if (!$this->intervalOverlaps($blockedStart, $blockedEnd, $blocked)
                    && !$this->appointmentIntervalsOverlap($blockedStart, $blockedEnd, $appointments, $timezone)) {
                    $key = $cursor->format('Y-m-d H:i:s');
                    if (!isset($seen[$key])) {
                        $slots[] = [
                            'start' => $cursor->format('Y-m-d H:i:s'),
                            'end' => $meetingEnd->format('Y-m-d H:i:s'),
                            'start_display' => $cursor->format('g:i A'),
                            'end_display' => $meetingEnd->format('g:i A'),
                        ];
                        $seen[$key] = true;
                    }
                }

                $cursor = $this->addMinutes($cursor, self::SLOT_INTERVAL_MINUTES);
            }
        }

        return $slots;
    }

    /**
     * Create the active appointment atomically and transition the Engagement to scheduled.
     * Returns appointment data plus a one-time raw client token for the later client-link milestone.
     */
    public function book(int $workspaceId, int $engagementId, string $startLocal, ?int $viewerUserId = null, bool $viewerIsOwner = false): array
    {
        $timezone = $this->workspaceTimezone($workspaceId);
        $requestedStart = $this->parseWorkspaceDateTime($startLocal, $timezone);

        if ($requestedStart === null) {
            throw new InvalidArgumentException('Please choose a valid appointment time.');
        }
        if ($requestedStart < new DateTimeImmutable('now', $timezone)) {
            throw new InvalidArgumentException('You cannot book a time in the past.');
        }

        $context = $this->getBookingContext($workspaceId, $engagementId, $viewerUserId, $viewerIsOwner);
        if ($context['engagement_status'] !== 'qualified') {
            if ($context['engagement_status'] === 'scheduled') {
                throw new InvalidArgumentException('This engagement already has a scheduled appointment.');
            }
            throw new InvalidArgumentException('Only qualified engagements can be scheduled.');
        }
        if ((int)$context['assigned_user_id'] < 1 || $context['consultant_status'] !== 'active') {
            throw new InvalidArgumentException('Assign an active consultant before scheduling this engagement.');
        }
        $consultantId = (int)$context['assigned_user_id'];
        $duration = (int)$context['duration_minutes'];
        $bufferBefore = (int)$context['buffer_before_minutes'];
        $bufferAfter = (int)$context['buffer_after_minutes'];
        $requestedEnd = $this->addMinutes($requestedStart, $duration);
        $blockedStart = $this->addMinutes($requestedStart, -($bufferBefore));
        $blockedEnd = $this->addMinutes($requestedEnd, $bufferAfter);

        $lockName = 'appointflow:booking:' . $workspaceId . ':' . $consultantId . ':' . $requestedStart->format('Y-m-d');
        $lockAcquired = false;

        $this->pdo->beginTransaction();
        try {
            $lockStmt = $this->pdo->prepare('SELECT GET_LOCK(?, ?)');
            $lockStmt->execute([$lockName, self::LOCK_TIMEOUT_SECONDS]);
            $lockResult = $lockStmt->fetchColumn();
            $lockAcquired = ((string)$lockResult === '1');
            if (!$lockAcquired) {
                throw new RuntimeException('The booking calendar is busy. Please try again.');
            }

            $lockedEngagement = $this->lockEngagement($workspaceId, $engagementId);
            if ($lockedEngagement['status'] !== 'qualified') {
                if ($lockedEngagement['status'] === 'scheduled') {
                    throw new InvalidArgumentException('This engagement already has a scheduled appointment.');
                }
                throw new InvalidArgumentException('Only qualified engagements can be scheduled.');
            }
            if ((int)$lockedEngagement['assigned_user_id'] !== $consultantId) {
                throw new RuntimeException('The consultant assignment changed. Refresh and try again.');
            }

            $freshService = $this->getActiveService($workspaceId, (int)$lockedEngagement['service_id']);
            $duration = (int)$freshService['duration_minutes'];
            $bufferBefore = (int)$freshService['buffer_before_minutes'];
            $bufferAfter = (int)$freshService['buffer_after_minutes'];
            $requestedEnd = $this->addMinutes($requestedStart, $duration);
            $blockedStart = $this->addMinutes($requestedStart, -($bufferBefore));
            $blockedEnd = $this->addMinutes($requestedEnd, $bufferAfter);

            $this->assertNotInClockChange($requestedStart, $requestedEnd, $blockedStart, $blockedEnd);
            $this->assertConsultantActive($workspaceId, $consultantId);
            $this->assertDateWithinAvailability(
                $workspaceId,
                $consultantId,
                $requestedStart,
                $requestedEnd,
                $timezone,
                $bufferBefore,
                $bufferAfter
            );
            $blocked = $this->listBlockedIntervals($workspaceId, $consultantId, $requestedStart->setTime(0, 0, 0), $requestedStart->setTime(0, 0, 0)->modify('+1 day'));
            if ($this->intervalOverlaps($blockedStart, $blockedEnd, $blocked)) {
                throw new InvalidArgumentException('That time is blocked or unavailable.');
            }

            if ($this->hasConflict($workspaceId, $consultantId, $requestedStart->format('Y-m-d H:i:s'), $requestedEnd->format('Y-m-d H:i:s'), null)) {
                throw new InvalidArgumentException('That appointment time is no longer available.');
            }

            $activeAppointment = $this->findActiveAppointmentForEngagement($engagementId);
            if ($activeAppointment !== null) {
                throw new InvalidArgumentException('This engagement already has a scheduled appointment.');
            }

            $rawToken = generateSecureToken(32);
            $tokenHash = hashSecureToken($rawToken);
            $expiresAt = null;

            $stmt = $this->pdo->prepare(
                'INSERT INTO appointments
                    (engagement_id, assigned_user_id, starts_at, ends_at, status,
                     client_token_hash, client_token_expires_at)
                 VALUES (?, ?, ?, ?, \'scheduled\', ?, ?)'
            );
            $stmt->execute([
                $engagementId,
                $consultantId,
                $requestedStart->format('Y-m-d H:i:s'),
                $requestedEnd->format('Y-m-d H:i:s'),
                $tokenHash,
                $expiresAt,
            ]);
            $appointmentId = (int)$this->pdo->lastInsertId();

            $stmt = $this->pdo->prepare(
                'UPDATE engagements
                 SET status = \'scheduled\'
                 WHERE id = ? AND workspace_id = ? AND status = \'qualified\''
            );
            $stmt->execute([$engagementId, $workspaceId]);
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('The engagement could not be moved to scheduled.');
            }

            $this->recordEvent(
                $engagementId,
                $viewerUserId,
                'appointment.booked',
                'Consultation booked for ' . $requestedStart->format('M j, Y g:i A') . '.',
                [
                    'appointment_id' => $appointmentId,
                    'starts_at' => $requestedStart->format('Y-m-d H:i:s'),
                    'ends_at' => $requestedEnd->format('Y-m-d H:i:s'),
                    'assigned_user_id' => $consultantId,
                ]
            );

            $notification = $this->pdo->prepare(
                'INSERT INTO notification_log
                    (workspace_id, engagement_id, appointment_id, recipient_email, notification_type, status)
                 VALUES (?, ?, ?, ?, \'confirmation\', \'pending\')'
            );
            $notification->execute([
                $workspaceId,
                $engagementId,
                $appointmentId,
                (string)$context['client_email'],
            ]);

            $this->pdo->commit();

            return [
                'appointment_id' => $appointmentId,
                'engagement_id' => $engagementId,
                'starts_at' => $requestedStart->format('Y-m-d H:i:s'),
                'ends_at' => $requestedEnd->format('Y-m-d H:i:s'),
                'starts_at_display' => $requestedStart->format('M j, Y g:i A'),
                'ends_at_display' => $requestedEnd->format('g:i A'),
                'client_token' => $rawToken,
            ];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        } finally {
            if ($lockAcquired) {
                try {
                    $unlock = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
                    $unlock->execute([$lockName]);
                } catch (Throwable) {
                    // Do not replace the primary booking result with an unlock warning.
                }
            }
        }
    }

    /**
     * Look up an appointment by the raw client token. Returns null for unknown or expired tokens
     * so callers can show one generic message and reveal nothing about the appointment.
     */
    public function findAppointmentByToken(string $token): ?array
    {
        $token = trim($token);
        if ($token === '' || strlen($token) > 128) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT a.id AS appointment_id, a.engagement_id, a.assigned_user_id, a.starts_at, a.ends_at,
                    a.status AS appointment_status, a.client_token_expires_at,
                    e.workspace_id, e.client_id, e.status AS engagement_status, e.service_id,
                    c.first_name AS client_first_name, c.last_name AS client_last_name, c.email AS client_email,
                    s.name AS service_name, s.duration_minutes, s.buffer_before_minutes, s.buffer_after_minutes,
                    u.first_name AS consultant_first_name, u.last_name AS consultant_last_name,
                    w.name AS workspace_name, w.timezone AS workspace_timezone
             FROM appointments a
             INNER JOIN engagements e ON e.id = a.engagement_id
             INNER JOIN clients c ON c.id = e.client_id
             INNER JOIN services s ON s.id = e.service_id
             INNER JOIN workspaces w ON w.id = e.workspace_id AND w.status = \'active\'
             LEFT JOIN users u ON u.id = a.assigned_user_id
             WHERE a.client_token_hash = ?
             LIMIT 1'
        );
        $stmt->execute([hashSecureToken($token)]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        if ($row['client_token_expires_at'] !== null) {
            $tz = $this->workspaceTimezone((int)$row['workspace_id']);
            $expires = $this->parseWorkspaceDateTime((string)$row['client_token_expires_at'], $tz);
            if ($expires === null || $expires < new DateTimeImmutable('now', $tz)) {
                return null;
            }
        }
        return $row;
    }

    /**
     * The client's own appointments (service, date/time, status only), newest first.
     * Deliberately exposes nothing else: no consultant, notes, engagement or contact data.
     */
    public function listClientAppointments(int $workspaceId, int $clientId, int $currentAppointmentId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.id, a.starts_at, a.status, s.name AS service_name
             FROM appointments a
             INNER JOIN engagements e ON e.id = a.engagement_id
             INNER JOIN services s ON s.id = e.service_id
             WHERE e.workspace_id = ? AND e.client_id = ?
             ORDER BY a.starts_at DESC, a.id DESC
             LIMIT 50'
        );
        $stmt->execute([$workspaceId, $clientId]);
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[] = [
                'starts_at' => (string)$row['starts_at'],
                'status' => (string)$row['status'],
                'service_name' => (string)$row['service_name'],
                'is_current' => (int)$row['id'] === $currentAppointmentId,
            ];
        }
        return $rows;
    }

    /**
     * Issue a fresh client link token for the active appointment of an engagement.
     * Replaces the stored hash, so any previously issued link stops working.
     * The raw token is returned once and never stored.
     */
    public function issueClientToken(int $workspaceId, int $engagementId, ?int $actorUserId, bool $actorIsOwner): string
    {
        $context = $this->getBookingContext($workspaceId, $engagementId, $actorUserId, $actorIsOwner);
        $this->pdo->beginTransaction();
        try {
            $appointment = $this->findActiveAppointmentForEngagement($engagementId);
            if ($appointment === null) {
                throw new InvalidArgumentException('There is no scheduled appointment to create a client link for.');
            }
            $rawToken = generateSecureToken(32);
            $stmt = $this->pdo->prepare(
                'UPDATE appointments SET client_token_hash = ?, client_token_expires_at = NULL WHERE id = ?'
            );
            $stmt->execute([hashSecureToken($rawToken), (int)$appointment['id']]);
            $this->recordEvent(
                $engagementId,
                $actorUserId,
                'appointment.client_link_issued',
                'Client management link generated.',
                ['appointment_id' => (int)$appointment['id'], 'client_email' => (string)$context['client_email']]
            );
            $this->pdo->commit();
            return $rawToken;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Available slots for rescheduling a scheduled appointment (its own time is not treated as a conflict). */
    public function getRescheduleSlots(array $appt, string $date): array
    {
        if ($appt['appointment_status'] !== 'scheduled' || $appt['engagement_status'] !== 'scheduled') {
            return [];
        }
        $workspaceId = (int)$appt['workspace_id'];
        $context = $this->getBookingContext($workspaceId, (int)$appt['engagement_id'], null, true);
        if ((int)$context['assigned_user_id'] < 1 || $context['consultant_status'] !== 'active') {
            return [];
        }
        return $this->buildSlots($workspaceId, $context, $date, (int)$appt['appointment_id']);
    }

    /**
     * Move the appointment to a new time. The same appointment row and the same Engagement are kept;
     * the Engagement stays "scheduled". Past times, conflicts, blocked times and availability are
     * all re-validated inside the lock.
     */
    public function rescheduleByToken(string $token, string $startLocal): array
    {
        $appt = $this->findAppointmentByToken($token);
        if ($appt === null) {
            throw new RuntimeException('This link is invalid or has expired.');
        }
        if ($appt['appointment_status'] !== 'scheduled') {
            throw new InvalidArgumentException('This appointment can no longer be rescheduled.');
        }
        $workspaceId = (int)$appt['workspace_id'];
        $engagementId = (int)$appt['engagement_id'];
        $appointmentId = (int)$appt['appointment_id'];
        $consultantId = (int)$appt['assigned_user_id'];

        $timezone = $this->workspaceTimezone($workspaceId);
        $now = new DateTimeImmutable('now', $timezone);
        $newStart = $this->parseWorkspaceDateTime($startLocal, $timezone);
        if ($newStart === null) {
            throw new InvalidArgumentException('Please choose a valid appointment time.');
        }
        if ($newStart < $now) {
            throw new InvalidArgumentException('You cannot reschedule to a time in the past.');
        }
        $oldStart = $this->parseWorkspaceDateTime((string)$appt['starts_at'], $timezone);
        if ($oldStart !== null && $oldStart < $now) {
            throw new InvalidArgumentException('This appointment has already started and can no longer be rescheduled.');
        }

        $lockName = 'appointflow:booking:' . $workspaceId . ':' . $consultantId . ':' . $newStart->format('Y-m-d');
        $lockAcquired = false;

        $this->pdo->beginTransaction();
        try {
            $lockStmt = $this->pdo->prepare('SELECT GET_LOCK(?, ?)');
            $lockStmt->execute([$lockName, self::LOCK_TIMEOUT_SECONDS]);
            $lockAcquired = ((string)$lockStmt->fetchColumn() === '1');
            if (!$lockAcquired) {
                throw new RuntimeException('The booking calendar is busy. Please try again.');
            }

            $stmt = $this->pdo->prepare(
                'SELECT id, engagement_id, assigned_user_id, starts_at, status
                 FROM appointments WHERE id = ? FOR UPDATE'
            );
            $stmt->execute([$appointmentId]);
            $locked = $stmt->fetch();
            if (!$locked || $locked['status'] !== 'scheduled') {
                throw new InvalidArgumentException('This appointment can no longer be rescheduled.');
            }
            $lockedEngagement = $this->lockEngagement($workspaceId, $engagementId);
            if ($lockedEngagement['status'] !== 'scheduled') {
                throw new InvalidArgumentException('This appointment can no longer be rescheduled.');
            }
            $consultantId = (int)$locked['assigned_user_id'];

            $service = $this->getActiveService($workspaceId, (int)$lockedEngagement['service_id']);
            $duration = (int)$service['duration_minutes'];
            $bufferBefore = (int)$service['buffer_before_minutes'];
            $bufferAfter = (int)$service['buffer_after_minutes'];
            $newEnd = $this->addMinutes($newStart, $duration);
            $blockedStart = $this->addMinutes($newStart, -($bufferBefore));
            $blockedEnd = $this->addMinutes($newEnd, $bufferAfter);
            $this->assertNotInClockChange($newStart, $newEnd, $blockedStart, $blockedEnd);

            $this->assertConsultantActive($workspaceId, $consultantId);
            $this->assertDateWithinAvailability($workspaceId, $consultantId, $newStart, $newEnd, $timezone, $bufferBefore, $bufferAfter);
            $dayStart = $newStart->setTime(0, 0, 0);
            $blocked = $this->listBlockedIntervals($workspaceId, $consultantId, $dayStart, $dayStart->modify('+1 day'));
            if ($this->intervalOverlaps($blockedStart, $blockedEnd, $blocked)) {
                throw new InvalidArgumentException('That time is blocked or unavailable.');
            }
            if ($this->hasConflict($workspaceId, $consultantId, $newStart->format('Y-m-d H:i:s'), $newEnd->format('Y-m-d H:i:s'), $appointmentId)) {
                throw new InvalidArgumentException('That appointment time is no longer available.');
            }

            $stmt = $this->pdo->prepare(
                'UPDATE appointments
                 SET starts_at = ?, ends_at = ?, reminder_sent_at = NULL
                 WHERE id = ? AND status = \'scheduled\''
            );
            $stmt->execute([$newStart->format('Y-m-d H:i:s'), $newEnd->format('Y-m-d H:i:s'), $appointmentId]);

            $this->recordEvent(
                $engagementId,
                null,
                'appointment.rescheduled',
                'Client rescheduled the consultation from ' . ($oldStart ? $oldStart->format('M j, Y g:i A') : (string)$locked['starts_at'])
                    . ' to ' . $newStart->format('M j, Y g:i A') . '.',
                [
                    'appointment_id' => $appointmentId,
                    'old_starts_at' => (string)$locked['starts_at'],
                    'new_starts_at' => $newStart->format('Y-m-d H:i:s'),
                    'new_ends_at' => $newEnd->format('Y-m-d H:i:s'),
                    'by' => 'client',
                ]
            );

            $notification = $this->pdo->prepare(
                'INSERT INTO notification_log
                    (workspace_id, engagement_id, appointment_id, recipient_email, notification_type, status)
                 VALUES (?, ?, ?, ?, \'reschedule\', \'pending\')'
            );
            $notification->execute([$workspaceId, $engagementId, $appointmentId, (string)$appt['client_email']]);

            $this->pdo->commit();

            return [
                'appointment_id' => $appointmentId,
                'engagement_id' => $engagementId,
                'starts_at_display' => $newStart->format('l, F j, Y \a\t g:i A'),
            ];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        } finally {
            if ($lockAcquired) {
                try {
                    $unlock = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
                    $unlock->execute([$lockName]);
                } catch (Throwable) {
                }
            }
        }
    }

    /**
     * Self-service "find my appointments": for a client email in this workspace, issue a fresh private
     * link for their next upcoming appointment (or their most recent one). Returns null when the email
     * is unknown, there is nothing to link, or the hourly limit is reached, so callers can always show
     * the same neutral message. The previous link for that appointment stops working.
     */
    public function reissueClientLinkForEmail(int $workspaceId, string $email): ?array
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM notification_log
             WHERE workspace_id = ? AND LOWER(recipient_email) = ?
               AND notification_type = \'confirmation\'
               AND created_at > (NOW() - INTERVAL 1 HOUR)'
        );
        $stmt->execute([$workspaceId, $email]);
        if ((int)$stmt->fetchColumn() >= 5) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT id FROM clients WHERE workspace_id = ? AND LOWER(email) = ? ORDER BY id ASC LIMIT 1');
        $stmt->execute([$workspaceId, $email]);
        $clientId = $stmt->fetchColumn();
        if ($clientId === false) {
            return null;
        }

        $timezone = $this->workspaceTimezone($workspaceId);
        $nowLocal = (new DateTimeImmutable('now', $timezone))->format('Y-m-d H:i:s');

        $base =
            'SELECT a.id, a.engagement_id
             FROM appointments a
             INNER JOIN engagements e ON e.id = a.engagement_id
             WHERE e.workspace_id = ? AND e.client_id = ? ';
        $stmt = $this->pdo->prepare($base . 'AND a.status = \'scheduled\' AND a.starts_at > ? ORDER BY a.starts_at ASC, a.id ASC LIMIT 1');
        $stmt->execute([$workspaceId, (int)$clientId, $nowLocal]);
        $appointment = $stmt->fetch();
        if (!$appointment) {
            $stmt = $this->pdo->prepare($base . 'ORDER BY a.starts_at DESC, a.id DESC LIMIT 1');
            $stmt->execute([$workspaceId, (int)$clientId]);
            $appointment = $stmt->fetch();
        }
        if (!$appointment) {
            return null;
        }

        $rawToken = generateSecureToken(32);
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('UPDATE appointments SET client_token_hash = ?, client_token_expires_at = NULL WHERE id = ?');
            $stmt->execute([hashSecureToken($rawToken), (int)$appointment['id']]);
            $this->recordEvent(
                (int)$appointment['engagement_id'],
                null,
                'appointment.client_link_reissued',
                'Client requested a new private appointment link by email.',
                ['appointment_id' => (int)$appointment['id']]
            );
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return [
            'appointment_id' => (int)$appointment['id'],
            'engagement_id' => (int)$appointment['engagement_id'],
            'token' => $rawToken,
        ];
    }

    public function hasConflict(int $workspaceId, int $assignedUserId, string $startsAt, string $endsAt, ?int $ignoreAppointmentId = null): bool
    {
        $timezone = $this->workspaceTimezone($workspaceId);
        $requestedStart = $this->parseWorkspaceDateTime($startsAt, $timezone);
        $requestedEnd = $this->parseWorkspaceDateTime($endsAt, $timezone);
        if ($requestedStart === null || $requestedEnd === null || $requestedEnd <= $requestedStart) {
            throw new InvalidArgumentException('Invalid appointment interval.');
        }

        $sql =
            'SELECT a.id, a.starts_at, a.ends_at,
                    s.buffer_before_minutes, s.buffer_after_minutes
             FROM appointments a
             INNER JOIN engagements e ON e.id = a.engagement_id
             INNER JOIN services s ON s.id = e.service_id
             WHERE e.workspace_id = ?
               AND a.assigned_user_id = ?
               AND a.status = \'scheduled\'
               AND a.starts_at < ?
               AND a.ends_at > ?';
        $params = [
            $workspaceId,
            $assignedUserId,
            $requestedEnd->modify('+24 hours')->format('Y-m-d H:i:s'),
            $requestedStart->modify('-24 hours')->format('Y-m-d H:i:s'),
        ];
        if ($ignoreAppointmentId !== null) {
            $sql .= ' AND a.id <> ?';
            $params[] = $ignoreAppointmentId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $blockedStart = $requestedStart;
        $blockedEnd = $requestedEnd;
        foreach ($rows as $row) {
            $existingStart = $this->parseWorkspaceDateTime((string)$row['starts_at'], $timezone);
            $existingEnd = $this->parseWorkspaceDateTime((string)$row['ends_at'], $timezone);
            if ($existingStart === null || $existingEnd === null) {
                continue;
            }
            $existingStart = $this->addMinutes($existingStart, -((int)$row['buffer_before_minutes']));
            $existingEnd = $this->addMinutes($existingEnd, (int)$row['buffer_after_minutes']);
            if ($existingStart < $blockedEnd && $existingEnd > $blockedStart) {
                return true;
            }
        }

        return false;
    }

    public function getScheduledAppointmentForEngagement(int $workspaceId, int $engagementId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.id, a.engagement_id, a.assigned_user_id, a.starts_at, a.ends_at,
                    a.status, a.confirmation_sent_at, a.reminder_sent_at,
                    a.client_token_expires_at,
                    u.first_name AS consultant_first_name, u.last_name AS consultant_last_name
             FROM appointments a
             INNER JOIN engagements e ON e.id = a.engagement_id AND e.workspace_id = ?
             LEFT JOIN users u ON u.id = a.assigned_user_id
             WHERE a.engagement_id = ?
               AND a.status = \'scheduled\'
             ORDER BY a.id DESC
             LIMIT 1'
        );
        $stmt->execute([$workspaceId, $engagementId]);
        $appointment = $stmt->fetch();
        return $appointment ?: null;
    }

    private function lockEngagement(int $workspaceId, int $engagementId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, status, assigned_user_id, service_id
             FROM engagements
             WHERE id = ? AND workspace_id = ?
             FOR UPDATE'
        );
        $stmt->execute([$engagementId, $workspaceId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException('Engagement not found.');
        }
        return $row;
    }

    private function findActiveAppointmentForEngagement(int $engagementId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, starts_at, ends_at
             FROM appointments
             WHERE engagement_id = ? AND status = \'scheduled\'
             ORDER BY id DESC
             LIMIT 1
             FOR UPDATE'
        );
        $stmt->execute([$engagementId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function getActiveService(int $workspaceId, int $serviceId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, duration_minutes, buffer_before_minutes, buffer_after_minutes
             FROM services
             WHERE id = ? AND workspace_id = ? AND active = 1
             LIMIT 1'
        );
        $stmt->execute([$serviceId, $workspaceId]);
        $service = $stmt->fetch();
        if (!$service) {
            throw new InvalidArgumentException('The selected service is no longer active.');
        }
        return $service;
    }

    private function assertConsultantActive(int $workspaceId, int $consultantId): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT id
             FROM users
             WHERE id = ? AND workspace_id = ? AND role = \'consultant\' AND status = \'active\'
             LIMIT 1'
        );
        $stmt->execute([$consultantId, $workspaceId]);
        if ($stmt->fetchColumn() === false) {
            throw new InvalidArgumentException('The assigned consultant is no longer active.');
        }
    }

    private function assertDateWithinAvailability(
        int $workspaceId,
        int $consultantId,
        DateTimeImmutable $startsAt,
        DateTimeImmutable $endsAt,
        DateTimeZone $timezone,
        int $bufferBefore,
        int $bufferAfter
    ): void {
        $weekday = (int)$startsAt->format('w');
        $businessRules = $this->listAvailabilityRules($workspaceId, null, $weekday);
        $consultantRules = $this->listAvailabilityRules($workspaceId, $consultantId, $weekday);
        $windows = $this->intersectWindows($businessRules, $consultantRules ?: null, $startsAt->setTime(0, 0, 0), $timezone);

        $blockedStart = $this->addMinutes($startsAt, -($bufferBefore));
        $blockedEnd = $this->addMinutes($endsAt, $bufferAfter);

        foreach ($windows as [$windowStart, $windowEnd]) {
            if ($blockedStart >= $windowStart && $blockedEnd <= $windowEnd) {
                return;
            }
        }

        throw new InvalidArgumentException('That appointment falls outside the consultant and business availability.');
    }

    private function listAvailabilityRules(int $workspaceId, ?int $userId, int $weekday): array
    {
        $sql =
            'SELECT start_time, end_time
             FROM availability_rules
             WHERE workspace_id = ?
               AND weekday = ?
               AND active = 1';
        $params = [$workspaceId, $weekday];
        if ($userId === null) {
            $sql .= ' AND user_id IS NULL';
        } else {
            $sql .= ' AND user_id = ?';
            $params[] = $userId;
        }
        $sql .= ' ORDER BY start_time ASC, id ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    private function intersectWindows(array $businessRules, ?array $consultantRules, DateTimeImmutable $dayStart, DateTimeZone $timezone): array
    {
        $businessWindows = $this->materializeWindows($businessRules, $dayStart, $timezone);
        if ($consultantRules === null || $consultantRules === []) {
            return $businessWindows;
        }

        $consultantWindows = $this->materializeWindows($consultantRules, $dayStart, $timezone);
        $result = [];
        foreach ($businessWindows as [$businessStart, $businessEnd]) {
            foreach ($consultantWindows as [$consultantStart, $consultantEnd]) {
                $start = $businessStart > $consultantStart ? $businessStart : $consultantStart;
                $end = $businessEnd < $consultantEnd ? $businessEnd : $consultantEnd;
                if ($start < $end) {
                    $result[] = [$start, $end];
                }
            }
        }
        return $result;
    }

    private function materializeWindows(array $rules, DateTimeImmutable $dayStart, DateTimeZone $timezone): array
    {
        $windows = [];
        foreach ($rules as $rule) {
            $start = DateTimeImmutable::createFromFormat(
                'Y-m-d H:i:s',
                $dayStart->format('Y-m-d') . ' ' . (string)$rule['start_time'],
                $timezone
            );
            $end = DateTimeImmutable::createFromFormat(
                'Y-m-d H:i:s',
                $dayStart->format('Y-m-d') . ' ' . (string)$rule['end_time'],
                $timezone
            );
            if ($start && $end && $start < $end) {
                $windows[] = [$start, $end];
            }
        }
        return $windows;
    }

    private function listBlockedIntervals(int $workspaceId, int $consultantId, DateTimeImmutable $dayStart, DateTimeImmutable $dayEnd): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT starts_at, ends_at
             FROM blocked_times
             WHERE workspace_id = ?
               AND starts_at < ?
               AND ends_at > ?
               AND (user_id IS NULL OR user_id = ?)
             ORDER BY starts_at ASC, id ASC'
        );
        $stmt->execute([
            $workspaceId,
            $dayEnd->format('Y-m-d H:i:s'),
            $dayStart->format('Y-m-d H:i:s'),
            $consultantId,
        ]);
        return $stmt->fetchAll();
    }

    private function listScheduledAppointments(
        int $workspaceId,
        int $consultantId,
        DateTimeImmutable $dayStart,
        DateTimeImmutable $dayEnd,
        ?int $ignoreAppointmentId
    ): array {
        $sql =
            'SELECT a.id, a.starts_at, a.ends_at,
                    s.buffer_before_minutes, s.buffer_after_minutes
             FROM appointments a
             INNER JOIN engagements e ON e.id = a.engagement_id
             INNER JOIN services s ON s.id = e.service_id
             WHERE e.workspace_id = ?
               AND a.assigned_user_id = ?
               AND a.status = \'scheduled\'
               AND a.starts_at < ?
               AND a.ends_at > ?';
        $params = [
            $workspaceId,
            $consultantId,
            $dayEnd->modify('+24 hours')->format('Y-m-d H:i:s'),
            $dayStart->modify('-24 hours')->format('Y-m-d H:i:s'),
        ];
        if ($ignoreAppointmentId !== null) {
            $sql .= ' AND a.id <> ?';
            $params[] = $ignoreAppointmentId;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    private function intervalOverlaps(DateTimeImmutable $startsAt, DateTimeImmutable $endsAt, array $blockedRows): bool
    {
        $timezone = $startsAt->getTimezone();
        foreach ($blockedRows as $row) {
            $rowStart = $this->parseWorkspaceDateTime((string)$row['starts_at'], $timezone);
            $rowEnd = $this->parseWorkspaceDateTime((string)$row['ends_at'], $timezone);
            if ($rowStart && $rowEnd && $rowStart < $endsAt && $rowEnd > $startsAt) {
                return true;
            }
        }
        return false;
    }

    private function appointmentIntervalsOverlap(DateTimeImmutable $startsAt, DateTimeImmutable $endsAt, array $appointments, DateTimeZone $timezone): bool
    {
        foreach ($appointments as $appointment) {
            $rowStart = $this->parseWorkspaceDateTime((string)$appointment['starts_at'], $timezone);
            $rowEnd = $this->parseWorkspaceDateTime((string)$appointment['ends_at'], $timezone);
            if (!$rowStart || !$rowEnd) {
                continue;
            }
            $rowStart = $this->addMinutes($rowStart, -((int)$appointment['buffer_before_minutes']));
            $rowEnd = $this->addMinutes($rowEnd, (int)$appointment['buffer_after_minutes']);
            if ($rowStart < $endsAt && $rowEnd > $startsAt) {
                return true;
            }
        }
        return false;
    }

    /**
     * Add real elapsed minutes. DateTimeImmutable::modify('+N minutes') can add wall-clock minutes instead
     * when the range crosses a daylight-saving change (observed on PHP 8.3: 01:30 EDT + 30 min gave
     * 02:00 EST, i.e. 90 real minutes), so the result depends on the PHP version. Timestamps are exact.
     */
    private function addMinutes(DateTimeImmutable $d, int $minutes): DateTimeImmutable
    {
        return $d->setTimestamp($d->getTimestamp() + $minutes * 60);
    }

    /**
     * True when this instant, stored as a workspace wall-clock string (the database keeps no offset),
     * reads back as the same instant. False inside the hour that repeats when clocks go back, where one
     * wall time names two instants and every comparison would silently pick the first.
     */
    private function readsBackExactly(DateTimeImmutable $d): bool
    {
        $back = new DateTimeImmutable($d->format('Y-m-d H:i:s'), $d->getTimezone());
        return $back->getTimestamp() === $d->getTimestamp();
    }

    private function assertNotInClockChange(DateTimeImmutable ...$instants): void
    {
        foreach ($instants as $i) {
            if (!$this->readsBackExactly($i)) {
                throw new InvalidArgumentException('That time overlaps the daylight-saving clock change and cannot be booked. Please choose another time.');
            }
        }
    }

    private function workspaceTimezone(int $workspaceId): DateTimeZone
    {
        $stmt = $this->pdo->prepare('SELECT timezone FROM workspaces WHERE id = ? AND status = \'active\' LIMIT 1');
        $stmt->execute([$workspaceId]);
        $timezone = $stmt->fetchColumn();
        if (!is_string($timezone) || trim($timezone) === '') {
            throw new RuntimeException('Workspace not found.');
        }
        return workspaceTimezone($timezone);
    }

    private function todayStart(DateTimeZone $timezone): DateTimeImmutable
    {
        return (new DateTimeImmutable('now', $timezone))->setTime(0, 0, 0);
    }

    private function parseWorkspaceDateTime(string $value, DateTimeZone $timezone): ?DateTimeImmutable
    {
        $formats = ['Y-m-d H:i:s', 'Y-m-d H:i'];
        foreach ($formats as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value, $timezone);
            if ($date && $date->format($format) === $value) {
                return $date;
            }
        }
        return null;
    }

    private function ceilToInterval(DateTimeImmutable $dateTime, int $intervalMinutes, DateTimeZone $timezone): DateTimeImmutable
    {
        $base = $dateTime->setTimezone($timezone)->setTime(
            (int)$dateTime->format('H'),
            (int)$dateTime->format('i'),
            0
        );
        $minutesSinceMidnight = ((int)$base->format('H') * 60) + (int)$base->format('i');
        $remainder = $minutesSinceMidnight % $intervalMinutes;
        if ($remainder === 0) {
            return $base;
        }
        return $this->addMinutes($base, ($intervalMinutes - $remainder));
    }

    private function recordEvent(
        int $engagementId,
        ?int $actorUserId,
        string $eventType,
        string $description,
        ?array $metadata = null
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO engagement_events
                (engagement_id, actor_user_id, event_type, description, metadata_json)
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
