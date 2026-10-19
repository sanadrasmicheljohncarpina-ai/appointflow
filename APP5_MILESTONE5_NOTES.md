# AppointFlow — Milestone 5 Notes

Milestone 5 implements the internal booking engine against the existing 15-table schema without changing migrations (a 16th table, `client_link_requests`, was added after Milestone 7).

## Included

- `BookingService` now calculates 15-minute appointment slots in the workspace timezone.
- Business-wide availability is always required.
- Consultant-specific availability is intersected when active rules exist for that consultant.
- Business-wide and consultant-specific blocked times remove slots.
- Service duration and before/after buffers are respected.
- Existing scheduled appointments and their service buffers prevent overlapping bookings.
- Booking is transaction-protected and uses a short MySQL/MariaDB advisory lock to reduce race-condition double bookings.
- Only qualified engagements with an active assigned consultant and active service can be scheduled.
- Booking atomically creates an appointment, moves the Engagement to `scheduled`, records `appointment.booked`, and creates a pending confirmation notification log row.
- A secure client token hash is generated for the existing appointment schema; client-facing token flows remain deferred to Milestone 6.
- Protected internal booking screen: `public/booking.php`.
- Engagement detail now exposes the Schedule consultation action for qualified assigned engagements and shows the scheduled appointment when present.

## Deliberately deferred

- Client-facing secure booking/management links
- Email delivery
- Reschedule/cancel client flows
- Calendar UI
- Preparation, meeting notes, and follow-ups

## Validation

- PHP syntax lint across the application: expected to pass before packaging.
- No migration files changed.
