#!/usr/bin/env python3
"""HTTP checks that rate limiting works on the real pages (needs php -S running, see northstar_e2e.py).
Clears storage/ratelimit before and after. Usage: python3 tests/ratelimit_http.py [base_url]"""
import sys, os, re, glob, subprocess, urllib.request, urllib.parse, http.cookiejar, urllib.error
BASE = sys.argv[1] if len(sys.argv) > 1 else 'http://127.0.0.1:8099/appointflow'
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DB = ['mariadb', '-uroot', '-N', '-B', 'appointflow']
fails, passed = [], 0
def check(l, ok):
    global passed
    if ok: passed += 1
    else: fails.append(l); print('FAIL:', l)
def sql(q): return subprocess.run(DB + ['-e', q], capture_output=True, text=True, check=True).stdout.strip()
def reset():
    for f in glob.glob(ROOT + '/storage/ratelimit/*'): os.remove(f)
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k): return None
class B:
    def __init__(s): s.op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), NoRedirect)
    def req(s, path, data=None, headers=None):
        body = urllib.parse.urlencode(data).encode() if data is not None else None
        rq = urllib.request.Request(BASE + path, data=body, headers=headers or {})
        try: r = s.op.open(rq, timeout=30); return r.status, r.headers, r.read().decode('utf-8', 'replace')
        except urllib.error.HTTPError as e: return e.code, e.headers, e.read().decode('utf-8', 'replace')
    def post(s, path, data, form_path=None, headers=None):
        _, _, t = s.req(form_path or path); m = re.search(r'name="csrf_token" value="([^"]+)"', t)
        return s.req(path, {**data, 'csrf_token': m.group(1) if m else ''}, headers)

slug = sql('select booking_slug from workspaces limit 1'); svc = sql('select id from services where active=1 limit 1')
def login(email, pw, hdr=None): return B().post('/public/login.php', {'email': email, 'password': pw}, headers=hdr)

reset()
# --- login: wrong passwords are limited per IP; spoofed X-Forwarded-For does not help
codes = [login('maya@northstar.test', 'wrong%d' % i, {'X-Forwarded-For': '9.9.9.%d' % i})[0] for i in range(10)]
check('first 10 wrong passwords get a normal answer (200)', codes == [200] * 10)
code, hdr, body = login('maya@northstar.test', 'wrong-again', {'X-Forwarded-For': '8.8.8.8'})
check('11th attempt -> 429 even with a spoofed X-Forwarded-For', code == 429)
check('429 carries Retry-After (seconds, <= 900)', hdr.get('Retry-After', '').isdigit() and 0 < int(hdr['Retry-After']) <= 900)
check('429 page shows a friendly message', 'Too many attempts' in body)
code, hdr, _ = login('maya@northstar.test', 'TestPass123!')
check('even the CORRECT password is refused while blocked (no brute-force oracle)', code == 429)
check('blocked request did not log anyone in', 'Location' not in hdr or 'dashboard' not in (hdr.get('Location') or ''))

# --- recovery: after the window (simulated by clearing) a real user can sign in
reset()
code, hdr, _ = login('maya@northstar.test', 'TestPass123!')
check('after reset the owner can sign in (302 to dashboard)', code == 302 and 'dashboard' in hdr.get('Location', ''))

# --- successful logins are given back: 15 good sign-ins in a row never trip the IP limit
reset()
ok = [login('maya@northstar.test', 'TestPass123!')[0] for _ in range(15)]
check('15 consecutive SUCCESSFUL sign-ins are all allowed', ok == [302] * 15)
# ...and a few typos in between do not lock a normal user
reset()
seq = [login('maya@northstar.test', 'typo')[0] for _ in range(3)] + [login('maya@northstar.test', 'TestPass123!')[0]]
check('3 typos then the right password works', seq == [200, 200, 200, 302])

# --- per-account limit: stops an attack spread over many IPs. One IP is stopped by the IP bucket first (10),
# so to exercise the ACCOUNT bucket (20) we clear the IP bucket between bursts, as separate IPs would.
import hashlib
reset()
ip_file = ROOT + '/storage/ratelimit/' + hashlib.sha256(b'login_ip|127.0.0.1').hexdigest() + '.rl'
for burst in range(2):
    for i in range(10): login('daniel@northstar.test', 'bad%d_%d' % (burst, i))
    if os.path.exists(ip_file): os.remove(ip_file)
code, _, body = login('daniel@northstar.test', 'Consult123!')
check('account bucket (20 failures/15 min) blocks the account from a "fresh" IP', code == 429)
code, _, _ = login('maya@northstar.test', 'TestPass123!')
check('...but a different account is unaffected', code == 302)

# --- public inquiry: 8 per 10 min, nothing created once refused
reset(); before = int(sql('select count(*) from engagements'))
def inquiry(i): return B().post(f'/public_booking/inquiry.php?business={slug}', {'business': slug, 'service_id': svc, 'first_name': 'Spam', 'last_name': 'Bot', 'email': f'spam{i}@bot.test', 'phone': '', 'company_name': '', 'problem_summary': 'x', 'additional_information': ''}, form_path=f'/public_booking/inquiry.php?business={slug}&service={svc}')
codes = [inquiry(i)[0] for i in range(8)]
check('first 8 inquiries accepted (302 to confirmation)', codes == [302] * 8)
code, hdr, body = inquiry(99)
check('9th inquiry -> 429 + Retry-After', code == 429 and hdr.get('Retry-After', '').isdigit())
check('refused inquiry creates NOTHING', int(sql('select count(*) from engagements')) == before + 8 and sql("select count(*) from clients where email='spam99@bot.test'") == '0')
check('refused inquiry re-renders the form with a message', 'Too many attempts' in body and '<form' in body)
check('viewing the form (GET) is never limited', B().req(f'/public_booking/inquiry.php?business={slug}')[0] == 200)

# --- find my appointments: 6 per 15 min
reset()
def find(i): return B().post(f'/public_booking/find.php?business={slug}', {'business': slug, 'email': f'who{i}@x.test'}, form_path=f'/public_booking/find.php?business={slug}')
codes = [find(i)[0] for i in range(6)]
check('first 6 find-my-appointments requests answered', codes == [200] * 6)
check('7th -> 429', find(7)[0] == 429)

# --- other pages are not affected by an exhausted limiter
check('booking page and login page still open while blocked', B().req(f'/public_booking/index.php?business={slug}')[0] == 200 and B().req('/public/login.php')[0] == 200)
# --- storage holds no readable IP/email
names = [os.path.basename(f) for f in glob.glob(ROOT + '/storage/ratelimit/*')]
blob = ''.join(open(f).read() for f in glob.glob(ROOT + '/storage/ratelimit/*'))
check('counter files are hash-named and contain only timestamps', names and all(re.fullmatch(r'[0-9a-f]{64}\.rl', n) for n in names) and re.fullmatch(r'[0-9\s]*', blob) is not None)
# NOTE: php -S ignores .htaccess, so web denial can only be verified on Apache/XAMPP (see README note). Static check here:
ht = open(ROOT + '/.htaccess').read(); sht = open(ROOT + '/storage/.htaccess').read()
check('Apache rules deny /storage (root rewrite rule + storage/.htaccess)', 'storage' in ht.split('[F,L]')[0] and 'Require all denied' in sht)
reset()
print(f"\n{'FAILED ' + str(len(fails)) if fails else 'ALL PASSED'}: {passed} checks passed" + (f", failures: {fails}" if fails else ''))
sys.exit(1 if fails else 0)
