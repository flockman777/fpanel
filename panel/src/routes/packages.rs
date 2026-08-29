use axum::extract::{Path, State};
use axum::routing::get;
use axum::{Json, Router};
use serde::{Deserialize, Serialize};
use sqlx::FromRow;

use crate::db::AppState;
use crate::error::{internal_error, ApiError};

#[derive(Debug, Serialize, FromRow)]
pub struct Package {
    pub id: i64,
    pub name: String,
    pub disk_limit_mb: i64,
    pub mailbox_limit: i64,
    pub database_limit: i64,
    pub domain_limit: i64,
    pub bandwidth_limit_gb: i64,
}

#[derive(Debug, Deserialize, Clone)]
pub struct CreatePackage {
    pub name: String,
    pub disk_limit_mb: i64,
    pub mailbox_limit: i64,
    pub database_limit: i64,
    pub domain_limit: i64,
    pub bandwidth_limit_gb: i64,
}

pub fn router() -> Router<AppState> {
    Router::new()
        .route("/", get(list).post(create))
        .route("/{id}", get(get_one).delete(remove))
}

async fn create(
    State(state): State<AppState>,
    Json(input): Json<CreatePackage>,
) -> Result<(axum::http::StatusCode, Json<Package>), ApiError> {
    if input.name.trim().is_empty() {
        return Err(ApiError::new(
            axum::http::StatusCode::BAD_REQUEST,
            "Package name cannot be empty",
        ));
    }

    let result = sqlx::query(
        "INSERT INTO packages (name, disk_limit_mb, mailbox_limit, database_limit, domain_limit, bandwidth_limit_gb) VALUES (?, ?, ?, ?, ?, ?)",
    )
    .bind(input.name.trim())
    .bind(input.disk_limit_mb)
    .bind(input.mailbox_limit)
    .bind(input.database_limit)
    .bind(input.domain_limit)
    .bind(input.bandwidth_limit_gb)
    .execute(&state.db)
    .await
    .map_err(|e| {
        if e.to_string().contains("UNIQUE") {
            ApiError::new(
                axum::http::StatusCode::CONFLICT,
                "Package name already exists",
            )
        } else {
            internal_error(e.into())
        }
    })?;

    let package = sqlx::query_as::<_, Package>("SELECT * FROM packages WHERE id = ?")
        .bind(result.last_insert_rowid())
        .fetch_one(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?;

    Ok((axum::http::StatusCode::CREATED, Json(package)))
}

async fn remove(
    State(state): State<AppState>,
    Path((_sess, id)): Path<(String, i64)>,
) -> Result<axum::http::StatusCode, ApiError> {
    let result = sqlx::query("DELETE FROM packages WHERE id = ?")
        .bind(id)
        .execute(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?;

    if result.rows_affected() == 0 {
        return Err(ApiError::new(
            axum::http::StatusCode::NOT_FOUND,
            "Package not found",
        ));
    }

    Ok(axum::http::StatusCode::NO_CONTENT)
}

async fn list(State(state): State<AppState>) -> Result<Json<Vec<Package>>, ApiError> {
    let packages = sqlx::query_as::<_, Package>("SELECT * FROM packages ORDER BY name")
        .fetch_all(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?;
    Ok(Json(packages))
}

async fn get_one(
    State(state): State<AppState>,
    Path((_sess, id)): Path<(String, i64)>,
) -> Result<Json<Package>, ApiError> {
    let package = sqlx::query_as::<_, Package>("SELECT * FROM packages WHERE id = ?")
        .bind(id)
        .fetch_optional(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?
        .ok_or_else(|| ApiError::new(axum::http::StatusCode::NOT_FOUND, "Package not found"))?;
    Ok(Json(package))
}