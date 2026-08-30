CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'admin',
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS packages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    disk_limit_mb INTEGER NOT NULL DEFAULT 1024,
    mailbox_limit INTEGER NOT NULL DEFAULT 5,
    database_limit INTEGER NOT NULL DEFAULT 1,
    domain_limit INTEGER NOT NULL DEFAULT 1,
    bandwidth_limit_gb INTEGER NOT NULL DEFAULT 10,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS accounts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    email TEXT NOT NULL,
    password_hash TEXT,
    package_id INTEGER NOT NULL REFERENCES packages(id),
    status TEXT NOT NULL DEFAULT 'active',
    name TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS domains (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id INTEGER NOT NULL REFERENCES accounts(id),
    name TEXT NOT NULL,
    kind TEXT NOT NULL DEFAULT 'main',
    status TEXT NOT NULL DEFAULT 'active',
    docroot TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE(name)
);

CREATE TABLE IF NOT EXISTS redirects (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id INTEGER NOT NULL REFERENCES accounts(id),
    domain_id INTEGER REFERENCES domains(id),
    from_path TEXT NOT NULL DEFAULT '/',
    to_url TEXT NOT NULL,
    permanent INTEGER NOT NULL DEFAULT 1,
    status TEXT NOT NULL DEFAULT 'active',
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE(account_id, domain_id, from_path)
);

CREATE TABLE IF NOT EXISTS databases (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id INTEGER NOT NULL REFERENCES accounts(id),
    name TEXT NOT NULL,
    db_user TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'active',
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE(name)
);

CREATE TABLE IF NOT EXISTS db_users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id INTEGER NOT NULL REFERENCES accounts(id),
    username TEXT NOT NULL,
    password TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'active',
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE(account_id, username)
);

CREATE TABLE IF NOT EXISTS db_privileges (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    db_id INTEGER NOT NULL REFERENCES databases(id),
    user_id INTEGER NOT NULL REFERENCES db_users(id),
    privileges TEXT NOT NULL DEFAULT 'ALL PRIVILEGES',
    UNIQUE(db_id, user_id)
);

CREATE TABLE IF NOT EXISTS email_accounts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id INTEGER NOT NULL REFERENCES accounts(id),
    domain_id INTEGER NOT NULL REFERENCES domains(id),
    local TEXT NOT NULL,
    password_hash TEXT NOT NULL,
    forward_to TEXT,
    quota_mb INTEGER NOT NULL DEFAULT 256,
    status TEXT NOT NULL DEFAULT 'active',
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE(domain_id, local)
);

CREATE TABLE IF NOT EXISTS email_forwarders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id INTEGER NOT NULL REFERENCES accounts(id),
    domain_id INTEGER NOT NULL REFERENCES domains(id),
    from_address TEXT NOT NULL,
    to_address TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'active',
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE(domain_id, from_address)
);

CREATE TABLE IF NOT EXISTS email_autoresponders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id INTEGER NOT NULL REFERENCES accounts(id),
    domain_id INTEGER NOT NULL REFERENCES domains(id),
    local TEXT NOT NULL,
    subject TEXT NOT NULL,
    body TEXT NOT NULL DEFAULT '',
    start_date TEXT,
    end_date TEXT,
    status TEXT NOT NULL DEFAULT 'active',
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE(domain_id, local)
);

CREATE TABLE IF NOT EXISTS email_defaults (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id INTEGER NOT NULL REFERENCES accounts(id),
    domain_id INTEGER NOT NULL REFERENCES domains(id),
    action TEXT NOT NULL DEFAULT 'discard',
    forward_to TEXT,
    status TEXT NOT NULL DEFAULT 'active',
    UNIQUE(account_id, domain_id)
);

CREATE TABLE IF NOT EXISTS ssl_certs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id INTEGER NOT NULL REFERENCES accounts(id),
    domain_id INTEGER NOT NULL REFERENCES domains(id),
    domain TEXT NOT NULL,
    cert_pem TEXT NOT NULL,
    key_pem TEXT NOT NULL,
    ca_pem TEXT,
    issuer TEXT,
    valid_from TEXT,
    valid_to TEXT,
    status TEXT NOT NULL DEFAULT 'active',
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE(domain_id)
);

CREATE TABLE IF NOT EXISTS php_settings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id INTEGER NOT NULL REFERENCES accounts(id),
    domain_id INTEGER NOT NULL REFERENCES domains(id),
    domain TEXT NOT NULL,
    version TEXT NOT NULL DEFAULT 'system',
    ini_config TEXT,
    handler TEXT NOT NULL DEFAULT 'system',
    status TEXT NOT NULL DEFAULT 'active',
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE(domain_id)
);

CREATE TABLE IF NOT EXISTS installed_apps (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id INTEGER NOT NULL REFERENCES accounts(id),
    domain_id INTEGER NOT NULL REFERENCES domains(id),
    domain TEXT NOT NULL,
    app TEXT NOT NULL,
    path TEXT NOT NULL DEFAULT '/',
    version TEXT,
    db_name TEXT,
    db_user TEXT,
    admin_user TEXT,
    admin_email TEXT,
    status TEXT NOT NULL DEFAULT 'installed',
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS ip_blocker (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id INTEGER NOT NULL REFERENCES accounts(id),
    domain_id INTEGER REFERENCES domains(id),
    domain TEXT,
    ip TEXT NOT NULL,
    reason TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE(domain_id, ip)
);

CREATE TABLE IF NOT EXISTS hotlink (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id INTEGER NOT NULL REFERENCES accounts(id),
    domain_id INTEGER NOT NULL REFERENCES domains(id),
    domain TEXT NOT NULL,
    extensions TEXT NOT NULL DEFAULT 'jpg|jpeg|png|gif|webp|bmp|svg|css|js|woff|woff2',
    allow_empty INTEGER NOT NULL DEFAULT 1,
    allowed_domains TEXT,
    status INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE(domain_id)
);

CREATE TABLE IF NOT EXISTS waf_rules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id INTEGER NOT NULL REFERENCES accounts(id),
    domain_id INTEGER NOT NULL REFERENCES domains(id),
    domain TEXT NOT NULL,
    enabled INTEGER NOT NULL DEFAULT 1,
    mode TEXT NOT NULL DEFAULT 'block',
    rules TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE(domain_id)
);

CREATE TABLE IF NOT EXISTS totp_secrets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_type TEXT NOT NULL,
    user_id INTEGER NOT NULL,
    secret TEXT NOT NULL,
    enabled INTEGER NOT NULL DEFAULT 0,
    last_step INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE(user_type, user_id)
);

CREATE TABLE IF NOT EXISTS ssh_access (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id INTEGER NOT NULL REFERENCES accounts(id),
    username TEXT NOT NULL,
    auth_type TEXT NOT NULL DEFAULT 'key',
    public_key TEXT,
    authorized_keys TEXT,
    status TEXT NOT NULL DEFAULT 'active',
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE(account_id, username)
);

CREATE TABLE IF NOT EXISTS run_apps (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id INTEGER NOT NULL REFERENCES accounts(id),
    domain_id INTEGER NOT NULL REFERENCES domains(id),
    domain TEXT NOT NULL,
    app TEXT NOT NULL DEFAULT 'app',
    runtime TEXT NOT NULL,
    entrypoint TEXT NOT NULL,
    port INTEGER NOT NULL,
    env TEXT,
    auto_restart INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'stopped',
    pid INTEGER,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE(domain_id)
);

CREATE TABLE IF NOT EXISTS dns_records (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id INTEGER NOT NULL REFERENCES accounts(id),
    domain_id INTEGER NOT NULL REFERENCES domains(id),
    domain TEXT NOT NULL,
    name TEXT NOT NULL DEFAULT '@',
    rtype TEXT NOT NULL,
    value TEXT NOT NULL,
    ttl INTEGER NOT NULL DEFAULT 3600,
    priority INTEGER,
    status TEXT NOT NULL DEFAULT 'active',
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE(domain_id, name, rtype, value)
);

CREATE TABLE IF NOT EXISTS cron_jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id INTEGER NOT NULL REFERENCES accounts(id),
    domain_id INTEGER REFERENCES domains(id),
    domain TEXT,
    schedule TEXT NOT NULL,
    command TEXT NOT NULL,
    description TEXT,
    status TEXT NOT NULL DEFAULT 'active',
    last_run TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);