use anyhow::Result;
use axum::http::StatusCode;
use chrono::{Duration, Utc};
use jsonwebtoken::{decode, encode, DecodingKey, EncodingKey, Header, Validation};
use serde::{Deserialize, Serialize};

#[derive(Debug, Serialize, Deserialize, Clone)]
pub struct Claims {
    pub sub: i64,
    pub username: String,
    pub role: String,
    pub sess: String,
    pub exp: usize,
}

pub struct AuthError(pub StatusCode, pub String);

pub async fn verify_and_get_claims_with_state(
    secret: &str,
    token: &str,
) -> Result<Claims, crate::error::ApiError> {
    verify_token(secret, token).map_err(|e| crate::error::ApiError::new(e.0, e.1))
}

pub fn create_token(secret: &str, user_id: i64, username: &str, role: &str) -> Result<(String, String)> {
    let exp = (Utc::now() + Duration::hours(24)).timestamp() as usize;
    let sess = uuid::Uuid::new_v4().to_string();
    let claims = Claims {
        sub: user_id,
        username: username.to_string(),
        role: role.to_string(),
        sess: sess.clone(),
        exp,
    };
    let token = encode(
        &Header::default(),
        &claims,
        &EncodingKey::from_secret(secret.as_bytes()),
    )?;
    Ok((token, sess))
}

pub fn verify_token(secret: &str, token: &str) -> Result<Claims, AuthError> {
    let data = decode::<Claims>(
        token,
        &DecodingKey::from_secret(secret.as_bytes()),
        &Validation::default(),
    )
    .map_err(|_| AuthError(StatusCode::UNAUTHORIZED, "Token invalid or expired".to_string()))?;
    Ok(data.claims)
}

pub fn hash_password(raw: &str) -> Result<String> {
    Ok(bcrypt::hash(raw, 12)?)
}

pub fn verify_password(raw: &str, hash: &str) -> bool {
    bcrypt::verify(raw, hash).unwrap_or(false)
}