use axum::extract::{Path, State};
use axum::http::StatusCode;
use axum::routing::{delete, get};
use axum::{Json, Router};
use serde::{Deserialize, Serialize};
use sqlx::Row;

use crate::db::AppState;
use crate::error::{internal_error, ApiError};
use crate::provision;

#[derive(Debug, Serialize)]
pub struct Redirect {
    pub id: i64,
    pub account_id: i64,
    pub domain_id: Option<i64>,
    pub domain: Option<String>,
    pub from_path: String,
    pub to_url: String,
    pub permanent: bool,
    pub status: String,
    pub created_at: String,
}

#[derive(Debug, Serialize)]
pub struct RedirectAdmin {
    pub id: i64,
    pub account_id: i64,
    pub username: String,
    pub domain_id: Option<i64>,
    pub domain: Option<String>,
    pub from_path: String,
    pub to_url: String,
    pub permanent: bool,
    pub status: String,
    pub created_at: String,
}

#[derive(Debug, Deserialize)]
pub struct CreateRedirect {
    pub account_id: i64,
    pub domain_id: Option<i64>,
    pub from_path: Option<String>,
    pub to_url: String,
    pub permanent: Option<bool>,
}

#[derive(Debug, Deserialize)]
pub struct ClientCreateRedirect {
    pub domain_id: Option<i64>,
    pub from_path: Option<String>,
    pub to_url: String,
    pub permanent: Option<bool>,
}

pub fn router() -> Router<AppState> {
    Router::new()
        .route("/", get(list).post(create))
        .route("/{id}", delete(remove))
}

pub fn client_router() -> Router<AppState> {
    Router::new()
        .route("/", get(client_list).post(client_create))
        .route("/{id}", delete(client_remove))
}

async fn list(State(state): State<AppState>) -> Result<Json<Vec<RedirectAdmin>>, ApiError> {
    let rows = sqlx::query(
        "SELECT r.id, r.account_id, a.username, r.domain_id, d.name AS domain, r.from_path, r.to_url, \
                r.permanent, r.status, r.created_at \
         FROM redirects r \
         JOIN accounts a ON a.id = r.account_id \
         LEFT JOIN domains d ON d.id = r.domain_id \
         ORDER BY r.created_at DESC",
    )
    .fetch_all(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?;

    let mut out = Vec::with_capacity(rows.len());
    for r in rows {
        out.push(RedirectAdmin {
            id: r.get(0),
            account_id: r.get(1),
            username: r.get(2),
            domain_id: r.get(3),
            domain: r.get(4),
            from_path: r.get(5),
            to_url: r.get(6),
            permanent: r.get::<i64, _>(7) != 0,
            status: r.get(8),
            created_at: r.get(9),
        });
    }
    Ok(Json(out))
}

async fn create(
    State(state): State<AppState>,
    Json(input): Json<CreateRedirect>,
) -> Result<(StatusCode, Json<Redirect>), ApiError> {
    check_redirect(&state, input.account_id, input.domain_id, input.from_path.as_deref()).await?;

    let permanent = input.permanent.unwrap_or(true);
    let from_path = normalize_from_path(input.from_path.as_deref());
    let rid = insert_redirect(
        &state,
        input.account_id,
        input.domain_id,
        &from_path,
        &input.to_url,
        permanent,
    )
    .await?;
    sync_redirects(&state, input.account_id, input.domain_id).await;
    Ok((StatusCode::CREATED, Json(rid)))
}

async fn remove(
    State(state): State<AppState>,
    Path((_sess, id)): Path<(String, i64)>,
) -> Result<StatusCode, ApiError> {
    if let Some((account_id, did)) = delete_redirect(&state, id, None).await? {
        sync_redirects(&state, account_id, did).await;
    }
    Ok(StatusCode::NO_CONTENT)
}

async fn client_list(
    State(state): State<AppState>,
    headers: axum::http::HeaderMap,
) -> Result<Json<Vec<Redirect>>, ApiError> {
    let (account_id, _username) = super::domains::bearer_account(&state, &headers).await?;
    let redirects = sqlx::query_as::<_, RedirectRow>(
        "SELECT r.id, r.account_id, r.domain_id, d.name AS domain, r.from_path, r.to_url, \
                r.permanent, r.status, r.created_at \
         FROM redirects r LEFT JOIN domains d ON d.id = r.domain_id \
         WHERE r.account_id = ? ORDER BY r.created_at DESC",
    )
    .bind(account_id)
    .fetch_all(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?;
    Ok(Json(redirects.into_iter().map(Redirect::from).collect()))
}

async fn client_create(
    State(state): State<AppState>,
    headers: axum::http::HeaderMap,
    Json(input): Json<ClientCreateRedirect>,
) -> Result<(StatusCode, Json<Redirect>), ApiError> {
    let (account_id, _username) = super::domains::bearer_account(&state, &headers).await?;

    if let Some(did) = input.domain_id {
        let owned = sqlx::query_scalar::<_, i64>(
            "SELECT COUNT(*) FROM domains WHERE id = ? AND account_id = ?",
        )
        .bind(did)
        .bind(account_id)
        .fetch_one(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?;
        if owned == 0 {
            return Err(ApiError::new(
                StatusCode::BAD_REQUEST,
                "Domain does not belong to this account",
            ));
        }
    }

    check_redirect(&state, account_id, input.domain_id, input.from_path.as_deref()).await?;
    let permanent = input.permanent.unwrap_or(true);
    let from_path = normalize_from_path(input.from_path.as_deref());
    let rid = insert_redirect(
        &state,
        account_id,
        input.domain_id,
        &from_path,
        &input.to_url,
        permanent,
    )
    .await?;
    sync_redirects(&state, account_id, input.domain_id).await;
    Ok((StatusCode::CREATED, Json(rid)))
}

async fn client_remove(
    State(state): State<AppState>,
    headers: axum::http::HeaderMap,
    Path((_sess, id)): Path<(String, i64)>,
) -> Result<StatusCode, ApiError> {
    let (account_id, _username) = super::domains::bearer_account(&state, &headers).await?;
    if let Some((_account_id, did)) = delete_redirect(&state, id, Some(account_id)).await? {
        sync_redirects(&state, account_id, did).await;
    }
    Ok(StatusCode::NO_CONTENT)
}

#[derive(Debug, sqlx::FromRow)]
struct RedirectRow {
    id: i64,
    account_id: i64,
    domain_id: Option<i64>,
    domain: Option<String>,
    from_path: String,
    to_url: String,
    permanent: i64,
    status: String,
    created_at: String,
}

impl From<RedirectRow> for Redirect {
    fn from(r: RedirectRow) -> Self {
        Redirect {
            id: r.id,
            account_id: r.account_id,
            domain_id: r.domain_id,
            domain: r.domain,
            from_path: r.from_path,
            to_url: r.to_url,
            permanent: r.permanent != 0,
            status: r.status,
            created_at: r.created_at,
        }
    }
}

fn normalize_from_path(path: Option<&str>) -> String {
    let p = path.unwrap_or("/").trim().to_string();
    if p.is_empty() {
        return "/".into();
    }
    if !p.starts_with('/') {
        "/".to_string() + &p
    } else {
        p
    }
}

async fn check_redirect(
    state: &AppState,
    account_id: i64,
    domain_id: Option<i64>,
    from_path: Option<&str>,
) -> Result<(), ApiError> {
    let account_exists = sqlx::query_scalar::<_, i64>(
        "SELECT COUNT(*) FROM accounts WHERE id = ?",
    )
    .bind(account_id)
    .fetch_one(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?;
    if account_exists == 0 {
        return Err(ApiError::new(StatusCode::NOT_FOUND, "Account not found"));
    }

    let path_only = from_path.is_none() || from_path.unwrap_or("").trim().is_empty() || from_path.unwrap_or("").trim() == "/";
    if domain_id.is_none() && path_only {
        return Err(ApiError::new(
            StatusCode::BAD_REQUEST,
            "from_path must be specified when no domain is selected",
        ));
    }

    let dup = if let Some(did) = domain_id {
        sqlx::query_scalar::<_, i64>(
            "SELECT COUNT(*) FROM redirects WHERE account_id = ? AND domain_id = ? AND from_path = ?",
        )
        .bind(account_id)
        .bind(did)
        .bind(normalize_from_path(from_path))
        .fetch_one(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?
    } else {
        sqlx::query_scalar::<_, i64>(
            "SELECT COUNT(*) FROM redirects WHERE account_id = ? AND domain_id IS NULL AND from_path = ?",
        )
        .bind(account_id)
        .bind(normalize_from_path(from_path))
        .fetch_one(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?
    };
    if dup > 0 {
        return Err(ApiError::new(
            StatusCode::CONFLICT,
            "A redirect for this domain/path already exists",
        ));
    }
    Ok(())
}

async fn insert_redirect(
    state: &AppState,
    account_id: i64,
    domain_id: Option<i64>,
    from_path: &str,
    to_url: &str,
    permanent: bool,
) -> Result<Redirect, ApiError> {
    if to_url.trim().is_empty() {
        return Err(ApiError::new(
            StatusCode::BAD_REQUEST,
            "to_url is required",
        ));
    }

    let result = sqlx::query(
        "INSERT INTO redirects (account_id, domain_id, from_path, to_url, permanent) \
         VALUES (?, ?, ?, ?, ?)",
    )
    .bind(account_id)
    .bind(domain_id)
    .bind(from_path)
    .bind(to_url.trim())
    .bind(if permanent { 1 } else { 0 })
    .execute(&state.db)
    .await
    .map_err(|e| {
        if e.to_string().contains("UNIQUE") {
            ApiError::new(StatusCode::CONFLICT, "A redirect for this domain/path already exists")
        } else if e.to_string().contains("FOREIGN KEY") {
            ApiError::new(StatusCode::BAD_REQUEST, "Referenced domain does not exist")
        } else {
            internal_error(e.into())
        }
    })?;

    let row = sqlx::query_as::<_, RedirectRow>(
        "SELECT r.id, r.account_id, r.domain_id, d.name AS domain, r.from_path, r.to_url, \
                r.permanent, r.status, r.created_at \
         FROM redirects r LEFT JOIN domains d ON d.id = r.domain_id WHERE r.id = ?",
    )
    .bind(result.last_insert_rowid())
    .fetch_one(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?;
    Ok(Redirect::from(row))
}

async fn delete_redirect(
    state: &AppState,
    id: i64,
    account_id: Option<i64>,
) -> Result<Option<(i64, Option<i64>)>, ApiError> {
    let q = if account_id.is_some() {
        "SELECT account_id, domain_id FROM redirects WHERE id = ? AND account_id = ?"
    } else {
        "SELECT account_id, domain_id FROM redirects WHERE id = ?"
    };
    let row = sqlx::query(q)
        .bind(id)
        .bind(account_id)
        .fetch_optional(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?;
    let Some(row) = row else {
        return Err(ApiError::new(StatusCode::NOT_FOUND, "Redirect not found"));
    };
    let info = Some((row.get::<i64, _>(0), row.get::<Option<i64>, _>(1)));

    sqlx::query("DELETE FROM redirects WHERE id = ?")
        .bind(id)
        .execute(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?;
    Ok(info)
}

async fn sync_redirects(state: &AppState, account_id: i64, scope: Option<i64>) {
    let rows: Vec<(i64, String)> = if let Some(did) = scope {
        sqlx::query_as("SELECT id, name FROM domains WHERE id = ? AND account_id = ? AND status = 'active'")
            .bind(did)
            .bind(account_id)
            .fetch_all(&state.db)
            .await
            .unwrap_or_default()
    } else {
        sqlx::query_as("SELECT id, name FROM domains WHERE account_id = ? AND status = 'active'")
            .bind(account_id)
            .fetch_all(&state.db)
            .await
            .unwrap_or_default()
    };

    for (did, name) in rows {
        let rules = sqlx::query(
            "SELECT from_path, to_url, permanent FROM redirects \
             WHERE account_id = ? AND status = 'active' AND (domain_id IS NULL OR domain_id = ?)",
        )
        .bind(account_id)
        .bind(did)
        .fetch_all(&state.db)
        .await
        .unwrap_or_default();

        let entries: Vec<provision::RedirectEntry> = rules
            .iter()
            .map(|r| provision::RedirectEntry {
                from: r.get(0),
                to: r.get(1),
                permanent: r.get::<i64, _>(2) != 0,
            })
            .collect();
        provision::write_redirects(&name, &entries);
    }
}