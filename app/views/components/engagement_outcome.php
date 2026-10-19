<?php
/**
 * Outcome controls for the engagement's current scheduled consultation.
 * Expects: $scheduledAppointment, $appointmentStarted (bool), $tz, $wfAction, $wfFields (callable).
 * Completing and no-show unlock once the start time has passed; cancelling is always possible.
 */
$outcomeStart = (new DateTimeImmutable((string)$scheduledAppointment['starts_at'], $tz))->format('l, F j \a\t g:i A');
?>
<hr>
<div class="section-head"><div><p class="eyebrow">OUTCOME</p><h2>After the consultation</h2></div></div>
<?php if ($appointmentStarted): ?>
    <p class="muted">The consultation time has passed. Record what happened. Save your meeting notes first if you have not yet.</p>
<?php else: ?>
    <p class="muted">Completing the consultation or marking a no-show unlocks once it starts (<?= h($outcomeStart) ?>). You can still cancel it.</p>
<?php endif; ?>
<div class="action-group outcome-actions">
    <form method="post" action="<?= h($wfAction) ?>" class="inline-form" onsubmit="return confirm('Mark this consultation as completed?')">
        <?= $wfFields('appt_complete') ?>
        <input type="hidden" name="appointment_id" value="<?= (int)$scheduledAppointment['id'] ?>">
        <button class="button" type="submit" <?= $appointmentStarted ? '' : 'disabled' ?>>Complete consultation</button>
    </form>
    <form method="post" action="<?= h($wfAction) ?>" class="inline-form" onsubmit="return confirm('Mark this client as a no-show? The engagement returns to qualified so you can rebook.')">
        <?= $wfFields('appt_no_show') ?>
        <input type="hidden" name="appointment_id" value="<?= (int)$scheduledAppointment['id'] ?>">
        <button class="button secondary" type="submit" <?= $appointmentStarted ? '' : 'disabled' ?>>Mark no-show</button>
    </form>
</div>
<details class="cancel-details">
    <summary>Cancel this consultation</summary>
    <form method="post" action="<?= h($wfAction) ?>" class="form-stack" onsubmit="return confirm('Cancel this consultation and notify the client?')">
        <?= $wfFields('appt_cancel') ?>
        <input type="hidden" name="appointment_id" value="<?= (int)$scheduledAppointment['id'] ?>">
        <label>Reason (optional)<textarea name="reason" rows="2" maxlength="500" placeholder="Kept in the engagement history (not included in the client email)"></textarea></label>
        <div><button class="button danger" type="submit">Cancel consultation</button></div>
    </form>
</details>
