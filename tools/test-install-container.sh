#!/usr/bin/env bash
# =============================================================================
#  Uji installer FPanel di container Ubuntu 24.04 yang bersih.
#
#  Pemakaian:
#      ./tools/test-install-container.sh [sumber-repo]
#
#  Butuh Docker (atau Colima di macOS). Container dijalankan privileged dengan
#  systemd supaya nginx/php-fpm/MariaDB benar-benar dikelola systemd seperti
#  di server sungguhan. Panel dipetakan ke port 18090 di host.
#
#  Skrip ini TIDAK menyentuh server produksi dan tidak butuh domain/SSL.
# =============================================================================
set -euo pipefail

SRC="${1:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
NAME="${NAME:-fpanel-install-test}"
IMG="${IMG:-ubuntu:24.04}"
HOST_PORT="${HOST_PORT:-18090}"

red()  { printf '\033[31m%s\033[0m\n' "$*"; }
grn()  { printf '\033[32m%s\033[0m\n' "$*"; }
ylw()  { printf '\033[33m%s\033[0m\n' "$*"; }
step() { printf '\n\033[36m==> %s\033[0m\n' "$*"; }

[ -f "$SRC/app/bootstrap.php" ] || { red "bukan repositori FPanel: $SRC"; exit 1; }
command -v docker >/dev/null || { red "docker tidak ditemukan"; exit 1; }

PASS=0; FAIL=0
ok()   { PASS=$((PASS+1)); printf '  \033[32mOK\033[0m   %s\n' "$*"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mGAGAL\033[0m %s\n' "$*"; }

dex() { docker exec "$NAME" bash -lc "$1"; }
has() { # has <deskripsi> <perintah-di-container>
  if dex "$2" >/dev/null 2>&1; then ok "$1"; else bad "$1"; fi
}
eq() { # eq <deskripsi> <harapan> <perintah>
  local want="$1" got; got="$(dex "$3" 2>/dev/null | tr -d '\r\n' || true)"
  if [ "$got" = "$want" ]; then ok "$1"; else bad "$1 (harap '$want', dapat '$got')"; fi
}

step "Bersihkan container lama"
docker rm -f "$NAME" >/dev/null 2>&1 || true
ok "container bersih"

step "Jalankan Ubuntu 24.04 + systemd"
docker run -d --name "$NAME" \
  --privileged --cgroupns=host \
  -v /sys/fs/cgroup:/sys/fs/cgroup:rw \
  -p "${HOST_PORT}:8090" \
  "$IMG" /sbin/init >/dev/null
for _ in $(seq 1 60); do
  if dex 'systemctl is-system-running 2>/dev/null | grep -qE "running|degraded"'; then break; fi
  sleep 1
done
if dex 'systemctl is-system-running' >/dev/null 2>&1; then
  ok "systemd aktif ($(dex 'systemctl is-system-running' | tr -d '\r'))"
else
  bad "systemd tidak aktif"; exit 1
fi

step "Salin repositori ke /src (tanpa .git/var/node_modules)"
docker exec "$NAME" mkdir -p /src
tar --exclude='./.git' --exclude='./node_modules' --exclude='./var' \
    --exclude='./install/.git' -cf - -C "$SRC" . \
  | docker exec -i "$NAME" tar -xf - -C /src
ok "repositori tersalin ($(dex 'find /src -type f | wc -l' | tr -d '\r') berkas)"

step "Jalankan installer (--yes --domain panel.ujicoba.test)"
if docker exec "$NAME" bash -lc 'cd /src && bash install/install.sh --yes --domain panel.ujicoba.test' \
     > /tmp/fpanel-install.log 2>&1; then
  ok "installer selesai tanpa error"
else
  bad "installer keluar dengan error (lihat /tmp/fpanel-install.log)"
  tail -30 /tmp/fpanel-install.log | sed 's/^/     /'
fi

step "Periksa hasil"
eq "nginx terpasang"        "yes" 'command -v nginx >/dev/null && echo yes || echo no'
eq "php8.3-fpm ada"         "yes" '[ -S /run/php/php8.3-fpm.sock ] && echo yes || echo no'
has "MariaDB jalan"         'systemctl is-active --quiet mariadb'
has "Redis jalan"           'systemctl is-active --quiet redis-server'
has "nginx jalan"           'systemctl is-active --quiet nginx'
has "php8.3-fpm jalan"      'systemctl is-active --quiet php8.3-fpm'

has "/srv/fpanel terpasang" '[ -f /srv/fpanel/app/bootstrap.php ]'
has "config/local.php ada"  '[ -f /srv/fpanel/config/local.php ]'
has "var milik www-data"    '[ "$(stat -c %U /srv/fpanel/var)" = "www-data" ]'
has "kunci panel ada"       '[ -s /etc/fpanel/app-key ]'
eq  "kunci panel 32 byte"   "32" 'php -r "echo strlen(base64_decode(file_get_contents(\"/etc/fpanel/app-key\")));"'

eq  "database panel ada"    "1"  '[ -f /srv/fpanel/var/db/fpanel.sqlite ] && echo 1 || echo 0'
eq  "jumlah user panel"     "1"  'php /srv/fpanel/bin/fpanel user:list | wc -l | tr -d " "'
has "user admin dibuat"     'php /srv/fpanel/bin/fpanel user:list | grep -q admin'

has "helper fpanel-app"     '[ -x /usr/local/sbin/fpanel-app ]'
has "helper fpanel-dbadmin" '[ -x /usr/local/sbin/fpanel-dbadmin ]'
has "sudoers valid"         'visudo -c >/dev/null'
has "sudoers fpanel ada"    '[ -f /etc/sudoers.d/fpanel ]'
has "cron fpanel-backup"    '[ -f /etc/cron.d/fpanel-backup ]'
has "logrotate terpasang"   '[ -f /etc/logrotate.d/fpanel-backup ]'
has "phpmyadmin fpma"       '[ -f /usr/share/phpmyadmin/fpma-index.php ]'
has "phpmyadmin conf.d"     '[ -d /etc/phpmyadmin/conf.d ]'

eq  "situs nginx aktif"     "200" 'curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:8090/api/health'
has "health db=ok"          'curl -s http://127.0.0.1:8090/api/health | grep -q "db=ok"'
has "login tampil"          'curl -s http://127.0.0.1:8090/login | grep -qi "masuk\|login"'
eq  "path terlarang ditolak" "403" 'curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:8090/config/local.php'
eq  "install/ ditolak"      "403" 'curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:8090/install/install.sh'

has "migrasi ulang aman"    'sudo -u www-data php /srv/fpanel/bin/fpanel db:migrate'
has "installer bisa diulang" 'cd /src && bash install/install.sh --yes --skip-packages --domain panel.ujicoba.test'
has "local.php selamat"      'grep -q panel.ujicoba.test /srv/fpanel/config/local.php'
eq  "domain terbaca app"     "https://panel.ujicoba.test" \
    'sudo -u www-data php -r "require \"/srv/fpanel/app/bootstrap.php\"; echo Fpanel\Support\Config::get(\"app.base_url\",\"\");"'
eq  "user admin tidak dobel" "1" 'php /srv/fpanel/bin/fpanel user:list | wc -l | tr -d " "'

step "Pemeriksaan dari host (port ${HOST_PORT})"
CODE="$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:${HOST_PORT}/api/health" || echo 000)"
if [ "$CODE" = "200" ]; then ok "panel terjangkau dari host (HTTP $CODE)"; else bad "panel tidak terjangkau dari host (HTTP $CODE)"; fi

echo
printf '\033[36m===== RINGKASAN =====\033[0m\n'
printf '  lulus : %d\n  gagal : %d\n' "$PASS" "$FAIL"
printf '  log   : /tmp/fpanel-install.log\n'
printf '  panel : http://127.0.0.1:%s   (container: %s)\n' "$HOST_PORT" "$NAME"
echo
echo "Bersihkan: docker rm -f $NAME"
[ "$FAIL" -eq 0 ]
