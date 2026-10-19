<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/database.php';
require_once __DIR__ . '/../app/security.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/permissions.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/validation.php';
require_once __DIR__ . '/../app/services/BookingService.php';
require_once __DIR__ . '/../app/services/EngagementService.php';
require_once __DIR__ . '/../app/services/NotificationService.php';
require_once __DIR__ . '/../app/services/LinkRequestService.php';

$pdo = db();
$currentUser = requireLogin($pdo);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method Not Allowed'); }
$engagementId = validatePositiveInt($_POST['engagement_id'] ?? null, 'Engagement');

// generate        - create a new private link and show it once (original behaviour)
// generate_email  - create the link AND email it to the client who asked for it
// dismiss         - close the client's link request without sending anything
$mode = (string)($_POST['mode'] ?? 'generate');
if (!in_array($mode, ['generate', 'generate_email', 'dismiss'], true)) {
    $mode = 'generate';
}

try {
    verifyCsrf($_POST['csrf_token'] ?? null);
    $workspaceId = (int)$currentUser['workspace_id'];
    $userId = (int)$currentUser['id'];
    $isOwner = $currentUser['role'] === 'owner';
    $requests = new LinkRequestService($pdo);

    if ($mode === 'dismiss') {
        $engagement = (new EngagementService($pdo))->get($workspaceId, $engagementId);
        if (!$isOwner && (int)$engagement['assigned_user_id'] !== $userId) {
            throw new RuntimeException('You do not have access to this engagement.');
        }
        $requests->resolve($workspaceId, $engagementId, $userId, 'dismissed');
        flash('success', 'Link request dismissed.');
        redirect(appUrl('public/engagement.php?id=' . $engagementId));
    }

    $booking = new BookingService($pdo);
    $token = $booking->issueClientToken($workspaceId, $engagementId, $userId, $isOwner);
    $url = absoluteUrl(clientAppointmentUrl($token));

    // Shown once on the engagement page, then discarded; the raw token is never stored.
    flash('client_link', $url);

    if ($mode === 'generate_email') {
        $appointment = $booking->getScheduledAppointmentForEngagement($workspaceId, $engagementId);
        $result = $appointment === null
            ? ['ok' => false, 'driver' => 'log', 'to' => '']
            : (new NotificationService($pdo))->dispatch($workspaceId, (int)$appointment['id'], 'confirmation', $token, 'link');

        if ($result['ok']) {
            $requests->resolve($workspaceId, $engagementId, $userId, 'sent');
            flash('success', $result['driver'] === 'smtp'
                ? 'Link generated and emailed to ' . $result['to'] . '.'
                : 'Link generated. Mail is in log mode, so the email to ' . $result['to'] . ' was saved in storage/mail and not actually sent. Use the link below instead.');
        } else {
            flash('error', 'The link was generated but the email could not be sent. Copy the link below or open it in your email app, and send it yourself.');
        }
    } else {
        // The owner is handling the client's request by hand (copy / their own email app).
        $requests->resolve($workspaceId, $engagementId, $userId, 'sent');
        flash('success', 'Client management link generated. Copy it now — it will not be shown again.');
    }
} catch (InvalidArgumentException | RuntimeException $e) {
    flash('error', $e->getMessage());
} catch (Throwable $e) {
    error_log('[appointment link] ' . $e->getMessage());
    flash('error', 'Unable to generate the client link.');
}
redirect(appUrl('public/engagement.php?id=' . $engagementId));
