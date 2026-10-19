<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/database.php';
require_once __DIR__ . '/../app/security.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/permissions.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/validation.php';
require_once __DIR__ . '/../app/services/TeamService.php';

$pdo = db();
$currentUser = requireLogin($pdo);
requireOwner($currentUser);

$workspaceId = (int)$currentUser['workspace_id'];
$teamService = new TeamService($pdo);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verifyCsrf($_POST['csrf_token'] ?? null);

        $action = (string)($_POST['action'] ?? '');

        if ($action === 'add_consultant') {
            $first = validateRequired((string)($_POST['first_name'] ?? ''), 'First name');
            $last = validateRequired((string)($_POST['last_name'] ?? ''), 'Last name');
            $email = validateEmail((string)($_POST['email'] ?? ''));
            $password = validatePassword((string)($_POST['password'] ?? ''));

            $teamService->createConsultant(
                $workspaceId,
                $first,
                $last,
                $email,
                $password
            );

            flash('success', 'Consultant account created.');
        } elseif ($action === 'edit_consultant') {
            $consultantId = validatePositiveInt(
                $_POST['consultant_id'] ?? null,
                'Consultant'
            );
            $first = validateRequired((string)($_POST['first_name'] ?? ''), 'First name');
            $last = validateRequired((string)($_POST['last_name'] ?? ''), 'Last name');
            $email = validateEmail((string)($_POST['email'] ?? ''));
            $passwordRaw = (string)($_POST['password'] ?? '');
            $password = $passwordRaw === '' ? null : validatePassword($passwordRaw);

            $teamService->updateConsultant(
                $workspaceId,
                $consultantId,
                $first,
                $last,
                $email,
                $password
            );

            flash('success', 'Consultant account updated.');
        } elseif ($action === 'set_status') {
            $consultantId = validatePositiveInt(
                $_POST['consultant_id'] ?? null,
                'Consultant'
            );
            $status = (string)($_POST['status'] ?? '');

            $teamService->setStatus($workspaceId, $consultantId, $status);

            flash(
                'success',
                $status === 'active'
                    ? 'Consultant account activated.'
                    : 'Consultant account deactivated.'
            );
        } else {
            throw new InvalidArgumentException('Unknown team action.');
        }

        redirect(appUrl('public/team.php'));
    } catch (InvalidArgumentException | RuntimeException $e) {
        $errors[] = $e->getMessage();
    } catch (Throwable $e) {
        error_log('[team] ' . $e->getMessage());
        $errors[] = 'Unable to complete that team action.';
    }
}

$members = $teamService->listMembers($workspaceId);
$consultants = array_values(
    array_filter(
        $members,
        static fn(array $member): bool => $member['role'] === 'consultant'
    )
);

$success = flash('success');
$csrf = csrfToken();

$pageTitle = 'Team';
require __DIR__ . '/../app/views/layouts/header.php';
?>
<section class="page-heading">
    <div>
        <p class="eyebrow">TEAM</p>
        <h1>People</h1>
        <p>Manage consultants who can be assigned to client engagements and consultations.</p>
    </div>
    <span class="role-pill"><?= count($consultants) ?> consultant<?= count($consultants) === 1 ? '' : 's' ?></span>
</section>

<?php if ($success): ?>
    <div class="success-box"><?= h($success) ?></div>
<?php endif; ?>

<?php if ($errors): ?>
    <div class="error-box">
        <?php foreach ($errors as $errorMessage): ?>
            <div><?= h($errorMessage) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<section class="content-card">
    <div class="section-head">
        <div>
            <p class="eyebrow">TEAM DIRECTORY</p>
            <h2>Workspace members</h2>
        </div>
        <span class="muted">Owner access is managed by the initial workspace setup.</span>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Last login</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($members as $member): ?>
                <tr>
                    <td>
                        <strong><?= h($member['first_name'] . ' ' . $member['last_name']) ?></strong>
                        <?php if ($member['role'] === 'owner' && (int)$member['id'] === (int)$currentUser['id']): ?>
                            <div class="table-subtle">Current account</div>
                        <?php endif; ?>
                    </td>
                    <td><?= h($member['email']) ?></td>
                    <td><?= h(ucfirst($member['role'])) ?></td>
                    <td>
                        <span class="status-badge status-<?= h($member['status']) ?>">
                            <?= h(ucfirst($member['status'])) ?>
                        </span>
                    </td>
                    <td><?= h($member['last_login_at'] ?: '—') ?></td>
                    <td>
                        <?php if ($member['role'] === 'consultant'): ?>
                            <div class="action-group">
                                <a
                                    class="button small secondary"
                                    href="#edit-consultant-<?= (int)$member['id'] ?>"
                                >Edit</a>

                                <form method="post" class="inline-form">
                                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                    <input type="hidden" name="action" value="set_status">
                                    <input type="hidden" name="consultant_id" value="<?= (int)$member['id'] ?>">
                                    <input
                                        type="hidden"
                                        name="status"
                                        value="<?= $member['status'] === 'active' ? 'inactive' : 'active' ?>"
                                    >
                                    <button class="button small <?= $member['status'] === 'active' ? 'danger' : '' ?>" type="submit">
                                        <?= $member['status'] === 'active' ? 'Deactivate' : 'Activate' ?>
                                    </button>
                                </form>
                            </div>
                        <?php else: ?>
                            <span class="muted">Owner</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>

            <?php if (!$members): ?>
                <tr>
                    <td colspan="6" class="table-empty">No workspace members found.</td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="two-column">
    <div class="content-card">
        <div class="section-head">
            <div>
                <p class="eyebrow">ADD</p>
                <h2>Add consultant</h2>
            </div>
        </div>

        <form method="post" class="form-stack">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="add_consultant">

            <label>
                First name
                <input name="first_name" maxlength="100" autocomplete="given-name" required>
            </label>

            <label>
                Last name
                <input name="last_name" maxlength="100" autocomplete="family-name" required>
            </label>

            <label>
                Email
                <input type="email" name="email" maxlength="255" autocomplete="email" required>
            </label>

            <label>
                Temporary password
                <input type="password" name="password" minlength="8" autocomplete="new-password" required>
                <span class="field-help">The consultant can use this account after it is created. Password recovery is outside this milestone.</span>
            </label>

            <button class="button" type="submit">Create consultant</button>
        </form>
    </div>

    <div class="content-card">
        <div class="section-head">
            <div>
                <p class="eyebrow">RULES</p>
                <h2>Team access</h2>
            </div>
        </div>

        <div class="info-list">
            <div><strong>Owner</strong><span>Full workspace and team control.</span></div>
            <div><strong>Consultant</strong><span>Designed for assigned engagements and consultations.</span></div>
            <div><strong>Inactive</strong><span>Cannot sign in until reactivated.</span></div>
        </div>
    </div>
</section>

<?php foreach ($consultants as $consultant): ?>
    <section class="content-card edit-card" id="edit-consultant-<?= (int)$consultant['id'] ?>">
        <div class="section-head">
            <div>
                <p class="eyebrow">EDIT CONSULTANT</p>
                <h2><?= h($consultant['first_name'] . ' ' . $consultant['last_name']) ?></h2>
                <span class="muted">Account status: <?= h(ucfirst($consultant['status'])) ?></span>
            </div>
        </div>

        <form method="post" class="form-grid">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="edit_consultant">
            <input type="hidden" name="consultant_id" value="<?= (int)$consultant['id'] ?>">

            <label>
                First name
                <input
                    name="first_name"
                    value="<?= h($consultant['first_name']) ?>"
                    maxlength="100"
                    required
                >
            </label>

            <label>
                Last name
                <input
                    name="last_name"
                    value="<?= h($consultant['last_name']) ?>"
                    maxlength="100"
                    required
                >
            </label>

            <label>
                Email
                <input
                    type="email"
                    name="email"
                    value="<?= h($consultant['email']) ?>"
                    maxlength="255"
                    required
                >
            </label>

            <label>
                New password
                <input
                    type="password"
                    name="password"
                    minlength="8"
                    autocomplete="new-password"
                >
                <span class="field-help">Leave blank to keep the current password.</span>
            </label>

            <div class="form-actions">
                <a class="button secondary" href="<?= h(appUrl('public/team.php')) ?>">Cancel</a>
                <button class="button" type="submit">Save consultant</button>
            </div>
        </form>
    </section>
<?php endforeach; ?>

<?php require __DIR__ . '/../app/views/layouts/footer.php'; ?>
