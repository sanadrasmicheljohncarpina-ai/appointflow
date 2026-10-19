<?php
declare(strict_types=1);

$root = dirname(__DIR__);

$requiredFiles = [
    'app/services/WorkspaceService.php',
    'app/services/TeamService.php',
    'public/settings.php',
    'public/team.php',
];

$checks = [];
foreach ($requiredFiles as $file) {
    $checks[$file . '_exists'] = is_file($root . '/' . $file);
}

require_once $root . '/app/validation.php';
$checks['timezone_validation_accepts_Asia_Manila'] =
    validateTimezone('Asia/Manila') === 'Asia/Manila';

try {
    validateTimezone('Not/A_Timezone');
    $checks['timezone_validation_rejects_invalid'] = false;
} catch (InvalidArgumentException) {
    $checks['timezone_validation_rejects_invalid'] = true;
}

$settingsSource = file_get_contents($root . '/public/settings.php');
$teamSource = file_get_contents($root . '/public/team.php');
$workspaceSource = file_get_contents($root . '/app/services/WorkspaceService.php');
$teamServiceSource = file_get_contents($root . '/app/services/TeamService.php');

$checks['settings_persists_timezone'] =
    is_string($settingsSource) && str_contains($settingsSource, "name=\"timezone\"");
$checks['team_has_edit_action'] =
    is_string($teamSource) && str_contains($teamSource, "edit_consultant");
$checks['team_has_status_action'] =
    is_string($teamSource) && str_contains($teamSource, "set_status");
$checks['workspace_service_updates_timezone'] =
    is_string($workspaceSource) && str_contains($workspaceSource, "SET name = ?, email = ?, phone = ?, timezone = ?");
$checks['team_service_scopes_consultants'] =
    is_string($teamServiceSource)
    && substr_count($teamServiceSource, "consultant") >= 3
    && substr_count($teamServiceSource, "workspace_id = ?") >= 3;

$ok = !in_array(false, $checks, true);

echo json_encode(
    ['success' => $ok, 'checks' => $checks],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . PHP_EOL;

exit($ok ? 0 : 1);
