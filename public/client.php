<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/database.php';
require_once __DIR__ . '/../app/security.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/permissions.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/validation.php';

$pdo = db();
$currentUser = requireLogin($pdo);
$clientId = validatePositiveInt($_GET['id'] ?? null, 'Client');
$workspaceId = (int)$currentUser['workspace_id'];
$stmt = $pdo->prepare('SELECT * FROM clients WHERE id=? AND workspace_id=? LIMIT 1');
$stmt->execute([$clientId, $workspaceId]);
$client = $stmt->fetch();
if (!$client) { http_response_code(404); exit('Client not found.'); }
$sql = 'SELECT e.id, e.title, e.status, e.updated_at, s.name AS service_name, u.first_name AS assigned_first, u.last_name AS assigned_last, a.starts_at
        FROM engagements e
        LEFT JOIN services s ON s.id=e.service_id
        LEFT JOIN users u ON u.id=e.assigned_user_id
        LEFT JOIN appointments a ON a.engagement_id=e.id AND a.status=\'scheduled\'
        WHERE e.workspace_id=? AND e.client_id=?';
$params = [$workspaceId, $clientId];
if ($currentUser['role'] === 'consultant') { $sql .= ' AND e.assigned_user_id=?'; $params[] = (int)$currentUser['id']; }
$sql .= ' ORDER BY e.updated_at DESC, e.id DESC';
$stmt = $pdo->prepare($sql); $stmt->execute($params); $engagements = $stmt->fetchAll();
$pageTitle = 'Client · '.$client['first_name'].' '.$client['last_name'];
require __DIR__ . '/../app/views/layouts/header.php';
?>
<section class="page-heading"><div><p class="eyebrow">CLIENT</p><h1><?= h($client['first_name'].' '.$client['last_name']) ?></h1><p><?= h($client['company_name'] ?: 'Individual client') ?></p></div></section>
<section class="two-column"><section class="content-card"><div class="section-head"><div><p class="eyebrow">CONTACT</p><h2>Client information</h2></div></div><div class="info-list"><div><strong>Email</strong><span><?= h($client['email']) ?></span></div><div><strong>Phone</strong><span><?= h($client['phone'] ?: '—') ?></span></div><div><strong>Company</strong><span><?= h($client['company_name'] ?: '—') ?></span></div><div><strong>Internal notes</strong><span><?= nl2br(h($client['notes'] ?: '—')) ?></span></div></div></section><section class="content-card"><div class="section-head"><div><p class="eyebrow">HISTORY</p><h2>Engagements</h2></div></div><?php if (!$engagements): ?><div class="empty-state"><strong>No engagements yet.</strong></div><?php else: ?><div class="info-list"><?php foreach ($engagements as $engagement): ?><div><strong><a href="<?= h(appUrl('public/engagement.php?id='.(int)$engagement['id'])) ?>">#<?= (int)$engagement['id'] ?> · <?= h($engagement['title']) ?></a></strong><span><?= h($engagement['service_name'] ?: '—') ?> · <?= h(str_replace('_',' ',$engagement['status'])) ?> · <?= h(trim(($engagement['assigned_first'] ?? '').' '.($engagement['assigned_last'] ?? '')) ?: 'Unassigned') ?></span></div><?php endforeach; ?></div><?php endif; ?></section></section>
<?php require __DIR__ . '/../app/views/layouts/footer.php'; ?>
