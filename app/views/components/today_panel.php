<?php
/**
 * Milestone 9 — "Today" operational panel (dashboard).
 * Expects: $summary, $todayAppointments, $todayFollowUps, $now, $tz (from OperationsService).
 * Shows what matters right now: today's consultations with preparation progress, and follow-ups
 * due today. All figures come from OperationsService, so they match the rest of the dashboard.
 */
$tpPrepProgress = static function (array $a): string {
    return (int)$a['prep_total'] > 0
        ? 'Preparation: ' . (int)$a['prep_done'] . '/' . (int)$a['prep_total'] . ' complete'
        : 'Preparation: no tasks yet';
};
?>
<section class="content-card today-panel">
    <div class="section-head">
        <div><p class="eyebrow">TODAY</p><h2><?= h($now->format('l, F j')) ?></h2></div>
        <a class="button secondary small" href="<?= h(appUrl('public/calendar.php?view=day')) ?>">Day view</a>
    </div>
    <div class="today-summary">
        <a href="<?= h(appUrl('public/calendar.php?view=day')) ?>"><strong><?= (int)$summary['today_consultations'] ?></strong> consultation<?= $summary['today_consultations'] === 1 ? '' : 's' ?></a>
        <span><strong><?= (int)$summary['today_prep_open'] ?></strong> preparation task<?= $summary['today_prep_open'] === 1 ? '' : 's' ?> open</span>
        <a href="<?= h(appUrl('public/followups.php?filter=overdue')) ?>" class="<?= $summary['overdue_followups'] > 0 ? 'is-alert' : '' ?>"><strong><?= (int)$summary['overdue_followups'] ?></strong> overdue follow-up<?= $summary['overdue_followups'] === 1 ? '' : 's' ?></a>
        <a href="<?= h(appUrl('public/followups.php?filter=today')) ?>"><strong><?= (int)$summary['followups_due_today'] ?></strong> follow-up<?= $summary['followups_due_today'] === 1 ? '' : 's' ?> due today</a>
        <a href="<?= h(appUrl('public/engagements.php?status=new')) ?>"><strong><?= (int)$summary['new_inquiries'] ?></strong> new inquir<?= $summary['new_inquiries'] === 1 ? 'y' : 'ies' ?></a>
    </div>

    <?php if (!$todayAppointments): ?>
        <p class="muted" style="margin:18px 0 0">No consultations scheduled today.</p>
    <?php else: ?>
        <div class="today-list">
        <?php foreach ($todayAppointments as $a):
            $start = new DateTimeImmutable((string)$a['starts_at'], $tz);
            ?>
            <a class="ops-row" href="<?= h(appUrl('public/engagement.php?id=' . (int)$a['engagement_id'])) ?>">
                <span class="ops-time"><?= h($start->format('g:i A')) ?></span>
                <span class="ops-main">
                    <strong><?= h(trim($a['first_name'] . ' ' . $a['last_name'])) ?></strong>
                    <span><?= h($a['service_name'] ?: $a['engagement_title']) ?><?= $isOwner ? ' · ' . h(trim(($a['consultant_first'] ?? '') . ' ' . ($a['consultant_last'] ?? ''))) : '' ?></span>
                    <span><?= h($tpPrepProgress($a)) ?></span>
                </span>
                <span class="status-badge status-<?= h($a['status']) ?>"><?= h(str_replace('_', ' ', $a['status'])) ?></span>
            </a>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($todayFollowUps): ?>
        <h3 class="day-heading">Follow-up</h3>
        <?php foreach ($todayFollowUps as $f): ?>
            <a class="ops-row" href="<?= h(appUrl('public/engagement.php?id=' . (int)$f['engagement_id'])) ?>#followups">
                <span class="ops-time"><?= h((new DateTimeImmutable((string)$f['due_at'], $tz))->format('g:i A')) ?></span>
                <span class="ops-main"><strong><?= h($f['title']) ?> — <?= h(trim($f['first_name'] . ' ' . $f['last_name'])) ?></strong><span>Due today</span></span>
            </a>
        <?php endforeach; ?>
    <?php endif; ?>
</section>
