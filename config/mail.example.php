<?php
declare(strict_types=1);

/**
 * Copy this file to config/mail.php and edit it. config/mail.php is never overwritten by updates.
 *
 * driver 'log'  : (default) nothing is sent; each email is saved as an HTML file in storage/mail/
 *                 so you can open it in a browser and click the links. Ideal for local testing.
 * driver 'smtp' : sends real email through your SMTP provider.
 *
 * Gmail example: host smtp.gmail.com, port 587, encryption 'tls', username = your Gmail address,
 * password = a Google "App password" (not your normal password), from_email = the same Gmail address.
 */
return [
    'driver'      => 'log',

    'host'        => 'smtp.gmail.com',
    'port'        => 587,
    'encryption'  => 'tls',          // 'tls' (STARTTLS, port 587), 'ssl' (port 465) or 'none'
    'username'    => '',
    'password'    => '',
    'from_email'  => '',             // must normally match the authenticated account
    'from_name'   => '',             // blank = the business name
    'timeout'     => 15,
    'verify_peer' => true,           // set false ONLY on a local test machine whose PHP lacks CA certificates

    // Origin used for links in emails sent from the command line (reminders): no trailing slash.
    'base_url'    => 'http://localhost',
];
