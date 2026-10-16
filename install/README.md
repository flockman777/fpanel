# Installing FPanel

This directory holds the **installer** and every system asset FPanel needs.
It is kept separate from the application code so it is always clear what goes
into `/srv/fpanel` and what goes into system directories.

| Folder | Destination on the server |
|---|---|
| `install.sh` | Main install script |
| `helpers/` | `/usr/local/sbin/fpanel-*` — privileged helpers |
| `sudoers/` | `/etc/sudoers.d/fpanel-*` — per-command permission rules |
| `systemd/` | example app-runtime units (`/etc/systemd/system/`) |
| `cron/` | `/etc/cron.d/fpanel-*` |
| `nginx/` | panel vhost + SSL snippet + catch-all |
| `logrotate/` | `/etc/logrotate.d/fpanel-backup` |
| `phpmyadmin/` | `/usr/share/phpmyadmin/fpma-index.php` (token front controller) |

---

## Quick start

```bash
git clone https://github.com/flockman777/fpanel.git
cd fpanel
sudo ./install/install.sh
```

The interactive installer asks for confirmation before changing anything on
the system.

## Requirements

| Item | Details |
|---|---|
| System | Ubuntu 24.04 (or a supported Debian derivative) |
| Access | root (or `sudo`), plus an internet connection |
| Disk | ~1.5 GB for packages + application |
| Ports | `8090` for the panel; `80`/`443` only when a domain is used |
| Domain | optional — the panel also works over `IP:8090` |

The packages the installer needs are installed automatically (`sudo`,
`sqlite3`, `nginx`, MariaDB, Redis, certbot, PHP-FPM, phpMyAdmin, WP-CLI,
Node.js). For PHP versions outside the distro default (7.4–8.4) the installer
adds the `ondrej/php` PPA itself. If the server is already prepared, skip
package installation with `--skip-packages` — the installer still checks that
the required tools are present.

### Non-interactive

```bash
sudo ./install/install.sh --yes \
  --domain panel.example.tld \
  --email admin@example.tld \
  --admin-user admin
```

The admin password is generated randomly and printed at the end. To choose
your own:

```bash
sudo ./install/install.sh --yes --admin-pass 'Strong!Pass123'
```

---

## What the installer does

1. **Preflight** — check root, the distro, and that the code is present.
2. **Packages** — nginx, MariaDB, Redis, certbot, PHP-FPM (multi-version),
   phpMyAdmin, WP-CLI, Node.js (optional). Afterwards every required tool
   (`nginx`, `php`, `rsync`, `sudo`, `visudo`, `sqlite3`, `unzip`, `curl`) is
   checked; if anything is missing the installer stops with a clear message.
3. **Base services** — nginx, MariaDB, Redis, and php-fpm are enabled early so
   service failures surface immediately (the database helper needs MariaDB
   running).
4. **Directories** — `/var/www`, `/var/log/nginx/fpanel`,
   `/etc/nginx/sites-available/fpanel-domains`, per-version PHP conf.d,
   `/var/lib/fpanel`, `/etc/fpanel`, `/var/backups/fpanel`.
5. **Application** — rsync the repository → `/srv/fpanel` (excluding `var/`,
   `node_modules/`, `.git/`, `install/`, `config/local.php`).
6. **Helpers + sudoers + cron + logrotate** — installed to their system
   locations. The sudo rules are validated with `visudo -c`; if they are not
   valid, everything is rolled back.
7. **phpMyAdmin** — token front controller `/pma/<token>/`.
8. **Panel key** — `/etc/fpanel/app-key` (32 random bytes, mode 0640).
9. **nginx** — panel vhost on port `8090`, SSL snippet, catch-all.
10. **Database** — `db:migrate` + admin account.
11. **Domain + SSL** (optional) — the port-80 vhost is installed first, then
    the certificate through the certbot webroot; the 443 vhost is only enabled
    once the certificate really exists (otherwise `nginx -t` can fail).
12. **Summary** — panel URL and admin credentials.

The installer is **safe to run repeatedly**: configuration files are
overwritten with the repository versions, but the database, `/var/www`,
certificates, the panel key, and `config/local.php` are left untouched.

---

## Options

```
--domain <d>      Panel domain (443 vhost + SSL)
--email <e>       Let's Encrypt & admin email
--admin-user <u>  Admin username         (default: admin)
--admin-email <e> Admin email
--admin-pass <p>  Admin password         (default: random)
--php <ver>       Default PHP version    (default: 8.3)
--port <n>        Panel port             (default: 8090)
--root <dir>      Install location       (default: /srv/fpanel)
--no-node         Do not install Node.js
--no-pma          Do not install phpMyAdmin
--skip-packages   Skip apt package installation
--yes             Non-interactive
```

---

## After installation

- Open `http://<ip>:8090` (or `https://<domain>` when a domain is set).
- Change the admin password from the dashboard and enable TOTP in the
  Security menu.
- Configure the server IP's **PTR/rDNS** so email sent by the panel is not
  rejected.
- For Cloudflare DNS-01 SSL, set `FPANEL_CF_API_TOKEN` & `FPANEL_CF_ZONE_ID`
  (see `env.example`).

## Secrets

Every secret lives **outside the repository**:

| File | Contents | Permissions |
|---|---|---|
| `/etc/fpanel/app-key` | SecretBox encryption key | `0640 root:www-data` |
| `/etc/fpanel/offsite.conf` | S3 offsite credentials | `0600 root` |
| `/srv/fpanel/var/db/fpanel.sqlite` | panel database | `0750 www-data` |

Never `git add` these files — `.gitignore` already covers them.

---

## Testing the installer without a server

```bash
# needs Docker (macOS: brew install colima docker && colima start)
./tools/test-install-container.sh
```

That script runs the installer inside a clean Ubuntu 24.04 container (with
systemd running), then checks services, the panel key, the database, sudoers,
HTTP responses, and runs the installer again to prove it is idempotent. It
never touches a production server and needs no domain/SSL.
