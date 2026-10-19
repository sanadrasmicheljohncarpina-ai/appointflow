# AppointFlow — Milestone 9 Notes (Operations Center)

No schema changes: still 16 migrations. Milestone 9 is read/query/UI work on top of the existing
`appointments`, `engagements`, `follow_ups`, `preparation_tasks`, `users`, `services` and `clients` tables.

## Flow

```text
              Dashboard (Today · Upcoming · Needs attention)
                 │             │              │
              Calendar     Follow-ups      Engagement
                 └─────────────┴──────────────┘
                               ▼
              Preparation / Meeting / Follow-ups / Outcome
```

## What was added

- `app/services/OperationsService.php` — the single place the operational screens get their data
  (read-only: it never writes and keeps no state of its own):
  `getDashboardSummary()`, `getUpcomingAppointments()`, `getTodayAppointments()`,
  `getCalendarAppointments()`, `getAwaitingOutcome()`, `getFollowUps()` / `getPendingFollowUps()` /
  `getOverdueFollowUps()`, `getFollowUpCounts()`, `getTodayPreparationTasks()`,
  `getPreparationProgress()`, plus the `dayLabel()` and `overdueLabel()` formatters.
- `public/calendar.php` — **Calendar**: week and day views, previous / next / today, owner filter by
  consultant, "Show cancelled". Each appointment shows client, service, consultant, start–end time and
  status (day view adds preparation progress) and links to its Engagement.
- `public/followups.php` — **Follow-up Center**: tabs *My follow-ups, All, Today, Upcoming, Overdue,
  Completed* with counts, owner-only "Assigned to" filter, and per item **Complete, Edit, Cancel, Open
  engagement**.
- `public/dashboard.php` — now an operations dashboard: a **Today** panel (`app/views/components/today_panel.php`),
  **Upcoming** grouped by day, **Needs attention** (overdue follow-ups with "N days overdue", plus meetings
  that ended without an outcome), and metric cards that link to the matching list.
- Header navigation: **Calendar** and **Follow-ups** for every signed-in user.
- `public/consultation_action.php`: follow-up actions accept `return=followups|dashboard` (plus an optional
  filter / assignee) so Complete / Edit / Cancel come back to the page they started from. Only those two
  fixed pages are accepted, never a URL from the request.
- CSS appended to `assets/css/app.css`.

## Rules worth knowing

- **One source of truth.** Counts and the lists behind them use the same predicates, and nothing is
  duplicated: completing a follow-up (anywhere) changes the dashboard, Today panel and follow-up center on the
  next load.
- **Workspace-local time.** "Now", "today" and "tomorrow" come from the workspace timezone, never the database
  clock. The calendar shows stored local times as they are and computes week/day ranges in the workspace
  timezone.
- **Who sees what:** the owner sees the whole workspace; a consultant sees engagements assigned to them (the
  same rule as `requireEngagementAccess`). Actions still go through the Milestone 8 services, so permissions,
  validation and Activity History are unchanged.
- **Follow-up filters never overlap:** *Overdue* = pending and due before now; *Today* = pending and due from
  now until midnight; *Upcoming* = pending and due tomorrow or later. Overdue + Today + Upcoming = *All*
  (pending). *Mine* = pending and assigned to you. *Completed* = most recently finished first.
- **Today** counts consultations that are scheduled, completed or no-show today. *Preparation tasks open* are
  the pending tasks of engagements with a scheduled consultation today.
- **Needs attention** also lists scheduled meetings that have already ended with no outcome, so a finished
  meeting is not forgotten.
- The week calendar places an appointment in the row of the hour it starts in (e.g. 9:30 shows in the 09:00
  row with its exact time); rows run 08:00–18:00 and widen automatically for earlier or later appointments.

## Not included

- Dragging to reschedule, blocked times / availability on the calendar, month view and calendar export
  (rescheduling remains the client link / booking flow).

## Tests

```text
php tests/milestone9_static.php
AF_TEST_DB=appointflow_test php tests/milestone9_integration.php   # optional, needs a scratch MySQL database
```

The integration test (60 assertions) uses a workspace in a different timezone from the application default
and covers: the "Sarah tomorrow at 10:00" scenario, today / upcoming / awaiting-outcome, calendar ranges,
cancelled appointments, follow-up filters and tab counts, consultant scoping, workspace isolation, and the
dashboard counts updating after a follow-up is completed or cancelled.

## Next

Milestone 10 — security hardening, edge-case and end-to-end testing, production readiness.
