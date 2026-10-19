<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/database.php';
require_once __DIR__ . '/../app/security.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/permissions.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/validation.php';
require_once __DIR__ . '/../app/services/AvailabilityService.php';
require_once __DIR__ . '/../app/services/TeamService.php';
require_once __DIR__ . '/../app/services/WorkspaceService.php';

$pdo = db();
$currentUser = requireLogin($pdo);
requireOwner($currentUser);

$workspaceId = (int)$currentUser['workspace_id'];
$workspaceService = new WorkspaceService($pdo);
$workspace = $workspaceService->get($workspaceId);
$availabilityService = new AvailabilityService($pdo);
$teamService = new TeamService($pdo);
$members = $teamService->listMembers($workspaceId);
$consultants = array_values(array_filter(
    $members,
    static fn(array $member): bool => $member['role'] === 'consultant' && $member['status'] === 'active'
));

$weekdays = [
    0 => 'Sunday',
    1 => 'Monday',
    2 => 'Tuesday',
    3 => 'Wednesday',
    4 => 'Thursday',
    5 => 'Friday',
    6 => 'Saturday',
];

$errors = [];
$timezone = workspaceTimezone((string)$workspace['timezone']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verifyCsrf($_POST['csrf_token'] ?? null);
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'add_rule') {
            $scope = (string)($_POST['scope'] ?? 'business');
            $userId = null;
            if ($scope === 'consultant') {
                $userId = validatePositiveInt($_POST['user_id'] ?? null, 'Consultant');
            } elseif ($scope !== 'business') {
                throw new InvalidArgumentException('Invalid availability scope.');
            }

            $weekday = filter_var($_POST['weekday'] ?? null, FILTER_VALIDATE_INT);
            if ($weekday === false || $weekday === null) {
                throw new InvalidArgumentException('Please select a weekday.');
            }

            $start = trim((string)($_POST['start_time'] ?? ''));
            $end = trim((string)($_POST['end_time'] ?? ''));
            $availabilityService->addRule($workspaceId, $userId, (int)$weekday, $start, $end);
            flash('success', 'Availability rule added.');
            redirect(appUrl('public/availability.php'));
        }

        if ($action === 'rule_status') {
            $ruleId = validatePositiveInt($_POST['rule_id'] ?? null, 'Availability rule');
            $active = ($_POST['active'] ?? '0') === '1';
            $availabilityService->setRuleStatus($workspaceId, $ruleId, $active);
            flash('success', $active ? 'Availability rule activated.' : 'Availability rule deactivated.');
            redirect(appUrl('public/availability.php'));
        }

        if ($action === 'delete_rule') {
            $ruleId = validatePositiveInt($_POST['rule_id'] ?? null, 'Availability rule');
            $availabilityService->deleteRule($workspaceId, $ruleId);
            flash('success', 'Availability rule removed.');
            redirect(appUrl('public/availability.php'));
        }

        if ($action === 'add_blocked') {
            $scope = (string)($_POST['scope'] ?? 'business');
            $userId = null;
            if ($scope === 'consultant') {
                $userId = validatePositiveInt($_POST['user_id'] ?? null, 'Consultant');
            } elseif ($scope !== 'business') {
                throw new InvalidArgumentException('Invalid blocked-time scope.');
            }

            $date = trim((string)($_POST['blocked_date'] ?? ''));
            $startTime = trim((string)($_POST['blocked_start_time'] ?? ''));
            $endTime = trim((string)($_POST['blocked_end_time'] ?? ''));
            $reasonRaw = trim((string)($_POST['reason'] ?? ''));
            $reason = $reasonRaw !== '' ? $reasonRaw : null;

            $startsAt = parseWorkspaceDateTime($date, $startTime, $timezone);
            $endsAt = parseWorkspaceDateTime($date, $endTime, $timezone);
            if ($endsAt <= $startsAt) {
                throw new InvalidArgumentException('Blocked time must end after it starts.');
            }

            $availabilityService->addBlockedTime(
                $workspaceId,
                $userId,
                $startsAt->format('Y-m-d H:i:s'),
                $endsAt->format('Y-m-d H:i:s'),
                $reason
            );
            flash('success', 'Blocked time added.');
            redirect(appUrl('public/availability.php'));
        }

        if ($action === 'delete_blocked') {
            $blockedId = validatePositiveInt($_POST['blocked_id'] ?? null, 'Blocked period');
            $availabilityService->deleteBlockedTime($workspaceId, $blockedId);
            flash('success', 'Blocked time removed.');
            redirect(appUrl('public/availability.php'));
        }

        throw new InvalidArgumentException('Unknown availability action.');
    } catch (InvalidArgumentException | RuntimeException $e) {
        $errors[] = $e->getMessage();
    } catch (Throwable $e) {
        error_log('[availability] ' . $e->getMessage());
        $errors[] = 'Unable to complete that availability action.';
    }
}

$rules = $availabilityService->listAllRules($workspaceId);
$blockedTimes = $availabilityService->listBlockedTimes($workspaceId);
$success = flash('success');
$csrf = csrfToken();

$businessRules = array_values(array_filter(
    $rules,
    static fn(array $rule): bool => $rule['user_id'] === null
));
$consultantRuleCount = count(array_filter(
    $rules,
    static fn(array $rule): bool => $rule['user_id'] !== null && (int)$rule['active'] === 1
));

$businessRuleCount = count(array_filter(
    $businessRules,
    static fn(array $rule): bool => (int)$rule['active'] === 1
));

function parseWorkspaceDateTime(string $date, string $time, DateTimeZone $timezone): DateTimeImmutable
{
    $date = trim($date);
    $time = trim($time);

    $dateTime = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . $time, $timezone);
    $errors = DateTimeImmutable::getLastErrors();
    $hasErrors = is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0);

    if (!$dateTime || $hasErrors || $dateTime->format('Y-m-d') !== $date || $dateTime->format('H:i') !== $time) {
        throw new InvalidArgumentException('Please enter a valid blocked date and time.');
    }

    return $dateTime;
}

$pageTitle = 'Availability';
require __DIR__ . '/../app/views/layouts/header.php';
?>
<section class="page-heading">
    <div>
        <p class="eyebrow">AVAILABILITY</p>
        <h1>Working hours</h1>
        <p>Configure recurring business hours, consultant availability, and blocked periods for the <?= h($workspace['timezone']) ?> workspace timezone.</p>
    </div>
    <span class="role-pill"><?= h($workspace['timezone']) ?></span>
</section>

<?php if ($success): ?>
    <div class="success-box"><?= h($success) ?></div>
<?php endif; ?>

<?php if ($errors): ?>
    <div class="error-box">
        <?php foreach ($errors as $message): ?>
            <div><?= h($message) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<section class="content-card">
    <div class="section-head">
        <div>
            <p class="eyebrow">BUSINESS AVAILABILITY</p>
            <h2>Recurring business hours</h2>
        </div>
        <span class="muted"><?= $businessRuleCount ?> active rule<?= $businessRuleCount === 1 ? '' : 's' ?></span>
    </div>

    <div class="availability-week-grid">
        <?php for ($day = 0; $day <= 6; $day++): ?>
            <?php
            $dayRules = array_values(array_filter(
                $businessRules,
                static fn(array $rule): bool => (int)$rule['weekday'] === $day
            ));
            ?>
            <article class="weekday-card">
                <h3><?= h($weekdays[$day]) ?></h3>
                <?php if (!$dayRules): ?>
                    <p class="muted">Unavailable</p>
                <?php else: ?>
                    <?php foreach ($dayRules as $rule): ?>
                        <div class="availability-row">
                            <div>
                                <strong><?= h(substr((string)$rule['start_time'], 0, 5)) ?>–<?= h(substr((string)$rule['end_time'], 0, 5)) ?></strong>
                                <span class="table-subtle"><?= (int)$rule['active'] === 1 ? 'Active' : 'Inactive' ?></span>
                            </div>
                            <div class="action-group">
                                <form method="post" class="inline-form">
                                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                    <input type="hidden" name="action" value="rule_status">
                                    <input type="hidden" name="rule_id" value="<?= (int)$rule['id'] ?>">
                                    <input type="hidden" name="active" value="<?= (int)$rule['active'] === 1 ? '0' : '1' ?>">
                                    <button class="button tiny <?= (int)$rule['active'] === 1 ? 'secondary' : '' ?>" type="submit">
                                        <?= (int)$rule['active'] === 1 ? 'Disable' : 'Enable' ?>
                                    </button>
                                </form>
                                <form method="post" class="inline-form" onsubmit="return confirm('Remove this availability rule?');">
                                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                    <input type="hidden" name="action" value="delete_rule">
                                    <input type="hidden" name="rule_id" value="<?= (int)$rule['id'] ?>">
                                    <button class="button tiny danger" type="submit">Remove</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </article>
        <?php endfor; ?>
    </div>
</section>

<section class="two-column availability-layout">
    <div class="content-card">
        <div class="section-head">
            <div>
                <p class="eyebrow">ADD</p>
                <h2>Recurring availability rule</h2>
            </div>
        </div>

        <form method="post" class="form-stack" id="availability-rule-form">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="add_rule">

            <label>
                Applies to
                <select name="scope" id="availability-scope" required>
                    <option value="business">Entire business</option>
                    <option value="consultant">Specific consultant</option>
                </select>
            </label>

            <label id="consultant-rule-field" class="hidden-field">
                Consultant
                <select name="user_id">
                    <option value="">Select consultant</option>
                    <?php foreach ($consultants as $consultant): ?>
                        <option value="<?= (int)$consultant['id'] ?>">
                            <?= h($consultant['first_name'] . ' ' . $consultant['last_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                Weekday
                <select name="weekday" required>
                    <?php foreach ($weekdays as $day => $name): ?>
                        <option value="<?= $day ?>" <?= $day === 1 ? 'selected' : '' ?>><?= h($name) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <div class="form-grid form-grid-compact">
                <label>
                    Start time
                    <input type="time" name="start_time" value="09:00" required>
                </label>
                <label>
                    End time
                    <input type="time" name="end_time" value="17:00" required>
                </label>
            </div>

            <button class="button" type="submit">Add availability</button>
        </form>
    </div>

    <div class="content-card">
        <div class="section-head">
            <div>
                <p class="eyebrow">CONSULTANTS</p>
                <h2>Staff availability</h2>
            </div>
            <span class="muted"><?= $consultantRuleCount ?> active rule<?= $consultantRuleCount === 1 ? '' : 's' ?></span>
        </div>

        <?php if (!$consultants): ?>
            <div class="empty-state"><strong>No active consultants.</strong><p>Add or activate a consultant in Team before adding individual availability.</p></div>
        <?php else: ?>
            <div class="info-list">
                <?php foreach ($consultants as $consultant): ?>
                    <?php
                    $count = count(array_filter(
                        $rules,
                        static fn(array $rule): bool => (int)($rule['user_id'] ?? 0) === (int)$consultant['id'] && (int)$rule['active'] === 1
                    ));
                    ?>
                    <div>
                        <strong><?= h($consultant['first_name'] . ' ' . $consultant['last_name']) ?></strong>
                        <span><?= $count ?> active recurring rule<?= $count === 1 ? '' : 's' ?>.</span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<section class="content-card">
    <div class="section-head">
        <div>
            <p class="eyebrow">BLOCKED TIMES</p>
            <h2>Unavailable periods</h2>
        </div>
        <span class="muted">Holidays, leave, meetings, or other exceptions</span>
    </div>

    <div class="two-column availability-layout">
        <div>
            <?php if (!$blockedTimes): ?>
                <div class="empty-state"><strong>No blocked periods.</strong><p>Add an exception when a business or consultant should not be bookable.</p></div>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>Scope</th>
                            <th>Starts</th>
                            <th>Ends</th>
                            <th>Reason</th>
                            <th>Action</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($blockedTimes as $blocked): ?>
                            <?php
                            $blockedStart = new DateTimeImmutable((string)$blocked['starts_at'], $timezone);
                            $blockedEnd = new DateTimeImmutable((string)$blocked['ends_at'], $timezone);
                            $scopeLabel = $blocked['user_id'] === null
                                ? 'Entire business'
                                : trim((string)$blocked['first_name'] . ' ' . (string)$blocked['last_name']);
                            ?>
                            <tr>
                                <td><?= h($scopeLabel) ?></td>
                                <td><?= h($blockedStart->format('M j, Y g:i A')) ?></td>
                                <td><?= h($blockedEnd->format('M j, Y g:i A')) ?></td>
                                <td><?= h($blocked['reason'] ?: '—') ?></td>
                                <td>
                                    <form method="post" class="inline-form" onsubmit="return confirm('Remove this blocked period?');">
                                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                        <input type="hidden" name="action" value="delete_blocked">
                                        <input type="hidden" name="blocked_id" value="<?= (int)$blocked['id'] ?>">
                                        <button class="button small danger" type="submit">Remove</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div>
            <form method="post" class="form-stack">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="add_blocked">

                <label>
                    Applies to
                    <select name="scope" id="blocked-scope" required>
                        <option value="business">Entire business</option>
                        <option value="consultant">Specific consultant</option>
                    </select>
                </label>

                <label id="consultant-blocked-field" class="hidden-field">
                    Consultant
                    <select name="user_id">
                        <option value="">Select consultant</option>
                        <?php foreach ($consultants as $consultant): ?>
                            <option value="<?= (int)$consultant['id'] ?>">
                                <?= h($consultant['first_name'] . ' ' . $consultant['last_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    Date
                    <input type="date" name="blocked_date" min="<?= h((new DateTimeImmutable('now', $timezone))->format('Y-m-d')) ?>" required>
                </label>

                <div class="form-grid form-grid-compact">
                    <label>
                        Start time
                        <input type="time" name="blocked_start_time" value="09:00" required>
                    </label>
                    <label>
                        End time
                        <input type="time" name="blocked_end_time" value="17:00" required>
                    </label>
                </div>

                <label>
                    Reason
                    <input name="reason" maxlength="255" placeholder="Holiday, meeting, leave, etc.">
                </label>

                <button class="button" type="submit">Block this period</button>
            </form>
        </div>
    </div>
</section>

<script>
(function () {
    function bindScope(scopeId, fieldId) {
        const scope = document.getElementById(scopeId);
        const field = document.getElementById(fieldId);
        if (!scope || !field) return;
        const select = field.querySelector('select');
        const update = () => {
            const consultant = scope.value === 'consultant';
            field.classList.toggle('hidden-field', !consultant);
            if (select) select.required = consultant;
        };
        scope.addEventListener('change', update);
        update();
    }

    bindScope('availability-scope', 'consultant-rule-field');
    bindScope('blocked-scope', 'consultant-blocked-field');
})();
</script>
<?php require __DIR__ . '/../app/views/layouts/footer.php'; ?>
