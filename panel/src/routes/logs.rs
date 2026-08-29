use axum::extract::{Path, Query, State};
use axum::http::StatusCode;
use axum::routing::get;
use axum::{Json, Router};
use serde::{Deserialize, Serialize};
use sqlx::Row;
use std::path::{Path as FsPath, PathBuf};

use crate::db::AppState;
use crate::error::{internal_error, ApiError};
use crate::provision;
use crate::routes::domains::bearer_account;

#[derive(Debug, Serialize)]
pub struct LogSummary {
    pub domain: String,
    pub account_id: i64,
    pub username: String,
    pub access_size: i64,
    pub error_size: i64,
    pub access_lines: i64,
    pub error_lines: i64,
    pub bandwidth: i64,
    pub last_access: Option<String>,
}

#[derive(Debug, Deserialize)]
struct LinesQuery {
    lines: Option<i64>,
}

pub fn router() -> Router<AppState> {
    Router::new()
        .route("/", get(list))
        .route("/access/{domain}", get(access_log))
        .route("/error/{domain}", get(error_log))
}

pub fn client_router() -> Router<AppState> {
    Router::new()
        .route("/", get(client_list))
        .route("/access/{domain}", get(client_access_log))
        .route("/error/{domain}", get(client_error_log))
}

fn logs_dir() -> PathBuf {
    std::env::var("FPANEL_LOGS")
        .map(PathBuf::from)
        .unwrap_or_else(|_| provision::data_dir().join("logs"))
}

fn safe_domain(domain: &str) -> Result<String, ApiError> {
    let d = domain.trim().to_lowercase();
    if d.is_empty()
        || d.len() > 253
        || d.contains('/')
        || d.contains('\\')
        || d.contains("..")
        || !d.chars().all(|c| c.is_ascii_alphanumeric() || c == '-' || c == '.')
    {
        return Err(ApiError::new(StatusCode::BAD_REQUEST, "Invalid domain"));
    }
    Ok(d)
}

fn tail(path: &FsPath, n: usize) -> Vec<String> {
    let Ok(raw) = std::fs::read(path) else {
        return Vec::new();
    };
    let s = String::from_utf8_lossy(&raw);
    let lines: Vec<&str> = s.lines().collect();
    let start = lines.len().saturating_sub(n);
    lines[start..].iter().map(|l| l.to_string()).collect()
}

fn file_metrics(path: &FsPath) -> (i64, i64, i64) {
    let Ok(raw) = std::fs::read(path) else {
        return (0, 0, 0);
    };
    let size = raw.len() as i64;
    let s = String::from_utf8_lossy(&raw);
    let mut lines = 0i64;
    let mut bandwidth = 0i64;
    for line in s.lines() {
        lines += 1;
        if line.is_empty() {
            continue;
        }
        if let Some(token) = line.split_whitespace().last() {
            if let Ok(b) = token.parse::<i64>() {
                bandwidth += b;
            }
        }
    }
    (size, lines, bandwidth)
}

async fn list_summaries(state: &AppState, account_id: Option<i64>) -> Result<Vec<LogSummary>, ApiError> {
    let q = if let Some(aid) = account_id {
        "SELECT d.name, d.account_id, a.username FROM domains d \
         JOIN accounts a ON a.id = d.account_id WHERE d.account_id = ? \
         AND d.status = 'active' ORDER BY d.name"
            .to_string()
    } else {
        "SELECT d.name, d.account_id, a.username FROM domains d \
         JOIN accounts a ON a.id = d.account_id WHERE d.status = 'active' ORDER BY d.name"
            .to_string()
    };
    let mut qb = sqlx::query(&q);
    if let Some(aid) = account_id {
        qb = qb.bind(aid);
    }
    let rows = qb
        .fetch_all(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?;

    let dir = logs_dir();
    let mut out = Vec::with_capacity(rows.len());
    for r in rows {
        let domain: String = r.get(0);
        let account_id: i64 = r.get(1);
        let username: String = r.get(2);
        let acc = dir.join(format!("{domain}.access.log"));
        let err = dir.join(format!("{domain}.error.log"));
        let (acc_size, acc_lines, bandwidth) = file_metrics(&acc);
        let (err_size, err_lines, _) = file_metrics(&err);
        let last_access = std::fs::metadata(&acc).ok().and_then(|m| m.modified().ok()).map(|t| {
            chrono::DateTime::<chrono::Utc>::from(t).format("%Y-%m-%d %H:%M:%S").to_string()
        });
        out.push(LogSummary {
            domain,
            account_id,
            username,
            access_size: acc_size,
            error_size: err_size,
            access_lines: acc_lines,
            error_lines: err_lines,
            bandwidth,
            last_access,
        });
    }
    Ok(out)
}

async fn list(State(state): State<AppState>) -> Result<Json<Vec<LogSummary>>, ApiError> {
    Ok(Json(list_summaries(&state, None).await?))
}

async fn access_log(
    State(state): State<AppState>,
    Path((_sess, domain)): Path<(String, String)>,
    Query(q): Query<LinesQuery>,
) -> Result<Json<serde_json::Value>, ApiError> {
    let domain = safe_domain(&domain)?;
    let _exists = domain_exists(&state, &domain, None).await?;
    let n = q.lines.unwrap_or(200).clamp(1, 5000) as usize;
    Ok(Json(serde_json::json!({
        "domain": domain,
        "lines": tail(&logs_dir().join(format!("{domain}.access.log")), n),
    })))
}

async fn error_log(
    State(state): State<AppState>,
    Path((_sess, domain)): Path<(String, String)>,
    Query(q): Query<LinesQuery>,
) -> Result<Json<serde_json::Value>, ApiError> {
    let domain = safe_domain(&domain)?;
    let _exists = domain_exists(&state, &domain, None).await?;
    let n = q.lines.unwrap_or(200).clamp(1, 5000) as usize;
    Ok(Json(serde_json::json!({
        "domain": domain,
        "lines": tail(&logs_dir().join(format!("{domain}.error.log")), n),
    })))
}

async fn client_list(
    State(state): State<AppState>,
    headers: axum::http::HeaderMap,
) -> Result<Json<Vec<LogSummary>>, ApiError> {
    let (account_id, _) = bearer_account(&state, &headers).await?;
    Ok(Json(list_summaries(&state, Some(account_id)).await?))
}

async fn client_access_log(
    State(state): State<AppState>,
    headers: axum::http::HeaderMap,
    Path((_sess, domain)): Path<(String, String)>,
    Query(q): Query<LinesQuery>,
) -> Result<Json<serde_json::Value>, ApiError> {
    let (account_id, _) = bearer_account(&state, &headers).await?;
    let domain = safe_domain(&domain)?;
    let _exists = domain_exists(&state, &domain, Some(account_id)).await?;
    let n = q.lines.unwrap_or(200).clamp(1, 5000) as usize;
    Ok(Json(serde_json::json!({
        "domain": domain,
        "lines": tail(&logs_dir().join(format!("{domain}.access.log")), n),
    })))
}

async fn client_error_log(
    State(state): State<AppState>,
    headers: axum::http::HeaderMap,
    Path((_sess, domain)): Path<(String, String)>,
    Query(q): Query<LinesQuery>,
) -> Result<Json<serde_json::Value>, ApiError> {
    let (account_id, _) = bearer_account(&state, &headers).await?;
    let domain = safe_domain(&domain)?;
    let _exists = domain_exists(&state, &domain, Some(account_id)).await?;
    let n = q.lines.unwrap_or(200).clamp(1, 5000) as usize;
    Ok(Json(serde_json::json!({
        "domain": domain,
        "lines": tail(&logs_dir().join(format!("{domain}.error.log")), n),
    })))
}

async fn domain_exists(
    state: &AppState,
    domain: &str,
    account_id: Option<i64>,
) -> Result<bool, ApiError> {
    let count = if let Some(aid) = account_id {
        sqlx::query_scalar::<_, i64>(
            "SELECT COUNT(*) FROM domains WHERE name = ? AND account_id = ?",
        )
        .bind(domain)
        .bind(aid)
        .fetch_one(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?
    } else {
        sqlx::query_scalar::<_, i64>("SELECT COUNT(*) FROM domains WHERE name = ?")
            .bind(domain)
            .fetch_one(&state.db)
            .await
            .map_err(|e| internal_error(e.into()))?
    };
    if count == 0 {
        return Err(ApiError::new(StatusCode::NOT_FOUND, "Domain not found"));
    }
    Ok(true)
}