use axum::extract::State;
use axum::http::{header, StatusCode};
use axum::routing::get;
use axum::{Json, Router};
use serde::{Deserialize, Serialize};
use sqlx::FromRow;

use crate::auth::{create_token, verify_and_get_claims_with_state};
use crate::db::AppState;
use crate::error::{internal_error, ApiError};

#[derive(Debug, Serialize, Deserialize, FromRow)]
pub struct ClientAccount {
    pub id: i64,
    pub username: String,
    pub email: String,
    pub package_id: i64,
    pub status: String,
    pub name: Option<String>,
}

#[derive(Debug, Serialize)]
pub struct ClientLoginRes {
    pub token: String,
    pub sess: String,
    pub account: ClientAccount,
}

#[derive(Debug, Deserialize)]
pub struct ClientLoginReq {
    pub username: String,
    pub password: String,
}

#[derive(Debug, Serialize, FromRow)]
pub struct PackageRow {
    pub name: String,
    pub disk_limit_mb: i64,
    pub mailbox_limit: i64,
    pub database_limit: i64,
    pub domain_limit: i64,
    pub bandwidth_limit_gb: i64,
}

#[derive(Debug, Serialize)]
pub struct ClientSummary {
    pub account: ClientAccount,
    pub package: PackageRow,
    pub usage: Usage,
}

#[derive(Debug, Serialize)]
pub struct Usage {
    pub disk_used_mb: i64,
    pub domain_used: i64,
    pub database_used: i64,
    pub mailbox_used: i64,
}

pub fn router() -> Router<AppState> {
    Router::new()
        .route("/login", axum::routing::post(login))
        .route("/me", get(me))
}

pub async fn login(
    State(state): State<AppState>,
    Json(input): Json<ClientLoginReq>,
) -> Result<Json<ClientLoginRes>, ApiError> {
    let key = format!("client:{}", input.username.trim().to_lowercase());
    crate::routes::auth::check_login_rate(&key)?;

    let account = sqlx::query_as::<_, ClientAccount>(
        "SELECT * FROM accounts WHERE username = ? AND status = 'active'",
    )
    .bind(input.username.trim())
    .fetch_optional(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?
    .ok_or_else(|| ApiError::new(StatusCode::UNAUTHORIZED, "Invalid username or password"))?;

    let Some(hash) = sqlx::query_scalar::<_, Option<String>>(
        "SELECT password_hash FROM accounts WHERE id = ?",
    )
    .bind(account.id)
    .fetch_optional(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?
    .flatten()
    else {
        return Err(ApiError::new(
            StatusCode::UNAUTHORIZED,
            "Account has no password set by admin",
        ));
    };

    if !crate::auth::verify_password(&input.password, &hash) {
        crate::routes::auth::record_login_failure(&key);
        return Err(ApiError::new(
            StatusCode::UNAUTHORIZED,
            "Invalid username or password",
        ));
    }

    crate::routes::auth::clear_login_failures(&key);

    let (token, sess) = create_token(&state.jwt_secret, account.id, &account.username, "client")
        .map_err(|_| ApiError::new(StatusCode::INTERNAL_SERVER_ERROR, "Failed to create token"))?;

    Ok(Json(ClientLoginRes {
        token,
        sess,
        account,
    }))
}

async fn me(
    State(state): State<AppState>,
    headers: axum::http::HeaderMap,
) -> Result<Json<ClientSummary>, ApiError> {
    let Some(token) = headers
        .get(header::AUTHORIZATION)
        .and_then(|v| v.to_str().ok())
        .and_then(|v| v.strip_prefix("Bearer "))
    else {
        return Err(ApiError::new(StatusCode::UNAUTHORIZED, "Token not found"));
    };

    let claims = verify_and_get_claims_with_state(&state.jwt_secret, token).await?;
    if claims.role != "client" {
        return Err(ApiError::new(
            StatusCode::FORBIDDEN,
            "Only client accounts can access this",
        ));
    }

    let account = sqlx::query_as::<_, ClientAccount>("SELECT * FROM accounts WHERE id = ?")
        .bind(claims.sub)
        .fetch_optional(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?
        .ok_or_else(|| ApiError::new(StatusCode::NOT_FOUND, "Account not found"))?;

    let package = sqlx::query_as::<_, PackageRow>(
        "SELECT name, disk_limit_mb, mailbox_limit, database_limit, domain_limit, bandwidth_limit_gb FROM packages WHERE id = ?",
    )
    .bind(account.package_id)
    .fetch_optional(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?
    .ok_or_else(|| ApiError::new(StatusCode::NOT_FOUND, "Package not found"))?;

    let domain_used = sqlx::query_scalar::<_, i64>("SELECT COUNT(*) FROM domains WHERE account_id = ?")
        .bind(account.id)
        .fetch_one(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?;
    let database_used =
        sqlx::query_scalar::<_, i64>("SELECT COUNT(*) FROM databases WHERE account_id = ?")
            .bind(account.id)
            .fetch_one(&state.db)
            .await
            .map_err(|e| internal_error(e.into()))?;
    let mailbox_used =
        sqlx::query_scalar::<_, i64>("SELECT COUNT(*) FROM email_accounts WHERE account_id = ?")
            .bind(account.id)
            .fetch_one(&state.db)
            .await
            .map_err(|e| internal_error(e.into()))?;
    let disk_used_mb =
        crate::routes::stats::dir_size(&crate::provision::account_home(&account.username))
            / 1048576;

    Ok(Json(ClientSummary {
        account,
        package,
        usage: Usage {
            disk_used_mb: disk_used_mb,
            domain_used: domain_used,
            database_used: database_used,
            mailbox_used: mailbox_used,
        },
    }))
}