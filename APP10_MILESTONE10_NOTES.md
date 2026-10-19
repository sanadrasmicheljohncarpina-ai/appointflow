# Milestone 10 notes: hardening (SMTP failures, time and DST boundaries)

## Time model
Every appointment, blocked period and availability window is stored as **workspace wall-clock time**
(`DATETIME`, no offset). `workspaces.timezone` decides what "today", "now" and "in the past" mean.
The MariaDB clock and PHP's default zone are not used for booking decisions (tested with the database
session zone and PHP zone set to unrelated values).

## Daylight-saving behaviour (BookingService)
* Minute arithmetic is done on timestamps (`addMinutes()`), so a 30-minute slot is 30 real minutes on
  every PHP version. `DateTimeImmutable::modify('+30 minutes')` was found to add wall-clock minutes across
  a clock change on PHP 8.3 (01:30 EDT + 30 min = 02:00 EST = 90 real minutes).
* **Clocks go forward:** the skipped hour is never offered; typing a time inside it is rejected as invalid.
  A meeting that spans the gap is stored with the correct wall-clock end (01:30 -> 03:00 is 30 real minutes).
* **Clocks go back:** a wall time in the repeated hour names two instants and the database cannot say which.
  A slot is offered only if its start, end and buffer edges read back from their stored wall-clock text as the
  same instants. Which half of the repeated hour that keeps depends on the zone (PHP picks the first pass
  in New York, the second in London), so about two hours of slots near the change can be withheld on one day
  a year. Booking or rescheduling such a time is refused with a daylight-saving message.
* Meetings stored **before** this change inside a repeated hour cannot be repaired automatically.

## Known limits
* Availability windows cannot cross midnight (rejected: "must end after it starts"); enter two windows
  on two days instead. The latest end time is 23:59, so the last quarter-hour of the day (23:45-24:00)
  cannot be booked.
* Blocked periods may cross midnight and are honoured on both days.
* A buffer cannot reach past midnight through normal booking; it can if the service's buffer is increased
  after appointments exist, and that case is handled (next day's early slots are blocked).

## Tests
```
AF_TEST_DB=appointflow_test sh tests/timezone_boundary_run.sh   # needs libfaketime; freezes the clock
AF_TEST_DB=appointflow_test sh tests/cron_timezone_test.sh      # reminders for workspaces far from the app zone
AF_TEST_DB=appointflow_test sh tests/smtp_failure_run.sh        # fake SMTP server failures
AF_TEST_DB=appointflow_test sh tests/cron_hardening_test.sh     # overlap lock, stale pending rows, early stop
```
All wipe the scratch database, whose name must end in `_test`.
