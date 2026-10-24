#!/usr/bin/env python3
"""End-to-end smoke test for synccit, run against a throwaway Docker stack.

Covers the web pages, register/login/logout, devices, profile edits, every
admin action, password reset over real SMTP (captured by mailpit), and the
JSON / XML / plain-text sync API plus the account API. CI runs it before an
image is published.

It creates users, promotes one to admin with a known password and adds the
documented is_admin column, so it refuses to run unless the app is on
localhost and the database starts out with no users.

Run it locally (needs Docker and python3). The separate compose project keeps
`down -v` away from any other synccit stack's database:

    docker build -t synccit:smoke .
    SYNCCIT_IMAGE=synccit:smoke docker compose -p synccit-smoke \\
        -f docker-compose.yml -f tests/docker-compose.smoke.yml \\
        --profile internal-db up -d --wait
    python3 tests/smoke.py
    docker compose -p synccit-smoke -f docker-compose.yml \\
        -f tests/docker-compose.smoke.yml --profile internal-db down -v

Usage: smoke.py [BASE_URL] [DB_CONTAINER] [MAILPIT_URL]
  defaults: http://localhost:8080 synccit_db http://localhost:8025
DB credentials come from DB_USER / DB_PASS / DB_NAME (compose defaults).
Exit status is the number of failed checks (capped at 100). Stdlib only.
"""
import html
import http.cookiejar
import json
import os
import re
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

ARGS = sys.argv[1:] + [None] * 3
BASE = (ARGS[0] or "http://localhost:8080").rstrip("/")
DBC = ARGS[1] or "synccit_db"
MAILPIT = (ARGS[2] or "http://localhost:8025").rstrip("/")
DB_USER = os.environ.get("DB_USER", "synccit")
DB_PASS = os.environ.get("DB_PASS", "synccit")
DB_NAME = os.environ.get("DB_NAME", "rddtsync")
P1, P2 = "Sup3r-test-pass", "N3w-test-pass"
HEX64 = re.compile(r"^[0-9a-f]{64}$")
CODE6 = re.compile(r"^[0-9a-z]{6}$")
FAILS = 0


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


def client():
    return urllib.request.build_opener(
        urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), NoRedirect)


def req(op, path, data=None):
    body = urllib.parse.urlencode(data).encode() if data is not None else None
    try:
        r = op.open(urllib.request.Request(BASE + path, data=body), timeout=60)
        return r.status, r.headers, r.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        return e.code, e.headers, e.read().decode("utf-8", "replace")


def check(name, cond, detail=""):
    global FAILS
    ok = bool(cond)
    FAILS += not ok
    extra = "" if ok or not detail else "   [%s]" % re.sub(r"\s+", " ", str(detail))[:240]
    print(("PASS  " if ok else "FAIL  ") + name + extra, flush=True)
    return ok


def loc(h):
    return h.get("Location") or ""


def token(body, name="hash"):
    m = re.search(r'name="%s" value="([^"]*)"' % name, body)
    return m.group(1) if m else ""


def path_of(url):
    u = urllib.parse.urlparse(html.unescape(url))
    return (u.path or "/") + ("?" + u.query if u.query else "")


def form_action(body, default):
    m = re.search(r'<form[^>]*action="([^"]*)"', body)
    return path_of(m.group(1)) if m and m.group(1) else default


def sql(q):
    r = subprocess.run(["docker", "exec", DBC, "mariadb", "-u" + DB_USER, "-p" + DB_PASS, DB_NAME, "-N", "-e", q],
                       capture_output=True, text=True)
    return r.stdout.strip()


def api(path, payload=None, raw=None, typ="json"):
    data = {"data": raw if raw is not None else json.dumps(payload), "type": typ}
    return req(client(), path, data)


def jget(body):
    try:
        return json.loads(body)
    except ValueError:
        return None


def mailbox(addr):
    with urllib.request.urlopen(MAILPIT + "/api/v1/messages?limit=100", timeout=10) as r:
        msgs = json.load(r).get("messages") or []
    return [m for m in msgs if any(t.get("Address") == addr for t in m.get("To") or [])]


def wait_mail(addr, seen, timeout=20):
    """Body (text + html) of the newest message to addr once more than `seen` exist."""
    deadline = time.time() + timeout
    while time.time() < deadline:
        hits = mailbox(addr)
        if len(hits) > seen:
            with urllib.request.urlopen(MAILPIT + "/api/v1/message/" + hits[0]["ID"], timeout=10) as r:
                full = json.load(r)
            return (full.get("Text") or "") + "\n" + (full.get("HTML") or "")
        time.sleep(1)
    return None


# ── safety: only ever against a fresh local stack ──────────────────────────
if urllib.parse.urlparse(BASE).hostname not in ("localhost", "127.0.0.1", "::1"):
    sys.exit("refusing to run: %s is not localhost; smoke.py is for a throwaway stack" % BASE)
existing = sql("SELECT COUNT(*) FROM `user`")
if existing != "0":
    sys.exit("refusing to run: database in %s has users (%r) or is unreachable; "
             "smoke.py needs a fresh throwaway stack" % (DBC, existing))

print("== anonymous pages")
anon = client()
for p in ["/", "/faq", "/synccit-apps", "/donate", "/create-account", "/login", "/reset"]:
    s, h, b = req(anon, p)
    check("GET %s -> 200" % p, s == 200, "HTTP %s" % s)
for p in ["/devices", "/profile", "/links", "/admin"]:
    s, h, b = req(anon, p)
    check("GET %s (anon) -> redirect" % p, s == 302, "HTTP %s" % s)
# kept out of the image by .dockerignore. a missing dot-directory answers 403
# rather than 404 (Apache's "^\." rule matches the first missing path part)
for p in ["/api/test.php", "/.palial/bypass.log"]:
    s, h, b = req(anon, p)
    check("%s not served" % p, s in (403, 404), "HTTP %s" % s)

print("== register")
c = client()
reg = {"username": "smoke1", "password": P1, "passwordconfirm": P1,
       "email": "smoke1@example.invalid", "create": "create"}
s, h, b = req(c, "/create-account", reg)
check("register -> redirect to login", s == 302 and "login" in loc(h), "HTTP %s %s" % (s, loc(h)))
s, h, b = req(c, "/create-account", reg)
check("register duplicate -> 'username already exists'", s == 200 and "username already exists" in b, "HTTP %s" % s)
check("register page has no hidden hash field", 'name="hash"' not in b)
s, h, b = req(c, "/create-account", dict(reg, username="smoke2", passwordconfirm=P1 + "x"))
check("register mismatch -> 'passwords do not match'", "passwords do not match" in b, "HTTP %s" % s)

print("== login")
stored = sql("SELECT passhash FROM `user` WHERE username='smoke1'")
check("stored hash readable for leak checks", len(stored) > 20, stored)
s, h, b = req(c, "/login", {"username": "smoke1", "password": "wrong", "login": "login"})
check("bad password -> 401 with error message", s == 401 and "username or password wrong" in b, "HTTP %s" % s)
check("bad password -> stored hash NOT in page", stored and stored not in b)
check("bad password -> no hidden hash field", 'name="hash"' not in b)
s, h, b = req(c, "/login", {"username": "nosuchuser", "password": "wrong", "login": "login"})
check("unknown user -> same 401 and message", s == 401 and "username or password wrong" in b, "HTTP %s" % s)
s, h, b = req(c, "/login", {"username": "smoke1", "password": P1, "login": "login"})
check("good password -> redirect index.php", s == 302 and "index.php" in loc(h), "HTTP %s %s" % (s, loc(h)))
check("good password -> empty body (exit after redirect)", b.strip() == "", "%d bytes" % len(b))

print("== authenticated pages")
for p in ["/", "/profile", "/devices", "/links", "/faq", "/synccit-apps"]:
    s, h, b = req(c, p)
    check("GET %s (authed) -> 200" % p, s == 200, "HTTP %s" % s)

print("== devices")
for name in ("smokedev-keep", "smokedev-remove"):
    s, h, page = req(c, "/devices")
    check("CSRF token is 64 hex chars", HEX64.match(token(page)), token(page))
    s, h, b = req(c, form_action(page, "/devices"), {"hash": token(page), "device": name, "submit": "add device"})
    check("add device %s -> listed" % name, s == 200 and name in b, "HTTP %s" % s)
code = sql("SELECT authhash FROM authcodes WHERE description='smokedev-remove'")
check("device auth code is 6 chars of [0-9a-z]", CODE6.match(code), code)
s, h, b = req(c, "/devices")
rm = [u for u in re.findall(r'href="([^"]+)"', b) if code and code in u and "remove" in u]
check("remove link present for device", rm, code)
if rm:
    s, h, b = req(c, path_of(rm[0]))
    check("remove device -> gone from DB",
          sql("SELECT COUNT(*) FROM authcodes WHERE description='smokedev-remove'") == "0", "HTTP %s" % s)

print("== profile edit, logout, re-login")
s, h, page = req(c, "/profile")
s, h, b = req(c, form_action(page, "/profile"),
              {"hash": token(page), "do": "edit", "email": "smoke1b@example.invalid",
               "oldpassword": P1, "password": P2, "passwordconfirm": P2, "save": "save"})
check("profile edit -> 200", s == 200, "HTTP %s" % s)
check("profile edit -> email updated",
      sql("SELECT email FROM `user` WHERE username='smoke1'") == "smoke1b@example.invalid")
s, h, b = req(c, "/")
m = re.search(r'href="([^"]*logout[^"]*)"', b)
check("logout link present", m)
if m:
    logout_token = re.split(r"[/=]", path_of(m.group(1)))[-1]
    check("login token (in logout link) is 64 hex chars", HEX64.match(logout_token), logout_token)
    s, h, b = req(c, path_of(m.group(1)))
    check("logout -> redirect login.php", s == 302 and "login" in loc(h), "HTTP %s %s" % (s, loc(h)))
s, h, b = req(c, "/profile")
check("after logout /profile -> redirect", s == 302, "HTTP %s" % s)
s, h, b = req(c, "/login", {"username": "smoke1", "password": P2, "login": "login"})
check("login with changed password -> redirect index.php",
      s == 302 and "index.php" in loc(h), "HTTP %s %s" % (s, loc(h)))

print("== admin")
# is_admin is a documented manual migration (diff.sql / README), not part of mysql.sql
sql("ALTER TABLE `user` ADD COLUMN IF NOT EXISTS is_admin INT(1) NOT NULL DEFAULT '0'")
sql("UPDATE `user` SET is_admin=1 WHERE username='smoke1'")
s, h, b = req(c, "/admin")
check("GET /admin (admin) -> 200 lists users", s == 200 and "smoke1" in b, "HTTP %s" % s)


def admin_post(page, fields):
    s, h, b = req(c, page)
    return req(c, "/admin", dict(fields, csrf=token(b, "csrf")))


s, h, b = admin_post("/admin?action=create",
                     {"action": "create", "username": "smoke_made", "password": P1, "email": ""})
check("admin create user -> 'user created'", "user created" in b, "HTTP %s" % s)
s, h, b = admin_post("/admin?action=create",
                     {"action": "create", "username": "smoke1", "password": P1, "email": ""})
check("admin create taken username -> 'username already exists'",
      s == 200 and "username already exists" in b, "HTTP %s" % s)
uid = sql("SELECT id FROM `user` WHERE username='smoke_made'")
check("admin-created user in DB", uid.isdigit(), uid)
uid = uid if uid.isdigit() else "0"
ep = "/admin?action=edit&id=" + uid
s, h, b = req(c, ep)
check("admin edit page -> 200", s == 200 and "smoke_made" in b, "HTTP %s" % s)
s, h, b = admin_post(ep, {"action": "edit", "id": uid, "username": "smoke_made", "email": "made@example.invalid"})
check("admin edit -> email saved", sql("SELECT email FROM `user` WHERE id=" + uid) == "made@example.invalid",
      "HTTP %s" % s)
s, h, b = admin_post(ep, {"action": "edit", "id": uid, "username": "smoke1", "email": "made@example.invalid"})
check("admin rename to taken username -> 'username already exists'",
      s == 200 and "username already exists" in b, "HTTP %s" % s)
s, h, b = admin_post(ep, {"action": "password", "id": uid, "password": P2})
s2, h2, b2 = req(client(), "/login", {"username": "smoke_made", "password": P2, "login": "login"})
check("admin set password -> user can log in with it", s2 == 302 and "index.php" in loc(h2),
      "HTTP %s/%s" % (s, s2))
s, h, b = admin_post(ep, {"action": "add_key", "id": uid, "description": "adminkey"})
kid = sql("SELECT id FROM authcodes WHERE userid=%s AND description='adminkey'" % uid)
check("admin add key -> in DB", kid.isdigit(), "HTTP %s" % s)
s, h, b = admin_post(ep, {"action": "delete_key", "id": uid, "key_id": kid or "0"})
check("admin delete key -> gone",
      sql("SELECT COUNT(*) FROM authcodes WHERE userid=%s AND description='adminkey'" % uid) == "0",
      "HTTP %s" % s)
s, h, b = admin_post(ep, {"action": "delete", "id": uid})
check("admin delete user -> gone", sql("SELECT COUNT(*) FROM `user` WHERE id=" + uid) == "0", "HTTP %s" % s)

print("== password reset over email")
r = client()
s, h, page = req(r, "/reset")
check("GET /reset -> 200", s == 200, "HTTP %s" % s)
if s == 200:
    addr = "smoke1b@example.invalid"
    seen = len(mailbox(addr))
    s, h, b = req(r, form_action(page, "/reset"), {"hash": token(page), "reset": "reset", "email": addr})
    check("reset request -> 'password reset email sent'", "password reset email sent" in b,
          "HTTP %s %s" % (s, b[-300:]))
    body = wait_mail(addr, seen)
    check("reset email delivered", body)
    m = re.search(r"reset\.php\?u=(\d+)(?:&|&amp;)t=([0-9a-f]+)", body or "")
    check("reset email contains reset link", m)
    if m:
        seen = len(mailbox(addr))
        s, h, b = req(r, "/reset.php?u=%s&t=%s" % m.groups())
        check("follow reset link -> 'new password has been emailed'", "new password has been emailed" in b,
              "HTTP %s" % s)
        body = wait_mail(addr, seen)
        pm = re.search(r"reset to, (\S+)", body or "")
        check("new-password email delivered", pm)
        if pm:
            s, h, b = req(client(), "/login", {"username": "smoke1", "password": pm.group(1), "login": "login"})
            check("login with emailed password -> redirect index.php",
                  s == 302 and "index.php" in loc(h), "HTTP %s" % s)

print("== api.php JSON")
A = "/api/api.php"
s, h, b = api(A, {"username": "apiuser1", "password": P1, "email": "", "dev": "smoke", "mode": "create"})
check("json create -> account created", (jget(b) or {}).get("success") == "account created", b)
s, h, b = api(A, {"username": "apiuser1", "password": P1, "email": "", "dev": "smoke", "mode": "create"})
check("json create duplicate -> 'username already exists'",
      (jget(b) or {}).get("error") == "username already exists", b)
s, h, b = api(A, {"username": "apiuser1", "password": P1, "device": "apidev", "dev": "smoke", "mode": "addauth"})
auth = (jget(b) or {}).get("auth", "")
check("json addauth -> 6-char auth code", CODE6.match(auth), b)
links = [{"id": "111111"}, {"id": "222222", "comments": "132"},
         {"id": "333333", "comments": "313", "both": True}, {"id": "444444"}]
s, h, b = api(A, {"username": "apiuser1", "auth": auth, "dev": "smoke", "mode": "update", "links": links})
check("json update -> 4 links updated", (jget(b) or {}).get("success") == "4 links updated", b)
s, h, b = api(A, {"username": "apiuser1", "auth": auth, "dev": "smoke", "mode": "read",
                  "links": [{"id": "111111"}, {"id": "333333"}]})
check("json read -> both links returned", jget(b) is not None and "111111" in b and "333333" in b, b)
s, h, b = api(A, {"username": "apiuser1", "auth": auth, "dev": "smoke", "mode": "history", "time": 0})
check("json history -> all 4 links",
      jget(b) is not None and all(i in b for i in ("111111", "222222", "333333", "444444")), b)
s, h, b = api(A, {"username": "apiuser1", "auth": "badauth", "dev": "smoke", "mode": "read",
                  "links": [{"id": "111111"}]})
check("json bad auth -> error", "error" in (jget(b) or {}), b)
s, h, b = api(A, raw="{not json")
check("json malformed -> error", "error" in (jget(b) or {}), b)

print("== api.php XML")


def xml(mode, inner):
    return ('<?xml version="1.0"?><synccit><username>apiuser1</username><auth>%s</auth><dev>smoke</dev>'
            '<mode>%s</mode>%s</synccit>' % (auth, mode, inner))


s, h, b = api(A, raw=xml("update", "<links><link><id>555555</id></link><link><id>666666</id>"
                                   "<comments>10</comments><both>true</both></link></links>"), typ="xml")
check("xml update -> 2 links updated", "2 links updated" in b, b)
s, h, b = api(A, raw=xml("read", "<links><link><id>555555</id></link><link><id>666666</id></link></links>"),
              typ="xml")
check("xml read -> links returned", "555555" in b and "666666" in b, b)
s, h, b = api(A, raw=xml("history", "<time>0</time><offset>0</offset>"), typ="xml")
check("xml history -> includes xml-synced links", "555555" in b and "666666" in b, b)

print("== api.php plain text")
s, h, b = req(client(), A, {"username": "apiuser1", "auth": auth, "mode": "update",
                            "links": "777777,888888", "comments": "888888:5", "dev": "smoke"})
check("text update -> links updated", "links updated" in b, b)
s, h, b = req(client(), A, {"username": "apiuser1", "auth": auth, "mode": "read", "links": "777777,888888"})
check("text read -> links returned", "777777" in b and "888888" in b, b)

print("== account.php JSON")
AC = "/api/account.php"
s, h, b = api(AC, {"username": "apiuser2", "password": P1, "email": "", "dev": "smoke", "mode": "create"})
check("account create -> account created", (jget(b) or {}).get("success") == "account created", b)
s, h, b = api(AC, {"username": "apiuser2", "password": P1, "email": "", "dev": "smoke", "mode": "create"})
check("account create duplicate -> 'username already exists'",
      (jget(b) or {}).get("error") == "username already exists", b)
s, h, b = api(AC, {"username": "apiuser1", "password": P1, "dev": "smoke", "mode": "login"})
login = (jget(b) or {}).get("login", "")
check("account login -> 64-hex login code", HEX64.match(login), b)
s, h, b = api(AC, {"username": "apiuser1", "login": login, "dev": "smoke", "mode": "devices"})
check("account devices -> lists apidev", "apidev" in b, b)
s, h, b = api(AC, {"username": "apiuser1", "login": login, "dev": "smoke", "mode": "history"})
check("account history -> includes synced links", "111111" in b or "555555" in b, b)
s, h, b = api(AC, {"username": "apiuser1", "login": login, "dev": "smoke", "mode": "addauth", "device": "apidev2"})
auth2 = (jget(b) or {}).get("auth", "")
check("account addauth -> 6-char auth code", CODE6.match(auth2), b)
s, h, b = api(AC, {"username": "apiuser1", "login": login, "dev": "smoke", "mode": "delete",
                   "devices": [{"id": auth2}]})
check("account delete -> '1 devices deleted'", "1 devices deleted" in b, b)
check("account delete -> apidev2 gone from DB",
      sql("SELECT COUNT(*) FROM authcodes WHERE description='apidev2'") == "0")

print("== links page with synced data")
w = client()
req(w, "/login", {"username": "apiuser1", "password": P1, "login": "login"})
s, h, b = req(w, "/links")
check("links page shows synced links", s == 200 and "111111" in b and "555555" in b, "HTTP %s" % s)

print(f"== {FAILS} failure(s)")
sys.exit(min(FAILS, 100))
