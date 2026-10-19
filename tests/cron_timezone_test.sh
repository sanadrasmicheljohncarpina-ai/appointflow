#!/bin/sh
# Reminder cron with workspaces in zones far from the app's own timezone (Asia/Manila by default).
# Appointment stored as workspace wall time: 10h away must be reminded, 30h away and already-started must not.
# Usage: AF_TEST_DB=appointflow_test sh tests/cron_timezone_test.sh   (wipes the scratch DB; uses a temp copy of the project)
: "${AF_TEST_DB:?set AF_TEST_DB to a *_test database}"
case "$AF_TEST_DB" in *_test) ;; *) echo "DB name must end in _test"; exit 2;; esac
ROOT="$(cd "$(dirname "$0")/.." && pwd)"; TMP="$(mktemp -d)"; cp -r "$ROOT" "$TMP/app"; cd "$TMP/app"
sed -i "s/APP_DB_NAME = '[^']*'/APP_DB_NAME = '$AF_TEST_DB'/" config/app.php
printf "<?php return ['driver'=>'log','base_url'=>'http://localhost'];\n" > config/mail.php
q(){ mysql -uroot "$AF_TEST_DB" -N -e "$1"; }
fail=0
for tz in Asia/Manila America/New_York Pacific/Kiritimati Pacific/Pago_Pago Australia/Sydney UTC; do
  q "SET FOREIGN_KEY_CHECKS=0; DELETE FROM notification_log; DELETE FROM appointments; DELETE FROM engagements; DELETE FROM clients; DELETE FROM users; DELETE FROM services; DELETE FROM workspaces; SET FOREIGN_KEY_CHECKS=1;
   INSERT INTO workspaces (id,name,email,booking_slug,timezone) VALUES (1,'N','n@example.com','n','$tz');
   INSERT INTO users (id,workspace_id,first_name,last_name,email,password_hash,role) VALUES (1,1,'O','O','o@example.com','x','consultant');
   INSERT INTO services (id,workspace_id,name,duration_minutes) VALUES (1,1,'S',60);
   INSERT INTO clients (id,workspace_id,first_name,last_name,email) VALUES (1,1,'C','C','c@example.com');"
  i=0
  for h in 10 30 -1; do   # hours from now, in the workspace's own clock
    i=$((i+1))
    S=$(TZ=$tz date -d "$h hours" '+%Y-%m-%d %H:%M:%S'); E=$(TZ=$tz date -d "$h hours + 1 hour" '+%Y-%m-%d %H:%M:%S')
    q "INSERT INTO engagements (id,workspace_id,client_id,service_id,assigned_user_id,title,problem_summary,status) VALUES ($i,1,1,1,1,'T','P','scheduled');
       INSERT INTO appointments (id,engagement_id,assigned_user_id,starts_at,ends_at,client_token_hash,status) VALUES ($i,$i,1,'$S','$E','h$i','scheduled');"
  done
  php cron/send_reminders.php >/dev/null 2>&1
  got=$(q "SELECT GROUP_CONCAT(appointment_id) FROM notification_log WHERE notification_type='reminder' AND status='sent'")
  if [ "$got" = "1" ]; then echo "ok   $tz: only the appointment 10h away was reminded"; else echo "FAIL $tz: reminded appointments = '$got' (expected 1)"; fail=1; fi
done
rm -rf "$TMP"; exit $fail
