# AppointFlow — Milestone 4 Notes

## Inquiry + Engagement

Implemented against the existing Milestone 3 codebase without changing the approved 15 database migrations.

### Added
- Public consultation services landing page now links to an inquiry flow.
- Public inquiry form creates or reuses a workspace-scoped client by email.
- Inquiry creates a long-lived Engagement with the original problem summary preserved.
- Optional active inquiry-stage intake questions are captured into `intake_answers`.
- Owner can qualify or reject new inquiries.
- Owner can assign an active consultant during qualification or afterward.
- Engagement list with search/status/assignee/service filters.
- Engagement detail with overview, original inquiry, intake answers, and activity history.
- Client list/detail views tied to Engagement history.

### Preserved rules
- Engagement remains the long-lived business record.
- Appointment tables and booking behavior were not redefined in this milestone.
- Business logic is routed through services rather than raw status mutation in page templates.
- Existing workspace/role authorization remains in place.

### Intentionally deferred
- Slot generation and real booking engine (Milestone 5).
- Secure client appointment-management links (Milestone 6).
- Preparation, meeting notes, follow-ups UI (later workflow milestone).
- Notifications/email delivery and calendar UI.
