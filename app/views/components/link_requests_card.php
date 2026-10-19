<?php
/**
 * Dashboard card: clients who asked for their private appointment link and are waiting for it.
 * Expects $linkRequests (array from LinkRequestService::pendingForUser).
 */
$linkRequests = $linkRequests ?? [];
if (!$linkRequests) {
    return;
}
?>
<section class="content-card link-requests-card">
    <div class="section-head">
        <div>
            <p class="eyebrow">ACTION NEEDED</p>
            <h2>Clients waiting for their link</h2>
        </div>
        <span class="role-pill"><?= count($linkRequests) ?></span>
    </div>
    <div class="info-list">
        <?php foreach ($linkRequests as $request): ?>
            <div class="link-request-row">
                <div>
                    <strong><?= h(trim($request['first_name'] . ' ' . $request['last_name'])) ?> · <?= h($request['title']) ?></strong>
                    <span><?= h($request['email']) ?> · requested <?= h((new DateTimeImmutable((string)$request['last_requested_at']))->format('M j, Y g:i A')) ?><?= (int)$request['request_count'] > 1 ? ' · asked ' . (int)$request['request_count'] . ' times' : '' ?></span>
                </div>
                <a class="button small" href="<?= h(appUrl('public/engagement.php?id=' . (int)$request['engagement_id'] . '#client-access')) ?>">Send link</a>
            </div>
        <?php endforeach; ?>
    </div>
</section>
