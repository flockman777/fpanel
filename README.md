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
`waf`, `ssh`, `totp`, `dns`, `cron`, `backups`, `logs`, `stats`, `apps`.
Customer-scoped endpoints live under `/api/s/{sess}/client/...`.

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
- [x] Security: IP blocker, hotlink, WAF, SSH, 2FA/TOTP
- [x] Cron jobs, backups
- [x] Monitoring: access/error logs, disk & bandwidth usage
- [ ] Billing module (own WHMCS-like) — deferred