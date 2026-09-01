use axum::extract::State;
use axum::http::{header, StatusCode};
use axum::routing::get;
use axum::{Json, Router};
use serde::{Deserialize, Serialize};
use sqlx::FromRow;

use crate::auth::{create_token, verify_and_get_claims_with_state};
use crate::db::AppState;
use crate::error::{internal_error, ApiError};

#[derive(Debug, Serialize, Deserialize, FromRow)]
pub struct ClientAccount {
    pub id: i64,
    pub username: String,
    pub email: String,
    pub package_id: i64,
    pub status: String,
    pub name: Option<String>,
}

#[derive(Debug, Serialize)]
pub struct ClientLoginRes {
    pub token: String,
    pub sess: String,
    pub account: ClientAccount,
}

#[derive(Debug, Deserialize)]
pub struct ClientLoginReq {
    pub username: String,
    pub password: String,
}

#[derive(Debug, Serialize, FromRow)]
pub struct PackageRow {
    pub name: String,
    pub disk_limit_mb: i64,
    pub mailbox_limit: i64,
    pub database_limit: i64,
    pub domain_limit: i64,
    pub bandwidth_limit_gb: i64,
}

#[derive(Debug, Serialize)]
pub struct ClientSummary {
    pub account: ClientAccount,
    pub package: PackageRow,
    pub usage: Usage,
    pub primary_domain: Option<String>,
}

#[derive(Debug, Serialize)]
pub struct Usage {
    pub disk_used_mb: i64,
    pub domain_used: i64,
    pub subdomain_used: i64,
    pub database_used: i64,
    pub mailbox_used: i64,
}

pub fn router() -> Router<AppState> {
    Router::new()
        .route("/login", axum::routing::post(login))
        .route("/me", get(me))
        .route("/server-info", get(server_info))
}

pub async fn login(
    State(state): State<AppState>,
    Json(input): Json<ClientLoginReq>,
) -> Result<Json<ClientLoginRes>, ApiError> {
    let key = format!("client:{}", input.username.trim().to_lowercase());
    crate::routes::auth::check_login_rate(&key)?;

    let account = sqlx::query_as::<_, ClientAccount>(
        "SELECT * FROM accounts WHERE username = ? AND status = 'active'",
    )
    .bind(input.username.trim())
    .fetch_optional(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?
    .ok_or_else(|| ApiError::new(StatusCode::UNAUTHORIZED, "Invalid username or password"))?;

    let Some(hash) = sqlx::query_scalar::<_, Option<String>>(
        "SELECT password_hash FROM accounts WHERE id = ?",
    )
    .bind(account.id)
    .fetch_optional(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?
    .flatten()
    else {
        return Err(ApiError::new(
            StatusCode::UNAUTHORIZED,
            "Account has no password set by admin",
        ));
    };

    if !crate::auth::verify_password(&input.password, &hash) {
        crate::routes::auth::record_login_failure(&key);
        return Err(ApiError::new(
            StatusCode::UNAUTHORIZED,
            "Invalid username or password",
        ));
    }

    crate::routes::auth::clear_login_failures(&key);

    let (token, sess) = create_token(&state.jwt_secret, account.id, &account.username, "client")
        .map_err(|_| ApiError::new(StatusCode::INTERNAL_SERVER_ERROR, "Failed to create token"))?;

    Ok(Json(ClientLoginRes {
        token,
        sess,
        account,
    }))
}

async fn me(
    State(state): State<AppState>,
    headers: axum::http::HeaderMap,
) -> Result<Json<ClientSummary>, ApiError> {
    let Some(token) = headers
        .get(header::AUTHORIZATION)
        .and_then(|v| v.to_str().ok())
        .and_then(|v| v.strip_prefix("Bearer "))
    else {
        return Err(ApiError::new(StatusCode::UNAUTHORIZED, "Token not found"));
    };

    let claims = verify_and_get_claims_with_state(&state.jwt_secret, token).await?;
    if claims.role != "client" {
        return Err(ApiError::new(
            StatusCode::FORBIDDEN,
            "Only client accounts can access this",
        ));
    }

    let account = sqlx::query_as::<_, ClientAccount>("SELECT * FROM accounts WHERE id = ?")
        .bind(claims.sub)
        .fetch_optional(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?
        .ok_or_else(|| ApiError::new(StatusCode::NOT_FOUND, "Account not found"))?;

    let package = sqlx::query_as::<_, PackageRow>(
        "SELECT name, disk_limit_mb, mailbox_limit, database_limit, domain_limit, bandwidth_limit_gb FROM packages WHERE id = ?",
    )
    .bind(account.package_id)
    .fetch_optional(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?
    .ok_or_else(|| ApiError::new(StatusCode::NOT_FOUND, "Package not found"))?;

    let domain_used = sqlx::query_scalar::<_, i64>("SELECT COUNT(*) FROM domains WHERE account_id = ? AND kind = 'main'")
        .bind(account.id)
        .fetch_one(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?;
    let subdomain_used = sqlx::query_scalar::<_, i64>("SELECT COUNT(*) FROM domains WHERE account_id = ? AND kind = 'sub'")
        .bind(account.id)
        .fetch_one(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?;
    let database_used =
        sqlx::query_scalar::<_, i64>("SELECT COUNT(*) FROM databases WHERE account_id = ?")
            .bind(account.id)
            .fetch_one(&state.db)
            .await
            .map_err(|e| internal_error(e.into()))?;
    let mailbox_used =
        sqlx::query_scalar::<_, i64>("SELECT COUNT(*) FROM email_accounts WHERE account_id = ?")
            .bind(account.id)
            .fetch_one(&state.db)
            .await
            .map_err(|e| internal_error(e.into()))?;
    let disk_used_mb =
        crate::routes::stats::dir_size(&crate::provision::account_home(&account.username))
            / 1048576;

    let primary_domain = sqlx::query_scalar::<_, String>(
        "SELECT name FROM domains WHERE account_id = ? ORDER BY id ASC LIMIT 1",
    )
    .bind(account.id)
    .fetch_optional(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?;

    Ok(Json(ClientSummary {
        account,
        package,
        usage: Usage {
            disk_used_mb: disk_used_mb,
            domain_used: domain_used,
            subdomain_used: subdomain_used,
            database_used: database_used,
            mailbox_used: mailbox_used,
        },
        primary_domain,
    }))
}

#[derive(Debug, Serialize)]
pub struct ServiceStatus {
    pub name: String,
    pub status: String,
}

#[derive(Debug, Serialize)]
pub struct ServerInfo {
    pub os: String,
    pub kernel: String,
    pub arch: String,
    pub ip: String,
    pub server_name: String,
    pub php_version: String,
    pub nginx_version: String,
    pub mariadb_version: String,
    pub panel_version: String,
    pub disk_used: String,
    pub disk_total: String,
    pub disk_pct: String,
    pub mem_pct: String,
    pub load: String,
    pub services: Vec<ServiceStatus>,
}

fn cmd_out(cmd: &str, args: &[&str]) -> String {
    std::process::Command::new(cmd)
        .args(args)
        .output()
        .map(|o| String::from_utf8_lossy(&o.stdout).trim().to_string())
        .unwrap_or_default()
}

async fn server_info(
    State(state): State<AppState>,
    headers: axum::http::HeaderMap,
) -> Result<Json<ServerInfo>, ApiError> {
    let Some(token) = headers
        .get(header::AUTHORIZATION)
        .and_then(|v| v.to_str().ok())
        .and_then(|v| v.strip_prefix("Bearer "))
    else {
        return Err(ApiError::new(StatusCode::UNAUTHORIZED, "Token not found"));
    };
    let claims = verify_and_get_claims_with_state(&state.jwt_secret, token).await?;
    if claims.role != "client" {
        return Err(ApiError::new(StatusCode::FORBIDDEN, "Forbidden"));
    }

    let os = cmd_out("sh", &["-c", "grep PRETTY_NAME /etc/os-release | cut -d'\"' -f2"]);
    let kernel = cmd_out("uname", &["-r"]);
    let arch = cmd_out("uname", &["-m"]);
    let ip = cmd_out("sh", &["-c", "hostname -I | awk '{print $1}'"]);
    let server_name = cmd_out("hostname", &[]);

    let php_version = cmd_out("sh", &["-c", "php --version 2>/dev/null | head -1 | awk '{print $1\" \"$2}'"]);
    let nginx_version = cmd_out("sh", &["-c", "nginx -v 2>&1 | sed 's/nginx version: nginx\\//nginx /'"]);
    let mariadb_version = cmd_out("sh", &["-c", "mariadb --version 2>/dev/null | awk '{print $5}' | tr -d ','"]);

    // disk
    let disk_out = cmd_out("sh", &["-c", "df -h / | tail -1 | awk '{print $3\"|\"$2\"|\"$5}'"]);
    let parts: Vec<&str> = disk_out.split('|').collect();
    let disk_used = parts.first().unwrap_or(&"").to_string();
    let disk_total = parts.get(1).unwrap_or(&"").to_string();
    let disk_pct = parts.get(2).unwrap_or(&"").to_string();

    // mem
    let mem_pct = cmd_out("sh", &["-c", "free | awk '/Mem/{printf \"%.0f%%\", $3/$2*100}'"]);
    let load = cmd_out("sh", &["-c", "cat /proc/loadavg | cut -d' ' -f1"]);

    // services
    let svc_names = vec![
        ("Pingora (Web Server)", "fserver"),
        ("Postfix (SMTP)", "postfix"),
        ("Dovecot (IMAP)", "dovecot"),
        ("NSD (DNS)", "nsd"),
        ("MariaDB", "mariadb"),
        ("Nginx", "nginx"),
        ("FPanel", "fpanel"),
        ("Mailtrack", "mailtrack"),
        ("Valkey", "valkey"),
    ];
    let services = svc_names
        .into_iter()
        .map(|(label, unit)| {
            let procs = ["fserver", "fpanel"];
            let status = if procs.contains(&unit) {
                if std::process::Command::new("pgrep")
                    .args(["-f", &format!("target/release/{}", unit)])
                    .output()
                    .map(|o| o.status.success())
                    .unwrap_or(false)
                {
                    "active".to_string()
                } else {
                    "inactive".to_string()
                }
            } else {
                let s = cmd_out("systemctl", &["is-active", unit]);
                if s.contains("inactive") || s.is_empty() {
                    "inactive".to_string()
                } else {
                    s
                }
            };
            ServiceStatus {
                name: label.to_string(),
                status,
            }
        })
        .collect();

    Ok(Json(ServerInfo {
        os,
        kernel,
        arch,
        ip,
        server_name,
        php_version,
        nginx_version,
        mariadb_version,
        panel_version: "1.0.0".to_string(),
        disk_used,
        disk_total,
        disk_pct,
        mem_pct,
        load,
        services,
    }))
}