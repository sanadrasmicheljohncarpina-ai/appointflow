<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/database.php';
require_once __DIR__ . '/../app/security.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/permissions.php';
require_once __DIR__ . '/../app/helpers.php';

$pdo = db();
$currentUser = requireLogin($pdo);
$workspaceId = (int)$currentUser['workspace_id'];
$isOwner = $currentUser['role'] === 'owner';
$params = [$workspaceId];
$where = '';
$q = trim((string)($_GET['q'] ?? ''));
if (!$isOwner) {
    $where = ' AND EXISTS (SELECT 1 FROM engagements ex WHERE ex.client_id = c.id AND ex.workspace_id = c.workspace_id AND ex.assigned_user_id = ?)';
    $params[] = (int)$currentUser['id'];
}
if ($q !== '') {
    $where .= ' AND (CONCAT(c.first_name, \' \', c.last_name) LIKE ? OR c.company_name LIKE ? OR c.email LIKE ?)';
    $like = '%' . $q . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
}
$sql =
    'SELECT c.id, c.first_name, c.last_name, c.email, c.phone, c.company_name,
            COUNT(DISTINCT e.id) AS engagement_count,
            MAX(e.updated_at) AS last_activity
     FROM clients c
     LEFT JOIN engagements e ON e.client_id = c.id AND e.workspace_id = c.workspace_id
     WHERE c.workspace_id = ?' . $where . '
     GROUP BY c.id, c.first_name, c.last_name, c.email, c.phone, c.company_name
     ORDER BY last_activity DESC, c.last_name ASC, c.first_name ASC';
$stmt = $pdo->prepare($sql); $stmt->execute($params); $clients = $stmt->fetchAll();
$pageTitle = 'Clients';
require __DIR__ . '/../app/views/layouts/header.php';
?>
<section class="page-heading"><div><p class="eyebrow">CLIENTS</p><h1>Clients</h1><p>Browse the people and organizations connected to your consultation engagements.</p></div><span class="role-pill"><?= count($clients) ?> client<?= count($clients) === 1 ? '' : 's' ?></span></section>
<section class="content-card"><form method="get" class="form-grid compact-filter-form"><label>Search<input name="q" value="<?= h($q) ?>" placeholder="Name, company, email"></label><div class="form-actions"><a class="button secondary" href="<?= h(appUrl('public/clients.php')) ?>">Clear</a><button class="button" type="submit">Search</button></div></form></section>
<section class="content-card"><div class="table-wrap"><table><thead><tr><th>Name</th><th>Company</th><th>Email</th><th>Phone</th><th>Engagements</th><th>Last activity</th></tr></thead><tbody><?php foreach ($clients as $client): ?><tr><td><a href="<?= h(appUrl('public/client.php?id='.(int)$client['id'])) ?>"><strong><?= h($client['first_name'].' '.$client['last_name']) ?></strong></a></td><td><?= h($client['company_name'] ?: '—') ?></td><td><?= h($client['email']) ?></td><td><?= h($client['phone'] ?: '—') ?></td><td><?= (int)$client['engagement_count'] ?></td><td><?= h($client['last_activity'] ?: '—') ?></td></tr><?php endforeach; ?><?php if (!$clients): ?><tr><td colspan="6" class="table-empty">No clients match the current search.</td></tr><?php endif; ?></tbody></table></div></section>
<?php require __DIR__ . '/../app/views/layouts/footer.php'; ?>
