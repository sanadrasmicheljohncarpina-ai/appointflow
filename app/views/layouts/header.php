<?php
require_once __DIR__ . '/../../security.php';
require_once __DIR__ . '/../../helpers.php';
ensureSession();
$pageTitle = $pageTitle ?? APP_NAME;
$currentUser = $currentUser ?? null;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($pageTitle) ?> · <?= h(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= h(appUrl('assets/css/app.css')) ?>">
</head>
<body>
<header class="topbar">
    <a class="brand" href="<?= h(appUrl('public/dashboard.php')) ?>">
        <span class="brand-mark">AF</span>
        <span>
            <strong>AppointFlow</strong>
            <small>Consultation workflow</small>
        </span>
    </a>
    <?php if ($currentUser): ?>
        <nav class="topnav">
            <a href="<?= h(appUrl('public/dashboard.php')) ?>">Dashboard</a>
            <a href="<?= h(appUrl('public/calendar.php')) ?>">Calendar</a>
            <a href="<?= h(appUrl('public/followups.php')) ?>">Follow-ups</a>
            <a href="<?= h(appUrl('public/engagements.php')) ?>"><?= $currentUser['role'] === 'owner' ? 'Engagements' : 'My Engagements' ?></a>
            <a href="<?= h(appUrl('public/clients.php')) ?>">Clients</a>
            <?php if ($currentUser['role'] === 'owner'): ?>
                <a href="<?= h(appUrl('public/team.php')) ?>">Team</a>
                <a href="<?= h(appUrl('public/services.php')) ?>">Services</a>
                <a href="<?= h(appUrl('public/availability.php')) ?>">Availability</a>
                <a href="<?= h(appUrl('public/settings.php')) ?>">Settings</a>
            <?php endif; ?>
            <a href="<?= h(appUrl('public/logout.php')) ?>">Log out</a>
        </nav>
    <?php endif; ?>
</header>
<main class="page-shell">
