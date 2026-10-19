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
require_once __DIR__ . '/../app/services/ConsultationService.php';
require_once __DIR__ . '/../app/services/FollowUpService.php';
require_once __DIR__ . '/../app/services/OperationsService.php';

/**
 * Milestone 8 actions: preparation checklist, meeting notes, completion / no-show,
 * follow-ups and the final outcome. Owners can act on any engagement in their workspace;
 * a consultant can act only on engagements assigned to them (requireEngagementAccess).
 */
/** Remember what was typed in the long forms so a validation error does not lose it. */
function keepInput(string $action, int $engagementId): void
{
    $forms = [
        'notes_save' => ['notes_save', ['summary', 'problem', 'requirements', 'decisions', 'concerns', 'next_step']],
        'followup_create' => ['followup_create', ['title', 'type', 'due_at', 'notes']],
        'prep_add' => ['prep_add', ['title', 'description', 'due_at']],
    ];
    if (!isset($forms[$action])) {
        return;
    }
    ensureSession();
    $data = [];
    foreach ($forms[$action][1] as $key) {
        $data[$key] = substr((string)($_POST[$key] ?? ''), 0, 10000);
    }
    $_SESSION['_old_input'] = ['form' => $forms[$action][0], 'engagement_id' => $engagementId, 'data' => $data];
}

$pdo = db();
$currentUser = requireLogin($pdo);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method Not Allowed'); }

$engagementId = validatePositiveInt($_POST['engagement_id'] ?? null, 'Engagement');
requireEngagementAccess($pdo, $currentUser, $engagementId);

$workspaceId = (int)$currentUser['workspace_id'];
$userId = (int)$currentUser['id'];
$isOwner = $currentUser['role'] === 'owner';
$anchor = 'preparation';
$action = (string)($_POST['action'] ?? '');

/*
 * Milestone 9: follow-up actions can be started from the Follow-up Center or the dashboard.
 * Only a fixed list of pages is accepted as a return target (never a URL from the request), so
 * this cannot be used as an open redirect.
 */
$returnPages = ['followups' => 'public/followups.php', 'dashboard' => 'public/dashboard.php'];
$returnKey = (string)($_POST['return'] ?? '');
$returnUrl = null;
if (isset($returnPages[$returnKey]) && in_array($action, ['followup_update', 'followup_complete', 'followup_cancel'], true)) {
    $returnUrl = appUrl($returnPages[$returnKey]);
    if ($returnKey === 'followups') {
        $query = [];
        $returnFilter = (string)($_POST['return_filter'] ?? '');
        if (in_array($returnFilter, OperationsService::FOLLOWUP_FILTERS, true)) {
            $query['filter'] = $returnFilter;
        }
        $returnAssignee = (string)($_POST['return_assignee'] ?? '');
        if ($isOwner && ctype_digit($returnAssignee) && (int)$returnAssignee > 0) {
            $query['assignee'] = (string)(int)$returnAssignee;
        }
        if ($query) {
            $returnUrl .= '?' . http_build_query($query);
        }
    }
}

try {
    verifyCsrf($_POST['csrf_token'] ?? null);
    $consultation = new ConsultationService($pdo);
    $followUps = new FollowUpService($pdo);

    // Only owners may pick a different assignee; consultants keep the engagement's assignee.
    $assigneeFromForm = static function () use ($isOwner): ?int {
        $raw = trim((string)($_POST['assigned_user_id'] ?? ''));
        return $isOwner && $raw !== '' ? validatePositiveInt($raw, 'Assignee') : null;
    };

    switch ($action) {
        case 'prep_add':
            $anchor = 'preparation';
            $consultation->addPreparationTask($workspaceId, $engagementId, $userId, (string)($_POST['title'] ?? ''), (string)($_POST['description'] ?? ''), (string)($_POST['due_at'] ?? ''));
            flash('success', 'Preparation task added.');
            break;
        case 'prep_starter':
            $anchor = 'preparation';
            $count = $consultation->addStarterChecklist($workspaceId, $engagementId, $userId);
            flash('success', $count . ' starter tasks added.');
            break;
        case 'prep_done':
        case 'prep_reopen':
            $anchor = 'preparation';
            $consultation->setPreparationTaskDone($workspaceId, $engagementId, $userId, validatePositiveInt($_POST['task_id'] ?? null, 'Task'), $action === 'prep_done');
            flash('success', $action === 'prep_done' ? 'Task completed.' : 'Task reopened.');
            break;
        case 'prep_remove':
            $anchor = 'preparation';
            $consultation->removePreparationTask($workspaceId, $engagementId, $userId, validatePositiveInt($_POST['task_id'] ?? null, 'Task'));
            flash('success', 'Task removed.');
            break;
        case 'notes_save':
            $anchor = 'meeting';
            $consultation->saveMeetingNote($workspaceId, $engagementId, $userId, validatePositiveInt($_POST['appointment_id'] ?? null, 'Appointment'), [
                'summary' => $_POST['summary'] ?? '',
                'problem' => $_POST['problem'] ?? '',
                'requirements' => $_POST['requirements'] ?? '',
                'decisions' => $_POST['decisions'] ?? '',
                'concerns' => $_POST['concerns'] ?? '',
                'next_step' => $_POST['next_step'] ?? '',
            ]);
            flash('success', 'Meeting notes saved.');
            break;
        case 'meeting_complete':
            $anchor = 'meeting';
            $consultation->completeAppointment($workspaceId, $engagementId, $userId, validatePositiveInt($_POST['appointment_id'] ?? null, 'Appointment'));
            $anchor = 'followups';
            flash('success', 'Consultation marked completed. Create a follow-up or record the outcome.');
            break;
        case 'meeting_no_show':
            $anchor = 'meeting';
            $consultation->markNoShow($workspaceId, $engagementId, $userId, validatePositiveInt($_POST['appointment_id'] ?? null, 'Appointment'));
            flash('success', 'Marked as a no-show. The engagement is back to qualified so it can be rebooked.');
            break;
        case 'followup_create':
            $anchor = 'followups';
            $followUps->create($workspaceId, $engagementId, $userId, $assigneeFromForm(), (string)($_POST['type'] ?? ''), (string)($_POST['title'] ?? ''), (string)($_POST['notes'] ?? ''), (string)($_POST['due_at'] ?? ''));
            flash('success', 'Follow-up created.');
            break;
        case 'followup_update':
            $anchor = 'followups';
            $followUps->update($workspaceId, $engagementId, $userId, validatePositiveInt($_POST['follow_up_id'] ?? null, 'Follow-up'), $assigneeFromForm(), (string)($_POST['type'] ?? ''), (string)($_POST['title'] ?? ''), (string)($_POST['notes'] ?? ''), (string)($_POST['due_at'] ?? ''));
            flash('success', 'Follow-up updated.');
            break;
        case 'followup_complete':
            $anchor = 'followups';
            $followUps->complete($workspaceId, $engagementId, $userId, validatePositiveInt($_POST['follow_up_id'] ?? null, 'Follow-up'));
            flash('success', 'Follow-up completed.');
            break;
        case 'followup_cancel':
            $anchor = 'followups';
            $followUps->cancel($workspaceId, $engagementId, $userId, validatePositiveInt($_POST['follow_up_id'] ?? null, 'Follow-up'));
            flash('success', 'Follow-up cancelled.');
            break;
        case 'outcome_converted':
        case 'outcome_closed_lost':
            $anchor = 'outcome';
            $outcome = $action === 'outcome_converted' ? 'converted' : 'closed_lost';
            $consultation->conclude($workspaceId, $engagementId, $userId, $outcome, (string)($_POST['outcome_note'] ?? ''));
            flash('success', $outcome === 'converted' ? 'Engagement converted.' : 'Engagement closed as lost.');
            break;
        default:
            throw new InvalidArgumentException('Unknown consultation action.');
    }
} catch (InvalidArgumentException | RuntimeException $e) {
    flash('error', $e->getMessage());
    keepInput($action ?? '', $engagementId);
} catch (Throwable $e) {
    error_log('[consultation action] ' . $e->getMessage());
    flash('error', 'Unable to complete that action.');
    keepInput($action ?? '', $engagementId);
}
if ($returnUrl !== null) {
    redirect($returnUrl);
}
redirect(appUrl('public/engagement.php?id=' . $engagementId) . '#' . $anchor);
