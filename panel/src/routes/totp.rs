use axum::extract::State;
use axum::http::{HeaderMap, StatusCode};
use axum::routing::{get, post};
use axum::{Json, Router};
use serde::{Deserialize, Serialize};

use crate::auth::{verify_and_get_claims_with_state};
use crate::db::AppState;
use crate::error::{internal_error, ApiError};
use crate::routes::auth::extract_bearer;
use crate::totp;

#[derive(Serialize)]
struct StatusOut {
    enabled: bool,
}

#[derive(Serialize)]
struct SetupOut {
    secret: String,
    uri: String,
    enabled: bool,
}

#[derive(Deserialize)]
struct CodeBody {
    code: String,
}

pub fn router() -> Router<AppState> {
    Router::new()
        .route("/status", get(status))
        .route("/setup", post(setup))
        .route("/enable", post(enable))
        .route("/disable", post(disable))
}

async fn user_id(state: &AppState, headers: &HeaderMap) -> Result<i64, ApiError> {
    let token = extract_bearer(headers)?;
    let claims = verify_and_get_claims_with_state(&state.jwt_secret, &token).await?;
    Ok(claims.sub)
}

async fn status(
    State(state): State<AppState>,
    headers: HeaderMap,
) -> Result<Json<StatusOut>, ApiError> {
    let uid = user_id(&state, &headers).await?;
    let enabled: i64 = sqlx::query_scalar(
        "SELECT COUNT(*) FROM totp_secrets WHERE user_type = 'admin' AND user_id = ? AND enabled = 1",
    )
    .bind(uid)
    .fetch_one(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?;
    Ok(Json(StatusOut { enabled: enabled != 0 }))
}

async fn setup(
    State(state): State<AppState>,
    headers: HeaderMap,
) -> Result<Json<SetupOut>, ApiError> {
    let uid = user_id(&state, &headers).await?;
    let existing: Option<(String, i64)> = sqlx::query_as(
        "SELECT secret, enabled FROM totp_secrets WHERE user_type = 'admin' AND user_id = ?",
    )
    .bind(uid)
    .fetch_optional(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?;

    let (secret, enabled) = match existing {
        Some((secret, enabled)) => (secret, enabled),
        None => {
            let secret = totp::new_secret().map_err(|e| internal_error(anyhow::anyhow!(e)))?;
            sqlx::query(
                "INSERT INTO totp_secrets (user_type, user_id, secret, enabled) VALUES ('admin', ?, ?, 0)",
            )
            .bind(uid)
            .bind(&secret)
            .execute(&state.db)
            .await
            .map_err(|e| internal_error(e.into()))?;
            (secret, 0)
        }
    };

    let username: String = sqlx::query_scalar("SELECT username FROM users WHERE id = ?")
        .bind(uid)
        .fetch_one(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?;

    let uri = totp::provisioning_uri(&secret, &username, "FPanel");
    Ok(Json(SetupOut { secret, uri, enabled: enabled != 0 }))
}

async fn enable(
    State(state): State<AppState>,
    headers: HeaderMap,
    Json(body): Json<CodeBody>,
) -> Result<Json<StatusOut>, ApiError> {
    let uid = user_id(&state, &headers).await?;
    let secret: Option<String> = sqlx::query_scalar(
        "SELECT secret FROM totp_secrets WHERE user_type = 'admin' AND user_id = ?",
    )
    .bind(uid)
    .fetch_optional(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?;
    let secret = secret.ok_or_else(|| {
        ApiError::new(StatusCode::BAD_REQUEST, "Run setup first to generate a secret")
    })?;

    if !totp::verify_code(&secret, &body.code, 1) {
        return Err(ApiError::new(
            StatusCode::BAD_REQUEST,
            "The authentication code is invalid or expired",
        ));
    }

    sqlx::query("UPDATE totp_secrets SET enabled = 1 WHERE user_type = 'admin' AND user_id = ?")
        .bind(uid)
        .execute(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?;
    Ok(Json(StatusOut { enabled: true }))
}

async fn disable(
    State(state): State<AppState>,
    headers: HeaderMap,
    Json(body): Json<CodeBody>,
) -> Result<Json<StatusOut>, ApiError> {
    let uid = user_id(&state, &headers).await?;
    let secret: Option<String> = sqlx::query_scalar(
        "SELECT secret FROM totp_secrets WHERE user_type = 'admin' AND user_id = ? AND enabled = 1",
    )
    .bind(uid)
    .fetch_optional(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?;
    let secret = secret.ok_or_else(|| {
        ApiError::new(StatusCode::BAD_REQUEST, "Two-factor authentication is not enabled")
    })?;

    if !totp::verify_code(&secret, &body.code, 1) {
        return Err(ApiError::new(
            StatusCode::BAD_REQUEST,
            "The authentication code is invalid or expired",
        ));
    }

    sqlx::query("UPDATE totp_secrets SET enabled = 0 WHERE user_type = 'admin' AND user_id = ?")
        .bind(uid)
        .execute(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?;
    Ok(Json(StatusOut { enabled: false }))
}