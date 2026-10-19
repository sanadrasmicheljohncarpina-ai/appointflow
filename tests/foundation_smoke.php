<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/security.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/permissions.php';

$checks = [
    'app_name' => APP_NAME === 'AppointFlow',
    'timezone' => appTimezone()->getName() === 'Asia/Manila',
    'token_length' => strlen(generateSecureToken(32)) > 30,
];

$ok = !in_array(false, $checks, true);
echo json_encode(['success' => $ok, 'checks' => $checks], JSON_PRETTY_PRINT) . PHP_EOL;
exit($ok ? 0 : 1);
