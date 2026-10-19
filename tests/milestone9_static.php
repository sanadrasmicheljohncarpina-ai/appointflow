<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $relative) use ($root): string {
    $path = $root . $relative;
    if (!is_file($path)) {
        throw new RuntimeException('Missing required Milestone 9 file: ' . $relative);
    }
    $content = file_get_contents($path);
    if ($content === false) {
        throw new RuntimeException('Unable to read ' . $relative);
    }
    return $content;
};
$expect = static function (string $label, string $haystack, array $needles): void {
    foreach ($needles as $needle) {
        if (!str_contains($haystack, $needle)) {
            throw new RuntimeException($label . ' is missing expected logic: ' . $needle);
        }
    }
};
$forbid = static function (string $label, string $haystack, array $needles): void {
    foreach ($needles as $needle) {
        if (str_contains($haystack, $needle)) {
            throw new RuntimeException($label . ' must not contain: ' . $needle);
        }
    }
};

$ops = $read('/app/services/OperationsService.php');
$expect('OperationsService', $ops, [
    'function getDashboardSummary', 'function getUpcomingAppointments', 'function getTodayAppointments',
    'function getCalendarAppointments', 'function getPendingFollowUps', 'function getOverdueFollowUps',
    'function getTodayPreparationTasks', 'function getFollowUps', 'function getFollowUpCounts',
    'function getPreparationProgress', 'function getAwaitingOutcome', 'e.workspace_id', 'workspaceTimezone(',
    'FOLLOWUP_FILTERS',
]);
$forbid('OperationsService', $ops, ['NOW()', 'CURDATE()', 'INSERT INTO', 'UPDATE ', 'DELETE FROM']);

foreach (['/public/calendar.php', '/public/followups.php', '/public/dashboard.php', '/app/views/components/today_panel.php'] as $page) {
    $source = $read($page);
    $forbid(basename($page), $source, ['NOW()', 'CURDATE()']);
}

$calendar = $read('/public/calendar.php');
$expect('calendar.php', $calendar, ['requireLogin', 'OperationsService', "'week'", "'day'", 'engagement.php?id=', 'getCalendarAppointments', '$ops->timezone(', 'createFromFormat']);

$followups = $read('/public/followups.php');
$expect('followups.php', $followups, [
    'requireLogin', 'OperationsService', 'getFollowUps', 'getFollowUpCounts', 'consultation_action.php',
    'followup_complete', 'followup_update', 'followup_cancel', 'csrfToken()', 'Open engagement', 'name="return"',
]);

$dashboard = $read('/public/dashboard.php');
$expect('dashboard.php', $dashboard, ['OperationsService', 'getDashboardSummary', 'getUpcomingAppointments', 'getOverdueFollowUps', 'today_panel.php', 'NEEDS ATTENTION', 'UPCOMING']);

$action = $read('/public/consultation_action.php');
$expect('consultation_action.php', $action, ['$returnPages', "'followups' => 'public/followups.php'", "'dashboard' => 'public/dashboard.php'", 'FOLLOWUP_FILTERS', 'requireEngagementAccess', 'verifyCsrf']);
$forbid('consultation_action.php', $action, ["header('Location: ' . \$_POST", 'redirect($_POST']);

$header = $read('/app/views/layouts/header.php');
$expect('header.php', $header, ['public/calendar.php', 'public/followups.php']);

$migrations = glob($root . '/database/migrations/*.sql');
if ($migrations === false || count($migrations) !== 16) {
    throw new RuntimeException('Milestone 9 must not change the schema: expected 16 migrations, found ' . (is_array($migrations) ? count($migrations) : 0) . '.');
}

echo "Milestone 9 static checks passed.\n";
