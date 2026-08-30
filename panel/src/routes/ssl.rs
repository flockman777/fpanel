use std::io::Write;
use std::path::PathBuf;
use std::process::{Command, Stdio};
use std::sync::OnceLock;
use tokio::sync::Mutex as AsyncMutex;

use axum::extract::{Path, Query, State};
use axum::http::{HeaderMap, StatusCode};
use axum::routing::{delete, get, post};
use axum::{Json, Router};
use chrono::{DateTime, NaiveDateTime, SecondsFormat, TimeZone, Utc};
use serde::{Deserialize, Serialize};
use sqlx::Row;

use crate::db::AppState;
use crate::error::{internal_error, ApiError};
use crate::provision;

#[derive(Debug, Serialize)]
pub struct SslRow {
    pub domain_id: i64,
    pub account_id: i64,
    pub domain: String,
    pub kind: String,
    pub cert_id: Option<i64>,
    pub issuer: Option<String>,
    pub valid_from: Option<String>,
    pub valid_to: Option<String>,
    pub days_left: Option<i64>,
    pub status: String,
}

#[derive(Debug, Serialize)]
pub struct SslRowAdmin {
    pub domain_id: i64,
    pub account_id: i64,
    pub username: String,
    pub domain: String,
    pub kind: String,
    pub cert_id: Option<i64>,
    pub issuer: Option<String>,
    pub valid_from: Option<String>,
    pub valid_to: Option<String>,
    pub days_left: Option<i64>,
    pub status: String,
}

#[derive(Debug, Deserialize)]
pub struct ImportCert {
    pub domain_id: i64,
    pub cert: String,
    pub key: String,
    pub ca: Option<String>,
    pub account_id: Option<i64>,
}

#[derive(Debug, Deserialize)]
pub struct GenerateCert {
    pub domain_id: i64,
    pub days: Option<i64>,
    pub account_id: Option<i64>,
}

#[derive(Debug, Deserialize)]
pub struct AdminPathQ {
    pub account_id: Option<i64>,
}

#[derive(Debug, Deserialize)]
pub struct AutoSsl {
    pub account_id: Option<i64>,
    pub domain_id: Option<i64>,
}

#[derive(Debug, Serialize)]
pub struct AutoResult {
    pub domain: String,
    pub ok: bool,
    pub message: String,
}

pub fn router() -> Router<AppState> {
    Router::new()
        .route("/", get(list_admin))
        .route("/autossl", post(autossl_admin))
        .route("/import", post(import_admin))
        .route("/generate", post(generate_admin))
        .route("/{id}", delete(delete_admin))
}

pub fn client_router() -> Router<AppState> {
    Router::new()
        .route("/", get(list_client))
        .route("/autossl", post(autossl_client))
        .route("/import", post(import_client))
        .route("/generate", post(generate_client))
        .route("/{id}", delete(delete_client))
}

// ---------- clients ----------

async fn list_client(
    State(state): State<AppState>,
    headers: HeaderMap,
) -> Result<Json<Vec<SslRow>>, ApiError> {
    let (aid, _) = super::domains::bearer_account(&state, &headers).await?;
    Ok(Json(list(&state, aid).await?))
}

async fn import_client(
    State(state): State<AppState>,
    headers: HeaderMap,
    Json(body): Json<ImportCert>,
) -> Result<(StatusCode, Json<SslRow>), ApiError> {
    let (aid, _) = super::domains::bearer_account(&state, &headers).await?;
    let row = import_cert(&state, aid, &body).await?;
    Ok((StatusCode::CREATED, Json(row)))
}

async fn generate_client(
    State(state): State<AppState>,
    headers: HeaderMap,
    Json(body): Json<GenerateCert>,
) -> Result<(StatusCode, Json<SslRow>), ApiError> {
    let (aid, _) = super::domains::bearer_account(&state, &headers).await?;
    let row = generate_cert(&state, aid, &body).await?;
    Ok((StatusCode::CREATED, Json(row)))
}

async fn delete_client(
    State(state): State<AppState>,
    headers: HeaderMap,
    Path((_sess, id)): Path<(String, i64)>,
) -> Result<StatusCode, ApiError> {
    let (aid, _) = super::domains::bearer_account(&state, &headers).await?;
    drop_cert(&state, aid, id).await?;
    Ok(StatusCode::NO_CONTENT)
}

// ---------- admins ----------

fn require_account(aid: Option<i64>) -> Result<i64, ApiError> {
    aid.ok_or_else(|| ApiError::new(StatusCode::BAD_REQUEST, "account_id is required"))
}

async fn account_username(state: &AppState, aid: i64) -> Result<String, ApiError> {
    sqlx::query_scalar::<_, String>("SELECT username FROM accounts WHERE id = ?")
        .bind(aid)
        .fetch_optional(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?
        .ok_or_else(|| ApiError::new(StatusCode::NOT_FOUND, "Account not found"))
}

async fn list_admin(
    State(state): State<AppState>,
    Query(q): Query<AdminPathQ>,
) -> Result<Json<Vec<SslRowAdmin>>, ApiError> {
    let aid = require_account(q.account_id)?;
    let username = account_username(&state, aid).await?;
    let rows = list(&state, aid).await?;
    Ok(Json(
        rows.into_iter()
            .map(|r| SslRowAdmin {
                username: username.clone(),
                domain_id: r.domain_id,
                account_id: aid,
                domain: r.domain,
                kind: r.kind,
                cert_id: r.cert_id,
                issuer: r.issuer,
                valid_from: r.valid_from,
                valid_to: r.valid_to,
                days_left: r.days_left,
                status: r.status,
            })
            .collect(),
    ))
}

async fn import_admin(
    State(state): State<AppState>,
    Json(body): Json<ImportCert>,
) -> Result<(StatusCode, Json<SslRowAdmin>), ApiError> {
    let aid = require_account(body.account_id)?;
    let username = account_username(&state, aid).await?;
    let row = import_cert(&state, aid, &body).await?;
    Ok((StatusCode::CREATED, Json(to_admin(aid, username, row))))
}

async fn generate_admin(
    State(state): State<AppState>,
    Json(body): Json<GenerateCert>,
) -> Result<(StatusCode, Json<SslRowAdmin>), ApiError> {
    let aid = require_account(body.account_id)?;
    let username = account_username(&state, aid).await?;
    let row = generate_cert(&state, aid, &body).await?;
    Ok((StatusCode::CREATED, Json(to_admin(aid, username, row))))
}

async fn delete_admin(
    State(state): State<AppState>,
    Query(q): Query<AdminPathQ>,
    Path((_sess, id)): Path<(String, i64)>,
) -> Result<StatusCode, ApiError> {
    let aid = require_account(q.account_id)?;
    drop_cert(&state, aid, id).await?;
    Ok(StatusCode::NO_CONTENT)
}

fn to_admin(aid: i64, username: String, row: SslRow) -> SslRowAdmin {
    SslRowAdmin {
        username,
        domain_id: row.domain_id,
        account_id: aid,
        domain: row.domain,
        kind: row.kind,
        cert_id: row.cert_id,
        issuer: row.issuer,
        valid_from: row.valid_from,
        valid_to: row.valid_to,
        days_left: row.days_left,
        status: row.status,
    }
}

// ---------- AutoSSL (Let's Encrypt) ----------

fn acme_lock() -> &'static AsyncMutex<()> {
    static LOCK: OnceLock<AsyncMutex<()>> = OnceLock::new();
    LOCK.get_or_init(|| AsyncMutex::new(()))
}

fn acme_email() -> String {
    std::env::var("FPANEL_ACME_EMAIL").unwrap_or_else(|_| "admin@fpanel.my.id".into())
}

fn acme_live_path(domain: &str) -> PathBuf {
    std::env::var("FPANEL_ACME_LIVE")
        .map(PathBuf::from)
        .unwrap_or_else(|_| PathBuf::from("/etc/letsencrypt/live"))
        .join(domain)
}

fn domain_webroot(domain: &str) -> Option<String> {
    let path = crate::provision::vhosts_dir().join(format!("{domain}.json"));
    let content = std::fs::read_to_string(&path).ok()?;
    serde_json::from_str::<serde_json::Value>(&content)
        .ok()
        .and_then(|v| v.get("root").and_then(|r| r.as_str()).map(|s| s.to_string()))
}

async fn autossl_admin(
    State(state): State<AppState>,
    Json(body): Json<AutoSsl>,
) -> Result<Json<Vec<AutoResult>>, ApiError> {
    if let Some(aid) = body.account_id {
        return Ok(Json(autossl_account(&state, aid, body.domain_id).await?));
    }
    let accounts: Vec<i64> = sqlx::query_scalar(
        "SELECT id FROM accounts WHERE status = 'active' ORDER BY id",
    )
    .fetch_all(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?;
    let mut all = Vec::new();
    for aid in accounts {
        all.extend(autossl_account(&state, aid, body.domain_id).await?);
    }
    Ok(Json(all))
}

async fn autossl_client(
    State(state): State<AppState>,
    headers: HeaderMap,
    Json(body): Json<AutoSsl>,
) -> Result<Json<Vec<AutoResult>>, ApiError> {
    let (aid, _) = super::domains::bearer_account(&state, &headers).await?;
    Ok(Json(autossl_account(&state, aid, body.domain_id).await?))
}

pub async fn autossl_all(state: &AppState) -> Result<(), String> {
    let accounts: Vec<i64> = sqlx::query_scalar("SELECT id FROM accounts WHERE status = 'active'")
        .fetch_all(&state.db)
        .await
        .map_err(|e| e.to_string())?;
    for aid in accounts {
        match autossl_account(state, aid, None).await {
            Ok(results) => {
                for r in results {
                    tracing::info!("[autossl] {} -> {}", r.domain, if r.ok { "ok" } else { &r.message });
                }
            }
            Err(e) => tracing::warn!("[autossl] account {aid}: {}", e.message),
        }
    }
    Ok(())
}

async fn autossl_account(
    state: &AppState,
    aid: i64,
    only_domain: Option<i64>,
) -> Result<Vec<AutoResult>, ApiError> {
    let username = account_username(state, aid).await?;
    let mut rows = sqlx::query(
        "SELECT id, name FROM domains WHERE account_id = ? AND status = 'active' ORDER BY name",
    )
    .bind(aid)
    .fetch_all(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?;
    if let Some(did) = only_domain {
        rows.retain(|r| r.get::<i64, _>(0) == did);
    }

    let _guard = acme_lock().lock().await;
    let mut results = Vec::with_capacity(rows.len());
    for r in &rows {
        let domain: String = r.get(1);
        match issue_and_store(state, aid, &username, &domain).await {
            Ok(msg) => results.push(AutoResult {
                domain: domain.clone(),
                ok: true,
                message: msg,
            }),
            Err(e) => results.push(AutoResult {
                domain: domain.clone(),
                ok: false,
                message: e.message.clone(),
            }),
        }
    }
    Ok(results)
}

async fn issue_and_store(
    state: &AppState,
    aid: i64,
    username: &str,
    domain: &str,
) -> Result<String, ApiError> {
    let current = list(state, aid)
        .await?
        .into_iter()
        .find(|s| s.domain == domain);
    if let Some(s) = current {
        if s.status == "active" && s.days_left.unwrap_or(0) > 30 {
            return Ok(format!("Certificate valid ({}) days left", s.days_left.unwrap_or(0)));
        }
    }

    let webroot = match domain_webroot(domain) {
        Some(w) => w,
        None => crate::provision::account_htdocs(username).to_string_lossy().into_owned(),
    };
    std::fs::create_dir_all(&webroot).map_err(|e| internal_error(e.into()))?;

    let email = acme_email();
    let domain_owned_arg = domain.to_string();
    let root_arg = webroot.clone();
    let out = tokio::task::spawn_blocking(move || {
        Command::new("certbot")
            .args([
                "certonly",
                "--webroot",
                "-w",
                &root_arg,
                "-d",
                &domain_owned_arg,
                "--non-interactive",
                "--agree-tos",
                "--no-eff-email",
                "-m",
                &email,
                "--keep-until-expiring",
                "--preferred-challenges",
                "http",
            ])
            .output()
    })
    .await
    .map_err(|e| internal_error(e.into()))?;

    let out = out.map_err(|e| ApiError::new(StatusCode::INTERNAL_SERVER_ERROR, format!("certbot unavailable: {e}")))?;
    if !out.status.success() {
        let err = String::from_utf8_lossy(&out.stderr).trim().to_string();
        let tail = err.lines().rev().take(6).collect::<Vec<_>>().join("\n");
        return Err(ApiError::new(
            StatusCode::BAD_GATEWAY,
            format!("certbot failed for {domain}: {tail}"),
        ));
    }

    let live = acme_live_path(domain);
    let cert_pem = std::fs::read_to_string(live.join("fullchain.pem"))
        .map_err(|e| internal_error(e.into()))?;
    let key_pem = std::fs::read_to_string(live.join("privkey.pem"))
        .map_err(|e| internal_error(e.into()))?;

    let domain_id: i64 = sqlx::query_scalar("SELECT id FROM domains WHERE name = ? AND account_id = ?")
        .bind(domain)
        .bind(aid)
        .fetch_one(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?;

    store_cert(state, aid, domain_id, domain.to_string(), cert_pem, key_pem, None, true).await?;
    Ok("Let's Encrypt certificate installed".to_string())
}

// ---------- helpers ----------

async fn list(state: &AppState, aid: i64) -> Result<Vec<SslRow>, ApiError> {
    let rows = sqlx::query(
        "SELECT d.id, d.account_id, d.name, d.kind, s.id, s.issuer, s.valid_from, s.valid_to \
         FROM domains d LEFT JOIN ssl_certs s ON s.domain_id = d.id \
         WHERE d.account_id = ? ORDER BY d.name",
    )
    .bind(aid)
    .fetch_all(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?;

    let now = Utc::now();
    let mut out = Vec::with_capacity(rows.len());
    for r in rows {
        let valid_to: Option<String> = r.get(7);
        let status = if r.get::<Option<i64>, _>(4).is_some() {
            match &valid_to {
                Some(v) => match DateTime::parse_from_rfc3339(v) {
                    Ok(t) if t.with_timezone(&Utc) < now => "expired".to_string(),
                    _ => "active".to_string(),
                },
                None => "active".to_string(),
            }
        } else {
            "none".to_string()
        };
        let days_left: Option<i64> = match &valid_to {
            Some(v) => DateTime::parse_from_rfc3339(v)
                .ok()
                .map(|t| (t.with_timezone(&Utc) - now).num_days()),
            None => None,
        };
        out.push(SslRow {
            domain_id: r.get(0),
            account_id: r.get(1),
            domain: r.get(2),
            kind: r.get(3),
            cert_id: r.get(4),
            issuer: r.get(5),
            valid_from: r.get(6),
            valid_to,
            days_left,
            status,
        });
    }
    Ok(out)
}

async fn domain_owned(state: &AppState, aid: i64, domain_id: i64) -> Result<String, ApiError> {
    let name: Option<String> = sqlx::query_scalar(
        "SELECT name FROM domains WHERE id = ? AND account_id = ? AND status = 'active'",
    )
    .bind(domain_id)
    .bind(aid)
    .fetch_optional(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?;
    name.ok_or_else(|| ApiError::new(StatusCode::NOT_FOUND, "Domain not found for this account"))
}

struct TempDir(PathBuf);

impl TempDir {
    fn new() -> Result<TempDir, ApiError> {
        let dir = std::env::temp_dir().join(format!("fpanel_ssl_{}", uuid::Uuid::new_v4()));
        std::fs::create_dir_all(&dir).map_err(|e| internal_error(e.into()))?;
        Ok(TempDir(dir))
    }
    fn path(&self) -> &PathBuf {
        &self.0
    }
}

impl Drop for TempDir {
    fn drop(&mut self) {
        let _ = std::fs::remove_dir_all(&self.0);
    }
}

fn run_openssl(dir: &PathBuf, args: &[&str]) -> Result<String, ApiError> {
    let out = Command::new("openssl")
        .args(args)
        .current_dir(dir)
        .output()
        .map_err(|e| ApiError::new(StatusCode::INTERNAL_SERVER_ERROR, format!("openssl not available: {e}")))?;
    if !out.status.success() {
        let err = String::from_utf8_lossy(&out.stderr).trim().to_string();
        return Err(ApiError::new(
            StatusCode::BAD_REQUEST,
            format!("openssl failed: {err}"),
        ));
    }
    Ok(String::from_utf8_lossy(&out.stdout).to_string())
}

fn run_openssl_stdin(pem: &str, args: &[&str]) -> Result<String, ApiError> {
    let mut child = Command::new("openssl")
        .args(args)
        .stdin(Stdio::piped())
        .stdout(Stdio::piped())
        .stderr(Stdio::piped())
        .spawn()
        .map_err(|e| ApiError::new(StatusCode::INTERNAL_SERVER_ERROR, format!("openssl not available: {e}")))?;
    child
        .stdin
        .take()
        .unwrap()
        .write_all(pem.as_bytes())
        .map_err(|e| internal_error(e.into()))?;
    let out = child.wait_with_output().map_err(|e| internal_error(e.into()))?;
    if !out.status.success() {
        let err = String::from_utf8_lossy(&out.stderr).trim().to_string();
        return Err(ApiError::new(StatusCode::BAD_REQUEST, format!("openssl failed: {err}")));
    }
    Ok(String::from_utf8_lossy(&out.stdout).to_string())
}

#[derive(Debug)]
struct CertMeta {
    issuer: String,
    valid_from: String,
    valid_to: String,
}

fn parse_meta(cert_pem: &str) -> Result<CertMeta, ApiError> {
    let out = run_openssl_stdin(cert_pem, &["x509", "-noout", "-subject", "-issuer", "-dates"])?;
    let mut subject = String::new();
    let mut issuer = String::new();
    let mut nbf = String::new();
    let mut nafter = String::new();
    for line in out.lines() {
        let line = line.trim();
        if let Some(v) = line.strip_prefix("subject=") {
            subject = extract_cn(v).to_string();
        } else if let Some(v) = line.strip_prefix("issuer=") {
            issuer = extract_cn(v).to_string();
        } else if let Some(v) = line.strip_prefix("notBefore=") {
            nbf = date_to_iso(v)?;
        } else if let Some(v) = line.strip_prefix("notAfter=") {
            nafter = date_to_iso(v)?;
        }
    }
    if subject.is_empty() || issuer.is_empty() || nbf.is_empty() || nafter.is_empty() {
        return Err(ApiError::new(
            StatusCode::BAD_REQUEST,
            "Could not parse certificate (invalid PEM?)",
        ));
    }
    Ok(CertMeta { issuer, valid_from: nbf, valid_to: nafter })
}

fn extract_cn(subject: &str) -> &str {
    let s = subject.trim();
    if let Some(idx) = s.find("CN=") {
        let rest = &s[idx + 3..];
        let end = rest
            .find(|c: char| c == ',' || c == '/')
            .unwrap_or(rest.len());
        return rest[..end].trim();
    }
    if let Some(idx) = s.find("CN =") {
        let rest = &s[idx + 4..];
        let end = rest.find(',').unwrap_or(rest.len());
        return rest[..end].trim();
    }
    s
}

fn date_to_iso(s: &str) -> Result<String, ApiError> {
    let s = s.trim().trim_end_matches(" GMT");
    let dt = NaiveDateTime::parse_from_str(s, "%b %e %H:%M:%S %Y")
        .map_err(|e| ApiError::new(StatusCode::BAD_REQUEST, format!("Could not parse certificate date: {e}")))?;
    Ok(Utc
        .from_utc_datetime(&dt)
        .to_rfc3339_opts(SecondsFormat::Secs, false))
}

fn cert_names(cert_pem: &str) -> Vec<String> {
    let mut names: Vec<String> = vec![];
    match run_openssl_stdin(cert_pem, &["x509", "-noout", "-ext", "subjectAltName"]) {
        Ok(out) => {
            for line in out.lines() {
                for tok in line.split(',') {
                    let tok = tok.trim();
                    if let Some(v) = tok.strip_prefix("DNS:") {
                        names.push(v.to_lowercase());
                    }
                }
            }
        }
        Err(_) => {}
    }
    match run_openssl_stdin(cert_pem, &["x509", "-noout", "-subject"]) {
        Ok(out) => {
            for line in out.lines() {
                if let Some(v) = line.trim().strip_prefix("subject=") {
                    names.push(extract_cn(v).to_lowercase());
                }
            }
        }
        Err(_) => {}
    }
    names
}

fn covers(identity: &str, domain: &str) -> bool {
    let domain = domain.to_lowercase();
    let identity = identity.to_lowercase();
    if identity == domain {
        return true;
    }
    if let Some(rest) = identity.strip_prefix("*.") {
        let prefix = domain.strip_suffix(rest);
        if let Some(p) = prefix {
            if p.ends_with('.') {
                let labels = p[..p.len() - 1].split('.').count();
                return labels == 1;
            }
        }
    }
    false
}

fn pubkeys_match(cert_pem: &str, key_pem: &str) -> Result<bool, ApiError> {
    let dir = TempDir::new()?;
    let cert_file = dir.path().join("c.pem");
    let key_file = dir.path().join("k.pem");
    let _ = std::fs::write(&cert_file, cert_pem);
    let _ = std::fs::write(&key_file, key_pem);

    let cert_pub = run_openssl(dir.path(), &["x509", "-in", "c.pem", "-noout", "-pubkey"])?;
    let key_pub = match run_openssl(dir.path(), &["rsa", "-in", "k.pem", "-pubout"]) {
        Ok(o) => o,
        Err(_) => run_openssl(dir.path(), &["pkey", "-in", "k.pem", "-pubout"])?,
    };

    let c_digest = digest_pem(dir.path(), &cert_pub)?;
    let k_digest = digest_pem(dir.path(), &key_pub)?;
    Ok(c_digest == k_digest)
}

fn digest_pem(dir: &PathBuf, pem: &str) -> Result<String, ApiError> {
    let f = dir.join("pub.pem");
    let _ = std::fs::write(&f, pem);
    Ok(run_openssl(dir, &["sha256", "-r", "pub.pem"])?
        .split_whitespace()
        .next()
        .unwrap_or_default()
        .to_string())
}

async fn store_cert(
    state: &AppState,
    aid: i64,
    domain_id: i64,
    domain: String,
    cert_pem: String,
    key_pem: String,
    ca_pem: Option<String>,
    replace: bool,
) -> Result<SslRow, ApiError> {
    let meta = parse_meta(&cert_pem)?;
    let names = cert_names(&cert_pem);
    if !names.is_empty() && !names.iter().any(|n| covers(n, &domain)) {
        return Err(ApiError::new(
            StatusCode::BAD_REQUEST,
            format!("Certificate does not cover domain {domain}"),
        ));
    }
    if !pubkeys_match(&cert_pem, &key_pem)? {
        return Err(ApiError::new(
            StatusCode::BAD_REQUEST,
            "Private key does not match certificate",
        ));
    }

    let existing: Option<i64> =
        sqlx::query_scalar("SELECT id FROM ssl_certs WHERE domain_id = ?")
            .bind(domain_id)
            .fetch_optional(&state.db)
            .await
            .map_err(|e| internal_error(e.into()))?;

    let cert_id = if let Some(existing_id) = existing {
        if !replace {
            return Err(ApiError::new(
                StatusCode::CONFLICT,
                "A certificate for this domain already exists",
            ));
        }
        sqlx::query(
            "UPDATE ssl_certs SET account_id = ?, cert_pem = ?, key_pem = ?, ca_pem = ?, issuer = ?, \
             valid_from = ?, valid_to = ?, status = 'active' WHERE id = ?",
        )
        .bind(aid)
        .bind(&cert_pem)
        .bind(&key_pem)
        .bind(&ca_pem)
        .bind(&meta.issuer)
        .bind(&meta.valid_from)
        .bind(&meta.valid_to)
        .bind(existing_id)
        .execute(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?;
        existing_id
    } else {
        let result = sqlx::query(
            "INSERT INTO ssl_certs (account_id, domain_id, domain, cert_pem, key_pem, ca_pem, issuer, valid_from, valid_to) \
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
        )
        .bind(aid)
        .bind(domain_id)
        .bind(&domain)
        .bind(&cert_pem)
        .bind(&key_pem)
        .bind(&ca_pem)
        .bind(&meta.issuer)
        .bind(&meta.valid_from)
        .bind(&meta.valid_to)
        .execute(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?;
        result.last_insert_rowid()
    };

    provision::write_ssl(
        &domain,
        &provision::SslProvision {
            domain: domain.clone(),
            cert: cert_pem,
            key: key_pem,
            ca: ca_pem,
            issuer: meta.issuer.clone(),
            valid_from: meta.valid_from.clone(),
            valid_to: meta.valid_to.clone(),
        },
    );
    if let Err(e) = crate::nginx::ensure_https_vhost(&domain) {
        tracing::warn!("[ssl] nginx vhost failed for {domain}: {e}");
    }

    let days_left = DateTime::parse_from_rfc3339(&meta.valid_to)
        .ok()
        .map(|t| (t.with_timezone(&Utc) - Utc::now()).num_days());

    Ok(SslRow {
        domain_id,
        account_id: aid,
        domain,
        kind: String::new(),
        cert_id: Some(cert_id),
        issuer: Some(meta.issuer),
        valid_from: Some(meta.valid_from),
        valid_to: Some(meta.valid_to),
        days_left,
        status: "active".to_string(),
    })
}

async fn import_cert(state: &AppState, aid: i64, body: &ImportCert) -> Result<SslRow, ApiError> {
    let domain = domain_owned(state, aid, body.domain_id).await?;
    if body.cert.trim().is_empty() || body.key.trim().is_empty() {
        return Err(ApiError::new(
            StatusCode::BAD_REQUEST,
            "Certificate and private key are required",
        ));
    }
    store_cert(
        state,
        aid,
        body.domain_id,
        domain,
        body.cert.trim().to_string(),
        body.key.trim().to_string(),
        body.ca.as_deref().map(|c| c.trim().to_string()).filter(|c| !c.is_empty()),
        false,
    )
    .await
}

async fn generate_cert(state: &AppState, aid: i64, body: &GenerateCert) -> Result<SslRow, ApiError> {
    let domain = domain_owned(state, aid, body.domain_id).await?;
    let days = body.days.unwrap_or(365).max(1).min(3650);

    let dir = TempDir::new()?;
    let subj = format!("/CN={domain}");
    let san = format!("subjectAltName=DNS:{domain},DNS:www.{domain}");
    run_openssl(
        dir.path(),
        &[
            "req",
            "-x509",
            "-newkey",
            "rsa:2048",
            "-keyout",
            "k.pem",
            "-out",
            "c.pem",
            "-days",
            &days.to_string(),
            "-nodes",
            "-subj",
            &subj,
            "-addext",
            &san,
        ],
    )?;

    let cert_pem = std::fs::read_to_string(dir.path().join("c.pem"))
        .map_err(|e| internal_error(e.into()))?;
    let key_pem = std::fs::read_to_string(dir.path().join("k.pem"))
        .map_err(|e| internal_error(e.into()))?;

    store_cert(state, aid, body.domain_id, domain, cert_pem, key_pem, None, false).await
}

async fn drop_cert(state: &AppState, aid: i64, id: i64) -> Result<(), ApiError> {
    let domain: Option<String> =
        sqlx::query_scalar("SELECT domain FROM ssl_certs WHERE id = ? AND account_id = ?")
            .bind(id)
            .bind(aid)
            .fetch_optional(&state.db)
            .await
            .map_err(|e| internal_error(e.into()))?;
    let Some(domain) = domain else {
        return Err(ApiError::new(
            StatusCode::NOT_FOUND,
            "Certificate not found",
        ));
    };
    sqlx::query("DELETE FROM ssl_certs WHERE id = ?")
        .bind(id)
        .execute(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?;
    provision::remove_ssl(&domain);
    Ok(())
}