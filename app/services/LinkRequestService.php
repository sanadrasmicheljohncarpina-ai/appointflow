<?php
declare(strict_types=1);

require_once __DIR__ . '/MailService.php';

/**
 * "Find my appointments" requests.
 *
 * A client enters their email on the public page; we remember the request against their
 * appointment's engagement, tell the owner, and the owner generates/sends the private link
 * from the engagement page. Only emails that belong to a real client are recorded, so the public
 * page cannot be used to probe for clients or to flood the owner.
 */
final class LinkRequestService
{
    private static bool $schemaChecked = false;
    private MailService $mailer;

    public function __construct(private PDO $pdo, ?MailService $mailer = null)
    {
        $this->mailer = $mailer ?? new MailService();
    }

    /**
     * Existing installs will not have run migration 016 yet; create the table on first use.
     * (Fresh installs get it from database/migrations.) Failure is logged, never fatal.
     */
    private function ensureSchema(): void
    {
        if (self::$schemaChecked) {
            return;
        }
        self::$schemaChecked = true;
        try {
            $sql = file_get_contents(__DIR__ . '/../../database/migrations/016_create_client_link_requests.sql');
            if ($sql !== false) {
                $this->pdo->exec($sql);
            }
        } catch (Throwable $e) {
            error_log('[link requests] schema check failed: ' . $e->getMessage());
        }
    }

    /**
     * Record a request for the client's most relevant scheduled appointment (the next upcoming one,
     * otherwise the latest scheduled one). Returns null when the email is unknown or has no appointment. Repeat requests for
     * the same engagement only bump a counter, so the owner is notified once per pending request.
     *
     * @return array{id:int,is_new:bool,engagement_id:int,appointment_id:int,email:string}|null
     */
    public function recordRequest(int $workspaceId, string $email): ?array
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return null;
        }
        $this->ensureSchema();

        $stmt = $this->pdo->prepare('SELECT id FROM clients WHERE workspace_id = ? AND LOWER(email) = ? ORDER BY id ASC LIMIT 1');
        $stmt->execute([$workspaceId, $email]);
        $clientId = $stmt->fetchColumn();
        if ($clientId === false) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT timezone FROM workspaces WHERE id = ? LIMIT 1');
        $stmt->execute([$workspaceId]);
        $nowLocal = (new DateTimeImmutable('now', workspaceTimezone((string)$stmt->fetchColumn())))->format('Y-m-d H:i:s');

        $base =
            'SELECT a.id, a.engagement_id
             FROM appointments a
             INNER JOIN engagements e ON e.id = a.engagement_id
             WHERE e.workspace_id = ? AND e.client_id = ? ';
        $stmt = $this->pdo->prepare($base . 'AND a.status = \'scheduled\' AND a.starts_at > ? ORDER BY a.starts_at ASC, a.id ASC LIMIT 1');
        $stmt->execute([$workspaceId, (int)$clientId, $nowLocal]);
        $appointment = $stmt->fetch();
        if (!$appointment) {
            // Nothing upcoming: fall back to the latest still-scheduled one (the owner can only issue links for scheduled appointments).
            $stmt = $this->pdo->prepare($base . 'AND a.status = \'scheduled\' ORDER BY a.starts_at DESC, a.id DESC LIMIT 1');
            $stmt->execute([$workspaceId, (int)$clientId]);
            $appointment = $stmt->fetch();
        }
        if (!$appointment) {
            return null;
        }
        $engagementId = (int)$appointment['engagement_id'];
        $appointmentId = (int)$appointment['id'];

        $stmt = $this->pdo->prepare(
            'SELECT id FROM client_link_requests
             WHERE workspace_id = ? AND engagement_id = ? AND email = ? AND status = \'pending\'
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$workspaceId, $engagementId, $email]);
        $existingId = $stmt->fetchColumn();
        if ($existingId !== false) {
            $this->pdo->prepare('UPDATE client_link_requests SET request_count = request_count + 1, last_requested_at = CURRENT_TIMESTAMP, appointment_id = ? WHERE id = ?')
                ->execute([$appointmentId, (int)$existingId]);
            return [
                'id' => (int)$existingId,
                'is_new' => false,
                'engagement_id' => $engagementId,
                'appointment_id' => $appointmentId,
                'email' => $email,
            ];
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO client_link_requests (workspace_id, engagement_id, appointment_id, client_id, email)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([$workspaceId, $engagementId, $appointmentId, (int)$clientId, $email]);
            $requestId = (int)$this->pdo->lastInsertId();
            $this->recordEvent(
                $engagementId,
                null,
                'appointment.client_link_requested',
                'Client asked for their private appointment link (' . $email . ').',
                ['appointment_id' => $appointmentId, 'request_id' => $requestId]
            );
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return [
            'id' => $requestId,
            'is_new' => true,
            'engagement_id' => $engagementId,
            'appointment_id' => $appointmentId,
            'email' => $email,
        ];
    }

    /** Email the business owner about a new request. Never throws; returns whether it was sent/saved. */
    public function notifyOwner(int $requestId): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT r.id, r.email, r.engagement_id,
                        c.first_name, c.last_name, e.title,
                        w.name AS workspace_name, w.email AS workspace_email
                 FROM client_link_requests r
                 INNER JOIN engagements e ON e.id = r.engagement_id
                 INNER JOIN clients c ON c.id = r.client_id
                 INNER JOIN workspaces w ON w.id = r.workspace_id
                 WHERE r.id = ?
                 LIMIT 1'
            );
            $stmt->execute([$requestId]);
            $r = $stmt->fetch();
            if (!$r) {
                return false;
            }

            $esc = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $client = trim((string)$r['first_name'] . ' ' . (string)$r['last_name']);
            $url = absoluteUrl(appUrl('public/engagement.php?id=' . (int)$r['engagement_id']));
            $subject = 'Link request: ' . $client . ' wants their appointment link';

            $text = $client . ' (' . $r['email'] . ") asked for their private appointment link.\n\n"
                . 'Engagement: #' . (int)$r['engagement_id'] . ' - ' . $r['title'] . "\n"
                . "Open it, then use \"Generate & email link\" under Client access:\n" . $url . "\n";
            $html = '<p><strong>' . $esc($client) . '</strong> (' . $esc($r['email']) . ') asked for their private appointment link.</p>'
                . '<p>Engagement #' . (int)$r['engagement_id'] . ' &middot; ' . $esc($r['title']) . '</p>'
                . '<p>Open it, then use <strong>Generate &amp; email link</strong> under Client access:<br>'
                . '<a href="' . $esc($url) . '">' . $esc($url) . '</a></p>';

            $this->mailer->send(
                (string)$r['workspace_email'],
                (string)$r['workspace_name'],
                $subject,
                $html,
                $text,
                (string)$r['email'],
                (string)$r['workspace_name'],
                (string)$r['workspace_email']
            );
            return true;
        } catch (Throwable $e) {
            error_log('[link request notify ' . $requestId . '] ' . $e->getMessage());
            return false;
        }
    }

    /** The pending request for an engagement, if any. */
    public function pendingForEngagement(int $workspaceId, int $engagementId): ?array
    {
        try {
            $this->ensureSchema();
            $stmt = $this->pdo->prepare(
                'SELECT id, email, request_count, requested_at, last_requested_at
                 FROM client_link_requests
                 WHERE workspace_id = ? AND engagement_id = ? AND status = \'pending\'
                 ORDER BY id DESC LIMIT 1'
            );
            $stmt->execute([$workspaceId, $engagementId]);
            $row = $stmt->fetch();
            return $row ?: null;
        } catch (Throwable $e) {
            error_log('[link requests] ' . $e->getMessage());
            return null;
        }
    }

    /** Pending requests the user may act on (owners: all; consultants: their assigned engagements). */
    public function pendingForUser(int $workspaceId, int $userId, bool $isOwner, int $limit = 10): array
    {
        try {
            $this->ensureSchema();
            $limit = max(1, min(50, $limit));
            $sql =
                'SELECT r.id, r.engagement_id, r.email, r.request_count, r.last_requested_at,
                        c.first_name, c.last_name, e.title
                 FROM client_link_requests r
                 INNER JOIN engagements e ON e.id = r.engagement_id AND e.workspace_id = r.workspace_id
                 INNER JOIN clients c ON c.id = r.client_id
                 WHERE r.workspace_id = ? AND r.status = \'pending\''
                . ($isOwner ? '' : ' AND e.assigned_user_id = ?')
                . ' ORDER BY r.last_requested_at DESC LIMIT ' . $limit;
            $params = $isOwner ? [$workspaceId] : [$workspaceId, $userId];
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (Throwable $e) {
            error_log('[link requests] ' . $e->getMessage());
            return [];
        }
    }

    /** Close pending requests for an engagement once the link was sent (or the request is ignored). */
    public function resolve(int $workspaceId, int $engagementId, ?int $userId, string $status): int
    {
        if (!in_array($status, ['sent', 'dismissed'], true)) {
            throw new InvalidArgumentException('Unsupported request status.');
        }
        $this->ensureSchema();
        $stmt = $this->pdo->prepare(
            'UPDATE client_link_requests
             SET status = ?, resolved_at = CURRENT_TIMESTAMP, resolved_by_user_id = ?
             WHERE workspace_id = ? AND engagement_id = ? AND status = \'pending\''
        );
        $stmt->execute([$status, $userId, $workspaceId, $engagementId]);
        $count = $stmt->rowCount();
        if ($count > 0 && $status === 'dismissed') {
            $this->recordEvent($engagementId, $userId, 'appointment.client_link_request_dismissed', 'Client link request dismissed.', null);
        }
        return $count;
    }

    private function recordEvent(int $engagementId, ?int $actorUserId, string $eventType, string $description, ?array $metadata): void
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
            $metadata === null ? null : json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
    }
}
