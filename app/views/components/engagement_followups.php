<?php
/**
 * Follow-up workspace and the engagement outcome (convert / close as lost).
 * Expects: $engagement, $followUps, $canAddFollowUp (bool), $nowLocal, $tz, $wfAction, $wfFields (callable).
 */
$typeLabels = FollowUpService::TYPE_LABELS;
$status = (string)$engagement['status'];
$defaultDue = (new DateTimeImmutable('+3 days', $tz))->format('Y-m-d');
$pendingCount = 0;
foreach ($followUps as $row) {
    if ($row['status'] === 'pending') {
        $pendingCount++;
    }
}
$followUpForm = static function (string $action, array $values, string $buttonLabel) use ($typeLabels, $wfAction, $wfFields, $defaultDue, $engagement): string {
    ob_start();
    ?>
    <form method="post" action="<?= h($wfAction) ?>" class="form-stack follow-up-form">
        <?= $wfFields($action) ?>
        <?php if (isset($values['id'])): ?><input type="hidden" name="follow_up_id" value="<?= (int)$values['id'] ?>"><?php endif; ?>
        <div class="form-grid">
            <label>What needs to happen?
                <input type="text" name="title" maxlength="200" required value="<?= h($values['title'] ?? '') ?>" placeholder="e.g. Prepare proposal">
            </label>
            <label>Type
                <select name="type">
                    <?php foreach ($typeLabels as $value => $label): ?>
                        <option value="<?= h($value) ?>" <?= ($values['type'] ?? 'proposal') === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Due date
                <input type="date" name="due_date" required value="<?= h($values['due_date'] ?? $defaultDue) ?>">
            </label>
        </div>
        <label><span>Notes <span class="muted">(optional)</span></span>
            <textarea name="notes" rows="2" maxlength="5000"><?= h($values['notes'] ?? '') ?></textarea>
        </label>
        <div><button class="button" type="submit"><?= h($buttonLabel) ?></button></div>
    </form>
    <?php
    return (string)ob_get_clean();
};
?>
<section class="content-card" id="follow-up">
    <div class="section-head">
        <div><p class="eyebrow">FOLLOW-UP</p><h2>What happens next</h2></div>
        <span class="status-badge status-<?= h($status) ?>"><?= h(str_replace('_', ' ', $status)) ?></span>
    </div>

    <?php if ($canAddFollowUp): ?>
        <details class="new-follow-up" <?= $followUps ? '' : 'open' ?>>
            <summary class="button secondary">+ New follow-up</summary>
            <?= $followUpForm('followup_add', [], 'Create follow-up') ?>
        </details>
    <?php endif; ?>

    <?php if (!$followUps): ?>
        <div class="empty-state">
            <strong>No follow-ups yet.</strong>
            <p><?= $canAddFollowUp ? 'Create one for the next step, or convert the engagement directly below.' : 'Follow-ups can be added once the consultation has been completed.' ?></p>
        </div>
    <?php else: ?>
        <ul class="follow-up-list">
            <?php foreach ($followUps as $item): ?>
                <?php
                $isPending = $item['status'] === 'pending';
                $isOverdue = $isPending && (string)$item['due_at'] < $nowLocal;
                $assignee = trim((string)$item['assigned_first'] . ' ' . (string)$item['assigned_last']);
                ?>
                <li class="follow-up-item status-row-<?= h($item['status']) ?>">
                    <div class="follow-up-main">
                        <strong><?= h($item['title']) ?></strong>
                        <span class="muted">
                            <?= h($typeLabels[$item['type']] ?? ucfirst((string)$item['type'])) ?>
                            · Assigned: <?= h($assignee !== '' ? $assignee : 'Unassigned') ?>
                            · Due <?= h((new DateTimeImmutable((string)$item['due_at'], $tz))->format('M j, Y')) ?>
                        </span>
                        <?php if ($item['notes']): ?><span class="follow-up-notes"><?= nl2br(h($item['notes'])) ?></span><?php endif; ?>
                    </div>
                    <div class="follow-up-side">
                        <?php if ($isPending): ?>
                            <span class="status-badge <?= $isOverdue ? 'status-closed_lost' : 'status-follow_up' ?>"><?= $isOverdue ? 'Overdue' : 'Pending' ?></span>
                        <?php elseif ($item['status'] === 'completed'): ?>
                            <span class="status-badge status-completed">Completed</span>
                        <?php else: ?>
                            <span class="status-badge status-new">Cancelled</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($isPending): ?>
                        <div class="action-group follow-up-actions">
                            <form method="post" action="<?= h($wfAction) ?>" class="inline-form">
                                <?= $wfFields('followup_complete') ?>
                                <input type="hidden" name="follow_up_id" value="<?= (int)$item['id'] ?>">
                                <button class="button small" type="submit">Complete</button>
                            </form>
                            <form method="post" action="<?= h($wfAction) ?>" class="inline-form" onsubmit="return confirm('Cancel this follow-up?')">
                                <?= $wfFields('followup_cancel') ?>
                                <input type="hidden" name="follow_up_id" value="<?= (int)$item['id'] ?>">
                                <button class="button secondary small" type="submit">Cancel</button>
                            </form>
                        </div>
                        <details class="edit-follow-up">
                            <summary>Edit</summary>
                            <?= $followUpForm('followup_update', [
                                'id' => $item['id'],
                                'title' => (string)$item['title'],
                                'type' => (string)$item['type'],
                                'due_date' => substr((string)$item['due_at'], 0, 10),
                                'notes' => (string)($item['notes'] ?? ''),
                            ], 'Save changes') ?>
                        </details>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ($canAddFollowUp): ?>
        <hr>
        <div class="section-head"><div><p class="eyebrow">OUTCOME</p><h2>Close out this engagement</h2></div></div>
        <p class="muted">
            <?= $pendingCount > 0 ? 'You have ' . (int)$pendingCount . ' open follow-up' . ($pendingCount === 1 ? '' : 's') . '. They stay on the list if you close the engagement now.' : 'Everything is done. Record the result of this engagement.' ?>
        </p>
        <div class="action-group">
            <form method="post" action="<?= h($wfAction) ?>" class="inline-form" onsubmit="return confirm('Convert this engagement? The client became a customer.')">
                <?= $wfFields('engagement_convert') ?>
                <button class="button" type="submit">Convert engagement</button>
            </form>
            <form method="post" action="<?= h($wfAction) ?>" class="inline-form" onsubmit="return confirm('Close this engagement as lost? This cannot be undone.')">
                <?= $wfFields('engagement_close_lost') ?>
                <button class="button danger" type="submit">Close as lost</button>
            </form>
        </div>
    <?php elseif ($status === 'converted'): ?>
        <div class="success-box">This engagement was converted.</div>
    <?php elseif ($status === 'closed_lost'): ?>
        <div class="empty-state"><strong>This engagement was closed as lost.</strong></div>
    <?php endif; ?>
</section>
