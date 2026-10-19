<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/database.php';
require_once __DIR__ . '/../app/security.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/services/WorkspaceService.php';
require_once __DIR__ . '/../app/services/LinkRequestService.php';
require_once __DIR__ . '/../app/services/FollowUpService.php';
require_once __DIR__ . '/../app/services/OperationsService.php';

$pdo = db();
$currentUser = requireLogin($pdo);
$workspaceId = (int)$currentUser['workspace_id'];
$userId = (int)$currentUser['id'];
$isOwner = $currentUser['role'] === 'owner';
$timezone = workspaceTimezone((string)$currentUser['workspace_timezone']);
$scopeUserId = $isOwner ? null : $userId;

$bookingSlug = '';
if ($isOwner) {
    $bookingSlug = (string)((new WorkspaceService($pdo))->get($workspaceId)['booking_slug'] ?? '');
}
$linkRequests = (new LinkRequestService($pdo))->pendingForUser($workspaceId, $userId, $isOwner);

$ops = new OperationsService($pdo);
$summary = $ops->getDashboardSummary($workspaceId, $scopeUserId, $timezone);
$todayAppointments = $ops->getTodayAppointments($workspaceId, $scopeUserId, $timezone);
$upcomingAppointments = $ops->getUpcomingAppointments($workspaceId, $scopeUserId, $timezone, 6);
$overdueFollowUps = $ops->getOverdueFollowUps($workspaceId, $scopeUserId, $timezone, 6);
$pendingPreparation = $ops->getPendingPreparation($workspaceId, $scopeUserId, $timezone, 6);
$newInquiries = $ops->getNewInquiries($workspaceId, $scopeUserId, 6);
$recent = $ops->getRecentEngagements($workspaceId, $scopeUserId, 8);
$needsAttention = $overdueFollowUps || $pendingPreparation || $newInquiries;
$todayLabel = (new DateTimeImmutable('now', $timezone))->format('l, F j');

$pageTitle = 'Dashboard';
require __DIR__ . '/../app/views/layouts/header.php';
?>
<section class="page-heading">
    <div>
        <p class="eyebrow">WORKSPACE · <?= h(strtoupper($todayLabel)) ?></p>
        <h1><?= h($currentUser['workspace_name']) ?></h1>
        <p>What needs your attention, and what is coming up.</p>
    </div>
    <span class="role-pill"><?= h(ucfirst($currentUser['role'])) ?></span>
</section>

<?php require __DIR__ . '/../app/views/components/link_requests_card.php'; ?>
<?php if ($isOwner) { require __DIR__ . '/../app/views/components/booking_link_card.php'; } ?>

<section class="metric-grid">
    <a class="metric-card metric-link" href="<?= h(appUrl('public/engagements.php?status=new')) ?>"><span>New inquiries</span><strong><?= $summary['new_inquiries'] ?></strong></a>
    <a class="metric-card metric-link" href="<?= h(appUrl('public/calendar.php?view=day')) ?>"><span>Today's consultations</span><strong><?= $summary['today_consultations'] ?></strong></a>
    <a class="metric-card metric-link" href="<?= h(appUrl('public/calendar.php')) ?>"><span>Upcoming consultations</span><strong><?= $summary['upcoming_consultations'] ?></strong></a>
    <div class="metric-card"><span>Open preparation tasks</span><strong><?= $summary['open_preparation_tasks'] ?></strong></div>
    <a class="metric-card metric-link<?= $summary['overdue_followups'] > 0 ? ' metric-alert' : '' ?>" href="<?= h(appUrl('public/followups.php?filter=overdue')) ?>"><span>Overdue follow-ups</span><strong><?= $summary['overdue_followups'] ?></strong><small><?= $summary['pending_followups'] ?> pending<?= $summary['due_today_followups'] > 0 ? ' · ' . $summary['due_today_followups'] . ' due today' : '' ?></small></a>
</section>

<div class="ops-grid">
    <section class="content-card">
        <div class="section-head">
            <div><p class="eyebrow">TODAY</p><h2>Consultations</h2></div>
            <a class="button secondary small" href="<?= h(appUrl('public/calendar.php?view=day')) ?>">Open day view</a>
        </div>
        <?php if (!$todayAppointments): ?>
            <div class="empty-state"><strong>No consultations scheduled for today.</strong></div>
        <?php else: ?>
            <div class="ops-list">
            <?php foreach ($todayAppointments as $appt): ?>
                <a class="ops-item" href="<?= h(engagementUrl((int)$appt['engagement_id'], 'appointment')) ?>">
                    <span class="ops-time"><?= h(formatLocalDateTime($appt['starts_at'], $timezone, 'g:i A')) ?></span>
                    <span class="ops-main"><strong><?= h(personName($appt['client_first'], $appt['client_last'])) ?></strong>
                        <small><?= h($appt['service_name'] ?: $appt['engagement_title']) ?><?= $isOwner ? ' · ' . h(personName($appt['consultant_first'], $appt['consultant_last'], 'Unassigned')) : '' ?></small></span>
                    <?php if ((new DateTimeImmutable((string)$appt['starts_at'], $timezone)) <= new DateTimeImmutable('now', $timezone)): ?>
                        <span class="status-badge status-follow_up">Record outcome</span>
                    <?php else: ?>
                        <span class="status-badge status-scheduled">Scheduled</span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="content-card">
        <div class="section-head">
            <div><p class="eyebrow">UPCOMING</p><h2>Next consultations</h2></div>
            <a class="button secondary small" href="<?= h(appUrl('public/calendar.php')) ?>">Open calendar</a>
        </div>
        <?php if (!$upcomingAppointments): ?>
            <div class="empty-state"><strong>Nothing scheduled after today.</strong></div>
        <?php else: ?>
            <div class="ops-list">
            <?php foreach ($upcomingAppointments as $appt): ?>
                <a class="ops-item" href="<?= h(engagementUrl((int)$appt['engagement_id'], 'appointment')) ?>">
                    <span class="ops-time"><?= h(formatLocalDateTime($appt['starts_at'], $timezone, 'M j')) ?><br><?= h(formatLocalDateTime($appt['starts_at'], $timezone, 'g:i A')) ?></span>
                    <span class="ops-main"><strong><?= h(personName($appt['client_first'], $appt['client_last'])) ?></strong>
                        <small><?= h($appt['service_name'] ?: $appt['engagement_title']) ?><?= $isOwner ? ' · ' . h(personName($appt['consultant_first'], $appt['consultant_last'], 'Unassigned')) : '' ?></small></span>
                </a>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>

<section class="content-card ops-attention">
    <div class="section-head">
        <div><p class="eyebrow">NEEDS ATTENTION</p><h2>Action items</h2></div>
        <a class="button secondary small" href="<?= h(appUrl('public/followups.php')) ?>">Open follow-up center</a>
    </div>
    <?php if (!$needsAttention): ?>
        <div class="empty-state"><strong>Nothing needs attention right now.</strong><p>No overdue follow-ups, unfinished preparation or unreviewed inquiries.</p></div>
    <?php else: ?>
        <div class="ops-attention-grid">
            <?php if ($overdueFollowUps): ?>
            <div>
                <h3>Overdue follow-ups</h3>
                <div class="ops-list">
                <?php foreach ($overdueFollowUps as $fu): ?>
                    <a class="ops-item" href="<?= h(engagementUrl((int)$fu['engagement_id'], 'follow-up')) ?>">
                        <span class="ops-main"><strong><?= h($fu['title']) ?></strong>
                            <small><?= h(personName($fu['first_name'], $fu['last_name'])) ?> · due <?= h(formatLocalDateTime($fu['due_at'], $timezone, 'M j')) ?></small></span>
                        <span class="status-badge status-closed_lost">Overdue</span>
                    </a>
                <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            <?php if ($pendingPreparation): ?>
            <div>
                <h3>Preparation to finish</h3>
                <div class="ops-list">
                <?php foreach ($pendingPreparation as $prep): ?>
                    <a class="ops-item" href="<?= h(engagementUrl((int)$prep['engagement_id'], 'preparation')) ?>">
                        <span class="ops-main"><strong><?= h(personName($prep['first_name'], $prep['last_name'])) ?></strong>
                            <small>Consultation <?= h(formatLocalDateTime($prep['starts_at'], $timezone, 'M j, g:i A')) ?></small></span>
                        <span class="status-badge status-new"><?= (int)$prep['tasks_total'] === 0 ? 'Not started' : (int)$prep['tasks_done'] . ' / ' . (int)$prep['tasks_total'] . ' done' ?></span>
                    </a>
                <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            <?php if ($newInquiries): ?>
            <div>
                <h3>New inquiries</h3>
                <div class="ops-list">
                <?php foreach ($newInquiries as $inq): ?>
                    <a class="ops-item" href="<?= h(engagementUrl((int)$inq['engagement_id'])) ?>">
                        <span class="ops-main"><strong><?= h(personName($inq['first_name'], $inq['last_name'])) ?></strong>
                            <small><?= h($inq['title']) ?> · received <?= h(formatLocalDateTime($inq['created_at'], $timezone, 'M j')) ?></small></span>
                        <span class="status-badge status-new">Unreviewed</span>
                    </a>
                <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</section>

<section class="content-card">
    <div class="section-head">
        <div><p class="eyebrow">RECENT</p><h2>Engagements</h2></div>
        <span class="muted">Latest workspace activity</span>
    </div>
    <?php if (!$recent): ?>
        <div class="empty-state"><strong>No engagements yet.</strong><p>Public inquiries now flow into the engagement pipeline.</p></div>
    <?php else: ?>
        <div class="table-wrap"><table>
            <thead><tr><th>Client</th><th>Engagement</th><th>Status</th><th>Assigned</th><th>Appointment</th><th>Next action</th></tr></thead>
            <tbody>
            <?php foreach ($recent as $row): ?>
                <tr>
                    <td><?= h(personName($row['first_name'], $row['last_name'])) ?></td>
                    <td><a href="<?= h(engagementUrl((int)$row['id'])) ?>">#<?= (int)$row['id'] ?> · <?= h($row['title']) ?></a></td>
                    <td><span class="status-badge status-<?= h($row['status']) ?>"><?= h(str_replace('_', ' ', $row['status'])) ?></span></td>
                    <td><?= h(personName($row['assigned_first'], $row['assigned_last'], 'Unassigned')) ?></td>
                    <td><?= h(formatLocalDateTime($row['starts_at'], $timezone)) ?></td>
                    <td><?= h($row['next_action'] ?: '—') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../app/views/layouts/footer.php'; ?>
