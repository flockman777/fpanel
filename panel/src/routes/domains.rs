use axum::extract::{Path, State};
use axum::http::{header, StatusCode};
use axum::routing::{delete, get};
use axum::{Json, Router};
use serde::{Deserialize, Serialize};
use sqlx::FromRow;
use sqlx::Row;

use crate::db::AppState;
use crate::error::{internal_error, ApiError};
use crate::provision;

#[derive(Debug, Serialize, FromRow)]
pub struct Domain {
    pub id: i64,
    pub account_id: i64,
    pub name: String,
    pub kind: String,
    pub status: String,
    pub created_at: String,
}

#[derive(Debug, Serialize)]
pub struct DomainAdmin {
    pub id: i64,
    pub account_id: i64,
    pub username: String,
    pub name: String,
    pub kind: String,
    pub status: String,
    pub created_at: String,
    pub docroot: String,
}

#[derive(Debug, Serialize)]
pub struct DomainClient {
    pub id: i64,
    pub account_id: i64,
    pub name: String,
    pub kind: String,
    pub status: String,
    pub created_at: String,
    pub docroot: String,
}

#[derive(Debug, Deserialize)]
pub struct CreateDomain {
    pub account_id: i64,
    pub name: String,
    pub kind: Option<String>,
}

#[derive(Debug, Deserialize)]
pub struct ClientCreateDomain {
    pub name: String,
    pub kind: Option<String>,
}

pub fn router() -> Router<AppState> {
    Router::new()
        .route("/", get(list).post(create))
        .route("/{id}", get(get_one).delete(remove))
}

pub fn client_router() -> Router<AppState> {
    Router::new()
        .route("/", get(client_list).post(client_create))
        .route("/{id}", delete(client_remove))
}

async fn list(State(state): State<AppState>) -> Result<Json<Vec<DomainAdmin>>, ApiError> {
    let rows = sqlx::query(
        "SELECT d.id, d.account_id, a.username, d.name, d.kind, d.status, d.created_at \
         FROM domains d JOIN accounts a ON a.id = d.account_id ORDER BY d.name",
    )
    .fetch_all(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?;

    let mut out = Vec::with_capacity(rows.len());
    for r in rows {
        let username: String = r.get(2);
        let kind: String = r.get(4);
        let name: String = r.get(3);
        out.push(DomainAdmin {
            id: r.get(0),
            account_id: r.get(1),
            username: username.clone(),
            name: name.clone(),
            kind: kind.clone(),
            status: r.get(5),
            created_at: r.get(6),
            docroot: provision::vhost_root(&username, &kind, &name)
                .to_string_lossy()
                .into_owned(),
        });
    }
    Ok(Json(out))
}

async fn get_one(
    State(state): State<AppState>,
    Path((_sess, id)): Path<(String, i64)>,
) -> Result<Json<Domain>, ApiError> {
    let domain = sqlx::query_as::<_, Domain>("SELECT * FROM domains WHERE id = ?")
        .bind(id)
        .fetch_optional(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?
        .ok_or_else(|| ApiError::new(StatusCode::NOT_FOUND, "Domain not found"))?;
    Ok(Json(domain))
}

pub async fn create(
    State(state): State<AppState>,
    Json(input): Json<CreateDomain>,
) -> Result<(StatusCode, Json<Domain>), ApiError> {
    let username = sqlx::query_scalar::<_, String>("SELECT username FROM accounts WHERE id = ?")
        .bind(input.account_id)
        .fetch_optional(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?
        .ok_or_else(|| ApiError::new(StatusCode::NOT_FOUND, "Account not found"))?;

    check_domain(state.clone(), input.account_id, &input.name, input.kind.as_deref()).await?;

    let domain = insert_domain(&state, input.account_id, &input.name, input.kind.as_deref().unwrap_or("main"))
        .await?;
    provision::write_vhost(&domain.name, &username, &domain.kind);
    crate::routes::dns::seed_domain_dns(&state, domain.id).await?;
    Ok((StatusCode::CREATED, Json(domain)))
}

async fn remove(
    State(state): State<AppState>,
    Path((_sess, id)): Path<(String, i64)>,
) -> Result<StatusCode, ApiError> {
    delete_domain(&state, id, None, true).await?;
    Ok(StatusCode::NO_CONTENT)
}

async fn client_list(State(state): State<AppState>, headers: axum::http::HeaderMap) -> Result<Json<Vec<DomainClient>>, ApiError> {
    let (account_id, username) = bearer_account(&state, &headers).await?;
    let rows = sqlx::query_as::<_, Domain>(
        "SELECT * FROM domains WHERE account_id = ? ORDER BY kind, name",
    )
    .bind(account_id)
    .fetch_all(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?;
    let out = rows
        .into_iter()
        .map(|d| DomainClient {
            id: d.id,
            account_id: d.account_id,
            name: d.name.clone(),
            kind: d.kind.clone(),
            status: d.status,
            created_at: d.created_at,
            docroot: provision::vhost_root(&username, &d.kind, &d.name)
                .to_string_lossy()
                .into_owned(),
        })
        .collect();
    Ok(Json(out))
}

async fn client_create(
    State(state): State<AppState>,
    headers: axum::http::HeaderMap,
    Json(input): Json<ClientCreateDomain>,
) -> Result<(StatusCode, Json<Domain>), ApiError> {
    let (account_id, username) = bearer_account(&state, &headers).await?;
    let kind = match input.kind.as_deref() {
        Some("sub") => "sub",
        Some("main") | Some("addon") | None => "addon",
        Some(other) => {
            return Err(ApiError::new(
                StatusCode::BAD_REQUEST,
                format!("Unsupported kind: {other}"),
            ))
        }
    };
    check_domain(state.clone(), account_id, &input.name, Some(kind)).await?;
    let domain = insert_domain(&state, account_id, &input.name, kind).await?;
    provision::write_vhost(&domain.name, &username, kind);
    crate::routes::dns::seed_domain_dns(&state, domain.id).await?;
    Ok((StatusCode::CREATED, Json(domain)))
}

async fn client_remove(
    State(state): State<AppState>,
    headers: axum::http::HeaderMap,
    Path((_sess, id)): Path<(String, i64)>,
) -> Result<StatusCode, ApiError> {
    let (account_id, _username) = bearer_account(&state, &headers).await?;
    delete_domain(&state, id, Some(account_id), true).await?;
    Ok(StatusCode::NO_CONTENT)
}

async fn check_domain(
    state: AppState,
    account_id: i64,
    name: &str,
    kind: Option<&str>,
) -> Result<(), ApiError> {
    let name = name.trim().to_lowercase();
    match kind.unwrap_or("main") {
        "main" | "sub" | "alias" | "addon" => {}
        _ => {
            return Err(ApiError::new(
                StatusCode::BAD_REQUEST,
                "Kind must be main, sub, alias or addon",
            ))
        }
    }
    if !valid_domain(&name) {
        return Err(ApiError::new(
            StatusCode::BAD_REQUEST,
            "Invalid domain name",
        ));
    }
    if kind == Some("sub") && name.split('.').count() > 1 {
        let owned = sqlx::query_scalar::<_, String>(
            "SELECT name FROM domains WHERE account_id = ? AND status = 'active'",
        )
        .bind(account_id)
        .fetch_all(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?;
        if !owned.iter().any(|d| name.ends_with(&format!(".{d}")) && name != *d) {
            return Err(ApiError::new(
                StatusCode::BAD_REQUEST,
                "Subdomain must be under a domain owned by this account",
            ));
        }
    }
    let limit = sqlx::query_scalar::<_, i64>(
        "SELECT p.domain_limit FROM packages p JOIN accounts a ON a.package_id = p.id WHERE a.id = ?",
    )
    .bind(account_id)
    .fetch_optional(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?
    .ok_or_else(|| ApiError::new(StatusCode::NOT_FOUND, "Account not found"))?;

    let used = sqlx::query_scalar::<_, i64>(
        "SELECT COUNT(*) FROM domains WHERE account_id = ? AND status = 'active'",
    )
    .bind(account_id)
    .fetch_one(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?;

    if used >= limit {
        return Err(ApiError::new(
            StatusCode::CONFLICT,
            format!("Domain limit reached ({used}/{limit})"),
        ));
    }
    Ok(())
}

async fn insert_domain(
    state: &AppState,
    account_id: i64,
    name: &str,
    kind: &str,
) -> Result<Domain, ApiError> {
    let result = sqlx::query("INSERT INTO domains (account_id, name, kind) VALUES (?, ?, ?)")
        .bind(account_id)
        .bind(name.trim().to_lowercase())
        .bind(kind)
        .execute(&state.db)
        .await
        .map_err(|e| {
            if e.to_string().contains("UNIQUE") {
                ApiError::new(StatusCode::CONFLICT, "Domain already exists")
            } else {
                internal_error(e.into())
            }
        })?;

    sqlx::query_as::<_, Domain>("SELECT * FROM domains WHERE id = ?")
        .bind(result.last_insert_rowid())
        .fetch_one(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))
}

async fn delete_domain(
    state: &AppState,
    id: i64,
    account_id: Option<i64>,
    remove_vhost: bool,
) -> Result<(), ApiError> {
    let q = if account_id.is_some() {
        "SELECT id, name, kind FROM domains WHERE id = ? AND account_id = ?"
    } else {
        "SELECT id, name, kind FROM domains WHERE id = ?"
    };
    let row = sqlx::query(q)
        .bind(id)
        .bind(account_id)
        .fetch_optional(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?;

    let Some(row) = row else {
        return Err(ApiError::new(StatusCode::NOT_FOUND, "Domain not found"));
    };
    let name: String = row.get(1);
    let kind: String = row.get(2);

    sqlx::query("DELETE FROM domains WHERE id = ?")
        .bind(id)
        .execute(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?;

    if remove_vhost {
        provision::remove_vhost(&name);
        provision::remove_mail(&name);
    }
    crate::routes::dns::cleanup_domain_dns(&state, id, &name, &kind).await?;
    Ok(())
}

pub(crate) async fn bearer_account(
    state: &AppState,
    headers: &axum::http::HeaderMap,
) -> Result<(i64, String), ApiError> {
    let Some(token) = headers
        .get(header::AUTHORIZATION)
        .and_then(|v| v.to_str().ok())
        .and_then(|v| v.strip_prefix("Bearer "))
    else {
        return Err(ApiError::new(StatusCode::UNAUTHORIZED, "Token not found"));
    };
    let claims = crate::auth::verify_and_get_claims_with_state(&state.jwt_secret, token).await?;
    let username = sqlx::query_scalar::<_, String>("SELECT username FROM accounts WHERE id = ?")
        .bind(claims.sub)
        .fetch_optional(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?
        .ok_or_else(|| ApiError::new(StatusCode::NOT_FOUND, "Account not found"))?;
    Ok((claims.sub, username))
}

fn valid_domain(name: &str) -> bool {
    if name.is_empty() || name.len() > 253 {
        return false;
    }
    let parts: Vec<&str> = name.split('.').collect();
    if parts.iter().any(|part| {
        part.is_empty()
            || part.len() > 63
            || part.starts_with('-')
            || part.ends_with('-')
            || !part.chars().all(|c| c.is_ascii_alphanumeric() || c == '-')
    }) {
        return false;
    }
    true
}