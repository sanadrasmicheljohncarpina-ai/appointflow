<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/database.php';
require_once __DIR__ . '/../app/security.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/permissions.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/services/EngagementService.php';
require_once __DIR__ . '/../app/services/TeamService.php';
require_once __DIR__ . '/../app/services/ServiceCatalogService.php';

$pdo = db();
$currentUser = requireLogin($pdo);
$workspaceId = (int)$currentUser['workspace_id'];
$isOwner = $currentUser['role'] === 'owner';
$userId = (int)$currentUser['id'];

$filters = [
    'status' => trim((string)($_GET['status'] ?? '')),
    'assigned_user_id' => trim((string)($_GET['assigned_user_id'] ?? '')),
    'service_id' => trim((string)($_GET['service_id'] ?? '')),
    'q' => trim((string)($_GET['q'] ?? '')),
];
$engagementService = new EngagementService($pdo);
$engagements = $engagementService->listForUser($workspaceId, $isOwner, $userId, $filters);
$members = $isOwner ? (new TeamService($pdo))->listMembers($workspaceId) : [];
$services = $isOwner ? (new ServiceCatalogService($pdo))->listServices($workspaceId) : [];

$pageTitle = $isOwner ? 'Engagements' : 'My Engagements';
require __DIR__ . '/../app/views/layouts/header.php';
?>
<section class="page-heading"><div><p class="eyebrow">ENGAGEMENTS</p><h1><?= h($pageTitle) ?></h1><p>Keep each client's inquiry, qualification, assignment, and future consultation connected in one record.</p></div><span class="role-pill"><?= count($engagements) ?> record<?= count($engagements) === 1 ? '' : 's' ?></span></section>
<section class="content-card">
<form method="get" class="form-grid compact-filter-form">
<label>Search<input name="q" value="<?= h($filters['q']) ?>" placeholder="Client, company, engagement"></label>
<label>Status<select name="status"><option value="">All statuses</option><?php foreach (['new','qualified','scheduled','completed','follow_up','converted','closed_lost'] as $status): ?><option value="<?= h($status) ?>" <?= $filters['status'] === $status ? 'selected' : '' ?>><?= h(ucwords(str_replace('_',' ',$status))) ?></option><?php endforeach; ?></select></label>
<?php if ($isOwner): ?><label>Assigned to<select name="assigned_user_id"><option value="">Anyone</option><?php foreach ($members as $member): ?><?php if ($member['role'] === 'consultant' && $member['status'] === 'active'): ?><option value="<?= (int)$member['id'] ?>" <?= $filters['assigned_user_id'] === (string)$member['id'] ? 'selected' : '' ?>><?= h($member['first_name'].' '.$member['last_name']) ?></option><?php endif; ?><?php endforeach; ?></select></label><label>Service<select name="service_id"><option value="">Any service</option><?php foreach ($services as $service): ?><option value="<?= (int)$service['id'] ?>" <?= $filters['service_id'] === (string)$service['id'] ? 'selected' : '' ?>><?= h($service['name']) ?></option><?php endforeach; ?></select></label><?php endif; ?><div class="form-actions"><a class="button secondary" href="<?= h(appUrl('public/engagements.php')) ?>">Clear</a><button class="button" type="submit">Filter</button></div>
</form>
</section>
<section class="content-card"><div class="table-wrap"><table><thead><tr><th>Client</th><th>Engagement</th><th>Service</th><th>Status</th><th>Assigned</th><th>Appointment</th><th>Next action</th></tr></thead><tbody>
<?php foreach ($engagements as $row): ?><tr><td><strong><?= h($row['first_name'].' '.$row['last_name']) ?></strong><div class="table-subtle"><?= h($row['company_name'] ?: $row['email']) ?></div></td><td><a href="<?= h(appUrl('public/engagement.php?id='.(int)$row['id'])) ?>">#<?= (int)$row['id'] ?> · <?= h($row['title']) ?></a></td><td><?= h($row['service_name'] ?: '—') ?></td><td><span class="status-badge status-<?= h($row['status']) ?>"><?= h(str_replace('_',' ',$row['status'])) ?></span></td><td><?= h(trim(($row['assigned_first'] ?? '').' '.($row['assigned_last'] ?? '')) ?: 'Unassigned') ?></td><td><?= $row['starts_at'] ? h((new DateTimeImmutable($row['starts_at'], workspaceTimezone((string)$currentUser['workspace_timezone'])))->format('M j, Y g:i A')) : '—' ?></td><td><?= h($row['next_action'] ?: '—') ?></td></tr><?php endforeach; ?>
<?php if (!$engagements): ?><tr><td colspan="7" class="table-empty">No engagements match the current filters.</td></tr><?php endif; ?></tbody></table></div></section>
<?php require __DIR__ . '/../app/views/layouts/footer.php'; ?>
