use axum::extract::{Path, Query, State};
use axum::http::{HeaderMap, StatusCode};
use axum::routing::{delete, get, post};
use axum::{Json, Router};
use serde::{Deserialize, Serialize};
use sqlx::Row;

use crate::db::AppState;
use crate::error::{internal_error, ApiError};
use crate::provision;
use crate::routes::domains::bearer_account;

#[derive(Serialize)]
struct RowOut {
    id: i64,
    account_id: i64,
    username: String,
    auth_type: String,
    public_key: Option<String>,
    authorized_keys: Option<String>,
    status: String,
    created_at: String,
}

#[derive(Serialize)]
struct KeyResponse {
    username: String,
    private_key: String,
    public_key: String,
}

#[derive(Deserialize)]
struct CreateBody {
    account_id: Option<i64>,
    username: String,
    auth_type: Option<String>,
    public_key: Option<String>,
    authorized_keys: Option<String>,
}

#[derive(Deserialize)]
struct AccountQ {
    account_id: Option<i64>,
}

pub fn router() -> Router<AppState> {
    Router::new()
        .route("/", get(list_admin).post(create_admin))
        .route("/{id}/keys", post(keys_admin))
        .route("/{id}", delete(remove_admin))
}

pub fn client_router() -> Router<AppState> {
    Router::new()
        .route("/", get(list_client).post(create_client))
        .route("/{id}/keys", post(keys_client))
        .route("/{id}", delete(remove_client))
}

fn valid_username(u: &str) -> Result<String, ApiError> {
    let s = u.trim().to_string();
    if s.is_empty() {
        return Err(ApiError::new(StatusCode::BAD_REQUEST, "username is required"));
    }
    let ok = !s.contains(' ')
        && !s.chars().any(|c| !(c.is_ascii_alphanumeric() || c == '.' || c == '_' || c == '-'));
    if !ok {
        return Err(ApiError::new(
            StatusCode::BAD_REQUEST,
            "username may only contain letters, digits, '.', '_' and '-'",
        ));
    }
    Ok(s)
}

async fn list_rows(state: &AppState, aid: i64) -> Result<Vec<RowOut>, ApiError> {
    let rows = sqlx::query(
        "SELECT id, account_id, username, auth_type, public_key, authorized_keys, status, created_at \
         FROM ssh_access WHERE account_id = ? ORDER BY id DESC",
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
                username: r.get(2),
                auth_type: r.get(3),
                public_key: r.get(4),
                authorized_keys: r.get(5),
                status: r.get(6),
                created_at: r.get(7),
            })
        })
        .collect()
}

async fn generate_keys() -> Result<(String, String), ApiError> {
    let tmp_priv = std::env::temp_dir().join(format!("fpanel_ed_{}", std::process::id()));
    let out = std::process::Command::new("openssl")
        .args(["genpkey", "-algorithm", "ed25519", "-out"])
        .arg(&tmp_priv)
        .output()
        .map_err(|e| internal_error(e.into()))?;
    if !out.status.success() {
        return Err(internal_error(anyhow::anyhow!("openssl genpkey failed")));
    }
    let private_key = std::fs::read_to_string(&tmp_priv).map_err(|e| internal_error(e.into()))?;
    let pub_out = std::process::Command::new("openssl")
        .args(["pkey", "-in"])
        .arg(&tmp_priv)
        .arg("-pubout")
        .output()
        .map_err(|e| internal_error(e.into()))?;
    let _ = std::fs::remove_file(&tmp_priv);
    if !pub_out.status.success() {
        return Err(internal_error(anyhow::anyhow!("openssl pubkey failed")));
    }
    Ok((private_key, String::from_utf8_lossy(&pub_out.stdout).to_string()))
}

async fn provision_account(state: &AppState, aid: i64) -> Result<String, ApiError> {
    let acc: String = sqlx::query_scalar("SELECT username FROM accounts WHERE id = ?")
        .bind(aid)
        .fetch_one(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?;
    let users: Vec<(String, String, Option<String>, Option<String>, String)> = sqlx::query_as(
        "SELECT username, auth_type, public_key, authorized_keys, status FROM ssh_access WHERE account_id = ?",
    )
    .bind(aid)
    .fetch_all(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?;

    let base = format!("{acc}_");
    if let Ok(entries) = std::fs::read_dir(provision::ssh_dir()) {
        for entry in entries.flatten() {
            let fname = entry.file_name().to_string_lossy().to_string();
            if fname.starts_with(&base) {
                let _ = std::fs::remove_file(entry.path());
            }
        }
    }

    for (username, auth_type, public_key, authorized_keys, status) in &users {
        provision::write_ssh(&format!("{acc}_{username}"), &provision::SshProvision {
            account: acc.clone(),
            username: username.clone(),
            auth_type: auth_type.clone(),
            public_key: public_key.clone(),
            authorized_keys: authorized_keys.clone(),
            status: status.clone(),
        });
    }
    Ok(acc)
}

async fn create(state: &AppState, aid: i64, body: CreateBody) -> Result<(RowOut, Option<String>), ApiError> {
    let username = valid_username(&body.username)?;
    let auth_type = body.auth_type.unwrap_or_else(|| "key".to_string());
    if auth_type != "key" && auth_type != "password" {
        return Err(ApiError::new(StatusCode::BAD_REQUEST, "auth_type must be 'key' or 'password'"));
    }

    let (public_key, generated_private) = match (auth_type.as_str(), &body.public_key) {
        ("key", Some(pk)) if !pk.trim().is_empty() => (Some(pk.trim().to_string()), None),
        ("key", _) => {
            let (priv_k, pub_k) = generate_keys().await?;
            (Some(pub_k), Some(priv_k))
        }
        _ => (None, None),
    };
    let authorized_keys = match auth_type.as_str() {
        "key" => body
            .authorized_keys
            .filter(|s| !s.trim().is_empty())
            .or(public_key.clone()),
        _ => body.authorized_keys.filter(|s| !s.trim().is_empty()),
    };

    let result = sqlx::query(
        "INSERT INTO ssh_access (account_id, username, auth_type, public_key, authorized_keys, status) \
         VALUES (?, ?, ?, ?, ?, 'active')",
    )
    .bind(aid)
    .bind(&username)
    .bind(&auth_type)
    .bind(&public_key)
    .bind(&authorized_keys)
    .execute(&state.db)
    .await
    .map_err(|e| {
        if e.to_string().contains("UNIQUE") {
            ApiError::new(StatusCode::CONFLICT, "This SSH username already exists")
        } else {
            internal_error(e.into())
        }
    })?;

    provision_account(state, aid).await?;

    let row = RowOut {
        id: result.last_insert_rowid(),
        account_id: aid,
        username,
        auth_type,
        public_key,
        authorized_keys,
        status: "active".to_string(),
        created_at: String::new(),
    };
    Ok((row, generated_private))
}

async fn regenerate(state: &AppState, aid: i64, id: i64) -> Result<KeyResponse, ApiError> {
    let row = sqlx::query("SELECT username FROM ssh_access WHERE id = ? AND account_id = ?")
        .bind(id)
        .bind(aid)
        .fetch_optional(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?;
    let Some(r) = row else {
        return Err(ApiError::new(StatusCode::NOT_FOUND, "SSH user not found"));
    };
    let username: String = r.get(0);
    let (private_key, public_key) = generate_keys().await?;

    sqlx::query("UPDATE ssh_access SET public_key = ?, status = 'active' WHERE id = ? AND account_id = ?")
        .bind(&public_key)
        .bind(id)
        .bind(aid)
        .execute(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?;

    provision_account(state, aid).await?;
    Ok(KeyResponse { username, private_key, public_key })
}

async fn remove(state: &AppState, aid: i64, id: i64) -> Result<(), ApiError> {
    let result = sqlx::query("DELETE FROM ssh_access WHERE id = ? AND account_id = ?")
        .bind(id)
        .bind(aid)
        .execute(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?;
    if result.rows_affected() == 0 {
        return Err(ApiError::new(StatusCode::NOT_FOUND, "SSH user not found"));
    }
    provision_account(state, aid).await?;
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
) -> Result<Json<serde_json::Value>, ApiError> {
    let (aid, _) = bearer_account(&state, &headers).await?;
    let (row, private) = create(&state, aid, body).await?;
    Ok(Json(serde_json::json!({ "user": row, "private_key": private })))
}

async fn keys_client(
    State(state): State<AppState>,
    headers: HeaderMap,
    Path((_sess, id)): Path<(String, i64)>,
) -> Result<Json<KeyResponse>, ApiError> {
    let (aid, _) = bearer_account(&state, &headers).await?;
    Ok(Json(regenerate(&state, aid, id).await?))
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
) -> Result<Json<serde_json::Value>, ApiError> {
    let aid = body.account_id.or(q.account_id).ok_or_else(|| {
        ApiError::new(StatusCode::BAD_REQUEST, "account_id is required")
    })?;
    let (row, private) = create(&state, aid, body).await?;
    Ok(Json(serde_json::json!({ "user": row, "private_key": private })))
}

async fn keys_admin(
    State(state): State<AppState>,
    Query(q): Query<AccountQ>,
    Path((_sess, id)): Path<(String, i64)>,
) -> Result<Json<KeyResponse>, ApiError> {
    let aid = require_account(q.account_id)?;
    Ok(Json(regenerate(&state, aid, id).await?))
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