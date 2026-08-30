use std::path::PathBuf;

use serde::Serialize;

const DEFAULT_INDEX: &str = r#"<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Welcome to FPanel</title>
</head>
<body style="font-family: system-ui, sans-serif; background:#f6f8fa; display:grid; place-items:center; height:100vh; margin:0">
  <main style="text-align:center">
    <h1 style="color:#0f172a">Your website is ready</h1>
    <p style="color:#475569">This is the default page for this domain. Upload your files or install an application to replace it.</p>
  </main>
</body>
</html>
"#;

#[derive(Debug, Serialize)]
pub struct Vhost {
    pub domain: String,
    pub account: String,
    pub root: String,
}

#[derive(Debug, Serialize)]
pub struct RedirectEntry {
    pub from: String,
    pub to: String,
    pub permanent: bool,
}

#[derive(Debug, Serialize, Clone)]
pub struct MailAccount {
    pub local: String,
    pub password_hash: Option<String>,
    pub forward_to: Option<String>,
    pub quota_mb: i64,
}

#[derive(Debug, Serialize, Clone)]
pub struct MailForwarder {
    pub from: String,
    pub to: Vec<String>,
}

#[derive(Debug, Serialize, Clone)]
pub struct MailAutoresponder {
    pub local: String,
    pub subject: String,
    pub body: String,
}

#[derive(Debug, Serialize, Clone)]
pub struct MailDefault {
    pub action: String,
    pub to: Option<String>,
}

#[derive(Debug, Serialize)]
pub struct SslProvision {
    pub domain: String,
    pub cert: String,
    pub key: String,
    pub ca: Option<String>,
    pub issuer: String,
    pub valid_from: String,
    pub valid_to: String,
}

#[derive(Debug, Serialize)]
pub struct RuntimeProvision {
    pub domain: String,
    pub app: String,
    pub runtime: String,
    pub entrypoint: String,
    pub target: String,
    pub auto_restart: bool,
}

#[derive(Debug, Serialize)]
pub struct PhpProvision {
    pub domain: String,
    pub version: String,
    pub handler: String,
    pub ini: Option<serde_json::Value>,
}

#[derive(Debug, Serialize, Clone)]
pub struct HotlinkCfg {
    pub enabled: bool,
    pub extensions: String,
    pub allow_empty: bool,
    pub allowed: Vec<String>,
}

#[derive(Debug, Serialize, Clone)]
pub struct WafRule {
    pub id: String,
    pub name: String,
    pub severity: String,
}

#[derive(Debug, Serialize, Clone)]
pub struct WafCfg {
    pub enabled: bool,
    pub mode: String,
    pub patterns: Vec<String>,
}

#[derive(Debug, Serialize)]
pub struct SecurityProvision {
    pub domain: String,
    pub blocked_ips: Vec<String>,
    pub hotlink: Option<HotlinkCfg>,
    pub waf: Option<WafCfg>,
}

#[derive(Debug, Serialize)]
pub struct SshProvision {
    pub account: String,
    pub username: String,
    pub auth_type: String,
    pub public_key: Option<String>,
    pub authorized_keys: Option<String>,
    pub status: String,
}

#[derive(Debug, Serialize)]
pub struct MailProvision {
    pub domain: String,
    pub accounts: Vec<MailAccount>,
    pub forwarders: Vec<MailForwarder>,
    pub autoresponders: Vec<MailAutoresponder>,
    pub default: Option<MailDefault>,
}

pub fn data_dir() -> PathBuf {
    std::env::var("FPANEL_DATA")
        .map(PathBuf::from)
        .unwrap_or_else(|_| PathBuf::from(env!("CARGO_MANIFEST_DIR")).join("data"))
}

pub fn ssh_dir() -> PathBuf {
    std::env::var("FPANEL_SSH")
        .map(PathBuf::from)
        .unwrap_or_else(|_| {
            std::env::var("FPANEL_DATA")
                .map(|d| PathBuf::from(d).join("ssh"))
                .unwrap_or_else(|_| PathBuf::from(env!("CARGO_MANIFEST_DIR")).join("data").join("ssh"))
        })
}

pub fn dns_dir() -> PathBuf {
    std::env::var("FPANEL_DNS")
        .map(PathBuf::from)
        .unwrap_or_else(|_| data_dir().join("dns"))
}

pub fn public_ip() -> String {
    std::env::var("FPANEL_PUBLIC_IP").unwrap_or_else(|_| "127.0.0.1".into())
}

pub fn default_ns1() -> String {
    std::env::var("FPANEL_NS1").unwrap_or_else(|_| "ns1.fpanel.my.id".into())
}

pub fn default_ns2() -> String {
    std::env::var("FPANEL_NS2").unwrap_or_else(|_| "ns2.fpanel.my.id".into())
}

pub fn nsd_zones_dir() -> PathBuf {
    std::env::var("FPANEL_NSD_ZONES")
        .map(PathBuf::from)
        .unwrap_or_else(|_| PathBuf::from("/etc/nsd/zones"))
}

pub fn nsd_conf_dir() -> PathBuf {
    std::env::var("FPANEL_NSD_CONF")
        .map(PathBuf::from)
        .unwrap_or_else(|_| PathBuf::from("/etc/nsd/nsd.conf.d"))
}

pub fn vhosts_dir() -> PathBuf {
    std::env::var("FPANEL_VHOSTS")
        .map(PathBuf::from)
        .unwrap_or_else(|_| PathBuf::from(env!("CARGO_MANIFEST_DIR")).join("vhosts"))
}

/// Base directory holding all account homes (cPanel-style). Default `/home`.
pub fn home_dir() -> PathBuf {
    std::env::var("FPANEL_HOME")
        .map(PathBuf::from)
        .unwrap_or_else(|_| PathBuf::from("/home"))
}

/// cPanel-style account home: `/home/<username>`.
pub fn account_home(username: &str) -> PathBuf {
    home_dir().join(username)
}

/// Document root of the primary domain: `/home/<username>/public_html`.
pub fn account_htdocs(username: &str) -> PathBuf {
    account_home(username).join("public_html")
}

/// cPanel-style layout:
/// - main (primary) and alias (parked) -> /home/<username>/public_html
/// - sub (subdomain)                   -> /home/<username>/<sub>
/// - addon (extra/added domain)        -> /home/<username>/<domain>
pub fn vhost_root(username: &str, kind: &str, domain: &str) -> PathBuf {
    match kind {
        "sub" | "addon" => account_home(username).join(domain.trim_matches('.')),
        _ => account_htdocs(username),
    }
}

/// Creates the cPanel-style account home skeleton (public_html, cgi-bin) and
/// replaces the default page if it is still the placeholder. Returns the home path.
pub fn ensure_web_dirs(username: &str) -> std::io::Result<PathBuf> {
    let home = account_home(username);
    std::fs::create_dir_all(&home)?;
    std::fs::create_dir_all(home.join("cgi-bin"))?;

    let htdocs = home.join("public_html");
    std::fs::create_dir_all(&htdocs)?;
    write_placeholder_if_missing(&htdocs.join("index.html"))?;

    let _ = run_chown(&home, "-R", "www-data:www-data");
    Ok(home)
}

/// Ensures the document root for a domain exists with a welcome page if new.
pub fn ensure_doc_root(username: &str, kind: &str, domain: &str) -> std::io::Result<PathBuf> {
    let root = vhost_root(username, kind, domain);
    std::fs::create_dir_all(&root)?;
    write_placeholder_if_missing(&root.join("index.html"))?;
    let _ = run_chown(&root, "-R", "www-data:www-data");
    Ok(root)
}

/// Removes the default welcome page at `root/index.html` so application
/// installers (WordPress, Laravel, OJS) do not ship an extra index.html that
/// would shadow their real index.php.
pub fn remove_placeholder(root: &std::path::Path) {
    let idx = root.join("index.html");
    let _ = std::fs::remove_file(&idx);
}

/// Points an existing vhost to a different document root and persists it so
/// the web server reloads it (used after installing Laravel, which serves
/// from the `public/` subdirectory).
pub fn set_vhost_root(name: &str, root: &str) -> Result<(), String> {
    let path = vhost_path(name);
    let text = std::fs::read_to_string(&path).map_err(|e| e.to_string())?;
    let mut val: serde_json::Value = serde_json::from_str(&text).map_err(|e| e.to_string())?;
    val["root"] = serde_json::Value::String(root.to_string());
    let bytes = serde_json::to_vec_pretty(&val).map_err(|e| e.to_string())?;
    atomic_write(&path, &bytes).map_err(|e| e.to_string())?;
    let _ = std::fs::create_dir_all(root);
    tracing::info!("[provision] vhost {name} root -> {root}");
    Ok(())
}

fn write_placeholder_if_missing(path: &std::path::Path) -> std::io::Result<()> {
    if !path.exists() {
        std::fs::write(path, DEFAULT_INDEX)
    } else {
        Ok(())
    }
}

fn run_chown(path: &std::path::Path, flags: &str, owner: &str) -> std::io::Result<()> {
    std::process::Command::new("chown")
        .arg(flags)
        .arg(owner)
        .arg(path)
        .status()
        .map(|_| ())
}

fn vhost_path(name: &str) -> PathBuf {
    vhosts_dir().join(format!("{name}.json"))
}

fn redirects_path(name: &str) -> PathBuf {
    vhosts_dir().join(format!("{name}.redirects.json"))
}

fn mail_path(name: &str) -> PathBuf {
    vhosts_dir().join(format!("{name}.mail.json"))
}

pub fn certs_dir() -> PathBuf {
    std::env::var("FPANEL_CERTS")
        .map(PathBuf::from)
        .unwrap_or_else(|_| data_dir().join("certs"))
}

fn ssl_path(name: &str) -> PathBuf {
    vhosts_dir().join(format!("{name}.ssl.json"))
}

fn runtime_path(name: &str) -> PathBuf {
    vhosts_dir().join(format!("{name}.runtime.json"))
}

fn php_path(name: &str) -> PathBuf {
    vhosts_dir().join(format!("{name}.php.json"))
}

fn security_path(name: &str) -> PathBuf {
    vhosts_dir().join(format!("{name}.security.json"))
}

fn ssh_path(account: &str) -> PathBuf {
    ssh_dir().join(format!("{account}.ssh.json"))
}

pub fn runtime_log_file(name: &str) -> PathBuf {
    std::env::var("FPANEL_RUNTIME_LOGS")
        .map(PathBuf::from)
        .unwrap_or_else(|_| data_dir().join("runtime_logs").join(format!("{name}.log")))
}

fn cert_file(name: &str) -> PathBuf {
    certs_dir().join(format!("{name}.crt"))
}

fn key_file(name: &str) -> PathBuf {
    certs_dir().join(format!("{name}.key"))
}

fn ca_file(name: &str) -> PathBuf {
    certs_dir().join(format!("{name}.ca"))
}

fn ensure_parent(path: &PathBuf) {
    if let Some(parent) = path.parent() {
        let _ = std::fs::create_dir_all(parent);
    }
}

fn atomic_write(path: &std::path::Path, bytes: &[u8]) -> std::io::Result<()> {
    let tmp = path.with_extension(format!(
        "{}.tmp",
        path.extension().and_then(|e| e.to_str()).unwrap_or("json")
    ));
    std::fs::write(&tmp, bytes)?;
    std::fs::rename(&tmp, path)
}

pub fn write_ssl(name: &str, prov: &SslProvision) {
    let crt = cert_file(name);
    let key = key_file(name);
    ensure_parent(&crt);
    ensure_parent(&key);

    let write = |path: &PathBuf, content: &str| -> bool {
        match atomic_write(path, content.as_bytes()) {
            Ok(_) => true,
            Err(e) => {
                tracing::warn!("[provision] failed to write ssl file {}: {e}", path.display());
                false
            }
        }
    };

    if !write(&crt, &prov.cert) || !write(&key, &prov.key) {
        return;
    }
    let ca = match &prov.ca {
        Some(ca) if !ca.trim().is_empty() => {
            if write(&ca_file(name), ca) {
                Some(ca_file(name).to_string_lossy().into_owned())
            } else {
                None
            }
        }
        _ => None,
    };

    let sidecar = SslProvision {
        domain: prov.domain.clone(),
        cert: crt.to_string_lossy().into_owned(),
        key: key.to_string_lossy().into_owned(),
        ca: ca.or(prov.ca.clone().map(|_| ca_file(name).to_string_lossy().into_owned())),
        issuer: prov.issuer.clone(),
        valid_from: prov.valid_from.clone(),
        valid_to: prov.valid_to.clone(),
    };
    match serde_json::to_vec_pretty(&sidecar) {
        Ok(bytes) => {
            let path = ssl_path(name);
            ensure_parent(&path);
            match atomic_write(&path, &bytes) {
                Ok(_) => tracing::info!("[provision] ssl {name} -> {}", crt.display()),
                Err(e) => tracing::warn!("[provision] failed to write ssl meta {name}: {e}"),
            }
        }
        Err(e) => tracing::warn!("[provision] failed to serialize ssl {name}: {e}"),
    }
}

pub fn remove_ssl(name: &str) {
    for path in [ssl_path(name), cert_file(name), key_file(name), ca_file(name)] {
        match std::fs::remove_file(&path) {
            Ok(_) => tracing::info!("[provision] removed ssl file {}", path.display()),
            Err(e) if e.kind() == std::io::ErrorKind::NotFound => {}
            Err(e) => tracing::warn!("[provision] failed to remove {}: {e}", path.display()),
        }
    }
}

pub fn write_redirects(name: &str, entries: &[RedirectEntry]) {
    let path = redirects_path(name);
    if let Some(parent) = path.parent() {
        let _ = std::fs::create_dir_all(parent);
    }
    if entries.is_empty() {
        return remove_redirects(name);
    }
    let bytes = match serde_json::to_vec_pretty(entries) {
        Ok(b) => b,
        Err(e) => {
            tracing::warn!("[provision] failed to serialize redirects {name}: {e}");
            return;
        }
    };
    match atomic_write(&path, &bytes) {
        Ok(_) => tracing::info!("[provision] redirects {} ({} rules)", name, entries.len()),
        Err(e) => tracing::warn!("[provision] failed to write redirects {name}: {e}"),
    }
}

pub fn remove_redirects(name: &str) {
    let path = redirects_path(name);
    match std::fs::remove_file(&path) {
        Ok(_) => tracing::info!("[provision] removed redirects {name}"),
        Err(e) if e.kind() == std::io::ErrorKind::NotFound => {}
        Err(e) => tracing::warn!("[provision] failed to remove redirects {name}: {e}"),
    }
}

pub fn write_mail(name: &str, prov: &MailProvision) {
    let path = mail_path(name);
    if let Some(parent) = path.parent() {
        let _ = std::fs::create_dir_all(parent);
    }
    let empty = prov.accounts.is_empty()
        && prov.forwarders.is_empty()
        && prov.autoresponders.is_empty()
        && prov.default.is_none();
    if empty {
        return remove_mail(name);
    }
    let bytes = match serde_json::to_vec_pretty(prov) {
        Ok(b) => b,
        Err(e) => {
            tracing::warn!("[provision] failed to serialize mail {name}: {e}");
            return;
        }
    };
    match atomic_write(&path, &bytes) {
        Ok(_) => tracing::info!(
            "[provision] mail {} ({} accounts, {} forwarders, {} autoresponders)",
            name,
            prov.accounts.len(),
            prov.forwarders.len(),
            prov.autoresponders.len()
        ),
        Err(e) => tracing::warn!("[provision] failed to write mail {name}: {e}"),
    }
}

pub fn remove_mail(name: &str) {
    let path = mail_path(name);
    match std::fs::remove_file(&path) {
        Ok(_) => tracing::info!("[provision] removed mail {name}"),
        Err(e) if e.kind() == std::io::ErrorKind::NotFound => {}
        Err(e) => tracing::warn!("[provision] failed to remove mail {name}: {e}"),
    }
}

pub fn write_runtime(name: &str, prov: &RuntimeProvision) {
    let path = runtime_path(name);
    if let Some(parent) = path.parent() {
        let _ = std::fs::create_dir_all(parent);
    }
    let bytes = match serde_json::to_vec_pretty(prov) {
        Ok(b) => b,
        Err(e) => {
            tracing::warn!("[provision] failed to serialize runtime {name}: {e}");
            return;
        }
    };
    match atomic_write(&path, &bytes) {
        Ok(_) => tracing::info!("[provision] runtime {} -> {}", name, prov.target),
        Err(e) => tracing::warn!("[provision] failed to write runtime {name}: {e}"),
    }
}

pub fn remove_runtime(name: &str) {
    let path = runtime_path(name);
    match std::fs::remove_file(&path) {
        Ok(_) => tracing::info!("[provision] removed runtime {name}"),
        Err(e) if e.kind() == std::io::ErrorKind::NotFound => {}
        Err(e) => tracing::warn!("[provision] failed to remove runtime {name}: {e}"),
    }
}

pub fn write_php(name: &str, prov: &PhpProvision) {
    let path = php_path(name);
    if let Some(parent) = path.parent() {
        let _ = std::fs::create_dir_all(parent);
    }
    let bytes = match serde_json::to_vec_pretty(prov) {
        Ok(b) => b,
        Err(e) => {
            tracing::warn!("[provision] failed to serialize php {name}: {e}");
            return;
        }
    };
    match atomic_write(&path, &bytes) {
        Ok(_) => tracing::info!("[provision] php {name} -> {} ({})", prov.version, prov.handler),
        Err(e) => tracing::warn!("[provision] failed to write php {name}: {e}"),
    }
}

pub fn remove_php(name: &str) {
    let path = php_path(name);
    match std::fs::remove_file(&path) {
        Ok(_) => tracing::info!("[provision] removed php {name}"),
        Err(e) if e.kind() == std::io::ErrorKind::NotFound => {}
        Err(e) => tracing::warn!("[provision] failed to remove php {name}: {e}"),
    }
}

pub fn write_security(name: &str, prov: &SecurityProvision) {
    let path = security_path(name);
    let bytes = match serde_json::to_vec_pretty(prov) {
        Ok(b) => b,
        Err(e) => {
            tracing::warn!("[provision] failed to serialize security {name}: {e}");
            return;
        }
    };
    match atomic_write(&path, &bytes) {
        Ok(_) => tracing::info!("[provision] security {name} updated"),
        Err(e) => tracing::warn!("[provision] failed to write security {name}: {e}"),
    }
}

pub fn remove_security(name: &str) {
    let path = security_path(name);
    match std::fs::remove_file(&path) {
        Ok(_) => tracing::info!("[provision] removed security {name}"),
        Err(e) if e.kind() == std::io::ErrorKind::NotFound => {}
        Err(e) => tracing::warn!("[provision] failed to remove security {name}: {e}"),
    }
}

pub fn write_ssh(account: &str, prov: &SshProvision) {
    if let Some(parent) = ssh_path(account).parent() {
        let _ = std::fs::create_dir_all(parent);
    }
    let bytes = match serde_json::to_vec_pretty(prov) {
        Ok(b) => b,
        Err(e) => {
            tracing::warn!("[provision] failed to serialize ssh {account}: {e}");
            return;
        }
    };
    match atomic_write(&ssh_path(account), &bytes) {
        Ok(_) => tracing::info!("[provision] ssh {account} updated"),
        Err(e) => tracing::warn!("[provision] failed to write ssh {account}: {e}"),
    }
}

pub fn remove_ssh(account: &str) {
    let path = ssh_path(account);
    match std::fs::remove_file(&path) {
        Ok(_) => tracing::info!("[provision] removed ssh {account}"),
        Err(e) if e.kind() == std::io::ErrorKind::NotFound => {}
        Err(e) => tracing::warn!("[provision] failed to remove ssh {account}: {e}"),
    }
}

pub fn write_vhost(name: &str, username: &str, kind: &str, docroot_override: Option<&str>) {
    let path = vhost_path(name);
    if let Some(parent) = path.parent() {
        let _ = std::fs::create_dir_all(parent);
    }
    let root = docroot_override
        .filter(|r| !r.is_empty())
        .map(PathBuf::from)
        .unwrap_or_else(|| vhost_root(username, kind, name));
    let vhost = Vhost {
        domain: name.to_string(),
        account: username.to_string(),
        root: root.to_string_lossy().into_owned(),
    };
    let bytes = match serde_json::to_vec_pretty(&vhost) {
        Ok(b) => b,
        Err(e) => {
            tracing::warn!("[provision] failed to serialize vhost {name}: {e}");
            return;
        }
    };
    match atomic_write(&path, &bytes) {
        Ok(_) => tracing::info!("[provision] vhost {} -> {}", name, root.display()),
        Err(e) => tracing::warn!("[provision] failed to write vhost {name}: {e}"),
    }
    let _ = std::fs::create_dir_all(&root);
}

pub fn remove_vhost(name: &str) {
    let path = vhost_path(name);
    match std::fs::remove_file(&path) {
        Ok(_) => tracing::info!("[provision] removed vhost {name}"),
        Err(e) if e.kind() == std::io::ErrorKind::NotFound => {}
        Err(e) => tracing::warn!("[provision] failed to remove vhost {name}: {e}"),
    }
}