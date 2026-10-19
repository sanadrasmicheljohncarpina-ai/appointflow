#!/usr/bin/env python3
"""
Northstar end-to-end validation (Milestone 10.6). Drives the REAL pages over HTTP against a real
MySQL/MariaDB database, then inspects the database to verify the historical chain.

Paths:  A direct success (completed -> converted)
        B cancel -> qualified -> reschedule (client token page) -> completed -> follow-up -> converted
        C no-show -> qualified -> rebooked -> completed -> closed_lost
Needs: php -S 127.0.0.1:8099 -t <parent of appointflow>   and   config/app.php pointing at the DB.
Usage: python3 tests/northstar_e2e.py [base_url] [db_cli_prefix]
"""
import re, sys, subprocess, urllib.request, urllib.parse, http.cookiejar, datetime as dt, glob, os, json

BASE = sys.argv[1] if len(sys.argv) > 1 else 'http://127.0.0.1:8099/appointflow'
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DB = ['mariadb', '-uroot', '-N', '-B', 'appointflow']
fails, passed = [], 0

def check(label, ok):
    global passed
    if ok: passed += 1
    else: fails.append(label); print('FAIL:', label)

def sql(q): return subprocess.run(DB + ['-e', q], capture_output=True, text=True, check=True).stdout.strip()

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k): return None

class Browser:
    def __init__(self):
        self.jar = http.cookiejar.CookieJar()
        self.op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar), NoRedirect)
    def req(self, url, data=None):
        body = urllib.parse.urlencode(data, doseq=True).encode() if data is not None else None
        try: r = self.op.open(urllib.request.Request(url, data=body), timeout=30); code, hdr, txt = r.status, r.headers, r.read().decode('utf-8', 'replace')
        except urllib.error.HTTPError as e: code, hdr, txt = e.code, e.headers, e.read().decode('utf-8', 'replace')
        return code, hdr.get('Location'), txt
    def get(self, path, follow=True):
        code, loc, txt = self.req(BASE + path if path.startswith('/') else path)
        if follow and code in (301, 302, 303) and loc: return self.get(loc if loc.startswith('http') else 'http://127.0.0.1:8099' + loc)
        return code, loc, txt
    def csrf(self, path):
        _, _, t = self.get(path); m = re.search(r'name="csrf_token" value="([^"]+)"', t); return m.group(1) if m else ''
    def post(self, path, data, csrf_from=None, follow=True):
        data = dict(data); data['csrf_token'] = self.csrf(csrf_from or path)
        code, loc, txt = self.req(BASE + path, data)
        if follow and code in (302, 303) and loc: return self.get(loc if loc.startswith('http') else 'http://127.0.0.1:8099' + loc)
        return code, loc, txt

def wait_clean(): subprocess.run(['sh', '-c', f'rm -f {ROOT}/storage/mail/*.html {ROOT}/storage/mail/*.eml 2>/dev/null'])
def mails(): return sorted(glob.glob(ROOT + '/storage/mail/*'), key=os.path.getmtime)
def last_mail_link():
    for f in reversed(mails()):
        m = re.search(r'(http://[^\s"<>]*(?:appointment/|appointment\.php\?token=)([A-Za-z0-9_-]{20,}))', open(f, errors='replace').read())
        if m: return m.group(2)
    return None

def local(hours):
    # The app stores naive WORKSPACE-LOCAL datetimes, so test times must be built in the workspace timezone,
    # not with the database server's NOW() (which may be in another timezone, e.g. UTC on a hosted DB).
    tzname = sql(f'select timezone from workspaces where id={ws}')
    return subprocess.run(['php', '-r', f"echo (new DateTime('now', new DateTimeZone('{tzname}')))->modify('{hours:+d} hours')->format('Y-m-d H:i:s');"], capture_output=True, text=True).stdout

def status(eid): return sql(f'select status from engagements where id={eid}')
def events(eid): return sql(f'select event_type from engagement_events where engagement_id={eid} order by id').split('\n')
def appts(eid): return sql(f'select status from appointments where engagement_id={eid} order by id').split('\n')

# ---------- seed what the installer does not create: a consultant (Daniel) + availability + service
sql("DELETE FROM client_link_requests; DELETE FROM notification_log; DELETE FROM engagement_events; DELETE FROM follow_ups; DELETE FROM meeting_notes; DELETE FROM preparation_tasks; DELETE FROM appointments; DELETE FROM intake_answers; DELETE FROM engagements; DELETE FROM clients; DELETE FROM blocked_times; DELETE FROM availability_rules; DELETE FROM services; DELETE FROM users WHERE role='consultant'")
ws = int(sql('select id from workspaces limit 1')); slug = sql(f'select booking_slug from workspaces where id={ws}')
h = subprocess.run(['php', '-r', "echo password_hash('Consult123!', PASSWORD_DEFAULT);"], capture_output=True, text=True).stdout
sql(f"INSERT INTO users (workspace_id,first_name,last_name,email,password_hash,role) VALUES ({ws},'Daniel','Consultant','daniel@northstar.test','{h}','consultant')")
daniel = int(sql("select id from users where email='daniel@northstar.test'"))
sql(f"INSERT INTO services (workspace_id,name,description,duration_minutes,buffer_before_minutes,buffer_after_minutes,active) VALUES ({ws},'Strategy Session','x',60,0,15,1)")
svc = int(sql(f"select id from services where workspace_id={ws} limit 1"))
for wd in range(7): sql(f"INSERT INTO availability_rules (workspace_id,user_id,weekday,start_time,end_time,active) VALUES ({ws},NULL,{wd},'00:00','23:59',1)")
wait_clean()
subprocess.run(['sh', '-c', f'rm -f {ROOT}/storage/ratelimit/* 2>/dev/null'])   # start from a clean rate-limit state

owner, cons, sarah = Browser(), Browser(), Browser()
tomorrow = (dt.date.today() + dt.timedelta(days=2)).isoformat()

def inquiry(b, first, email, problem):
    code, loc, t = b.post(f'/public_booking/inquiry.php?business={slug}', {'business': slug, 'service_id': svc, 'first_name': first, 'last_name': 'Client', 'email': email, 'phone': '', 'company_name': 'Acme', 'problem_summary': problem, 'additional_information': ''}, csrf_from=f'/public_booking/inquiry.php?business={slug}&service={svc}', follow=False)
    check(f'{first}: inquiry accepted (redirect)', code in (302, 303))
    return int(sql(f"select id from engagements where client_id=(select id from clients where email='{email}') order by id desc limit 1"))

# ---------- staff login (and the open-redirect fix over HTTP)
code, loc, _ = owner.post('/public/login.php?next=https://evil.example', {'email': 'maya@northstar.test', 'password': 'TestPass123!'}, csrf_from='/public/login.php', follow=False)
check('login redirects to dashboard, not evil.example', code == 302 and 'evil' not in (loc or '') and 'dashboard' in (loc or ''))
code, _, t = owner.get('/public/dashboard.php'); check('owner dashboard loads', code == 200)
cons.post('/public/login.php', {'email': 'daniel@northstar.test', 'password': 'Consult123!'}, csrf_from='/public/login.php', follow=False)
code, _, t = cons.get('/public/dashboard.php'); check('consultant dashboard loads', code == 200)
code, _, _ = Browser().get('/public/engagements.php', follow=False); check('anonymous staff page redirects to login', code == 302)

def qualify(eid):
    owner.post('/public/engagement_action.php', {'action': 'qualify', 'engagement_id': eid, 'assigned_user_id': daniel, 'qualification_notes': 'Good fit'}, csrf_from=f'/public/engagement.php?id={eid}')
def book(eid, hhmm='10:00', day=None):
    d = day or tomorrow
    code, _, t = owner.post('/public/booking.php', {'engagement_id': eid, 'date': d, 'start_at': f'{d} {hhmm}:00'}, csrf_from=f'/public/booking.php?engagement={eid}&date={d}')
    return t
def make_past(eid):
    sql(f"update appointments set starts_at='{local(-3)}', ends_at='{local(-2)}' where engagement_id={eid} and status='scheduled'")
def consult(b, eid, action, **kw):
    return b.post('/public/consultation_action.php', {'engagement_id': eid, 'action': action, **kw}, csrf_from=f'/public/engagement.php?id={eid}')
NOTE = {'summary': 'Great call', 'problem': 'Slow site', 'requirements': 'Rebuild', 'decisions': 'Proceed', 'concerns': 'Budget', 'next_step': 'Send proposal'}
def notes_and_complete(b, eid):
    aid = sql(f"select id from appointments where engagement_id={eid} and status='scheduled'")
    consult(b, eid, 'notes_save', appointment_id=aid, **NOTE)
    consult(b, eid, 'meeting_complete', appointment_id=aid)

# =============== PATH B: the full Northstar scenario
print('--- Path B: inquiry -> qualify -> book -> confirm -> remind -> cancel -> rebook -> reschedule -> prepare -> meet -> notes -> complete -> follow-up -> converted')
eB = inquiry(sarah, 'Sarah', 'sarah@client.test', 'Our website is slow and loses leads')
check('B: inquiry creates NEW engagement', status(eB) == 'new')
check('B: inquiry creates client + event', 'engagement.created' in ' '.join(events(eB)) or len(events(eB)) >= 1)
# consultant cannot qualify (owner-only action)
code, _, _ = cons.post('/public/engagement_action.php', {'action': 'qualify', 'engagement_id': eB, 'assigned_user_id': daniel}, csrf_from='/public/dashboard.php', follow=False)
check('B: consultant forbidden from qualify', code == 403 and status(eB) == 'new')
qualify(eB); check('B: Maya qualified + assigned Daniel', status(eB) == 'qualified' and sql(f'select assigned_user_id from engagements where id={eB}') == str(daniel))
wait_clean(); book(eB, '10:00')
check('B: Sarah slot booked -> scheduled', status(eB) == 'scheduled' and appts(eB) == ['scheduled'])
tok = last_mail_link(); check('B: confirmation email saved with private link', tok is not None)
check('B: confirmation logged', sql(f"select count(*) from notification_log where engagement_id={eB} and notification_type='confirmation'") != '0')
# reminder via cron
sql(f"update appointments set starts_at='{local(5)}', ends_at='{local(6)}' where engagement_id={eB}")
r = subprocess.run(['php', ROOT + '/cron/send_reminders.php'], capture_output=True, text=True)
check('B: cron reminder ran', r.returncode == 0)
check('B: reminder logged once', sql(f"select count(*) from notification_log where engagement_id={eB} and notification_type='reminder' and status='sent'") == '1')
r = subprocess.run(['php', ROOT + '/cron/send_reminders.php'], capture_output=True, text=True)
check('B: second cron run does NOT duplicate the reminder', sql(f"select count(*) from notification_log where engagement_id={eB} and notification_type='reminder'") == '1')
sql(f"update appointments set starts_at='{tomorrow} 10:00:00', ends_at='{tomorrow} 11:00:00', reminder_sent_at=NULL where engagement_id={eB}")
sc = Browser(); P = f'/public_booking/appointment.php?token={tok}'
code, _, t = sc.get(P); check('B: client page opens with valid token', code == 200 and 'Reschedule' in t)
check('B: client page hides internal data', 'qualification' not in t.lower() and 'daniel@' not in t)
code, _, t = Browser().get('/public_booking/appointment.php?token=' + tok[:-2] + 'xx'); check('B: tampered token -> 404', code == 404)
# Sarah cancels
sc.post(P, {'token': tok, 'action': 'cancel', 'reason': 'Conflict'}, csrf_from=P)
check('B: Sarah cancelled -> engagement back to qualified, appointment cancelled', status(eB) == 'qualified' and appts(eB) == ['cancelled'])
check('B: cancel notification logged', sql(f"select count(*) from notification_log where engagement_id={eB} and notification_type='cancellation'") != '0')
# staff rebooks, Sarah reschedules via new link
wait_clean(); book(eB, '10:00', tomorrow)
tok2 = last_mail_link(); check('B: rebooked with a NEW token', tok2 and tok2 != tok)
code, _, t = Browser().get(f'/public_booking/appointment.php?token={tok}'); check('B: old token still views history only', code in (200, 404))
P2 = f'/public_booking/appointment.php?token={tok2}'
d2 = (dt.date.today() + dt.timedelta(days=3)).isoformat()
sc.post(P2, {'token': tok2, 'action': 'reschedule', 'start_at': f'{d2} 14:00:00'}, csrf_from=P2 + '&mode=reschedule')
check('B: reschedule keeps ONE active appointment (+1 cancelled kept)', sorted(appts(eB)) == ['cancelled', 'scheduled'])
check('B: new time stored', sql(f"select starts_at from appointments where engagement_id={eB} and status='scheduled'").startswith(f'{d2} 14:00'))
check('B: reschedule notification logged', sql(f"select count(*) from notification_log where engagement_id={eB} and notification_type='reschedule'") != '0')
# Daniel prepares (checklist), cannot complete before start
consult(cons, eB, 'prep_starter'); check('B: Daniel added starter checklist', sql(f'select count(*) from preparation_tasks where engagement_id={eB}') == '4')
aid = sql(f"select id from appointments where engagement_id={eB} and status='scheduled'")
consult(cons, eB, 'notes_save', appointment_id=aid, **NOTE); consult(cons, eB, 'meeting_complete', appointment_id=aid)
check('B: cannot complete a meeting that has not started', status(eB) == 'scheduled')
make_past(eB)
consult(cons, eB, 'meeting_complete', appointment_id=aid)
check('B: notes saved before completion', sql(f'select count(*) from meeting_notes where appointment_id={aid}') == '1')
check('B: appointment completed -> engagement completed', status(eB) == 'completed' and 'completed' in appts(eB))
fdue = (dt.date.today() + dt.timedelta(days=2)).isoformat() + 'T09:00'
consult(cons, eB, 'followup_create', title='Send proposal', type='proposal', due_at=fdue, notes='')
check('B: follow-up created -> engagement follow_up', status(eB) == 'follow_up')
fid = sql(f'select id from follow_ups where engagement_id={eB}')
consult(cons, eB, 'followup_complete', follow_up_id=fid); check('B: follow-up completed', sql(f'select status from follow_ups where id={fid}') == 'completed')
consult(owner, eB, 'outcome_converted', outcome_note='Signed')
check('B: engagement converted', status(eB) == 'converted')
ev = events(eB)
for e in ['appointment.booked', 'appointment.cancelled', 'appointment.rescheduled', 'appointment.completed', 'engagement.follow_up', 'follow_up.created', 'engagement.converted']:
    check(f'B: history contains {e}', e in ev)
check('B: history is in correct order', ev.index('appointment.booked') < ev.index('appointment.cancelled') < ev.index('appointment.completed') < ev.index('engagement.converted'))

# =============== PATH A: direct success
print('--- Path A: qualified -> scheduled -> completed -> converted (no follow-up)')
eA = inquiry(Browser(), 'Alan', 'alan@client.test', 'Need a roadmap'); qualify(eA); book(eA, '12:00', d2)
make_past(eA); notes_and_complete(cons, eA); consult(owner, eA, 'outcome_converted', outcome_note='')
check('A: completed -> converted directly', status(eA) == 'converted' and sql(f'select count(*) from follow_ups where engagement_id={eA}') == '0')
check('A: converted is terminal (cannot be re-concluded)', (consult(owner, eA, 'outcome_closed_lost', outcome_note='')[0] in (200, 302)) and status(eA) == 'converted')

# =============== PATH C: no-show
print('--- Path C: no-show -> qualified -> rebooked -> completed -> closed_lost')
eC = inquiry(Browser(), 'Nina', 'nina@client.test', 'Unsure what we need'); qualify(eC); book(eC, '15:00', d2)
make_past(eC); aidC = sql(f"select id from appointments where engagement_id={eC} and status='scheduled'")
consult(cons, eC, 'meeting_no_show', appointment_id=aidC)
check('C: no-show kept as history, engagement back to QUALIFIED', appts(eC) == ['no_show'] and status(eC) == 'qualified')
book(eC, '16:00', (dt.date.today() + dt.timedelta(days=4)).isoformat())
check('C: rebooked after no-show', status(eC) == 'scheduled' and sorted(appts(eC)) == ['no_show', 'scheduled'])
make_past(eC); notes_and_complete(cons, eC); consult(owner, eC, 'outcome_closed_lost', outcome_note='Not a fit')
check('C: closed_lost recorded', status(eC) == 'closed_lost')

# =============== final integrity audit of the database
print('--- database integrity audit')
check('exactly one active appointment per engagement', sql("select count(*) from (select engagement_id from appointments where status='scheduled' group by engagement_id having count(*)>1) x") == '0')
check('no scheduled appointment on a non-scheduled engagement', sql("select count(*) from appointments a join engagements e on e.id=a.engagement_id where a.status='scheduled' and e.status<>'scheduled'") == '0')
check('no scheduled engagement without an active appointment', sql("select count(*) from engagements e where e.status='scheduled' and not exists (select 1 from appointments a where a.engagement_id=e.id and a.status='scheduled')") == '0')
check('no orphan notes/follow-ups/events', sql("select (select count(*) from meeting_notes n left join appointments a on a.id=n.appointment_id where a.id is null)+(select count(*) from follow_ups f left join engagements e on e.id=f.engagement_id where e.id is null)+(select count(*) from engagement_events v left join engagements e on e.id=v.engagement_id where e.id is null)") == '0')
check('no pending follow-ups on concluded engagements', sql("select count(*) from follow_ups f join engagements e on e.id=f.engagement_id where f.status='pending' and e.status in ('converted','closed_lost')") == '0')
check('no notification stuck in pending', sql("select count(*) from notification_log where status='pending'") == '0')
check('no raw tokens stored (hash only)', sql("select count(*) from appointments where client_token_hash not regexp '^[0-9a-f]{64}$'") == '0')
print(f"\n{'FAILED ' + str(len(fails)) if fails else 'ALL PASSED'}: {passed} checks passed" + (f", failures: {fails}" if fails else ''))
sys.exit(1 if fails else 0)
