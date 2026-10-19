<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/database.php';
require_once __DIR__ . '/../app/security.php';
require_once __DIR__ . '/../app/helpers.php';

$pdo = db();
$slug = trim((string)($_GET['business'] ?? ''));
$engagementId = (int)($_GET['engagement'] ?? 0);
$stmt = $pdo->prepare('SELECT id, name, booking_slug FROM workspaces WHERE booking_slug = ? AND status = \'active\' LIMIT 1');
$stmt->execute([$slug]);
$workspace = $stmt->fetch();
if (!$workspace) { http_response_code(404); exit('Booking page not found.'); }
$stmt = $pdo->prepare(
    'SELECT e.id, e.status, e.title, c.first_name, c.last_name
     FROM engagements e INNER JOIN clients c ON c.id=e.client_id
     WHERE e.id=? AND e.workspace_id=? LIMIT 1'
);
$stmt->execute([$engagementId, (int)$workspace['id']]);
$engagement = $stmt->fetch();
if (!$engagement) { http_response_code(404); exit('Inquiry not found.'); }
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Inquiry received · <?= h($workspace['name']) ?></title><link rel="stylesheet" href="<?= h(appUrl('assets/css/app.css')) ?>"></head>
<body><header class="public-topbar"><div><strong><?= h($workspace['name']) ?></strong><small>Powered by AppointFlow</small></div></header><main class="page-shell"><section class="content-card form-card"><div class="section-head"><div><p class="eyebrow">RECEIVED</p><h1>Consultation request received</h1></div><span class="role-pill">Inquiry #<?= (int)$engagement['id'] ?></span></div><p>Thank you, <?= h($engagement['first_name']) ?>. Your request has been recorded and is waiting for business review.</p><div class="info-list"><div><strong>Request</strong><span><?= h($engagement['title']) ?></span></div><div><strong>Status</strong><span><?= h(ucfirst((string)$engagement['status'])) ?></span></div><div><strong>Reference</strong><span>#<?= (int)$engagement['id'] ?></span></div></div><div style="margin-top:20px"><a class="button" href="<?= h(bookingUrl((string)$workspace['booking_slug'])) ?>">Back to services</a></div></section></main><footer class="site-footer">AppointFlow MVP · <?= date('Y') ?></footer></body></html>
