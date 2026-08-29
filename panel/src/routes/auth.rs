use axum::extract::State;
use axum::http::{header, HeaderMap, StatusCode};
use axum::Json;
use serde::{Deserialize, Serialize};
use sqlx::FromRow;
use std::collections::HashMap;
use std::sync::{Mutex, OnceLock};
use std::time::{Duration, Instant};

use crate::auth::{create_token, hash_password, verify_and_get_claims_with_state, verify_password};
use crate::db::AppState;
use crate::error::{internal_error, ApiError};

const MAX_LOGIN_ATTEMPTS: u32 = 5;
const RATE_WINDOW: Duration = Duration::from_secs(60);

fn login_guard() -> &'static Mutex<HashMap<String, (u32, Instant)>> {
    static G: OnceLock<Mutex<HashMap<String, (u32, Instant)>>> = OnceLock::new();
    G.get_or_init(|| Mutex::new(HashMap::new()))
}

pub(crate) fn check_login_rate(key: &str) -> Result<(), ApiError> {
    let mut m = login_guard().lock().unwrap();
    if let Some((count, until)) = m.get(key) {
        if *count >= MAX_LOGIN_ATTEMPTS && *until > Instant::now() {
            let wait = until.saturating_duration_since(Instant::now()).as_secs();
            return Err(ApiError::new(
                StatusCode::TOO_MANY_REQUESTS,
                format!("Too many failed login attempts. Try again in {wait}s."),
            ));
        }
        if *until <= Instant::now() {
            m.remove(key);
        }
    }
    Ok(())
}

pub(crate) fn record_login_failure(key: &str) {
    let mut m = login_guard().lock().unwrap();
    let (count, until) = m
        .get(key)
        .copied()
        .unwrap_or((0, Instant::now() + RATE_WINDOW));
    let until = if until <= Instant::now() {
        Instant::now() + RATE_WINDOW
    } else {
        until
    };
    m.insert(key.to_string(), (count + 1, until));
}

pub(crate) fn clear_login_failures(key: &str) {
    login_guard().lock().unwrap().remove(key);
}

#[derive(Debug, Serialize, FromRow)]
pub struct User {
    pub id: i64,
    pub username: String,
    pub role: String,
}

#[derive(Debug, Deserialize)]
pub struct LoginReq {
    pub username: String,
    pub password: String,
    #[serde(default)]
    pub totp: Option<String>,
}

#[derive(Debug, Deserialize)]
pub struct RegisterReq {
    pub username: String,
    pub password: String,
}

#[derive(Debug, Serialize)]
pub struct AuthRes {
    pub token: String,
    pub sess: String,
    pub user: User,
}

pub async fn login(
    State(state): State<AppState>,
    Json(input): Json<LoginReq>,
) -> Result<Json<AuthRes>, ApiError> {
    let key = format!("admin:{}", input.username.trim().to_lowercase());
    check_login_rate(&key)?;

    let row = sqlx::query_as::<_, (i64, String, String, String)>(
        "SELECT id, username, password_hash, role FROM users WHERE username = ?",
    )
    .bind(input.username.trim())
    .fetch_optional(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?
    .ok_or_else(|| ApiError::new(StatusCode::UNAUTHORIZED, "Invalid username or password"))?;

    let (id, username, password_hash, role) = row;

    if !verify_password(&input.password, &password_hash) {
        record_login_failure(&key);
        return Err(ApiError::new(
            StatusCode::UNAUTHORIZED,
            "Invalid username or password",
        ));
    }

    let totp_row = sqlx::query_as::<_, (i64, String, i64)>(
        "SELECT user_id, secret, enabled FROM totp_secrets WHERE user_type = 'admin' AND user_id = ?",
    )
    .bind(id)
    .fetch_optional(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?;

    if let Some((_, secret, enabled)) = totp_row {
        if enabled != 0 {
            let code = input.totp.clone().unwrap_or_default();
            if code.trim().is_empty() {
                return Err(ApiError::new(
                    StatusCode::from_u16(428).expect("428"),
                    "Two-factor authentication code required",
                ));
            }
            if !crate::totp::verify_code(&secret, &code, 1) {
                record_login_failure(&key);
                return Err(ApiError::new(
                    StatusCode::UNAUTHORIZED,
                    "Invalid two-factor authentication code",
                ));
            }
        }
    }

    clear_login_failures(&key);

    let (token, sess) = create_token(&state.jwt_secret, id, &username, &role).map_err(|_| {
        ApiError::new(StatusCode::INTERNAL_SERVER_ERROR, "Failed to create token")
    })?;

    Ok(Json(AuthRes {
        token,
        sess,
        user: User { id, username, role },
    }))
}

pub async fn register(
    State(state): State<AppState>,
    Json(input): Json<RegisterReq>,
) -> Result<(StatusCode, Json<AuthRes>), ApiError> {
    let existing_users = sqlx::query_scalar::<_, i64>("SELECT COUNT(*) FROM users")
        .fetch_one(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?;
    if existing_users > 0 {
        return Err(ApiError::new(
            StatusCode::FORBIDDEN,
            "Registration is closed. The admin account already exists.",
        ));
    }

    if input.username.trim().len() < 3 || input.password.len() < 6 {
        return Err(ApiError::new(
            StatusCode::BAD_REQUEST,
            "Username must be at least 3 characters, password at least 6 characters",
        ));
    }

    let password_hash = hash_password(&input.password)
        .map_err(|_| ApiError::new(StatusCode::INTERNAL_SERVER_ERROR, "Failed to hash password"))?;
    let username = input.username.trim();

    let result = sqlx::query(
        "INSERT INTO users (username, password_hash, role) VALUES (?, ?, 'admin')",
    )
    .bind(username)
    .bind(&password_hash)
    .execute(&state.db)
    .await
    .map_err(|e| {
        if e.to_string().contains("UNIQUE") {
            return ApiError::new(StatusCode::CONFLICT, "Username already taken");
        }
        internal_error(e.into())
    })?;

    let (token, sess) = create_token(&state.jwt_secret, result.last_insert_rowid(), username, "admin")
        .map_err(|_| ApiError::new(StatusCode::INTERNAL_SERVER_ERROR, "Failed to create token"))?;

    Ok((
        StatusCode::CREATED,
        Json(AuthRes {
            token,
            sess,
            user: User {
                id: result.last_insert_rowid(),
                username: username.to_string(),
                role: "admin".to_string(),
            },
        }),
    ))
}

pub async fn me(
    State(state): State<AppState>,
    headers: HeaderMap,
) -> Result<Json<User>, ApiError> {
    let token = extract_bearer(&headers)?;
    let claims = verify_and_get_claims_with_state(&state.jwt_secret, &token).await?;

    let user = sqlx::query_as::<_, User>("SELECT id, username, role FROM users WHERE id = ?")
        .bind(claims.sub)
        .fetch_optional(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?
        .ok_or_else(|| ApiError::new(StatusCode::UNAUTHORIZED, "User not found"))?;

    Ok(Json(user))
}

pub fn extract_bearer(headers: &HeaderMap) -> Result<String, ApiError> {
    headers
        .get(header::AUTHORIZATION)
        .and_then(|v| v.to_str().ok())
        .and_then(|v| v.strip_prefix("Bearer "))
        .map(|s| s.to_string())
        .ok_or_else(|| ApiError::new(StatusCode::UNAUTHORIZED, "Token not found"))
}