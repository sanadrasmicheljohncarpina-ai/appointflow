<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$checks = [];

$requiredFiles = [
    'app/services/ServiceCatalogService.php',
    'app/services/AvailabilityService.php',
    'public/services.php',
    'public/availability.php',
];

foreach ($requiredFiles as $file) {
    $checks[$file . '_exists'] = is_file($root . '/' . $file);
}

$serviceSource = file_get_contents($root . '/app/services/ServiceCatalogService.php');
$availabilitySource = file_get_contents($root . '/app/services/AvailabilityService.php');
$servicesPage = file_get_contents($root . '/public/services.php');
$availabilityPage = file_get_contents($root . '/public/availability.php');
$headerSource = file_get_contents($root . '/app/views/layouts/header.php');

$checks['service_crud_methods'] =
    is_string($serviceSource)
    && str_contains($serviceSource, 'createService(')
    && str_contains($serviceSource, 'updateService(')
    && str_contains($serviceSource, 'setStatus(');

$checks['availability_crud_methods'] =
    is_string($availabilitySource)
    && str_contains($availabilitySource, 'addRule(')
    && str_contains($availabilitySource, 'setRuleStatus(')
    && str_contains($availabilitySource, 'deleteRule(')
    && str_contains($availabilitySource, 'addBlockedTime(')
    && str_contains($availabilitySource, 'deleteBlockedTime(');

$checks['workspace_scoping_present'] =
    is_string($serviceSource) && str_contains($serviceSource, 'workspace_id = ?')
    && is_string($availabilitySource) && substr_count($availabilitySource, 'workspace_id = ?') >= 5;

$checks['services_page_owner_only'] =
    is_string($servicesPage) && str_contains($servicesPage, 'requireOwner($currentUser)');
$checks['availability_page_owner_only'] =
    is_string($availabilityPage) && str_contains($availabilityPage, 'requireOwner($currentUser)');

$checks['owner_nav_has_services'] =
    is_string($headerSource) && str_contains($headerSource, "appUrl('public/services.php')");
$checks['owner_nav_has_availability'] =
    is_string($headerSource) && str_contains($headerSource, "appUrl('public/availability.php')");

$checks['non_negative_validator'] =
    str_contains((string)file_get_contents($root . '/app/validation.php'), 'function validateNonNegativeInt');

$migrations = glob($root . '/database/migrations/*.sql') ?: [];
$checks['core_migrations_present'] = count($migrations) >= 15;

$ok = !in_array(false, $checks, true);

echo json_encode(
    ['success' => $ok, 'checks' => $checks],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . PHP_EOL;

exit($ok ? 0 : 1);
