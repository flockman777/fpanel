use axum::extract::{Path, State};
use axum::http::StatusCode;
use axum::routing::post;
use axum::{Json, Router};
use serde::{Deserialize, Serialize};
use sqlx::Row;

use crate::db::AppState;
use crate::error::{internal_error, ApiError};
use crate::routes::dns::{generate_zone, try_insert_record};

pub const PUBLIC_IP: &str = "157.15.125.2";

#[derive(Debug, Serialize)]
pub struct DomainDeliverability {
    pub domain: String,
    pub spf: bool,
    pub dmarc: bool,
    pub dkim: bool,
    pub dkim_signing: bool,
}

#[derive(Debug, Deserialize)]
pub struct ActionReq {
    pub action: String,
}

fn dkim_key_path(domain: &str) -> std::path::PathBuf {
    std::path::PathBuf::from("/etc/opendkim/keys").join(domain).join("default.private")
}

async fn has_txt(state: &AppState, domain_id: i64, name: &str, prefix: &str) -> bool {
    let count = sqlx::query_scalar::<_, i64>(
        "SELECT COUNT(*) FROM dns_records WHERE domain_id = ? AND rtype = 'TXT' AND name = ? AND value LIKE ?",
    )
    .bind(domain_id)
    .bind(name)
    .bind(format!("{prefix}%"))
    .fetch_one(&state.db)
    .await
    .unwrap_or(0);
    count > 0
}

pub fn router() -> Router<AppState> {
    Router::new().route("/", axum::routing::get(list)).route("/{domain}", post(apply))
}

async fn list(State(state): State<AppState>) -> Result<Json<Vec<DomainDeliverability>>, ApiError> {
    let rows = sqlx::query("SELECT id, name FROM domains WHERE status = 'active' ORDER BY name")
        .fetch_all(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?;
    let mut out = Vec::new();
    for r in rows {
        let id: i64 = r.get(0);
        let domain: String = r.get(1);
        out.push(DomainDeliverability {
            spf: has_txt(&state, id, "@", "v=spf1").await,
            dmarc: has_txt(&state, id, "_dmarc", "v=DMARC1").await,
            dkim: has_txt(&state, id, "default._domainkey", "v=DKIM1").await,
            dkim_signing: dkim_key_path(&domain).exists(),
            domain,
        });
    }
    Ok(Json(out))
}

async fn apply(
    State(state): State<AppState>,
    Path((_sess, domain)): Path<(String, String)>,
    Json(input): Json<ActionReq>,
) -> Result<Json<serde_json::Value>, ApiError> {
    let Some(row) = sqlx::query("SELECT id, account_id FROM domains WHERE name = ? AND status = 'active'")
        .bind(&domain)
        .fetch_optional(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?
    else {
        return Err(ApiError::new(StatusCode::NOT_FOUND, "Domain not found"));
    };
    let domain_id: i64 = row.get(0);
    let account_id: i64 = row.get(1);

    match input.action.as_str() {
        "spf" => {
            try_insert_record(
                &state,
                account_id,
                domain_id,
                &domain,
                "@",
                "TXT",
                &format!("v=spf1 mx a ip4:{PUBLIC_IP} ~all"),
            )
            .await?;
        }
        "dmarc" => {
            try_insert_record(
                &state,
                account_id,
                domain_id,
                &domain,
                "_dmarc",
                "TXT",
                &format!("v=DMARC1; p=none; rua=mailto:admin@{domain}"),
            )
            .await?;
        }
        "dkim" => {
            setup_dkim(&domain)?;
            let p = dkim_public_key(&domain)?;
            try_insert_record(
                &state,
                account_id,
                domain_id,
                &domain,
                "default._domainkey",
                "TXT",
                &format!("v=DKIM1; k=rsa; p={p}"),
            )
            .await?;
        }
        other => {
            return Err(ApiError::new(
                StatusCode::BAD_REQUEST,
                format!("Unknown action '{other}'"),
            ))
        }
    }

    generate_zone(&state, domain_id).await?;
    Ok(Json(serde_json::json!({ "ok": true, "domain": domain })))
}

fn run(cmd: &str, args: &[&str]) -> Result<String, ApiError> {
    let out = std::process::Command::new(cmd)
        .args(args)
        .output()
        .map_err(|e| internal_error(e.into()))?;
    if !out.status.success() {
        return Err(ApiError::new(
            StatusCode::INTERNAL_SERVER_ERROR,
            format!(
                "command failed: {} {}: {}",
                cmd,
                args.join(" "),
                String::from_utf8_lossy(&out.stderr).trim()
            ),
        ));
    }
    Ok(String::from_utf8_lossy(&out.stdout).into_owned())
}

fn append_if_missing(path: &str, line: &str) -> Result<(), ApiError> {
    let content = std::fs::read_to_string(path).unwrap_or_default();
    if content.lines().any(|l| l.trim() == line.trim()) {
        return Ok(());
    }
    std::fs::OpenOptions::new()
        .create(true)
        .append(true)
        .open(path)
        .and_then(|mut f| {
            use std::io::Write;
            f.write_all(line.as_bytes())
        })
        .map_err(|e| internal_error(e.into()))?;
    Ok(())
}

fn setup_dkim(domain: &str) -> Result<(), ApiError> {
    let dir = std::path::PathBuf::from("/etc/opendkim/keys").join(domain);
    std::fs::create_dir_all(&dir).map_err(|e| internal_error(e.into()))?;
    let key_path = dkim_key_path(domain);
    if !key_path.exists() {
        run("openssl", &["genrsa", "-out", key_path.to_str().unwrap(), "2048"])?;
        let _ = std::process::Command::new("chown")
            .args(["opendkim:opendkim", key_path.to_str().unwrap()])
            .status();
        let _ = std::process::Command::new("chmod")
            .args(["640", key_path.to_str().unwrap()])
            .status();
    }
    append_if_missing(
        "/etc/opendkim/KeyTable",
        &format!("default._domainkey.{domain} {domain}:default:{}\n", key_path.display()),
    )?;
    append_if_missing(
        "/etc/opendkim/SigningTable",
        &format!("*@*.{domain} default._domainkey.{domain}\n*@{domain} default._domainkey.{domain}\n"),
    )?;
    run("systemctl", &["restart", "opendkim"])?;
    run("postfix", &["reload"])?;
    Ok(())
}

fn dkim_public_key(domain: &str) -> Result<String, ApiError> {
    let key_path = dkim_key_path(domain);
    let der = run(
        "sh",
        &[
            "-c",
            &format!("openssl rsa -in '{}' -pubout -outform DER 2>/dev/null | base64 -w0", key_path.display()),
        ],
    )?;
    Ok(der.trim().to_string())
}