# AppointFlow — Milestone 7 Notes (email delivery)

No schema changes (still 15 migrations).

## Included
- `MailService`: `log` driver (default, saves each email to `storage/mail/*.html`, nothing is sent) and `smtp` driver (STARTTLS/SSL + AUTH LOGIN, no libraries). Config: copy `config/mail.example.php` to `config/mail.php`.
- `NotificationService::dispatch()`: builds the email, sends/saves it, marks the `notification_log` row `sent` or `failed` (with error text), sets `confirmation_sent_at` / `reminder_sent_at`. It never throws, so a mail problem cannot break booking.
- Emails: confirmation (with private link), reschedule, cancellation, reminder, and "your private link".
- Hooks: staff booking → confirmation; client reschedule → reschedule email; client cancel → cancellation email.
- **Find my appointments**: `/book/<slug>/my-appointments`. Client enters their email; if known, a fresh private link for their next (or latest) appointment is emailed. Always the same neutral answer; max 5 emails per address per hour. The previous link for that appointment stops working.
- `cron/send_reminders.php`: CLI only; reminders for appointments starting in the next 24 h (once each).
- `scripts/send_test_email.php you@example.com`: verifies mail settings.
- `.htaccess` now blocks web access to `app/ config/ database/ storage/ scripts/ cron/ tests/` and to `*.md`, `*.txt` and `authentication` in the project root.

## Notes
- Reminder emails cannot contain the private link (only a hash is stored); they link to Find my appointments instead.
- Old `pending` rows from Milestone 5 stay pending (their tokens are gone). Use Generate client link or Find my appointments.
- Not included: inquiry-received emails and emails to staff. They need new notification types (an enum migration), so they are a deliberate decision for the next step.

## Windows scheduling (reminders)
Task Scheduler → Create Task → Trigger: every 15 minutes → Action: `C:\xampp\php\php.exe` with arguments `C:\xampp\htdocs\appointflow\cron\send_reminders.php`.

## Manual test script
1. `php scripts/send_test_email.php you@example.com` (log mode: file appears in `storage/mail/`).
2. Schedule a consultation for a client → success message says the email was saved/sent; open the file and click the link.
3. On the client page, reschedule then cancel → two more emails.
4. Open `/book/<slug>/my-appointments`, enter the client's email → email with a new link; the old link now says invalid.
5. Enter an unknown email → the same neutral message, no email.
