<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/database.php';
require_once __DIR__ . '/../app/security.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/permissions.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/validation.php';
require_once __DIR__ . '/../app/services/BookingService.php';
require_once __DIR__ . '/../app/services/AppointmentService.php';
require_once __DIR__ . '/../app/services/NotificationService.php';

$pdo = db();
$currentUser = requireLogin($pdo);
$workspaceId = (int)$currentUser['workspace_id'];
$userId = (int)$currentUser['id'];
$isOwner = $currentUser['role'] === 'owner';

$engagementId = validatePositiveInt($_GET['engagement'] ?? $_POST['engagement_id'] ?? null, 'Engagement');
$bookingService = new BookingService($pdo);
$error = null;
$selectedDate = trim((string)($_GET['date'] ?? $_POST['date'] ?? ''));

$timezone = workspaceTimezone((string)$currentUser['workspace_timezone']);
$today = new DateTimeImmutable('today', $timezone);
if ($selectedDate === '') {
    $selectedDate = $today->format('Y-m-d');
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrf($_POST['csrf_token'] ?? null);
        $startAt = trim((string)($_POST['start_at'] ?? ''));
        $result = $bookingService->book($workspaceId, $engagementId, $startAt, $userId, $isOwner);
        $mail = (new NotificationService($pdo))->dispatch($workspaceId, (int)$result['appointment_id'], 'confirmation', (string)$result['client_token']);
        $message = 'Consultation scheduled for ' . $result['starts_at_display'] . '.';
        if ($mail['ok'] && $mail['driver'] === 'smtp') {
            $message .= ' Confirmation email sent to ' . $mail['to'] . '.';
        } elseif ($mail['ok']) {
            $message .= ' Email is in test mode: the confirmation was saved in storage/mail instead of being sent.';
        } else {
            $message .= ' The confirmation email could not be sent; use Generate client link on the engagement page.';
        }
        flash('success', $message);
        redirect(appUrl('public/engagement.php?id=' . $engagementId));
    }

    $context = $bookingService->getBookingContext($workspaceId, $engagementId, $userId, $isOwner);
    $slots = $bookingService->getAvailableSlots($workspaceId, $engagementId, $selectedDate, $userId, $isOwner);
    $scheduled = $bookingService->getScheduledAppointmentForEngagement($workspaceId, $engagementId);
} catch (InvalidArgumentException | RuntimeException $e) {
    $error = $e->getMessage();
    try {
        $context = $bookingService->getBookingContext($workspaceId, $engagementId, $userId, $isOwner);
        $slots = $error === 'This engagement already has a scheduled appointment.' ? [] : $bookingService->getAvailableSlots($workspaceId, $engagementId, $selectedDate, $userId, $isOwner);
        $scheduled = $bookingService->getScheduledAppointmentForEngagement($workspaceId, $engagementId);
    } catch (Throwable) {
        http_response_code(400);
        exit(h($error));
    }
} catch (Throwable $e) {
    error_log('[booking] ' . $e->getMessage());
    $error = 'Unable to load the booking calendar right now.';
    $context = null;
    $slots = [];
    $scheduled = null;
}

if ($context === null) {
    http_response_code(404);
    exit('Booking context not found.');
}

$pageTitle = 'Schedule consultation';
$csrf = csrfToken();
require __DIR__ . '/../app/views/layouts/header.php';
?>
<section class="page-heading">
    <div>
        <p class="eyebrow">BOOKING</p>
        <h1>Schedule consultation</h1>
        <p><?= h($context['client_first_name'] . ' ' . $context['client_last_name']) ?> · <?= h($context['service_name']) ?> · <?= (int)$context['duration_minutes'] ?> minutes</p>
    </div>
    <a class="button secondary" href="<?= h(appUrl('public/engagement.php?id=' . $engagementId)) ?>">Back to engagement</a>
</section>

<?php if ($error): ?><div class="error-box"><?= h($error) ?></div><?php endif; ?>

<section class="two-column booking-layout">
    <div class="content-stack">
        <section class="content-card">
            <div class="section-head">
                <div><p class="eyebrow">SELECT A DATE</p><h2><?= h($context['service_name']) ?></h2></div>
                <span class="role-pill"><?= h((string)$currentUser['workspace_timezone']) ?></span>
            </div>
            <form method="get" class="form-grid booking-date-form">
                <input type="hidden" name="engagement" value="<?= (int)$engagementId ?>">
                <label>Date<input type="date" name="date" value="<?= h($selectedDate) ?>" min="<?= h($today->format('Y-m-d')) ?>" required></label>
                <div class="form-actions"><button class="button secondary" type="submit">Show availability</button></div>
            </form>
        </section>

        <section class="content-card">
            <div class="section-head">
                <div><p class="eyebrow">AVAILABLE TIMES</p><h2><?= h($today->format('Y-m-d') === $selectedDate ? 'Today' : (new DateTimeImmutable($selectedDate . ' 00:00:00', $timezone))->format('l, F j, Y')) ?></h2></div>
                <span class="muted"><?= count($slots) ?> available</span>
            </div>

            <?php if ($scheduled): ?>
                <div class="success-box">This engagement already has a scheduled consultation on <?= h((new DateTimeImmutable((string)$scheduled['starts_at'], $timezone))->format('M j, Y g:i A')) ?>.</div>
            <?php elseif (!$slots): ?>
                <div class="empty-state">
                    <strong>No available times for this date.</strong>
                    <p>Try another date or check the consultant's availability and blocked periods.</p>
                </div>
            <?php else: ?>
                <div class="slot-grid">
                    <?php foreach ($slots as $slot): ?>
                        <form method="post" class="slot-form">
                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                            <input type="hidden" name="engagement_id" value="<?= (int)$engagementId ?>">
                            <input type="hidden" name="date" value="<?= h($selectedDate) ?>">
                            <input type="hidden" name="start_at" value="<?= h($slot['start']) ?>">
                            <button class="slot-button" type="submit">
                                <strong><?= h($slot['start_display']) ?></strong>
                                <span><?= h($slot['end_display']) ?></span>
                            </button>
                        </form>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <aside class="content-stack">
        <section class="content-card">
            <div class="section-head"><div><p class="eyebrow">CONSULTATION</p><h2>Booking details</h2></div></div>
            <div class="info-list">
                <div><strong>Client</strong><span><?= h($context['client_first_name'] . ' ' . $context['client_last_name']) ?></span></div>
                <div><strong>Company</strong><span><?= h($context['company_name'] ?: 'Individual client') ?></span></div>
                <div><strong>Service</strong><span><?= h($context['service_name']) ?></span></div>
                <div><strong>Consultant</strong><span><?= h($context['consultant_first_name'] . ' ' . $context['consultant_last_name']) ?></span></div>
                <div><strong>Duration</strong><span><?= (int)$context['duration_minutes'] ?> minutes</span></div>
                <div><strong>Buffers</strong><span><?= (int)$context['buffer_before_minutes'] ?> min before · <?= (int)$context['buffer_after_minutes'] ?> min after</span></div>
            </div>
        </section>
        <section class="content-card">
            <div class="section-head"><div><p class="eyebrow">BOOKING RULE</p><h2>What this calendar checks</h2></div></div>
            <div class="info-list">
                <div><strong>Business availability</strong><span>The workspace's recurring hours must allow the entire consultation and buffers.</span></div>
                <div><strong>Consultant availability</strong><span>Consultant-specific rules are applied when configured.</span></div>
                <div><strong>Blocked times</strong><span>Business-wide and consultant-specific blocked periods are excluded.</span></div>
                <div><strong>Conflicts</strong><span>Existing scheduled appointments and their buffers cannot overlap.</span></div>
            </div>
        </section>
    </aside>
</section>
<?php require __DIR__ . '/../app/views/layouts/footer.php'; ?>
