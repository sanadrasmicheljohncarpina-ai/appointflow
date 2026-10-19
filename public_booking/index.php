<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/database.php';
require_once __DIR__ . '/../app/security.php';
require_once __DIR__ . '/../app/helpers.php';

$pdo = db();
$slug = trim((string)($_GET['business'] ?? ''));
if ($slug === '') { http_response_code(400); exit('A business booking link is required.'); }
$stmt = $pdo->prepare('SELECT id,name,email,phone,timezone,booking_slug,status FROM workspaces WHERE booking_slug=? LIMIT 1');
$stmt->execute([$slug]);
$workspace = $stmt->fetch();
if (!$workspace || $workspace['status'] !== 'active') { http_response_code(404); exit('Booking page not found.'); }
$stmt = $pdo->prepare('SELECT id,name,description,duration_minutes FROM services WHERE workspace_id=? AND active=1 ORDER BY name ASC, id ASC');
$stmt->execute([(int)$workspace['id']]);
$services = $stmt->fetchAll();
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Consult with <?= h($workspace['name']) ?></title><link rel="stylesheet" href="<?= h(appUrl('assets/css/app.css')) ?>"></head>
<body><header class="public-topbar"><div><strong><?= h($workspace['name']) ?></strong><small>Powered by AppointFlow</small></div></header><main class="page-shell"><section class="page-heading"><div><p class="eyebrow">CONSULTATION</p><h1>How can we help?</h1><p>Choose a consultation type to start your request.</p></div></section><section class="feature-grid public-service-grid"><?php if (!$services): ?><div class="empty-state" style="grid-column:1/-1"><strong>No active consultation types yet.</strong><p>Please check back later.</p></div><?php else: ?><?php foreach ($services as $service): ?><article class="feature public-service-card"><strong><?= h($service['name']) ?></strong><span><?= h($service['description'] ?: 'Professional consultation with the team.') ?></span><small><?= (int)$service['duration_minutes'] ?> minutes</small><div style="margin-top:14px"><a class="button small" href="<?= h(inquiryUrl($slug, (int)$service['id'])) ?>">Request this consultation</a></div></article><?php endforeach; ?><?php endif; ?></section><p class="muted" style="margin-top:22px">Already booked? <a href="<?= h(findAppointmentsUrl($slug)) ?>">Find my appointments</a></p></main><footer class="site-footer">AppointFlow MVP · <?= date('Y') ?></footer></body></html>
