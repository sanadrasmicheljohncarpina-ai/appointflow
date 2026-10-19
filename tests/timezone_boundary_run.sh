#!/bin/sh
# Milestone 10.8: time/boundary tests with a frozen clock. Needs libfaketime (apt install faketime), php, mysql.
# DESTRUCTIVE to the scratch DB: AF_TEST_DB=appointflow_test sh tests/timezone_boundary_run.sh
: "${AF_TEST_DB:?set AF_TEST_DB to a *_test database}"
cd "$(dirname "$0")/.."; export AF_TEST_DB; rc=0
t(){ # scenario, frozen UTC instant, [ENV=VALUE ...]
  s=$1; i=$2; shift 2
  env "$@" SCEN="$s" faketime "$i" php tests/timezone_boundary_test.php | grep -E "^FAIL|^info|checks" || true
  env "$@" SCEN="$s" faketime "$i" php tests/timezone_boundary_test.php >/dev/null 2>&1 || rc=1; }
# Manila is UTC+8: 15:40 UTC = 23:40 Manila (midnight), 15:59:30 / 16:00:30 straddle Manila midnight.
t midnight          '2026-10-02 15:40:00'
t blocked_midnight  '2026-10-02 15:40:00'
t buffer_midnight   '2026-10-02 15:40:00'
for inst in '2026-10-02 15:59:30' '2026-10-02 16:00:30' '2026-10-02 03:59:50' '2026-10-02 10:30:00'; do
  for tzs in "DB_TZ=+00:00 PHP_TZ=UTC" "DB_TZ=-11:00 PHP_TZ=Pacific/Kiritimati" "DB_TZ=+13:00 PHP_TZ=Pacific/Pago_Pago"; do
    echo "-- clock $inst | $tzs"; t tz_independence "$inst" $tzs
  done
done
for s in dst_fall_back dst_fall_back_london dst_spring_forward dst_spring_sydney; do t $s '2026-10-02 06:00:00'; done
exit $rc
