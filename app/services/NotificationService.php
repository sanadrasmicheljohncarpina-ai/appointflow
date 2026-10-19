<?php
declare(strict_types=1);

require_once __DIR__ . '/MailService.php';

final class NotificationService
{
    private MailService $mailer;

    public function __construct(private PDO $pdo, ?MailService $mailer = null)
    {
        $this->mailer = $mailer ?? new MailService();
    }

    public function queue(int $workspaceId, ?int $engagementId, ?int $appointmentId, string $recipientEmail, string $type): int
    {
        $allowed = ['confirmation', 'reminder', 'cancellation', 'reschedule'];
        if (!in_array($type, $allowed, true)) {
            throw new InvalidArgumentException('Unsupported notification type.');
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO notification_log
                (workspace_id, engagement_id, appointment_id, recipient_email, notification_type, status)
             VALUES (?, ?, ?, ?, ?, \'pending\')'
        );
        $stmt->execute([$workspaceId, $engagementId, $appointmentId, $recipientEmail, $type]);
        return (int)$this->pdo->lastInsertId();
    }

    public function driver(): string
    {
        return $this->mailer->driver();
    }

    /**
     * Send (or, in log mode, save) the email for an appointment and record the outcome in notification_log.
     * Never throws: failures are logged and returned so a mail problem can never break booking.
     *
     * $workspaceId the workspace that owns the appointment; an appointment from any other workspace is rejected
     * $type     notification_log type: confirmation | reminder | cancellation | reschedule
     * $token    raw client token, when known, to include the private appointment link
     * $template email wording; defaults to $type. 'link' = "here is your private link" (logged as 'confirmation')
     *
     * @return array{ok:bool,driver:string,to:string}
     */
    public function dispatch(int $workspaceId, int $appointmentId, string $type, ?string $token = null, ?string $template = null): array
    {
        $template = $template ?? $type;
        $driver = $this->mailer->driver();
        $notificationId = null;
        $to = '';
        try {
            $c = $this->loadContext($workspaceId, $appointmentId);
            $to = (string)$c['client_email'];
            $notificationId = $this->claimPending($c, $type, $template === $type);
            $c['link'] = ($token !== null && $token !== '') ? $this->absolute(clientAppointmentUrl($token)) : null;
            $c['find_link'] = $this->absolute(findAppointmentsUrl((string)$c['booking_slug']));
            [$subject, $html, $text] = $this->render($template, $c);

            $this->mailer->send(
                $to,
                trim((string)$c['client_first_name'] . ' ' . (string)$c['client_last_name']),
                $subject,
                $html,
                $text,
                (string)($c['workspace_email'] ?? ''),
                (string)$c['workspace_name'],
                (string)($c['workspace_email'] ?? '')
            );

            $this->pdo->prepare('UPDATE notification_log SET status = \'sent\', sent_at = CURRENT_TIMESTAMP, error_message = NULL WHERE id = ?')
                ->execute([$notificationId]);
            if ($template === 'confirmation') {
                $this->pdo->prepare('UPDATE appointments SET confirmation_sent_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$appointmentId]);
            } elseif ($template === 'reminder') {
                $this->pdo->prepare('UPDATE appointments SET reminder_sent_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$appointmentId]);
            }
            return ['ok' => true, 'driver' => $driver, 'to' => $to];
        } catch (Throwable $e) {
            error_log('[notification ' . $template . ' appointment ' . $appointmentId . '] ' . $e->getMessage());
            if ($notificationId !== null) {
                try {
                    $this->pdo->prepare('UPDATE notification_log SET status = \'failed\', error_message = ? WHERE id = ?')
                        ->execute([substr($e->getMessage(), 0, 1000), $notificationId]);
                } catch (Throwable) {
                    // The original failure is already logged.
                }
            }
            return ['ok' => false, 'driver' => $driver, 'to' => $to];
        }
    }

    private function loadContext(int $workspaceId, int $appointmentId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.id AS appointment_id, a.engagement_id, a.starts_at, a.ends_at,
                    e.workspace_id,
                    c.first_name AS client_first_name, c.last_name AS client_last_name, c.email AS client_email,
                    s.name AS service_name,
                    w.name AS workspace_name, w.email AS workspace_email, w.booking_slug, w.timezone,
                    u.first_name AS consultant_first_name, u.last_name AS consultant_last_name
             FROM appointments a
             INNER JOIN engagements e ON e.id = a.engagement_id
             INNER JOIN clients c ON c.id = e.client_id
             INNER JOIN services s ON s.id = e.service_id
             INNER JOIN workspaces w ON w.id = e.workspace_id
             LEFT JOIN users u ON u.id = a.assigned_user_id
             WHERE a.id = ? AND e.workspace_id = ?
             LIMIT 1'
        );
        $stmt->execute([$appointmentId, $workspaceId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException('Appointment not found for notification.');
        }
        return $row;
    }

    /** Reuse the pending row queued by the booking transaction, or create one. */
    private function claimPending(array $c, string $type, bool $reuse): int
    {
        if ($reuse) {
            $stmt = $this->pdo->prepare(
                'SELECT id FROM notification_log
                 WHERE appointment_id = ? AND notification_type = ? AND status = \'pending\'
                 ORDER BY id DESC LIMIT 1'
            );
            $stmt->execute([(int)$c['appointment_id'], $type]);
            $id = $stmt->fetchColumn();
            if ($id !== false) {
                return (int)$id;
            }
        }
        return $this->queue((int)$c['workspace_id'], (int)$c['engagement_id'], (int)$c['appointment_id'], (string)$c['client_email'], $type);
    }

    private function absolute(string $path): string
    {
        if (PHP_SAPI === 'cli' || empty($_SERVER['HTTP_HOST'])) {
            if (defined('APP_PUBLIC_URL') && APP_PUBLIC_URL !== '') {
                return rtrim((string)APP_PUBLIC_URL, '/') . $path;
            }
            return rtrim((string)$this->mailer->config('base_url', 'http://localhost'), '/') . $path;
        }
        return absoluteUrl($path);
    }

    /** @return array{0:string,1:string,2:string} subject, html, text */
    private function render(string $template, array $c): array
    {
        $e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $tz = workspaceTimezone((string)$c['timezone']);
        $start = new DateTimeImmutable((string)$c['starts_at'], $tz);
        $when = $start->format('l, F j, Y \a\t g:i A') . ' (' . $c['timezone'] . ')';
        $business = (string)$c['workspace_name'];
        $first = (string)$c['client_first_name'];
        $consultant = trim((string)($c['consultant_first_name'] ?? '') . ' ' . (string)($c['consultant_last_name'] ?? ''));
        $link = $c['link'];
        $linkText = null;

        switch ($template) {
            case 'confirmation':
                $subject = 'Your consultation is confirmed — ' . $start->format('M j, Y g:i A');
                $intro = 'Your consultation has been scheduled.';
                $linkText = $link ? 'View, reschedule or cancel your appointment here:' : null;
                break;
            case 'reschedule':
                $subject = 'Your appointment has been rescheduled';
                $intro = 'Your appointment has been rescheduled. Here is your new time.';
                $linkText = $link ? 'View or change your appointment here:' : null;
                break;
            case 'cancellation':
                $subject = 'Your appointment has been cancelled';
                $intro = 'Your appointment has been cancelled. If this was a mistake, please contact us.';
                $linkText = $link ? 'You can review your appointments here:' : null;
                break;
            case 'reminder':
                $subject = 'Reminder: your consultation on ' . $start->format('M j, Y g:i A');
                $intro = 'This is a friendly reminder of your upcoming consultation.';
                $link = $c['find_link'];
                $linkText = 'Need to make a change? Get a fresh private link here:';
                break;
            case 'link':
                $subject = 'Your private appointment link';
                $intro = 'Here is the private link you asked for. Do not share it: anyone with it can change your appointment.';
                $linkText = 'Open your appointments:';
                break;
            default:
                throw new InvalidArgumentException('Unknown email template.');
        }

        $rows = [['Service', (string)$c['service_name']], ['When', $when]];
        if ($consultant !== '') {
            $rows[] = ['Consultant', $consultant];
        }

        $htmlRows = '';
        $textRows = '';
        foreach ($rows as [$label, $value]) {
            $htmlRows .= '<tr><td style="padding:6px 18px 6px 0;color:#65717c;font-size:13px">' . $e($label)
                . '</td><td style="padding:6px 0;font-size:14px;font-weight:600">' . $e($value) . '</td></tr>';
            $textRows .= $label . ': ' . $value . "\n";
        }

        $htmlLink = '';
        $textLink = '';
        if ($linkText !== null && $link) {
            $htmlLink = '<p style="margin:22px 0 8px;font-size:14px">' . $e($linkText) . '</p>'
                . '<p style="margin:0 0 6px"><a href="' . $e($link) . '" style="display:inline-block;background:#0d2941;color:#ffffff;padding:12px 18px;border-radius:8px;text-decoration:none;font-weight:700">Open my appointment page</a></p>'
                . '<p style="margin:8px 0 0;font-size:12px;color:#65717c;word-break:break-all">' . $e($link) . '</p>';
            $textLink = "\n" . $linkText . "\n" . $link . "\n";
        }

        $html = '<!doctype html><html><body style="margin:0;padding:24px;background:#f3f6f9;font-family:Arial,Helvetica,sans-serif;color:#1b2733">'
            . '<div style="max-width:560px;margin:0 auto;background:#ffffff;border:1px solid #dde5ea;border-radius:14px;padding:28px">'
            . '<p style="margin:0 0 4px;font-size:11px;font-weight:700;letter-spacing:1.5px;color:#1aa69b">' . $e(strtoupper($business)) . '</p>'
            . '<h1 style="margin:0 0 14px;font-size:22px">' . $e($subject) . '</h1>'
            . '<p style="margin:0 0 16px;font-size:14px;line-height:1.6">Hello ' . $e($first) . ',<br>' . $e($intro) . '</p>'
            . '<table role="presentation" style="border-collapse:collapse">' . $htmlRows . '</table>'
            . $htmlLink
            . '<p style="margin:26px 0 0;font-size:12px;color:#65717c">Sent by ' . $e($business) . ' · Powered by AppointFlow</p>'
            . '</div></body></html>';

        $text = 'Hello ' . $first . ",\n" . $intro . "\n\n" . $textRows . $textLink . "\n— " . $business . "\n";

        return [$subject, $html, $text];
    }
}
