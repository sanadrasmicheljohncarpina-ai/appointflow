#!/bin/sh
# Starts the fake SMTP servers, runs the failure test, stops them. Usage: AF_TEST_DB=appointflow_test sh tests/smtp_failure_run.sh
cd "$(dirname "$0")"
i=0; PIDS=""
for m in ok auth535 rcpt550 rcpt450 data554 drop hang hang_after_banner drip; do
  i=$((i+1)); python3 fake_smtp.py $m $((2500+i)) & PIDS="$PIDS $!"
done
sleep 1
php smtp_failure_test.php; rc=$?
kill $PIDS 2>/dev/null
exit $rc
