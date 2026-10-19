<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/database.php';
require_once __DIR__ . '/../app/security.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/validation.php';
require_once __DIR__ . '/../app/services/BookingService.php';
require_once __DIR__ . '/../app/services/NotificationService.php';
require_once __DIR__ . '/../app/services/LinkRequestService.php';

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

$pdo = db();
$slug = trim((string)($_GET['business'] ?? $_POST['business'] ?? ''));
$stmt = $pdo->prepare('SELECT id, name, booking_slug, status FROM workspaces WHERE booking_slug = ? LIMIT 1');
$stmt->execute([$slug]);
$workspace = $stmt->fetch();
if (!$workspace || $workspace['status'] !== 'active') {
    http_response_code(404);
    exit('Booking page not found.');
}

// 'owner' (default): the business is notified and sends the link. 'auto': email the client directly.
$autoSend = defined('APP_LINK_REQUEST_MODE') && APP_LINK_REQUEST_MODE === 'auto';
$done = false;
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verifyCsrf($_POST['csrf_token'] ?? null);
        $email = validateEmail((string)($_POST['email'] ?? ''));
        if ($autoSend) {
            $found = (new BookingService($pdo))->reissueClientLinkForEmail((int)$workspace['id'], $email);
            if ($found !== null) {
                (new NotificationService($pdo))->dispatch((int)$workspace['id'], (int)$found['appointment_id'], 'confirmation', (string)$found['token'], 'link');
            }
        } else {
            $requests = new LinkRequestService($pdo);
            $request = $requests->recordRequest((int)$workspace['id'], $email);
            if ($request !== null && $request['is_new']) {
                $requests->notifyOwner((int)$request['id']);
            }
        }
        // Same neutral answer whether or not the email is known, so this page cannot be used to probe clients.
        $done = true;
    } catch (InvalidArgumentException | RuntimeException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('[find appointments] ' . $e->getMessage());
        $error = 'Something went wrong. Please try again in a moment.';
    }
}
$csrf = csrfToken();
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>Find my appointments · <?= h($workspace['name']) ?></title><link rel="stylesheet" href="<?= h(appUrl('assets/css/app.css')) ?>"></head>
<body>
<header class="public-topbar"><div><strong><?= h($workspace['name']) ?></strong><small>Powered by AppointFlow</small></div><a class="button small secondary" href="<?= h(bookingUrl((string)$workspace['booking_slug'])) ?>">Back to services</a></header>
<main class="page-shell">
    <section class="page-heading"><div><p class="eyebrow">YOUR APPOINTMENTS</p><h1>Find my appointments</h1><p><?= $autoSend ? 'Enter the email you used when you booked. We will send you a private link to view and manage your appointments.' : 'Enter the email you used when you booked. We will let ' . h($workspace['name']) . ' know, and they will email you a private link to view and manage your appointments.' ?></p></div></section>
    <?php if ($error): ?><div class="error-box"><?= h($error) ?></div><?php endif; ?>
    <?php if ($done): ?>
        <div class="success-box">If we found appointments for that email address, <?= $autoSend ? 'we have sent a private link to it. Please check your inbox (and spam folder). The link can take a minute to arrive.' : h($workspace['name']) . ' has been notified and will email you a private link. Please check your inbox (and spam folder) in the coming hours.' ?></div>
    <?php else: ?>
        <section class="content-card form-width">
            <form method="post" class="form-stack" action="<?= h(findAppointmentsUrl((string)$workspace['booking_slug'])) ?>">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="business" value="<?= h((string)$workspace['booking_slug']) ?>">
                <label>Email<input type="email" name="email" maxlength="255" autocomplete="email" required></label>
                <button class="button" type="submit"><?= $autoSend ? 'Email me my link' : 'Request my link' ?></button>
            </form>
        </section>
    <?php endif; ?>
</main>
<footer class="site-footer">AppointFlow MVP · <?= date('Y') ?></footer>
</body></html>
