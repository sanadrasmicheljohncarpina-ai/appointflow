<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/database.php';
require_once __DIR__ . '/../app/security.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/permissions.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/validation.php';
require_once __DIR__ . '/../app/services/ServiceCatalogService.php';

$pdo = db();
$currentUser = requireLogin($pdo);
requireOwner($currentUser);

$workspaceId = (int)$currentUser['workspace_id'];
$serviceCatalog = new ServiceCatalogService($pdo);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verifyCsrf($_POST['csrf_token'] ?? null);
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'create') {
            $name = validateRequired((string)($_POST['name'] ?? ''), 'Service name');
            $descriptionRaw = trim((string)($_POST['description'] ?? ''));
            $description = $descriptionRaw !== '' ? $descriptionRaw : null;
            $duration = validatePositiveInt($_POST['duration_minutes'] ?? null, 'Duration');
            $bufferBefore = validateNonNegativeInt($_POST['buffer_before_minutes'] ?? null, 'Buffer before');
            $bufferAfter = validateNonNegativeInt($_POST['buffer_after_minutes'] ?? null, 'Buffer after');

            $serviceCatalog->createService(
                $workspaceId,
                $name,
                $description,
                $duration,
                $bufferBefore,
                $bufferAfter
            );
            flash('success', 'Service created.');
            redirect(appUrl('public/services.php'));
        }

        if ($action === 'edit') {
            $serviceId = validatePositiveInt($_POST['service_id'] ?? null, 'Service');
            $name = validateRequired((string)($_POST['name'] ?? ''), 'Service name');
            $descriptionRaw = trim((string)($_POST['description'] ?? ''));
            $description = $descriptionRaw !== '' ? $descriptionRaw : null;
            $duration = validatePositiveInt($_POST['duration_minutes'] ?? null, 'Duration');
            $bufferBefore = validateNonNegativeInt($_POST['buffer_before_minutes'] ?? null, 'Buffer before');
            $bufferAfter = validateNonNegativeInt($_POST['buffer_after_minutes'] ?? null, 'Buffer after');

            $serviceCatalog->updateService(
                $workspaceId,
                $serviceId,
                $name,
                $description,
                $duration,
                $bufferBefore,
                $bufferAfter
            );
            flash('success', 'Service updated.');
            redirect(appUrl('public/services.php'));
        }

        if ($action === 'set_status') {
            $serviceId = validatePositiveInt($_POST['service_id'] ?? null, 'Service');
            $active = ($_POST['active'] ?? '0') === '1';
            $serviceCatalog->setStatus($workspaceId, $serviceId, $active);
            flash('success', $active ? 'Service activated.' : 'Service deactivated.');
            redirect(appUrl('public/services.php'));
        }

        throw new InvalidArgumentException('Unknown service action.');
    } catch (InvalidArgumentException | RuntimeException $e) {
        $errors[] = $e->getMessage();
    } catch (Throwable $e) {
        error_log('[services] ' . $e->getMessage());
        $errors[] = 'Unable to complete that service action.';
    }
}

$services = $serviceCatalog->listServices($workspaceId);
$activeCount = count(array_filter($services, static fn(array $service): bool => (int)$service['active'] === 1));
$success = flash('success');
$csrf = csrfToken();

$pageTitle = 'Services';
require __DIR__ . '/../app/views/layouts/header.php';
?>
<section class="page-heading">
    <div>
        <p class="eyebrow">SERVICES</p>
        <h1>Consultation services</h1>
        <p>Define the consultation types clients can request, including duration and scheduling buffers.</p>
    </div>
    <span class="role-pill"><?= $activeCount ?> active service<?= $activeCount === 1 ? '' : 's' ?></span>
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

<section class="two-column services-layout">
    <div class="content-card">
        <div class="section-head">
            <div>
                <p class="eyebrow">CATALOG</p>
                <h2>Your services</h2>
            </div>
            <span class="muted">Only active services can be offered for booking.</span>
        </div>

        <?php if (!$services): ?>
            <div class="empty-state">
                <strong>No services yet.</strong>
                <p>Create your first consultation type using the form.</p>
            </div>
        <?php else: ?>
            <div class="service-list">
                <?php foreach ($services as $service): ?>
                    <article class="service-card">
                        <div class="service-card-head">
                            <div>
                                <h3><?= h($service['name']) ?></h3>
                                <p class="service-meta">
                                    <?= (int)$service['duration_minutes'] ?> min
                                    <?php if ((int)$service['buffer_before_minutes'] > 0): ?>
                                        · <?= (int)$service['buffer_before_minutes'] ?> min before buffer
                                    <?php endif; ?>
                                    <?php if ((int)$service['buffer_after_minutes'] > 0): ?>
                                        · <?= (int)$service['buffer_after_minutes'] ?> min after buffer
                                    <?php endif; ?>
                                </p>
                            </div>
                            <span class="status-badge <?= (int)$service['active'] === 1 ? 'status-active' : 'status-inactive' ?>">
                                <?= (int)$service['active'] === 1 ? 'Active' : 'Inactive' ?>
                            </span>
                        </div>

                        <p class="service-description"><?= h($service['description'] ?: 'No description added.') ?></p>

                        <div class="action-group">
                            <a class="button small secondary" href="#edit-service-<?= (int)$service['id'] ?>">Edit</a>
                            <form method="post" class="inline-form">
                                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                <input type="hidden" name="action" value="set_status">
                                <input type="hidden" name="service_id" value="<?= (int)$service['id'] ?>">
                                <input type="hidden" name="active" value="<?= (int)$service['active'] === 1 ? '0' : '1' ?>">
                                <button class="button small <?= (int)$service['active'] === 1 ? 'danger' : '' ?>" type="submit">
                                    <?= (int)$service['active'] === 1 ? 'Deactivate' : 'Activate' ?>
                                </button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="content-card">
        <div class="section-head">
            <div>
                <p class="eyebrow">ADD</p>
                <h2>New service</h2>
            </div>
        </div>

        <form method="post" class="form-stack">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="create">

            <label>
                Service name
                <input name="name" maxlength="150" required placeholder="Discovery Consultation">
            </label>

            <label>
                Description
                <textarea name="description" rows="4" maxlength="4000" placeholder="What the client can expect from this consultation."></textarea>
            </label>

            <label>
                Duration (minutes)
                <input type="number" name="duration_minutes" min="1" max="1440" value="30" required>
            </label>

            <div class="form-grid form-grid-compact">
                <label>
                    Buffer before
                    <input type="number" name="buffer_before_minutes" min="0" max="1440" value="0" required>
                </label>
                <label>
                    Buffer after
                    <input type="number" name="buffer_after_minutes" min="0" max="1440" value="0" required>
                </label>
            </div>

            <button class="button" type="submit">Create service</button>
        </form>
    </div>
</section>

<?php foreach ($services as $service): ?>
    <section class="content-card edit-card" id="edit-service-<?= (int)$service['id'] ?>">
        <div class="section-head">
            <div>
                <p class="eyebrow">EDIT SERVICE</p>
                <h2><?= h($service['name']) ?></h2>
                <span class="muted">Service status: <?= (int)$service['active'] === 1 ? 'Active' : 'Inactive' ?></span>
            </div>
        </div>

        <form method="post" class="form-grid">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="service_id" value="<?= (int)$service['id'] ?>">

            <label>
                Service name
                <input name="name" value="<?= h($service['name']) ?>" maxlength="150" required>
            </label>

            <label>
                Duration (minutes)
                <input type="number" name="duration_minutes" min="1" max="1440" value="<?= (int)$service['duration_minutes'] ?>" required>
            </label>

            <label>
                Description
                <textarea name="description" rows="5" maxlength="4000"><?= h($service['description'] ?? '') ?></textarea>
            </label>

            <div class="form-grid form-grid-compact">
                <label>
                    Buffer before
                    <input type="number" name="buffer_before_minutes" min="0" max="1440" value="<?= (int)$service['buffer_before_minutes'] ?>" required>
                </label>
                <label>
                    Buffer after
                    <input type="number" name="buffer_after_minutes" min="0" max="1440" value="<?= (int)$service['buffer_after_minutes'] ?>" required>
                </label>
            </div>

            <div class="form-actions">
                <a class="button secondary" href="<?= h(appUrl('public/services.php')) ?>">Cancel</a>
                <button class="button" type="submit">Save service</button>
            </div>
        </form>
    </section>
<?php endforeach; ?>
<?php require __DIR__ . '/../app/views/layouts/footer.php'; ?>
