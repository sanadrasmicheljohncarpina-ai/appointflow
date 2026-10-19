<?php
/**
 * Meeting workspace: structured notes for one consultation (meeting_notes belongs to the appointment).
 * Expects: $meetingAppointment, $meetingNote (or null), $meetingCanRecord (bool), $tz, $wfAction, $wfFields (callable).
 */
$meetingWhen = (new DateTimeImmutable((string)$meetingAppointment['starts_at'], $tz))->format('l, F j, Y \a\t g:i A');
$noteValue = static fn(string $field): string => $meetingNote ? (string)($meetingNote[$field] ?? '') : '';
$noteFields = [
    ['summary', 'Summary', true, 'What was this consultation about, in a few sentences?'],
    ['problem', 'Problem', true, 'The core problem the client wants solved'],
    ['requirements', 'Requirements', true, 'What the client needs, constraints, budget, timeline'],
    ['decisions', 'Decisions', false, 'Anything agreed during the meeting'],
    ['concerns', 'Concerns', false, 'Risks, objections or open questions'],
    ['next_step', 'Next step', true, 'What happens next, and who does it'],
];
?>
<section class="content-card" id="meeting">
    <div class="section-head">
        <div><p class="eyebrow">MEETING</p><h2>Consultation notes</h2></div>
        <?php if ($meetingNote): ?><span class="status-badge status-completed">Notes saved</span><?php endif; ?>
    </div>
    <p class="muted">For the consultation on <?= h($meetingWhen) ?>.</p>

    <?php if (!$meetingCanRecord): ?>
        <div class="empty-state"><strong>Notes unlock when the consultation starts.</strong><p>You can document the meeting here without leaving this page.</p></div>
    <?php else: ?>
        <form method="post" action="<?= h($wfAction) ?>" class="form-stack">
            <?= $wfFields('notes_save') ?>
            <input type="hidden" name="appointment_id" value="<?= (int)$meetingAppointment['id'] ?>">
            <?php foreach ($noteFields as [$field, $label, $required, $hint]): ?>
                <label><span><?= h($label) ?> <span class="muted"><?= $required ? '(required)' : '(optional)' ?></span></span>
                    <textarea name="<?= h($field) ?>" rows="3" maxlength="5000" placeholder="<?= h($hint) ?>" <?= $required ? 'required' : '' ?>><?= h($noteValue($field)) ?></textarea>
                </label>
            <?php endforeach; ?>
            <div class="action-group">
                <button class="button" type="submit"><?= $meetingNote ? 'Update meeting notes' : 'Save meeting notes' ?></button>
                <?php if ($meetingNote): ?>
                    <span class="muted">Last saved <?= h((new DateTimeImmutable((string)$meetingNote['updated_at']))->format('M j, Y g:i A')) ?><?= trim((string)$meetingNote['author_first'] . (string)$meetingNote['author_last']) !== '' ? ' · started by ' . h(trim((string)$meetingNote['author_first'] . ' ' . (string)$meetingNote['author_last'])) : '' ?></span>
                <?php endif; ?>
            </div>
        </form>
    <?php endif; ?>
</section>
