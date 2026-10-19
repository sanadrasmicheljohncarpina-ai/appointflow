<?php
/** Milestone 8 — final outcome card (converted / closed lost). Expects $engagement, $engagementId. */
if (!in_array($engagement['status'], ['completed', 'follow_up'], true)) {
    return;
}
$coAction = appUrl('public/consultation_action.php');
?>
<section class="content-card" id="outcome">
    <div class="section-head"><div><p class="eyebrow">OUTCOME</p><h2>Record the outcome</h2></div></div>
    <p class="muted">Use this when the engagement has reached its end. Any pending follow-ups are cancelled automatically.</p>
    <form method="post" action="<?= h($coAction) ?>" class="form-stack">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="engagement_id" value="<?= (int)$engagementId ?>">
        <label>Note (optional)<textarea name="outcome_note" rows="3" maxlength="5000" placeholder="Why did this convert, or why was it lost?"></textarea></label>
        <div class="action-group">
            <button class="button" type="submit" name="action" value="outcome_converted" onclick="return confirm('Mark this engagement as converted?');">Converted</button>
            <button class="button danger" type="submit" name="action" value="outcome_closed_lost" onclick="return confirm('Close this engagement as lost? This cannot be undone.');">Closed – lost</button>
        </div>
    </form>
</section>
