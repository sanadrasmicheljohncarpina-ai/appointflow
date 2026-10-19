<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$required = [
    'app/services/EngagementService.php',
    'app/services/IntakeService.php',
    'public/engagements.php',
    'public/engagement.php',
    'public/engagement_action.php',
    'public/clients.php',
    'public/client.php',
    'public_booking/inquiry.php',
    'public_booking/confirmation.php',
];
$missing = [];
foreach ($required as $file) { if (!is_file($root . '/' . $file)) $missing[] = $file; }
if ($missing) { fwrite(STDERR, "Missing: " . implode(', ', $missing) . PHP_EOL); exit(1); }
$engagement = file_get_contents($root . '/app/services/EngagementService.php');
$checks = [
    'Engagement transitions are centralized' => str_contains($engagement, 'TRANSITIONS'),
    'Client deduplication is workspace-scoped' => str_contains($engagement, 'LOWER(email) = LOWER(?)'),
    'Qualification validates active consultants' => str_contains($engagement, "role = \'consultant\' AND status = \'active\'"),
    'Engagement activity is recorded' => str_contains($engagement, 'engagement_events'),
];
foreach ($checks as $label => $ok) { if (!$ok) { fwrite(STDERR, "Failed: {$label}" . PHP_EOL); exit(1); } }

echo "Milestone 4 static checks passed." . PHP_EOL;
