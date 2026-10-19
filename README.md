# AppointFlow — MVP v0.1

AppointFlow is a lightweight consultation workflow system for small consulting/service businesses.

The implementation follows the approved model:

```text
Client
  ↓
Engagement
  ├── Appointment
  ├── Intake
  ├── Preparation
  ├── Meeting Notes
  ├── Follow-ups
  └── Activity History
```

An **Engagement** is the long-lived business record. An **Appointment** is a scheduled event belonging to that engagement.

## Current status

**Milestones 1–9 are implemented. Milestone 10 (security hardening and release readiness) is next.**

| Milestone | Scope | Status |
|---|---|---|
| 1 | Foundation: PHP 8.2+/PDO, schema, secure sessions, CSRF, authorization foundation | Done |
| 2 | Workspace settings + team management (owner / consultants) | Done |
| 3 | Services + business/consultant availability + blocked periods | Done |
| 4 | Public inquiry form, client dedupe, engagements, qualification, assignment, activity history | Done |
| 5 | Booking engine: slot generation, buffers, conflict prevention, transactional scheduling | Done |
| 6 | Secure client appointment links: view, reschedule, cancel (engagement survives changes) | Done |
| 7 | Email notifications (confirmation, reschedule, cancellation, reminders), "Find my appointments" | Done |
| 8 | Preparation checklist, meeting notes, appointment outcomes, follow-ups, completion workflow | Done |
| 9 | Operations Center: actionable dashboard, read-only calendar (week/day), follow-up center | Done |
| 10 | Security hardening, edge-case and end-to-end testing, production readiness | Planned |

Also implemented after Milestone 7:

- Owner dashboard **Your booking link** panel (copy, open, QR code download) with a warning while the link points to localhost
- **Client link requests**: with `APP_LINK_REQUEST_MODE = 'owner'` a client's "Find my appointments" request is saved, the owner is notified, and the owner generates/sends the link from the engagement page (see below)

Schema: 16 migrations. Milestones 8 and 9 use the existing tables, so neither needs a new migration.

Not implemented yet:

- Staff-side rescheduling from the engagement page (clients can reschedule through their private link)
- Reports / advanced analytics, AI integration, payments (out of scope for v1)

## Local XAMPP setup

1. Copy the `appointflow` folder to `C:\xampp\htdocs\appointflow`.
2. Start Apache and MySQL in XAMPP.
3. Run the one-time local installer:

   `http://localhost/appointflow/setup/`

4. Enter the business and owner details and install.
5. Sign in at:

   `http://localhost/appointflow/public/login.php`

The installer creates the `appointflow` database and applies every migration in `database/migrations/` (currently `001` through `016`).

### phpMyAdmin alternative

Import:

`database/database.sql`

Then create the first owner with:

`php scripts/install.php --business="Northstar Digital Consulting" --email=owner@example.com --first=Maya --last=Owner --password="ChangeMe123!"`

## Database structure

Migrations are kept separately under `database/migrations/` in the approved dependency order.

The combined import file is provided for convenience only; the migrations remain the source of schema structure.

## Security boundary

Protected operations should pass through the authentication/authorization helpers and service layer. State-changing forms use CSRF tokens. Passwords are stored with `password_hash()`. Client appointment tokens are stored only as hashes; the raw token exists only in the private link.

## Testing

Run everything from the project root:

```text
php tests/foundation_smoke.php
php tests/milestone2_static.php
php tests/milestone3_static.php
php tests/milestone4_static.php
php tests/milestone5_static.php
php tests/milestone6_static.php
php tests/milestone7_static.php
php tests/milestone8_static.php
php tests/milestone9_operations.php          # static checks; add --db for the Operations Center scenario (creates and removes throwaway rows)
php tests/cancel_workspace_scope.php        # static checks; add --db for the cross-workspace cancel test (creates and removes throwaway rows)
sh tests/php_lint.sh        # php -l on app, config, public, public_booking, setup, scripts and cron
```

The static tests check that expected logic is present in the source (they do not run the app against a database). They require **at least** the 15 core migrations, numbered consecutively from `001`, so adding a new migration does not break them.

## Consultation workspace (Milestone 8)

The Engagement page is the consultant's single working surface; there is no separate meeting page. Sections appear as they become relevant:

| Section | What it does |
|---|---|
| Appointment | Shows the current consultation, or the latest one (completed / no-show / cancelled) once nothing is scheduled. Outcome controls: **Complete consultation**, **Mark no-show**, **Cancel**. |
| Preparation | Checklist with progress (Not started / In progress / Ready). A standard four-task list is created the first time the engagement has a scheduled consultation; tasks can be added, completed, reopened and removed. |
| Meeting | Summary, problem, requirements, decisions, concerns, next step. Notes belong to the consultation (`meeting_notes.appointment_id`), so repeat consultations keep separate notes. Unlocks when the consultation starts. |
| Follow-up | Create, edit, complete and cancel follow-ups (proposal, call, email, document, meeting, other). Creating the first one moves the engagement to `follow_up`. |
| Outcome | **Convert engagement** or **Close as lost**, available from `completed` and `follow_up`. |

Rules enforced in the service layer (`AppointmentService`, `MeetingNoteService`, `FollowUpService`):

- A consultation cannot be completed, or marked as a no-show, before its scheduled start time.
- Complete: appointment `completed`, engagement `scheduled` → `completed`.
- No-show: appointment `no_show`, engagement `scheduled` → `qualified`, so it can be rebooked. Cancel behaves the same way and also emails the client.
- Follow-ups can only be created once the engagement is `completed` or `follow_up`.
- Engagement status changes only as the result of these actions; there is no free-form status dropdown. Every step is written to the activity history.
- Access: the owner, or the consultant assigned to the engagement.

## Operations Center (Milestone 9)

Read-only visibility for daily work. Nothing here changes data; every row links to the relevant Engagement page (or one of its sections).

| Page | What it shows |
|---|---|
| Dashboard (`public/dashboard.php`) | Counts (new inquiries, today's and upcoming consultations, open preparation tasks, overdue follow-ups), **Today**, **Next consultations**, and **Action items**: overdue follow-ups, preparation still to finish ("Not started" until the checklist is first opened), and unreviewed inquiries. A consultation that has already started shows "Record outcome". |
| Calendar (`public/calendar.php`) | **Week** (Monday to Sunday) and **Day** views with Prev / Today / Next. Each consultation shows time, client, service, consultant and status. Owners see all consultants and can filter by one; consultants see only their own. Cancelled consultations are hidden unless "Show cancelled" is ticked. No drag-and-drop or staff rescheduling. |
| Follow-up center (`public/followups.php`) | Tabs **All / Today / Upcoming / Overdue / Completed** with counts. "All" includes every status; the other tabs show pending items (Completed shows completed ones). Follow-ups are due at the end of their due date, so one due today is "Today", not "Overdue", until the day ends. Owners can filter by assignee. Complete or edit a follow-up on its engagement page. |

All queries live in `app/services/OperationsService.php`. "Now", "today" and "tomorrow" are computed in the **workspace timezone** in PHP (appointment and due times are stored as workspace-local wall-clock time), never with the database's own clock. Consultants only see engagements assigned to them, the same rule the Engagement page enforces, so every link opens.

## Technical decisions preserved from the specification

- PDO, not mysqli
- No framework
- Engagement remains intact through appointment changes
- Appointment state: scheduled / cancelled / completed / no_show
- Rescheduling is an operation, not a permanent appointment status
- Workspace timezone is explicit
- Business logic belongs in the service layer rather than page files
- Version 1 avoids AI, payments, full CRM, marketplace, and other out-of-scope features

## Sharing the booking link

Owners see a **Your booking link** panel at the top of the dashboard with a copy button, an "Open page" button and a QR code (PNG/SVG download). The QR code is generated in the browser from the bundled `assets/js/qrcode.js` (MIT, Kazuhiko Arase), so no external service is used.

Before sharing the link with real clients:

1. Deploy AppointFlow to a host with a public domain (or use a tunnel such as ngrok/Cloudflare Tunnel for a demo).
2. Set `APP_PUBLIC_URL` in `config/app.php` (e.g. `https://book.example.com`) and adjust `APP_BASE_PATH` if the app is not in a subfolder.
3. Set `base_url` in `config/mail.php` to the same origin so emailed and cron-sent links work.

While the link still points to `localhost` or a private IP, the panel shows a warning that clients cannot open it.

## Client link requests ("Find my appointments")

By default (`APP_LINK_REQUEST_MODE = 'owner'` in `config/app.php`) a client who enters their email on the public *Find my appointments* page does **not** get an automatic email. Instead:

1. The request is saved (only if the email belongs to a client with a scheduled appointment, so the page cannot be used to probe clients), and the business owner is emailed.
2. It appears on the owner's dashboard under **Clients waiting for their link**, and on that engagement's *Client access* section with the client's email.
3. The owner clicks **Generate & email link to client** (or **Dismiss**). After any generation, **Open in my email app** opens a prefilled message to the client, useful when SMTP is not configured.

Set `APP_LINK_REQUEST_MODE = 'auto'` to restore the old behaviour (link emailed to the client immediately).

Upgrading an existing install: migration `database/migrations/016_create_client_link_requests.sql` adds the `client_link_requests` table. The app also creates it automatically the first time it is needed; if your database user cannot create tables, run that file manually.
