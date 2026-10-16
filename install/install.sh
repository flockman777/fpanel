#!/usr/bin/env bash
# =============================================================================
#  FPanel — installation script
#
#  Installs the FPanel hosting control panel on a fresh server (Ubuntu 24.04 /
#  its Debian derivatives). Safe to run repeatedly: steps that change config
#  files overwrite them with the version from the repository, while runtime
#  data (database, /var/www, certificates) is left untouched.
#
#  Examples:
#     sudo ./install/install.sh
#     sudo ./install/install.sh --domain panel.example.tld --email admin@example.tld
#     sudo ./install/install.sh --yes --admin-pass 'Strong!Pass123'
#
#  Full options: ./install/install.sh --help
# =============================================================================
set -euo pipefail

# ---------------------------------------------------------------- settings
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
CERT_OK=0

# Repository root = one directory above install/
SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# ---------------------------------------------------------------- utilities
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
  [ "$(id -u)" -eq 0 ] || die "must be run as root (sudo ./install/install.sh)"
}

confirm() {
  [ "$ASSUME_YES" = "1" ] && return 0
  local a
  read -r -p "$1 [y/N] " a </dev/tty || true
  case "${a:-}" in y|Y|yes|YES) return 0 ;; *) return 1 ;; esac
}

usage() {
  cat <<'EOF'
FPanel installer

Usage: sudo ./install/install.sh [options]

Options:
  --domain <d>      Panel domain (optional; used for the 443 vhost + SSL)
  --email <e>       Email for Let's Encrypt & the admin account
  --admin-user <u>  Username admin      (default: admin)
  --admin-email <e> Admin email         (default: same as --email)
  --admin-pass <p>  Admin password      (default: randomly generated)
  --php <ver>       Default PHP version (default: 8.3)
  --port <n>        Port panel          (default: 8090)
  --root <dir>      Install location    (default: /srv/fpanel)
  --no-node         Do not install Node.js
  --no-pma          Do not install phpMyAdmin
  --skip-packages   Skip the apt package installation (server already prepared)
  --yes             Non-interactive (assume every answer is "yes")
  -h, --help        Show this help

The same environment variables are also recognized (FPANEL_ROOT, PANEL_DOMAIN, etc).
EOF
}

# ---------------------------------------------------------------- arguments
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
    *)                die "unknown option: $1 (see --help)" ;;
  esac
done

# =============================================================================
#  0. Preflight checks
# =============================================================================
need_root
[ -f "$SRC/app/bootstrap.php" ] || die "no FPanel code found in $SRC (run it from inside the repository)"

if [ ! -f /etc/os-release ]; then
  die "cannot detect the operating system (/etc/os-release is missing)"
fi
# shellcheck disable=SC1091
. /etc/os-release
case "${ID:-}${ID_LIKE:-}" in
  *ubuntu*|*debian*) : ;;
  *) warn "distro '${PRETTY_NAME:-?}' has not been tested; continue at your own risk." ;;
esac

info "FPanel will be installed:"
printf '      source     : %s\n' "$SRC"
printf '      target     : %s\n' "$FPANEL_ROOT"
printf '      panel port : %s\n' "$PANEL_PORT"
printf '      PHP        : %s\n' "$PHP_VERSIONS"
printf '      domain     : %s\n' "${PANEL_DOMAIN:-(none — panel via IP:port)}"
echo
confirm "Continue?" || die "cancelled by the user"

# =============================================================================
#  1. System packages
# =============================================================================
export DEBIAN_FRONTEND=noninteractive

if [ "$SKIP_PACKAGES" = "0" ]; then
  info "Updating the package index"
  apt-get update -qq

  info "Installing base packages"
  apt-get install -y -qq --no-install-recommends \
    nginx mariadb-server redis-server \
    certbot python3-certbot-nginx \
    curl ca-certificates gnupg openssl rsync unzip acl sudo sqlite3 \
    build-essential python3-venv python3-pip \
    gawk debconf-utils ssl-cert software-properties-common lsb-release

  # PHP versions other than the distro default (e.g. 7.4/8.4) only exist in the ondrej PPA.
  for v in $PHP_VERSIONS; do
    if ! apt-cache show "php${v}-fpm" >/dev/null 2>&1; then
      info "PHP $v is not in the default repo — adding the ondrej/php PPA"
      add-apt-repository -y ppa:ondrej/php >/dev/null 2>&1 \
        || warn "failed to add the ondrej/php PPA; PHP $v may not be available"
      apt-get update -qq || true
      break
    fi
  done

  info "Installing PHP $PHP_VERSIONS"
  for v in $PHP_VERSIONS; do
    apt-get install -y -qq --no-install-recommends \
      "php${v}-cli" "php${v}-fpm" "php${v}-common" "php${v}-opcache" \
      "php${v}-mbstring" "php${v}-curl" "php${v}-sqlite3" "php${v}-zip" \
      "php${v}-gd" "php${v}-intl" "php${v}-bcmath" "php${v}-mysql" \
      "php${v}-xml" "php${v}-soap" \
      || warn "some php${v} packages failed to install (continuing)"
    # Optional extensions (can be switched on/off from the panel).
    apt-get install -y -qq --no-install-recommends \
      "php${v}-imagick" "php${v}-redis" "php${v}-apcu" "php${v}-memcached" \
      2>/dev/null || warn "optional php${v} extensions skipped"
  done

  if [ "$WITH_PMA" = "1" ]; then
    info "Installing phpMyAdmin (without automatic webserver/DB configuration)"
    echo "phpmyadmin phpmyadmin/dbconfig-install boolean false" | debconf-set-selections
    echo "phpmyadmin phpmyadmin/reconfigure-webserver multiselect" | debconf-set-selections
    apt-get install -y -qq --no-install-recommends phpmyadmin \
      || warn "phpMyAdmin failed to install; the phpMyAdmin feature is disabled"
  fi

  info "Installing WP-CLI"
  if [ ! -x /usr/local/bin/wp ]; then
    curl -fsSL -o /usr/local/bin/wp \
      https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
      && chmod 0755 /usr/local/bin/wp \
      && ok "WP-CLI installed" || warn "WP-CLI failed to download (continuing)"
  else
    ok "WP-CLI already exists"
  fi

  if [ "$WITH_NODE" = "1" ] && ! command -v node >/dev/null 2>&1; then
    info "Installing Node.js 22 (NodeSource)"
    curl -fsSL https://deb.nodesource.com/setup_22.x | bash - >/dev/null 2>&1 \
      && apt-get install -y -qq nodejs \
      && ok "Node.js $(node -v) installed" || warn "Node.js failed to install (continuing)"
  fi
else
  info "Skipping the package installation (--skip-packages)"
fi

# Required tools. Checked after packages are installed so that failures like
# "visudo is missing" show up here, not halfway through.
MISSING=""
for bin in nginx php rsync sudo visudo sqlite3 unzip curl; do
  command -v "$bin" >/dev/null 2>&1 || MISSING="$MISSING $bin"
done
for v in $PHP_VERSIONS; do
  [ -d "/etc/php/$v/fpm" ] || MISSING="$MISSING php${v}-fpm"
done
if [ -n "$MISSING" ]; then
  die "required tools are missing:$MISSING — run without --skip-packages or install them manually first"
fi
ok "all required tools are present"

# =============================================================================
#  2. Base services
# =============================================================================
# Started BEFORE the database migration: the fpanel-dbadmin helper needs
# MariaDB up, and service failures are better noticed early than at the end.
info "Starting the base services"
systemctl enable --now nginx mariadb redis-server >/dev/null 2>&1 || true
for v in $PHP_VERSIONS; do
  systemctl enable --now "php${v}-fpm" >/dev/null 2>&1 || true
done

DOWN=""
for s in nginx mariadb redis-server; do
  systemctl is-active --quiet "$s" || DOWN="$DOWN $s"
done
for v in $PHP_VERSIONS; do
  systemctl is-active --quiet "php${v}-fpm" || DOWN="$DOWN php${v}-fpm"
done
if [ -n "$DOWN" ]; then
  warn "services are not active yet:$DOWN"
  for s in $DOWN; do
    systemctl status "$s" --no-pager -l 2>&1 | tail -3 | sed 's/^/     /'
  done
else
  ok "base services are active"
fi

# =============================================================================
#  3. Infrastructure directories
# =============================================================================
info "Preparing the directories"

# Per-domain vhost + php.ini override + logs are written by www-data, so
# their parent directory must be group-writable by www-data. Setgid mode (2775)
# makes new files inherit the www-data group without a manual chgrp.
install -d -o root -g www-data -m 2775 /etc/nginx/sites-available/fpanel-domains
install -d -o root -g www-data -m 2775 /var/www
install -d -o www-data -g www-data -m 0755 /var/log/nginx/fpanel
install -d -o www-data -g www-data -m 0755 /var/www/acme/challenges

for v in $PHP_VERSIONS; do
  [ -d "/etc/php/$v/fpm" ] && install -d -o www-data -g www-data -m 0755 "/etc/php/$v/fpm/conf.d/domains"
done

# Root helper state
install -d -o root    -g www-data -m 0750 /etc/fpanel
install -d -o root    -g root     -m 0700 /var/backups/fpanel
install -d -o root    -g root     -m 0755 /var/log/fpanel-cron
install -d -o root    -g root     -m 0755 /var/lib/fpanel
install -d -o root    -g root     -m 0755 /var/lib/fpanel/php-ext
install -d -o root    -g root     -m 0755 /var/lib/fpanel/cron
install -d -o root    -g root     -m 0700 /var/cache/fpanel 2>/dev/null || true
install -d -o root    -g root     -m 0700 /var/cache/fpanel/wp 2>/dev/null || true

ok "directories ready"

# =============================================================================
#  4. Copy the application to /srv/fpanel
# =============================================================================
info "Copying the application to $FPANEL_ROOT"
install -d -o root -g www-data -m 0755 "$FPANEL_ROOT"

rsync -a --delete \
  --exclude 'var/' \
  --exclude 'node_modules/' \
  --exclude '.git/' \
  --exclude 'install/' \
  --exclude 'config/local.php' \
  --exclude '*.bak' --exclude '*.bak-*' \
  "$SRC/" "$FPANEL_ROOT/"

# Runtime directories owned by www-data
install -d -o www-data -g www-data -m 0750 "$FPANEL_ROOT/var"
for d in db log snapshots jobs sessions; do
  install -d -o www-data -g www-data -m 0750 "$FPANEL_ROOT/var/$d"
done

# PHP files are only read, they do not need to be writable.
chown -R root:www-data "$FPANEL_ROOT/app" "$FPANEL_ROOT/bin" \
  "$FPANEL_ROOT/config" "$FPANEL_ROOT/tools" "$FPANEL_ROOT/web"
chmod 0755 "$FPANEL_ROOT/bin/fpanel"
find "$FPANEL_ROOT/app" -type d -exec chmod 0755 {} +
find "$FPANEL_ROOT/app" -type f -exec chmod 0644 {} +
ok "application copied"

# =============================================================================
#  5. Privileged helpers + sudoers + systemd + cron + logrotate
# =============================================================================
info "Installing the root helpers"
install -d -o root -g root -m 0755 /usr/local/sbin
for f in "$SRC"/install/helpers/fpanel-*; do
  base="$(basename "$f")"
  sed "s#/srv/fpanel#$FPANEL_ROOT#g" "$f" > "/usr/local/sbin/$base"
  chown root:root "/usr/local/sbin/$base"
  chmod 0755 "/usr/local/sbin/$base"
done
ok "helpers installed in /usr/local/sbin/fpanel-*"

info "Installing the sudo rules"
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
  die "invalid sudo rules — every fpanel rule was discarded for safety"
fi
ok "sudo rules valid & installed"

info "Installing the example systemd units"
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
ok "systemd units installed"

info "Installing cron jobs"
for f in "$SRC"/install/cron/fpanel-*; do
  base="$(basename "$f")"
  sed "s#/srv/fpanel#$FPANEL_ROOT#g" "$f" > "/etc/cron.d/$base"
  chown root:root "/etc/cron.d/$base"
  chmod 0644 "/etc/cron.d/$base"
done
ok "cron installed in /etc/cron.d/fpanel-*"

[ -f "$SRC/install/logrotate/fpanel-backup" ] && {
  install -o root -g root -m 0644 "$SRC/install/logrotate/fpanel-backup" \
    /etc/logrotate.d/fpanel-backup
  ok "logrotate installed"
}

# =============================================================================
#  6. phpMyAdmin (token front controller)
# =============================================================================
if [ "$WITH_PMA" = "1" ] && [ -d /usr/share/phpmyadmin ]; then
  info "Installing the phpMyAdmin front controller"
  install -d -o root -g www-data -m 2770 /etc/phpmyadmin /etc/phpmyadmin/conf.d
  # The phpmyadmin package may already have created this directory with other
  # permissions; make sure they match (www-data must write the token file here).
  chown root:www-data /etc/phpmyadmin /etc/phpmyadmin/conf.d
  chmod 2770 /etc/phpmyadmin /etc/phpmyadmin/conf.d
  install -o root -g root -m 0644 \
    "$SRC/install/phpmyadmin/fpma-index.php" /usr/share/phpmyadmin/fpma-index.php
  ok "phpMyAdmin ready (/pma/<token>)"
else
  warn "phpMyAdmin skipped — the /pma feature is disabled"
fi

# =============================================================================
#  7. Panel encryption key
# =============================================================================
if [ ! -s /etc/fpanel/app-key ]; then
  info "Generating the panel encryption key"
  openssl rand -base64 32 > /etc/fpanel/app-key
  chown root:www-data /etc/fpanel/app-key
  chmod 0640 /etc/fpanel/app-key
  ok "key created: /etc/fpanel/app-key (do NOT delete it — secrets would be lost)"
else
  ok "the panel key already exists, left as-is"
fi

# =============================================================================
#  8. Local config (panel domain) — NOT part of the repository
# =============================================================================
if [ -n "$PANEL_DOMAIN" ] || [ -n "$PANEL_EMAIL" ]; then
  info "Writing config/local.php (panel domain & email)"
  {
    echo '<?php'
    echo '// Generated automatically by install/install.sh — NOT tracked by Git.'
    echo '// The values here override config/fpanel.php for this server only.'
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
  ok "config/local.php written"
else
  info "Without --domain: the panel uses the request host (IP:${PANEL_PORT})"
fi

# =============================================================================
#  9. nginx: panel vhost
# =============================================================================
info "Installing the panel nginx configuration"
sed "s#/srv/fpanel#$FPANEL_ROOT#g; s/8090/$PANEL_PORT/g" \
  "$SRC/install/nginx/fpanel-site.conf" > /etc/nginx/sites-available/fpanel
chown root:root /etc/nginx/sites-available/fpanel
chmod 0644 /etc/nginx/sites-available/fpanel

# Catch-all: reject unknown hosts on port 80. Installed but NOT
# enabled automatically so it does not clash with other server blocks on :80.
sed "s#/srv/fpanel#$FPANEL_ROOT#g" "$SRC/install/nginx/fpanel-catchall.conf" \
  > /etc/nginx/sites-available/fpanel-catchall.conf
chown root:root /etc/nginx/sites-available/fpanel-catchall.conf

install -o root -g root -m 0644 "$SRC/install/nginx/fpanel-ssl.conf" \
  /etc/nginx/snippets/fpanel-ssl.conf 2>/dev/null || true

ln -sf /etc/nginx/sites-available/fpanel /etc/nginx/sites-enabled/fpanel

if nginx -t >/dev/null 2>&1; then
  systemctl reload nginx 2>/dev/null || systemctl restart nginx
  ok "nginx reloaded"
else
  nginx -t || true
  die "invalid nginx configuration"
fi

# =============================================================================
# 10. Panel database + admin account
# =============================================================================
info "Running the panel database migration"
sudo -u www-data php "$FPANEL_ROOT/bin/fpanel" db:migrate
ok "database schema ready"

if [ -z "$ADMIN_PASS" ]; then
  # Satisfy the rules: >=10 characters, with uppercase, lowercase and digits.
  ADMIN_PASS="$(openssl rand -base64 24 | tr -dc 'A-Za-z0-9' | cut -c1-16)Aa9"
fi
[ -n "$ADMIN_EMAIL" ] || ADMIN_EMAIL="${PANEL_EMAIL:-}"
if [ -z "$ADMIN_EMAIL" ]; then
  # The panel rejects emails without a dot (e.g. admin@localhost), so don't
  # use that as a default. The panel domain is used when there is one.
  if [ -n "$PANEL_DOMAIN" ]; then
    ADMIN_EMAIL="admin@$PANEL_DOMAIN"
  else
    ADMIN_EMAIL="admin@example.tld"
    warn "default admin email 'admin@example.tld' — change it through the dashboard after signing in"
  fi
fi

info "Creating the admin account '$ADMIN_USER'"
if sudo -u www-data php "$FPANEL_ROOT/bin/fpanel" user:list 2>/dev/null \
     | awk '{print $2}' | grep -qx "$ADMIN_USER"; then
  warn "user '$ADMIN_USER' already exists — the password was not changed"
  ADMIN_PASS=""
else
  sudo -u www-data php "$FPANEL_ROOT/bin/fpanel" user:create \
    "$ADMIN_USER" "$ADMIN_PASS" "$ADMIN_EMAIL" admin
fi

# =============================================================================
# 11. Domain + SSL (optional)
# =============================================================================
if [ -n "$PANEL_DOMAIN" ]; then
  CERT_DIR="/etc/letsencrypt/live/$PANEL_DOMAIN"
  install -d -o www-data -g www-data -m 0755 /var/www/acme/challenges

  if [ -f "$CERT_DIR/fullchain.pem" ]; then
    # The certificate already exists (from a previous install or the DNS-01 path):
    # don't downgrade the config to port 80 only, use 443 right away.
    CERT_OK=1
    ok "the certificate for $PANEL_DOMAIN already exists"
  else
    # Stage 1: port 80 vhost only for now. A 443 vhost pointing to a certificate
    # that does not exist yet makes `nginx -t` fail and takes down all of nginx.
    info "Installing the port 80 vhost for $PANEL_DOMAIN"
    tmp="$(mktemp)"
    sed "s#panel\.example\.tld#$PANEL_DOMAIN#g" \
      "$SRC/install/nginx/fpanel-panel-acme.conf.example" > "$tmp"
    install -o root -g root -m 0644 "$tmp" /etc/nginx/sites-available/fpanel-panel.conf
    rm -f "$tmp"
    ln -sf /etc/nginx/sites-available/fpanel-panel.conf /etc/nginx/sites-enabled/fpanel-panel

    if nginx -t >/dev/null 2>&1; then
      systemctl reload nginx
      ok "port 80 vhost active"
    else
      nginx -t || true
      die "invalid nginx configuration for $PANEL_DOMAIN"
    fi

    # Stage 2: certificate (needs --email and an A record already pointing here).
    if [ -n "$PANEL_EMAIL" ]; then
      info "Requesting a Let's Encrypt certificate for $PANEL_DOMAIN"
      if certbot certonly --webroot -w /var/www/acme/challenges \
          -d "$PANEL_DOMAIN" -d "www.$PANEL_DOMAIN" \
          --non-interactive --agree-tos -m "$PANEL_EMAIL" --keep-until-expiring; then
        CERT_OK=1
        ok "SSL certificate installed"
      else
        warn "failed to obtain the certificate — the panel stays reachable via :$PANEL_PORT"
      fi
    else
      warn "without --email, SSL is skipped (the A record $PANEL_DOMAIN must point to this IP)"
    fi
  fi

  # Stage 3: final vhost — 443 when the certificate really exists.
  if [ "$CERT_OK" = "1" ]; then
    info "Enabling the 443 vhost for $PANEL_DOMAIN"
    tmp="$(mktemp)"
    sed "s#/srv/fpanel#$FPANEL_ROOT#g; s#panel\.example\.tld#$PANEL_DOMAIN#g" \
      "$SRC/install/nginx/fpanel-panel.conf.example" > "$tmp"
    install -o root -g root -m 0644 "$tmp" /etc/nginx/sites-available/fpanel-panel.conf
    rm -f "$tmp"
    ln -sf /etc/nginx/sites-available/fpanel-panel.conf /etc/nginx/sites-enabled/fpanel-panel
    if nginx -t >/dev/null 2>&1; then
      systemctl reload nginx
      ok "443 vhost active"
    else
      nginx -t || true
      warn "the 443 vhost is not valid — the panel stays on :$PANEL_PORT"
    fi
  fi
fi

# =============================================================================
# 12. Summary
# =============================================================================
ip="$(hostname -I 2>/dev/null | awk '{print $1}')"
echo
echo "============================================================================="
printf '  %sFPanel installation complete%s\n' "$c_grn" "$c_reset"
echo "============================================================================="
printf '  Panel        : http://%s:%s\n' "${ip:-<ip-server>}" "$PANEL_PORT"
if [ -n "$PANEL_DOMAIN" ]; then
  if [ "$CERT_OK" = "1" ]; then
    printf '  Domain       : https://%s\n' "$PANEL_DOMAIN"
  else
    printf '  Domain       : %s (port 80 vhost ready; no certificate yet)\n' "$PANEL_DOMAIN"
  fi
fi
printf '  Code         : %s\n' "$FPANEL_ROOT"
printf '  CLI          : php %s/bin/fpanel help\n' "$FPANEL_ROOT"
echo
if [ -n "$ADMIN_PASS" ]; then
  printf '  %sAdmin%s        : %s\n' "$c_ylw" "$c_reset" "$ADMIN_USER"
  printf '  %sPassword%s     : %s\n' "$c_ylw" "$c_reset" "$ADMIN_PASS"
  echo "  (save it now — the password will not be shown again)"
else
  printf '  Admin        : %s (password unchanged)\n' "$ADMIN_USER"
fi
echo
echo "  Reminders:"
echo "   - PTR/rDNS must be set on this IP for email to be deliverable."
echo "   - Ports 80/443 & inbound port 25 depend on the VPS provider."
echo "   - Panel secrets live in /etc/fpanel/ — never commit them to Git."
echo "============================================================================="
