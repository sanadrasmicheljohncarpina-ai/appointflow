<?php
declare(strict_types=1);

function validateRequired(string $value, string $label): string
{
    $value = trim($value);
    if ($value === '') {
        throw new InvalidArgumentException($label . ' is required.');
    }
    return $value;
}

function validateEmail(string $email): string
{
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Please enter a valid email address.');
    }
    return $email;
}

function validatePassword(string $password): string
{
    if (strlen($password) < 8) {
        throw new InvalidArgumentException('Password must be at least 8 characters.');
    }
    return $password;
}

function validatePositiveInt(mixed $value, string $label): int
{
    $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($number === false) {
        throw new InvalidArgumentException($label . ' must be a positive whole number.');
    }
    return (int)$number;
}


function validateNonNegativeInt(mixed $value, string $label): int
{
    $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    if ($number === false) {
        throw new InvalidArgumentException($label . ' must be a whole number of zero or greater.');
    }
    return (int)$number;
}


function validateTimezone(string $timezone): string
{
    $timezone = trim($timezone);
    if ($timezone === '' || !in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
        throw new InvalidArgumentException('Please select a valid timezone.');
    }
    return $timezone;
}
