<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/database.php';
require_once __DIR__ . '/../app/security.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/validation.php';
require_once __DIR__ . '/../app/services/EngagementService.php';
require_once __DIR__ . '/../app/services/IntakeService.php';

$pdo = db();
$slug = trim((string)($_GET['business'] ?? $_POST['business'] ?? ''));
$serviceId = (int)($_GET['service'] ?? $_POST['service_id'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT id, name, email, phone, timezone, booking_slug, status
     FROM workspaces WHERE booking_slug = ? LIMIT 1'
);
$stmt->execute([$slug]);
$workspace = $stmt->fetch();
if (!$workspace || $workspace['status'] !== 'active') {
    http_response_code(404);
    exit('Booking page not found.');
}

$stmt = $pdo->prepare(
    'SELECT id, name, description, duration_minutes
     FROM services
     WHERE workspace_id = ? AND active = 1
     ORDER BY name ASC, id ASC'
);
$stmt->execute([(int)$workspace['id']]);
$services = $stmt->fetchAll();

$selectedService = null;
foreach ($services as $service) {
    if ((int)$service['id'] === $serviceId) {
        $selectedService = $service;
        break;
    }
}

$intakeService = new IntakeService($pdo);
$questions = $intakeService->listActiveQuestions((int)$workspace['id'], $selectedService ? $serviceId : null, 'inquiry');
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verifyCsrf($_POST['csrf_token'] ?? null);
        if (!$selectedService) {
            throw new InvalidArgumentException('Please select a consultation type.');
        }

        $firstName = validateRequired((string)($_POST['first_name'] ?? ''), 'First name');
        $lastName = validateRequired((string)($_POST['last_name'] ?? ''), 'Last name');
        $email = validateEmail((string)($_POST['email'] ?? ''));
        $phoneRaw = trim((string)($_POST['phone'] ?? ''));
        $companyRaw = trim((string)($_POST['company_name'] ?? ''));
        $problem = validateRequired((string)($_POST['problem_summary'] ?? ''), 'What do you need help with?');
        $additional = trim((string)($_POST['additional_information'] ?? ''));

        $postedAnswers = $_POST['intake'] ?? [];
        if (!is_array($postedAnswers)) {
            $postedAnswers = [];
        }

        foreach ($questions as $question) {
            $qid = (int)$question['id'];
            $raw = $postedAnswers[$qid] ?? '';
            $answer = is_array($raw) ? implode(', ', $raw) : trim((string)$raw);
            if ((int)$question['required'] === 1 && $answer === '') {
                throw new InvalidArgumentException('Please answer: ' . (string)$question['question']);
            }
        }

        $summary = $problem;
        if ($additional !== '') {
            $summary .= "\n\nAdditional information:\n" . $additional;
        }

        $engagementService = new EngagementService($pdo);
        $clientId = $engagementService->findOrCreateClient(
            (int)$workspace['id'],
            $firstName,
            $lastName,
            $email,
            $phoneRaw !== '' ? $phoneRaw : null,
            $companyRaw !== '' ? $companyRaw : null
        );
        $engagementId = $engagementService->create(
            (int)$workspace['id'],
            $clientId,
            $serviceId,
            $selectedService['name'] . ' inquiry',
            $summary,
            'website'
        );
        $intakeService->validateAndSaveAnswers($engagementId, $questions, $postedAnswers);

        $events = $pdo->prepare(
            'INSERT INTO engagement_events (engagement_id, actor_user_id, event_type, description)
             VALUES (?, NULL, \'inquiry.submitted\', \'Public inquiry submitted.\')'
        );
        $events->execute([$engagementId]);

        header('Location: ' . confirmationUrl((string)$workspace['booking_slug'], $engagementId));
        exit;
    } catch (InvalidArgumentException | RuntimeException $e) {
        $errors[] = $e->getMessage();
    } catch (Throwable $e) {
        error_log('[public inquiry] ' . $e->getMessage());
        $errors[] = 'We could not submit the inquiry. Please try again.';
    }
}

$csrf = csrfToken();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Request a consultation · <?= h($workspace['name']) ?></title>
    <link rel="stylesheet" href="<?= h(appUrl('assets/css/app.css')) ?>">
</head>
<body>
<header class="public-topbar">
    <div><strong><?= h($workspace['name']) ?></strong><small>Powered by AppointFlow</small></div>
    <a class="button small secondary" href="<?= h(bookingUrl((string)$workspace['booking_slug'])) ?>">Back to services</a>
</header>
<main class="page-shell">
    <section class="page-heading">
        <div>
            <p class="eyebrow">CONSULTATION REQUEST</p>
            <h1>Tell us what you need help with</h1>
            <p>This starts your consultation request. The business will review it before booking is offered.</p>
        </div>
    </section>

    <?php if ($errors): ?>
        <div class="error-box">
            <?php foreach ($errors as $error): ?><div><?= h($error) ?></div><?php endforeach; ?>
        </div>
    <?php endif; ?>

    <section class="two-column">
        <div class="content-card">
            <form method="post" class="form-stack">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="business" value="<?= h($slug) ?>">

                <label>
                    Consultation type
                    <select name="service_id" required>
                        <option value="">Select a consultation</option>
                        <?php foreach ($services as $service): ?>
                            <option value="<?= (int)$service['id'] ?>" <?= (int)$service['id'] === $serviceId ? 'selected' : '' ?>>
                                <?= h($service['name']) ?> · <?= (int)$service['duration_minutes'] ?> min
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <div class="form-grid">
                    <label>First name<input name="first_name" maxlength="100" autocomplete="given-name" value="<?= h($_POST['first_name'] ?? '') ?>" required></label>
                    <label>Last name<input name="last_name" maxlength="100" autocomplete="family-name" value="<?= h($_POST['last_name'] ?? '') ?>" required></label>
                    <label>Email<input type="email" name="email" maxlength="255" autocomplete="email" value="<?= h($_POST['email'] ?? '') ?>" required></label>
                    <label>Phone<input name="phone" maxlength="50" autocomplete="tel" value="<?= h($_POST['phone'] ?? '') ?>"></label>
                    <label>Company<input name="company_name" maxlength="150" autocomplete="organization" value="<?= h($_POST['company_name'] ?? '') ?>"></label>
                </div>

                <label>
                    What do you need help with?
                    <textarea name="problem_summary" rows="6" maxlength="10000" required><?= h($_POST['problem_summary'] ?? '') ?></textarea>
                </label>

                <label>
                    Additional information
                    <textarea name="additional_information" rows="4" maxlength="10000"><?= h($_POST['additional_information'] ?? '') ?></textarea>
                </label>

                <?php foreach ($questions as $question): ?>
                    <label>
                        <?= h($question['question']) ?><?= (int)$question['required'] === 1 ? ' *' : '' ?>
                        <?php $answer = $_POST['intake'][$question['id']] ?? ''; ?>
                        <?php if ($question['field_type'] === 'textarea'): ?>
                            <textarea name="intake[<?= (int)$question['id'] ?>]" rows="4" <?= (int)$question['required'] === 1 ? 'required' : '' ?>><?= h(is_array($answer) ? '' : $answer) ?></textarea>
                        <?php elseif ($question['field_type'] === 'select'): ?>
                            <select name="intake[<?= (int)$question['id'] ?>]" <?= (int)$question['required'] === 1 ? 'required' : '' ?>>
                                <option value="">Select an option</option>
                                <?php $options = json_decode((string)($question['options_json'] ?? '[]'), true); if (is_array($options)): foreach ($options as $option): ?>
                                    <option value="<?= h($option) ?>" <?= (string)$answer === (string)$option ? 'selected' : '' ?>><?= h($option) ?></option>
                                <?php endforeach; endif; ?>
                            </select>
                        <?php elseif ($question['field_type'] === 'checkbox'): ?>
                            <input type="checkbox" name="intake[<?= (int)$question['id'] ?>]" value="Yes" <?= ((string)$answer === 'Yes') ? 'checked' : '' ?>>
                        <?php else: ?>
                            <input name="intake[<?= (int)$question['id'] ?>]" value="<?= h(is_array($answer) ? '' : $answer) ?>" <?= (int)$question['required'] === 1 ? 'required' : '' ?>>
                        <?php endif; ?>
                    </label>
                <?php endforeach; ?>

                <button class="button wide" type="submit">Submit consultation request</button>
            </form>
        </div>

        <aside class="content-card">
            <div class="section-head"><div><p class="eyebrow">WHAT HAPPENS NEXT</p><h2>Review before booking</h2></div></div>
            <div class="info-list">
                <div><strong>1. Inquiry received</strong><span>Your request becomes a secure engagement record.</span></div>
                <div><strong>2. Business reviews it</strong><span>The owner can qualify or reject the request and assign a consultant.</span></div>
                <div><strong>3. Booking follows</strong><span>Once qualified, a later booking flow can attach an appointment to the same engagement.</span></div>
            </div>
        </aside>
    </section>
</main>
<footer class="site-footer">AppointFlow MVP · <?= date('Y') ?></footer>
</body>
</html>
