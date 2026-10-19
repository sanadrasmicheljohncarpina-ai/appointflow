<?php
/**
 * Milestone 8 — Preparation / Meeting / Follow-up workspace sections for the Engagement page.
 * Expects: $engagement, $engagementId, $currentUser, $prepTasks, $meetingAppointment,
 *          $meetingNote, $followUpList, $assignableMembers, $oldInput
 * Outputs the three main-column cards. (The Outcome card is consultation_outcome.php.)
 */
$cwTz = workspaceTimezone((string)$currentUser['workspace_timezone']);
$cwNow = new DateTimeImmutable('now', $cwTz);
$cwStatus = (string)$engagement['status'];
$cwIsOwner = $currentUser['role'] === 'owner';
$cwAction = appUrl('public/consultation_action.php');
$cwFmt = static fn(?string $v, string $f = 'M j, Y g:i A'): string => $v ? (new DateTimeImmutable($v, $cwTz))->format($f) : '';
$cwInput = static fn(string $form, string $key, string $default = ''): string => (string)(($oldInput['form'] ?? '') === $form ? ($oldInput['data'][$key] ?? $default) : $default);
$cwHidden = static function (string $action, array $extra = []) use ($engagementId): string {
    $html = '<input type="hidden" name="csrf_token" value="' . h(csrfToken()) . '"><input type="hidden" name="engagement_id" value="' . (int)$engagementId . '"><input type="hidden" name="action" value="' . h($action) . '">';
    foreach ($extra as $name => $value) {
        $html .= '<input type="hidden" name="' . h($name) . '" value="' . h($value) . '">';
    }
    return $html;
};

$prepEditable = in_array($cwStatus, ['qualified', 'scheduled'], true);
$showPrep = $prepTasks || $prepEditable;
$prepDone = count(array_filter($prepTasks, static fn(array $t): bool => $t['status'] === 'completed'));

$notesEditable = $meetingAppointment !== null && in_array($cwStatus, ['scheduled', 'completed', 'follow_up'], true);
$showMeeting = $meetingAppointment !== null;
$meetingStarted = $meetingAppointment !== null && (new DateTimeImmutable((string)$meetingAppointment['starts_at'], $cwTz)) <= $cwNow;

$followUpCreatable = in_array($cwStatus, ['completed', 'follow_up'], true);
$showFollowUps = $followUpCreatable || $followUpList;
?>
<?php if ($showPrep): ?>
<section class="content-card" id="preparation">
    <div class="section-head"><div><p class="eyebrow">PREPARATION</p><h2>Consultation checklist</h2></div><?php if ($prepTasks): ?><span class="status-badge <?= $prepDone === count($prepTasks) ? 'status-completed' : 'status-scheduled' ?>"><?= (int)$prepDone ?> of <?= count($prepTasks) ?> done</span><?php endif; ?></div>
    <?php if (!$prepTasks): ?>
        <div class="empty-state"><strong>No preparation tasks yet.</strong><p>Add what needs to happen before the meeting, or start from the standard checklist.</p>
            <form method="post" action="<?= h($cwAction) ?>" class="inline-form"><?= $cwHidden('prep_starter') ?><button class="button secondary" type="submit">Add starter checklist</button></form></div>
    <?php else: ?>
        <ul class="task-list">
        <?php foreach ($prepTasks as $task): $done = $task['status'] === 'completed'; ?>
            <li class="task-item<?= $done ? ' is-done' : '' ?>">
                <div class="task-main">
                    <strong><?= h($task['title']) ?></strong>
                    <?php if ($task['description']): ?><span><?= nl2br(h($task['description'])) ?></span><?php endif; ?>
                    <?php if ($task['due_at'] && !$done): ?><span class="<?= (new DateTimeImmutable((string)$task['due_at'], $cwTz)) < $cwNow ? 'text-overdue' : '' ?>">Due <?= h($cwFmt((string)$task['due_at'])) ?></span><?php endif; ?>
                    <?php if ($done && $task['completed_at']): ?><span>Done <?= h($cwFmt((string)$task['completed_at'])) ?></span><?php endif; ?>
                </div>
                <?php if ($prepEditable): ?>
                <div class="action-group">
                    <form method="post" action="<?= h($cwAction) ?>" class="inline-form"><?= $cwHidden($done ? 'prep_reopen' : 'prep_done', ['task_id' => (string)$task['id']]) ?><button class="button <?= $done ? 'secondary' : '' ?> small" type="submit"><?= $done ? 'Reopen' : 'Mark done' ?></button></form>
                    <form method="post" action="<?= h($cwAction) ?>" class="inline-form" onsubmit="return confirm('Remove this task?');"><?= $cwHidden('prep_remove', ['task_id' => (string)$task['id']]) ?><button class="button danger small" type="submit">Remove</button></form>
                </div>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <?php if ($prepEditable): ?>
        <details class="inline-details" <?= ($oldInput['form'] ?? '') === 'prep_add' ? 'open' : '' ?>><summary>Add a preparation task</summary>
            <form method="post" action="<?= h($cwAction) ?>" class="form-stack"><?= $cwHidden('prep_add') ?>
                <label>Task<input type="text" name="title" maxlength="200" required value="<?= h($cwInput('prep_add', 'title')) ?>"></label>
                <label>Details (optional)<textarea name="description" rows="3" maxlength="5000"><?= h($cwInput('prep_add', 'description')) ?></textarea></label>
                <label>Due (optional)<input type="datetime-local" name="due_at" value="<?= h($cwInput('prep_add', 'due_at')) ?>"></label>
                <button class="button" type="submit">Add task</button>
            </form>
        </details>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($showMeeting): $apptStatus = (string)$meetingAppointment['status']; ?>
<section class="content-card" id="meeting">
    <div class="section-head"><div><p class="eyebrow">MEETING</p><h2>Meeting workspace</h2></div><span class="status-badge status-<?= $apptStatus === 'completed' ? 'completed' : 'scheduled' ?>"><?= $apptStatus === 'completed' ? 'Completed' : 'Scheduled' ?></span></div>
    <div class="info-list">
        <div><strong>When</strong><span><?= h($cwFmt((string)$meetingAppointment['starts_at'], 'l, F j, Y · g:i A')) ?> – <?= h($cwFmt((string)$meetingAppointment['ends_at'], 'g:i A')) ?></span></div>
        <div><strong>Consultant</strong><span><?= h(trim($meetingAppointment['consultant_first_name'] . ' ' . $meetingAppointment['consultant_last_name'])) ?></span></div>
        <?php if ($apptStatus === 'completed' && $meetingAppointment['completed_at']): ?><div><strong>Completed</strong><span><?= h($cwFmt((string)$meetingAppointment['completed_at'])) ?></span></div><?php endif; ?>
    </div>
    <hr>
    <?php if ($notesEditable): ?>
        <h3 class="subhead">Meeting notes</h3>
        <?php if ($meetingNote): ?><p class="muted">Last saved <?= h($cwFmt((string)$meetingNote['updated_at'])) ?><?= $meetingNote['author_first'] ? ' by ' . h($meetingNote['author_first'] . ' ' . $meetingNote['author_last']) : '' ?>.</p><?php endif; ?>
        <form method="post" action="<?= h($cwAction) ?>" class="form-stack"><?= $cwHidden('notes_save', ['appointment_id' => (string)$meetingAppointment['id']]) ?>
            <?php
            $noteFields = [
                'summary' => ['Summary', true, 'What was discussed, in a few sentences.'],
                'problem' => ['Problem discussed', true, 'The client\'s problem in their own terms.'],
                'requirements' => ['Requirements', true, 'What the client needs from us.'],
                'decisions' => ['Decisions (optional)', false, ''],
                'concerns' => ['Concerns (optional)', false, 'Risks, objections, budget or timing worries.'],
                'next_step' => ['Agreed next step', true, 'What happens next, and who does it.'],
            ];
            foreach ($noteFields as $key => [$label, $required, $placeholder]): ?>
                <label><?= h($label) ?><textarea name="<?= h($key) ?>" rows="3" maxlength="10000" <?= $required ? 'required' : '' ?> placeholder="<?= h($placeholder) ?>"><?= h($cwInput('notes_save', $key, (string)($meetingNote[$key] ?? ''))) ?></textarea></label>
            <?php endforeach; ?>
            <button class="button" type="submit"><?= $meetingNote ? 'Update notes' : 'Save notes' ?></button>
        </form>
    <?php elseif ($meetingNote): ?>
        <h3 class="subhead">Meeting notes</h3>
        <div class="info-list">
            <?php foreach (['summary' => 'Summary', 'problem' => 'Problem discussed', 'requirements' => 'Requirements', 'decisions' => 'Decisions', 'concerns' => 'Concerns', 'next_step' => 'Agreed next step'] as $key => $label): if (!$meetingNote[$key]) continue; ?>
                <div><strong><?= h($label) ?></strong><span><?= nl2br(h($meetingNote[$key])) ?></span></div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="empty-state"><strong>No meeting notes were recorded.</strong></div>
    <?php endif; ?>

    <?php if ($apptStatus === 'scheduled' && $cwStatus === 'scheduled'): ?>
        <hr>
        <h3 class="subhead">After the meeting</h3>
        <?php if (!$meetingStarted): ?>
            <p class="muted">You can mark this consultation completed or as a no-show once it starts.</p>
        <?php elseif (!$meetingNote): ?>
            <p class="muted">Save the meeting notes above, then mark the consultation completed.</p>
        <?php endif; ?>
        <div class="action-group">
            <form method="post" action="<?= h($cwAction) ?>" class="inline-form"><?= $cwHidden('meeting_complete', ['appointment_id' => (string)$meetingAppointment['id']]) ?><button class="button" type="submit" <?= (!$meetingStarted || !$meetingNote) ? 'disabled' : '' ?>>Mark consultation completed</button></form>
            <?php if ($meetingStarted): ?><form method="post" action="<?= h($cwAction) ?>" class="inline-form" onsubmit="return confirm('Mark this as a no-show? The engagement returns to qualified so it can be rebooked.');"><?= $cwHidden('meeting_no_show', ['appointment_id' => (string)$meetingAppointment['id']]) ?><button class="button danger" type="submit">Client did not attend</button></form><?php endif; ?>
        </div>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($showFollowUps): ?>
<section class="content-card" id="followups">
    <div class="section-head"><div><p class="eyebrow">FOLLOW-UP</p><h2>Follow-ups</h2></div></div>
    <?php if (!$followUpList): ?>
        <div class="empty-state"><strong>No follow-ups yet.</strong><p>Create the next action to keep this engagement moving, or record the outcome.</p></div>
    <?php else: ?>
        <ul class="task-list">
        <?php foreach ($followUpList as $fu):
            $pending = $fu['status'] === 'pending';
            $overdue = $pending && (new DateTimeImmutable((string)$fu['due_at'], $cwTz)) < $cwNow;
            $badge = ['pending' => $overdue ? 'status-closed_lost' : 'status-follow_up', 'completed' => 'status-completed', 'cancelled' => 'status-new'][$fu['status']] ?? 'status-new';
        ?>
            <li class="task-item<?= $pending ? '' : ' is-done' ?>">
                <div class="task-main">
                    <strong><?= h($fu['title']) ?> <span class="status-badge <?= h($badge) ?>"><?= h($overdue ? 'Overdue' : $fu['status']) ?></span></strong>
                    <span><?= h(ucfirst((string)$fu['type'])) ?> · <?= $pending ? 'Due ' : ($fu['status'] === 'completed' ? 'Was due ' : 'Was due ') ?><?= h($cwFmt((string)$fu['due_at'])) ?> · <?= h(trim(($fu['assigned_first'] ?? '') . ' ' . ($fu['assigned_last'] ?? '')) ?: 'Unassigned') ?></span>
                    <?php if ($fu['notes']): ?><span><?= nl2br(h($fu['notes'])) ?></span><?php endif; ?>
                    <?php if ($fu['status'] === 'completed' && $fu['completed_at']): ?><span>Completed <?= h($cwFmt((string)$fu['completed_at'])) ?></span><?php endif; ?>
                    <?php if ($pending && $followUpCreatable): ?>
                    <details class="inline-details"><summary>Edit</summary>
                        <form method="post" action="<?= h($cwAction) ?>" class="form-stack"><?= $cwHidden('followup_update', ['follow_up_id' => (string)$fu['id']]) ?>
                            <label>Title<input type="text" name="title" maxlength="200" required value="<?= h($fu['title']) ?>"></label>
                            <div class="form-grid form-grid-compact">
                                <label>Type<select name="type"><?php foreach (FollowUpService::TYPES as $t): ?><option value="<?= h($t) ?>" <?= $fu['type'] === $t ? 'selected' : '' ?>><?= h(ucfirst($t)) ?></option><?php endforeach; ?></select></label>
                                <label>Due<input type="datetime-local" name="due_at" required value="<?= h((new DateTimeImmutable((string)$fu['due_at'], $cwTz))->format('Y-m-d\TH:i')) ?>"></label>
                            </div>
                            <?php if ($cwIsOwner && $assignableMembers): ?><label>Assigned to<select name="assigned_user_id"><?php foreach ($assignableMembers as $m): ?><option value="<?= (int)$m['id'] ?>" <?= (int)$fu['assigned_user_id'] === (int)$m['id'] ? 'selected' : '' ?>><?= h($m['first_name'] . ' ' . $m['last_name']) ?></option><?php endforeach; ?></select></label><?php endif; ?>
                            <label>Notes<textarea name="notes" rows="3" maxlength="5000"><?= h($fu['notes']) ?></textarea></label>
                            <button class="button" type="submit">Save changes</button>
                        </form>
                    </details>
                    <?php endif; ?>
                </div>
                <?php if ($pending): ?>
                <div class="action-group">
                    <form method="post" action="<?= h($cwAction) ?>" class="inline-form"><?= $cwHidden('followup_complete', ['follow_up_id' => (string)$fu['id']]) ?><button class="button small" type="submit">Complete</button></form>
                    <form method="post" action="<?= h($cwAction) ?>" class="inline-form" onsubmit="return confirm('Cancel this follow-up?');"><?= $cwHidden('followup_cancel', ['follow_up_id' => (string)$fu['id']]) ?><button class="button danger small" type="submit">Cancel</button></form>
                </div>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <?php if ($followUpCreatable): ?>
        <details class="inline-details" <?= ($oldInput['form'] ?? '') === 'followup_create' || !$followUpList ? 'open' : '' ?>><summary>Create a follow-up</summary>
            <form method="post" action="<?= h($cwAction) ?>" class="form-stack"><?= $cwHidden('followup_create') ?>
                <label>Title<input type="text" name="title" maxlength="200" required placeholder="e.g. Send proposal" value="<?= h($cwInput('followup_create', 'title')) ?>"></label>
                <div class="form-grid form-grid-compact">
                    <label>Type<select name="type"><?php foreach (FollowUpService::TYPES as $t): ?><option value="<?= h($t) ?>" <?= $cwInput('followup_create', 'type', 'email') === $t ? 'selected' : '' ?>><?= h(ucfirst($t)) ?></option><?php endforeach; ?></select></label>
                    <label>Due<input type="datetime-local" name="due_at" required value="<?= h($cwInput('followup_create', 'due_at', $cwNow->modify('+2 days')->setTime(9, 0)->format('Y-m-d\TH:i'))) ?>"></label>
                </div>
                <?php if ($cwIsOwner && $assignableMembers): ?><label>Assigned to<select name="assigned_user_id"><option value="">Engagement consultant (default)</option><?php foreach ($assignableMembers as $m): ?><option value="<?= (int)$m['id'] ?>"><?= h($m['first_name'] . ' ' . $m['last_name']) ?></option><?php endforeach; ?></select></label><?php endif; ?>
                <label>Notes (optional)<textarea name="notes" rows="3" maxlength="5000"><?= h($cwInput('followup_create', 'notes')) ?></textarea></label>
                <button class="button" type="submit">Create follow-up</button>
            </form>
        </details>
    <?php endif; ?>
</section>
<?php endif; ?>
