# AppointFlow — Milestone 8 Notes (Consultation Execution & Follow-up)

No schema changes: everything is built on the existing `preparation_tasks`, `meeting_notes` and `follow_ups` tables (still 16 migrations).

## Flow

```text
qualified ──(book)──► scheduled ──► [Preparation checklist] ──► [Meeting notes]
                                        │
                      ┌─────────────────┴─────────────────┐
                      ▼                                   ▼
        Mark consultation completed                Client did not attend
        appointment → completed                    appointment → no_show
        engagement  → completed                    engagement  → qualified (rebook)
                      │
                      ▼
              Create follow-up  (first one: completed → follow_up)
                      │
                      ▼
     Converted  or  Closed – lost   (from completed or follow_up)
     pending follow-ups are cancelled automatically
```

Every step writes to `engagement_events`, so it appears in the Activity History.

## What was added

- `app/services/ConsultationService.php` — preparation checklist (add, starter checklist, done/reopen, remove), meeting notes (create/update, one per appointment), completion, no-show and the final outcome.
- `app/services/FollowUpService.php` — create / edit / complete / cancel / list, plus `overdueCount` (now compared against workspace-local time).
- `public/consultation_action.php` — single POST endpoint (CSRF + `requireEngagementAccess`).
- `app/views/components/consultation_workspace.php` and `consultation_outcome.php`, included from `public/engagement.php`: **Preparation**, **Meeting**, **Follow-ups** cards and an **Outcome** card.
- `EngagementService::canTransition()` (single source of truth for the state machine) and `recordEvent()`.

## Rules worth knowing

- **Who can act:** the owner on any engagement in the workspace; a consultant only on engagements assigned to them. Both can record the outcome.
- **Preparation** can be edited while the engagement is `qualified` or `scheduled`; it is read-only afterwards.
- **Meeting notes** require summary, problem, requirements and next step (decisions and concerns are optional). Editable while `scheduled`, `completed` or `follow_up`; frozen once converted / closed.
- **Marking completed** needs the meeting to have started *and* notes saved. **No-show** also needs the meeting to have started.
- **Follow-ups** can be created only once the consultation is completed. Due date cannot be in the past (an unchanged overdue date can still be kept when editing). Assignee defaults to the engagement's consultant; only owners can pick someone else.
- **Concluding** cancels pending follow-ups so nothing stays overdue on a finished engagement.
- Notes and follow-up text typed into a form are kept if validation fails.

## Also fixed

- Dashboard "Upcoming" and "Overdue actions" compared workspace-local times against the database server's `NOW()`. They now use the workspace's local time.
- Older milestone notes that said "still 15 migrations" now say 016 was added after Milestone 7.

## Tests

```text
php tests/milestone8_static.php
AF_TEST_DB=appointflow_test php tests/milestone8_integration.php   # optional, needs a scratch MySQL database
```

The integration test runs the real services against MySQL/MariaDB (60 assertions: state transitions, workspace isolation, auto-cancel, activity events, overdue counting). It refuses to run unless the database name ends in `_test`.

## Next

Milestone 9 — operational dashboard, calendar UI, overdue/pending visibility.
