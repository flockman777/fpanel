use axum::extract::{Path, State};
use axum::http::StatusCode;
use axum::routing::{delete, get, put};
use axum::{Json, Router};
use serde::Serialize;
use serde::Deserialize;
use sqlx::FromRow;
use sqlx::Row;

use crate::db::AppState;
use crate::error::{internal_error, ApiError};
use crate::provision;
use crate::routes::domains::bearer_account;

#[derive(Debug, Serialize, FromRow)]
pub struct CronJob {
    pub id: i64,
    pub account_id: i64,
    pub domain_id: Option<i64>,
    pub domain: Option<String>,
    pub schedule: String,
    pub command: String,
    pub description: Option<String>,
    pub status: String,
    pub last_run: Option<String>,
    pub created_at: String,
}

#[derive(Debug, Serialize)]
pub struct CronAdmin {
    pub id: i64,
    pub account_id: i64,
    pub username: String,
    pub domain_id: Option<i64>,
    pub domain: Option<String>,
    pub schedule: String,
    pub command: String,
    pub description: Option<String>,
    pub status: String,
    pub last_run: Option<String>,
    pub created_at: String,
}

#[derive(Debug, Deserialize)]
pub struct CreateCron {
    pub account_id: i64,
    pub domain_id: Option<i64>,
    pub schedule: String,
    pub command: String,
    pub description: Option<String>,
}

#[derive(Debug, Deserialize)]
pub struct ClientCreateCron {
    pub domain_id: Option<i64>,
    pub schedule: String,
    pub command: String,
    pub description: Option<String>,
}

pub fn router() -> Router<AppState> {
    Router::new()
        .route("/", get(list).post(create))
        .route("/{id}", get(get_one).put(update).delete(remove))
}

pub fn client_router() -> Router<AppState> {
    Router::new()
        .route("/", get(client_list).post(client_create))
        .route("/{id}", put(client_update).delete(client_remove))
}

async fn list(State(state): State<AppState>) -> Result<Json<Vec<CronAdmin>>, ApiError> {
    let rows = sqlx::query(
        "SELECT c.id, c.account_id, a.username, c.domain_id, c.domain, c.schedule, c.command, \
         c.description, c.status, c.last_run, c.created_at \
         FROM cron_jobs c JOIN accounts a ON a.id = c.account_id ORDER BY c.id",
    )
    .fetch_all(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?;

    let mut out = Vec::with_capacity(rows.len());
    for r in rows {
        out.push(CronAdmin {
            id: r.get(0),
            account_id: r.get(1),
            username: r.get(2),
            domain_id: r.get(3),
            domain: r.get(4),
            schedule: r.get(5),
            command: r.get(6),
            description: r.get(7),
            status: r.get(8),
            last_run: r.get(9),
            created_at: r.get(10),
        });
    }
    Ok(Json(out))
}

async fn get_one(
    State(state): State<AppState>,
    Path((_sess, id)): Path<(String, i64)>,
) -> Result<Json<CronAdmin>, ApiError> {
    let row = sqlx::query(
        "SELECT c.id, c.account_id, a.username, c.domain_id, c.domain, c.schedule, c.command, \
         c.description, c.status, c.last_run, c.created_at \
         FROM cron_jobs c JOIN accounts a ON a.id = c.account_id WHERE c.id = ?",
    )
    .bind(id)
    .fetch_optional(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?
    .ok_or_else(|| ApiError::new(StatusCode::NOT_FOUND, "Cron job not found"))?;
    Ok(Json(CronAdmin {
        id: row.get(0),
        account_id: row.get(1),
        username: row.get(2),
        domain_id: row.get(3),
        domain: row.get(4),
        schedule: row.get(5),
        command: row.get(6),
        description: row.get(7),
        status: row.get(8),
        last_run: row.get(9),
        created_at: row.get(10),
    }))
}

async fn create(
    State(state): State<AppState>,
    Json(input): Json<CreateCron>,
) -> Result<(StatusCode, Json<CronAdmin>), ApiError> {
    resolve_account(&state, input.account_id, None).await?;
    let (domain_id, domain) = resolve_domain(&state, input.domain_id, None).await?;
    validate_schedule(&input.schedule)?;
    if input.command.trim().is_empty() {
        return Err(ApiError::new(StatusCode::BAD_REQUEST, "Command is required"));
    }

    let result = sqlx::query(
        "INSERT INTO cron_jobs (account_id, domain_id, domain, schedule, command, description) \
         VALUES (?, ?, ?, ?, ?, ?)",
    )
    .bind(input.account_id)
    .bind(domain_id)
    .bind(domain)
    .bind(input.schedule.trim())
    .bind(input.command.trim())
    .bind(input.description)
    .execute(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?;

    let job = fetch_job(&state, result.last_insert_rowid()).await?;
    reflect_cron(&state, input.account_id).await?;
    Ok((StatusCode::CREATED, Json(job)))
}

async fn update(
    State(state): State<AppState>,
    Path((_sess, id)): Path<(String, i64)>,
    Json(input): Json<serde_json::Value>,
) -> Result<Json<CronAdmin>, ApiError> {
    let existing = sqlx::query_as::<_, CronJob>("SELECT * FROM cron_jobs WHERE id = ?")
        .bind(id)
        .fetch_optional(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?
        .ok_or_else(|| ApiError::new(StatusCode::NOT_FOUND, "Cron job not found"))?;

    let schedule = input.get("schedule").and_then(|v| v.as_str()).map(|s| s.to_string()).unwrap_or(existing.schedule);
    let command = input.get("command").and_then(|v| v.as_str()).map(|s| s.to_string()).unwrap_or(existing.command);
    let description = input.get("description").and_then(|v| v.as_str()).map(|s| s.to_string()).or(existing.description);
    let status = input.get("status").and_then(|v| v.as_str()).map(|s| s.to_string()).unwrap_or(existing.status);
    let domain_id = input.get("domain_id").and_then(|v| v.as_i64());
    validate_schedule(&schedule)?;
    if command.trim().is_empty() {
        return Err(ApiError::new(StatusCode::BAD_REQUEST, "Command is required"));
    }
    let (ndom_id, ndom) = resolve_domain(&state, domain_id, None).await?;

    sqlx::query(
        "UPDATE cron_jobs SET domain_id = ?, domain = ?, schedule = ?, command = ?, description = ?, status = ? WHERE id = ?",
    )
    .bind(ndom_id)
    .bind(ndom)
    .bind(schedule.trim())
    .bind(command.trim())
    .bind(description)
    .bind(status)
    .bind(id)
    .execute(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?;

    let job = fetch_job(&state, id).await?;
    reflect_cron(&state, existing.account_id).await?;
    Ok(Json(job))
}

async fn remove(
    State(state): State<AppState>,
    Path((_sess, id)): Path<(String, i64)>,
) -> Result<StatusCode, ApiError> {
    let existing = sqlx::query_as::<_, CronJob>("SELECT * FROM cron_jobs WHERE id = ?")
        .bind(id)
        .fetch_optional(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?
        .ok_or_else(|| ApiError::new(StatusCode::NOT_FOUND, "Cron job not found"))?;
    sqlx::query("DELETE FROM cron_jobs WHERE id = ?")
        .bind(id)
        .execute(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?;
    reflect_cron(&state, existing.account_id).await?;
    Ok(StatusCode::NO_CONTENT)
}

async fn client_list(
    State(state): State<AppState>,
    headers: axum::http::HeaderMap,
) -> Result<Json<Vec<CronJob>>, ApiError> {
    let (account_id, _) = bearer_account(&state, &headers).await?;
    let jobs = sqlx::query_as::<_, CronJob>(
        "SELECT * FROM cron_jobs WHERE account_id = ? ORDER BY id",
    )
    .bind(account_id)
    .fetch_all(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?;
    Ok(Json(jobs))
}

async fn client_create(
    State(state): State<AppState>,
    headers: axum::http::HeaderMap,
    Json(input): Json<ClientCreateCron>,
) -> Result<(StatusCode, Json<CronAdmin>), ApiError> {
    let (account_id, _) = bearer_account(&state, &headers).await?;
    let (domain_id, domain) = resolve_domain(&state, input.domain_id, Some(account_id)).await?;
    validate_schedule(&input.schedule)?;
    if input.command.trim().is_empty() {
        return Err(ApiError::new(StatusCode::BAD_REQUEST, "Command is required"));
    }

    let result = sqlx::query(
        "INSERT INTO cron_jobs (account_id, domain_id, domain, schedule, command, description) \
         VALUES (?, ?, ?, ?, ?, ?)",
    )
    .bind(account_id)
    .bind(domain_id)
    .bind(domain)
    .bind(input.schedule.trim())
    .bind(input.command.trim())
    .bind(input.description)
    .execute(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?;

    let job = fetch_job(&state, result.last_insert_rowid()).await?;
    reflect_cron(&state, account_id).await?;
    Ok((StatusCode::CREATED, Json(job)))
}

async fn client_update(
    State(state): State<AppState>,
    headers: axum::http::HeaderMap,
    Path((_sess, id)): Path<(String, i64)>,
    Json(input): Json<serde_json::Value>,
) -> Result<Json<CronAdmin>, ApiError> {
    let (account_id, _) = bearer_account(&state, &headers).await?;
    let existing = sqlx::query_as::<_, CronJob>("SELECT * FROM cron_jobs WHERE id = ? AND account_id = ?")
        .bind(id)
        .bind(account_id)
        .fetch_optional(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?
        .ok_or_else(|| ApiError::new(StatusCode::NOT_FOUND, "Cron job not found"))?;

    let schedule = input.get("schedule").and_then(|v| v.as_str()).map(|s| s.to_string()).unwrap_or(existing.schedule);
    let command = input.get("command").and_then(|v| v.as_str()).map(|s| s.to_string()).unwrap_or(existing.command);
    let description = input.get("description").and_then(|v| v.as_str()).map(|s| s.to_string()).or(existing.description);
    let domain_id = input.get("domain_id").and_then(|v| v.as_i64());
    validate_schedule(&schedule)?;
    if command.trim().is_empty() {
        return Err(ApiError::new(StatusCode::BAD_REQUEST, "Command is required"));
    }
    let (ndom_id, ndom) = resolve_domain(&state, domain_id, Some(account_id)).await?;

    sqlx::query(
        "UPDATE cron_jobs SET domain_id = ?, domain = ?, schedule = ?, command = ?, description = ? WHERE id = ?",
    )
    .bind(ndom_id)
    .bind(ndom)
    .bind(schedule.trim())
    .bind(command.trim())
    .bind(description)
    .bind(id)
    .execute(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?;

    let job = fetch_job(&state, id).await?;
    reflect_cron(&state, account_id).await?;
    Ok(Json(job))
}

async fn client_remove(
    State(state): State<AppState>,
    headers: axum::http::HeaderMap,
    Path((_sess, id)): Path<(String, i64)>,
) -> Result<StatusCode, ApiError> {
    let (account_id, _) = bearer_account(&state, &headers).await?;
    sqlx::query_as::<_, CronJob>("SELECT * FROM cron_jobs WHERE id = ? AND account_id = ?")
        .bind(id)
        .bind(account_id)
        .fetch_optional(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?
        .ok_or_else(|| ApiError::new(StatusCode::NOT_FOUND, "Cron job not found"))?;
    sqlx::query("DELETE FROM cron_jobs WHERE id = ? AND account_id = ?")
        .bind(id)
        .bind(account_id)
        .execute(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?;
    reflect_cron(&state, account_id).await?;
    Ok(StatusCode::NO_CONTENT)
}

async fn fetch_job(state: &AppState, id: i64) -> Result<CronAdmin, ApiError> {
    let row = sqlx::query(
        "SELECT c.id, c.account_id, a.username, c.domain_id, c.domain, c.schedule, c.command, \
         c.description, c.status, c.last_run, c.created_at \
         FROM cron_jobs c JOIN accounts a ON a.id = c.account_id WHERE c.id = ?",
    )
    .bind(id)
    .fetch_one(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?;
    Ok(CronAdmin {
        id: row.get(0),
        account_id: row.get(1),
        username: row.get(2),
        domain_id: row.get(3),
        domain: row.get(4),
        schedule: row.get(5),
        command: row.get(6),
        description: row.get(7),
        status: row.get(8),
        last_run: row.get(9),
        created_at: row.get(10),
    })
}

async fn resolve_account(state: &AppState, account_id: i64, want: Option<i64>) -> Result<String, ApiError> {
    let aid = want.unwrap_or(account_id);
    sqlx::query_scalar::<_, String>("SELECT username FROM accounts WHERE id = ?")
        .bind(aid)
        .fetch_optional(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?
        .ok_or_else(|| ApiError::new(StatusCode::NOT_FOUND, "Account not found"))
}

async fn resolve_domain(
    state: &AppState,
    domain_id: Option<i64>,
    account_id: Option<i64>,
) -> Result<(Option<i64>, Option<String>), ApiError> {
    let Some(did) = domain_id else {
        return Ok((None, None));
    };
    let row = if let Some(aid) = account_id {
        sqlx::query("SELECT name FROM domains WHERE id = ? AND account_id = ? AND status = 'active'")
            .bind(did)
            .bind(aid)
            .fetch_optional(&state.db)
            .await
            .map_err(|e| internal_error(e.into()))?
            .ok_or_else(|| ApiError::new(StatusCode::NOT_FOUND, "Domain not found for this account"))?
    } else {
        sqlx::query("SELECT name FROM domains WHERE id = ?")
            .bind(did)
            .fetch_optional(&state.db)
            .await
            .map_err(|e| internal_error(e.into()))?
            .ok_or_else(|| ApiError::new(StatusCode::NOT_FOUND, "Domain not found"))?
    };
    Ok((Some(did), Some(row.get(0))))
}

fn validate_schedule(schedule: &str) -> Result<(), ApiError> {
    let s = schedule.trim();
    let parts: Vec<&str> = s.split_whitespace().collect();
    if parts.len() != 5 {
        return Err(ApiError::new(
            StatusCode::BAD_REQUEST,
            "Schedule must have 5 cron fields (minute hour day month weekday)",
        ));
    }
    for p in parts {
        for tok in p.split(',') {
            let tok = tok.trim();
            if tok.is_empty() {
                continue;
            }
            let ok = tok == "*" || {
                let (range, step) = match tok.split_once('/') {
                    Some((r, st)) => (r, st),
                    None => (tok, "1"),
                };
                let step_ok = step.parse::<u32>().map(|v| v > 0).unwrap_or(false);
                let range_ok = if let Some((a, b)) = range.split_once('-') {
                    a.parse::<u32>().is_ok() && b.parse::<u32>().is_ok()
                } else if range == "*" {
                    true
                } else {
                    range.parse::<u32>().is_ok()
                };
                step_ok && range_ok
            };
            if !ok {
                return Err(ApiError::new(StatusCode::BAD_REQUEST, "Invalid cron schedule field"));
            }
        }
    }
    Ok(())
}

pub(crate) async fn reflect_cron(state: &AppState, account_id: i64) -> Result<(), ApiError> {
    let Some(username) = sqlx::query_scalar::<_, String>("SELECT username FROM accounts WHERE id = ?")
        .bind(account_id)
        .fetch_optional(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?
    else {
        return Ok(());
    };

    let jobs = sqlx::query_as::<_, CronJob>(
        "SELECT * FROM cron_jobs WHERE account_id = ? AND status = 'active' ORDER BY id",
    )
    .bind(account_id)
    .fetch_all(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?;

    let mut content = format!("# FPanel cron jobs for {username}\n");
    for j in &jobs {
        let note = j.description.as_ref().map(|d| format!(" # {d}")).unwrap_or_default();
        content.push_str(&format!("{} {} {}\n", j.schedule, j.command, note));
    }

    let dir = provision::data_dir().join("cron");
    std::fs::create_dir_all(&dir).map_err(|e| internal_error(anyhow::Error::new(e)))?;
    let path = dir.join(format!("{username}.cron"));
    if jobs.is_empty() {
        std::fs::remove_file(&path).ok();
        return Ok(());
    }
    let tmp = dir.join(format!("{username}.cron.tmp"));
    std::fs::write(&tmp, content).map_err(|e| internal_error(anyhow::Error::new(e)))?;
    std::fs::rename(&tmp, &path).map_err(|e| internal_error(anyhow::Error::new(e)))?;
    Ok(())
}