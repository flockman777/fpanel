# FPanel

Self-hosted hosting control panel backed by Rust. Blue theme, Lucide icons.

## Layout

```
fpanel/
├── panel/   # Rust/axum backend — API + persistence (Port 8181)
├── server/  # Pingora-based web server for client vhosts (Port 8080)
├── web/     # React/Vite/Tailwind — admin UI (Port 5173)
└── db/      # Schema (db/schema.sql)
```

## Components

| Component | Directory | Purpose |
|---|---|---|
| Panel | `panel/` | Management API: auth, accounts, domains, files, databases, email, PHP, SSL, security, DNS, cron, backups, monitoring |
| fserver | `server/` | Reverse-proxy web server: static files, PHP-CGI, redirects, proxy runtime, IP blocker / hotlink / WAF, access & error logs |
| Admin UI | `web/` | Operator panel (Vite, proxying to :8181) |
| Client UI | `web/client/` | Customer panel |
| mailtrack | `tools/mailtrack.py` | Postfix content filter: HTML open-pixel + link-click tracking daemon |

## Getting Started

**Backend:**
```bash
cd panel
export OPENSSL_DIR=/opt/homebrew/opt/openssl@3   # macOS Homebrew
cargo run
# listens on http://localhost:8181
```

**Web server (fserver):**
```bash
cd server
cargo run
# listens on http://localhost:8080
```

**Frontend:**
```bash
cd web
node node_modules/vite/bin/vite.js
# http://localhost:5173 (proxies /api to :8181)
```

## First admin account

Register the first admin via API:
```bash
curl -X POST http://localhost:8181/api/auth/register \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"CHANGE_ME"}'
```

`{sess}` is a per-login session token. All protected endpoints are reached via
`/api/s/{sess}/...` — a session-URL model where each login gets its own URL.
Requests with a missing or mismatched session are rejected (401/404).

## Environment

| Variable | Used by | Default |
|---|---|---|
| `FPANEL_SECRET` | panel | `fpanel-dev-secret-change-me` (JWT signing key — set a strong secret in production) |
| `FPANEL_LOGS` | panel, fserver | `<repo>/panel/data/logs` (access/error logs destination) |
| `FPANEL_VHOSTS` | fserver | `<repo>/panel/vhosts` |

## API (summary)

| Method | Endpoint | Purpose |
|---|---|---|
| POST | `/api/auth/register` | Register first admin |
| POST | `/api/auth/login` | Login → JWT + `sess` |
| GET | `/api/s/{sess}/me` | Session info (Bearer token) |
| GET/POST | `/api/s/{sess}/packages` | List / create hosting packages |
| GET/DELETE | `/api/s/{sess}/packages/{id}` | Package detail / delete |
| GET/POST | `/api/s/{sess}/accounts` | List / create customer accounts |
| GET/PUT/DELETE | `/api/s/{sess}/accounts/{id}` | Account detail / update / delete |

Plus full feature suites under `/api/s/{sess}/...`: `domains`, `directives`,
`files`, `databases`, `email`, `php`, `ssl`, `runtime`, `ipblocker`, `hotlink`,
`waf`, `ssh`, `totp`, `dns`, `cron`, `backups`, `logs`, `stats`, `apps`,
`deliverability`, `delivery`, `tracking`.
Customer-scoped endpoints live under `/api/s/{sess}/client/...`.

## Email

Out-of-the-box mail stack plus deliverability tooling:

- **Mail service** — Postfix (SMTP :25 / submission :587) + Dovecot (IMAP :993 / POP3 :995)
  with per-account maildir virtual hosting and Roundcube webmail (`https://webmail.<domain>`).
- **DKIM** — per-domain OpenDKIM keys (`/etc/opendkim/keys/<domain>/`), issued and
  published as DNS records by the panel; signing applied inside the mail pipeline.
- **Deliverability page** (`/deliverability`) — one-click SPF / DMARC / DKIM record
  provisioning on the panel's nsd authoritative DNS (`generate_zone` also chunks
  TXT records >255 chars for nsd).
- **Delivery tracking** (`/delivery`) — parses postfix `maillog_file`
  (`/var/log/mail.log`) into the `mail_log` table; shows sent / bounced / deferred
  per message.
- **Open & link tracking** (`/tracking`) — Postfix `content_filter` hands every outbound
  mail to `tools/mailtrack.py` (systemd `mailtrack.service`), which injects a tracking
  pixel (`/t/o/{token}.png`) and rewrites links to `/t/c/{token}?u=...`, then re-injects
  through the loopback SMTP listener (DKIM-signing milter) so recipients see one trust
  chain. Opens and clicks are stored in `/var/log/mailtrack.db`.
- **Client Mail Settings tab** — shows IMAP/POP3/SMTP host & ports (993/995/587) plus a
  copy-to-clipboard helper so customers can configure any mail client.

## Runtime data

`panel/data/` and `panel/vhosts/` are generated at runtime (domains, logs, certs,
PHP sites) and are **not** part of this repository. See `.gitignore`.

## Roadmap

- [x] Auth + dashboard + accounts + packages
- [x] Domains & subdomains (main / sub / alias)
- [x] File manager
- [x] Database provisioning (MariaDB)
- [x] Pingora web server layer (static, PHP, redirects, proxy)
- [x] DNS zone editor, SSL, email
- [x] Email deliverability: SPF / DMARC / DKIM provisioning + signing
- [x] Email delivery tracking (postfix log) and open/click tracking (content filter)
- [x] Security: IP blocker, hotlink, WAF, SSH, 2FA/TOTP
- [x] Cron jobs, backups
- [x] Monitoring: access/error logs, disk & bandwidth usage
- [ ] Billing module (own WHMCS-like) — deferred