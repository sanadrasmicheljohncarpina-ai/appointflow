<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/database.php';
require_once __DIR__ . '/../app/security.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/permissions.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/validation.php';
require_once __DIR__ . '/../app/services/EngagementService.php';
require_once __DIR__ . '/../app/services/BookingService.php';
require_once __DIR__ . '/../app/services/AppointmentService.php';
require_once __DIR__ . '/../app/services/NotificationService.php';
require_once __DIR__ . '/../app/services/PreparationService.php';
require_once __DIR__ . '/../app/services/MeetingNoteService.php';
require_once __DIR__ . '/../app/services/FollowUpService.php';

/*
 * Preparation -> Meeting -> Outcome -> Follow-up actions for one engagement.
 * Open to the owner and to the consultant assigned to the engagement (requireEngagementAccess).
 * Every action redirects back to the Engagement page, to the section it came from.
 */
$pdo = db();
$currentUser = requireLogin($pdo);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method Not Allowed'); }
$engagementId = validatePositiveInt($_POST['engagement_id'] ?? null, 'Engagement');
$action = (string)($_POST['action'] ?? '');

$anchors = [
    'prep' => '#preparation',
    'notes' => '#meeting',
    'appt' => '#appointment',
    'followup' => '#follow-up',
    'engagement' => '#follow-up',
];
$anchor = '';
foreach ($anchors as $prefix => $fragment) {
    if (str_starts_with($action, $prefix . '_')) {
        $anchor = $fragment;
        break;
    }
}

try {
    verifyCsrf($_POST['csrf_token'] ?? null);
    requireEngagementAccess($pdo, $currentUser, $engagementId);

    $workspaceId = (int)$currentUser['workspace_id'];
    $userId = (int)$currentUser['id'];
    $timezone = workspaceTimezone((string)$currentUser['workspace_timezone']);
    $text = static fn(string $key): string => trim((string)($_POST[$key] ?? ''));

    // The consultation an outcome action refers to must be this engagement's current scheduled one.
    $requireScheduled = static function () use ($pdo, $workspaceId, $engagementId): int {
        $appointmentId = validatePositiveInt($_POST['appointment_id'] ?? null, 'Consultation');
        $current = (new BookingService($pdo))->getScheduledAppointmentForEngagement($workspaceId, $engagementId);
        if ($current === null || (int)$current['id'] !== $appointmentId) {
            throw new InvalidArgumentException('That consultation is no longer scheduled.');
        }
        return $appointmentId;
    };

    switch ($action) {
        // ---- Preparation ---------------------------------------------------------------
        case 'prep_add':
            (new PreparationService($pdo))->add($workspaceId, $engagementId, $userId, $text('title'), $text('due_date'));
            flash('success', 'Preparation task added.');
            break;
        case 'prep_complete':
        case 'prep_reopen':
            (new PreparationService($pdo))->setCompleted(
                $workspaceId,
                $engagementId,
                validatePositiveInt($_POST['task_id'] ?? null, 'Task'),
                $userId,
                $action === 'prep_complete'
            );
            break;
        case 'prep_remove':
            (new PreparationService($pdo))->remove($workspaceId, $engagementId, validatePositiveInt($_POST['task_id'] ?? null, 'Task'));
            flash('success', 'Task removed.');
            break;

        // ---- Meeting notes -------------------------------------------------------------
        case 'notes_save':
            $created = (new MeetingNoteService($pdo))->save(
                $workspaceId,
                $engagementId,
                validatePositiveInt($_POST['appointment_id'] ?? null, 'Consultation'),
                $userId,
                [
                    'summary' => $text('summary'),
                    'problem' => $text('problem'),
                    'requirements' => $text('requirements'),
                    'decisions' => $text('decisions'),
                    'concerns' => $text('concerns'),
                    'next_step' => $text('next_step'),
                ],
                $timezone
            );
            flash('success', $created ? 'Meeting notes saved.' : 'Meeting notes updated.');
            break;

        // ---- Appointment outcome -------------------------------------------------------
        case 'appt_complete':
            (new AppointmentService($pdo))->complete($workspaceId, $requireScheduled(), $userId, $timezone);
            flash('success', 'Consultation completed. Add meeting notes and any follow-ups below.');
            break;
        case 'appt_no_show':
            (new AppointmentService($pdo))->markNoShow($workspaceId, $requireScheduled(), $userId, $timezone);
            flash('success', 'Marked as a no-show. The engagement is qualified again, so you can rebook.');
            break;
        case 'appt_cancel':
            $appointmentId = $requireScheduled();
            $reason = $text('reason');
            if (mb_strlen($reason) > 500) {
                throw new InvalidArgumentException('Cancellation reason must be 500 characters or fewer.');
            }
            (new AppointmentService($pdo))->cancel($workspaceId, $appointmentId, $userId, $reason);
            $mail = (new NotificationService($pdo))->dispatch($workspaceId, $appointmentId, 'cancellation');
            flash('success', 'Consultation cancelled. '
                . ($mail['ok'] ? 'The client was notified by email.' : 'The cancellation email could not be sent, so please let the client know yourself.')
                . ' The engagement is qualified again, so you can rebook.');
            break;

        // ---- Follow-ups ----------------------------------------------------------------
        case 'followup_add':
            (new FollowUpService($pdo))->create(
                $workspaceId,
                $engagementId,
                $userId,
                $text('type'),
                $text('title'),
                $text('notes'),
                $text('due_date')
            );
            flash('success', 'Follow-up created.');
            break;
        case 'followup_update':
            (new FollowUpService($pdo))->update(
                $workspaceId,
                $engagementId,
                validatePositiveInt($_POST['follow_up_id'] ?? null, 'Follow-up'),
                $userId,
                $text('type'),
                $text('title'),
                $text('notes'),
                $text('due_date')
            );
            flash('success', 'Follow-up updated.');
            break;
        case 'followup_complete':
            (new FollowUpService($pdo))->complete($workspaceId, $engagementId, validatePositiveInt($_POST['follow_up_id'] ?? null, 'Follow-up'), $userId);
            flash('success', 'Follow-up completed.');
            break;
        case 'followup_cancel':
            (new FollowUpService($pdo))->cancel($workspaceId, $engagementId, validatePositiveInt($_POST['follow_up_id'] ?? null, 'Follow-up'), $userId);
            flash('success', 'Follow-up cancelled.');
            break;

        // ---- Engagement outcome --------------------------------------------------------
        case 'engagement_convert':
            (new EngagementService($pdo))->transition($engagementId, 'converted', $userId, 'Engagement converted.');
            flash('success', 'Engagement converted.');
            break;
        case 'engagement_close_lost':
            (new EngagementService($pdo))->transition($engagementId, 'closed_lost', $userId, 'Engagement closed as lost.');
            flash('success', 'Engagement closed as lost.');
            break;

        default:
            throw new InvalidArgumentException('Unknown action.');
    }
} catch (InvalidArgumentException | RuntimeException $e) {
    flash('error', $e->getMessage());
} catch (Throwable $e) {
    error_log('[engagement workflow ' . $action . '] ' . $e->getMessage());
    flash('error', 'Unable to complete that action.');
}
redirect(appUrl('public/engagement.php?id=' . $engagementId) . $anchor);
