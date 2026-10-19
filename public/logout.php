<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/auth.php';
logoutUser();
header('Location: ' . appUrl('public/login.php'));
exit;
