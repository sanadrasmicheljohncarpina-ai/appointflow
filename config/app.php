<?php
declare(strict_types=1);

const APP_NAME = 'AppointFlow';
const APP_TIMEZONE_DEFAULT = 'Asia/Manila';
const APP_DB_HOST = '127.0.0.1';
const APP_DB_NAME = 'appointflow';
const APP_DB_USER = 'root';
const APP_DB_PASSWORD = '';
const APP_DB_CHARSET = 'utf8mb4';
const APP_BASE_PATH = '/appointflow';

/*
 * Public address of this installation (scheme + host only, no trailing slash, no path).
 * Leave blank while developing on localhost: links are then built from the browser's host.
 * Set it once the app is online so the booking link, QR code and emailed links always use it:
 *   const APP_PUBLIC_URL = 'https://book.northstardigital.com';
 * If the app is not served from the domain root, also update APP_BASE_PATH above
 * (e.g. '' when it lives at the root of the domain).
 * Reminder emails sent from cron use 'base_url' in config/mail.php, so keep the two in step.
 */
const APP_PUBLIC_URL = '';

/*
 * What happens when a client uses "Find my appointments" on the public booking page:
 *   'owner' (default) - the request is saved, the business owner is emailed and sees it on the
 *                       engagement page, then generates and sends the private link.
 *   'auto'            - the private link is emailed to the client straight away (no owner step).
 */
const APP_LINK_REQUEST_MODE = 'owner';
