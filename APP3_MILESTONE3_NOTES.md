# AppointFlow — Milestone 3 Notes

## Implemented

- Owner-only Services management page
- Create, edit, activate, and deactivate services
- Service duration and before/after buffers
- Duplicate service-name protection per workspace
- Owner-only Availability page
- Weekly business availability rules
- Consultant-specific recurring availability rules
- Enable/disable/remove availability rules
- Blocked periods for the full business or a specific consultant
- Blocked-time overlap protection for the same scope
- Workspace timezone used for blocked-period parsing/display
- Owner navigation links for Services and Availability
- No database migrations changed

## Boundaries preserved

- Existing `services`, `availability_rules`, and `blocked_times` tables are used as-is.
- Appointment slot generation is still deferred to the booking-engine milestone.
- No inquiry, qualification, appointment, client-token, email, or AI features were added here.
- Consultants cannot modify global business configuration.
