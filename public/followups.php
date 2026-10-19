<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/database.php';
require_once __DIR__ . '/../app/security.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/services/TeamService.php';
require_once __DIR__ . '/../app/services/FollowUpService.php';
require_once __DIR__ . '/../app/services/OperationsService.php';

$pdo = db();
$currentUser = requireLogin($pdo);
$workspaceId = (int)$currentUser['workspace_id'];
$userId = (int)$currentUser['id'];
$isOwner = $currentUser['role'] === 'owner';
$timezone = workspaceTimezone((string)$currentUser['workspace_timezone']);
$scopeUserId = $isOwner ? null : $userId;

$filter = (string)($_GET['filter'] ?? 'all');
if (!in_array($filter, OperationsService::FOLLOW_UP_FILTERS, true)) {
    $filter = 'all';
}

// Owners can narrow to one team member; consultants are already limited to their own engagements.
$members = $isOwner ? (new TeamService($pdo))->listMembers($workspaceId) : [];
$filterUserId = null;
if ($isOwner) {
    $requested = (int)($_GET['assignee'] ?? 0);
    foreach ($members as $member) {
        if ((int)$member['id'] === $requested) {
            $filterUserId = $requested;
        }
    }
}

$ops = new OperationsService($pdo);
$counts = $ops->getFollowUpCounts($workspaceId, $scopeUserId, $timezone, $filterUserId);
$followUps = $ops->getFollowUps($workspaceId, $scopeUserId, $timezone, $filter, $filterUserId);

$tabs = ['all' => 'All', 'today' => 'Today', 'upcoming' => 'Upcoming', 'overdue' => 'Overdue', 'completed' => 'Completed'];
$tabUrl = static function (string $tab) use ($filterUserId): string {
    $params = ['filter' => $tab];
    if ($filterUserId !== null) {
        $params['assignee'] = (string)$filterUserId;
    }
    return appUrl('public/followups.php') . '?' . http_build_query($params);
};
$emptyText = [
    'all' => 'No follow-ups yet. They are created from an engagement once its consultation is completed.',
    'today' => 'Nothing is due today.',
    'upcoming' => 'No upcoming follow-ups.',
    'overdue' => 'No overdue follow-ups.',
    'completed' => 'No completed follow-ups yet.',
];

$pageTitle = 'Follow-ups';
require __DIR__ . '/../app/views/layouts/header.php';
?>
<section class="page-heading">
    <div>
        <p class="eyebrow">FOLLOW-UPS</p>
        <h1><?= h($isOwner ? 'Follow-up center' : 'My follow-ups') ?></h1>
        <p>Every follow-up across your engagements. Open one to complete or edit it on its engagement page.</p>
    </div>
    <span class="role-pill"><?= $counts['overdue'] ?> overdue</span>
</section>

<section class="content-card">
    <div class="fu-tabs" role="tablist">
        <?php foreach ($tabs as $key => $label): ?>
            <a class="fu-tab<?= $filter === $key ? ' active' : '' ?><?= $key === 'overdue' && $counts['overdue'] > 0 ? ' fu-tab-alert' : '' ?>" href="<?= h($tabUrl($key)) ?>" role="tab" aria-selected="<?= $filter === $key ? 'true' : 'false' ?>">
                <?= h($label) ?> <span><?= $counts[$key] ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if ($isOwner): ?>
        <form method="get" class="cal-filters">
            <input type="hidden" name="filter" value="<?= h($filter) ?>">
            <label>Assigned to
                <select name="assignee">
                    <option value="">Everyone</option>
                    <?php foreach ($members as $member): ?>
                        <option value="<?= (int)$member['id'] ?>" <?= $filterUserId === (int)$member['id'] ? 'selected' : '' ?>><?= h(personName($member['first_name'], $member['last_name'])) ?><?= (int)$member['id'] === $userId ? ' (me)' : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="button secondary small" type="submit">Apply</button>
        </form>
    <?php endif; ?>

    <?php if (!$followUps): ?>
        <div class="empty-state"><strong><?= h($emptyText[$filter]) ?></strong></div>
    <?php else: ?>
        <div class="table-wrap"><table>
            <thead><tr><th>Follow-up</th><th>Client</th><th>Due</th><th>Status</th><th>Assigned</th></tr></thead>
            <tbody>
            <?php foreach ($followUps as $fu): ?>
                <tr>
                    <td>
                        <a href="<?= h(engagementUrl((int)$fu['engagement_id'], 'follow-up')) ?>"><strong><?= h($fu['title']) ?></strong></a>
                        <div class="table-subtle"><?= h(FollowUpService::TYPE_LABELS[$fu['type']] ?? ucfirst((string)$fu['type'])) ?> · #<?= (int)$fu['engagement_id'] ?> <?= h($fu['engagement_title']) ?></div>
                    </td>
                    <td><?= h(personName($fu['first_name'], $fu['last_name'])) ?></td>
                    <td><?= h(formatLocalDateTime($fu['due_at'], $timezone, 'M j, Y')) ?></td>
                    <td>
                        <?php if ($fu['is_overdue']): ?>
                            <span class="status-badge status-closed_lost">Overdue</span>
                        <?php elseif ($fu['status'] === 'completed'): ?>
                            <span class="status-badge status-completed">Completed<?= $fu['completed_at'] ? ' · ' . h(formatLocalDateTime($fu['completed_at'], $timezone, 'M j')) : '' ?></span>
                        <?php elseif ($fu['status'] === 'cancelled'): ?>
                            <span class="status-badge status-inactive">Cancelled</span>
                        <?php else: ?>
                            <span class="status-badge status-follow_up">Pending</span>
                        <?php endif; ?>
                    </td>
                    <td><?= h(personName($fu['assigned_first'], $fu['assigned_last'], 'Unassigned')) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../app/views/layouts/footer.php'; ?>
