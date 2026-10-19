<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/database.php';
require_once __DIR__ . '/../app/security.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/services/BookingService.php';
require_once __DIR__ . '/../app/services/AppointmentService.php';
require_once __DIR__ . '/../app/services/NotificationService.php';

// The token lives in the URL, so keep it out of caches, referrers and search indexes.
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');

$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$pdo = db();
$bookingService = new BookingService($pdo);
$appt = null;
try {
    $appt = $bookingService->findAppointmentByToken($token);
} catch (Throwable $e) {
    error_log('[client appointment lookup] ' . $e->getMessage());
}

if ($appt === null) {
    http_response_code(404);
    ?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><meta name="referrer" content="no-referrer"><title>Link unavailable</title><link rel="stylesheet" href="<?= h(appUrl('assets/css/app.css')) ?>"></head>
<body><header class="public-topbar"><div><strong>Appointment</strong><small>Powered by AppointFlow</small></div></header><main class="page-shell"><section class="content-card form-card"><div class="empty-state"><strong>This link is invalid or has expired.</strong><p>Please use the most recent link you received, or contact the business to request a new one.</p></div></section></main></body></html>
    <?php
    exit;
}

$timezone = workspaceTimezone((string)$appt['workspace_timezone']);
$now = new DateTimeImmutable('now', $timezone);
$error = null;
$flashSuccess = flash('success');
$self = clientAppointmentUrl($token);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    try {
        verifyCsrf($_POST['csrf_token'] ?? null);
        if ($action === 'reschedule') {
            $result = $bookingService->rescheduleByToken($token, trim((string)($_POST['start_at'] ?? '')));
            (new NotificationService($pdo))->dispatch((int)$appt['workspace_id'], (int)$appt['appointment_id'], 'reschedule', $token);
            flash('success', 'Your appointment has been rescheduled to ' . $result['starts_at_display'] . '.');
            redirect($self);
        } elseif ($action === 'cancel') {
            $reason = trim((string)($_POST['reason'] ?? ''));
            if (strlen($reason) > 500) {
                $reason = substr($reason, 0, 500);
            }
            if ($appt['appointment_status'] !== 'scheduled') {
                throw new InvalidArgumentException('This appointment can no longer be cancelled.');
            }
            (new AppointmentService($pdo))->cancel((int)$appt['workspace_id'], (int)$appt['appointment_id'], null, $reason);
            (new NotificationService($pdo))->dispatch((int)$appt['workspace_id'], (int)$appt['appointment_id'], 'cancellation', $token);
            flash('success', 'Your appointment has been cancelled.');
            redirect($self);
        } else {
            throw new InvalidArgumentException('Unknown action.');
        }
    } catch (InvalidArgumentException | RuntimeException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('[client appointment action] ' . $e->getMessage());
        $error = 'Something went wrong. Please try again.';
    }
    // Reload fresh state after a failed action.
    $appt = $bookingService->findAppointmentByToken($token) ?? $appt;
}

$start = new DateTimeImmutable((string)$appt['starts_at'], $timezone);
$end = new DateTimeImmutable((string)$appt['ends_at'], $timezone);
$isScheduled = $appt['appointment_status'] === 'scheduled';
$isFuture = $start > $now;
$canManage = $isScheduled && $isFuture;
$myAppointments = [];
try {
    $myAppointments = $bookingService->listClientAppointments((int)$appt['workspace_id'], (int)$appt['client_id'], (int)$appt['appointment_id']);
} catch (Throwable $e) {
    error_log('[client appointment list] ' . $e->getMessage());
}
$statusLabels = ['scheduled' => 'Scheduled', 'cancelled' => 'Cancelled', 'completed' => 'Completed', 'no_show' => 'Missed'];
$consultant = trim((string)$appt['consultant_first_name'] . ' ' . (string)$appt['consultant_last_name']);

$mode = (string)($_GET['mode'] ?? '');
$selectedDate = trim((string)($_GET['date'] ?? ''));
$slots = [];
if ($canManage && $mode === 'reschedule') {
    if ($selectedDate === '') {
        $selectedDate = $now->format('Y-m-d');
    }
    try {
        $slots = $bookingService->getRescheduleSlots($appt, $selectedDate);
    } catch (InvalidArgumentException $e) {
        $error = $error ?? $e->getMessage();
    } catch (Throwable $e) {
        error_log('[client reschedule slots] ' . $e->getMessage());
        $error = $error ?? 'Unable to load available times right now.';
    }
}

$statusLabel = ['scheduled' => 'Scheduled', 'cancelled' => 'Cancelled', 'completed' => 'Completed', 'no_show' => 'Missed'][$appt['appointment_status']] ?? ucfirst((string)$appt['appointment_status']);
$statusClass = $appt['appointment_status'] === 'scheduled' ? 'status-scheduled' : ($appt['appointment_status'] === 'completed' ? 'status-completed' : 'status-closed_lost');
$csrf = csrfToken();
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><meta name="referrer" content="no-referrer"><title>Your appointment · <?= h($appt['workspace_name']) ?></title><link rel="stylesheet" href="<?= h(appUrl('assets/css/app.css')) ?>"></head>
<body>
<header class="public-topbar"><div><strong><?= h($appt['workspace_name']) ?></strong><small>Powered by AppointFlow</small></div></header>
<main class="page-shell">
    <section class="page-heading">
        <div>
            <p class="eyebrow">YOUR APPOINTMENT</p>
            <h1><?= h($appt['service_name']) ?></h1>
            <p>Hello <?= h($appt['client_first_name']) ?>, here are the details of your consultation.</p>
        </div>
        <span class="status-badge <?= h($statusClass) ?>"><?= h($statusLabel) ?></span>
    </section>

    <?php if ($flashSuccess): ?><div class="success-box"><?= h($flashSuccess) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="error-box"><?= h($error) ?></div><?php endif; ?>

    <section class="two-column booking-layout">
        <div class="content-stack">
            <section class="content-card">
                <div class="section-head"><div><p class="eyebrow">DETAILS</p><h2><?= h($start->format('l, F j, Y')) ?></h2></div><span class="role-pill"><?= h((string)$appt['workspace_timezone']) ?></span></div>
                <div class="info-list">
                    <div><strong>Time</strong><span><?= h($start->format('g:i A')) ?> – <?= h($end->format('g:i A')) ?></span></div>
                    <div><strong>Service</strong><span><?= h($appt['service_name']) ?> · <?= (int)$appt['duration_minutes'] ?> minutes</span></div>
                    <div><strong>Consultant</strong><span><?= h($consultant !== '' ? $consultant : 'To be confirmed') ?></span></div>
                    <div><strong>Status</strong><span><?= h($statusLabel) ?></span></div>
                </div>
                <?php if ($canManage && $mode !== 'reschedule'): ?>
                    <div class="heading-actions" style="margin-top:20px;justify-content:flex-start">
                        <a class="button" href="<?= h($self . (str_contains($self, '?') ? '&' : '?') . 'mode=reschedule') ?>">Reschedule</a>
                    </div>
                <?php elseif ($appt['appointment_status'] === 'cancelled'): ?>
                    <div class="empty-state" style="margin-top:20px"><strong>This appointment was cancelled.</strong><p>If you would like a new time, please contact <?= h($appt['workspace_name']) ?>.</p></div>
                <?php elseif ($isScheduled && !$isFuture): ?>
                    <div class="empty-state" style="margin-top:20px"><strong>This appointment has already started.</strong><p>It can no longer be changed online.</p></div>
                <?php endif; ?>
            </section>

            <?php if ($canManage && $mode === 'reschedule'): ?>
            <section class="content-card">
                <div class="section-head"><div><p class="eyebrow">RESCHEDULE</p><h2>Choose a new date</h2></div><a class="muted" href="<?= h($self) ?>">Back</a></div>
                <form method="get" action="<?= h((string)strtok($self, '?')) ?>" class="form-grid booking-date-form">
                    <?php if (!prettyUrlsEnabled()): ?><input type="hidden" name="token" value="<?= h($token) ?>"><?php endif; ?>
                    <input type="hidden" name="mode" value="reschedule">
                    <label>Date<input type="date" name="date" value="<?= h($selectedDate) ?>" min="<?= h($now->format('Y-m-d')) ?>" required></label>
                    <div class="form-actions"><button class="button secondary" type="submit">Show availability</button></div>
                </form>
            </section>
            <section class="content-card">
                <div class="section-head"><div><p class="eyebrow">AVAILABLE TIMES</p><h2><?= h((new DateTimeImmutable($selectedDate . ' 00:00:00', $timezone))->format('l, F j, Y')) ?></h2></div><span class="muted"><?= count($slots) ?> available</span></div>
                <?php if (!$slots): ?>
                    <div class="empty-state"><strong>No available times for this date.</strong><p>Please try another date.</p></div>
                <?php else: ?>
                    <div class="slot-grid">
                        <?php foreach ($slots as $slot): ?>
                            <form method="post" action="<?= h($self) ?>" class="slot-form">
                                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                <input type="hidden" name="token" value="<?= h($token) ?>">
                                <input type="hidden" name="action" value="reschedule">
                                <input type="hidden" name="start_at" value="<?= h($slot['start']) ?>">
                                <button class="slot-button" type="submit"><strong><?= h($slot['start_display']) ?></strong><span><?= h($slot['end_display']) ?></span></button>
                            </form>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
            <?php endif; ?>
        </div>

        <aside class="content-stack">
            <section class="content-card">
                <div class="section-head"><div><p class="eyebrow">OVERVIEW</p><h2>Your appointments</h2></div><span class="muted"><?= count($myAppointments) ?></span></div>
                <?php if (!$myAppointments): ?>
                    <div class="empty-state"><strong>No appointments yet.</strong></div>
                <?php else: ?>
                    <div class="info-list">
                        <?php foreach ($myAppointments as $row): $rowStart = new DateTimeImmutable($row['starts_at'], $timezone); ?>
                            <div>
                                <strong><?= h($rowStart->format('M j, Y · g:i A')) ?><?= $row['is_current'] ? ' · this appointment' : '' ?></strong>
                                <span><?= h($row['service_name']) ?> · <span class="status-badge <?= $row['status'] === 'scheduled' ? 'status-scheduled' : ($row['status'] === 'completed' ? 'status-completed' : 'status-closed_lost') ?>"><?= h($statusLabels[$row['status']] ?? ucfirst($row['status'])) ?></span></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
            <?php if ($canManage): ?>
            <section class="content-card">
                <div class="section-head"><div><p class="eyebrow">CANCEL</p><h2>Can't make it?</h2></div></div>
                <form method="post" action="<?= h($self) ?>" class="form-stack" onsubmit="return confirm('Cancel this appointment?');">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="token" value="<?= h($token) ?>">
                    <input type="hidden" name="action" value="cancel">
                    <label>Reason (optional)<input type="text" name="reason" maxlength="500" placeholder="Let us know why"></label>
                    <button class="button danger" type="submit">Cancel appointment</button>
                </form>
            </section>
            <?php endif; ?>
            <section class="content-card">
                <div class="section-head"><div><p class="eyebrow">KEEP THIS LINK PRIVATE</p><h2>Your link</h2></div></div>
                <p class="muted">Anyone with this link can see your appointment list and change this appointment. No account is needed.</p>
            </section>
        </aside>
    </section>
</main>
<footer class="site-footer">AppointFlow MVP · <?= date('Y') ?></footer>
</body></html>
