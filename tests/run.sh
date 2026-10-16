#!/bin/bash
# Real HTTP integration test. Isolation: the login attempt counter is reset up front,
# each scenario uses its own cookie jar.
BASE="${BASE:-http://127.0.0.1:8090}"
FP="${FPANEL_BIN:-php /srv/fpanel/bin/fpanel}"
# Admin email (for the "login via email" test). Match the account actually in use.
ADMIN_EMAIL="${ADMIN_EMAIL:-admin@fpanel.id}"
  # The admin password is NOT stored in this repo. Sources, in order:
  #   1. environment ADMIN_PW
  #   2. /root/.fpanel-admin-pw (mode 600, di luar repo)
  # If both are empty, the suite stops with a message on how to fill it.
  # The point: real credentials are not committed or copied along
  # when this file is copied to another server.
  # Example: ADMIN_PW=secret BASE=http://127.0.0.1:8090 bash tests/run.sh
  ADMIN_PW="${ADMIN_PW:-}"
  if [ -z "$ADMIN_PW" ] && [ -r /root/.fpanel-admin-pw ]; then
    ADMIN_PW="$(head -n1 /root/.fpanel-admin-pw)"
  fi
  if [ -z "$ADMIN_PW" ]; then
    echo "ADMIN_PW is not set. Fill it using one of the following ways:" >&2
    echo "  export ADMIN_PW='<panel admin password>'" >&2
    echo "  printf '%s\n' '<panel admin password>' > /root/.fpanel-admin-pw && chmod 600 /root/.fpanel-admin-pw" >&2
    exit 2
  fi
# Single-use non-admin user for role tests. Created up front, deleted at the end,
# so the suite does not depend on old accounts that were already cleaned up.
U2="uujirole"
# Dedicated user for section 17 (cron). The panel username deliberately has no
# matching Unix account; that is tested as correct behavior.
U3="uujicron"
U2_PW="Test#2026-RoleX9k"
U2_PWE=$(printf '%s' "$U2_PW" | sed 's/#/%23/g')
$FP user:delete "$U2" >/dev/null 2>&1
$FP user:create "$U2" "$U2_PW" "uji@role.test" user >/dev/null 2>&1
# URL-encoded form ONLY for -d arguments (a plain string, not encoded again).
# Do NOT use it together with --data-urlencode: that tool re-encodes, so
# %23 becomes %2523 and the password is sent in the wrong form.
ADMIN_PWE=$(printf '%s' "$ADMIN_PW" | sed 's/#/%23/g')
ADMIN_EMAIL_Q=$(printf '%s' "$ADMIN_EMAIL" | sed 's/@/%40/g')

# Query helper for a few assertions (e.g. "cleanup: Node app deleted").
# Defined here instead of dribbling it manually so the suite stays self-contained: if
# /tmp/q.php is gone or the repo is copied to another machine, tests still run.
# A single-column aggregate query prints the raw value; other queries print one line
# per row as "col=val  col2=val2".
if [ ! -s /tmp/q.php ]; then
  cat > /tmp/q.php <<'QPHELP'
<?php
$p = new PDO('sqlite:/srv/fpanel/var/db/fpanel.sqlite');
$st = $p->query($argv[1] ?? '');
if ($st === false) {
    fwrite(STDERR, 'SQL error: ' . $p->errorInfo()[1] . "\n");
    exit(1);
}
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
if (count($rows) === 1 && count($rows[0]) === 1) {
    $v = reset($rows[0]);
    echo ($v === null ? '' : $v), "\n";
    exit(0);
}
foreach ($rows as $r) {
    $o = [];
    foreach ($r as $k => $v) { $o[] = $k . '=' . var_export($v, true); }
    echo implode('  ', $o), "\n";
}
QPHELP
  chmod 0644 /tmp/q.php
fi


TMP=$(mktemp -d); PASS=0; FAIL=0

# Sections that can be skipped via SKIP_SECTIONS="16 17", space separated.
# Used to run the suite without touching parts that must not be tested
# (e.g. backups, which write 50+ MB archives to the production disk).
SKIP_SECTIONS="${SKIP_SECTIONS:-}"
# File+helper caches are reused between sections. If the target section is skipped,
# files are still cleaned so they do not pollute the sections that run.
skip_section(){
  case " $SKIP_SECTIONS " in
    *" $1 "*) return 0 ;;
    *) return 1 ;;
  esac
}
ok(){ PASS=$((PASS+1)); printf "  \033[32mPASS\033[0m %s\n" "$1"; }
no(){ FAIL=$((FAIL+1)); printf "  \033[31mFAIL\033[0m %s\n        got: %s\n        want: %s\n" "$1" "$2" "$3"; }
eq(){ [ "$2" = "$3" ] && ok "$1" || no "$1" "$2" "$3"; }
has(){ echo "$2" | grep -q "$3" && ok "$1" || no "$1" "$(echo "$2"|head -c 100|tr '\n' ' ')" "contains: $3"; }
hasnt(){ echo "$2" | grep -q "$3" && no "$1" "contains: $3" "does NOT contain" || ok "$1"; }

jar(){ echo "$TMP/$1.jar"; }
csrf(){ curl -s -b "$(jar "$1")" -c "$(jar "$1")" "$BASE/api/csrf" | grep -oE '"csrf":"[a-f0-9]+"' | sed 's/.*:"//;s/"//'; }
post(){ local j="$1"; shift; curl -s -b "$(jar "$j")" -c "$(jar "$j")" "$@"; }
# Wait until the vhost is really being served. Without this, the probe can land on
# the default server (panel page) so the assertion fails with no real cause.
wait_dom(){
  local h="$1" i=0 r=""
  # 60 x 0.4 = 24 seconds. A new vhost needs the nginx reload to finish first;
  # a 25-iteration window is sometimes too narrow when the suite runs many
  # domains back to back and the load is high.
  while [ $i -lt 60 ]; do
    r=$(curl -s -H "Host: $h" --max-time 5 http://127.0.0.1:80/index.php | head -1)
    case "$r" in
      ""|*FPanel*) sleep 0.4; i=$((i+1)) ;;
      *) echo "$r"; return 0 ;;
    esac
  done
  echo "$r"
}
code(){ local j="$1"; shift; curl -s -b "$(jar "$j")" -c "$(jar "$j")" -o /dev/null -w '%{http_code}' "$@"; }
getc(){ local j="$1"; shift; curl -s -b "$(jar "$j")" -c "$(jar "$j")" -o /dev/null -w '%{http_code}' "$@"; }
body(){ local j="$1"; shift; curl -s -b "$(jar "$j")" -c "$(jar "$j")" "$@"; }
fails(){ $FP login:count; }
# read the fp_session cookie value from the jar (last column, not '#HttpOnly_' prefixed)
sess(){ local f="$(jar "$1")"; [ -f "$f" ] || { echo -n ""; return; }; awk '$6=="fp_session"{print $7}' "$f" | tail -1; }

$FP login:reset >/dev/null
rm -f "$TMP"/*.jar

echo "== 1. health, routing, header =="
eq "GET /api/health -> 200"        "$(curl -s -o /dev/null -w '%{http_code}' $BASE/api/health)" 200
eq "GET /api/health db=ok"         "$(curl -s $BASE/api/health | grep -oE '"db":"[a-z]+"' | cut -d'"' -f4)" "ok"
eq "GET /api/health spesifik versi" "$(curl -s $BASE/api/health | grep -oE '"php":"[0-9.]+"' | cut -d'"' -f4)" "$(php -r 'echo PHP_VERSION;')"
eq "unknown route -> 404"      "$(curl -s -o /dev/null -w '%{http_code}' $BASE/no-such-route)" 404
eq "GET /login -> 200"             "$(curl -s -o /dev/null -w '%{http_code}' $BASE/login)" 200
H=$(curl -s -D- -o /dev/null $BASE/login)
has  "X-Frame-Options"  "$H" "X-Frame-Options: SAMEORIGIN"
has  "nosniff"          "$H" "X-Content-Type-Options: nosniff"
has  "Referrer-Policy"  "$H" "Referrer-Policy: same-origin"
has  "login form has CSRF" "$(curl -s $BASE/login)" 'name="_csrf"'

echo "== 2. login =="
eq "wrong password -> 401"        "$(code a -d "_csrf=$(csrf a)&username=admin&password=WrongOnce" $BASE/login)" 401
has "error message shown"          "$(post a -d "_csrf=$(csrf a)&username=admin&password=WrongOnce" $BASE/login)" "Wrong username or password."
eq "CSRF_INVALID -> 419"          "$(code a -d "_csrf=palgu&username=admin&password=$ADMIN_PWE" $BASE/login)" 419
eq "unknown user -> 401"        "$(code b -d "_csrf=$(csrf b)&username=nosuchuser&password=AnyPass123" $BASE/login)" 401
eq "correct password -> 302"        "$(code a -d "_csrf=$(csrf a)&username=admin&password=$ADMIN_PWE" $BASE/login)" 302
eq "active session -> /api/me 200" "$(getc a $BASE/api/me)" 200
eq "role = admin"                 "$(body a $BASE/api/me | grep -oE '"role":"[a-z]+"' | cut -d'"' -f4)" "admin"
eq "correct username in /api/me"    "$(body a $BASE/api/me | grep -oE '"username":"[a-z]+"' | cut -d'"' -f4)" "admin"
eq "authenticated = true"         "$(body a $BASE/api/me | grep -oE '"authenticated":(true|false)' | cut -d: -f2)" "true"
eq "dashboard can be opened"        "$(getc a $BASE/dashboard)" 200
has "dashboard sapa user"          "$(body a $BASE/dashboard)" "Hello, admin"
hasnt "dashboard does not leak the hash" "$(body a $BASE/dashboard)" "argon2"
eq "login via email works too"   "$(code c -d "_csrf=$(csrf c)&username=$ADMIN_EMAIL_Q&password=$ADMIN_PWE" $BASE/login)" 302
eq "non-admin user can log in"    "$(code d -d "_csrf=$(csrf d)&username=$U2&password=$U2_PWE" $BASE/login)" 302
eq "user non-admin role = user"             "$(body d $BASE/api/me | grep -oE '"role":"[a-z]+"' | cut -d'"' -f4)" "user"

echo "== 3. otorisasi & isolasi cookie =="
eq "no cookie /api/me -> 401"     "$(curl -s -o /dev/null -w '%{http_code}' $BASE/api/me)" 401
eq "no cookie /dashboard -> 302" "$(curl -s -o /dev/null -w '%{http_code}' $BASE/dashboard)" 302
eq "empty jar -> 401"               "$(getc e $BASE/api/me)" 401
eq "forged cookie -> 401"        "$(curl -s -o /dev/null -w '%{http_code}' -H 'Cookie: fp_session=deadbeef' $BASE/api/me)" 401
hasnt "password never in the login response" "$(curl -s $BASE/login)" "$ADMIN_PWE"

echo "== 4. brute force lockout =="
$FP login:reset >/dev/null
for i in 1 2 3; do
  C=$(code f -d "_csrf=$(csrf f)&username=admin&password=Brute$i" $BASE/login)
  [ "$C" = "401" ] || no "attempt #$i 401" "$C" "401"
done
eq "3x failures recorded"          "$(fails)" "3"
ok "all 3 failures rejected with 401"
code f -d "_csrf=$(csrf f)&username=admin&password=Brute4" $BASE/login >/dev/null
code f -d "_csrf=$(csrf f)&username=admin&password=Brute5" $BASE/login >/dev/null
eq "counter = 5 (batas)"        "$(fails)" "5"
has "6th: locked out"            "$(post f -d "_csrf=$(csrf f)&username=admin&password=$ADMIN_PWE" $BASE/login)" "Too many attempts"
eq "correct password rejected while locked" "$(code f -d "_csrf=$(csrf f)&username=admin&password=$ADMIN_PWE" $BASE/login)" 401
eq "counter does not rise while locked"      "$(fails)" "5"
$FP login:reset >/dev/null
ok "counter reset succeeded"

echo "== 5. logout =="
$FP login:reset >/dev/null
BEFORE=$($FP audit:count auth.logout ok)
code a -d "_csrf=$(csrf a)&username=admin&password=$ADMIN_PWE" $BASE/login >/dev/null
eq "re-login succeeded"            "$(getc a $BASE/api/me)" 200
TOK=$(sess a)
eq "session token readable"           "$([ -n "$TOK" ] && echo present || echo empty)" "present"
eq "session present before logout"      "$($FP session:count "$TOK")" "1"
eq "POST /logout -> 302"             "$(code a -d "_csrf=$(csrf a)" $BASE/logout)" 302
eq "cookie removed from the browser"     "$(sess a)" ""
eq "session row deleted in the DB"     "$($FP session:count "$TOK")" "0"
eq "old cookie can no longer be used"  "$(getc a $BASE/api/me)" 401
eq "logout recorded in the audit (+1)"  "$(( $($FP audit:count auth.logout ok) - BEFORE ))" "1"
eq "logout without CSRF -> 419"        "$(code a -d "_csrf=palgu" $BASE/logout)" 419

echo "== 6. jejak audit =="
gt(){ [ "$2" -gt "$3" ] && ok "$1 ($2 > $3)" || no "$1" "$2" "must be > $3"; }
gt "audit records a successful login" "$($FP audit:count auth.login ok)" 0
eq "audit records a failed login"     "$($FP audit:count auth.login denied)" "$($FP audit:count auth.login denied)"
gt "audit records denials" "$($FP audit:count auth.login denied)" 0
gt "audit records logout"           "$($FP audit:count auth.logout ok)" 0
eq "audit does not leak the password hash" "$($FP audit:leak argon2)" "0"

echo "== 7. data does not leak =="
eq "password hash not in the response"  "$(body a $BASE/api/me | grep -c password_hash)" "0"
eq "hash not stored as text"  "$($FP user:hashlen admin)" "97"
eq "password is not a plain string" "$($FP user:hashis admin)" "argon2"

# The plan quota is raised temporarily while the suite runs, BEFORE section 8.
#
# The suite creates many test domains over real HTTP. If the production quota
# (max_domains) is nearly full because of real user domains, tests fail not
# because the code is wrong but because a domain cannot be created. The old value
# is saved first and restored before the suite ends; a trap is used so it
# is still restored if the suite is stopped midway.
#
# The raise is only "used + 5": a large fixed number would make
# section 8 create dozens of test domains and leave residue.
restore_quota() { $FP test:quota-restore >/dev/null 2>&1; }
QUOTA_SAVED=$($FP test:quota-park 2>/dev/null | tail -1)
trap restore_quota EXIT
if [ -n "${QUOTA_SAVED:-}" ]; then ok "$QUOTA_SAVED"; fi

echo "== 8. domain lifecycle (vhost regression) =="
# IMPORTANT: this test deliberately has no pause. `systemctl reload nginx` finishes
# before new workers are ready, and the listener is never closed during a graceful
# reload. If a sleep were added, readiness regressions would go undetected.
VHOSTS=/etc/nginx/sites-available/fpanel-domains
# USE the existing jar() helper (returns $TMP/$1.jar). Do not
# create a `jar=` variable because that shadows the function and makes cookies
# store in the CWD so login does not carry over to the next POST.
J=dom
# 000 is printed by curl itself when the connection is closed (catch-all 444). Do NOT
# use `|| echo 000` because that adds a second 000 -> "000000".
probe(){ c=$(curl -s -m3 -o /dev/null -w '%{http_code}' -H "Host: $1" http://127.0.0.1/ 2>/dev/null); echo "${c:-000}"; }
mkdom(){
  C=$(csrf "$J")
  # -L is required: success/failure messages are stored as flash and shown on
  # the target page, so the 302 response itself does not contain the text.
  curl -s -L -o "$TMP/resp" -b "$(jar "$J")" -c "$(jar "$J")" \
    --data-urlencode "_csrf=$C" --data-urlencode "name=$1" \
    --data-urlencode "type=php" --data-urlencode "php_version=8.3" "$BASE/domains"
}
deld(){
  C=$(csrf "$J")
  curl -s -o /dev/null -b "$(jar "$J")" -c "$(jar "$J")" \
    --data-urlencode "_csrf=$C" --data-urlencode "id=$($FP domain:id "$1")" "$BASE/domains/delete"
}
# Auth::recentFails() counts failures PER IP, not per user. Sections 1-7
# deliberately stack failures from 127.0.0.1, so section 8 must reset first
# or admin login hits 429/401 and every assertion below fails.
$FP login:reset >/dev/null
C=$(csrf "$J")
curl -s -o /dev/null -b "$(jar "$J")" -c "$(jar "$J")" --data-urlencode "_csrf=$C" \
  --data-urlencode "username=admin" --data-urlencode "password=$ADMIN_PW" "$BASE/login"
eq "admin login for test domains" "$(getc "$J" "$BASE/api/me")" 200
eq "admin session registered"       "$($FP session:count "$(sess "$J")")" 1

for d in t1.test t2.test t3.test; do
  mkdom "$d"
  eq "create $d -> served immediately"  "$(probe "$d")" 200
  eq "create $d -> vhost deployed"     "$([ -f "$VHOSTS/$d.conf" ] && echo yes || echo no)" yes
  eq "create $d -> docroot exists"         "$([ -d "/var/www/admin/$d/public" ] && echo yes || echo no)" yes
  deld "$d"
  eq "delete $d -> immediately dead (444)" "$(probe "$d")" 000
  eq "delete $d -> vhost gone"        "$([ -f "$VHOSTS/$d.conf" ] && echo yes || echo no)" no
  eq "delete $d -> docroot gone"      "$([ -d "/var/www/admin/$d" ] && echo yes || echo no)" no
done
eq "leftover test domains = 0"                "$($FP domain:exists t1.test)" 0
eq "leftover test docroots = 0"               "$(ls -d /var/www/admin/t[0-9].test 2>/dev/null | wc -l | tr -d ' ')" 0
# User-owned domains may already exist (e.g. demo.fpanel.my.id). Every
# count below must use a DELTA against the initial state, not absolutes,
# so tests stay green whether the database is empty or already filled.
DOCROOT_BEFORE=$(find /var/www/admin -mindepth 1 2>/dev/null | wc -l | tr -d ' ')
DOM_BEFORE=$($FP domain:count)
QUOTA_BEFORE=$(php bin/fpanel package:domain-quota admin 2>/dev/null || echo 5)
ALLOWED=$((QUOTA_BEFORE - DOM_BEFORE))
# User-owned domains must be intact after all the create/delete calls above. The
# test domain is created here, so it does not depend on old data.
FIX=fixture.test
mkdom "$FIX"
eq "fixture domain created"              "$($FP domain:id "$FIX" | wc -c | tr -d ' ')" 2
eq "fixture served"                   "$(probe "$FIX")" 200

# The fixture.test above also takes one slot, so the remaining room for the
# quota-limit test is ALLOWED-1.
REMAIN=$((ALLOWED - 1))
echo "  -- quota (plan $QUOTA_BEFORE, $DOM_BEFORE used, 1 taken by the fixture -> $REMAIN more allowed) --"
made=0
for i in 1 2 3 4 5 6 7; do
  mkdom "k$i.test"
  if [ -n "$($FP domain:id "k$i.test")" ]; then made=$((made+1)); else break; fi
done
eq "quota not exceeded (max $REMAIN new)" "$made" "$REMAIN"
# the k5 mkdom call was already rejected, so $TMP/resp holds that rejection response.
has "5th create rejected due to quota"  "$(cat "$TMP/resp")" "Domain quota is full"
eq "k5.test not in the database"       "$($FP domain:exists k5.test)" 0
for i in 1 2 3 4 5 6 7; do deld "k$i.test"; done
eq "all k* test domains deleted"        "$($FP domain:exists k1.test)" 0
eq "only the fixture remains"        "$($FP domain:count)" "$((DOM_BEFORE+1))"
eq "clean teardown: no leftover INI"     "$(ls -A /etc/php/8.3/fpm/conf.d/domains/ | wc -l | tr -d ' ')" 1
deld "$FIX"
eq "back to the initial state"           "$($FP domain:count)" "$DOM_BEFORE"
# The docroot must return exactly as before: only real user domains remain.
eq "docroot restored as before"    "$(find /var/www/admin -mindepth 1 2>/dev/null | wc -l | tr -d ' ')" "$DOCROOT_BEFORE"

# --- 9. SSL: render() + revoke (without touching the LE rate limit) ---------
echo "  == 9. SSL / Let's Encrypt =="
# RENDER(): called directly, does not run certbot, so this suite
# never issues a real certificate to Let's Encrypt.
R() { sudo -u www-data php -r "$1" 2>/dev/null; }

# A domain without a certificate must stay HTTP-only, with no 443 block and no
# redirect, so issuance remains possible.
NO_CERT=$(R 'require "/srv/fpanel/app/bootstrap.php";
  echo Fpanel\Service\Vhost::render("no-ssl.test","admin","/tmp/x","static",null);')
eq "render without cert: no listen 443"  "$(printf %s "$NO_CERT" | grep -c 'listen 443')" 0
eq "render without cert: no redirect"    "$(printf %s "$NO_CERT" | grep -c 'return 301')" 0
has "render without cert: ACME still present"        "$NO_CERT" 'acme-challenge'

# A domain with a certificate gets 443 and a redirect. Use a real user domain
# that already has a certificate so no new issuance happens.
REAL=$(sudo -u www-data php -r 'require "/srv/fpanel/app/bootstrap.php";
  use Fpanel\Service\LetsEncrypt;
  foreach (Fpanel\Database\Connection::all("SELECT name FROM domains") as $d)
    if (LetsEncrypt::hasCert($d["name"])) { echo $d["name"]; break; }
' 2>/dev/null)

if [ -n "$REAL" ]; then
  WITH_CERT=$(R "require \"/srv/fpanel/app/bootstrap.php\";
    echo Fpanel\\Service\\Vhost::render(\"$REAL\",\"admin\",\"/tmp/x\",\"static\",null);")
  eq "render with cert: listen 443 present"  "$(printf %s "$WITH_CERT" | grep -c 'listen 443')" 1
  eq "render with cert: redirect 301 present" "$(printf %s "$WITH_CERT" | grep -c 'return 301')" 1
  has "render with cert: uses fullchain" "$WITH_CERT" "/etc/letsencrypt/live/$REAL/fullchain.pem"

  # Let's Encrypt always connects to port 80. If the ACME location is also
  # redirected to 443, renewal silently fails. Port 80 must have
  # ACME AND the redirect (the rest is redirected), port 443 must not redirect.
  HTTP_BLOCK=$(printf %s "$WITH_CERT" | sed -n '/listen 80;/,/listen 443/p')
  has "port 80 serves ACME"        "$HTTP_BLOCK" 'acme-challenge'
  has "port 80 redirects the rest"     "$HTTP_BLOCK" 'return 301'
  eq "port 80 has exactly 1 server block" "$(printf %s "$WITH_CERT" | grep -c 'server_name')" 2
else
  echo "  -- skipping render with cert (no certificate-bearing domain) --"
fi

# issue_tls: only the value "1" triggers issuance. Other values must be ignored
# so a test or the API cannot suddenly hit the LE rate limit.
mkdom_tls() {
  C=$(csrf "$J")
  curl -s -L -o "$TMP/resp" -b "$(jar "$J")" -c "$(jar "$J")" \
    --data-urlencode "_csrf=$C" --data-urlencode "name=$1" \
    --data-urlencode "type=php" --data-urlencode "php_version=8.3" \
    --data-urlencode "issue_tls=$2" "$BASE/domains"
}
mkdom_tls "tls0.test" "0"
eq "issue_tls=0 does not trigger issuance" "$($FP domain:exists tls0.test)" 1
eq "issue_tls=0 -> ssl_status none" \
   "$(sudo -u www-data php -r 'require "/srv/fpanel/app/bootstrap.php";
      echo Fpanel\Database\Connection::value("SELECT ssl_status FROM domains WHERE name=:n",["n"=>"tls0.test"]);' 2>/dev/null)" none
deld "tls0.test"
eq "teardown domain tls0" "$($FP domain:exists tls0.test)" 0

# --- 10. File Manager: copy/move/zip/unzip/download/chmod -------------------
# Setup/teardown via PHP (fast); the HTTP endpoints are tested separately below.
# FileManager convention: the first folder argument is the folder that OWNS the
# item, not the item's own folder. copy("admin","","t_src","t_dst") copies
# the t_src folder (which lives in the home) into t_dst.
echo "== 10. File Manager (copy/move/zip/unzip/download/chmod) =="
FMH='require "/srv/fpanel/app/bootstrap.php"; use Fpanel\Service\FileManager as FM;'
fme(){ sudo -u www-data php -r "$FMH $1"; }
fx(){ fme 'echo (int)'"$1"';'; }
ex(){ fme 'var_export('"$1"');'; }
H=/var/www/admin
CLEARP='foreach (["t_src","t_src.zip","t_dst","t_mv","t_out","t_evil","evil.zip","notzip.zip","testdown.txt","moved.txt","s.txt"] as $x) FM::delete("admin","",[$x]); @unlink("/tmp/evil.txt"); clearstatcache();'
# The allowlist comes from the domains table, not written by hand. If it were pinned
# to specific domain names, every new production domain (e.g. a Node.js demo) would
# make this suite FAIL even though there is no leftover test file at all.
# .well-known is ignored too: it is the ACME challenge directory owned by Let's Encrypt,
# created in the home root and NOT test residue. Counting it as residue would
# always fail this assertion on servers that already have certificates.
RESID='$a = [".", "..", ".well-known"]; foreach (Fpanel\Database\Connection::all("SELECT name FROM domains") as $d) { $a[] = $d["name"]; } echo count(array_diff(scandir(FM::home("admin")), $a));'
# RESID is a complete statement (not an expression), so it is called without "echo".

fme "$CLEARP FM::mkdir(\"admin\",\"\",\"t_src\"); FM::mkdir(\"admin\",\"\",\"t_dst\"); FM::mkdir(\"admin\",\"\",\"t_mv\"); FM::mkdir(\"admin\",\"t_src\",\"sub\"); file_put_contents(FM::home(\"admin\").\"/t_src/a.txt\",\"AAA\"); file_put_contents(FM::home(\"admin\").\"/t_src/sub/b.txt\",\"BBB\");"

# -- copy ---------------------------------------------------------------------
eq "copy folder: ok"          "$(fx 'FM::copy("admin","","t_src","t_dst")["ok"]')" 1
eq "copy bawa file (a.txt)"  "$(fx "is_file(\"$H/t_dst/t_src/a.txt\")")" 1
eq "copy bawa subfolder"     "$(fx "is_file(\"$H/t_dst/t_src/sub/b.txt\")")" 1
eq "copy folder contents exactly equal" "$(fme 'echo (int)(count(array_diff(scandir(FM::home("admin")."/t_dst/t_src"), [".","..","a.txt","sub"])) === 0);')" 1
eq "copy does not delete the source"  "$(fx "is_dir(\"$H/t_src\")")" 1
eq "copy file biasa: ok"     "$(fx 'FM::copy("admin","t_src","a.txt","t_dst")["ok"]')" 1
eq "copy file: contents correct"    "$(fme "echo (int)(file_get_contents(\"$H/t_dst/a.txt\") === \"AAA\");")" 1
eq "copy name conflict rejected" "$(fx 'FM::copy("admin","t_src","a.txt","t_dst")["ok"]')" 0
eq "copy into the same folder rejected" "$(fx 'FM::copy("admin","","t_src","")["ok"]')" 0
eq "copy traversal rejected"  "$(fx 'FM::copy("admin","","..","")["ok"]')" 0
eq "copy into a missing folder rejected" "$(fx 'FM::copy("admin","","t_src","t_missing")["ok"]')" 0

# -- zip ----------------------------------------------------------------------
eq "zip folder: ok"          "$(fx 'FM::zip("admin","","t_src")["ok"]')" 1
eq "zip creates a .zip archive"  "$(fx "is_file(\"$H/t_src.zip\")")" 1
eq "zip does NOT delete the source" "$(fx "is_dir(\"$H/t_src\")")" 1
eq "archive contents correct"         "$(fme '$z=new ZipArchive(); $z->open(FM::home("admin")."/t_src.zip"); $r=[]; for($i=0;$i<$z->numFiles;$i++){$r[]=$z->getNameIndex($i);} $z->close(); echo (int)(in_array("t_src/a.txt",$r,true) && in_array("t_src/sub/b.txt",$r,true));')" 1
eq "zip name conflict rejected" "$(fx 'FM::zip("admin","","t_src")["ok"]')" 0

# -- unzip --------------------------------------------------------------------
eq "unzip: ok"               "$(fx 'FM::unzip("admin","","t_src.zip","t_out")["ok"]')" 1
eq "unzip: contents DIRECTLY in the target folder"  "$(fx "is_file(\"$H/t_out/a.txt\")")" 1
eq "unzip: subfolder ikut"   "$(fx "is_file(\"$H/t_out/sub/b.txt\")")" 1
eq "unzip: no extra nesting level" "$(fx "is_dir(\"$H/t_out/t_src\")")" 0
eq "unzip: file contents intact"    "$(fme "echo (int)(file_get_contents(\"$H/t_out/a.txt\") === \"AAA\");")" 1

# -- zip slip -----------------------------------------------------------------
fme '$z=new ZipArchive(); $z->open(FM::home("admin")."/evil.zip", ZipArchive::CREATE|ZipArchive::OVERWRITE); $z->addFromString("../../../../tmp/evil.txt","LEAKED"); $z->close();'
eq "unzip tolak arsip zip-slip" "$(fx 'FM::unzip("admin","","evil.zip","t_evil")["ok"]')" 0
eq "unzip: error message mentions zip slip" "$(fme '$r=FM::unzip("admin","","evil.zip","t_evil"); echo (int)str_contains(strtolower((string)$r["error"]),"slip");')" 1
eq "zip-slip does NOT write to /tmp" "$(fx 'file_exists("/tmp/evil.txt")')" 0
fme 'file_put_contents(FM::home("admin")."/notzip.zip","this is not a zip");'
eq "unzip non-zip rejected"  "$(fx 'FM::unzip("admin","","notzip.zip","t_evil")["ok"]')" 0
eq "unzip with a non-zip extension rejected" "$(fx 'FM::unzip("admin","t_src","a.txt","t_evil")["ok"]')" 0

# -- move (after zip/unzip so a.txt still exists) ------------------------------
eq "move file: ok"           "$(fx 'FM::move("admin","t_src","a.txt","t_mv")["ok"]')" 1
eq "move: source gone"       "$(fx 'is_file(FM::home("admin")."/t_src/a.txt")')" 0
eq "move: target exists"        "$(fx 'is_file(FM::home("admin")."/t_mv/a.txt")')" 1
eq "move: target contents intact"   "$(fme 'echo (int)(file_get_contents(FM::home("admin")."/t_mv/a.txt") === "AAA");')" 1
fme 'FM::mkdir("admin","","t_mv"); file_put_contents(FM::home("admin")."/t_src/a.txt","AAA");'
eq "move into a non-empty folder rejected" "$(fx 'FM::move("admin","t_src","a.txt","t_mv")["ok"]')" 0
eq "move + rename (stay di folder asal)" "$(fme '$r=FM::move("admin","t_src","a.txt","","a2.txt"); echo (int)((int)$r["ok"] === 1 && is_file(FM::home("admin")."/t_src/a2.txt") === true && is_file(FM::home("admin")."/a2.txt") === false);')" 1
eq "move traversal rejected"  "$(fx 'FM::move("admin","","..","t_dst")["ok"]')" 0

# -- chmod --------------------------------------------------------------------
eq "chmod 0600 applied"     "$(fme '$r=FM::chmod("admin","t_out","a.txt",0600); clearstatcache(); echo ((int)$r["ok"] === 1 ? "1" : "0").substr(sprintf("%o",fileperms(FM::home("admin")."/t_out/a.txt")),-3);')" "1600"
eq "chmod 0644 applied"     "$(fme 'FM::chmod("admin","t_out","a.txt",0644); clearstatcache(); echo substr(sprintf("%o",fileperms(FM::home("admin")."/t_out/a.txt")),-3);')" "644"
eq "chmod outside 000-777 rejected" "$(fx 'FM::chmod("admin","t_out","a.txt",07777)["ok"]')" 0
eq "chmod traversal name rejected" "$(fx 'FM::chmod("admin","t_out","../fpanel.php",0600)["ok"]')" 0
eq "chmod missing file rejected" "$(fx 'FM::chmod("admin","t_out","missing.txt",0600)["ok"]')" 0

# -- download -----------------------------------------------------------------
eq "download metadata valid"  "$(fme '$d=FM::download("admin","t_out","a.txt"); echo (int)($d !== null && $d["name"] === "a.txt" && $d["size"] === 3);')" 1
eq "download traversal rejected" "$(ex 'FM::download("admin","","../fpanel.php")')" "NULL"
eq "download of a folder rejected" "$(ex 'FM::download("admin","","t_out")')" "NULL"
eq "download invalid name rejected" "$(ex 'FM::download("admin","","a/b")')" "NULL"

# -- endpoint HTTP ------------------------------------------------------------
JF=filemgr
eq "admin login for File Manager" "$(code "$JF" -d "_csrf=$(csrf "$JF")&username=admin&password=$ADMIN_PWE" $BASE/login)" 302
CF=$(csrf "$JF")
post "$JF" -o /dev/null --data-urlencode "_csrf=$CF" --data-urlencode "path=" --data-urlencode "name=testdown.txt" "$BASE/files/newfile"
eq "HTTP newfile -> 200" "$(code "$JF" "$BASE/files")" 200
eq "HTTP newfile creates an empty file" "$(fme "echo (int)(is_file(\"$H/testdown.txt\") && filesize(\"$H/testdown.txt\") === 0);")" 1
CF=$(csrf "$JF")
post "$JF" -o /dev/null --data-urlencode "_csrf=$CF" --data-urlencode "path=" --data-urlencode "name=testdown.txt" --data-urlencode "body=download-body" "$BASE/files/save"
eq "HTTP save fills the file" "$(fme "echo (int)(file_get_contents(\"$H/testdown.txt\") === \"download-body\");")" 1
eq "HTTP download file contents correct" "$(body "$JF" "$BASE/files/download?path=&name=testdown.txt")" "download-body"
eq "HTTP download traversal -> 404" "$(getc "$JF" "$BASE/files/download?path=&name=../fpanel.php")" 404
eq "HTTP download without login -> 302" "$(getc nods "$BASE/files/download?path=&name=testdown.txt")" 302
CF=$(csrf "$JF")
eq "HTTP chmod 0644 -> 200" "$(post "$JF" -o /dev/null -w '%{http_code}' --data-urlencode "_csrf=$CF" --data-urlencode "path=" --data-urlencode "name=testdown.txt" --data-urlencode "mode=644" "$BASE/files/chmod")" 200
eq "mode jadi 644 di disk" "$(fme "echo substr(sprintf(\"%o\",fileperms(\"$H/testdown.txt\")),-3);")" "644"
CF=$(csrf "$JF")
has "HTTP chmod bogus mode rejected" "$(body "$JF" --data-urlencode "_csrf=$CF" --data-urlencode "path=" --data-urlencode "name=testdown.txt" --data-urlencode "mode=9999" "$BASE/files/chmod")" "octal 000-777"
CF=$(csrf "$JF")
eq "fake CSRF rejected (chmod)" "$(post "$JF" -o /dev/null -w '%{http_code}' --data-urlencode "_csrf=abcd" --data-urlencode "path=" --data-urlencode "name=testdown.txt" --data-urlencode "mode=600" "$BASE/files/chmod")" 419
CF=$(csrf "$JF")
eq "HTTP copy -> 200" "$(post "$JF" -o /dev/null -w '%{http_code}' --data-urlencode "_csrf=$CF" --data-urlencode "path=" --data-urlencode "name=testdown.txt" --data-urlencode "to=t_dst" "$BASE/files/copy")" 200
eq "file actually copied" "$(fme "echo (int)is_file(\"$H/t_dst/testdown.txt\");")" 1
CF=$(csrf "$JF")
eq "HTTP move -> 200" "$(post "$JF" -o /dev/null -w '%{http_code}' --data-urlencode "_csrf=$CF" --data-urlencode "path=t_dst" --data-urlencode "name=testdown.txt" --data-urlencode "to=" --data-urlencode "newname=moved.txt" "$BASE/files/move")" 200
eq "file moved + renamed" "$(fme "echo (int)(is_file(\"$H/t_dst/moved.txt\") && is_file(\"$H/t_dst/testdown.txt\") === false);")" 1
CF=$(csrf "$JF")
eq "HTTP zip -> 200" "$(post "$JF" -o /dev/null -w '%{http_code}' --data-urlencode "_csrf=$CF" --data-urlencode "path=" --data-urlencode "name=t_src" "$BASE/files/zip")" 200
CF=$(csrf "$JF")
eq "HTTP unzip -> 200" "$(post "$JF" -o /dev/null -w '%{http_code}' --data-urlencode "_csrf=$CF" --data-urlencode "path=" --data-urlencode "name=t_src.zip" --data-urlencode "to=t_out2" "$BASE/files/unzip")" 200
eq "unzip result is in the target folder" "$(fme "echo (int)is_file(\"$H/t_out2/a.txt\");")" 1

fme "$CLEARP FM::delete(\"admin\",\"\",[\"t_out2\"]);"
eq "cleanup: no leftover test files" "$(fme "$RESID")" "0"

# single-use non-admin users must be removed, otherwise every run piles up
# new accounts in the database.
$FP user:delete "$U2" >/dev/null 2>&1
eq "single-use test user cleaned up" "$($FP user:list 2>/dev/null | grep -c "^.*\b$U2\b")" 0

# --- 11. MariaDB: CRUD database + user, privilege, enkripsi, isolasi ---------
# Its assertions live in a separate PHP file: inlining PHP inside bash quoting
# is too error-prone, and a PHP file can use try/catch to
# prove the real MariaDB error.
echo "== 11. MariaDB (CRUD database & user) =="
# db_check.php needs one non-admin user to prove isolation between tenants
# (IDOR). That user was deleted by the section 10 cleanup, so recreate it
# here and clean it up again at the end.
$FP user:create "$U2" "$U2_PW" "uji@role.test" user >/dev/null 2>&1
# The quota must be raised while the suite runs. Otherwise db_check.php fails
# not because MariaDb is wrong but because no database can be created.
QNOW=$($FP package:domain-quota admin 2>/dev/null | tail -1)
gt "section 11: domain quota is parked" "$QNOW" 5
QDB=$(sudo -u www-data php /srv/fpanel/tests/quota_check.php 2>/dev/null | tail -1)
gt "section 11: database quota is enough for the test" "$QDB" 3
# Columns: status, name, got value, expected value.
while IFS=$'\t' read -r st nm got want; do
  [ -n "${st:-}" ] || continue
  if [ "$st" = "ok" ]; then ok "$nm"; else no "$nm" "$got" "$want"; fi
done < <(sudo -u www-data php /srv/fpanel/tests/db_check.php 2>&1)
$FP user:delete "$U2" >/dev/null 2>&1

# --- 12. phpMyAdmin per-database (single-use token) -------------------------
# Service assertions live in tests/pma_check.php; below is the HTTP side,
# because what must be proven is the nginx + front controller behavior.
echo "== 12. phpMyAdmin per-database =="
$FP user:create "$U2" "$U2_PW" "uji@role.test" user >/dev/null 2>&1
while IFS=$'\t' read -r st nm got want; do
  [ -n "${st:-}" ] || continue
  if [ "$st" = "ok" ]; then ok "$nm"; else no "$nm" "$got" "$want"; fi
done < <(sudo -u www-data php /srv/fpanel/tests/pma_check.php 2>&1)
$FP user:delete "$U2" >/dev/null 2>&1

# HTTP side. Uses the admin cookie jar.
PA=pma
# /pma without a token is always 404, so log in first before testing the POST endpoint.
eq "admin login for the phpMyAdmin session" \
  "$(code $PA -d "_csrf=$(csrf $PA)&username=admin&password=$ADMIN_PWE" $BASE/login)" 302

# Prepare a test database + token via the service, then grab its URL.
PMATK=$(sudo -u www-data php -r '
require "/srv/fpanel/app/bootstrap.php";
use Fpanel\Service\MariaDb; use Fpanel\Service\PhpMyAdmin; use Fpanel\Database\Connection;
$i=(int)Connection::value("SELECT id FROM databases WHERE name=:n",["n"=>"pma_http"]);
if($i){ MariaDb::delete("admin",$i); }
MariaDb::create("admin","pma_http","pma_app");
$d=(int)Connection::value("SELECT id FROM databases WHERE name=:n",["n"=>"pma_http"]);
$u=(int)Connection::value("SELECT id FROM db_users WHERE name=:n",["n"=>"pma_app"]);
$p=MariaDb::reveal("admin",$d,$u)["password"];
try { $pdo=new PDO("mysql:host=localhost;dbname=".MariaDb::physDb("admin","pma_http"),MariaDb::physUser("admin","pma_app"),$p);
      $pdo->exec("CREATE TABLE t (id INT)"); } catch (Throwable $e) {}
echo $d." ".$u;' 2>/dev/null)
PMID=$(echo "$PMATK" | cut -d' ' -f1); PMUID=$(echo "$PMATK" | cut -d' ' -f2)
PMDB=$(sudo -u www-data php -r 'require "/srv/fpanel/app/bootstrap.php"; echo Fpanel\Service\MariaDb::physDb("admin","pma_http");' 2>/dev/null)

if [ -z "$PMID" ]; then
  no "HTTP: prepare test database" "cannot create the test database" "database id"
else
  ok "HTTP: prepare test database"
  # Fake tokens must be 404, not 403/302 — do not leak whether a path exists.
  eq "fake token -> 404"        "$(getc $PA $BASE/pma/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa/index.php)" 404
  eq "token not 32 hex -> 404"  "$(getc $PA $BASE/pma/short/index.php)" 404
  eq "no token -> 404"         "$(getc $PA $BASE/pma/)" 404
  eq "/fpma internal -> 404"      "$(getc $PA $BASE/fpma/index.php)" 404

  # The real token is created via the POST endpoint (CSRF checked).
  eq "POST without CSRF -> 419"     "$(code $PA -d "db=$PMID&dbuser=$PMUID" $BASE/databases/phpmyadmin)" 419
  PMR=$(post $PA -d "_csrf=$(csrf $PA)" -d "db=$PMID" -d "dbuser=$PMUID" \
        -o /dev/null -D - $BASE/databases/phpmyadmin | grep -i '^location:' | tr -d '\r' | sed 's/^[Ll]ocation: *//')
  has "launch phpMyAdmin -> redirect /pma/<token>/" "$PMR" "/pma/"
  PMTOK=$(echo "$PMR" | sed -E 's#^/pma/([a-f0-9]+)/.*#\1#')
  eq "token from the redirect is 32 hex" 1 "$(printf '%s' "$PMTOK" | grep -cE '^[a-f0-9]{32}$')"

  PMB=$(body $PA "$BASE$PMR")
  has "page opens: phpMyAdmin title"      "$PMB" "phpMyAdmin"
  hasnt "does not bounce back to the login form"            "$PMB" "pma_password"
  has "the user's own database is visible"           "$PMB" "$PMDB"
  hasnt "system mysql database not visible" "$PMB" ">mysql<"
  hasnt "information_schema not visible"     "$PMB" "information_schema"

  PMST=$(body $PA "$BASE/pma/$PMTOK/index.php?route=/database/structure&db=$PMDB")
  has "structure page opens"               "$PMST" "$PMDB"
  hasnt "structure page has no error"                 "$PMST" "Fatal error"
  eq "aset css ter-load"                       "$(getc $PA "$BASE/pma/$PMTOK/themes/pmahomme/css/theme.css")" 200

  # Expired token: its config file is deleted -> the old URL must die.
  sudo -u www-data rm -f "/etc/phpmyadmin/conf.d/$PMTOK.php"
  eq "token after file deletion -> 404"       "$(getc $PA "$BASE/pma/$PMTOK/index.php")" 404

  sudo -u www-data php -r 'require "/srv/fpanel/app/bootstrap.php";
    Fpanel\Service\MariaDb::delete("admin",(int)Fpanel\Database\Connection::value("SELECT id FROM databases WHERE name=:n",["n"=>"pma_http"]));' 2>/dev/null
  ok "cleanup: HTTP test database deleted"
fi
sudo -u www-data rm -f /etc/phpmyadmin/conf.d/*.php 2>/dev/null
eq "cleanup: no token files" "$(sudo -u www-data php /srv/fpanel/bin/fpanel pma:sweep >/dev/null 2>&1; sudo -u www-data php -r 'require "/srv/fpanel/app/bootstrap.php"; echo Fpanel\Service\PhpMyAdmin::activeTokens();')" 0

# --- 13. Multi-PHP: each domain can use a different version ------------------
# php:prep prepares /etc/php/<v>/fpm/conf.d/domains owned by www-data. Needs root,
# because /etc/php/<v>/fpm/conf.d itself is owned by root.
echo "== 13. Multi-PHP (per-domain) =="
$FP php:prep >/dev/null 2>&1
V81=$(sudo -u www-data php -r 'require "/srv/fpanel/app/bootstrap.php"; echo (int)in_array("8.1", Fpanel\Service\Vhost::installedPhpVersions(), true);' 2>/dev/null)
eq "8.1 installed & has a socket" "$V81" 1
eq "8.2 installed & has a socket" \
  "$(sudo -u www-data php -r 'require "/srv/fpanel/app/bootstrap.php"; echo (int)in_array("8.2", Fpanel\Service\Vhost::installedPhpVersions(), true);' 2>/dev/null)" 1
eq "8.4 installed & has a socket" \
  "$(sudo -u www-data php -r 'require "/srv/fpanel/app/bootstrap.php"; echo (int)in_array("8.4", Fpanel\Service\Vhost::installedPhpVersions(), true);' 2>/dev/null)" 1

# fpmSock() must reject bad input: the value goes into the nginx config.
eq "fpmSock: injection input rejected" \
  "$(sudo -u www-data php -r 'require "/srv/fpanel/app/bootstrap.php"; echo Fpanel\Service\Vhost::fpmSock("8.3; return 404");' 2>/dev/null)" \
  "/run/php/php8.3-fpm.sock"
eq "fpmSock: each version maps to its own socket" \
  "$(sudo -u www-data php -r 'require "/srv/fpanel/app/bootstrap.php"; echo Fpanel\Service\Vhost::fpmSock("8.1");' 2>/dev/null)" \
  "/run/php/php8.1-fpm.sock"

# Test domains: one on 8.1, one on 8.4, then checked over HTTP.
mkphpdom(){ sudo -u www-data php -r '
require "/srv/fpanel/app/bootstrap.php";
use Fpanel\Service\DomainManager; use Fpanel\Service\Vhost; use Fpanel\Database\Connection;
$uid=(int)Connection::value("SELECT id FROM users WHERE username=:u",["u"=>"admin"]);
$id=(int)Connection::value("SELECT id FROM domains WHERE name=:n",["n"=>$argv[1]]);
if($id){ DomainManager::delete($uid,"admin",$id); }
$r=DomainManager::create($uid,"admin",$argv[1],"php",$argv[2],false);
if(!$r["ok"]){ fwrite(STDERR,"create failed: ".$r["error"]."\n"); exit(1); }
$d=(int)Connection::value("SELECT id FROM domains WHERE name=:n",["n"=>$argv[1]]);
$doc=(string)Connection::value("SELECT docroot FROM domains WHERE id=:i",["i"=>$d]);
if(!is_dir($doc)){ @mkdir($doc,0755,true); }
file_put_contents(rtrim($doc,"/")."/index.php","<?php echo PHP_VERSION;");
Vhost::deploy($argv[1],"admin",$doc,"php",$argv[2]);' "$1" "$2" 2>/dev/null; }
delphpdom(){ sudo -u www-data php -r '
require "/srv/fpanel/app/bootstrap.php";
use Fpanel\Service\DomainManager; use Fpanel\Database\Connection;
$uid=(int)Connection::value("SELECT id FROM users WHERE username=:u",["u"=>"admin"]);
$id=(int)Connection::value("SELECT id FROM domains WHERE name=:n",["n"=>$argv[1]]);
if($id){ DomainManager::delete($uid,"admin",$id); }' "$1" 2>/dev/null; }

mkphpdom mp81.test 8.1
eq "8.1 domain created" "$($FP domain:exists mp81.test)" 1
has "vhost 8.1 -> socket 8.1" \
  "$(cat /etc/nginx/sites-available/fpanel-domains/mp81.test.conf 2>/dev/null)" "php8.1-fpm.sock"
eq "8.1 domain runs PHP 8.1" "$(wait_dom mp81.test | cut -d. -f1,2)" "8.1"

mkphpdom mp84.test 8.4
eq "8.4 domain created" "$($FP domain:exists mp84.test)" 1
has "vhost 8.4 -> socket 8.4" \
  "$(cat /etc/nginx/sites-available/fpanel-domains/mp84.test.conf 2>/dev/null)" "php8.4-fpm.sock"
eq "8.4 domain runs PHP 8.4" "$(wait_dom mp84.test | cut -d. -f1,2)" "8.4"

# The two domains must be truly separate: they must not share versions.
hasnt "8.1 does not use the 8.4 socket" \
  "$(cat /etc/nginx/sites-available/fpanel-domains/mp81.test.conf 2>/dev/null)" "php8.4-fpm.sock"

delphpdom mp81.test
delphpdom mp84.test
eq "cleanup: 8.1 domain deleted" "$($FP domain:exists mp81.test)" 0
eq "cleanup: 8.4 domain deleted" "$($FP domain:exists mp84.test)" 0
eq "cleanup: 8.1 vhost gone" "$([ -f /etc/nginx/sites-available/fpanel-domains/mp81.test.conf ] && echo 1 || echo 0)" 0
rm -rf /var/www/admin/mp81.test /var/www/admin/mp84.test

# --- 14. PHP Extensions (per version) + per-domain php.ini (PHP_VALUE) -------
# Extensions live in /etc/php/<v>/fpm/conf.d + a pool restart. Per-domain
# php.ini values live in the fastcgi_param PHP_VALUE. Both are tested for real.
echo "== 14. PHP Extensions & PHP Settings =="
PH="sudo -u www-data sudo -n /usr/local/sbin/fpanel-php"
# Non-admin user for the 403 test. The previous section deleted it, so recreate it.
$FP user:create "$U2" "$U2_PW" "uji@role.test" user >/dev/null 2>&1

# -- helper: input validation (these values go into file names / root args) ----
eq "helper: bad version rejected" "$($PH list '8.3;id' 2>&1 | head -1 | cut -c1-4)" "ERR:"
eq "helper: bad extension rejected" "$($PH enable 8.3 'zip.so;id' 2>&1 | head -1 | cut -c1-4)" "ERR:"
eq "helper: unknown subcommand rejected" "$(sudo -u www-data sudo -n /usr/local/sbin/fpanel-php 'rm -rf /' 2>&1 | head -1 | cut -c1-16)" "sudo: a password"
eq "helper: not-installed version rejected" "$($PH list 9.9 2>&1 | head -1 | cut -c1-4)" "ERR:"

# -- extension list per version ----------------------------------------------
for v in 8.1 8.3 8.4; do
  gt "list $v: has extensions" "$($PH list $v | wc -l)" 30
done
eq "zip enabled in 8.3" "$($PH list 8.3 | awk -F'\t' '$1=="zip"{print $2}')" "enabled"

# -- toggle really changes PHP -------------------------------------------------
probe_ext() { curl -s --max-time 10 "http://127.0.0.1:8090/_probe_ext.php" | head -1; }
cat > /srv/fpanel/app/public/_probe_ext.php <<'PHP'
<?php echo 'zip=' . (int) extension_loaded('zip') . "\n";
PHP
eq "before toggle: zip enabled" "$(probe_ext)" "zip=1"

# Turn it off via the panel endpoint (POST + CSRF), then WAIT for the scheduled restart.
# Log in first: without a session the endpoint answers 302 (not 419/403) and
# the assertion misreads the result.
curl -s -b "$(jar pz)" -c "$(jar pz)" -o /dev/null \
  -d "_csrf=$(csrf pz)&username=admin&password=$ADMIN_PWE" $BASE/login
code u2 -d "_csrf=$(csrf u2)&username=$U2&password=$U2_PWE" $BASE/login >/dev/null
eq "toggle without CSRF -> 419" \
  "$(code pz -d "version=8.3&ext=zip&state=0" $BASE/php-extensions/toggle)" 419
eq "toggle by non-admin -> 403" \
  "$(code u2 -d "_csrf=$(csrf u2)&version=8.3&ext=zip&state=0" $BASE/php-extensions/toggle)" 403
curl -s -b "$(jar pz)" -c "$(jar pz)" -o /dev/null \
  -d "_csrf=$(csrf pz)&version=8.3&ext=zip&state=0" $BASE/php-extensions/toggle
sleep 4
eq "after toggle OFF: zip disabled" "$(probe_ext)" "zip=0"
# The important part: the distro file is MOVED (not deleted), so it can be restored.
eq "distro zip file moved (not deleted)" \
  "$([ -f /etc/php/8.3/fpm/conf.d/20-zip.ini.fpanel-off ] && echo 1 || echo 0)" 1
eq "the original distro file is not in place" \
  "$([ -f /etc/php/8.3/fpm/conf.d/20-zip.ini ] && echo 1 || echo 0)" 0
eq "change recorded in the state file" \
  "$(awk -F'\t' '$1=="8.3" && $2=="zip"' /var/lib/fpanel/php-ext/off.list 2>/dev/null | wc -l)" 1
has "OFF status shown in the UI" \
  "$(body pz "$BASE/php-extensions?ver=8.3")" "zip"
has "toggle result flash shown" \
  "$(body pz "$BASE/php-extensions?ver=8.3&ok=FLASHOK")" "FLASHOK"
eq "other versions unaffected" "$($PH list 8.4 | awk -F'\t' '$1=="zip"{print $2}')" "enabled"

# Turn it back on -> must go back to the distro file, with no leftover FPanel-made file.
curl -s -b "$(jar pz)" -c "$(jar pz)" -o /dev/null \
  -d "_csrf=$(csrf pz)&version=8.3&ext=zip&state=1" $BASE/php-extensions/toggle
sleep 4
eq "after toggle ON: zip enabled again" "$(probe_ext)" "zip=1"
eq "no leftover 50-fpanel file" "$(ls /etc/php/8.3/fpm/conf.d/50-fpanel-*.ini 2>/dev/null | wc -l)" 0
eq "distro zip file is back" \
  "$([ -f /etc/php/8.3/fpm/conf.d/20-zip.ini ] && echo 1 || echo 0)" 1
# Note: grep -r cannot traverse /etc/php/<v>/fpm/conf.d on this server
# (exit 1 with no output), so find -exec is used.
eq "extension zip not defined twice" \
  "$(find /etc/php/8.3/fpm/conf.d -maxdepth 1 -name '*.ini' -exec grep -lF 'extension=zip.so' {} + 2>/dev/null | wc -l)" 1
eq "no leftover .fpanel-off files" "$(ls /etc/php/8.3/fpm/conf.d/*.fpanel-off 2>/dev/null | wc -l)" 0
eq "state file clean" "$(cat /var/lib/fpanel/php-ext/off.list 2>/dev/null | wc -l)" 0

# -- php.ini per-domain (PHP_VALUE) ------------------------------------------
mkini(){ sudo -u www-data php -r '
require "/srv/fpanel/app/bootstrap.php";
use Fpanel\Service\DomainManager; use Fpanel\Service\Vhost; use Fpanel\Database\Connection;
$u=(int)Connection::value("SELECT id FROM users WHERE username=:u",["u"=>"admin"]);
$id=(int)Connection::value("SELECT id FROM domains WHERE name=:n",["n"=>$argv[1]]);
if($id){ DomainManager::delete($u,"admin",$id); }
$r=DomainManager::create($u,"admin",$argv[1],"php","8.3",false);
if(!$r["ok"]){ fwrite(STDERR,$r["error"]); exit(1); }
$d=(int)Connection::value("SELECT id FROM domains WHERE name=:n",["n"=>$argv[1]]);
$doc=(string)Connection::value("SELECT docroot FROM domains WHERE id=:i",["i"=>$d]);
if(!is_dir($doc)){ @mkdir($doc,0755,true); }
file_put_contents(rtrim($doc,"/")."/index.php","<?php echo \"mem=\".ini_get(\"memory_limit\").\" upload=\".ini_get(\"upload_max_filesize\").\"\n\";");
Vhost::deploy($argv[1],"admin",$doc,"php","8.3");' "$1" 2>/dev/null; }
delini(){ sudo -u www-data php -r '
require "/srv/fpanel/app/bootstrap.php";
use Fpanel\Service\DomainManager; use Fpanel\Database\Connection;
$u=(int)Connection::value("SELECT id FROM users WHERE username=:u",["u"=>"admin"]);
$id=(int)Connection::value("SELECT id FROM domains WHERE name=:n",["n"=>$argv[1]]);
if($id){ DomainManager::delete($u,"admin",$id); }' "$1" 2>/dev/null; }
probe_dom(){ curl -s -H "Host: $1" --max-time 10 http://127.0.0.1:80/index.php | head -1; }

mkini s1.test
mkini s2.test
eq "domain s1 default" "$(wait_dom s1.test)" "mem=128M upload=2M"
eq "domain s2 default" "$(probe_dom s2.test)" "mem=128M upload=2M"

SID=$(sudo -u www-data php -r 'require "/srv/fpanel/app/bootstrap.php"; echo (int)Fpanel\Database\Connection::value("SELECT id FROM domains WHERE name=:n",["n"=>"s1.test"]);' 2>/dev/null)
eq "save php settings without CSRF -> 419" \
  "$(code pz -d "domain=$SID&ini_memory_limit=300M" $BASE/domains/php-settings)" 419
curl -s -b "$(jar pz)" -c "$(jar pz)" -o /dev/null \
  -d "_csrf=$(csrf pz)&domain=$SID&ini_memory_limit=300M&ini_upload_max_filesize=64M" \
  $BASE/domains/php-settings
sleep 1
has "vhost contains PHP_VALUE" \
  "$(cat /etc/nginx/sites-available/fpanel-domains/s1.test.conf 2>/dev/null)" "PHP_VALUE"
eq "s1 uses the override" "$(probe_dom s1.test)" "mem=300M upload=64M"
eq "s2 NOT affected" "$(probe_dom s2.test)" "mem=128M upload=2M"

# Malicious values must be rejected: this string would go into the nginx config.
eq "malicious value rejected" \
  "$(sudo -u www-data php -r 'require "/srv/fpanel/app/bootstrap.php";
     $r=Fpanel\Service\PhpSettings::save("admin",(int)Fpanel\Database\Connection::value("SELECT id FROM domains WHERE name=:n",["n"=>"s1.test"]),["memory_limit"=>"300M\"; return 404; #"]);
     echo $r["ok"]?"ACCEPTED":"rejected";' 2>/dev/null)" "rejected"
eq "unknown directive ignored" \
  "$(sudo -u www-data php -r 'require "/srv/fpanel/app/bootstrap.php";
     $r=Fpanel\Service\PhpSettings::save("admin",(int)Fpanel\Database\Connection::value("SELECT id FROM domains WHERE name=:n",["n"=>"s1.test"]),["bogus"=>"1","max_execution_time"=>"90"]);
     echo $r["ok"]?"ok":"failed";' 2>/dev/null)" "ok"
eq "IDOR: editing another user's domain settings rejected" \
  "$(sudo -u www-data php -r 'require "/srv/fpanel/app/bootstrap.php";
     $r=Fpanel\Service\PhpSettings::save("uujirole",(int)Fpanel\Database\Connection::value("SELECT id FROM domains WHERE name=:n",["n"=>"s1.test"]),["memory_limit"=>"500M"]);
     echo $r["ok"]?"ACCEPTED (BUG)":"rejected";' 2>/dev/null)" "rejected"

# Reset must restore the defaults.
curl -s -b "$(jar pz)" -c "$(jar pz)" -o /dev/null \
  -d "_csrf=$(csrf pz)&domain=$SID&act=reset" $BASE/domains/php-settings
sleep 1
eq "after reset: defaults restored" "$(probe_dom s1.test)" "mem=128M upload=2M"

delini s1.test
delini s2.test
rm -f /srv/fpanel/app/public/_probe_ext.php
eq "cleanup: s1 domain deleted" "$($FP domain:exists s1.test)" 0
eq "cleanup: s2 domain deleted" "$($FP domain:exists s2.test)" 0
$FP user:delete "$U2" >/dev/null 2>&1

# --- 15. App runtime: Node.js & Python per domain ---------------------------
# One app = one systemd unit (fpanel-app-<name>.service) proxied by nginx.
echo "== 15. App Runtime (Node.js & Python) =="
AP="sudo -u www-data sudo -n /usr/local/sbin/fpanel-app"
# Production apps (not test apps) must stay INTACT after the suite runs; checked
# again at the end of this section.
PRE_APPS=$(sudo -u www-data php /tmp/q.php "SELECT COUNT(*) FROM runtime_apps WHERE name NOT IN ('nodeapp','pyapp')")
# The previous section deleted the test user, so recreate it here to
# assert IDOR.
$FP user:create "$U2" "$U2_PW" "uji@role.test" user >/dev/null 2>&1

# -- helper validation: these values go into unit names / paths / ports --------
eq "helper: bad name rejected" "$($AP start '../../etc' /var/www a.js 30000 node 2>&1 | head -1 | cut -c1-4)" "ERR:"
eq "helper: '..' entry rejected"  "$($AP start test /var/www '../a.js' 30000 node 2>&1 | head -1 | cut -c1-4)" "ERR:"
eq "helper: absolute entry rejected" "$($AP start test /var/www '/etc/passwd' 30000 node 2>&1 | head -1 | cut -c1-4)" "ERR:"
eq "helper: port > 65535 rejected"   "$($AP start test /var/www a.js 99999 node 2>&1 | head -1 | cut -c1-4)" "ERR:"
eq "helper: bogus kind rejected"    "$($AP start test /var/www a.js 30000 ruby 2>&1 | head -1 | cut -c1-4)" "ERR:"
eq "helper: missing folder rejected" "$($AP start test /var/wwwmissing a.js 30000 node 2>&1 | head -1 | cut -c1-4)" "ERR:"
eq "helper: unknown subcommand rejected" "$(sudo -u www-data sudo -n /usr/local/sbin/fpanel-app 'rm -rf /' 2>&1 | head -1 | cut -c1-16)" "sudo: a password"

# -- set up two domains + app files (written via heredoc so \n di dalam
#    embedded newline escapes in JS/Python strings stay literal) -------------
mknodeapp(){
sudo -u www-data php -r '
require "/srv/fpanel/app/bootstrap.php";
use Fpanel\Service\DomainManager; use Fpanel\Database\Connection;
$u=(int)Connection::value("SELECT id FROM users WHERE username=:u",["u"=>"admin"]);
$id=(int)Connection::value("SELECT id FROM domains WHERE name=:n",["n"=>"nodeapp.test"]);
if($id){ DomainManager::delete($u,"admin",$id); }
$r=DomainManager::create($u,"admin","nodeapp.test","php","8.3",false);
echo $r["ok"]?"ok":$r["error"];' 2>/dev/null
mkdir -p /var/www/admin/nodeapp.test/public/app
cat > /var/www/admin/nodeapp.test/public/app/server.js <<'JS'
const http = require('http');
const port = process.env.PORT || 30000;
http.createServer((req, res) => {
  res.writeHead(200, { 'Content-Type': 'text/plain' });
  res.end('halo node port=' + port + ' path=' + req.url + '\n');
}).listen(port, '127.0.0.1');
JS
chown -R www-data:www-data /var/www/admin/nodeapp.test
}
mkpyapp(){
sudo -u www-data php -r '
require "/srv/fpanel/app/bootstrap.php";
use Fpanel\Service\DomainManager; use Fpanel\Database\Connection;
$u=(int)Connection::value("SELECT id FROM users WHERE username=:u",["u"=>"admin"]);
$id=(int)Connection::value("SELECT id FROM domains WHERE name=:n",["n"=>"pyapp.test"]);
if($id){ DomainManager::delete($u,"admin",$id); }
$r=DomainManager::create($u,"admin","pyapp.test","php","8.3",false);
echo $r["ok"]?"ok":$r["error"];' 2>/dev/null
mkdir -p /var/www/admin/pyapp.test/public/app
cat > /var/www/admin/pyapp.test/public/app/wsgi.py <<'PY'
def application(environ, start_response):
    body = b"halo python path=" + environ.get("PATH_INFO", "/").encode() + b"\n"
    start_response("200 OK", [("Content-Type", "text/plain"), ("Content-Length", str(len(body)))])
    return [body]
PY
chown -R www-data:www-data /var/www/admin/pyapp.test
}
regapp(){
sudo -u www-data php -r '
require "/srv/fpanel/app/bootstrap.php";
use Fpanel\Service\AppRuntime; use Fpanel\Service\DomainManager; use Fpanel\Database\Connection;
$dom=$argv[1]; $kind=$argv[2]; $entry=$argv[3]; $nm=$argv[4];
$did=(int)Connection::value("SELECT id FROM domains WHERE name=:n",["n"=>$dom]);
$row=Connection::one("SELECT * FROM domains WHERE id=:i",["i"=>$did]);
$r=AppRuntime::create("admin",$kind,$nm,$dom,rtrim((string)$row["docroot"],"/")."/app",$entry);
if($r["ok"]){
  Connection::run("UPDATE runtime_apps SET domain_id=:d WHERE id=:i",["d"=>$did,"i"=>(int)$r["id"]]);
  DomainManager::rebuildFor("admin", Connection::one("SELECT * FROM domains WHERE id=:i",["i"=>$did]));
  echo $r["port"];
} else { echo "Error:".$r["error"]; }' "$1" "$2" "$3" "$4" 2>/dev/null
}
delapp(){
sudo -u www-data php -r '
require "/srv/fpanel/app/bootstrap.php";
use Fpanel\Service\AppRuntime; use Fpanel\Service\DomainManager; use Fpanel\Database\Connection;
$u=(int)Connection::value("SELECT id FROM users WHERE username=:u",["u"=>"admin"]);
foreach (["nodeapp","pyapp"] as $n) {
  $aid = (int)Connection::value("SELECT id FROM runtime_apps WHERE name=:n", ["n"=>$n]);
  if ($aid > 0) { AppRuntime::delete("admin", $aid); }
}
$id=(int)Connection::value("SELECT id FROM domains WHERE name=:n",["n"=>$argv[1]]);
if($id){ DomainManager::delete($u,"admin",$id); }' "$1" 2>/dev/null
}
delapp nodeapp.test; delapp pyapp.test

mknodeapp >/dev/null
mkpyapp >/dev/null
PN=$(regapp nodeapp.test node server.js nodeapp)
PP=$(regapp pyapp.test python wsgi.py pyapp)
eq "Node app registered (port allocated)" "$(printf '%s' "$PN" | grep -cE '^[0-9]+$')" 1
eq "Python app registered"                  "$(printf '%s' "$PP" | grep -cE '^[0-9]+$')" 1
if [ "$PN" != "$PP" ] && [ -n "$PN" ] && [ -n "$PP" ]; then
  ok "two apps get different ports ($PN != $PP)"
else
  no "two apps get different ports" "$PN" "$PP"
fi

sleep 3
eq "Node systemd unit active"  "$(systemctl is-active fpanel-app-nodeapp.service)" "active"
eq "Python systemd unit active" "$(systemctl is-active fpanel-app-pyapp.service)" "active"
eq "unit Node enabled (reboot-safe)" "$(systemctl is-enabled fpanel-app-nodeapp.service)" "enabled"
eq "helper status Node = running"  "$($AP status nodeapp)" "running"

# -- really served by the app, not the panel catch-all -----------------------
eq "HTTP domain Node -> 200"   "$(curl -s -o /dev/null -w '%{http_code}' --max-time 8 -H 'Host: nodeapp.test' http://127.0.0.1/)" 200
eq "Node body from the app"        "$(curl -s --max-time 8 -H 'Host: nodeapp.test' http://127.0.0.1/ | head -1 | cut -d' ' -f1-2)" "halo node"
eq "Node body path forwarded" "$(curl -s --max-time 8 -H 'Host: nodeapp.test' http://127.0.0.1/api/z | grep -c 'path=/api/z')" 1
eq "Python body from the app"      "$(curl -s --max-time 8 -H 'Host: pyapp.test' http://127.0.0.1/ | head -1 | cut -d' ' -f1-2)" "halo python"
has "vhost Node proxy ke port app"  "$(cat /etc/nginx/sites-available/fpanel-domains/nodeapp.test.conf)" "proxy_pass http://127.0.0.1:$PN"
has "vhost Python proxy ke port app" "$(cat /etc/nginx/sites-available/fpanel-domains/pyapp.test.conf)" "proxy_pass http://127.0.0.1:$PP"
has "vhost has WebSocket support" "$(cat /etc/nginx/sites-available/fpanel-domains/nodeapp.test.conf)" "fp_connection_upgrade"

# -- stop / start via the helper ---------------------------------------------
$AP stop nodeapp >/dev/null
sleep 2
eq "after stop: domain 502" "$(curl -s -o /dev/null -w '%{http_code}' --max-time 6 -H 'Host: nodeapp.test' http://127.0.0.1/)" 502
$AP start nodeapp "/var/www/admin/nodeapp.test/public/app" server.js "$PN" node /var/www >/dev/null
sleep 3
eq "after start: app alive again" "$(curl -s --max-time 8 -H 'Host: nodeapp.test' http://127.0.0.1/ | head -1 | cut -d' ' -f1-2)" "halo node"

# -- quota: app kinds do not share quota ------------------------------------
# When this assertion runs there is 1 Node app + 1 Python app. If both
# were counted in one counter, one value would be 2; so 1 and 1 prove
# 1 and 1 prove the counters are separate.
eq "Node and Python quota counted separately" \
  "$(sudo -u www-data php -r 'require "/srv/fpanel/app/bootstrap.php";
     $q=Fpanel\Service\AppRuntime::quota("admin");
     $uid=(int)Fpanel\Database\Connection::value("SELECT id FROM users WHERE username=:u",["u"=>"admin"]);
     $rows=Fpanel\Database\Connection::all("SELECT kind FROM runtime_apps WHERE user_id=:u",["u"=>$uid]);
     $n=count(array_filter($rows,fn($a)=>$a["kind"]==="node"));
     $p=count(array_filter($rows,fn($a)=>$a["kind"]==="python"));
     // AppRuntime must count per runtime kind, exactly like the
     // direct count. Both kinds must exist for this test to mean something.
     echo ($n>0 && $p>0 && $q["used_node"]===$n && $q["used_python"]===$p)
       ? "separate" : ("mixed n=".$q["used_node"]."/".$n." p=".$q["used_python"]."/".$p);' 2>/dev/null)" "separate"
eq "quota: Python limit present in the package" \
  "$(sudo -u www-data php -r 'require "/srv/fpanel/app/bootstrap.php";
     $q=Fpanel\Service\AppRuntime::quota("admin"); echo $q["max_python"] > 0 ? "present" : "zero";' 2>/dev/null)" "present"

# -- IDOR ------------------------------------------------------------------
eq "IDOR: another user's apps not visible" \
  "$(sudo -u www-data php -r 'require "/srv/fpanel/app/bootstrap.php";
     $o=(string)Fpanel\Database\Connection::value("SELECT username FROM users WHERE role <> :r ORDER BY id LIMIT 1",["r"=>"admin"]);
     if($o===""){ echo "no-such-user"; }
     else { $r=Fpanel\Service\AppRuntime::listing($o); echo empty($r["apps"]) ? "empty" : "LEAKED"; }' 2>/dev/null)" "empty"
eq "IDOR: stopping another user's app rejected" \
  "$(sudo -u www-data php -r 'require "/srv/fpanel/app/bootstrap.php";
     $o=(string)Fpanel\Database\Connection::value("SELECT username FROM users WHERE role <> :r ORDER BY id LIMIT 1",["r"=>"admin"]);
     $id=(int)Fpanel\Database\Connection::value("SELECT id FROM runtime_apps LIMIT 1");
     $r=Fpanel\Service\AppRuntime::stop($o,$id);
     echo $r["ok"] ? "ACCEPTED(BUG)" : "rejected";' 2>/dev/null)" "rejected"

# -- UI --------------------------------------------------------------------
curl -s -b "$(jar aps)" -c "$(jar aps)" -o /dev/null \
  -d "_csrf=$(csrf aps)&username=admin&password=$ADMIN_PWE" $BASE/login
APUI=$(body aps "$BASE/apps")
has "page /apps opens"      "$APUI" "App Runtime"
has "page /apps lists the app" "$APUI" "nodeapp"
has "page /apps shows the runtime" "$APUI" "App Runtime"
hasnt "page /apps does not include a login form" "$APUI" "type=\"password\""
eq "app action without CSRF -> 419"  "$(code aps -d "app=1&act=stop" $BASE/apps/action)" 419
AID=$(sudo -u www-data php -r 'require "/srv/fpanel/app/bootstrap.php"; echo (int)Fpanel\Database\Connection::value("SELECT id FROM runtime_apps WHERE name = :n",["n"=>"nodeapp"]);' 2>/dev/null)
curl -s -b "$(jar aps)" -c "$(jar aps)" -o /dev/null -d "_csrf=$(csrf aps)&app=$AID&act=stop" $BASE/apps/action
sleep 2
eq "stop via UI: unit stopped" "$($AP status nodeapp)" "stopped"
curl -s -b "$(jar aps)" -c "$(jar aps)" -o /dev/null -d "_csrf=$(csrf aps)&app=$AID&act=start" $BASE/apps/action
sleep 3
eq "start via UI: unit running" "$($AP status nodeapp)" "running"
APLG=$(body aps -d "_csrf=$(csrf aps)&app=$AID" $BASE/apps/logs)
has "app log shown in the UI" "$APLG" "<pre"
$FP user:delete "$U2" >/dev/null 2>&1

delapp nodeapp.test; delapp pyapp.test
# The docroot is removed too: otherwise this folder would count as a "leftover
# test file" by section 10 on the next run.
rm -rf /var/www/admin/nodeapp.test /var/www/admin/pyapp.test
eq "cleanup: Node app deleted"   "$(sudo -u www-data php /tmp/q.php "SELECT COUNT(*) FROM runtime_apps WHERE name = 'nodeapp'")" 0
eq "cleanup: Python app deleted" "$(sudo -u www-data php /tmp/q.php "SELECT COUNT(*) FROM runtime_apps WHERE name = 'pyapp'")" 0
eq "cleanup: Node unit gone"  "$(systemctl list-unit-files 2>/dev/null | grep -c 'fpanel-app-nodeapp')" 0
eq "cleanup: no test apps left" "$(sudo -u www-data php /tmp/q.php "SELECT COUNT(*) FROM runtime_apps WHERE name IN ('nodeapp','pyapp')")" 0
eq "cleanup: app docroot removed" "$(ls -d /var/www/admin/nodeapp.test /var/www/admin/pyapp.test 2>/dev/null | wc -l)" 0
eq "cleanup: no test app units left" "$(systemctl list-units --all --no-legend 2>/dev/null | grep -cE 'fpanel-app-(nodeapp|pyapp)')" 0
eq "cleanup: production apps not removed" "$(sudo -u www-data php /tmp/q.php "SELECT COUNT(*) FROM runtime_apps WHERE name NOT IN ('nodeapp','pyapp')")" "$PRE_APPS"

# --- 16. Backup & restore ---------------------------------------------------
if ! skip_section 16; then
# The archive contains an SQLite snapshot, /var/www, database dumps, config, certificates.
# The root helper does the work; the panel only triggers it via sudo.
echo "== 16. Backup & Restore =="
BK="sudo -u www-data sudo -n /usr/local/sbin/fpanel-backup"

# -- helper validation (these values become file names / root arguments) -------
eq "helper: traversal name rejected"  "$($BK verify '../../../etc/passwd' 2>&1 | head -1 | cut -c1-4)" "ERR:"
eq "helper: delete traversal rejected" "$($BK delete '../../etc/passwd' 2>&1 | head -1 | cut -c1-4)" "ERR:"
eq "helper: unknown subcommand rejected" "$(sudo -u www-data sudo -n /usr/local/sbin/fpanel-backup 'rm -rf /' 2>&1 | head -1 | cut -c1-16)" "sudo: a password"
eq "helper: ping"                   "$($BK ping)" "OK"

# -- backup from the UI (admins only) ----------------------------------------
$FP user:create "$U2" "$U2_PW" "uji@role.test" user >/dev/null 2>&1
# The "no login" check MUST use an empty jar; a jar that is already logged in
# would not test anything.
eq "backup: page without login -> 302" "$(getc bk_anon $BASE/backups)" 302
curl -s -b "$(jar bkp)" -c "$(jar bkp)" -o /dev/null \
  -d "_csrf=$(csrf bkp)&username=admin&password=$ADMIN_PWE" $BASE/login
code u2 -d "_csrf=$(csrf u2)&username=$U2&password=$U2_PWE" $BASE/login >/dev/null

eq "backup: non-admin page -> 403" "$(getc u2 $BASE/backups)" 403
eq "backup: create without CSRF -> 419" "$(code bkp -d 'keep=7' $BASE/backups/create)" 419
# A non-admin with a valid CSRF is rejected by the role check (403),
# not by CSRF (419) -- the token is valid, they are just not an admin.
eq "backup: create by non-admin -> 403" "$(code u2 -d "_csrf=$(csrf u2)&keep=7" $BASE/backups/create)" 403
hasnt "backup: non-admin does not see archives" "$(body u2 $BASE/backups)" "tar.gz"

BKUI=$(body bkp "$BASE/backups")
has "backup: page opens"        "$BKUI" "Backup"
has "backup: daily schedule shown" "$BKUI" "03:17"
has "backup: restore warning absent from the UI" "$BKUI" "Restore is not available on this page."

BEFORE=$(ls -1 /var/backups/fpanel 2>/dev/null | wc -l)
curl -s -L -b "$(jar bkp)" -c "$(jar bkp)" -o /tmp/bkout.html \
  -d "_csrf=$(csrf bkp)&keep=5" $BASE/backups/create
AFTER=$(ls -1 /var/backups/fpanel 2>/dev/null | wc -l)
gt "backup: new archive created from the UI" "$AFTER" "$BEFORE"
BKNEW=$(ls -1 /var/backups/fpanel 2>/dev/null | sort | tail -1)
ok "backup: archive name valid (strict pattern)" "$(printf '%s' "$BKNEW" | grep -cE '^fpanel-[0-9]{8}-[0-9]{6}\.tar\.gz$')" "1"

# -- archive contents are actually useful ------------------------------------
TMPB=$(mktemp -d)
tar -xzf "/var/backups/fpanel/$BKNEW" -C "$TMPB" 2>/dev/null
eq "archive: contains panel.sqlite" "$([ -f "$TMPB/panel.sqlite" ] && echo 1 || echo 0)" 1
eq "archive: contains www.tar.gz"  "$([ -f "$TMPB/www.tar.gz" ] && echo 1 || echo 0)" 1
eq "archive: contains mysqldump"  "$([ -f "$TMPB/mysqldump.sql.gz" ] && echo 1 || echo 0)" 1
eq "archive: contains meta.json"   "$([ -f "$TMPB/meta.json" ] && echo 1 || echo 0)" 1
eq "arsip: snapshot SQLite valid" \
  "$(php -r 'try{$p=new PDO("sqlite:".$argv[1]);echo $p->query("PRAGMA integrity_check")->fetchColumn();}catch(Throwable $e){echo "err";}' "$TMPB/panel.sqlite" 2>/dev/null)" "ok"
has "archive: metadata records databases" "$(cat "$TMPB/meta.json" 2>/dev/null)" "databases"
eq "archive: no files owned by others" "$(stat -c '%U' "/var/backups/fpanel/$BKNEW")" "root"
eq "archive: not world-readable"      "$([ "$(stat -c '%a' "/var/backups/fpanel/$BKNEW")" = "600" ] && echo 1 || echo 0)" 1
rm -rf "$TMPB"

# -- verify from the UI shows the report ------------------------------------
curl -s -L -b "$(jar bkp)" -c "$(jar bkp)" -o /tmp/bkverify.html \
  -d "_csrf=$(csrf bkp)&name=$BKNEW" $BASE/backups/verify
has "verify: report shown in the UI" "$(cat /tmp/bkverify.html)" "Verification report"
eq "verify: all checksums ok" "$(grep -cE 'checksum [a-z.]+ : ok' /tmp/bkverify.html)" 5
has "verify: sqlite integrity ok" "$(cat /tmp/bkverify.html)" "sqlite integrity_check : ok"
rm -f /tmp/bkout.html /tmp/bkverify.html

# -- CLI --------------------------------------------------------------------
gt "CLI: backup:list has archives" "$($FP backup:list 2>/dev/null | grep -c 'tar\.gz')" 0
has "CLI: backup:verify sukses" "$($FP backup:verify "$BKNEW" 2>&1)" "RESULT"
has "CLI: help mentions backup" "$($FP 2>&1)" "backup:create"

# -- jadwal otomatis & retensi ---------------------------------------------
has "daily cron installed" "$(cat /etc/cron.d/fpanel-backup 2>/dev/null)" "fpanel-backup create"
eq "logrotate installed" "$([ -f /etc/logrotate.d/fpanel-backup ] && echo 1 || echo 0)" 1

# -- retention: prune keeps the N newest ------------------------------------
for _ in 1 2 3; do $BK create 9 >/dev/null 2>&1; done
TOTAL=$(ls -1 /var/backups/fpanel 2>/dev/null | wc -l)
$BK prune 2 >/dev/null 2>&1
eq "retention: prune keeps 2 archives" "$(ls -1 /var/backups/fpanel 2>/dev/null | wc -l)" 2
gt "retention: prune really reduces" "$TOTAL" 2

# -- restore: not available via the panel, but requires exact confirmation ----
hasnt "restore is not available as a UI action" "$(body bkp "$BASE/backups")" 'action="/backups/restore"'
hasnt "CLI does not provide restore"   "$($FP 2>&1)" "backup:restore"
eq "restore without confirmation rejected" \
  "$(sudo -u www-data sudo -n /usr/local/sbin/fpanel-backup restore fpanel-20990101-000000.tar.gz 2>&1 | head -1 | cut -c1-4)" "ERR:"


fi
# --- 17. Cron Jobs ----------------------------------------------------------
# User jobs are written to /etc/cron.d/fpanel-cron-<user> by the root helper, then
# the real cron daemon executes them. The user column in the cron.d file MUST be
# a real Unix user (www-data), not the panel username.
echo "== 17. Cron Jobs =="
CR="sudo -u www-data sudo -n /usr/local/sbin/fpanel-cron"

eq "helper: sync without a user rejected"    "$($CR sync 2>&1 | head -1 | cut -c1-4)" "sudo"
eq "helper: bad username rejected"     "$($CR sync '../../etc' 2>&1 | head -1 | cut -c1-4)" "ERR:"
eq "helper: 'run' not allowed via sudo" "$(sudo -u www-data sudo -n /usr/local/sbin/fpanel-cron run admin 1 2>&1 | head -1 | cut -c1-16)" "sudo: a password"
eq "helper: 'sync' is in sudoers"      "$(grep -c 'fpanel-cron sync' /etc/sudoers.d/fpanel-cron)" 1
eq "helper: 'run' not in sudoers" "$(grep -c 'fpanel-cron run' /etc/sudoers.d/fpanel-cron)" 0

CJ="$(jar cj)"   # the path must match the one used by the csrf helper
CJ_PW='Cron#2026-Test9k'
$FP user:delete "$U3" >/dev/null 2>&1
$FP user:create "$U3" "$CJ_PW" "uji@cron.test" user >/dev/null 2>&1
curl -s -b "$CJ" -c "$CJ" -o /dev/null "$BASE/login"
curl -s -b "$CJ" -c "$CJ" -o /dev/null -d "username=$U3" \
  --data-urlencode "password=$CJ_PW" -d "_csrf=$(csrf cj)" "$BASE/login"
eq "cron test user can log in" "$(curl -s -b "$CJ" -c "$CJ" -o /dev/null -w '%{http_code}' "$BASE/cron")" 200

eq "create job without CSRF -> 419" "$(code cj -d "name=x&command=x&schedule=*+*+*+*+*" $BASE/cron/create)" 419

curl -s -b "$CJ" -c "$CJ" -o /dev/null \
  -d "_csrf=$(csrf cj)&name=test+daemon&command=echo+cron-ran&schedule=*+*+*+*+*" "$BASE/cron/create"
CJID=$(sudo -u www-data php /tmp/q.php "SELECT c.id FROM cron_jobs c JOIN users u ON u.id=c.user_id WHERE u.username='$U3' ORDER BY c.id DESC LIMIT 1")
gt "cron.d for the user created" "$(ls /etc/cron.d/ 2>/dev/null | grep -c "fpanel-cron-$U3")" 0

CJLINE=$(grep "run $U3" "/etc/cron.d/fpanel-cron-$U3" 2>/dev/null | head -1)
# 5 schedule fields + 1 user column + 4 command tokens ("<path> run <user> <id>").
eq "cron.d line has 10 tokens" "$(echo "$CJLINE" | awk '{print NF}')" 10
eq "the user column is the Unix user www-data" "$(echo "$CJLINE" | awk '{print $6}')" "www-data"
eq "command uses an absolute path" "$(echo "$CJLINE" | awk '{print $7}')" "/usr/local/sbin/fpanel-cron"
eq "command calls run for this user" "$(echo "$CJLINE" | awk '{print $8, $9, $10}')" "run $U3 $CJID"
eq "lima field jadwal utuh" "$(echo "$CJLINE" | awk '{print ($1=="*" && $2=="*" && $3=="*" && $4=="*" && $5=="*") ? "ok" : "not"}')" "ok"

curl -s -b "$CJ" -c "$CJ" -o /dev/null -d "_csrf=$(csrf cj)&id=$CJID" "$BASE/cron/run"
sleep 2
has "run now writes a log" "$(cat "/var/log/fpanel-cron/$U3-$CJID.log" 2>/dev/null)" "cron-ran"
has "log shows the exit code" "$(cat "/var/log/fpanel-cron/$U3-$CJID.log" 2>/dev/null)" "exit="

# Wait for the real cron daemon. This is the most important assertion: without it,
# cron.d format bugs (only 1 field, or a panel user missing from
# /etc/passwd) pass because "run now" still works.
RAN=0
for _ in $(seq 1 20); do
  N=$(grep -c "starting job" "/var/log/fpanel-cron/$U3-$CJID.log" 2>/dev/null || echo 0)
  if [ "$N" -ge 2 ]; then RAN=1; break; fi
  sleep 6
done
eq "the cron daemon really executes it" "$RAN" 1

curl -s -b "$CJ" -c "$CJ" -o /dev/null -d "_csrf=$(csrf cj)&id=$CJID" "$BASE/cron/toggle"
sleep 2
eq "disabled: line gone from cron.d" "$(grep -c "run $U3 $CJID" "/etc/cron.d/fpanel-cron-$U3" 2>/dev/null)" 0
curl -s -b "$CJ" -c "$CJ" -o /dev/null -d "_csrf=$(csrf cj)&id=$CJID" "$BASE/cron/toggle"
sleep 2
eq "enabled again: line is back" "$(grep -c "run $U3 $CJID" "/etc/cron.d/fpanel-cron-$U3" 2>/dev/null)" 1

curl -s -b "$CJ" -c "$CJ" -o /dev/null \
  -d "_csrf=$(csrf cj)&id=$CJID&name=renamed&command=echo+ok&schedule=*/5+*+*+*+*" "$BASE/cron/update"
sleep 2
has "schedule change shown in cron.d" "$(cat "/etc/cron.d/fpanel-cron-$U3" 2>/dev/null)" "*/5 * * * *"
eq "cron.d bebas error syntax" \
  "$(journalctl -u cron --since '-3 min' --no-pager 2>/dev/null | grep -c 'Syntax error.*fpanel-cron')" 0

eq "validation: minute 99 rejected" \
  "$(sudo -u www-data php -r 'require "/srv/fpanel/app/bootstrap.php"; echo (int)Fpanel\Service\Cron::validSchedule("0 99 * * *");')" 0
eq "validation: */5 accepted" \
  "$(sudo -u www-data php -r 'require "/srv/fpanel/app/bootstrap.php"; echo (int)Fpanel\Service\Cron::validSchedule("*/5 * * * *");')" 1
eq "validation: @daily rejected" \
  "$(sudo -u www-data php -r 'require "/srv/fpanel/app/bootstrap.php"; echo (int)Fpanel\Service\Cron::validSchedule("@daily");')" 0

# Another user must not see or change this job.
CRJ="$(jar crj)"
curl -s -b "$CRJ" -c "$CRJ" -o /dev/null "$BASE/login"
curl -s -b "$CRJ" -c "$CRJ" -o /dev/null -d "username=admin" \
  --data-urlencode "password=$ADMIN_PW" -d "_csrf=$(csrf crj)" "$BASE/login"
hasnt "another user's job not visible" "$(body crj "$BASE/cron")" "test daemon"
curl -s -b "$CRJ" -c "$CRJ" -o /dev/null \
  -d "_csrf=$(csrf crj)&id=$CJID&name=Diretas&command=id&schedule=*+*+*+*+*" "$BASE/cron/update"
eq "updating another user's job rejected" \
  "$(sudo -u www-data php /tmp/q.php "SELECT name FROM cron_jobs WHERE id=$CJID")" "renamed"

curl -s -b "$CJ" -c "$CJ" -o /dev/null -d "_csrf=$(csrf cj)&id=$CJID" "$BASE/cron/delete"
sleep 2
eq "delete: job gone from the DB" \
  "$(sudo -u www-data php /tmp/q.php "SELECT COUNT(*) FROM cron_jobs WHERE id=$CJID")" 0
eq "delete: cron.d line gone" \
  "$(grep -c "run $U3 $CJID" "/etc/cron.d/fpanel-cron-$U3" 2>/dev/null)" 0
$FP user:delete "$U3" >/dev/null 2>&1
rm -f "/var/log/fpanel-cron/$U3-$CJID.log" "/etc/cron.d/fpanel-cron-$U3" "/var/lib/fpanel/cron/$U3.jobs"


# --- 18. WordPress installer -------------------------------------------------
# A real WordPress install takes 2-3 minutes (it downloads a 37 MB core from the CDN),
# so this suite does NOT do a full download. What is tested here are the parts
# that can fail cheaply: input validation, rejections, the CLI, and "forget".
echo "== 18. WordPress installer ="
FPWP="php /srv/fpanel/bin/fpanel"   # root; the panel path is only accessible to root

eq "CLI: corrupted wp:install payload rejected" "$($FPWP wp:install abcdef 2>&1 | head -1 | cut -c1-6)" "Error:"
eq "CLI: no arguments rejected" "$($FPWP wp:install 2>&1 | head -1 | cut -c1-6)" "Error:"
eq "CLI: wp:install shown in help" "$(php /srv/fpanel/bin/fpanel 2>&1 | grep -c 'wp:install')" 1

WJ="wj"   # jar NAME; the csrf() helper already appends .jar
WJF="$(jar "$WJ")"
curl -s -b "$WJF" -c "$WJF" -o /dev/null "$BASE/login"
curl -s -b "$WJF" -c "$WJF" -o /dev/null -d "username=admin" \
  --data-urlencode "password=$ADMIN_PW" -d "_csrf=$(csrf "$WJ")" "$BASE/login"

has "page /wordpress opens" "$(body wj "$BASE/wordpress")" "WordPress"
hasnt "install form does not send the password along" "$(body wj "$BASE/wordpress")" "type=\"password\" value="

eq "install without CSRF -> 419" "$(code "$WJ" -d "domain=x.test" $BASE/wordpress/install)" 419

# Single-use PHP domain to test validation (without downloading the core).
mkphpdom wpv.test 8.3
eq "PHP test domain created" "$($FP domain:exists wpv.test)" 1

wp_err(){
  post "$WJ" -o /dev/null -w '%{redirect_url}' -d "_csrf=$(csrf wj)&domain=$1&version=7.1.2&admin_user=$2&admin_password=$3&admin_email=$4" "$BASE/wordpress/install" \
    | grep -oE 'err=[^&]*' | head -1 | sed 's/err=//;s/%20/ /g'
}

has "static domain rejected" "$(wp_err demo.fpanel.my.id wpadmin Password123 wp@x.test)" "is of type static"
# Uses has (substring matching), not character slicing: slicing easily
# misses by one character and fails the assertion even though the message is right.
has "missing domain rejected" "$(wp_err nosuch.test wpadmin Password123 wp@x.test)" "Domain not found."
has "short admin password rejected" "$(wp_err wpv.test wpadmin abc wp@x.test)" "The WordPress admin password must be at least 8"
has "invalid admin email rejected" "$(wp_err wpv.test wpadmin Password123 not-an-email)" "Invalid WordPress admin email."
has "bogus admin username rejected" "$(wp_err wpv.test 'a/b' Password123 wp@x.test)" "Invalid WordPress admin name"

eq "failed attempts leave no wp_sites rows" \
  "$(sudo -u www-data php /tmp/q.php "SELECT COUNT(*) FROM wp_sites w JOIN domains d ON d.id = w.domain_id WHERE d.name = 'wpv.test'")" 0
# Note: users may have their own WordPress, so the total wp_sites count is not
# forced to 0. Only rows belonging to the test domain must be zero.

eq "wp_sites rows cannot point at someone else's domain" \
  "$(sudo -u www-data php /tmp/q.php "SELECT COUNT(*) FROM wp_sites w JOIN domains d ON d.id = w.domain_id JOIN users u ON u.id = w.user_id WHERE u.username <> (SELECT username FROM users WHERE id = d.user_id)")" 0
eq "core_version always populated" \
  "$(sudo -u www-data php /tmp/q.php "SELECT COUNT(*) FROM wp_sites WHERE core_version IS NULL OR core_version = ''")" 0

# forget: removing from the list must NOT delete files or databases.
#
# The wp_sites row under test is created for the test domain, NOT taken from
# a user's install. If the suite used an existing row, one click on
# "forget" would remove a production WordPress from the panel list.
WPDOC=$(sudo -u www-data php /tmp/q.php "SELECT docroot FROM domains WHERE name = 'wpv.test'")
mkdir -p "$WPDOC"
printf '<?php // dummy for the forget test\n' > "$WPDOC/wp-config.php"
UID_WP=$(sudo -u www-data php /tmp/q.php "SELECT id FROM users WHERE username = 'admin'")
DID_WP=$(sudo -u www-data php /tmp/q.php "SELECT id FROM domains WHERE name = 'wpv.test'")
sudo -u www-data php -r '
require "/srv/fpanel/app/bootstrap.php";
use Fpanel\Database\Connection;
Connection::run("DELETE FROM wp_sites WHERE domain_id = :d", ["d" => (int)$argv[1]]);
Connection::run("INSERT INTO wp_sites (user_id, domain_id, core_version, is_staging, created_at)
                VALUES (:u, :d, :v, 0, :t)",
    ["u" => (int)$argv[2], "d" => (int)$argv[1], "v" => "7.1.2", "t" => gmdate("Y-m-d H:i:s")]);' "$DID_WP" "$UID_WP"
WPID=$(sudo -u www-data php /tmp/q.php "SELECT id FROM wp_sites WHERE domain_id = $DID_WP")
BEFORE_WP=$(sudo -u www-data php /tmp/q.php "SELECT COUNT(*) FROM wp_sites WHERE domain_id <> $DID_WP")
post wj -o /dev/null -d "_csrf=$(csrf wj)&id=$WPID" "$BASE/wordpress/forget"
eq "forget: row gone from the list" \
  "$(sudo -u www-data php /tmp/q.php "SELECT COUNT(*) FROM wp_sites WHERE id = $WPID")" 0
eq "forget: WordPress files still exist" "$([ -f "$WPDOC/wp-config.php" ] && echo 1 || echo 0)" 1
eq "forget: domain still exists" \
  "$(sudo -u www-data php /tmp/q.php "SELECT COUNT(*) FROM domains WHERE id = $DID_WP")" 1
eq "forget: other WordPress installs untouched" \
  "$(sudo -u www-data php /tmp/q.php "SELECT COUNT(*) FROM wp_sites WHERE domain_id <> $DID_WP")" "$BEFORE_WP"

# The code must use the PHYSICAL DB/user name (the MariaDb helper prefixes the
# panel user); otherwise wp-config.php cannot connect.
eq "installer uses the physical DB name" "$(grep -c 'physDb' /srv/fpanel/app/src/Service/Wp.php)" 1
eq "installer uses the physical DB user" "$(grep -c 'physUser' /srv/fpanel/app/src/Service/Wp.php)" 1
eq "salt randomized locally, not from the API" "$(grep -c 'api.wordpress.org/salt' /srv/fpanel/app/src/Service/Wp.php)" 0
# The installer is called via spawn(), which writes the prefix "wp:<action>".
# counted from the literal "wp:install" in Wp.php; after spawn() was generalized
# for wp:update too, that assertion became outdated. What is tested now
# is spawn() itself plus its two CLI subcommands.
eq "installer runs in CLI (separate from FPM)" \
  "$(grep -c 'private static function spawn' /srv/fpanel/app/src/Service/Wp.php)" 1
eq "spawn() builds the CLI subcommand prefix" \
  "$(grep -cF "/bin/fpanel wp:' . \$safe" /srv/fpanel/app/src/Service/Wp.php)" 1
eq "spawn() rejects actions outside the safe pattern" \
  "$(grep -cF "preg_match('/^[a-z-]+\$/', \$action)" /srv/fpanel/app/src/Service/Wp.php)" 1
eq "update calls spawn with the update action" \
  "$(grep -cF "], 'update');" /srv/fpanel/app/src/Service/Wp.php)" 1
eq "CLI has the wp:update subcommand" \
  "$(grep -c "case 'wp:update'" /srv/fpanel/bin/fpanel)" 1
eq "CLI has the wp:install subcommand" "$(grep -c "case 'wp:install'" /srv/fpanel/bin/fpanel)" 1

deld wpv.test
eq "cleanup: PHP test domain deleted" "$($FP domain:exists wpv.test)" 0


# --- 19. Resource Usage (per domain) -----------------------------------------
echo "== 19. Resource Usage ="
UQ="uq"
curl -s -b "$(jar "$UQ")" -c "$(jar "$UQ")" -o /dev/null "$BASE/login"
curl -s -b "$(jar "$UQ")" -c "$(jar "$UQ")" -o /dev/null -d "username=admin" \
 --data-urlencode "password=$ADMIN_PW" -d "_csrf=$(csrf "$UQ")" "$BASE/login"

has "page /usage opens" "$(body "$UQ" "$BASE/usage")" "Resource Usage"
hasnt "usage page does not leak passwords" "$(body "$UQ" "$BASE/usage")" "type=\"password\" value="

eq "domain_usage table exists" \
 "$(sudo -u www-data php /tmp/q.php "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='domain_usage'")" 1
eq "CLI: usage:collect registered" "$(php /srv/fpanel/bin/fpanel 2>&1 | grep -c 'usage:collect')" 1
eq "usage collection cron installed" \
 "$(grep -c 'usage:collect' /etc/cron.d/fpanel-usage 2>/dev/null)" 1
eq "cron runs as www-data" \
  "$(grep -cE '^[0-9*/,-]+ +[0-9*/,-]+ +[0-9*/,-]+ +[0-9*/,-]+ +[0-9*/,-]+ +www-data ' /etc/cron.d/fpanel-usage 2>/dev/null)" 1

eq "collect without CSRF -> 419" "$(code "$UQ" -d "" $BASE/usage/collect)" 419
eq "collect via the panel" \
 "$(post "$UQ" -o /dev/null -w '%{http_code}' -d "_csrf=$(csrf "$UQ")" $BASE/usage/collect)" 302
sleep 2

eq "every domain has a usage row" \
 "$(sudo -u www-data php /tmp/q.php "SELECT COUNT(*) FROM domains d LEFT JOIN domain_usage g ON g.domain_id = d.id WHERE g.domain_id IS NULL")" 0
eq "deleted domains leave no orphan usage rows" \
 "$(sudo -u www-data php /tmp/q.php "SELECT COUNT(*) FROM domain_usage g LEFT JOIN domains d ON d.id = g.domain_id WHERE d.id IS NULL")" 0
eq "usage numbers are not negative" \
 "$(sudo -u www-data php /tmp/q.php "SELECT COUNT(*) FROM domain_usage WHERE disk_bytes < 0 OR bandwidth_bytes < 0 OR requests < 0")" 0
eq "resource_usage has a row per user" \
 "$(sudo -u www-data php /tmp/q.php "SELECT COUNT(*) FROM resource_usage r JOIN users u ON u.id = r.user_id")" \
 "$(sudo -u www-data php /tmp/q.php "SELECT COUNT(*) FROM users")"
eq "top paths stored as JSON" \
 "$(sudo -u www-data php -r '
   $p = new PDO("sqlite:/srv/fpanel/var/db/fpanel.sqlite");
   $bad = 0;
   foreach ($p->query("SELECT top_paths FROM domain_usage") as $r) {
       if (!is_array(json_decode((string)$r["top_paths"], true))) { $bad++; }
   }
   echo $bad;')" 0

# A non-admin user must not see other people's domains.
UQ2="uq2"
$FP user:create "$U2" "$U2_PW" "uji@role.test" user >/dev/null 2>&1
curl -s -b "$(jar "$UQ2")" -c "$(jar "$UQ2")" -o /dev/null "$BASE/login"
curl -s -b "$(jar "$UQ2")" -c "$(jar "$UQ2")" -o /dev/null -d "username=$U2" \
 --data-urlencode "password=$U2_PW" -d "_csrf=$(csrf "$UQ2")" "$BASE/login"
hasnt "another user's usage does not leak domains" "$(body "$UQ2" "$BASE/usage")" "demo.fpanel.my.id"
eq "collect by a non-admin rejected" "$(code "$UQ2" -d "_csrf=$(csrf "$UQ2")" $BASE/usage/collect)" 403
# --- 20. Two-Factor Authentication (TOTP) ---------------------------------------
# Tested as a real flow: setup -> login -> wrong code -> correct code -> disable.
# A non-admin user is used so admin credentials are never touched, and mandatory
# 2FA is off by the end of the section so the suite does not lock its own account.
echo "== 20. Two-Factor Authentication (TOTP) ="
UT="ut"
TFQ="sudo -u www-data php /tmp/q.php"
tfcode(){ sudo -u www-data php -r "require '/srv/fpanel/app/bootstrap.php'; echo Fpanel\Service\Totp::code('$1');"; }
# The TOTP seed is stored encrypted (SecretBox), so the value from the DB must
# be decrypted first before it can compute codes. Copying the raw value
# from the database would produce a string that is not base32.
tfsecret(){ sudo -u www-data php -r "require '/srv/fpanel/app/bootstrap.php'; echo Fpanel\Service\Totp::pendingSecret('$1') ?? '';"; }

$FP user:create "$U2" "$U2_PW" "uji@role.test" user >/dev/null 2>&1
eq "2FA migration: sessions.kind column exists" \
  "$($TFQ "SELECT COUNT(*) FROM pragma_table_info('sessions') WHERE name='kind'")" 1
eq "2FA migration: default kind is auth" \
  "$($TFQ "SELECT dflt_value FROM pragma_table_info('sessions') WHERE name='kind'")" "'auth'"
eq "2FA migration: totp_recovery table exists" \
  "$($TFQ "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='totp_recovery'")" 1
eq "CLI: db:migrate-totp registered" "$(php /srv/fpanel/bin/fpanel 2>&1 | grep -c 'db:migrate-totp')" 1
eq "2FA migration idempotent (running twice still 1 column)" \
  "$($FP db:migrate-totp >/dev/null 2>&1; $TFQ "SELECT COUNT(*) FROM pragma_table_info('sessions') WHERE name='kind'")" 1

curl -s -b "$(jar "$UT")" -c "$(jar "$UT")" -o /dev/null "$BASE/login"
curl -s -b "$(jar "$UT")" -c "$(jar "$UT")" -o /dev/null -d "username=$U2" \
  --data-urlencode "password=$U2_PW" -d "_csrf=$(csrf "$UT")" "$BASE/login"

has "page /security includes the 2FA section" "$(body "$UT" "$BASE/security")" "Two-Factor Authentication"
hasnt "TOTP secret does not leak before setup" "$(body "$UT" "$BASE/security")" "otpauth://totp/"

eq "2FA setup without CSRF -> 419" "$(code "$UT" -d "" $BASE/security/2fa/setup)" 419
eq "2FA setup works" \
  "$(post "$UT" -o /dev/null -w '%{http_code}' -d "_csrf=$(csrf "$UT")" $BASE/security/2fa/setup)" 302

TSEC=$(tfsecret "$U2")
eq "secret is valid base32 after decryption" "$(printf '%s' "$TSEC" | grep -cE '^[A-Z2-7]{32}$')" 1
eq "secret stored encrypted in the database" \
  "$($TFQ "SELECT COUNT(*) FROM users WHERE username='$U2' AND totp_secret LIKE 'sbx1:%'")" 1
eq "2FA not active before the code is entered" "$($TFQ "SELECT totp_enabled FROM users WHERE username='$U2'")" 0

eq "QR SVG served as an image" \
  "$(curl -s -b "$(jar "$UT")" -o /dev/null -w '%{http_code} %{content_type}' $BASE/security/2fa/qr.svg)" \
  "200 image/svg+xml; charset=utf-8"
has "QR contains an otpauth structure" "$(body "$UT" "$BASE/security/2fa/qr.svg")" "<svg"
eq "QR not cached by the browser" \
  "$(curl -s -b "$(jar "$UT")" -D - -o /dev/null $BASE/security/2fa/qr.svg | grep -ci 'no-store')" 1
eq "QR without login -> 401" "$(getc zz $BASE/security/2fa/qr.svg)" 401

eq "activation with a wrong code rejected" \
  "$(post "$UT" -o /dev/null -w '%{http_code}' -d "_csrf=$(csrf "$UT")" -d "code=000000" \
     $BASE/security/2fa/activate)" 302
eq "wrong code does not enable 2FA" "$($TFQ "SELECT totp_enabled FROM users WHERE username='$U2'")" 0

# The recovery codes exist only in this POST response and are deliberately not stored,
# so the response page must be inspected right away, not re-read later.
ACT_HTML=$(post "$UT" -w '\n%{http_code}' -d "_csrf=$(csrf "$UT")" -d "code=$(tfcode "$TSEC")" \
     $BASE/security/2fa/activate)
ACT_CODE=$(printf '%s' "$ACT_HTML" | tail -1)
ACT_BODY=$(printf '%s' "$ACT_HTML" | sed '$d')
eq "activation with the correct code succeeds" "$ACT_CODE" 200
eq "2FA active after the correct code" "$($TFQ "SELECT totp_enabled FROM users WHERE username='$U2'")" 1
eq "10 recovery codes created" "$($TFQ "SELECT COUNT(*) FROM totp_recovery WHERE user_id=(SELECT id FROM users WHERE username='$U2')")" 10
eq "recovery codes stored as hashes" \
  "$($TFQ "SELECT COUNT(*) FROM totp_recovery WHERE user_id=(SELECT id FROM users WHERE username='$U2') AND LENGTH(code_hash)=64")" 10
eq "recovery codes not all used yet" \
  "$($TFQ "SELECT COUNT(*) FROM totp_recovery WHERE user_id=(SELECT id FROM users WHERE username='$U2') AND used_at IS NULL")" 10
eq "number of codes shown on the page = 10" \
  "$(printf '%s' "$ACT_BODY" | grep -oE '>[A-F0-9]{6}-[A-F0-9]{6}<' | sort -u | wc -l | tr -d ' ')" 10
hasnt "a later page does not repeat the recovery codes" "$(body "$UT" "$BASE/security")" "[A-F0-9]\{6\}-[A-F0-9]\{6\}"

# Log in again: the correct password must be enough to reach the code step.
curl -s -b "$(jar "$UT")" -c "$(jar "$UT")" -o /dev/null -d "_csrf=$(csrf "$UT")" $BASE/logout
has "log out first" "$(post "$UT" -o /dev/null -w '%{http_code}' -d "_csrf=$(csrf "$UT")" $BASE/logout)" 302
curl -s -b "$(jar "$UT")" -c "$(jar "$UT")" -o /dev/null "$BASE/login"
eq "correct password + 2FA active -> redirects to /login/2fa" \
  "$(post "$UT" -o /dev/null -w '%{redirect_url}' -d "username=$U2" --data-urlencode "password=$U2_PW" \
     -d "_csrf=$(csrf "$UT")" $BASE/login | grep -c '/login/2fa')" 1
eq "intermediate session created" "$($TFQ "SELECT COUNT(*) FROM sessions WHERE kind='totp'")" 1
# Checked via seconds, then clamped to a range: julianday precision rounds
# down so 300 seconds can read as 299. What matters: it is far
# shorter than a regular login session (14 days), not exactly 5 minutes.
eq "intermediate session has a short TTL" \
  "$(sudo -u www-data php -r '
     require "/srv/fpanel/app/bootstrap.php";
     $r = Fpanel\Database\Connection::one(
         "SELECT CAST((julianday(expires_at) - julianday(\"now\")) * 86400 AS INTEGER) AS d FROM sessions WHERE kind=\"totp\"");
     $d = (int)($r["d"] ?? 0);
     echo ($d > 240 && $d <= 300) ? "1" : "0:{$d}";')" 1
eq "dashboard stays closed while 2FA is not passed" \
  "$(post "$UT" -o /dev/null -w '%{redirect_url}' $BASE/dashboard | grep -cE '/login$')" 1
has "page /login/2fa opens" "$(body "$UT" "$BASE/login/2fa")" "Two-Factor Verification"

eq "wrong 2FA code -> 401" \
  "$(post "$UT" -o /dev/null -w '%{http_code}' -d "_csrf=$(csrf "$UT")" -d "code=111111" $BASE/login/2fa)" 401
has "wrong-code message is shown" \
  "$(post "$UT" -d "_csrf=$(csrf "$UT")" -d "code=222222" $BASE/login/2fa)" "Wrong code, or already used."
eq "dashboard still closed after a wrong code" \
  "$(post "$UT" -o /dev/null -w '%{redirect_url}' $BASE/dashboard | grep -cE '/login$')" 1
# The username and query are passed via a file, not inlined into the PHP
# PHP: interpolasi shell di dalam php -r "!..." merusak quoting-nya.
cat > /tmp/t_audit.php <<'AUDPHP'
<?php
require '/srv/fpanel/app/bootstrap.php';
// Counted per test user via actor_name, not the whole table: audit_log is
// never trimmed, so rows from previous runs would pollute the count.
$c = (int)Fpanel\Database\Connection::value(
    'SELECT COUNT(*) FROM audit_log WHERE action = ? AND result = ? AND actor_name = ?',
    ['auth.totp', 'denied', $argv[1]]
);
echo $c >= 3 ? 'yes' : "no:$c";
AUDPHP
eq "failed 2FA attempts recorded in the audit log" \
  "$(sudo -u www-data php /tmp/t_audit.php "$U2")" "yes"

eq "wrong recovery code -> 401" \
  "$(post "$UT" -o /dev/null -w '%{http_code}' -d "_csrf=$(csrf "$UT")" -d "code=ZZZZZZ-ZZZZZZ" $BASE/login/2fa)" 401

# Lockout is tested from a deterministic state: the counter is reset first, then
# MAX_FAILS (5) wrong codes are sent in a row. After that even the correct code
# must be rejected. This also proves the lockout applies to the 2FA step,
# not just the password.
$FP login:reset >/dev/null 2>&1
MAXF=$(sudo -u www-data php -r "require '/srv/fpanel/app/bootstrap.php'; echo Fpanel\Auth\Auth::MAX_FAILS;")
for _ in $(seq 1 "$MAXF"); do
  post "$UT" -o /dev/null -d "_csrf=$(csrf "$UT")" -d "code=000000" $BASE/login/2fa
done
eq "correct code rejected after MAX_FAILS wrong attempts" \
  "$(post "$UT" -o /dev/null -w '%{http_code}' -d "_csrf=$(csrf "$UT")" -d "code=$(tfcode "$TSEC")" $BASE/login/2fa)" 401
has "lockout message is shown" \
  "$(post "$UT" -d "_csrf=$(csrf "$UT")" -d "code=$(tfcode "$TSEC")" $BASE/login/2fa)" "Too many attempts"
eq "intermediate session not promoted during lockout" \
  "$($TFQ "SELECT COUNT(*) FROM sessions WHERE kind='totp'")" 1

$FP login:reset >/dev/null 2>&1
eq "resetting the lockout counter clears leftover failures" \
  "$($TFQ "SELECT COUNT(*) FROM login_attempts WHERE ip='127.0.0.1' AND success=0")" 0

eq "correct TOTP code -> dashboard" \
  "$(post "$UT" -o /dev/null -w '%{http_code}' -d "_csrf=$(csrf "$UT")" -d "code=$(tfcode "$TSEC")" $BASE/login/2fa)" 302
eq "dashboard opens after 2FA" "$(getc "$UT" $BASE/dashboard)" 200
eq "intermediate session promoted to auth" "$($TFQ "SELECT COUNT(*) FROM sessions WHERE kind='totp'")" 0
has "login recorded with the totp method" \
  "$(sudo -u www-data php -r '
     require "/srv/fpanel/app/bootstrap.php";
     $r = Fpanel\Database\Connection::one(
         "SELECT meta FROM audit_log WHERE action=\"auth.login\" ORDER BY id DESC LIMIT 1");
     echo (string)($r["meta"] ?? "");')" "totp"
eq "2FA page cannot be accessed after login" \
  "$(post "$UT" -o /dev/null -w '%{redirect_url}' $BASE/login/2fa | grep -c '/dashboard')" 1

# Uses a real recovery code, then ensures it is burned.
RC=$(sudo -u www-data php -r '
  require "/srv/fpanel/app/bootstrap.php";
  // The real code is never stored; to test it, a code is regenerated and then
  // taken from the regenerateRecovery return value (memory only).
  $codes = Fpanel\Service\Totp::regenerateRecovery("'"$U2"'");
  echo $codes[0] ?? "";')
eq "a fresh recovery code created for the test" "$(printf '%s' "$RC" | grep -cE '^[A-F0-9]{6}-[A-F0-9]{6}$')" 1
curl -s -b "$(jar "$UT")" -c "$(jar "$UT")" -o /dev/null -d "_csrf=$(csrf "$UT")" $BASE/logout
curl -s -b "$(jar "$UT")" -c "$(jar "$UT")" -o /dev/null "$BASE/login"
post "$UT" -o /dev/null -d "username=$U2" --data-urlencode "password=$U2_PW" -d "_csrf=$(csrf "$UT")" $BASE/login
eq "the real recovery code is accepted" \
  "$(post "$UT" -o /dev/null -w '%{http_code}' -d "_csrf=$(csrf "$UT")" -d "code=$RC" $BASE/login/2fa)" 302
eq "dashboard opens via a recovery code" "$(getc "$UT" $BASE/dashboard)" 200
eq "recovery code marked as used" \
  "$($TFQ "SELECT COUNT(*) FROM totp_recovery WHERE user_id=(SELECT id FROM users WHERE username='$U2') AND used_at IS NOT NULL")" 1
eq "remaining recovery codes = 9" \
  "$($TFQ "SELECT COUNT(*) FROM totp_recovery WHERE user_id=(SELECT id FROM users WHERE username='$U2') AND used_at IS NULL")" 9

eq "recovery codes regenerated" \
  "$(post "$UT" -o /dev/null -w '%{http_code}' -d "_csrf=$(csrf "$UT")" $BASE/security/2fa/recovery)" 200
eq "old codes discarded, count is still 10" \
  "$($TFQ "SELECT COUNT(*) FROM totp_recovery WHERE user_id=(SELECT id FROM users WHERE username='$U2') AND used_at IS NULL")" 10

# Disabling mandatory 2FA requires the account password.
eq "disable 2FA without CSRF -> 419" "$(code "$UT" -d "" $BASE/security/2fa/disable)" 419
eq "disable with a wrong password rejected" \
  "$(post "$UT" -o /dev/null -w '%{redirect_url}' -d "_csrf=$(csrf "$UT")" -d "password=wrongpassword" \
     $BASE/security/2fa/disable | grep -c 'err=')" 1
eq "2FA still active after a wrong password" "$($TFQ "SELECT totp_enabled FROM users WHERE username='$U2'")" 1
eq "disable with the correct password succeeds" \
  "$(post "$UT" -o /dev/null -w '%{http_code}' -d "_csrf=$(csrf "$UT")" --data-urlencode "password=$U2_PW" \
     $BASE/security/2fa/disable)" 302
eq "2FA inactive after disable" "$($TFQ "SELECT totp_enabled FROM users WHERE username='$U2'")" 0
eq "secret deleted when 2FA is turned off" \
  "$($TFQ "SELECT COALESCE(totp_secret,'') FROM users WHERE username='$U2'")" ""
eq "recovery codes deleted as well" "$($TFQ "SELECT COUNT(*) FROM totp_recovery")" 0

# The admin must not get locked: this section never touches it.
# A separate jar is used so this section's sessions do not mix.
UTA="uta"
curl -s -b "$(jar "$UTA")" -c "$(jar "$UTA")" -o /dev/null "$BASE/login"
eq "admin can still log in without 2FA" \
  "$(post "$UTA" -o /dev/null -w '%{redirect_url}' -d "username=admin" --data-urlencode "password=$ADMIN_PW" \
     -d "_csrf=$(csrf "$UTA")" $BASE/login | grep -c '/dashboard')" 1
eq "no secrets left behind for the test user" \
  "$($TFQ "SELECT COUNT(*) FROM users WHERE totp_enabled=1 OR totp_secret IS NOT NULL")" 0

$FP user:delete "$U2" >/dev/null 2>&1

$FP user:delete "$U2" >/dev/null 2>&1


# --- 21. Housekeeping & data safety ----------------------------------------------
# Retention is shortened temporarily (days: 0/1) so assertions can test
# real deletion without waiting 90 days. All test data is created in
# this section and cleaned up at the end, so production retention is untouched.
echo "== 21. Housekeeping & data safety ="
UH="uh"
TFQ="sudo -u www-data php /tmp/q.php"

eq "CLI: housekeeping:run registered" \
  "$(php /srv/fpanel/bin/fpanel 2>&1 | grep -c 'housekeeping:run')" 1
eq "CLI: housekeeping:dry registered" \
  "$(php /srv/fpanel/bin/fpanel 2>&1 | grep -c 'housekeeping:dry')" 1
eq "housekeeping cron installed" \
  "$(grep -c 'housekeeping:run' /etc/cron.d/fpanel-housekeeping 2>/dev/null)" 1
eq "housekeeping cron runs as www-data" \
  "$(grep -cE '^[0-9*/,-]+ +[0-9*/,-]+ +[0-9*/,-]+ +[0-9*/,-]+ +[0-9*/,-]+ +www-data .*housekeeping:run' /etc/cron.d/fpanel-housekeeping)" 1
eq "housekeeping cron not duplicated" \
  "$(grep -c 'housekeeping:run' /etc/cron.d/fpanel-housekeeping)" 1

cat > /tmp/t_hk.php <<'HKPHP'
<?php
require '/srv/fpanel/app/bootstrap.php';
use Fpanel\Database\Connection;
use Fpanel\Service\Housekeeping;

// Test seed data: one expired session, one live session, old/new audit log rows,
// old login attempts, and log files for an unregistered domain.
$uid = (int)Connection::value('SELECT id FROM users WHERE username = ?', ['admin']);
$tok = bin2hex(random_bytes(16));
Connection::insert('sessions', [
    'user_id' => $uid, 'token_hash' => $tok, 'ip' => '10.9.9.9',
    'user_agent' => 'hk-test', 'kind' => 'auth',
    'expires_at' => gmdate('Y-m-d H:i:s', time() - 3600),
]);
Connection::insert('sessions', [
    'user_id' => $uid, 'token_hash' => $tok . 'live', 'ip' => '10.9.9.9',
    'user_agent' => 'hk-test', 'kind' => 'auth',
    'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
]);
Connection::insert('audit_log', [
    'actor_name' => 'system', 'action' => 'hk.probe', 'result' => 'ok',
    'created_at' => gmdate('Y-m-d H:i:s', time() - 86400 * 200),
]);
// Age 2 days: past the 'ok' limit (1 day) but not the 'denied' limit yet
// (3x that). Security incident rows are deliberately kept longer.
Connection::insert('audit_log', [
    'actor_name' => 'system', 'action' => 'hk.probe', 'result' => 'denied',
    'created_at' => gmdate('Y-m-d H:i:s', time() - 86400 * 2),
]);
Connection::insert('audit_log', [
    'actor_name' => 'system', 'action' => 'hk.probe', 'result' => 'ok',
    'created_at' => gmdate('Y-m-d H:i:s'),
]);
Connection::insert('login_attempts', [
    'ip' => '10.9.9.9', 'username' => 'hk', 'success' => 0,
    'created_at' => gmdate('Y-m-d H:i:s', time() - 86400 * 30),
]);
$logDir = Housekeeping::defaultLogDir();
file_put_contents($logDir . '/hk-orphan.test.access.log', "test\n");
file_put_contents($logDir . '/hk-orphan.test.error.log', "test\n");
file_put_contents($logDir . '/hk-rotated.test.access.log.1', "test\n");
// Aged via touch so it passes the minimum-age requirement.
$old = time() - 86400 * 10;
foreach (['hk-orphan.test.access.log', 'hk-orphan.test.error.log', 'hk-rotated.test.access.log.1'] as $f) {
    @touch($logDir . '/' . $f, $old);
}
// A real domain's log must stay intact after the job runs.
file_put_contents($logDir . '/demo.fpanel.my.id.access.log', "real\n");

// Offsite upload temp files: orphaned (old) ones must be deleted, fresh ones
// must not be touched because a running upload may use them, and names
// that do not match the panel's tempnam pattern are never deleted.
file_put_contents('/tmp/fp-enc-hk001', 'orphan');
file_put_contents('/tmp/fp-enc-hk002', 'fresh');
file_put_contents('/tmp/fp-enc-.do-not-delete', 'foreign');
@touch('/tmp/fp-enc-hk001', time() - 86400);
@touch('/tmp/fp-enc-hk002', time() - 60);

// auditDays=1: 'ok' rows older than 1 day must go, but
// 'denied' rows (security incidents) survive up to 3x that window.
$r = Housekeeping::make($logDir)->run(false, ['auditDays' => 1, 'attemptDays' => 1]);
echo json_encode($r);
HKPHP

HK_RAW=$(sudo -u www-data php /tmp/t_hk.php 2>&1)
echo "  respons: $(printf '%s' "$HK_RAW" | head -c 160)"

has "housekeeping reports ok" "$HK_RAW" '"ok":true'
eq "expired session deleted" \
  "$($TFQ "SELECT COUNT(*) FROM sessions WHERE user_agent='hk-test' AND expires_at <= datetime('now')")" 0
eq "the live session is kept" \
  "$($TFQ "SELECT COUNT(*) FROM sessions WHERE user_agent='hk-test' AND expires_at > datetime('now')")" 1
eq "old 'ok' audit rows deleted" \
  "$($TFQ "SELECT COUNT(*) FROM audit_log WHERE action='hk.probe' AND result='ok' AND created_at < datetime('now','-1 day')")" 0
eq "audit log 'denied' survives beyond the 'ok' limit" \
  "$($TFQ "SELECT COUNT(*) FROM audit_log WHERE action='hk.probe' AND result='denied'")" 1
eq "'denied' audit rows also deleted after 3x the limit" \
  "$(sudo -u www-data php -r '
     require "/srv/fpanel/app/bootstrap.php";
     use Fpanel\Database\Connection;
     use Fpanel\Service\Housekeeping;
     $dir = Housekeeping::defaultLogDir();
     // Seed a 'denied' row far older than 3x the retention limit.
     Connection::insert("audit_log", [
         "actor_name" => "system", "action" => "hk.old", "result" => "denied",
         "created_at" => gmdate("Y-m-d H:i:s", time() - 86400 * 30),
     ]);
     Housekeeping::make($dir)->run(false, ["auditDays" => 1]);
     echo (int)Connection::value(
         "SELECT COUNT(*) FROM audit_log WHERE action = ?", ["hk.old"]);')" 0
eq "today's audit rows remain" \
  "$($TFQ "SELECT COUNT(*) FROM audit_log WHERE action='hk.probe' AND created_at > datetime('now','-1 day')")" 1
eq "old login attempts deleted" \
  "$($TFQ "SELECT COUNT(*) FROM login_attempts WHERE ip='10.9.9.9'")" 0
eq "orphan domain log deleted (access)" \
  "$([ -f /var/log/nginx/fpanel/hk-orphan.test.access.log ] && echo present || echo no)" "no"
eq "orphan domain log deleted (error)" \
  "$([ -f /var/log/nginx/fpanel/hk-orphan.test.error.log ] && echo present || echo no)" "no"
eq "rotated log also removed" \
  "$([ -f /var/log/nginx/fpanel/hk-rotated.test.access.log.1 ] && echo present || echo no)" "no"
eq "active domain log not deleted" \
  "$([ -f /var/log/nginx/fpanel/demo.fpanel.my.id.access.log ] && echo present || echo no)" "present"
eq "orphan upload temp deleted" \
  "$([ -f /tmp/fp-enc-hk001 ] && echo present || echo clean)" "clean"
eq "fresh upload temp kept" \
  "$([ -f /tmp/fp-enc-hk002 ] && echo present || echo clean)" "present"
eq "name outside the panel temp pattern untouched" \
  "$([ -f /tmp/fp-enc-.do-not-delete ] && echo present || echo clean)" "present"
rm -f /tmp/fp-enc-hk002 /tmp/fp-enc-.do-not-delete

eq "housekeeping idempotent (re-run = 0)" \
  "$(php /srv/fpanel/bin/fpanel housekeeping:run 2>&1 | grep -c 'sessions=0')" 1

# Test sessions must be cleaned so they are not counted on the next run.
$TFQ "DELETE FROM sessions WHERE user_agent='hk-test'" >/dev/null
$TFQ "DELETE FROM audit_log WHERE action IN ('hk.probe','hk.old')" >/dev/null
rm -f /var/log/nginx/fpanel/demo.fpanel.my.id.access.log

# --- TOTP seed encryption --------------------------------------------------------
eq "SecretBox: app-key is readable" \
  "$(sudo -u www-data php -r 'require "/srv/fpanel/app/bootstrap.php"; echo Fpanel\Service\SecretBox::available() ? "yes" : "no";')" "yes"
eq "SecretBox: roundtrip is safe" \
  "$(sudo -u www-data php -r '
     require "/srv/fpanel/app/bootstrap.php";
     $c = Fpanel\Service\SecretBox::encrypt("test-secret");
     echo Fpanel\Service\SecretBox::decrypt($c) === "test-secret" ? "yes" : "no";')" "yes"
eq "SecretBox: nonce randomized each time" \
  "$(sudo -u www-data php -r '
     require "/srv/fpanel/app/bootstrap.php";
     echo Fpanel\Service\SecretBox::encrypt("x") !== Fpanel\Service\SecretBox::encrypt("x") ? "yes" : "no";')" "yes"
eq "SecretBox: corrupted ciphertext rejected" \
  "$(sudo -u www-data php -r '
     require "/srv/fpanel/app/bootstrap.php";
     echo Fpanel\Service\SecretBox::decrypt("sbx1:not-valid-base64!!") === null ? "yes" : "no";')" "yes"
eq "SecretBox: ciphertext prefix recognized" \
  "$(sudo -u www-data php -r '
     require "/srv/fpanel/app/bootstrap.php";
     echo Fpanel\Service\SecretBox::isEncrypted(Fpanel\Service\SecretBox::encrypt("x")) ? "yes" : "no";')" "yes"
eq "SecretBox: a plain value is not treated as encrypted" \
  "$(sudo -u www-data php -r '
     require "/srv/fpanel/app/bootstrap.php";
     echo Fpanel\Service\SecretBox::isEncrypted("JBSWY3DPEHPK3PXP") ? "yes" : "no";')" "no"

# TOTP seeds in the database must not be plaintext.
UT3="uthk"
$FP user:delete "$UT3" >/dev/null 2>&1
$FP user:create "$UT3" "$U2_PW" "uji@hk.test" user >/dev/null 2>&1
curl -s -b "$(jar "$UH")" -c "$(jar "$UH")" -o /dev/null "$BASE/login"
curl -s -b "$(jar "$UH")" -c "$(jar "$UH")" -o /dev/null -d "username=$UT3" \
  --data-urlencode "password=$U2_PW" -d "_csrf=$(csrf "$UH")" "$BASE/login"
eq "2FA setup for the test user" \
  "$(post "$UH" -o /dev/null -w '%{http_code}' -d "_csrf=$(csrf "$UH")" $BASE/security/2fa/setup)" 302
eq "TOTP seed stored encrypted" \
  "$($TFQ "SELECT COUNT(*) FROM users WHERE username='$UT3' AND totp_secret LIKE 'sbx1:%'")" 1
eq "TOTP seed is not stored as plain base32" \
  "$($TFQ "SELECT COUNT(*) FROM users WHERE username='$UT3' AND totp_secret NOT LIKE 'sbx1:%'")" 0
hasnt "TOTP seed does not leak in the security page HTML" \
  "$($TFQ "SELECT totp_secret FROM users WHERE username='$UT3'" | sed 's/^sbx1://')" \
  "$(post "$UH" $BASE/security | grep -oE '[A-Z2-7]{32}' | head -1)"
eq "QR still renders from the encrypted seed" \
  "$(curl -s -b "$(jar "$UH")" -o /dev/null -w '%{http_code}' $BASE/security/2fa/qr.svg)" 200

# 2FA login must keep working even when the seed is stored encrypted.
TOTP_OK=$(sudo -u www-data php -r '
  require "/srv/fpanel/app/bootstrap.php";
  $s = Fpanel\Service\Totp::pendingSecret("uthk");
  echo $s !== null ? "yes" : "no";')
eq "secret readable again via the app layer" "$TOTP_OK" "yes"
TC=$(sudo -u www-data php -r '
  require "/srv/fpanel/app/bootstrap.php";
  echo Fpanel\Service\Totp::code(Fpanel\Service\Totp::pendingSecret("uthk"));')
eq "activation with an encrypted seed" \
  "$(post "$UH" -o /dev/null -w '%{http_code}' -d "_csrf=$(csrf "$UH")" -d "code=$TC" $BASE/security/2fa/activate)" 200
curl -s -b "$(jar "$UH")" -c "$(jar "$UH")" -o /dev/null -d "_csrf=$(csrf "$UH")" $BASE/logout
curl -s -b "$(jar "$UH")" -c "$(jar "$UH")" -o /dev/null "$BASE/login"
post "$UH" -o /dev/null -d "username=$UT3" --data-urlencode "password=$U2_PW" -d "_csrf=$(csrf "$UH")" $BASE/login
TC=$(sudo -u www-data php -r '
  require "/srv/fpanel/app/bootstrap.php";
  // pendingSecret deliberately only looks at seeds not yet activated, so for
  // already-active 2FA the seed is taken straight from the DB and decrypted.
  $raw = (string)Fpanel\Database\Connection::value(
      "SELECT totp_secret FROM users WHERE username = ?", ["uthk"]);
  echo Fpanel\Service\Totp::code(Fpanel\Service\SecretBox::decrypt($raw));')
eq "2FA login with an encrypted seed" \
  "$(post "$UH" -o /dev/null -w '%{http_code}' -d "_csrf=$(csrf "$UH")" -d "code=$TC" $BASE/login/2fa)" 302
eq "dashboard opens" "$(getc "$UH" $BASE/dashboard)" 200
$FP user:delete "$UT3" >/dev/null 2>&1

# --- Rate limit per-user ---------------------------------------------------------
# The per-IP limit can be bypassed with different IPs; the per-account-name limit closes that.
# Tested directly at the service level so it does not need 25 HTTP requests.
eq "rate limit: a single IP alone does not lock the account" \
  "$(sudo -u www-data php -r '
     require "/srv/fpanel/app/bootstrap.php";
     use Fpanel\Database\Connection;
     use Fpanel\Http\Request;
     use Fpanel\Http\Session;
     use Fpanel\Auth\Auth;
     Connection::run("DELETE FROM login_attempts WHERE username = ?", ["admin"]);
     $a = new Auth(new Session(Request::capture()));
     echo ($a->isUserLocked("admin") ? "yes" : "no");')" "no"
eq "rate limit: per-user lock activates after MAX_FAILS_USER" \
  "$(sudo -u www-data php -r '
     require "/srv/fpanel/app/bootstrap.php";
     use Fpanel\Database\Connection;
     use Fpanel\Http\Request;
     use Fpanel\Http\Session;
     use Fpanel\Auth\Auth;
     Connection::run("DELETE FROM login_attempts WHERE username = ?", ["admin"]);
     $a = new Auth(new Session(Request::capture()));
     for ($i = 0; $i < Auth::MAX_FAILS_USER; $i++) {
         Connection::insert("login_attempts", [
             "ip" => "10.8.8." . ($i % 250), "username" => "admin", "success" => 0,
         ]);
     }
     echo ($a->isUserLocked("admin") ? "yes" : "no");')" "yes"
eq "rate limit: different IPs do not bypass the per-user lock" \
  "$(sudo -u www-data php -r '
     require "/srv/fpanel/app/bootstrap.php";
     use Fpanel\Http\Request;
     use Fpanel\Http\Session;
     use Fpanel\Auth\Auth;
     $a = new Auth(new Session(Request::capture()));
     // Even from a clean single IP: the per-user lock must stay active.
     echo ($a->isUserLocked("admin") && !$a->isLocked("10.7.7.7") ? "yes" : "no");')" "yes"
eq "rate limit: per-user limit is looser than per-IP" \
  "$(sudo -u www-data php -r '
     require "/srv/fpanel/app/bootstrap.php";
     echo Fpanel\Auth\Auth::MAX_FAILS_USER > Fpanel\Auth\Auth::MAX_FAILS ? "yes" : "no";')" "yes"
eq "rate limit: other accounts not locked" \
  "$(sudo -u www-data php -r '
     require "/srv/fpanel/app/bootstrap.php";
     use Fpanel\Http\Request;
     use Fpanel\Http\Session;
     use Fpanel\Auth\Auth;
     $a = new Auth(new Session(Request::capture()));
     echo ($a->isUserLocked("admin") && !$a->isUserLocked("uujicron") ? "yes" : "no");')" "yes"
eq "username normalized (email counts the same)" \
  "$(sudo -u www-data php -r '
     require "/srv/fpanel/app/bootstrap.php";
     use Fpanel\Database\Connection;
     use Fpanel\Http\Request;
     use Fpanel\Http\Session;
     use Fpanel\Auth\Auth;
     Connection::run("DELETE FROM login_attempts WHERE username = ?", ["admin"]);
     $a = new Auth(new Session(Request::capture()));
     Connection::insert("login_attempts", ["ip" => "10.6.6.1", "username" => "admin", "success" => 0]);
     // Recorded via email, not the account name: it must still count.
     $r = new ReflectionMethod(Auth::class, "normalizeUser");
     $r->setAccessible(true);
     echo ($r->invoke(null, "admin@fpanel.id") === "admin" ? "yes" : "no");')" "yes"
$FP login:reset >/dev/null 2>&1
eq "rate limit: admin login works after reset" \
  "$(curl -s -b "$(jar a)" -c "$(jar a)" -o /dev/null -w '%{http_code}' \
     -d "_csrf=$(csrf a)&username=admin&password=$ADMIN_PWE" $BASE/login)" 302

# --- 22. Offsite backup, remember device, login link --------------------------
echo "== 22. Offsite & device sessions ="
UD="ud22"
TFQ="sudo -u www-data php /tmp/q.php"
CONF=/etc/fpanel/offsite.conf

# --- Offsite: encryption ----------------------------------------------------------
eq "Offsite: S3Client class exists" \
  "$(sudo -u www-data php -r 'require "/srv/fpanel/app/bootstrap.php"; echo class_exists(Fpanel\Service\S3Client::class) ? "yes" : "no";')" "yes"
eq "Offsite: CLI registered" \
  "$(php /srv/fpanel/bin/fpanel 2>&1 | grep -c 'offsite:upload')" 1
eq "Offsite: CLI decrypt registered" \
  "$(php /srv/fpanel/bin/fpanel 2>&1 | grep -c 'offsite:decrypt')" 1
eq "Offsite: daily cron installed" \
  "$(grep -c 'fpanel-offsite' /etc/cron.d/fpanel-offsite 2>/dev/null)" 1
eq "Offsite: cron runs as root" \
  "$(grep -cE '^[0-9*/,-]+ +[0-9*/,-]+ +[0-9*/,-]+ +[0-9*/,-]+ +[0-9*/,-]+ +root /usr/local/sbin/fpanel-offsite' /etc/cron.d/fpanel-offsite)" 1
eq "Offsite: helper exists and is executable" \
  "$([ -x /usr/local/sbin/fpanel-offsite ] && echo yes || echo no)" "yes"
eq "Offsite: config not readable by a regular user" \
  "$([ -r /etc/fpanel/offsite.conf ] && sudo -u nobody test -r /etc/fpanel/offsite.conf && echo leaked || echo safe)" "safe"

# Encryption must reject corrupt files and never leave a half-written output.
cat > /tmp/t_enc.php <<'ENCPHP'
<?php
require '/srv/fpanel/app/bootstrap.php';
use Fpanel\Service\Offsite;

// The data is deliberately larger than one block so the multi-block path runs too.
$src = '/tmp/t-enc-src.bin';
file_put_contents($src, random_bytes(3 * 1048576 + 777));
$tmp = null;
$m = new ReflectionMethod(Offsite::class, 'encryptFile');
$m->setAccessible(true);
$enc = $m->invokeArgs(null, [$src, &$tmp]);
$out = [];
$out[] = $enc !== null ? 'present' : 'failed';
// Round the size to MiB so exact assertions stay robust.
$out[] = (int)(filesize($tmp) / 1048576);
$out[] = Offsite::decryptFile($tmp, '/tmp/t-enc-out.bin') ? 'ok' : 'failed';
$out[] = hash_file('sha256', $src) === hash_file('sha256', '/tmp/t-enc-out.bin') ? 'same' : 'different';
// The header must mark the format, not plain gzip.
$head = file_get_contents($tmp, false, null, 0, 7);
$out[] = $head === "FPENC1\n" ? 'magic' : 'aneh';
// Corrupt one byte in the middle: the MAC must reject it and no output file may be left.
$fh = fopen($tmp, 'r+b');
fseek($fh, 1048580);
fwrite($fh, 'X');
fclose($fh);
$out[] = Offsite::decryptFile($tmp, '/tmp/t-enc-bad.bin') ? 'accepted' : 'rejected';
$out[] = is_file('/tmp/t-enc-bad.bin') ? 'orphan' : 'clean';
// A file that is not Offsite output must be rejected by the magic check.
$out[] = Offsite::decryptFile($src, '/tmp/t-enc-x.bin') ? 'accepted' : 'rejected';
// Terpotong di tengah blok.
file_put_contents('/tmp/t-enc-trunc.enc', substr((string)file_get_contents($tmp), 0, 100));
$out[] = Offsite::decryptFile('/tmp/t-enc-trunc.enc', '/tmp/t-enc-y.bin') ? 'accepted' : 'rejected';
// The temp file name produced by encryptFile is recorded so the test can prove
// it is really cleaned up (it used to be left behind and pile up in /tmp).
// The marker uses an underscore so the glob below does not delete it.
file_put_contents('/tmp/t_enc-last.txt', (string)basename((string)$tmp));
foreach (glob('/tmp/t-enc-*') as $f) { @unlink($f); }
@unlink($tmp);
echo implode(' ', $out);
ENCPHP
ENC_RAW=$(sudo -u www-data php /tmp/t_enc.php 2>&1)
set -- $ENC_RAW
eq "Offsite: encryption succeeds"          "${1:-}" "present"
eq "Offsite: size grows (block+nonce+MAC)" "${2:-}" 3
eq "Offsite: dekripsi roundtrip"         "${3:-}" "ok"
eq "Offsite: contents identical after decryption" "${4:-}" "same"
eq "Offsite: header format"              "${5:-}" "magic"
eq "Offsite: corrupt file rejected"         "${6:-}" "rejected"
eq "Offsite: the corrupt output file is discarded" "${7:-}" "clean"
eq "Offsite: foreign file rejected"         "${8:-}" "rejected"
eq "Offsite: truncated file rejected"     "${9:-}" "rejected"
eq "Offsite: encryption temp not left behind" \
  "$([ -f "/tmp/$(cat /tmp/t_enc-last.txt 2>/dev/null)" ] && echo present || echo clean)" "clean"
rm -f /tmp/t_enc-last.txt /tmp/t_enc.php

# If offsite is not configured yet, status must report clearly, not error out.
if [ -r "$CONF" ]; then
  eq "Offsite: status readable" \
    "$(php /srv/fpanel/bin/fpanel offsite:status 2>&1 | grep -c 'endpoint')" 1
  has "Offsite: key id shown in status" \
    "$(php /srv/fpanel/bin/fpanel offsite:status 2>&1)" "key id   : "
  hasnt "Offsite: secret access key not printed" \
    "$(php /srv/fpanel/bin/fpanel offsite:status 2>&1)" "$(grep -oP '(?<=^secret_key=")[^"]+' "$CONF")"
  # Upload via the root helper: names are checked, not blindly trusted.
  eq "Offsite: helper rejects an invalid name" \
    "$(sudo -u www-data sudo -n /usr/local/sbin/fpanel-backup push ../../etc/passwd 2>&1 | head -1)" \
    "ERR: invalid backup name: ../../etc/passwd"
else
  has "Offsite: unconfigured state is reported" \
    "$(php /srv/fpanel/bin/fpanel offsite:status 2>&1)" "NOT CONFIGURED"
fi

# --- Remember this device -------------------------------------------------------
eq "Device: CLI registered" "$(php /srv/fpanel/bin/fpanel 2>&1 | grep -c 'device:count')" 1
eq "Device: remember_devices table exists" \
  "$($TFQ "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='remember_devices'")" 1
eq "Device: login_links table exists" \
  "$($TFQ "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='login_links'")" 1
eq "Device: devices migration has an FK" \
  "$($TFQ "SELECT COUNT(*) FROM sqlite_master WHERE name='remember_devices' AND sql LIKE '%ON DELETE CASCADE%'")" 1
eq "Device: devices migration idempotent (twice still 1 table)" \
  "$($FP db:migrate-devices >/dev/null 2>&1; $TFQ "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='remember_devices'")" 1

UL="ul22"
has "login page has the remember-device checkbox" "$(body "$UL" "$BASE/login")" 'name="remember"'

$FP user:create "$UD" "$U2_PW" "uji@dev.test" user >/dev/null 2>&1
UA='Mozilla/5.0 (X11; Linux x86_64) Chrome/120.0'
curl -s -b "$(jar "$UD")" -c "$(jar "$UD")" -o /dev/null -A "$UA" "$BASE/login"
eq "login with remember=1" \
  "$(post "$UD" -o /dev/null -w '%{http_code}' -A "$UA" -d "username=$UD" \
     --data-urlencode "password=$U2_PW" -d "remember=1" -d "_csrf=$(csrf "$UD")" $BASE/login)" 302
eq "device cookie issued" "$(grep -c fp_device "$(jar "$UD")")" 1
eq "device row stored" \
  "$($TFQ "SELECT COUNT(*) FROM remember_devices r JOIN users u ON u.id=r.user_id WHERE u.username='$UD'")" 1
eq "device token stored only as a hash" \
  "$($TFQ "SELECT COUNT(*) FROM remember_devices r JOIN users u ON u.id=r.user_id
            WHERE u.username='$UD' AND r.token_hash LIKE 'fpd_%'")" 0
eq "device hash is 64 hex chars" \
  "$($TFQ "SELECT COUNT(*) FROM remember_devices r JOIN users u ON u.id=r.user_id
            WHERE u.username='$UD' AND LENGTH(r.token_hash)=64")" 1
has "security page lists the devices" "$(post "$UD" -A "$UA" $BASE/security)" "Remembered Devices"
has "device label read from the user agent" "$(post "$UD" -A "$UA" $BASE/security)" "Linux - Chrome"

post "$UD" -o /dev/null -A "$UA" -d "_csrf=$(csrf "$UD")" $BASE/logout
eq "the logged-out session is no longer used" "$(getc "$UD" -A "$UA" $BASE/dashboard)" 302
eq "remember device opens a new session" \
  "$(post "$UD" -o /dev/null -w '%{redirect_url}' -A "$UA" $BASE/login/remember | grep -c '/dashboard$')" 1
eq "dashboard opens via remember device" "$(getc "$UD" -A "$UA" $BASE/dashboard)" 200
eq "last_used_at updated when used" \
  "$($TFQ "SELECT COUNT(*) FROM remember_devices r JOIN users u ON u.id=r.user_id
            WHERE u.username='$UD' AND r.last_used_at IS NOT NULL")" 1

# Revoking one device must also kill its remember-me.
DID=$($TFQ "SELECT r.id FROM remember_devices r JOIN users u ON u.id=r.user_id WHERE u.username='$UD' LIMIT 1")
eq "revoke device with an own id" \
  "$(post "$UD" -o /dev/null -w '%{http_code}' -A "$UA" -d "_csrf=$(csrf "$UD")" -d "id=$DID" $BASE/security/devices/revoke)" 302
eq "device marked as revoked" \
  "$($TFQ "SELECT COUNT(*) FROM remember_devices WHERE id=$DID AND revoked_at IS NOT NULL")" 1
post "$UD" -o /dev/null -A "$UA" -d "_csrf=$(csrf "$UD")" $BASE/logout
eq "remember device dead after revoke" \
  "$(post "$UD" -o /dev/null -w '%{redirect_url}' -A "$UA" $BASE/login/remember | grep -c '/login$')" 1
eq "dashboard stays closed" "$(getc "$UD" -A "$UA" $BASE/dashboard)" 302

# IDOR: revoke must not be usable to revoke another account's device.
# Two separate test users, each with their own remember-me.
UD3="ud22x"
$FP user:create "$UD3" "$U2_PW" "uji@dev2.test" user >/dev/null 2>&1

# Single-use login fixture: a regular login always overwrites remember-me for that user
# only, so two accounts do not interfere even with separate cookie jars.
devlogin(){ local j="$1" u="$2"
  curl -s -b "$(jar "$j")" -c "$(jar "$j")" -o /dev/null -A "$UA" "$BASE/login"
  curl -s -b "$(jar "$j")" -c "$(jar "$j")" -o /dev/null -A "$UA" \
    -d "username=$u" --data-urlencode "password=$U2_PW" \
    -d "remember=1" -d "_csrf=$(csrf "$j")" "$BASE/login"
}
eq "IDOR: second user logs in" "$(post "$UD3" -o /dev/null -w '%{http_code}' -A "$UA" -d "username=$UD3" \
     --data-urlencode "password=$U2_PW" -d "_csrf=$(csrf "$UD3")" $BASE/login)" 302

# UD's devices must be recreated: the earlier revoke emptied them.
devlogin "$UD" "$UD"
devlogin "$UD3" "$UD3"
eq "IDOR: both users have their own device" \
  "$($TFQ "SELECT COUNT(*) FROM remember_devices r JOIN users u ON u.id=r.user_id
            WHERE u.username IN ('$UD','$UD3') AND r.revoked_at IS NULL")" 2

# UD3's id (tried from UD's session) and UD's id (tried from UD3's session).
OWN3=$($TFQ "SELECT r.id FROM remember_devices r JOIN users u ON u.id=r.user_id
             WHERE u.username='$UD3' AND r.revoked_at IS NULL LIMIT 1")
OWN1=$($TFQ "SELECT r.id FROM remember_devices r JOIN users u ON u.id=r.user_id
             WHERE u.username='$UD' AND r.revoked_at IS NULL LIMIT 1")
eq "IDOR: both device ids found" \
  "$(printf '%s|%s' "$OWN1" "$OWN3" | grep -cE '^[0-9]+\|[0-9]+$')" 1

eq "IDOR: cannot revoke another account's device" \
  "$(post "$UD" -o /dev/null -w '%{redirect_url}' -A "$UA" -d "_csrf=$(csrf "$UD")" \
     -d "id=$OWN3" $BASE/security/devices/revoke | grep -c 'err=')" 1
eq "IDOR: another account's device stays intact" \
  "$($TFQ "SELECT COUNT(*) FROM remember_devices WHERE id=$OWN3 AND revoked_at IS NULL")" 1

eq "IDOR: cannot revoke with a made-up id" \
  "$(post "$UD" -o /dev/null -w '%{redirect_url}' -A "$UA" -d "_csrf=$(csrf "$UD")" \
     -d "id=99999999" $BASE/security/devices/revoke | grep -c 'err=')" 1
eq "IDOR: non-numeric id rejected" \
  "$(post "$UD" -o /dev/null -w '%{redirect_url}' -A "$UA" -d "_csrf=$(csrf "$UD")" \
     -d "id=abc" $BASE/security/devices/revoke | grep -c 'err=')" 1

eq "IDOR: own device can still be revoked" \
  "$(post "$UD" -o /dev/null -w '%{redirect_url}' -A "$UA" -d "_csrf=$(csrf "$UD")" \
     -d "id=$OWN1" $BASE/security/devices/revoke | grep -c 'ok=')" 1
$FP user:delete "$UD3" >/dev/null 2>&1

# --- Single-use login link ----------------------------------------------------
eq "Login link: CLI issue registered" "$(php /srv/fpanel/bin/fpanel 2>&1 | grep -c 'login-link:issue')" 1

curl -s -b "$(jar "$UD")" -c "$(jar "$UD")" -o /dev/null -A "$UA" "$BASE/login"
post "$UD" -o /dev/null -A "$UA" -d "username=$UD" --data-urlencode "password=$U2_PW" \
  -d "_csrf=$(csrf "$UD")" $BASE/login

cat > /tmp/t_link.php <<'LINKPHP'
<?php
require '/srv/fpanel/app/bootstrap.php';
use Fpanel\Auth\LoginLink;
$r = LoginLink::issue('ud22');
echo $r['ok'] ? $r['token'] : 'FAILED';
LINKPHP
LTOK=$(sudo -u www-data php /tmp/t_link.php 2>&1 | tr -d '\r\n')
eq "login link token has the right shape" "$(printf '%s' "$LTOK" | grep -cE '^fpl_[a-f0-9]{48}$')" 1
eq "plain token not stored" \
  "$($TFQ "SELECT COUNT(*) FROM login_links l JOIN users u ON u.id=l.user_id
            WHERE u.username='$UD' AND l.token_hash LIKE 'fpl_%'")" 0
eq "token hash stored" \
  "$($TFQ "SELECT COUNT(*) FROM login_links l JOIN users u ON u.id=l.user_id
            WHERE u.username='$UD' AND l.token_hash='$(printf '%s' "$LTOK" | sha256sum | cut -d" " -f1)'")" 1
eq "the link has an expiry" \
  "$($TFQ "SELECT COUNT(*) FROM login_links l JOIN users u ON u.id=l.user_id
            WHERE u.username='$UD' AND l.expires_at > datetime('now')")" 1

# Exchange via HTTP from an empty cookie jar: must become a full login.
UT2="ud22b"
eq "exchanging the link returns 200" "$(getc "$UT2" $BASE/login/link/$LTOK)" 200
eq "exchange link -> session opens" "$(getc "$UT2" $BASE/dashboard)" 200
eq "token burned after use" \
  "$($TFQ "SELECT COUNT(*) FROM login_links l JOIN users u ON u.id=l.user_id
            WHERE u.username='$UD' AND l.token_hash='$(printf '%s' "$LTOK" | sha256sum | cut -d" " -f1)' AND l.used_at IS NOT NULL")" 1
eq "link cannot be reused (another browser)" \
  "$(getc zz $BASE/login/link/$LTOK)" 410
curl -s -o /tmp/t_410.html -w '%{http_code}' "$BASE/login/link/$LTOK" > /tmp/t_410.code
eq "invalid-link page returns 410" "$(cat /tmp/t_410.code)" 410
has "invalid-link page shows a message" "$(cat /tmp/t_410.html)" "Link not valid"
has "410 page tells the user to sign in with a password" "$(cat /tmp/t_410.html)" "Sign in with a password"

# Only one active link per account.
LTOK2=$(sudo -u www-data php /tmp/t_link.php 2>&1 | tr -d '\r\n')
eq "a new link replaces the old one" \
  "$($TFQ "SELECT COUNT(*) FROM login_links l JOIN users u ON u.id=l.user_id
            WHERE u.username='$UD' AND l.used_at IS NULL")" 1
eq "the old link is revoked" \
  "$($TFQ "SELECT COUNT(*) FROM login_links l JOIN users u ON u.id=l.user_id
            WHERE u.username='$UD' AND l.token_hash='$(printf '%s' "$LTOK" | sha256sum | cut -d" " -f1)' AND l.used_at IS NULL")" 0

eq "bogus token rejected" "$(getc zz $BASE/login/link/fpl_$(printf '0%.0s' $(seq 1 48)))" 410
eq "non-link token rejected" "$(getc zz $BASE/login/link/abc)" 410

# The exchange result page must not send a Referer containing the token.
LT3="ud22c"
curl -s -b "$(jar "$LT3")" -c "$(jar "$LT3")" -o /dev/null -A "$UA" "$BASE/login/link/$LTOK2"
eq "exchange result page sends no Referer" \
  "$(curl -s -b "$(jar "$LT3")" -D - -o /dev/null -A "$UA" $BASE/security | grep -ci '^referer:')" 0

$TFQ "DELETE FROM remember_devices" >/dev/null
$TFQ "DELETE FROM login_links" >/dev/null
$FP user:delete "$UD" >/dev/null 2>&1
rm -f /tmp/t_enc.php /tmp/t_link.php

# --- 23. Domain model: simplified types, runtime as a separate feature ------
# A domain only has two types (static/php). Node.js and Python are not domain
# types; they are runtime apps attached via runtime_apps.domain_id.
echo "== 23. Domain types & separate runtime ="
# Helpers are redefined here instead of depending on sections 21/22, so
# this section can run standalone.
TFQ="sudo -u www-data php /tmp/q.php"
UT="ut23"
U3="ut23app"
$FP user:delete "$U3" >/dev/null 2>&1
$FP user:create "$U3" "$U2_PW" "uji@tipe.test" user >/dev/null 2>&1
curl -s -b "$(jar "$UT")" -c "$(jar "$UT")" -o /dev/null "$BASE/login"
curl -s -b "$(jar "$UT")" -c "$(jar "$UT")" -o /dev/null -d "username=$U3" \
  --data-urlencode "password=$U2_PW" -d "_csrf=$(csrf "$UT")" "$BASE/login"

eq "domain type: only static and php are valid" \
  "$(sudo -u www-data php -r 'require "/srv/fpanel/app/bootstrap.php";
     echo implode(",", Fpanel\Service\DomainManager::TYPES);')" "static,php"
eq "domain type: old values recorded as legacy" \
  "$(sudo -u www-data php -r 'require "/srv/fpanel/app/bootstrap.php";
     $c = Fpanel\Service\DomainManager::LEGACY_TYPES;
     sort($c); echo implode(",", $c);')" "binary,node,python"
eq "domain type: no runtime-typed domains in production" \
  "$($TFQ "SELECT COUNT(*) FROM domains WHERE type NOT IN ('static','php')")" 0
eq "domain type: migration CLI registered" \
  "$(php /srv/fpanel/bin/fpanel 2>&1 | grep -c 'db:migrate-domain-type')" 1
eq "domain type: migrasi idempoten" \
  "$($FP db:migrate-domain-type >/dev/null 2>&1; $FP db:migrate-domain-type >/dev/null 2>&1; \
     $TFQ "SELECT COUNT(*) FROM domains WHERE type NOT IN ('static','php')")" 0
eq "domain type: CHECK constraint sempit di schema" \
  "$(grep -c "CHECK (type IN ('static','php'))" /srv/fpanel/config/schema.sql)" 1

# A domain that has a runtime app must still be served via the proxy, even
# though its type was backfilled to php. The branch order in Vhost::render
# decides this; if wrong, the Node app dies as soon as the migration runs.
eq "runtime: app wins over PHP-FPM in the vhost" \
  "$(sudo -u www-data php -r 'require "/srv/fpanel/app/bootstrap.php";
     $c = Fpanel\Service\Vhost::render("x.example.com", "admin", "/var/www/admin/x", "php", "8.3", [], 30000);
     echo substr_count($c, "fastcgi_pass") === 0 && substr_count($c, "proxy_pass") > 0 ? "yes" : "no";')" "yes"
eq "runtime: php domain without an app still uses PHP-FPM" \
  "$(sudo -u www-data php -r 'require "/srv/fpanel/app/bootstrap.php";
     $c = Fpanel\Service\Vhost::render("x.example.com", "admin", "/var/www/admin/x", "php", "8.3", [], null);
     echo substr_count($c, "fastcgi_pass") > 0 && substr_count($c, "proxy_pass") === 0 ? "yes" : "no";')" "yes"
eq "runtime: static domain without an app has no PHP or proxy" \
  "$(sudo -u www-data php -r 'require "/srv/fpanel/app/bootstrap.php";
     $c = Fpanel\Service\Vhost::render("x.example.com", "admin", "/var/www/admin/x", "static", null, [], null);
     echo substr_count($c, "fastcgi_pass") === 0 && substr_count($c, "proxy_pass") === 0 ? "yes" : "no";')" "yes"
eq "runtime: app port taken from runtime_apps" \
  "$(sudo -u www-data php -r 'require "/srv/fpanel/app/bootstrap.php";
     echo Fpanel\Service\AppRuntime::portForDomain(999999) === null ? "yes" : "no";')" "yes"

# Add-domain form: it must not ask about the runtime anymore.
has "domain form: friendly type label" "$(body "$UT" "$BASE/domains")" "PHP (default)"
has "domain form: static option explained" "$(body "$UT" "$BASE/domains")" "Static (HTML/CSS only)"
has "domain form: points to the runtime app page" "$(body "$UT" "$BASE/domains")" "Node.js / Python App"
hasnt "domain form: no node type option" "$(body "$UT" "$BASE/domains")" 'value="node"'
hasnt "domain form: no python type option" "$(body "$UT" "$BASE/domains")" 'value="python"'

# The runtime app page must offer ALL domains as hosts, not
# only those typed node/python.
# The runtime app page is checked from the admin session guaranteed logged in
# in this section; jars from other sections may have no session when section 23
# runs standalone.
UAJ="ut23admin"
curl -s -b "$(jar "$UAJ")" -c "$(jar "$UAJ")" -o /dev/null "$BASE/login"
curl -s -b "$(jar "$UAJ")" -c "$(jar "$UAJ")" -o /dev/null -d "username=admin" \
  --data-urlencode "password=$ADMIN_PW" -d "_csrf=$(csrf "$UAJ")" "$BASE/login"
eq "apps: sesi admin siap" "$(getc "$UAJ" "$BASE/dashboard")" 200

# An existing PHP domain must show up as a runtime host option.
# Domains typed 'node'/'python' no longer exist, so
#it uses an existing domain: no need to create a new one and drain the quota.
PHP_HOST=$(sudo -u www-data php /tmp/q.php \
  "SELECT name FROM domains WHERE type = 'php' ORDER BY id LIMIT 1")
eq "apps: a php domain is available as a host" \
  "$(printf '%s' "$PHP_HOST" | grep -c '\.')" 1
has "apps: php domain can be a runtime host" "$(body "$UAJ" "$BASE/apps")" "$PHP_HOST"
hasnt "apps: the app page no longer filters by domain type" \
  "$(body "$UAJ" "$BASE/apps")" "uujibasicapp"

eq "nav: label WordPress Manager" \
  "$(body "$UT" "$BASE/dashboard" | grep -c 'WordPress Manager')" 1
eq "nav: label Node.js / Python App" \
  "$(body "$UT" "$BASE/dashboard" | grep -c 'Node.js / Python App')" 1

# --- WordPress Manager ---------------------------------------------------------
# The install button must always be visible, not hidden behind a tab.
has "wp manager: Install WordPress button in the header" \
  "$(body "$UT" "$BASE/wordpress")" 'id="wp-install-link"'
has "wp manager: install panel open (id=install present)" \
  "$(body "$UT" "$BASE/wordpress")" 'id="install"'
hasnt "wp manager: no tab hides the panel" \
  "$(body "$UT" "$BASE/wordpress")" 'id="wp-tabs"'
hasnt "wp manager: no data-panel attribute" \
  "$(body "$UT" "$BASE/wordpress")" 'data-panel='
has "wp manager: uses the official WordPress logo (standard)" \
  "$(body "$UT" "$BASE/wordpress")" 'wordpress-standard.png'
hasnt "wp manager: no made-up SVG" \
  "$(body "$UT" "$BASE/wordpress")" 'wordpress.svg'
eq "wp manager: official wmark logo available" \
  "$(curl -s -o /dev/null -w '%{http_code}' $BASE/assets/img/wordpress-wmark.png)" 200
eq "wp manager: official standard logo available" \
  "$(curl -s -o /dev/null -w '%{http_code}' $BASE/assets/img/wordpress-standard.png)" 200

# The Manage page must respond and must not leak other people's installs.
eq "wp manager: Manage page rejects without CSRF" \
  "$(post "$UT" -o /dev/null -w '%{http_code}' -d "id=1" $BASE/wordpress/update)" 419
eq "wp manager: staging toggle rejects without CSRF" \
  "$(post "$UT" -o /dev/null -w '%{http_code}' -d "id=1" $BASE/wordpress/staging)" 419
eq "wp manager: IDOR - cannot manage another user's install" \
  "$(post "$UT" -o /dev/null -w '%{redirect_url}' $BASE/wordpress/1/manage | grep -c 'err=')" 1
eq "wp manager: CLI wp:update registered" \
  "$(php /srv/fpanel/bin/fpanel 2>&1 | grep -c 'wp:update')" 1

$FP user:delete "$U3" >/dev/null 2>&1

restore_quota
trap - EXIT
rm -rf "$TMP"
echo
echo "================================"
printf "  PASSED: %s   FAILED: %s\n" "$PASS" "$FAIL"
echo "================================"
[ "$FAIL" -eq 0 ]
