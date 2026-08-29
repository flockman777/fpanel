use axum::extract::{Path, Query, State};
use axum::http::{HeaderMap, StatusCode};
use axum::routing::{delete, get, post};
use axum::{Json, Router};
use serde::{Deserialize, Serialize};
use sqlx::Row;

use crate::db::AppState;
use crate::error::{internal_error, ApiError};
use crate::routes::domains::bearer_account;
use crate::routes::sec::refresh_security;
use crate::routes::sec::valid_ip;

#[derive(Serialize)]
struct RowOut {
    id: i64,
    account_id: i64,
    domain_id: Option<i64>,
    domain: Option<String>,
    ip: String,
    reason: Option<String>,
    created_at: String,
}

#[derive(Deserialize)]
struct CreateBody {
    account_id: Option<i64>,
    domain_id: Option<i64>,
    ip: String,
    reason: Option<String>,
}

#[derive(Deserialize)]
struct AccountQ {
    account_id: Option<i64>,
}

pub fn router() -> Router<AppState> {
    Router::new().route("/", get(list_admin).post(create_admin)).route("/{id}", delete(remove_admin))
}

pub fn client_router() -> Router<AppState> {
    Router::new()
        .route("/", get(list_client).post(create_client))
        .route("/{id}", delete(remove_client))
}

async fn list_rows(state: &AppState, aid: i64) -> Result<Vec<RowOut>, ApiError> {
    let rows = sqlx::query(
        "SELECT b.id, b.account_id, b.domain_id, b.domain, b.ip, b.reason, b.created_at \
         FROM ip_blocker b WHERE b.account_id = ? ORDER BY b.id DESC",
    )
    .bind(aid)
    .fetch_all(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?;
    rows.iter()
        .map(|r| {
            Ok(RowOut {
                id: r.get(0),
                account_id: r.get(1),
                domain_id: r.get(2),
                domain: r.get(3),
                ip: r.get(4),
                reason: r.get(5),
                created_at: r.get(6),
            })
        })
        .collect()
}

async fn create(state: &AppState, aid: i64, body: CreateBody) -> Result<RowOut, ApiError> {
    let ip = valid_ip(&body.ip)?;
    let (domain_id, domain) = match body.domain_id {
        Some(did) => {
            let name: String = sqlx::query_scalar(
                "SELECT name FROM domains WHERE id = ? AND account_id = ? AND status = 'active'",
            )
            .bind(did)
            .bind(aid)
            .fetch_optional(&state.db)
            .await
            .map_err(|e| internal_error(e.into()))?
            .ok_or_else(|| ApiError::new(StatusCode::NOT_FOUND, "Domain not found"))?;
            (Some(did), Some(name))
        }
        None => (None, None),
    };

    let dup: i64 = if let Some(did) = domain_id {
        sqlx::query_scalar("SELECT COUNT(*) FROM ip_blocker WHERE domain_id = ? AND ip = ?")
            .bind(did)
            .bind(&ip)
            .fetch_one(&state.db)
            .await
            .map_err(|e| internal_error(e.into()))?
    } else {
        sqlx::query_scalar("SELECT COUNT(*) FROM ip_blocker WHERE domain_id IS NULL AND account_id = ? AND ip = ?")
            .bind(aid)
            .bind(&ip)
            .fetch_one(&state.db)
            .await
            .map_err(|e| internal_error(e.into()))?
    };
    if dup > 0 {
        return Err(ApiError::new(StatusCode::CONFLICT, "This IP is already blocked"));
    }

    let result = sqlx::query(
        "INSERT INTO ip_blocker (account_id, domain_id, domain, ip, reason) VALUES (?, ?, ?, ?, ?)",
    )
    .bind(aid)
    .bind(domain_id)
    .bind(&domain)
    .bind(&ip)
    .bind(&body.reason)
    .execute(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?;

    if let Some(did) = domain_id {
        if let Some(d) = &domain {
            refresh_security(state, aid, did, d).await?;
        }
    } else {
        let domains: Vec<(i64, String)> =
            sqlx::query_as("SELECT id, name FROM domains WHERE account_id = ?")
                .bind(aid)
                .fetch_all(&state.db)
                .await
                .map_err(|e| internal_error(e.into()))?;
        for (did, name) in domains {
            refresh_security(state, aid, did, &name).await?;
        }
    }

    Ok(RowOut {
        id: result.last_insert_rowid(),
        account_id: aid,
        domain_id,
        domain,
        ip,
        reason: body.reason,
        created_at: String::new(),
    })
}

async fn remove(state: &AppState, aid: i64, id: i64) -> Result<(), ApiError> {
    let row = sqlx::query("SELECT domain_id, domain FROM ip_blocker WHERE id = ? AND account_id = ?")
        .bind(id)
        .bind(aid)
        .fetch_optional(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?;
    let Some(row) = row else {
        return Err(ApiError::new(StatusCode::NOT_FOUND, "Blocked IP not found"));
    };
    let domain_id: Option<i64> = row.get(0);
    let domain: Option<String> = row.get(1);

    sqlx::query("DELETE FROM ip_blocker WHERE id = ? AND account_id = ?")
        .bind(id)
        .bind(aid)
        .execute(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?;

    if let Some(did) = domain_id {
        if let Some(d) = &domain {
            refresh_security(state, aid, did, d).await?;
        }
    } else {
        let domains: Vec<(i64, String)> =
            sqlx::query_as("SELECT id, name FROM domains WHERE account_id = ?")
                .bind(aid)
                .fetch_all(&state.db)
                .await
                .map_err(|e| internal_error(e.into()))?;
        for (did, name) in domains {
            refresh_security(state, aid, did, &name).await?;
        }
    }
    Ok(())
}

// clients
async fn list_client(State(state): State<AppState>, headers: HeaderMap) -> Result<Json<Vec<RowOut>>, ApiError> {
    let (aid, _) = bearer_account(&state, &headers).await?;
    Ok(Json(list_rows(&state, aid).await?))
}

async fn create_client(
    State(state): State<AppState>,
    headers: HeaderMap,
    Json(body): Json<CreateBody>,
) -> Result<(StatusCode, Json<RowOut>), ApiError> {
    let (aid, _) = bearer_account(&state, &headers).await?;
    Ok((StatusCode::CREATED, Json(create(&state, aid, body).await?)))
}

async fn remove_client(
    State(state): State<AppState>,
    headers: HeaderMap,
    Path((_sess, id)): Path<(String, i64)>,
) -> Result<StatusCode, ApiError> {
    let (aid, _) = bearer_account(&state, &headers).await?;
    remove(&state, aid, id).await?;
    Ok(StatusCode::NO_CONTENT)
}

// admins
fn require_account(aid: Option<i64>) -> Result<i64, ApiError> {
    aid.ok_or_else(|| ApiError::new(StatusCode::BAD_REQUEST, "account_id is required"))
}

async fn list_admin(State(state): State<AppState>, Query(q): Query<AccountQ>) -> Result<Json<Vec<RowOut>>, ApiError> {
    let aid = require_account(q.account_id)?;
    Ok(Json(list_rows(&state, aid).await?))
}

async fn create_admin(
    State(state): State<AppState>,
    Query(q): Query<AccountQ>,
    Json(body): Json<CreateBody>,
) -> Result<(StatusCode, Json<RowOut>), ApiError> {
    let aid = body.account_id.or(q.account_id).ok_or_else(|| {
        ApiError::new(StatusCode::BAD_REQUEST, "account_id is required")
    })?;
    Ok((StatusCode::CREATED, Json(create(&state, aid, body).await?)))
}

async fn remove_admin(
    State(state): State<AppState>,
    Query(q): Query<AccountQ>,
    Path((_sess, id)): Path<(String, i64)>,
) -> Result<StatusCode, ApiError> {
    let aid = require_account(q.account_id)?;
    remove(&state, aid, id).await?;
    Ok(StatusCode::NO_CONTENT)
}