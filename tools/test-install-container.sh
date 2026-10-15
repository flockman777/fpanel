#!/usr/bin/env bash
# =============================================================================
#  Uji installer FPanel di container Ubuntu 24.04 yang bersih.
#
#  Pemakaian:
#      ./tools/test-install-container.sh [sumber-repo]
#
#  Butuh Docker (atau Colima di macOS). Container dijalankan privileged dengan
#  systemd supaya nginx/php-fpm/MariaDB benar-benar dikelola systemd seperti di
#  server sungguhan. Panel dipetakan ke port 18090 di host.
#
#  Skrip ini TIDAK menyentuh server produksi dan tidak butuh domain/SSL.
# =============================================================================
set -euo pipefail

SRC="${1:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
NAME="${NAME:-fpanel-install-test}"
IMG="${IMG:-ubuntu:24.04}"
HOST_PORT="${HOST_PORT:-18090}"
DOMAIN="${DOMAIN:-panel.ujicoba.test}"

red()  { printf '\033[31m%s\033[0m\n' "$*"; }
grn()  { printf '\033[32m%s\033[0m\n' "$*"; }
step() { printf '\n\033[36m==> %s\033[0m\n' "$*"; }

[ -f "$SRC/app/bootstrap.php" ] || { red "bukan repositori FPanel: $SRC"; exit 1; }
command -v docker >/dev/null || { red "docker tidak ditemukan"; exit 1; }
docker info >/dev/null 2>&1 || { red "daemon Docker tidak jalan (colima start?)"; exit 1; }

step "Bersihkan container lama"
docker rm -f "$NAME" >/dev/null 2>&1 || true
grn "container bersih"

step "Siapkan image dengan systemd"
SYS_IMG="fpanel-systemd:24.04"
if docker image inspect "$SYS_IMG" >/dev/null 2>&1; then
  grn "image $SYS_IMG sudah ada"
else
  docker run -d --name "${NAME}-prep" "$IMG" sleep infinity >/dev/null
  docker exec "${NAME}-prep" bash -c \
    'apt-get update -qq && DEBIAN_FRONTEND=noninteractive apt-get install -y -qq systemd systemd-sysv dbus' >/dev/null 2>&1
  docker commit "${NAME}-prep" "$SYS_IMG" >/dev/null
  docker rm -f "${NAME}-prep" >/dev/null
  grn "image $SYS_IMG dibuat"
fi

step "Jalankan Ubuntu 24.04 + systemd"
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
  grn "systemd aktif"
else
  red "systemd tidak aktif"; exit 1
fi

step "Salin repositori ke /src (tanpa .git/var/node_modules)"
docker exec "$NAME" mkdir -p /src
tar --exclude='./.git' --exclude='./node_modules' --exclude='./var' \
    -cf - -C "$SRC" . | docker exec -i "$NAME" tar -xf - -C /src
grn "repositori tersalin: $(docker exec "$NAME" find /src -type f | wc -l | tr -d ' ') berkas"

step "Jalankan installer (--yes --domain $DOMAIN)"
if docker exec "$NAME" bash -c "cd /src && bash install/install.sh --yes --domain $DOMAIN" \
     > /tmp/fpanel-install.log 2>&1; then
  grn "installer selesai tanpa error"
else
  red "installer keluar dengan error — 30 baris terakhir:"
  tail -30 /tmp/fpanel-install.log | sed 's/^/     /'
fi

step "Verifikasi di dalam container"
cat > /tmp/fp-verify.sh <<'VERIFY'
#!/bin/bash
p=0; f=0
ok()  { p=$((p+1)); printf '  OK    %s\n' "$*"; }
bad() { f=$((f+1)); printf '  GAGAL %s\n' "$*"; }
t()   { if eval "$2" >/dev/null 2>&1; then ok "$1"; else bad "$1"; fi; }
e()   { local want="$1" desc="$2"; shift 2
        local got; got="$(eval "$@" 2>/dev/null | tr -d '\r\n')"
        if [ "$got" = "$want" ]; then ok "$desc"; else bad "$desc (harap '$want', dapat '$got')"; fi; }
h()   { local code; code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "$1" 2>/dev/null || echo 000)"
        if [ "$code" = "$2" ]; then ok "$3 (HTTP $code)"; else bad "$3 (HTTP $code, harap $2)"; fi; }

# --- layanan ---------------------------------------------------------------
t "nginx aktif"        'systemctl is-active --quiet nginx'
t "php8.3-fpm aktif"   'systemctl is-active --quiet php8.3-fpm'
t "mariadb aktif"      'systemctl is-active --quiet mariadb'
t "redis aktif"        'systemctl is-active --quiet redis-server'
t "socket fpm ada"     '[ -S /run/php/php8.3-fpm.sock ]'
t "konfig nginx valid" 'nginx -t 2>/dev/null'
t "vhost 443 belum ada" '! grep -q ssl_certificate /etc/nginx/sites-enabled/fpanel-panel'

# --- berkas aplikasi -------------------------------------------------------
t "/srv/fpanel ada"    '[ -f /srv/fpanel/app/bootstrap.php ]'
t "var milik www-data" '[ "$(stat -c %U /srv/fpanel/var)" = "www-data" ]'
t "kunci panel ada"    '[ -s /etc/fpanel/app-key ]'
e "32" "kunci 32 byte" 'php -r "echo strlen(base64_decode(file_get_contents(\"/etc/fpanel/app-key\")));"'
t "config/local.php"   '[ -f /srv/fpanel/config/local.php ]'
t "domain tersimpan"   'grep -q panel.ujicoba.test /srv/fpanel/config/local.php'
t "local.php 0640"     '[ "$(stat -c %a /srv/fpanel/config/local.php)" = "640" ]'

# --- database panel --------------------------------------------------------
t "sqlite panel ada"   '[ -f /srv/fpanel/var/db/fpanel.sqlite ]'
e "1" "satu user panel" 'php /srv/fpanel/bin/fpanel user:list | wc -l'
t "user admin ada"     'php /srv/fpanel/bin/fpanel user:list | grep -q " admin "'
t "skema punya tabel"  'sqlite3 /srv/fpanel/var/db/fpanel.sqlite ".tables" | grep -q users'

# --- helper & aturan root --------------------------------------------------
t "helper fpanel-app"     '[ -x /usr/local/sbin/fpanel-app ]'
t "helper fpanel-dbadmin" '[ -x /usr/local/sbin/fpanel-dbadmin ]'
t "helper fpanel-php"     '[ -x /usr/local/sbin/fpanel-php ]'
t "sudoers valid"         'visudo -c >/dev/null'
t "sudoers fpanel"        '[ -f /etc/sudoers.d/fpanel ]'
t "sudoers 0440"          '[ "$(stat -c %a /etc/sudoers.d/fpanel)" = "440" ]'
t "cron backup"           '[ -f /etc/cron.d/fpanel-backup ]'
t "logrotate"             '[ -f /etc/logrotate.d/fpanel-backup ]'
t "www-data boleh sudo"   'sudo -u www-data sudo -n /usr/local/sbin/fpanel-php ping'

# --- helper benar-benar bekerja -------------------------------------------
e "OK" "dbadmin ping"     'sudo -u www-data sudo -n /usr/local/sbin/fpanel-dbadmin ping'
e "OK" "php helper ping"  'sudo -u www-data sudo -n /usr/local/sbin/fpanel-php ping'
e "OK" "backup ping"      'sudo -u www-data sudo -n /usr/local/sbin/fpanel-backup ping'

# --- phpMyAdmin ------------------------------------------------------------
t "fpma-index.php"        '[ -f /usr/share/phpmyadmin/fpma-index.php ]'
t "phpmyadmin conf.d"     '[ -d /etc/phpmyadmin/conf.d ]'
t "conf.d milik www-data" '[ "$(stat -c %G /etc/phpmyadmin/conf.d)" = "www-data" ]'

# --- HTTP ------------------------------------------------------------------
h "http://127.0.0.1:8090/api/health" 200 "health 200"
t "health db=ok"          "curl -s --max-time 10 http://127.0.0.1:8090/api/health | grep -q '\"db\":\"ok\"'"
h "http://127.0.0.1:8090/login" 200 "halaman login 200"
h "http://127.0.0.1:8090/config/local.php" 403 "config/ ditolak"
h "http://127.0.0.1:8090/install/install.sh" 403 "install/ ditolak"
h "http://127.0.0.1:8090/var/db/fpanel.sqlite" 403 "var/ ditolak"
h "http://127.0.0.1:8090/bin/fpanel" 403 "bin/ ditolak"
h "http://127.0.0.1:8090/pma" 404 "pma tanpa token 404"

# --- idempotensi: installer dijalankan ulang -------------------------------
echo '  --- installer diulang ---'
if (cd /src && bash install/install.sh --yes --skip-packages --domain panel.ujicoba.test) >/tmp/fp-rerun.log 2>&1; then
  ok "installer bisa diulang"
else
  bad "installer gagal saat diulang"; tail -15 /tmp/fp-rerun.log | sed 's/^/       /'
fi
t "local.php selamat"      'grep -q panel.ujicoba.test /srv/fpanel/config/local.php'
e "1" "user tidak dobel"   'php /srv/fpanel/bin/fpanel user:list | wc -l'
t "panel masih hidup"      "curl -s --max-time 10 http://127.0.0.1:8090/api/health | grep -q '\"db\":\"ok\"'"

# --- WP-CLI & Node ---------------------------------------------------------
t "wp-cli terpasang"       '[ -x /usr/local/bin/wp ]'
t "node terpasang"         'command -v node >/dev/null'

echo
echo "  ---- $p lulus, $f gagal ----"
exit $([ "$f" -eq 0 ] && echo 0 || echo 1)
VERIFY

docker cp /tmp/fp-verify.sh "$NAME:/tmp/fp-verify.sh" >/dev/null
set +e
docker exec "$NAME" bash /tmp/fp-verify.sh
RC=$?
set -e

step "Cek dari host (port ${HOST_PORT})"
CODE="$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "http://127.0.0.1:${HOST_PORT}/api/health" 2>/dev/null || echo 000)"
if [ "$CODE" = "200" ]; then grn "panel terjangkau dari host (HTTP $CODE)"; else red "panel tidak terjangkau dari host (HTTP $CODE)"; fi

echo
printf '\033[36m===== RINGKASAN =====\033[0m\n'
printf '  hasil   : %s\n' "$([ "$RC" -eq 0 ] && echo 'SEMUA LULUS' || echo 'ADA YANG GAGAL')"
printf '  log     : /tmp/fpanel-install.log  (ulang: /tmp/fp-rerun.log)\n'
printf '  panel   : http://127.0.0.1:%s\n' "$HOST_PORT"
printf '  bersih  : docker rm -f %s\n' "$NAME"
exit "$RC"
