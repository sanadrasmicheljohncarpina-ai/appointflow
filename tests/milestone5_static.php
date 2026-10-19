<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$requiredFiles = [
    '/app/services/BookingService.php',
    '/public/booking.php',
];

foreach ($requiredFiles as $relative) {
    if (!is_file($root . $relative)) {
        throw new RuntimeException('Missing required Milestone 5 file: ' . $relative);
    }
}

$booking = file_get_contents($root . '/app/services/BookingService.php');
if ($booking === false) {
    throw new RuntimeException('Unable to read BookingService.php.');
}

foreach ([
    'getAvailableSlots',
    'book(',
    'hasConflict',
    'GET_LOCK',
    'appointment.booked',
    "status = \'scheduled\'",
    'buffer_before_minutes',
    'buffer_after_minutes',
    'availability_rules',
    'blocked_times',
] as $needle) {
    if (!str_contains($booking, $needle)) {
        throw new RuntimeException('BookingService is missing expected logic: ' . $needle);
    }
}

$bookingPage = file_get_contents($root . '/public/booking.php');
if ($bookingPage === false || !str_contains($bookingPage, 'Schedule consultation') || !str_contains($bookingPage, 'getAvailableSlots')) {
    throw new RuntimeException('Internal booking page is missing expected booking UI/logic.');
}

$engagement = file_get_contents($root . '/public/engagement.php');
if ($engagement === false || !str_contains($engagement, 'public/booking.php?engagement=')) {
    throw new RuntimeException('Engagement detail does not expose the booking action.');
}

$migrations = glob($root . '/database/migrations/*.sql');
if ($migrations === false || count($migrations) < 15) {
    throw new RuntimeException('Expected at least the 15 core migrations; found ' . (is_array($migrations) ? count($migrations) : 0) . '.');
}
sort($migrations, SORT_STRING);
foreach (array_values($migrations) as $index => $migration) {
    if ((int)substr(basename($migration), 0, 3) !== $index + 1) {
        throw new RuntimeException('Migrations must be numbered consecutively from 001; problem at ' . basename($migration) . '.');
    }
}

foreach ($migrations as $migration) {
    $name = basename($migration);
    if (!preg_match('/^\d{3}_[a-z0-9_]+\.sql$/', $name)) {
        throw new RuntimeException('Unexpected migration filename: ' . $name);
    }
}

echo "Milestone 5 static checks passed.\n";
