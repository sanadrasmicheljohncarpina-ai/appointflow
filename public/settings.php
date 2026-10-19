<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/database.php';
require_once __DIR__ . '/../app/security.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/permissions.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/validation.php';
require_once __DIR__ . '/../app/services/WorkspaceService.php';

$pdo = db();
$currentUser = requireLogin($pdo);
requireOwner($currentUser);

$workspaceService = new WorkspaceService($pdo);
$workspaceId = (int)$currentUser['workspace_id'];
$workspace = $workspaceService->get($workspaceId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verifyCsrf($_POST['csrf_token'] ?? null);

        $name = validateRequired((string)($_POST['name'] ?? ''), 'Business name');
        $email = validateEmail((string)($_POST['email'] ?? ''));
        $phoneRaw = trim((string)($_POST['phone'] ?? ''));
        $phone = $phoneRaw !== '' ? $phoneRaw : null;
        $timezone = validateTimezone((string)($_POST['timezone'] ?? ''));

        $workspaceService->updateProfile(
            $workspaceId,
            $name,
            $email,
            $phone,
            $timezone
        );

        flash('success', 'Business settings updated.');
        redirect(appUrl('public/settings.php'));
    } catch (InvalidArgumentException $e) {
        flash('error', $e->getMessage());
    } catch (Throwable $e) {
        error_log('[settings] ' . $e->getMessage());
        flash('error', 'Unable to update business settings.');
    }

    $workspace = $workspaceService->get($workspaceId);
}

$success = flash('success');
$error = flash('error');
$timezones = DateTimeZone::listIdentifiers();

$pageTitle = 'Settings';
require __DIR__ . '/../app/views/layouts/header.php';
?>
<section class="page-heading">
    <div>
        <p class="eyebrow">WORKSPACE</p>
        <h1>Business settings</h1>
        <p>Configure the business identity and timezone used by this AppointFlow workspace.</p>
    </div>
</section>

<section class="content-card form-card">
    <div class="form-width">
        <?php if ($success): ?>
            <div class="success-box"><?= h($success) ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="error-box"><?= h($error) ?></div>
        <?php endif; ?>

        <form method="post" class="form-stack">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">

            <label>
                Business name
                <input
                    name="name"
                    value="<?= h($workspace['name']) ?>"
                    maxlength="150"
                    autocomplete="organization"
                    required
                >
            </label>

            <label>
                Business email
                <input
                    type="email"
                    name="email"
                    value="<?= h($workspace['email']) ?>"
                    maxlength="255"
                    autocomplete="email"
                    required
                >
            </label>

            <label>
                Phone
                <input
                    name="phone"
                    value="<?= h($workspace['phone'] ?? '') ?>"
                    maxlength="50"
                    autocomplete="tel"
                >
            </label>

            <label>
                Timezone
                <select name="timezone" required>
                    <?php foreach ($timezones as $timezone): ?>
                        <option
                            value="<?= h($timezone) ?>"
                            <?= $workspace['timezone'] === $timezone ? 'selected' : '' ?>
                        >
                            <?= h($timezone) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="field-help">This timezone will be used by future availability and booking calculations.</span>
            </label>

            <label>
                Booking slug
                <input value="<?= h($workspace['booking_slug']) ?>" disabled>
                <span class="field-help">This is the unique name in your public booking link.</span>
            </label>

            <label>
                Public booking link
                <input id="booking-link" readonly value="<?= h(absoluteUrl(bookingUrl((string)$workspace['booking_slug']))) ?>" onclick="this.select()">
                <span class="field-help">Share this link on your website, email signature or social profiles.</span>
            </label>
            <div>
                <button class="button secondary" type="button" onclick="(function(b){var i=document.getElementById('booking-link');i.select();var done=function(){b.textContent='Copied!';setTimeout(function(){b.textContent='Copy booking link'},1800)};if(navigator.clipboard&&window.isSecureContext){navigator.clipboard.writeText(i.value).then(done)}else{document.execCommand('copy');done()}})(this)">Copy booking link</button>
                <a class="button secondary" href="<?= h(bookingUrl((string)$workspace['booking_slug'])) ?>" target="_blank" rel="noopener">Open page</a>
            </div>

            <button class="button" type="submit">Save business settings</button>
        </form>
    </div>
</section>
<?php require __DIR__ . '/../app/views/layouts/footer.php'; ?>
