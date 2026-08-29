use axum::extract::{Path, State};
use axum::http::StatusCode;
use axum::routing::get;
use serde::{Deserialize, Serialize};
use sqlx::FromRow;

use axum::Json;
use axum::Router;

use crate::db::AppState;
use crate::error::{internal_error, ApiError};

#[derive(Debug, Serialize, FromRow)]
pub struct Account {
    pub id: i64,
    pub username: String,
    pub email: String,
    pub package_id: i64,
    pub status: String,
    pub name: Option<String>,
}

#[derive(Debug, Deserialize)]
pub struct CreateAccount {
    pub username: String,
    pub email: String,
    pub password: Option<String>,
    pub package_id: i64,
    pub name: Option<String>,
}

#[derive(Debug, Deserialize)]
pub struct UpdateAccount {
    pub email: Option<String>,
    pub password: Option<String>,
    pub package_id: Option<i64>,
    pub status: Option<String>,
    pub name: Option<String>,
}

pub fn router() -> Router<AppState> {
    Router::new()
        .route("/", get(list).post(create))
        .route("/{id}", get(get_one).put(update).delete(remove))
}

async fn list(State(state): State<AppState>) -> Result<Json<Vec<Account>>, ApiError> {
    let accounts = sqlx::query_as::<_, Account>("SELECT * FROM accounts ORDER BY id")
        .fetch_all(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?;
    Ok(Json(accounts))
}

async fn get_one(
    State(state): State<AppState>,
    Path((_sess, id)): Path<(String, i64)>,
) -> Result<Json<Account>, ApiError> {
    let account = sqlx::query_as::<_, Account>("SELECT * FROM accounts WHERE id = ?")
        .bind(id)
        .fetch_optional(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?
        .ok_or_else(|| ApiError::new(StatusCode::NOT_FOUND, "Account not found"))?;
    Ok(Json(account))
}

async fn create(
    State(state): State<AppState>,
    Json(input): Json<CreateAccount>,
) -> Result<(StatusCode, Json<Account>), ApiError> {
    if input.username.trim().is_empty() || !input.email.contains('@') {
        return Err(ApiError::new(
            StatusCode::BAD_REQUEST,
            "Invalid username or email",
        ));
    }

    let password_hash = match input.password.as_deref() {
        Some(p) if p.len() >= 6 => Some(
            crate::auth::hash_password(p).map_err(|e| internal_error(e.into()))?,
        ),
        _ => None,
    };

    let result = sqlx::query(
        "INSERT INTO accounts (username, email, password_hash, package_id, name) VALUES (?, ?, ?, ?, ?)",
    )
    .bind(input.username.trim())
    .bind(input.email.trim())
    .bind(password_hash)
    .bind(input.package_id)
    .bind(input.name)
    .execute(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?;

    let account = sqlx::query_as::<_, Account>("SELECT * FROM accounts WHERE id = ?")
        .bind(result.last_insert_rowid())
        .fetch_one(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?;

    trace::log_provision(&format!("create account {}", account.username));

    Ok((StatusCode::CREATED, Json(account)))
}

async fn update(
    State(state): State<AppState>,
    Path((_sess, id)): Path<(String, i64)>,
    Json(input): Json<UpdateAccount>,
) -> Result<Json<Account>, ApiError> {
    let current = sqlx::query_as::<_, Account>("SELECT * FROM accounts WHERE id = ?")
        .bind(id)
        .fetch_optional(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?
        .ok_or_else(|| ApiError::new(StatusCode::NOT_FOUND, "Account not found"))?;

    let email = input.email.unwrap_or(current.email);
    let package_id = input.package_id.unwrap_or(current.package_id);
    let status = input.status.unwrap_or(current.status);
    let name = input.name;

    let password_hash = match input.password.as_deref() {
        Some(p) if p.len() >= 6 => Some(
            crate::auth::hash_password(p).map_err(|e| internal_error(e.into()))?,
        ),
        _ => None,
    };

    if let Some(hash) = password_hash {
        sqlx::query("UPDATE accounts SET email = ?, package_id = ?, status = ?, name = ?, password_hash = ? WHERE id = ?")
            .bind(&email)
            .bind(package_id)
            .bind(&status)
            .bind(&name)
            .bind(hash)
            .bind(id)
            .execute(&state.db)
            .await
            .map_err(|e| internal_error(e.into()))?;
    } else {
        sqlx::query("UPDATE accounts SET email = ?, package_id = ?, status = ?, name = ? WHERE id = ?")
            .bind(&email)
            .bind(package_id)
            .bind(&status)
            .bind(&name)
            .bind(id)
            .execute(&state.db)
            .await
            .map_err(|e| internal_error(e.into()))?;
    }

    let account = sqlx::query_as::<_, Account>("SELECT * FROM accounts WHERE id = ?")
        .bind(id)
        .fetch_one(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?;
    Ok(Json(account))
}

async fn remove(
    State(state): State<AppState>,
    Path((_sess, id)): Path<(String, i64)>,
) -> Result<StatusCode, ApiError> {
    let result = sqlx::query("DELETE FROM accounts WHERE id = ?")
        .bind(id)
        .execute(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?;

    if result.rows_affected() == 0 {
        return Err(ApiError::new(
            StatusCode::NOT_FOUND,
            "Account not found",
        ));
    }

    trace::log_provision(&format!("delete account {id}"));
    Ok(StatusCode::NO_CONTENT)
}

mod trace {
    pub fn log_provision(msg: &str) {
        tracing::info!("[provision-stub] {msg}");
    }
}