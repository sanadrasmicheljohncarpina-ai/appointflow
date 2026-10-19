#!/bin/sh
# Cron reminder hardening: overlap lock, stale-pending sweep, early stop on a dead mail server.
# Runs against a TEMPORARY COPY of the project pointed at a scratch DB, so config/ is untouched.
# Usage: AF_TEST_DB=appointflow_test sh tests/cron_hardening_test.sh   (migrations loaded; DB wiped)
set -e
: "${AF_TEST_DB:?set AF_TEST_DB to a *_test database}"
case "$AF_TEST_DB" in *_test) ;; *) echo "DB name must end in _test"; exit 2;; esac
ROOT="$(cd "$(dirname "$0")/.." && pwd)"; TMP="$(mktemp -d)"; cp -r "$ROOT" "$TMP/app"; cd "$TMP/app"
sed -i "s/APP_DB_NAME = '[^']*'/APP_DB_NAME = '$AF_TEST_DB'/" config/app.php
q(){ mysql -uroot "$AF_TEST_DB" -N -e "$1"; }
mail(){ printf "<?php return ['driver'=>'smtp','host'=>'127.0.0.1','port'=>%s,'encryption'=>'none','timeout'=>5,'base_url'=>'http://localhost'];\n" "$1" > config/mail.php; }
seed(){ q "SET FOREIGN_KEY_CHECKS=0; DELETE FROM notification_log; DELETE FROM appointments; DELETE FROM engagements; DELETE FROM clients; DELETE FROM users; DELETE FROM services; DELETE FROM workspaces; SET FOREIGN_KEY_CHECKS=1;
 INSERT INTO workspaces (id,name,email,booking_slug,timezone) VALUES (1,'N','n@example.com','n','Asia/Manila');
 INSERT INTO users (id,workspace_id,first_name,last_name,email,password_hash,role) VALUES (1,1,'O','O','o@example.com','x','owner');
 INSERT INTO services (id,workspace_id,name,duration_minutes) VALUES (1,1,'S',60);
 INSERT INTO clients (id,workspace_id,first_name,last_name,email) VALUES (1,1,'C','C','c@example.com');
 INSERT INTO engagements (id,workspace_id,client_id,service_id,assigned_user_id,title,problem_summary,status) VALUES (1,1,1,1,1,'T','P','scheduled');"
  for i in 1 2 3 4 5 6 7 8; do
    S=$(TZ=Asia/Manila date -d "+$((i+2)) hours" '+%Y-%m-%d %H:%M:%S'); E=$(TZ=Asia/Manila date -d "+$((i+3)) hours" '+%Y-%m-%d %H:%M:%S')
    q "INSERT INTO appointments (id,engagement_id,assigned_user_id,starts_at,ends_at,client_token_hash,status) VALUES ($i,1,1,'$S','$E','h$i','scheduled')"
  done; }
fail=0; ok(){ if [ "$2" = "$3" ]; then echo "ok   $1"; else echo "FAIL $1 (expected $3, got $2)"; fail=1; fi; }
python3 tests/fake_smtp.py ok 2601 >/dev/null 2>&1 & SMTP=$!
sleep 1
mail 2601; seed
php cron/send_reminders.php >/dev/null 2>&1 & C1=$!
php cron/send_reminders.php >/dev/null 2>&1 & C2=$!
wait $C1 $C2; sleep 1
ok "two simultaneous runs: one reminder per appointment" "$(q "SELECT COUNT(*)=COUNT(DISTINCT appointment_id) FROM notification_log WHERE notification_type='reminder'")" 1
ok "two simultaneous runs: all 8 reminded" "$(q "SELECT COUNT(DISTINCT appointment_id) FROM notification_log WHERE status='sent'")" 8
q "INSERT INTO notification_log (workspace_id,engagement_id,appointment_id,recipient_email,notification_type,status,created_at) VALUES (1,1,1,'c@example.com','reminder','pending',NOW()-INTERVAL 2 HOUR),(1,1,1,'c@example.com','confirmation','pending',NOW())"
php cron/send_reminders.php >/dev/null 2>&1
ok "stale pending row marked failed" "$(q "SELECT COUNT(*) FROM notification_log WHERE status='failed' AND error_message LIKE 'Abandoned%'")" 1
ok "fresh pending row left alone" "$(q "SELECT COUNT(*) FROM notification_log WHERE status='pending'")" 1
kill $SMTP 2>/dev/null || true
mail 2599; seed
php cron/send_reminders.php >/dev/null 2>&1
ok "dead server: run stops after 3 failures" "$(q "SELECT COUNT(*) FROM notification_log WHERE status='failed'")" 3
rm -rf "$TMP"; exit $fail
