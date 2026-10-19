<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $relative) use ($root): string {
    $path = $root . $relative;
    if (!is_file($path)) {
        throw new RuntimeException('Missing required Milestone 8 file: ' . $relative);
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

$expect('AppointmentService', $read('/app/services/AppointmentService.php'), [
    'function complete', 'function markNoShow', 'function overviewForEngagement',
    'A consultation cannot be completed before its scheduled start time.',
    'appointment.completed', 'appointment.no_show', 'FOR UPDATE', 'latest_completed',
]);
$expect('PreparationService', $read('/app/services/PreparationService.php'), [
    'function ensureDefaults', 'preparation.seeded', 'function summarize', 'function setCompleted',
    'preparation.ready', 'Review client inquiry', 'Review intake responses',
    'Prepare consultation questions', 'Review submitted information',
]);
$expect('MeetingNoteService', $read('/app/services/MeetingNoteService.php'), [
    'function canRecord', 'function save', 'meeting.notes_saved', 'meeting.notes_updated',
    'Summary', 'Problem', 'Requirements', 'Next step',
]);
$expect('FollowUpService', $read('/app/services/FollowUpService.php'), [
    'TYPE_LABELS', 'function listForEngagement', 'function create', 'function update',
    'function complete', 'function cancel', 'follow_up.created', 'follow_up.completed',
    'engagement.follow_up', 'function overdueCount',
]);

$workflow = $read('/public/engagement_workflow.php');
$expect('Workflow endpoint', $workflow, [
    'requireLogin', 'verifyCsrf', 'requireEngagementAccess',
    'prep_add', 'prep_complete', 'prep_reopen', 'notes_save',
    'appt_complete', 'appt_no_show', 'appt_cancel',
    'followup_add', 'followup_update', 'followup_complete', 'followup_cancel',
    'engagement_convert', 'engagement_close_lost',
]);
if (str_contains($workflow, 'requireOwner')) {
    throw new RuntimeException('The workflow endpoint must also be usable by the assigned consultant.');
}
if (str_contains($workflow, 'DELETE FROM engagements')) {
    throw new RuntimeException('The workflow must never delete an Engagement.');
}

$page = $read('/public/engagement.php');
$expect('Engagement page', $page, [
    'overviewForEngagement', 'latestAppointment', 'meetingAppointment', 'prepSummary',
    'engagement_outcome.php', 'engagement_preparation.php', 'engagement_meeting.php', 'engagement_followups.php',
    'id="appointment"', 'appointment_link.php',
]);

$expect('Preparation view', $read('/app/views/components/engagement_preparation.php'), ['id="preparation"', 'prep_add', 'prep_complete', 'prep_reopen']);
$expect('Meeting view', $read('/app/views/components/engagement_meeting.php'), ['id="meeting"', 'notes_save', 'next_step', 'requirements']);
$expect('Follow-up view', $read('/app/views/components/engagement_followups.php'), ['id="follow-up"', 'followup_add', 'followup_complete', 'engagement_convert', 'engagement_close_lost']);
$expect('Outcome view', $read('/app/views/components/engagement_outcome.php'), ['appt_complete', 'appt_no_show', 'appt_cancel', 'appointmentStarted']);

// Milestone 8 uses the existing tables; it must not need a schema change beyond the 16 migrations.
foreach (['009_create_preparation_tasks', '010_create_meeting_notes', '011_create_follow_ups'] as $migration) {
    $read('/database/migrations/' . $migration . '.sql');
}

echo "Milestone 8 static checks passed.\n";
