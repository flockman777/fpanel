use axum::extract::State;
use axum::{Json, Router};
use serde::Serialize;

use crate::db::AppState;
use crate::error::{internal_error, ApiError};
use crate::provision;
use crate::routes::domains::bearer_account;

#[derive(Debug, Serialize)]
pub struct AccountUsage {
    pub account_id: i64,
    pub username: String,
    pub disk_bytes: i64,
    pub bandwidth_bytes: i64,
    pub access_log_bytes: i64,
    pub error_log_bytes: i64,
    pub domains: i64,
    pub databases: i64,
}

pub fn router() -> Router<AppState> {
    Router::new().route("/", axum::routing::get(list))
}

pub fn client_router() -> Router<AppState> {
    Router::new().route("/", axum::routing::get(client_own))
}

fn logs_dir() -> std::path::PathBuf {
    std::env::var("FPANEL_LOGS")
        .map(std::path::PathBuf::from)
        .unwrap_or_else(|_| provision::data_dir().join("logs"))
}

fn dir_size(path: &std::path::Path) -> i64 {
    fn walk(p: &std::path::Path) -> i64 {
        let Ok(rd) = std::fs::read_dir(p) else {
            return std::fs::metadata(p).map(|m| m.len() as i64).unwrap_or(0);
        };
        let mut total = 0i64;
        for e in rd.flatten() {
            let path = e.path();
            if path.is_dir() {
                total += walk(&path);
            } else {
                total += e.metadata().map(|m| m.len() as i64).unwrap_or(0);
            }
        }
        total
    }
    walk(path)
}

fn log_bandwidth(domain: &str) -> (i64, i64, i64) {
    let path = logs_dir().join(format!("{domain}.access.log"));
    let Ok(raw) = std::fs::read(&path) else {
        return (0, 0, 0);
    };
    let mut bandwidth = 0i64;
    for line in String::from_utf8_lossy(&raw).lines() {
        if let Some(t) = line.split_whitespace().last() {
            if let Ok(b) = t.parse::<i64>() {
                bandwidth += b;
            }
        }
    }
    let err_path = logs_dir().join(format!("{domain}.error.log"));
    let err_size = std::fs::metadata(err_path).map(|m| m.len() as i64).unwrap_or(0);
    (bandwidth, raw.len() as i64, err_size)
}

async fn accumulate(
    state: &AppState,
    account_id: i64,
) -> Result<AccountUsage, ApiError> {
    let username = sqlx::query_scalar::<_, String>("SELECT username FROM accounts WHERE id = ?")
        .bind(account_id)
        .fetch_optional(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?
        .ok_or_else(|| ApiError::new(axum::http::StatusCode::NOT_FOUND, "Account not found"))?;

    let domains: Vec<String> = sqlx::query_scalar::<_, String>(
        "SELECT name FROM domains WHERE account_id = ? AND status = 'active'",
    )
    .bind(account_id)
    .fetch_all(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?;

    let mut bandwidth_bytes = 0i64;
    let mut access_log_bytes = 0i64;
    let mut error_log_bytes = 0i64;
    for d in &domains {
        let (bw, acc, err) = log_bandwidth(d);
        bandwidth_bytes += bw;
        access_log_bytes += acc;
        error_log_bytes += err;
    }

    let dom_count = sqlx::query_scalar::<_, i64>(
        "SELECT COUNT(*) FROM domains WHERE account_id = ? AND status = 'active'",
    )
    .bind(account_id)
    .fetch_one(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?;

    let db_count = sqlx::query_scalar::<_, i64>(
        "SELECT COUNT(*) FROM databases WHERE account_id = ? AND status = 'active'",
    )
    .bind(account_id)
    .fetch_one(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?;

    let disk_bytes = dir_size(&provision::account_htdocs(&username));

    Ok(AccountUsage {
        account_id,
        disk_bytes,
        bandwidth_bytes,
        access_log_bytes,
        error_log_bytes,
        domains: dom_count,
        databases: db_count,
        username,
    })
}

async fn list(State(state): State<AppState>) -> Result<Json<Vec<AccountUsage>>, ApiError> {
    let ids: Vec<i64> = sqlx::query_scalar::<_, i64>("SELECT id FROM accounts ORDER BY username")
        .fetch_all(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?;
    let mut out = Vec::with_capacity(ids.len());
    for id in ids {
        out.push(accumulate(&state, id).await?);
    }
    Ok(Json(out))
}

async fn client_own(
    State(state): State<AppState>,
    headers: axum::http::HeaderMap,
) -> Result<Json<AccountUsage>, ApiError> {
    let (account_id, _) = bearer_account(&state, &headers).await?;
    Ok(Json(accumulate(&state, account_id).await?))
}