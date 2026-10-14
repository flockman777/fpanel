-- FPanel schema v1 (SQLite). Prinsip: quota di package, runtime di domain,
-- semua aksi destruktif masuk audit_log, kerja berat masuk jobs.

CREATE TABLE IF NOT EXISTS schema_migrations (
  version     TEXT PRIMARY KEY,
  applied_at  TEXT NOT NULL DEFAULT (datetime('now'))
);

-- ============ PAKET & PENGGUNA ============
CREATE TABLE IF NOT EXISTS packages (
  id                INTEGER PRIMARY KEY,
  name              TEXT NOT NULL UNIQUE,
  max_domains       INTEGER NOT NULL DEFAULT 5,
  max_subdomains    INTEGER NOT NULL DEFAULT 20,
  max_databases     INTEGER NOT NULL DEFAULT 3,
  max_db_users      INTEGER NOT NULL DEFAULT 3,
  max_apps          INTEGER NOT NULL DEFAULT 3,
  max_wp_sites      INTEGER NOT NULL DEFAULT 2,
  max_cron          INTEGER NOT NULL DEFAULT 10,
  max_disk_mb       INTEGER NOT NULL DEFAULT 2048,
  max_bandwidth_mb  INTEGER NOT NULL DEFAULT 10240,
  max_memory_mb     INTEGER NOT NULL DEFAULT 256,
  max_node_apps     INTEGER NOT NULL DEFAULT 1,
  php_versions      TEXT NOT NULL DEFAULT '["8.3"]',  -- JSON allow-list
  node_versions     TEXT NOT NULL DEFAULT '["22"]',
  resources         TEXT NOT NULL DEFAULT '{}',      -- JSON toggle: {"email":false,"cron":true}
  is_default        INTEGER NOT NULL DEFAULT 0,
  created_at        TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS users (
  id              INTEGER PRIMARY KEY,
  username        TEXT NOT NULL UNIQUE,
  email           TEXT NOT NULL UNIQUE,
  password_hash   TEXT NOT NULL,
  role            TEXT NOT NULL DEFAULT 'user' CHECK (role IN ('admin','user')),
  status          TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active','suspended')),
  package_id      INTEGER REFERENCES packages(id) ON DELETE SET NULL,
  resource_toggles TEXT NOT NULL DEFAULT '{}',
  shell           TEXT NOT NULL DEFAULT '/usr/sbin/nologin',
  totp_secret     TEXT,
  totp_enabled    INTEGER NOT NULL DEFAULT 0,
  ssh_keys        TEXT NOT NULL DEFAULT '[]',        -- JSON
  failed_logins   INTEGER NOT NULL DEFAULT 0,
  locked_until    TEXT,
  last_login_at   TEXT,
  last_login_ip   TEXT,
  created_at      TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at      TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS ix_users_role ON users(role);

-- ============ DOMAIN & RUNTIME ============
-- type menentukan cara nginx melayani: root / fastcgi / proxy_pass
CREATE TABLE IF NOT EXISTS domains (
  id             INTEGER PRIMARY KEY,
  user_id        INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  name           TEXT NOT NULL UNIQUE,
  -- Hanya dua tipe. Node.js dan Python bukan tipe domain: keduanya aplikasi
  -- runtime yang menempel pada domain lewat runtime_apps.domain_id.
  type           TEXT NOT NULL DEFAULT 'php'
                 CHECK (type IN ('static','php')),
  is_subdomain   INTEGER NOT NULL DEFAULT 0,
  parent_domain  TEXT,
  docroot        TEXT NOT NULL,
  php_version    TEXT,               -- diisi bila type=php
  node_version   TEXT,               -- versi Node untuk app yang menempel
  runtime_port   INTEGER,            -- port app runtime yang menempel
  app_id         TEXT,               -- id katalog bila di-deploy dari App Catalog
  status         TEXT NOT NULL DEFAULT 'active'
                 CHECK (status IN ('active','suspended','error')),
  has_wellknown  INTEGER NOT NULL DEFAULT 0,   -- .well-known tidak diblokir HTTPS
  ssl_status     TEXT NOT NULL DEFAULT 'none'
                 CHECK (ssl_status IN ('none','pending','active','error','revoked')),
  ssl_issued_at  TEXT,               -- waktu sertifikat terakhir terbit
  ssl_error      TEXT,               -- pesan error terakhir (display ke user)
  created_at     TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at     TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS ix_domains_user ON domains(user_id);
CREATE INDEX IF NOT EXISTS ix_domains_port ON domains(runtime_port);

-- ============ DATABASE ============
CREATE TABLE IF NOT EXISTS databases (
  id          INTEGER PRIMARY KEY,
  user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  name        TEXT NOT NULL,
  quota_mb    INTEGER NOT NULL DEFAULT 256,
  used_mb     INTEGER NOT NULL DEFAULT 0,
  charset     TEXT NOT NULL DEFAULT 'utf8mb4',
  UNIQUE (name)
);
CREATE INDEX IF NOT EXISTS ix_db_user ON databases(user_id);

CREATE TABLE IF NOT EXISTS db_users (
  id            INTEGER PRIMARY KEY,
  database_id   INTEGER NOT NULL REFERENCES databases(id) ON DELETE CASCADE,
  name          TEXT NOT NULL,
  password_hash TEXT NOT NULL,
  host          TEXT NOT NULL DEFAULT 'localhost',
  privileges    TEXT NOT NULL DEFAULT 'ALL PRIVILEGES',
  UNIQUE (name, host)
);

-- ============ SSL ============
CREATE TABLE IF NOT EXISTS mail_accounts (
  id           INTEGER PRIMARY KEY,
  user_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  domain_id    INTEGER NOT NULL REFERENCES domains(id) ON DELETE CASCADE,
  address      TEXT NOT NULL,
  password_enc TEXT,
  quota_mb     INTEGER NOT NULL DEFAULT 256,
  used_mb      INTEGER NOT NULL DEFAULT 0,
  status       TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active','suspended')),
  created_at   TEXT NOT NULL DEFAULT (datetime('now')),
  UNIQUE (address)
);
CREATE TABLE IF NOT EXISTS ssl_certs (
  id           INTEGER PRIMARY KEY,
  domain_id    INTEGER NOT NULL REFERENCES domains(id) ON DELETE CASCADE,
  provider     TEXT NOT NULL DEFAULT 'letsencrypt',
  challenge    TEXT NOT NULL DEFAULT 'dns-01' CHECK (challenge IN ('dns-01','http-01')),
  san          TEXT NOT NULL DEFAULT '',
  status       TEXT NOT NULL DEFAULT 'pending'
               CHECK (status IN ('pending','issuing','active','failed','expired','revoked')),
  cert_path    TEXT,
  key_path     TEXT,
  fullchain_path TEXT,
  auto_renew   INTEGER NOT NULL DEFAULT 1,
  issued_at    TEXT,
  expires_at   TEXT,
  last_error   TEXT,
  created_at   TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS ix_ssl_domain ON ssl_certs(domain_id);

-- ============ ANTREAN PEKERJAAN ============
-- Semua yang lama/berat WAJIB lewat sini, tidak boleh blocking HTTP request.
CREATE TABLE IF NOT EXISTS jobs (
  id            INTEGER PRIMARY KEY,
  user_id       INTEGER REFERENCES users(id) ON DELETE CASCADE,
  type          TEXT NOT NULL,
  payload       TEXT NOT NULL DEFAULT '{}',
  status        TEXT NOT NULL DEFAULT 'queued'
                CHECK (status IN ('queued','running','done','failed','cancelled')),
  priority      INTEGER NOT NULL DEFAULT 100,
  attempts      INTEGER NOT NULL DEFAULT 0,
  max_attempts  INTEGER NOT NULL DEFAULT 3,
  progress      INTEGER NOT NULL DEFAULT 0,
  progress_msg  TEXT,
  stdout        TEXT,
  stderr        TEXT,
  exit_code     INTEGER,
  started_at    TEXT,
  finished_at   TEXT,
  created_at    TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS ix_jobs_pick ON jobs(status, priority, id);
CREATE INDEX IF NOT EXISTS ix_jobs_user ON jobs(user_id, id DESC);

-- ============ KEAMANAN & AUDIT ============
CREATE TABLE IF NOT EXISTS sessions (
  id           INTEGER PRIMARY KEY,
  user_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  token_hash   TEXT NOT NULL UNIQUE,
  ip           TEXT,
  user_agent   TEXT,
  created_at   TEXT NOT NULL DEFAULT (datetime('now')),
  last_seen_at TEXT NOT NULL DEFAULT (datetime('now')),
  expires_at   TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS ix_sessions_user ON sessions(user_id);
CREATE INDEX IF NOT EXISTS ix_sessions_exp  ON sessions(expires_at);

CREATE TABLE IF NOT EXISTS login_attempts (
  id        INTEGER PRIMARY KEY,
  ip        TEXT NOT NULL,
  username  TEXT,
  success   INTEGER NOT NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS ix_attempts ON login_attempts(ip, created_at DESC);

CREATE TABLE IF NOT EXISTS audit_log (
  id          INTEGER PRIMARY KEY,
  actor_id    INTEGER REFERENCES users(id) ON DELETE SET NULL,
  actor_name  TEXT NOT NULL DEFAULT 'system',
  action      TEXT NOT NULL,
  target_type TEXT,
  target_id   TEXT,
  ip          TEXT,
  result      TEXT NOT NULL DEFAULT 'ok' CHECK (result IN ('ok','denied','error')),
  meta        TEXT NOT NULL DEFAULT '{}',
  created_at  TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS ix_audit_created ON audit_log(id DESC);
CREATE INDEX IF NOT EXISTS ix_audit_actor   ON audit_log(actor_id, id DESC);

-- ============ BACKUP, CRON, APPS ============
CREATE TABLE IF NOT EXISTS backups (
  id          INTEGER PRIMARY KEY,
  user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  domain_id   INTEGER REFERENCES domains(id) ON DELETE CASCADE,
  kind        TEXT NOT NULL CHECK (kind IN ('full','files','db')),
  status      TEXT NOT NULL DEFAULT 'queued'
              CHECK (status IN ('queued','running','done','failed')),
  size_bytes  INTEGER NOT NULL DEFAULT 0,
  path        TEXT,
  keep_forever INTEGER NOT NULL DEFAULT 0,
  error       TEXT,
  created_at  TEXT NOT NULL DEFAULT (datetime('now')),
  finished_at TEXT
);
CREATE INDEX IF NOT EXISTS ix_backup_user ON backups(user_id, id DESC);

CREATE TABLE IF NOT EXISTS cron_jobs (
  id         INTEGER PRIMARY KEY,
  user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  name       TEXT NOT NULL,
  command    TEXT NOT NULL,
  schedule   TEXT NOT NULL,           -- 5-field cron
  enabled    INTEGER NOT NULL DEFAULT 1,
  next_run   TEXT,
  last_run   TEXT,
  last_exit  INTEGER,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS ix_cron_next ON cron_jobs(enabled, next_run);

CREATE TABLE IF NOT EXISTS apps_installed (
  id          INTEGER PRIMARY KEY,
  user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  domain_id   INTEGER REFERENCES domains(id) ON DELETE CASCADE,
  app_id      TEXT NOT NULL,
  app_version TEXT,
  state       TEXT NOT NULL DEFAULT 'installed'
              CHECK (state IN ('installing','installed','updating','failed','removing')),
  health      TEXT NOT NULL DEFAULT 'unknown',
  config      TEXT NOT NULL DEFAULT '{}',
  installed_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS ix_apps_user ON apps_installed(user_id);

-- Cache metadata plugin & theme dari api.wordpress.org (dipinjol worker)
CREATE TABLE IF NOT EXISTS catalog_items (
  id            INTEGER PRIMARY KEY,
  kind          TEXT NOT NULL CHECK (kind IN ('plugin','theme')),
  slug          TEXT NOT NULL,
  name          TEXT NOT NULL,
  author        TEXT,
  latest        TEXT,
  versions      TEXT NOT NULL DEFAULT '[]',   -- JSON array, descending
  last_updated  TEXT,
  tested_up_to  TEXT,
  downloads     INTEGER NOT NULL DEFAULT 0,
  active_installs INTEGER NOT NULL DEFAULT 0,
  rating        REAL NOT NULL DEFAULT 0,
  closed        INTEGER NOT NULL DEFAULT 0,
  synced_at     TEXT NOT NULL DEFAULT (datetime('now')),
  UNIQUE (kind, slug)
);

CREATE TABLE IF NOT EXISTS wp_sites (
  id           INTEGER PRIMARY KEY,
  user_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  domain_id    INTEGER NOT NULL REFERENCES domains(id) ON DELETE CASCADE,
  core_version TEXT NOT NULL,
  is_staging   INTEGER NOT NULL DEFAULT 0,
  production_id INTEGER REFERENCES wp_sites(id) ON DELETE SET NULL,
  last_backup_at TEXT,
  created_at   TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS ix_wp_user ON wp_sites(user_id);

-- ============ PENGGUNAAN & ALERT ============
CREATE TABLE IF NOT EXISTS resource_usage (
  user_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  disk_bytes   INTEGER NOT NULL DEFAULT 0,
  bandwidth_bytes INTEGER NOT NULL DEFAULT 0,
  measured_at  TEXT NOT NULL DEFAULT (datetime('now')),
  PRIMARY KEY (user_id)
);

CREATE TABLE IF NOT EXISTS alerts (
  id         INTEGER PRIMARY KEY,
  user_id    INTEGER REFERENCES users(id) ON DELETE CASCADE,
  severity   TEXT NOT NULL CHECK (severity IN ('info','warn','critical')),
  kind       TEXT NOT NULL,
  message    TEXT NOT NULL,
  action_url TEXT,
  resolved   INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS ix_alerts_open ON alerts(resolved, id DESC);

CREATE TABLE IF NOT EXISTS settings (
  key        TEXT PRIMARY KEY,
  value      TEXT NOT NULL,
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- App runtime per domain (Node.js / Python). Satu app = satu systemd unit
-- fpanel-app-<nama>.service; port dipakai nginx untuk proxy ke app tersebut.
CREATE TABLE IF NOT EXISTS runtime_apps (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL,
  username TEXT NOT NULL,
  -- Domain tempat app ini dilayani. ON DELETE CASCADE supaya app ikut hilang
  -- bersama domainnya. Kolom ini yang jadi sumber kebenaran runtime, bukan
  -- domains.type.
  domain_id INTEGER REFERENCES domains(id) ON DELETE CASCADE,
  name TEXT NOT NULL,
  kind TEXT NOT NULL,
  root TEXT NOT NULL,
  entry TEXT NOT NULL,
  node_version TEXT,
  port INTEGER NOT NULL,
  status TEXT NOT NULL DEFAULT 'stopped',
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_runtime_apps_user_name ON runtime_apps (user_id, name);
CREATE UNIQUE INDEX IF NOT EXISTS idx_runtime_apps_port ON runtime_apps (port);
