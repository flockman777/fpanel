#!/usr/bin/env bash
# =============================================================================
#  Test the FPanel installer in a clean Ubuntu 24.04 container.
#
#  Usage:
#      ./tools/test-install-container.sh [source-repo]
#
#  Requires Docker (or Colima on macOS). The container runs privileged with
#  systemd so nginx/php-fpm/MariaDB are really managed by systemd like on a
#  real server. The panel is mapped to port 18090 on the host.
#
#  This script does NOT touch the production server and needs no domain/SSL.
# =============================================================================
set -euo pipefail

SRC="${1:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
NAME="${NAME:-fpanel-install-test}"
IMG="${IMG:-ubuntu:24.04}"
HOST_PORT="${HOST_PORT:-18090}"
DOMAIN="${DOMAIN:-panel.sandbox.test}"

red()  { printf '\033[31m%s\033[0m\n' "$*"; }
grn()  { printf '\033[32m%s\033[0m\n' "$*"; }
step() { printf '\n\033[36m==> %s\033[0m\n' "$*"; }

[ -f "$SRC/app/bootstrap.php" ] || { red "not an FPanel repository: $SRC"; exit 1; }
command -v docker >/dev/null || { red "docker not found"; exit 1; }
docker info >/dev/null 2>&1 || { red "the Docker daemon is not running (colima start?)"; exit 1; }

step "Clean up the old container"
docker rm -f "$NAME" >/dev/null 2>&1 || true
grn "container clean"

step "Prepare the image with systemd"
SYS_IMG="fpanel-systemd:24.04"
if docker image inspect "$SYS_IMG" >/dev/null 2>&1; then
  grn "image $SYS_IMG already exists"
else
  docker run -d --name "${NAME}-prep" "$IMG" sleep infinity >/dev/null
  docker exec "${NAME}-prep" bash -c \
    'apt-get update -qq && DEBIAN_FRONTEND=noninteractive apt-get install -y -qq systemd systemd-sysv dbus' >/dev/null 2>&1
  docker commit "${NAME}-prep" "$SYS_IMG" >/dev/null
  docker rm -f "${NAME}-prep" >/dev/null
  grn "image $SYS_IMG built"
fi

step "Run Ubuntu 24.04 + systemd"
docker rm -f "$NAME" >/dev/null 2>&1 || true
docker run -d --name "$NAME" \
  --privileged --cgroupns=host \
  -v /sys/fs/cgroup:/sys/fs/cgroup:rw \
  -p "${HOST_PORT}:8090" \
  "$SYS_IMG" /sbin/init >/dev/null
for _ in $(seq 1 60); do
  docker exec "$NAME" systemctl is-system-running >/dev/null 2>&1 && break
  sleep 1
done
if docker exec "$NAME" systemctl is-system-running 2>/dev/null | grep -qE 'running|degraded'; then
  grn "systemd is active"
else
  red "systemd is not active"; exit 1
fi

step "Copy the repository to /src (without .git/var/node_modules)"
docker exec "$NAME" mkdir -p /src
tar --exclude='./.git' --exclude='./node_modules' --exclude='./var' \
    -cf - -C "$SRC" . | docker exec -i "$NAME" tar -xf - -C /src
grn "repository copied: $(docker exec "$NAME" find /src -type f | wc -l | tr -d ' ') files"

step "Run the installer (--yes --domain $DOMAIN)"
if docker exec "$NAME" bash -c "cd /src && bash install/install.sh --yes --domain $DOMAIN" \
     > /tmp/fpanel-install.log 2>&1; then
  grn "installer finished without errors"
else
  red "installer exited with an error — last 30 lines:"
  tail -30 /tmp/fpanel-install.log | sed 's/^/     /'
fi

step "Verify inside the container"
cat > /tmp/fp-verify.sh <<'VERIFY'
#!/bin/bash
p=0; f=0
ok()  { p=$((p+1)); printf '  OK    %s\n' "$*"; }
bad() { f=$((f+1)); printf '  FAIL  %s\n' "$*"; }
t()   { if eval "$2" >/dev/null 2>&1; then ok "$1"; else bad "$1"; fi; }
e()   { local want="$1" desc="$2"; shift 2
        local got; got="$(eval "$@" 2>/dev/null | tr -d '\r\n')"
        if [ "$got" = "$want" ]; then ok "$desc"; else bad "$desc (wanted '$want', got '$got')"; fi; }
h()   { local code; code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "$1" 2>/dev/null || echo 000)"
        if [ "$code" = "$2" ]; then ok "$3 (HTTP $code)"; else bad "$3 (HTTP $code, wanted $2)"; fi; }

# --- services --------------------------------------------------------------
t "nginx active"        'systemctl is-active --quiet nginx'
t "php8.3-fpm active"   'systemctl is-active --quiet php8.3-fpm'
t "mariadb active"      'systemctl is-active --quiet mariadb'
t "redis active"        'systemctl is-active --quiet redis-server'
t "fpm socket exists"   '[ -S /run/php/php8.3-fpm.sock ]'
t "nginx config valid" 'nginx -t 2>/dev/null'
t "vhost 443 not there yet" '! grep -q ssl_certificate /etc/nginx/sites-enabled/fpanel-panel'

# --- application files -----------------------------------------------------
t "/srv/fpanel exists" '[ -f /srv/fpanel/app/bootstrap.php ]'
t "var owned by www-data" '[ "$(stat -c %U /srv/fpanel/var)" = "www-data" ]'
t "panel key exists"    '[ -s /etc/fpanel/app-key ]'
e "32" "key is 32 bytes" 'php -r "echo strlen(base64_decode(file_get_contents(\"/etc/fpanel/app-key\")));"'
t "config/local.php"   '[ -f /srv/fpanel/config/local.php ]'
t "domain saved"        'grep -q panel.sandbox.test /srv/fpanel/config/local.php'
t "local.php 0640"     '[ "$(stat -c %a /srv/fpanel/config/local.php)" = "640" ]'

# --- panel database --------------------------------------------------------
t "panel sqlite exists" '[ -f /srv/fpanel/var/db/fpanel.sqlite ]'
e "1" "one panel user"   'php /srv/fpanel/bin/fpanel user:list | wc -l'
t "admin user exists"   'php /srv/fpanel/bin/fpanel user:list | grep -q " admin "'
t "schema has tables"   'sqlite3 /srv/fpanel/var/db/fpanel.sqlite ".tables" | grep -q users'

# --- helpers & root rules --------------------------------------------------
t "helper fpanel-app"     '[ -x /usr/local/sbin/fpanel-app ]'
t "helper fpanel-dbadmin" '[ -x /usr/local/sbin/fpanel-dbadmin ]'
t "helper fpanel-php"     '[ -x /usr/local/sbin/fpanel-php ]'
t "sudoers valid"         'visudo -c >/dev/null'
t "sudoers fpanel"        '[ -f /etc/sudoers.d/fpanel ]'
t "sudoers 0440"          '[ "$(stat -c %a /etc/sudoers.d/fpanel)" = "440" ]'
t "cron backup"           '[ -f /etc/cron.d/fpanel-backup ]'
t "logrotate"             '[ -f /etc/logrotate.d/fpanel-backup ]'
t "www-data may use sudo" 'sudo -u www-data sudo -n /usr/local/sbin/fpanel-php ping'

# --- helpers actually work -------------------------------------------------
e "OK" "dbadmin ping"     'sudo -u www-data sudo -n /usr/local/sbin/fpanel-dbadmin ping'
e "OK" "php helper ping"  'sudo -u www-data sudo -n /usr/local/sbin/fpanel-php ping'
e "OK" "backup ping"      'sudo -u www-data sudo -n /usr/local/sbin/fpanel-backup ping'

# --- phpMyAdmin ------------------------------------------------------------
t "fpma-index.php"        '[ -f /usr/share/phpmyadmin/fpma-index.php ]'
t "phpmyadmin conf.d"     '[ -d /etc/phpmyadmin/conf.d ]'
t "conf.d owned by www-data" '[ "$(stat -c %G /etc/phpmyadmin/conf.d)" = "www-data" ]'

# --- HTTP ------------------------------------------------------------------
h "http://127.0.0.1:8090/api/health" 200 "health 200"
t "health db=ok"          "curl -s --max-time 10 http://127.0.0.1:8090/api/health | grep -q '\"db\":\"ok\"'"
h "http://127.0.0.1:8090/login" 200 "login page 200"
h "http://127.0.0.1:8090/config/local.php" 403 "config/ rejected"
h "http://127.0.0.1:8090/install/install.sh" 403 "install/ rejected"
h "http://127.0.0.1:8090/var/db/fpanel.sqlite" 403 "var/ rejected"
h "http://127.0.0.1:8090/bin/fpanel" 403 "bin/ rejected"
h "http://127.0.0.1:8090/pma" 404 "pma without token 404"

# --- idempotency: the installer is re-run ----------------------------------
echo '  --- installer re-run ---'
if (cd /src && bash install/install.sh --yes --skip-packages --domain panel.sandbox.test) >/tmp/fp-rerun.log 2>&1; then
  ok "the installer can be re-run"
else
  bad "installer failed on re-run"; tail -15 /tmp/fp-rerun.log | sed 's/^/       /'
fi
t "local.php survived"     'grep -q panel.sandbox.test /srv/fpanel/config/local.php'
e "1" "no duplicate user"  'php /srv/fpanel/bin/fpanel user:list | wc -l'
t "panel still alive"      "curl -s --max-time 10 http://127.0.0.1:8090/api/health | grep -q '\"db\":\"ok\"'"

# --- WP-CLI & Node ---------------------------------------------------------
t "wp-cli installed"       '[ -x /usr/local/bin/wp ]'
t "node installed"         'command -v node >/dev/null'

echo
echo "  ---- $p passed, $f failed ----"
exit $([ "$f" -eq 0 ] && echo 0 || echo 1)
VERIFY

docker cp /tmp/fp-verify.sh "$NAME:/tmp/fp-verify.sh" >/dev/null
set +e
docker exec "$NAME" bash /tmp/fp-verify.sh
RC=$?
set -e

step "Check from the host (port ${HOST_PORT})"
CODE="$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "http://127.0.0.1:${HOST_PORT}/api/health" 2>/dev/null || echo 000)"
if [ "$CODE" = "200" ]; then grn "panel reachable from the host (HTTP $CODE)"; else red "panel not reachable from the host (HTTP $CODE)"; fi

echo
printf '\033[36m===== SUMMARY =====\033[0m\n'
printf '  result  : %s\n' "$([ "$RC" -eq 0 ] && echo 'ALL PASSED' || echo 'SOME FAILED')"
printf '  log     : /tmp/fpanel-install.log  (re-run: /tmp/fp-rerun.log)\n'
printf '  panel   : http://127.0.0.1:%s\n' "$HOST_PORT"
printf '  cleanup : docker rm -f %s\n' "$NAME"
exit "$RC"
