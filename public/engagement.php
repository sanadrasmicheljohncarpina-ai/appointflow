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
require_once __DIR__ . '/../app/services/IntakeService.php';
require_once __DIR__ . '/../app/services/TeamService.php';
require_once __DIR__ . '/../app/services/BookingService.php';
require_once __DIR__ . '/../app/services/LinkRequestService.php';
require_once __DIR__ . '/../app/services/AppointmentService.php';
require_once __DIR__ . '/../app/services/PreparationService.php';
require_once __DIR__ . '/../app/services/MeetingNoteService.php';
require_once __DIR__ . '/../app/services/FollowUpService.php';

$pdo = db();
$currentUser = requireLogin($pdo);
$engagementId = validatePositiveInt($_GET['id'] ?? null, 'Engagement');
$engagementService = new EngagementService($pdo);
$basic = $engagementService->get((int)$currentUser['workspace_id'], $engagementId);
if ($currentUser['role'] === 'consultant' && (int)$basic['assigned_user_id'] !== (int)$currentUser['id']) {
    http_response_code(403); exit('Forbidden');
}
$engagement = $basic;
$events = $engagementService->getEvents((int)$currentUser['workspace_id'], $engagementId);
$answers = (new IntakeService($pdo))->getAnswers($engagementId);
$consultants = $currentUser['role'] === 'owner' ? array_values(array_filter((new TeamService($pdo))->listMembers((int)$currentUser['workspace_id']), static fn(array $member): bool => $member['role'] === 'consultant' && $member['status'] === 'active')) : [];
$scheduledAppointment = (new BookingService($pdo))->getScheduledAppointmentForEngagement((int)$currentUser['workspace_id'], $engagementId);

// --- Preparation -> Meeting -> Outcome -> Follow-up workspace data -------------------------------
$workspaceId = (int)$currentUser['workspace_id'];
$tz = workspaceTimezone((string)$currentUser['workspace_timezone']);
$nowLocal = (new DateTimeImmutable('now', $tz))->format('Y-m-d H:i:s');
$appointmentOverview = (new AppointmentService($pdo))->overviewForEngagement($workspaceId, $engagementId);
$latestAppointment = $appointmentOverview['latest'];
$appointmentStarted = $scheduledAppointment !== null
    && new DateTimeImmutable((string)$scheduledAppointment['starts_at'], $tz) <= new DateTimeImmutable('now', $tz);

$preparationService = new PreparationService($pdo);
if ($scheduledAppointment !== null) {
    try {
        $preparationService->ensureDefaults($workspaceId, $engagementId, (int)$currentUser['id']);
    } catch (Throwable $e) {
        error_log('[preparation defaults] ' . $e->getMessage());
    }
}
$prepTasks = $preparationService->list($workspaceId, $engagementId);
$prepSummary = PreparationService::summarize($prepTasks);
$showPreparation = $scheduledAppointment !== null || $prepTasks !== [];

// Notes belong to the consultation: the current scheduled one, otherwise the latest completed one.
$meetingAppointment = $scheduledAppointment ?? $appointmentOverview['latest_completed'];
$meetingNote = $meetingAppointment !== null ? (new MeetingNoteService($pdo))->get($workspaceId, (int)$meetingAppointment['id']) : null;
$meetingCanRecord = $meetingAppointment !== null && MeetingNoteService::canRecord($meetingAppointment, $tz);

$followUps = (new FollowUpService($pdo))->listForEngagement($workspaceId, $engagementId);
$canAddFollowUp = in_array($engagement['status'], ['completed', 'follow_up'], true);
$showFollowUps = $canAddFollowUp || $followUps !== [] || in_array($engagement['status'], ['converted', 'closed_lost'], true);

$wfAction = appUrl('public/engagement_workflow.php');
$wfFields = static function (string $action) use ($engagementId): string {
    return '<input type="hidden" name="csrf_token" value="' . h(csrfToken()) . '">'
        . '<input type="hidden" name="engagement_id" value="' . (int)$engagementId . '">'
        . '<input type="hidden" name="action" value="' . h($action) . '">';
};
$error = flash('error');
$success = flash('success');
$clientLink = flash('client_link');
$linkRequest = (new LinkRequestService($pdo))->pendingForEngagement((int)$currentUser['workspace_id'], $engagementId);
// Prefilled message for the owner's own email app (works even when AppointFlow mail is not configured).
$mailtoHref = '';
if ($clientLink) {
    $mailBody = 'Hi ' . $engagement['first_name'] . ",\n\nHere is your private link to view, reschedule or cancel your appointment:\n"
        . $clientLink . "\n\nPlease do not share this link: anyone who has it can change your appointment.\n\nThank you,\n"
        . $currentUser['workspace_name'];
    $mailtoHref = 'mailto:' . str_replace('%40', '@', rawurlencode((string)$engagement['email']))
        . '?subject=' . rawurlencode('Your private appointment link') . '&body=' . rawurlencode($mailBody);
}
$pageTitle = 'Engagement #'.$engagementId;
require __DIR__ . '/../app/views/layouts/header.php';
?>
<section class="page-heading"><div><p class="eyebrow">ENGAGEMENT #<?= (int)$engagement['id'] ?></p><h1><?= h($engagement['first_name'].' '.$engagement['last_name']) ?></h1><p><?= h($engagement['company_name'] ?: 'Individual client') ?> · <?= h($engagement['title']) ?></p></div><div class="heading-actions"><span class="status-badge status-<?= h($engagement['status']) ?>"><?= h(str_replace('_',' ',$engagement['status'])) ?></span><?php if (in_array($engagement['status'], ['qualified'], true) && (int)($engagement['assigned_user_id'] ?? 0) > 0 && !$scheduledAppointment): ?><a class="button" href="<?= h(appUrl('public/booking.php?engagement='.(int)$engagementId)) ?>">Schedule consultation</a><?php endif; ?></div></section>
<?php if ($error): ?><div class="error-box"><?= h($error) ?></div><?php endif; ?><?php if ($success): ?><div class="success-box"><?= h($success) ?></div><?php endif; ?>
<section class="two-column engagement-detail-layout">
<div class="content-stack">
<section class="content-card"><div class="section-head"><div><p class="eyebrow">OVERVIEW</p><h2>Engagement</h2></div></div><div class="info-list"><div><strong>Client</strong><span><?= h($engagement['first_name'].' '.$engagement['last_name']) ?> · <?= h($engagement['email']) ?><?= $engagement['phone'] ? ' · '.h($engagement['phone']) : '' ?></span></div><div><strong>Service</strong><span><?= h($engagement['service_name'] ?: 'Not selected') ?></span></div><div><strong>Assigned</strong><span><?= h(trim(($engagement['assigned_first'] ?? '').' '.($engagement['assigned_last'] ?? '')) ?: 'Unassigned') ?></span></div><div><strong>Source</strong><span><?= h($engagement['source'] ?: '—') ?></span></div><div><strong>Priority</strong><span><?= h(ucfirst($engagement['priority'])) ?></span></div><div><strong>Next action</strong><span><?= h($engagement['next_action'] ?: '—') ?><?= $engagement['next_action_due_at'] ? ' · '.h($engagement['next_action_due_at']) : '' ?></span></div></div></section>
<section class="content-card"><div class="section-head"><div><p class="eyebrow">INQUIRY</p><h2>Original request</h2></div></div><div class="rich-text"><?= nl2br(h($engagement['problem_summary'])) ?></div><?php if ($engagement['qualification_notes']): ?><hr><div class="section-head"><div><p class="eyebrow">QUALIFICATION</p><h2>Internal notes</h2></div></div><div class="rich-text"><?= nl2br(h($engagement['qualification_notes'])) ?></div><?php endif; ?></section>
<section class="content-card"><div class="section-head"><div><p class="eyebrow">INTAKE</p><h2>Collected answers</h2></div></div><?php if (!$answers): ?><div class="empty-state"><strong>No additional intake answers.</strong><p>The core inquiry fields are preserved above.</p></div><?php else: ?><div class="info-list"><?php foreach ($answers as $answer): ?><div><strong><?= h($answer['question']) ?></strong><span><?= nl2br(h($answer['answer_text'])) ?></span></div><?php endforeach; ?></div><?php endif; ?></section>
<section class="content-card" id="appointment"><div class="section-head"><div><p class="eyebrow">APPOINTMENT</p><h2>Consultation</h2></div><?php if ($scheduledAppointment): ?><span class="status-badge status-scheduled">Scheduled</span><?php elseif ($latestAppointment): ?><span class="status-badge <?= $latestAppointment['status'] === 'completed' ? 'status-completed' : 'status-closed_lost' ?>"><?= h(str_replace('_', '-', (string)$latestAppointment['status'])) ?></span><?php endif; ?></div><?php if ($scheduledAppointment): ?><div class="info-list"><div><strong>Date</strong><span><?= h((new DateTimeImmutable((string)$scheduledAppointment['starts_at'], workspaceTimezone((string)$currentUser['workspace_timezone'])))->format('l, F j, Y')) ?></span></div><div><strong>Time</strong><span><?= h((new DateTimeImmutable((string)$scheduledAppointment['starts_at'], workspaceTimezone((string)$currentUser['workspace_timezone'])))->format('g:i A')) ?> – <?= h((new DateTimeImmutable((string)$scheduledAppointment['ends_at'], workspaceTimezone((string)$currentUser['workspace_timezone'])))->format('g:i A')) ?></span></div><div><strong>Consultant</strong><span><?= h(trim($scheduledAppointment['consultant_first_name'].' '.$scheduledAppointment['consultant_last_name'])) ?></span></div></div><?php require __DIR__ . '/../app/views/components/engagement_outcome.php'; ?><hr><div class="section-head" id="client-access"><div><p class="eyebrow">CLIENT ACCESS</p><h2>Client management link</h2></div></div><?php if ($linkRequest): ?><div class="link-request-box"><strong>This client asked for their link</strong><span><?= h($linkRequest['email']) ?> · requested <?= h((new DateTimeImmutable((string)$linkRequest['last_requested_at']))->format('M j, Y g:i A')) ?><?= (int)$linkRequest['request_count'] > 1 ? ' · asked ' . (int)$linkRequest['request_count'] . ' times' : '' ?></span><div class="action-group"><form method="post" action="<?= h(appUrl('public/appointment_link.php')) ?>" class="inline-form"><input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>"><input type="hidden" name="engagement_id" value="<?= (int)$engagementId ?>"><input type="hidden" name="mode" value="generate_email"><button class="button" type="submit">Generate &amp; email link to client</button></form><form method="post" action="<?= h(appUrl('public/appointment_link.php')) ?>" class="inline-form"><input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>"><input type="hidden" name="engagement_id" value="<?= (int)$engagementId ?>"><input type="hidden" name="mode" value="dismiss"><button class="button secondary" type="submit">Dismiss</button></form></div></div><?php endif; ?><?php if ($clientLink): ?><div class="success-box"><strong>Copy this link now — it is shown only once.</strong><br><input type="text" readonly value="<?= h($clientLink) ?>" onclick="this.select()" style="margin-top:8px"><?php if ($mailtoHref !== ''): ?><div class="action-group" style="margin-top:10px"><a class="button secondary small" href="<?= h($mailtoHref) ?>">Open in my email app (to <?= h($engagement['email']) ?>)</a></div><?php endif; ?></div><?php endif; ?><p class="muted">The client can view, reschedule or cancel without logging in. Generating a new link disables any earlier one.</p><form method="post" action="<?= h(appUrl('public/appointment_link.php')) ?>" class="inline-form"><input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>"><input type="hidden" name="engagement_id" value="<?= (int)$engagementId ?>"><button class="button secondary" type="submit">Generate client link</button></form><?php elseif ($latestAppointment): ?><?php $latestLabels = ['completed' => 'Completed', 'no_show' => 'No-show', 'cancelled' => 'Cancelled', 'scheduled' => 'Scheduled']; ?><div class="info-list"><div><strong>Date</strong><span><?= h((new DateTimeImmutable((string)$latestAppointment['starts_at'], $tz))->format('l, F j, Y')) ?></span></div><div><strong>Time</strong><span><?= h((new DateTimeImmutable((string)$latestAppointment['starts_at'], $tz))->format('g:i A')) ?> – <?= h((new DateTimeImmutable((string)$latestAppointment['ends_at'], $tz))->format('g:i A')) ?></span></div><div><strong>Consultant</strong><span><?= h(trim((string)$latestAppointment['consultant_first_name'].' '.(string)$latestAppointment['consultant_last_name'])) ?></span></div><div><strong>Result</strong><span><?= h($latestLabels[$latestAppointment['status']] ?? ucfirst((string)$latestAppointment['status'])) ?><?= $latestAppointment['status'] === 'cancelled' && $latestAppointment['cancellation_reason'] ? ' · ' . h($latestAppointment['cancellation_reason']) : '' ?></span></div></div><p class="muted" style="margin-top:14px"><?php if ($latestAppointment['status'] === 'completed'): ?>This consultation took place. Meeting notes and follow-ups are below.<?php elseif ($engagement['status'] === 'qualified' && (int)($engagement['assigned_user_id'] ?? 0) > 0): ?>No consultation is scheduled now. Use <strong>Schedule consultation</strong> above to rebook.<?php else: ?>No consultation is scheduled now.<?php endif; ?></p><?php else: ?><div class="empty-state"><strong>No consultation scheduled yet.</strong><p><?php if ($engagement['status'] === "qualified" && (int)($engagement['assigned_user_id'] ?? 0) > 0): ?>Use <strong>Schedule consultation</strong> above to choose an available time.<?php elseif ($engagement['status'] === "qualified"): ?>Assign an active consultant before booking can begin.<?php else: ?>The engagement must be qualified before booking is available.<?php endif; ?></p></div><?php endif; ?></section>
<?php if ($showPreparation) { require __DIR__ . '/../app/views/components/engagement_preparation.php'; } ?>
<?php if ($meetingAppointment !== null) { require __DIR__ . '/../app/views/components/engagement_meeting.php'; } ?>
<?php if ($showFollowUps) { require __DIR__ . '/../app/views/components/engagement_followups.php'; } ?>
</div>
<aside class="content-stack">
<?php if ($currentUser['role'] === 'owner' && in_array($engagement['status'], ['new','qualified'], true)): ?><section class="content-card"><div class="section-head"><div><p class="eyebrow">OWNER ACTION</p><h2><?= $engagement['status'] === 'new' ? 'Review inquiry' : 'Assignment' ?></h2></div></div><?php if ($engagement['status'] === 'new'): ?><form method="post" action="<?= h(appUrl('public/engagement_action.php')) ?>" class="form-stack"><input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>"><input type="hidden" name="engagement_id" value="<?= (int)$engagementId ?>"><input type="hidden" name="action" value="qualify"><label>Assign consultant<select name="assigned_user_id"><option value="">Leave unassigned</option><?php foreach ($consultants as $consultant): ?><option value="<?= (int)$consultant['id'] ?>"><?= h($consultant['first_name'].' '.$consultant['last_name']) ?></option><?php endforeach; ?></select></label><label>Qualification notes<textarea name="qualification_notes" rows="5" placeholder="Why is this a fit? What should the consultant know?"></textarea></label><button class="button" type="submit">Qualify inquiry</button></form><hr><form method="post" action="<?= h(appUrl('public/engagement_action.php')) ?>" class="inline-form"><input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>"><input type="hidden" name="engagement_id" value="<?= (int)$engagementId ?>"><input type="hidden" name="action" value="reject"><button class="button danger" type="submit">Reject inquiry</button></form><?php else: ?><form method="post" action="<?= h(appUrl('public/engagement_action.php')) ?>" class="form-stack"><input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>"><input type="hidden" name="engagement_id" value="<?= (int)$engagementId ?>"><input type="hidden" name="action" value="assign"><label>Assigned consultant<select name="assigned_user_id"><option value="">Unassigned</option><?php foreach ($consultants as $consultant): ?><option value="<?= (int)$consultant['id'] ?>" <?= (int)$engagement['assigned_user_id'] === (int)$consultant['id'] ? 'selected' : '' ?>><?= h($consultant['first_name'].' '.$consultant['last_name']) ?></option><?php endforeach; ?></select></label><button class="button" type="submit">Save assignment</button></form><?php endif; ?></section><?php endif; ?>
<section class="content-card"><div class="section-head"><div><p class="eyebrow">ACTIVITY</p><h2>History</h2></div></div><?php if (!$events): ?><div class="empty-state"><strong>No activity yet.</strong></div><?php else: ?><div class="timeline"><?php foreach ($events as $event): ?><div class="timeline-item"><strong><?= h($event['description']) ?></strong><span><?= h($event['created_at']) ?><?= $event['actor_first'] ? ' · '.h($event['actor_first'].' '.$event['actor_last']) : '' ?></span></div><?php endforeach; ?></div><?php endif; ?></section>
</aside></section>
<?php require __DIR__ . '/../app/views/layouts/footer.php'; ?>
