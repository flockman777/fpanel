# FPanel

Self-hosted hosting control panel: manage domains, PHP versions, MariaDB
databases, Node.js/Python apps, backups, and SSL certificates from a single
web interface.

Written in **plain PHP 8.3 without a framework** (no Composer at runtime),
panel data stored in **SQLite**, and privileged operations executed through
root helpers locked down with per-command `sudoers` rules.

---

## Features

| Area | What it does |
|---|---|
| **Domains** | Add/remove nginx vhosts, per-domain docroot, per-domain access/error logs |
| **PHP** | Multi-version (7.4–8.4), FPM pool per domain, extension on/off, `php.ini` overrides |
| **Databases** | Create/remove MariaDB databases and users, grant/revoke, check size |
| **phpMyAdmin** | Per-database access with one-time tokens (`/pma/<token>/`), tokens swept automatically |
| **SSL** | Let's Encrypt through certbot (HTTP-01 and Cloudflare DNS-01) |
| **App runtime** | Run Node.js / Python apps as systemd units (`fpanel-app-<name>`) |
| **File manager** | Browse, upload, edit, and delete files inside the docroot |
| **Backup** | Local backups (SQLite, `/var/www`, databases, config, certificates) + S3 offsite |
| **Cron** | Per-user cron jobs managed through a root helper |
| **WordPress** | Install and update WordPress with WP-CLI |
| **Security** | Login + TOTP, remember-device, CSRF, audit log, login rate limiting |
| **Statistics** | Per-domain disk, bandwidth, and request usage |

---

## Architecture

```
                    ┌─────────────────────────────┐
   Browser  ──────▶ │  nginx  :8090 (panel)       │
                    │  nginx  :80/443 (domains)   │
                    └──────────────┬──────────────┘
                                   │ fastcgi
                    ┌──────────────▼──────────────┐
                    │  php8.3-fpm                 │
                    │  /srv/fpanel/app/public     │
                    └──────────────┬──────────────┘
             ┌─────────────────────┼─────────────────────┐
             │                     │                     │
     ┌───────▼────────┐  ┌─────────▼─────────┐  ┌────────▼────────┐
     │ SQLite         │  │ MariaDB + Redis   │  │ root helpers    │
     │ var/db/*.sqlite│  │ (domains, apps)   │  │ /usr/local/sbin │
     └────────────────┘  └───────────────────┘  └─────────────────┘
```

- The **panel** runs as `www-data` and has no direct root access.
- The **application** calls privileged operations through
  `/usr/local/sbin/fpanel-*`, allowed per command in
  `/etc/sudoers.d/fpanel-*`. Each helper validates its own arguments
  (name, path, port) before touching systemd/nginx/MariaDB.
- **phpMyAdmin** is reached through the `fpma-index.php` front controller,
  which validates the token from the URI; no credentials pass through it.

---

## Repository layout

```
.
├── app/                    # PHP application (SPL autoload, no framework)
│   ├── bootstrap.php       #   entry point for CLI & web (FPANEL_ROOT, autoload, config)
│   ├── public/             #   docroot: index.php + static assets
│   ├── src/                #   Auth, Database, Http, Service, View, Support
│   ├── views/              #   .php templates
│   └── i18n/               #   translations (id)
├── bin/fpanel              # CLI (migrate, user, backup, ...)
├── config/
│   ├── fpanel.php          #   main configuration (overridable via FPANEL_* env vars)
│   └── schema.sql          #   panel database schema
├── install/                # INSTALLER + system assets
│   ├── install.sh          #   main install script
│   ├── helpers/            #   → /usr/local/sbin/fpanel-*
│   ├── sudoers/            #   → /etc/sudoers.d/fpanel-*
│   ├── systemd/            #   example units
│   ├── cron/               #   → /etc/cron.d/fpanel-*
│   ├── nginx/              #   panel vhost + snippet + catch-all
│   ├── logrotate/          #   → /etc/logrotate.d/fpanel-backup
│   └── phpmyadmin/         #   → /usr/share/phpmyadmin/fpma-index.php
├── assets-src/             # Tailwind sources (canonical) + config
├── tools/                  # build scripts (CSS, icons, features)
├── tests/                  # integration suite
└── web/mockup/             # legacy UI mockups (visual reference)
```

---

## Installation

Requirements: **Ubuntu 24.04** (or a Debian derivative), **root** access, and
an internet connection. A domain is optional (the panel also works over
`IP:8090`).

```bash
git clone https://github.com/flockman777/fpanel.git
cd fpanel
sudo ./install/install.sh
```

The installer installs packages, creates directories, copies the application
to `/srv/fpanel`, installs the helpers + sudoers + cron, creates the database,
and creates the admin account. See [`install/README.md`](install/README.md)
for advanced options (domain, SSL, non-interactive mode).

When it finishes, the panel is available at:

- `http://<server-ip>:8090` — always available;
- `https://<panel-domain>` — once a domain and SSL are configured.

---

## Configuration

Main configuration lives in `config/fpanel.php`. Every value can be
overridden through `FPANEL_*` environment variables (see
`install/env.example`):

| Env | Default | Purpose |
|---|---|---|
| `FPANEL_BASE_URL` | — (auto) | Absolute panel URL |
| `FPANEL_APP_DEBUG` | `0` | Debug mode |
| `FPANEL_CF_API_TOKEN` | — | Cloudflare token (SSL DNS-01) |
| `FPANEL_CF_ZONE_ID` | — | Cloudflare zone |
| `FPANEL_MAIL_RELAY_*` | — | SMTP relay for notifications |

Server-specific values (such as the panel domain and SSL email) are written
by the installer to `config/local.php`. That file is **not** part of Git, so
the defaults committed to the repository stay generic for everyone:

```php
<?php
return [
    'app'         => ['base_url' => 'https://panel.example.tld'],
    'letsencrypt' => ['email'    => 'admin@example.tld'],
];
```

Secrets are stored **outside** the repository:

- `/etc/fpanel/app-key` — encryption key (SecretBox), mode `0640 root:www-data`;
- `/etc/fpanel/offsite.conf` — S3 offsite credentials, mode `0600 root`.

---

## CLI

```bash
php bin/fpanel help              # list commands
php bin/fpanel db:migrate        # apply the schema
php bin/fpanel user:create admin 'Strong!Pass123' admin@example.tld admin
php bin/fpanel user:list
php bin/fpanel backup:create
php bin/fpanel pma:sweep
```

---

## Development

```bash
# CSS (Tailwind) — output goes to app/public & web/mockup
./tools/build-css.sh

# Icons (Lucide)
node tools/build-icons.mjs

# Integration suite (needs an installed stack; see the header of tests/run.sh)
sudo tests/run.sh
```

The integration suite runs ~657 assertions covering domains, databases, SSL,
backup, and security flows.

The installer can be tested in a clean container without touching a server:

```bash
# needs Docker (on macOS: brew install colima docker && colima start)
./tools/test-install-container.sh
```

That script runs `install/install.sh` on Ubuntu 24.04 + systemd, then checks
services, the panel key, the database, sudo rules, and HTTP responses —
including a second installer run to prove it is idempotent.

---

## Security

- The panel never stores MariaDB/SSH passwords in plain text.
- Root operations go only through helpers allowed **per command** in
  `/etc/sudoers.d/` — never `NOPASSWD: ALL`.
- All secrets (panel key, S3 credentials) live in `/etc/fpanel` with strict
  permissions and are **never** committed to the repository.
- The `var/` directory (database, logs, snapshots) is ignored by git.

---

## License

MIT.
