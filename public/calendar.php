<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/database.php';
require_once __DIR__ . '/../app/security.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/services/TeamService.php';
require_once __DIR__ . '/../app/services/OperationsService.php';

$pdo = db();
$currentUser = requireLogin($pdo);
$workspaceId = (int)$currentUser['workspace_id'];
$userId = (int)$currentUser['id'];
$isOwner = $currentUser['role'] === 'owner';
$timezone = workspaceTimezone((string)$currentUser['workspace_timezone']);

// ---- Request parameters (all validated; anything unexpected falls back to a safe default) ------
$view = ($_GET['view'] ?? 'week') === 'day' ? 'day' : 'week';
$today = new DateTimeImmutable('today', $timezone);
$anchor = $today;
$dateParam = trim((string)($_GET['date'] ?? ''));
if ($dateParam !== '') {
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $dateParam, $timezone);
    $errors = DateTimeImmutable::getLastErrors();
    if ($parsed !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
        $anchor = $parsed;
    }
}
$showCancelled = ($_GET['cancelled'] ?? '') === '1';

// Consultants always see only their own engagements. Owners see everyone, optionally narrowed to one consultant.
$members = $isOwner ? (new TeamService($pdo))->listMembers($workspaceId) : [];
$consultants = array_values(array_filter($members, static fn (array $m): bool => $m['role'] === 'consultant'));
$filterConsultantId = null;
if ($isOwner) {
    $requested = (int)($_GET['consultant'] ?? 0);
    foreach ($consultants as $member) {
        if ((int)$member['id'] === $requested) {
            $filterConsultantId = $requested;
        }
    }
}
$scopeUserId = $isOwner ? $filterConsultantId : $userId;

[$rangeStart, $rangeEnd] = OperationsService::viewRange($view, $anchor);
$ops = new OperationsService($pdo);
$appointments = $ops->getAppointmentsBetween(
    $workspaceId,
    $scopeUserId,
    $rangeStart->format('Y-m-d H:i:s'),
    $rangeEnd->format('Y-m-d H:i:s'),
    $showCancelled
);

$byDay = [];
foreach ($appointments as $appt) {
    $byDay[substr((string)$appt['starts_at'], 0, 10)][] = $appt;
}

$calendarUrl = static function (array $overrides = []) use ($view, $anchor, $filterConsultantId, $showCancelled, $isOwner): string {
    $params = array_merge([
        'view' => $view,
        'date' => $anchor->format('Y-m-d'),
        'consultant' => $isOwner && $filterConsultantId ? (string)$filterConsultantId : null,
        'cancelled' => $showCancelled ? '1' : null,
    ], $overrides);
    return appUrl('public/calendar.php') . '?' . http_build_query(array_filter($params, static fn ($v) => $v !== null && $v !== ''));
};
$step = $view === 'day' ? '1 day' : '7 days';
$prevUrl = $calendarUrl(['date' => $anchor->modify('-' . $step)->format('Y-m-d')]);
$nextUrl = $calendarUrl(['date' => $anchor->modify('+' . $step)->format('Y-m-d')]);
$todayUrl = $calendarUrl(['date' => $today->format('Y-m-d')]);

$lastDay = $rangeEnd->modify('-1 day');
if ($view === 'day') {
    $rangeLabel = $rangeStart->format('l, F j, Y');
} elseif ($rangeStart->format('Y') === $lastDay->format('Y')) {
    $rangeLabel = $rangeStart->format('M j') . ' – ' . $lastDay->format('M j, Y');
} else {
    $rangeLabel = $rangeStart->format('M j, Y') . ' – ' . $lastDay->format('M j, Y');
}

$days = [];
for ($d = $rangeStart; $d < $rangeEnd; $d = $d->modify('+1 day')) {
    $days[] = $d;
}
$statusLabels = ['scheduled' => 'Scheduled', 'completed' => 'Completed', 'no_show' => 'No-show', 'cancelled' => 'Cancelled'];

$renderEvent = static function (array $appt) use ($timezone, $statusLabels): void {
    $status = (string)$appt['status'];
    ?>
    <a class="cal-event cal-<?= h($status) ?>" href="<?= h(engagementUrl((int)$appt['engagement_id'], 'appointment')) ?>">
        <span class="cal-time"><?= h(formatLocalDateTime($appt['starts_at'], $timezone, 'g:i A')) ?> – <?= h(formatLocalDateTime($appt['ends_at'], $timezone, 'g:i A')) ?></span>
        <strong><?= h(personName($appt['client_first'], $appt['client_last'])) ?></strong>
        <span class="cal-meta"><?= h($appt['service_name'] ?: $appt['engagement_title']) ?></span>
        <span class="cal-meta"><?= h(personName($appt['consultant_first'], $appt['consultant_last'], 'Unassigned')) ?></span>
        <span class="status-badge cal-badge-<?= h($status) ?>"><?= h($statusLabels[$status] ?? $status) ?></span>
    </a>
    <?php
};

$pageTitle = 'Calendar';
require __DIR__ . '/../app/views/layouts/header.php';
?>
<section class="page-heading">
    <div>
        <p class="eyebrow">CALENDAR</p>
        <h1><?= h($isOwner ? 'Consultation calendar' : 'My calendar') ?></h1>
        <p>All times are shown in the workspace timezone (<?= h($timezone->getName()) ?>). Select a consultation to open its engagement.</p>
    </div>
    <span class="role-pill"><?= count($appointments) ?> consultation<?= count($appointments) === 1 ? '' : 's' ?></span>
</section>

<section class="content-card">
    <div class="cal-toolbar">
        <div class="action-group">
            <a class="button secondary small" href="<?= h($prevUrl) ?>" aria-label="Previous <?= h($view) ?>">‹ Prev</a>
            <a class="button secondary small" href="<?= h($todayUrl) ?>">Today</a>
            <a class="button secondary small" href="<?= h($nextUrl) ?>" aria-label="Next <?= h($view) ?>">Next ›</a>
            <strong class="cal-range"><?= h($rangeLabel) ?></strong>
        </div>
        <div class="action-group">
            <a class="button small <?= $view === 'week' ? '' : 'secondary' ?>" href="<?= h($calendarUrl(['view' => 'week'])) ?>">Week</a>
            <a class="button small <?= $view === 'day' ? '' : 'secondary' ?>" href="<?= h($calendarUrl(['view' => 'day'])) ?>">Day</a>
        </div>
    </div>

    <form method="get" class="cal-filters">
        <input type="hidden" name="view" value="<?= h($view) ?>">
        <input type="hidden" name="date" value="<?= h($anchor->format('Y-m-d')) ?>">
        <?php if ($isOwner): ?>
            <label>Consultant
                <select name="consultant">
                    <option value="">All consultants</option>
                    <?php foreach ($consultants as $member): ?>
                        <option value="<?= (int)$member['id'] ?>" <?= $filterConsultantId === (int)$member['id'] ? 'selected' : '' ?>><?= h(personName($member['first_name'], $member['last_name'])) ?><?= $member['status'] === 'active' ? '' : ' (inactive)' ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        <?php endif; ?>
        <label class="cal-check"><input type="checkbox" name="cancelled" value="1" <?= $showCancelled ? 'checked' : '' ?>> Show cancelled</label>
        <button class="button secondary small" type="submit">Apply</button>
    </form>

    <?php if ($view === 'week'): ?>
        <div class="cal-week">
            <?php foreach ($days as $day): $key = $day->format('Y-m-d'); $isToday = $key === $today->format('Y-m-d'); ?>
                <div class="cal-day<?= $isToday ? ' cal-today' : '' ?>">
                    <a class="cal-day-head" href="<?= h($calendarUrl(['view' => 'day', 'date' => $key])) ?>">
                        <span><?= h($day->format('D')) ?></span><strong><?= h($day->format('j')) ?></strong>
                    </a>
                    <?php if (empty($byDay[$key])): ?>
                        <p class="cal-empty">—</p>
                    <?php else: foreach ($byDay[$key] as $appt) { $renderEvent($appt); } endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: $key = $rangeStart->format('Y-m-d'); ?>
        <div class="cal-dayview">
            <?php if (empty($byDay[$key])): ?>
                <div class="empty-state"><strong>No consultations on this day.</strong></div>
            <?php else: foreach ($byDay[$key] as $appt) { $renderEvent($appt); } endif; ?>
        </div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../app/views/layouts/footer.php'; ?>
