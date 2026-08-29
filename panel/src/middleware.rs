use axum::extract::{Request, State};
use axum::http::{header, StatusCode};
use axum::middleware::Next;
use axum::response::Response;

use crate::auth::{verify_and_get_claims_with_state};
use crate::db::AppState;
use crate::error::ApiError;

pub async fn session_guard(
    State(state): State<AppState>,
    req: Request,
    next: Next,
) -> Result<Response, ApiError> {
    let path = req.uri().path();

    let Some(url_sess) = extract_sess(path) else {
        return Ok(next.run(req).await);
    };

    let Some(token) = req
        .headers()
        .get(header::AUTHORIZATION)
        .and_then(|v| v.to_str().ok())
        .and_then(|v| v.strip_prefix("Bearer "))
        .map(|s| s.to_string())
    else {
        return Err(ApiError::new(StatusCode::UNAUTHORIZED, "Token not found"));
    };

    let claims = verify_and_get_claims_with_state(&state.jwt_secret, &token).await?;

    if claims.sess != url_sess {
        return Err(ApiError::new(
            StatusCode::UNAUTHORIZED,
            "Session URL is not valid for this token",
        ));
    }

    let segs: Vec<&str> = path.split('/').filter(|s| !s.is_empty()).collect();
    let resource = segs.get(2).copied().unwrap_or("");
    if resource == "client" {
        if claims.role != "client" {
            return Err(ApiError::new(
                StatusCode::FORBIDDEN,
                "Admin tokens cannot access client endpoints",
            ));
        }
    } else if claims.role != "admin" {
        return Err(ApiError::new(
            StatusCode::FORBIDDEN,
            "Client accounts cannot access admin endpoints",
        ));
    }

    Ok(next.run(req).await)
}

fn extract_sess(path: &str) -> Option<String> {
    let segs: Vec<&str> = path.split('/').collect();
    for (i, seg) in segs.iter().enumerate() {
        if *seg == "s" {
            return segs.get(i + 1).map(|s| s.to_string());
        }
    }
    None
}