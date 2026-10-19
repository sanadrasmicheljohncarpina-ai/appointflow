# AppointFlow — Milestone 6 Notes

Client-side appointment management through a secure link. No login, no schema changes (still 15 migrations).

## Included

- `public_booking/appointment.php?token=…` — client page showing date, time, service, consultant, status.
- **Reschedule** — same `appointments` row, same Engagement (stays `scheduled`). Re-validates availability, blocked times, buffers and conflicts inside the advisory lock; the appointment's own slot is not treated as a conflict. Records `appointment.rescheduled`, queues a `reschedule` notification, clears `reminder_sent_at`.
- **Cancel** — `AppointmentService::cancel()`: appointment → `cancelled`, Engagement `scheduled` → `qualified` (never deleted), records `appointment.cancelled`, queues a `cancellation` notification.
- **Generate client link** — button on Engagement detail (owner or assigned consultant) → `public/appointment_link.php`. Issues a new token, replaces the stored hash (old links stop working), shows the full URL once. Needed because email delivery is still deferred and because Milestone 5 appointments only stored a hash.
- Tokens: only a SHA-256 hash is stored. Unknown/expired tokens return one generic 404 page. Page sends `Cache-Control: no-store`, `Referrer-Policy: no-referrer`, `X-Robots-Tag: noindex`.
- CSRF on every POST; redirect-after-POST with flash messages.

## Rules

- Reschedule/cancel only while status is `scheduled` and the start time is in the future.
- No minimum-notice window (can be added later).

## Deferred

- Email delivery of links/notifications, rebooking after cancellation by the client, rate limiting on token lookups.

## Manual test script

1. Engagement #1 → Consultation card → **Generate client link**, copy the URL.
2. Open it in a Private window: details visible, no login.
3. Reschedule to another slot → success message; Engagement #1 still `scheduled`; History shows "Client rescheduled…".
4. Cancel → page shows Cancelled; Engagement #1 is `qualified` and still exists; History shows "Client cancelled…".
5. Generate a new link on a scheduled engagement → previous link now shows "invalid or has expired".
6. Tamper with the token → generic invalid page.

## Friendly URLs (polish step)

Rules live in `/.htaccess` (Apache + mod_rewrite). The original long URLs still work.

| Purpose | URL |
|---|---|
| Home | `/appointflow/` |
| Public booking | `/appointflow/book/<slug>` |
| Inquiry form | `/appointflow/book/<slug>/request/<service-id>` |
| Confirmation | `/appointflow/book/<slug>/received/<engagement-id>` |
| Client appointment link | `/appointflow/appointment/<token>` |
| Team sign-in | `/appointflow/login` |
| Staff pages | `/appointflow/dashboard`, `engagements`, `clients`, `team`, `services`, `availability`, `settings` |

- All links are built by helpers in `app/helpers.php` (`bookingUrl`, `inquiryUrl`, `confirmationUrl`, `clientAppointmentUrl`, `loginUrl`, `absoluteUrl`).
- Optional constants in `config/app.php`: `APP_PRETTY_URLS` (set `false` if mod_rewrite is unavailable) and `APP_PUBLIC_URL` (e.g. `https://book.example.com`, used for links shown to staff).
- Settings now shows the full public booking link with a **Copy booking link** button.
- `requireLogin()` now redirects to an absolute login URL (a relative one would 404 under friendly URLs).
