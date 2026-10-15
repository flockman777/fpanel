#!/usr/bin/env bash
# =============================================================================
#  FPanel — skrip instalasi
#
#  Memasang panel kontrol hosting FPanel di server baru (Ubuntu 24.04 /
#  Debian turunannya). Aman dijalankan berulang: langkah yang mengubah berkas
#  konfigurasi akan menimpanya dengan versi dari repositori, sedangkan data
#  runtime (database, /var/www, sertifikat) tidak disentuh.
#
#  Contoh:
#     sudo ./install/install.sh
#     sudo ./install/install.sh --domain panel.contoh.tld --email admin@contoh.tld
#     sudo ./install/install.sh --yes --admin-pass 'Sandi!Kuat123'
#
#  Opsi lengkap: ./install/install.sh --help
# =============================================================================
set -euo pipefail

# ---------------------------------------------------------------- pengaturan
FPANEL_ROOT="${FPANEL_ROOT:-/srv/fpanel}"
PANEL_PORT="${PANEL_PORT:-8090}"
PANEL_DOMAIN="${PANEL_DOMAIN:-}"
PANEL_EMAIL="${PANEL_EMAIL:-}"
ADMIN_USER="${ADMIN_USER:-admin}"
ADMIN_EMAIL="${ADMIN_EMAIL:-}"
ADMIN_PASS="${ADMIN_PASS:-}"
PHP_VERSIONS="${PHP_VERSIONS:-8.3}"
PHP_DEFAULT="${PHP_DEFAULT:-8.3}"
WITH_NODE="${WITH_NODE:-1}"
WITH_PMA="${WITH_PMA:-1}"
ASSUME_YES="${ASSUME_YES:-0}"
SKIP_PACKAGES="${SKIP_PACKAGES:-0}"

# Akar repositori = satu direktori di atas install/
SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# ---------------------------------------------------------------- utilitas
c_reset=''; c_red=''; c_grn=''; c_ylw=''; c_blu=''
if [ -t 1 ]; then
  c_reset=$'\033[0m'; c_red=$'\033[31m'; c_grn=$'\033[32m'
  c_ylw=$'\033[33m'; c_blu=$'\033[36m'
fi

info() { printf '%s==>%s %s\n' "$c_blu" "$c_reset" "$*"; }
ok()   { printf '%s  OK%s %s\n' "$c_grn" "$c_reset" "$*"; }
warn() { printf '%s  !!%s %s\n' "$c_ylw" "$c_reset" "$*" >&2; }
die()  { printf '%s  XX%s %s\n' "$c_red" "$c_reset" "$*" >&2; exit 1; }

need_root() {
  [ "$(id -u)" -eq 0 ] || die "harus dijalankan sebagai root (sudo ./install/install.sh)"
}

confirm() {
  [ "$ASSUME_YES" = "1" ] && return 0
  local a
  read -r -p "$1 [y/N] " a </dev/tty || true
  case "${a:-}" in y|Y|ya|YA) return 0 ;; *) return 1 ;; esac
}

usage() {
  cat <<'EOF'
FPanel installer

Pemakaian: sudo ./install/install.sh [opsi]

Opsi:
  --domain <d>      Domain panel (opsional; dipakai untuk vhost 443 + SSL)
  --email <e>       Email untuk Let's Encrypt & akun admin
  --admin-user <u>  Username admin      (default: admin)
  --admin-email <e> Email admin         (default: sama dengan --email)
  --admin-pass <p>  Sandi admin         (default: dibuat acak)
  --php <ver>       Versi PHP default   (default: 8.3)
  --port <n>        Port panel          (default: 8090)
  --root <dir>      Lokasi pemasangan   (default: /srv/fpanel)
  --no-node         Jangan pasang Node.js
  --no-pma          Jangan pasang phpMyAdmin
  --skip-packages   Lewati pemasangan paket apt (server sudah siap)
  --yes             Non-interaktif (anggap semua jawaban "ya")
  -h, --help        Tampilkan bantuan ini

Variabel lingkungan yang sama juga dikenali (FPANEL_ROOT, PANEL_DOMAIN, dst).
EOF
}

# ---------------------------------------------------------------- argumen
while [ $# -gt 0 ]; do
  case "$1" in
    --domain)         PANEL_DOMAIN="${2:-}"; shift 2 ;;
    --email)          PANEL_EMAIL="${2:-}"; shift 2 ;;
    --admin-user)     ADMIN_USER="${2:-}"; shift 2 ;;
    --admin-email)    ADMIN_EMAIL="${2:-}"; shift 2 ;;
    --admin-pass)     ADMIN_PASS="${2:-}"; shift 2 ;;
    --php)            PHP_DEFAULT="${2:-}"; PHP_VERSIONS="${2:-}"; shift 2 ;;
    --port)           PANEL_PORT="${2:-}"; shift 2 ;;
    --root)           FPANEL_ROOT="${2:-}"; shift 2 ;;
    --no-node)        WITH_NODE=0; shift ;;
    --no-pma)         WITH_PMA=0; shift ;;
    --skip-packages)  SKIP_PACKAGES=1; shift ;;
    --yes|-y)         ASSUME_YES=1; shift ;;
    -h|--help)        usage; exit 0 ;;
    *)                die "opsi tidak dikenal: $1 (lihat --help)" ;;
  esac
done

# =============================================================================
#  0. Pemeriksaan awal
# =============================================================================
need_root
[ -f "$SRC/app/bootstrap.php" ] || die "tidak menemukan kode FPanel di $SRC (jalankan dari dalam repositori)"

if [ ! -f /etc/os-release ]; then
  die "tidak bisa mengenali sistem operasi (/etc/os-release tidak ada)"
fi
# shellcheck disable=SC1091
. /etc/os-release
case "${ID:-}${ID_LIKE:-}" in
  *ubuntu*|*debian*) : ;;
  *) warn "distro '${PRETTY_NAME:-?}' belum diuji; lanjutkan dengan risiko sendiri." ;;
esac

info "FPanel akan dipasang:"
printf '      sumber     : %s\n' "$SRC"
printf '      tujuan     : %s\n' "$FPANEL_ROOT"
printf '      port panel : %s\n' "$PANEL_PORT"
printf '      PHP        : %s\n' "$PHP_VERSIONS"
printf '      domain     : %s\n' "${PANEL_DOMAIN:-(belum ada — panel lewat IP:port)}"
echo
confirm "Lanjutkan?" || die "dibatalkan oleh pengguna"

# =============================================================================
#  1. Paket sistem
# =============================================================================
export DEBIAN_FRONTEND=noninteractive

if [ "$SKIP_PACKAGES" = "0" ]; then
  info "Memperbarui indeks paket"
  apt-get update -qq

  info "Memasang paket dasar"
  apt-get install -y -qq --no-install-recommends \
    nginx mariadb-server redis-server \
    certbot python3-certbot-nginx \
    curl ca-certificates gnupg openssl rsync unzip acl \
    build-essential python3-venv python3-pip \
    gawk debconf-utils ssl-cert software-properties-common lsb-release

  # Versi PHP di luar bawaan distro (mis. 7.4/8.4) hanya ada di PPA ondrej.
  for v in $PHP_VERSIONS; do
    if ! apt-cache show "php${v}-fpm" >/dev/null 2>&1; then
      info "PHP $v tidak ada di repo bawaan — menambah PPA ondrej/php"
      add-apt-repository -y ppa:ondrej/php >/dev/null 2>&1 \
        || warn "gagal menambah PPA ondrej/php; PHP $v mungkin tidak tersedia"
      apt-get update -qq || true
      break
    fi
  done

  info "Memasang PHP $PHP_VERSIONS"
  for v in $PHP_VERSIONS; do
    apt-get install -y -qq --no-install-recommends \
      "php${v}-cli" "php${v}-fpm" "php${v}-common" "php${v}-opcache" \
      "php${v}-mbstring" "php${v}-curl" "php${v}-sqlite3" "php${v}-zip" \
      "php${v}-gd" "php${v}-intl" "php${v}-bcmath" "php${v}-mysql" \
      "php${v}-xml" "php${v}-soap" \
      || warn "sebagian paket php${v} gagal dipasang (lanjut)"
    # Extension opsional (bisa dimatikan-nyalakan lewat panel).
    apt-get install -y -qq --no-install-recommends \
      "php${v}-imagick" "php${v}-redis" "php${v}-apcu" "php${v}-memcached" \
      2>/dev/null || warn "extension opsional php${v} dilewati"
  done

  if [ "$WITH_PMA" = "1" ]; then
    info "Memasang phpMyAdmin (tanpa konfigurasi webserver/DB otomatis)"
    echo "phpmyadmin phpmyadmin/dbconfig-install boolean false" | debconf-set-selections
    echo "phpmyadmin phpmyadmin/reconfigure-webserver multiselect" | debconf-set-selections
    apt-get install -y -qq --no-install-recommends phpmyadmin \
      || warn "phpMyAdmin gagal dipasang; fitur phpMyAdmin dinonaktifkan"
  fi

  info "Memasang WP-CLI"
  if [ ! -x /usr/local/bin/wp ]; then
    curl -fsSL -o /usr/local/bin/wp \
      https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
      && chmod 0755 /usr/local/bin/wp \
      && ok "WP-CLI dipasang" || warn "WP-CLI gagal diunduh (lanjut)"
  else
    ok "WP-CLI sudah ada"
  fi

  if [ "$WITH_NODE" = "1" ] && ! command -v node >/dev/null 2>&1; then
    info "Memasang Node.js 22 (NodeSource)"
    curl -fsSL https://deb.nodesource.com/setup_22.x | bash - >/dev/null 2>&1 \
      && apt-get install -y -qq nodejs \
      && ok "Node.js $(node -v) dipasang" || warn "Node.js gagal dipasang (lanjut)"
  fi
else
  info "Melewati pemasangan paket (--skip-packages)"
fi

# =============================================================================
#  2. Direktori infrastruktur
# =============================================================================
info "Menyiapkan direktori"

# Vhost + php.ini override + log per-domain ditulis oleh www-data, jadi
# direktori induknya harus bisa ditulis grup www-data. Mode setgid (2775)
# membuat berkas baru mewarisi grup www-data tanpa harus chgrp manual.
install -d -o root -g www-data -m 2775 /etc/nginx/sites-available/fpanel-domains
install -d -o root -g www-data -m 2775 /var/www
install -d -o www-data -g www-data -m 0755 /var/log/nginx/fpanel
install -d -o www-data -g www-data -m 0755 /var/www/acme/challenges

for v in $PHP_VERSIONS; do
  [ -d "/etc/php/$v/fpm" ] && install -d -o www-data -g www-data -m 0755 "/etc/php/$v/fpm/conf.d/domains"
done

# State helper root
install -d -o root    -g www-data -m 0750 /etc/fpanel
install -d -o root    -g root     -m 0700 /var/backups/fpanel
install -d -o root    -g root     -m 0755 /var/log/fpanel-cron
install -d -o root    -g root     -m 0755 /var/lib/fpanel
install -d -o root    -g root     -m 0755 /var/lib/fpanel/php-ext
install -d -o root    -g root     -m 0755 /var/lib/fpanel/cron
install -d -o root    -g root     -m 0700 /var/cache/fpanel 2>/dev/null || true
install -d -o root    -g root     -m 0700 /var/cache/fpanel/wp 2>/dev/null || true

ok "direktori siap"

# =============================================================================
#  3. Salin aplikasi ke /srv/fpanel
# =============================================================================
info "Menyalin aplikasi ke $FPANEL_ROOT"
install -d -o root -g www-data -m 0755 "$FPANEL_ROOT"

rsync -a --delete \
  --exclude 'var/' \
  --exclude 'node_modules/' \
  --exclude '.git/' \
  --exclude 'install/' \
  --exclude 'config/local.php' \
  --exclude '*.bak' --exclude '*.bak-*' \
  "$SRC/" "$FPANEL_ROOT/"

# Direktori runtime milik www-data
install -d -o www-data -g www-data -m 0750 "$FPANEL_ROOT/var"
for d in db log snapshots jobs sessions; do
  install -d -o www-data -g www-data -m 0750 "$FPANEL_ROOT/var/$d"
done

# Berkas PHP dibaca, tidak perlu ditulis.
chown -R root:www-data "$FPANEL_ROOT/app" "$FPANEL_ROOT/bin" \
  "$FPANEL_ROOT/config" "$FPANEL_ROOT/tools" "$FPANEL_ROOT/web"
chmod 0755 "$FPANEL_ROOT/bin/fpanel"
find "$FPANEL_ROOT/app" -type d -exec chmod 0755 {} +
find "$FPANEL_ROOT/app" -type f -exec chmod 0644 {} +
ok "aplikasi tersalin"

# =============================================================================
#  4. Helper privileged + sudoers + systemd + cron + logrotate
# =============================================================================
info "Memasang helper root"
install -d -o root -g root -m 0755 /usr/local/sbin
for f in "$SRC"/install/helpers/fpanel-*; do
  base="$(basename "$f")"
  sed "s#/srv/fpanel#$FPANEL_ROOT#g" "$f" > "/usr/local/sbin/$base"
  chown root:root "/usr/local/sbin/$base"
  chmod 0755 "/usr/local/sbin/$base"
done
ok "helper dipasang di /usr/local/sbin/fpanel-*"

info "Memasang aturan sudo"
install -d -o root -g root -m 0755 /etc/sudoers.d
for f in "$SRC"/install/sudoers/*; do
  base="$(basename "$f")"
  tmp="/tmp/fpanel-sudo-$base"
  sed "s#/srv/fpanel#$FPANEL_ROOT#g" "$f" > "$tmp"
  install -o root -g root -m 0440 "$tmp" "/etc/sudoers.d/$base"
  rm -f "$tmp"
done
if ! visudo -c >/dev/null 2>&1; then
  for f in /etc/sudoers.d/fpanel*; do rm -f "$f"; done
  die "aturan sudo tidak valid — semua aturan fpanel dibatalkan demi keamanan"
fi
ok "aturan sudo valid & terpasang"

info "Memasang unit systemd contoh"
install -d -o root -g root -m 0755 /etc/systemd/system
shopt -s nullglob
for f in "$SRC"/install/systemd/*.service; do
  base="$(basename "$f")"
  sed "s#/srv/fpanel#$FPANEL_ROOT#g" "$f" > "/etc/systemd/system/$base"
  chown root:root "/etc/systemd/system/$base"
  chmod 0644 "/etc/systemd/system/$base"
done
shopt -u nullglob
systemctl daemon-reload
ok "unit systemd dipasang"

info "Memasang cron"
for f in "$SRC"/install/cron/fpanel-*; do
  base="$(basename "$f")"
  sed "s#/srv/fpanel#$FPANEL_ROOT#g" "$f" > "/etc/cron.d/$base"
  chown root:root "/etc/cron.d/$base"
  chmod 0644 "/etc/cron.d/$base"
done
ok "cron dipasang di /etc/cron.d/fpanel-*"

[ -f "$SRC/install/logrotate/fpanel-backup" ] && {
  install -o root -g root -m 0644 "$SRC/install/logrotate/fpanel-backup" \
    /etc/logrotate.d/fpanel-backup
  ok "logrotate dipasang"
}

# =============================================================================
#  5. phpMyAdmin (front controller token)
# =============================================================================
if [ "$WITH_PMA" = "1" ] && [ -d /usr/share/phpmyadmin ]; then
  info "Memasang front controller phpMyAdmin"
  install -d -o root -g www-data -m 2770 /etc/phpmyadmin /etc/phpmyadmin/conf.d
  # Paket phpmyadmin bisa sudah membuat direktori ini dengan izin lain;
  # pastikan izinnya sesuai (www-data perlu menulis file token di sini).
  chown root:www-data /etc/phpmyadmin /etc/phpmyadmin/conf.d
  chmod 2770 /etc/phpmyadmin /etc/phpmyadmin/conf.d
  install -o root -g root -m 0644 \
    "$SRC/install/phpmyadmin/fpma-index.php" /usr/share/phpmyadmin/fpma-index.php
  ok "phpMyAdmin siap (/pma/<token>)"
else
  warn "phpMyAdmin dilewati — fitur /pma dinonaktifkan"
fi

# =============================================================================
#  6. Kunci enkripsi panel
# =============================================================================
if [ ! -s /etc/fpanel/app-key ]; then
  info "Membuat kunci enkripsi panel"
  openssl rand -base64 32 > /etc/fpanel/app-key
  chown root:www-data /etc/fpanel/app-key
  chmod 0640 /etc/fpanel/app-key
  ok "kunci dibuat: /etc/fpanel/app-key (JANGAN dihapus — rahasia akan hilang)"
else
  ok "kunci panel sudah ada, dibiarkan"
fi

# =============================================================================
#  7. Konfigurasi lokal (domain panel) — TIDAK ikut repositori
# =============================================================================
if [ -n "$PANEL_DOMAIN" ] || [ -n "$PANEL_EMAIL" ]; then
  info "Menulis config/local.php (domain & email panel)"
  {
    echo '<?php'
    echo '// Dibuat otomatis oleh install/install.sh — TIDAK ikut ke Git.'
    echo '// Nilai di sini menimpa config/fpanel.php untuk server ini saja.'
    echo 'return ['
    echo "    'app' => ["
    echo "        'base_url' => 'https://${PANEL_DOMAIN}',"
    echo '    ],'
    if [ -n "$PANEL_EMAIL" ]; then
      echo "    'letsencrypt' => ["
      echo "        'email' => '${PANEL_EMAIL}',"
      echo '    ],'
    fi
    echo '];'
  } > "$FPANEL_ROOT/config/local.php"
  chown root:www-data "$FPANEL_ROOT/config/local.php"
  chmod 0640 "$FPANEL_ROOT/config/local.php"
  ok "config/local.php ditulis"
else
  info "Tanpa --domain: panel memakai host permintaan (IP:${PANEL_PORT})"
fi

# =============================================================================
#  8. nginx: vhost panel
# =============================================================================
info "Memasang konfigurasi nginx panel"
sed "s#/srv/fpanel#$FPANEL_ROOT#g; s/8090/$PANEL_PORT/g" \
  "$SRC/install/nginx/fpanel-site.conf" > /etc/nginx/sites-available/fpanel
chown root:root /etc/nginx/sites-available/fpanel
chmod 0644 /etc/nginx/sites-available/fpanel

# Catch-all: tolak host tak dikenal di port 80. Disimpan tapi TIDAK
# di-enable otomatis supaya tidak bentrok dengan server block lain di :80.
sed "s#/srv/fpanel#$FPANEL_ROOT#g" "$SRC/install/nginx/fpanel-catchall.conf" \
  > /etc/nginx/sites-available/fpanel-catchall.conf
chown root:root /etc/nginx/sites-available/fpanel-catchall.conf

install -o root -g root -m 0644 "$SRC/install/nginx/fpanel-ssl.conf" \
  /etc/nginx/snippets/fpanel-ssl.conf 2>/dev/null || true

ln -sf /etc/nginx/sites-available/fpanel /etc/nginx/sites-enabled/fpanel

if nginx -t >/dev/null 2>&1; then
  systemctl reload nginx 2>/dev/null || systemctl restart nginx
  ok "nginx dimuat ulang"
else
  nginx -t || true
  die "konfigurasi nginx tidak valid"
fi

# =============================================================================
#  9. Database panel + akun admin
# =============================================================================
info "Menjalankan migrasi database panel"
sudo -u www-data php "$FPANEL_ROOT/bin/fpanel" db:migrate
ok "skema database siap"

if [ -z "$ADMIN_PASS" ]; then
  # Penuhi aturan: >=10 karakter, ada huruf besar, huruf kecil, angka.
  ADMIN_PASS="$(openssl rand -base64 24 | tr -dc 'A-Za-z0-9' | cut -c1-16)Aa9"
fi
[ -n "$ADMIN_EMAIL" ] || ADMIN_EMAIL="${PANEL_EMAIL:-admin@localhost}"
if [ "$ADMIN_EMAIL" = "admin@localhost" ]; then
  warn "email admin default 'admin@localhost' — ganti lewat dasbor nanti"
fi

info "Membuat akun admin '$ADMIN_USER'"
if sudo -u www-data php "$FPANEL_ROOT/bin/fpanel" user:list 2>/dev/null \
     | awk '{print $2}' | grep -qx "$ADMIN_USER"; then
  warn "user '$ADMIN_USER' sudah ada — sandi tidak diubah"
  ADMIN_PASS=""
else
  sudo -u www-data php "$FPANEL_ROOT/bin/fpanel" user:create \
    "$ADMIN_USER" "$ADMIN_PASS" "$ADMIN_EMAIL" admin
fi

# =============================================================================
# 10. Layanan
# =============================================================================
info "Mengaktifkan layanan"
systemctl enable --now nginx mariadb redis-server >/dev/null 2>&1 || true
for v in $PHP_VERSIONS; do
  systemctl enable --now "php${v}-fpm" >/dev/null 2>&1 || true
done
systemctl restart nginx >/dev/null 2>&1 || true
ok "layanan aktif"

# =============================================================================
# 11. Domain + SSL (opsional)
# =============================================================================
if [ -n "$PANEL_DOMAIN" ]; then
  info "Menyiapkan vhost domain $PANEL_DOMAIN"
  tmp="$(mktemp)"
  sed "s#/srv/fpanel#$FPANEL_ROOT#g; s#panel\.example\.tld#$PANEL_DOMAIN#g" \
    "$SRC/install/nginx/fpanel-panel.conf.example" > "$tmp"
  install -o root -g root -m 0644 "$tmp" /etc/nginx/sites-available/fpanel-panel.conf
  rm -f "$tmp"
  ln -sf /etc/nginx/sites-available/fpanel-panel.conf /etc/nginx/sites-enabled/fpanel-panel

  if [ -n "$PANEL_EMAIL" ]; then
    info "Meminta sertifikat Let's Encrypt untuk $PANEL_DOMAIN"
    install -d -o www-data -g www-data -m 0755 /var/www/acme/challenges
    nginx -t >/dev/null 2>&1 && systemctl reload nginx
    if certbot certonly --webroot -w /var/www/acme/challenges \
        -d "$PANEL_DOMAIN" -d "www.$PANEL_DOMAIN" \
        --non-interactive --agree-tos -m "$PANEL_EMAIL" --keep-until-expiring; then
      nginx -t >/dev/null 2>&1 && systemctl reload nginx
      ok "sertifikat SSL terpasang"
    else
      warn "gagal mendapatkan sertifikat — panel tetap bisa diakses lewat :$PANEL_PORT"
    fi
  else
    warn "tanpa --email, SSL dilewati (A record $PANEL_DOMAIN harus mengarah ke IP ini)"
  fi
  nginx -t >/dev/null 2>&1 && systemctl reload nginx || true
fi

# =============================================================================
# 12. Ringkasan
# =============================================================================
ip="$(hostname -I 2>/dev/null | awk '{print $1}')"
echo
echo "============================================================================="
printf '  %sFPanel selesai dipasang%s\n' "$c_grn" "$c_reset"
echo "============================================================================="
printf '  Panel        : http://%s:%s\n' "${ip:-<ip-server>}" "$PANEL_PORT"
[ -n "$PANEL_DOMAIN" ] && printf '  Domain       : https://%s\n' "$PANEL_DOMAIN"
printf '  Kode         : %s\n' "$FPANEL_ROOT"
printf '  CLI          : php %s/bin/fpanel help\n' "$FPANEL_ROOT"
echo
if [ -n "$ADMIN_PASS" ]; then
  printf '  %sAdmin%s        : %s\n' "$c_ylw" "$c_reset" "$ADMIN_USER"
  printf '  %sSandi%s        : %s\n' "$c_ylw" "$c_reset" "$ADMIN_PASS"
  echo "  (simpan sekarang — sandi tidak ditampilkan lagi)"
else
  printf '  Admin        : %s (sandi tidak diubah)\n' "$ADMIN_USER"
fi
echo
echo "  Pengingat:"
echo "   - PTR/rDNS di IP ini harus diatur agar email bisa terkirim."
echo "   - Port 80/443 & port 25 inbound bergantung pada penyedia VPS."
echo "   - Rahasia panel ada di /etc/fpanel/ — jangan di-commit ke Git."
echo "============================================================================="
