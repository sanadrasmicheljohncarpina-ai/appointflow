<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/security.php';
ensureSession();
if (!empty($_SESSION['user_id'])) {
    header('Location: ' . appUrl('public/dashboard.php'));
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= h(appUrl('assets/css/app.css')) ?>">
</head>
<body class="marketing-body">
<header class="public-topbar">
    <a class="brand" href="<?= h(appUrl('public/index.php')) ?>">
        <span class="brand-mark">AF</span><span><strong>AppointFlow</strong><small>Consultation workflow</small></span>
    </a>
    <a class="button secondary" href="<?= h(appUrl('public/login.php')) ?>">Team sign in</a>
</header>
<main class="marketing">
    <section class="hero-card">
        <p class="eyebrow">CONSULTATION WORKFLOW</p>
        <h1>Keep the client context connected from inquiry to follow-up.</h1>
        <p>AppointFlow is a lightweight workspace for consultation-driven businesses. The MVP starts with the workflow around an appointment rather than treating the calendar as the whole product.</p>
        <div class="hero-actions">
            <a class="button" href="<?= h(appUrl('public/login.php')) ?>">Open workspace</a>
            <span class="hero-note">MVP foundation</span>
        </div>
    </section>
    <section class="feature-grid">
        <article class="feature"><strong>Inquiry</strong><span>Capture why the client reached out.</span></article>
        <article class="feature"><strong>Engagement</strong><span>Keep the business context in one record.</span></article>
        <article class="feature"><strong>Appointment</strong><span>Schedule the consultation without losing context.</span></article>
        <article class="feature"><strong>Follow-up</strong><span>Continue the workflow after the meeting.</span></article>
    </section>
</main>
</body>
</html>
