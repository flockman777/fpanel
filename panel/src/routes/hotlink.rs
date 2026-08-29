use axum::extract::{Path, Query, State};
use axum::http::{HeaderMap, StatusCode};
use axum::routing::{delete, get, put};
use axum::{Json, Router};
use serde::{Deserialize, Serialize};
use sqlx::Row;

use crate::db::AppState;
use crate::error::{internal_error, ApiError};
use crate::routes::domains::bearer_account;
use crate::routes::sec::refresh_security;

#[derive(Serialize)]
struct RowOut {
    id: i64,
    account_id: i64,
    domain_id: i64,
    domain: String,
    extensions: String,
    allow_empty: bool,
    allowed_domains: Option<String>,
    status: bool,
    created_at: String,
}

#[derive(Deserialize, Default)]
struct UpsertBody {
    domain_id: i64,
    extensions: Option<String>,
    allow_empty: Option<bool>,
    allowed_domains: Option<String>,
    status: Option<bool>,
}

#[derive(Deserialize)]
struct AccountQ {
    account_id: Option<i64>,
}

pub fn router() -> Router<AppState> {
    Router::new()
        .route("/", get(list_admin).put(upsert_admin))
        .route("/{id}", delete(remove_admin))
}

pub fn client_router() -> Router<AppState> {
    Router::new()
        .route("/", get(list_client).put(upsert_client))
        .route("/{id}", delete(remove_client))
}

async fn list_rows(state: &AppState, aid: i64) -> Result<Vec<RowOut>, ApiError> {
    let rows = sqlx::query(
        "SELECT id, account_id, domain_id, domain, extensions, allow_empty, allowed_domains, status, created_at \
         FROM hotlink WHERE account_id = ? ORDER BY id DESC",
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
                extensions: r.get(4),
                allow_empty: r.get::<i64, _>(5) != 0,
                allowed_domains: r.get(6),
                status: r.get::<i64, _>(7) != 0,
                created_at: r.get(8),
            })
        })
        .collect()
}

async fn upsert(state: &AppState, aid: i64, body: UpsertBody) -> Result<RowOut, ApiError> {
    let name: String = sqlx::query_scalar(
        "SELECT name FROM domains WHERE id = ? AND account_id = ? AND status = 'active'",
    )
    .bind(body.domain_id)
    .bind(aid)
    .fetch_optional(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?
    .ok_or_else(|| ApiError::new(StatusCode::NOT_FOUND, "Domain not found"))?;

    let extensions = body
        .extensions
        .unwrap_or_else(|| "jpg|jpeg|png|gif|webp|bmp|svg|css|js|woff|woff2".to_string());
    let allow_empty = body.allow_empty.unwrap_or(true);
    let allowed_domains = body.allowed_domains.filter(|s| !s.trim().is_empty());
    let status = body.status.unwrap_or(true);

    sqlx::query(
        "INSERT INTO hotlink (account_id, domain_id, domain, extensions, allow_empty, allowed_domains, status) \
         VALUES (?, ?, ?, ?, ?, ?, ?) \
         ON CONFLICT(domain_id) DO UPDATE SET \
         extensions = excluded.extensions, allow_empty = excluded.allow_empty, \
         allowed_domains = excluded.allowed_domains, status = excluded.status",
    )
    .bind(aid)
    .bind(body.domain_id)
    .bind(&name)
    .bind(&extensions)
    .bind(allow_empty)
    .bind(&allowed_domains)
    .bind(status)
    .execute(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?;

    refresh_security(state, aid, body.domain_id, &name).await?;

    let row = sqlx::query(
        "SELECT id, account_id, domain_id, domain, extensions, allow_empty, allowed_domains, status, created_at \
         FROM hotlink WHERE domain_id = ?",
    )
    .bind(body.domain_id)
    .fetch_one(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?;
    Ok(RowOut {
        id: row.get(0),
        account_id: row.get(1),
        domain_id: row.get(2),
        domain: row.get(3),
        extensions: row.get(4),
        allow_empty: row.get::<i64, _>(5) != 0,
        allowed_domains: row.get(6),
        status: row.get::<i64, _>(7) != 0,
        created_at: row.get(8),
    })
}

async fn remove(state: &AppState, aid: i64, id: i64) -> Result<(), ApiError> {
    let row = sqlx::query("SELECT domain_id, domain FROM hotlink WHERE id = ? AND account_id = ?")
        .bind(id)
        .bind(aid)
        .fetch_optional(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?;
    let Some(row) = row else {
        return Err(ApiError::new(StatusCode::NOT_FOUND, "Hotlink protection not found"));
    };
    let domain_id: i64 = row.get(0);
    let domain: String = row.get(1);

    sqlx::query("DELETE FROM hotlink WHERE id = ? AND account_id = ?")
        .bind(id)
        .bind(aid)
        .execute(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?;

    refresh_security(state, aid, domain_id, &domain).await?;
    Ok(())
}

// clients
async fn list_client(State(state): State<AppState>, headers: HeaderMap) -> Result<Json<Vec<RowOut>>, ApiError> {
    let (aid, _) = bearer_account(&state, &headers).await?;
    Ok(Json(list_rows(&state, aid).await?))
}

async fn upsert_client(
    State(state): State<AppState>,
    headers: HeaderMap,
    Json(body): Json<UpsertBody>,
) -> Result<Json<RowOut>, ApiError> {
    let (aid, _) = bearer_account(&state, &headers).await?;
    Ok(Json(upsert(&state, aid, body).await?))
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

async fn upsert_admin(
    State(state): State<AppState>,
    Query(q): Query<AccountQ>,
    Json(body): Json<UpsertBody>,
) -> Result<Json<RowOut>, ApiError> {
    let aid = require_account(q.account_id)?;
    Ok(Json(upsert(&state, aid, body).await?))
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