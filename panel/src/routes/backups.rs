use axum::extract::{Path, State};
use axum::http::StatusCode;
use axum::routing::{delete, get, post};
use axum::{Json, Router};
use serde::{Deserialize, Serialize};
use std::time::{SystemTime, UNIX_EPOCH};

use crate::db::AppState;
use crate::error::{internal_error, ApiError};
use crate::provision;
use crate::routes::domains::bearer_account;

#[derive(Debug, Serialize)]
pub struct BackupInfo {
    pub file: String,
    pub username: String,
    pub size: i64,
    pub created_at: String,
}

#[derive(Debug, Deserialize)]
pub struct CreateBackup {
    pub account_id: i64,
}

pub fn router() -> Router<AppState> {
    Router::new()
        .route("/", get(list).post(create))
        .route("/{file}", delete(remove))
        .route("/{file}/restore", post(restore))
        .route("/{file}/download", get(download))
}

pub fn client_router() -> Router<AppState> {
    Router::new()
        .route("/", get(client_list).post(client_create))
        .route("/{file}", delete(client_remove))
        .route("/{file}/restore", post(client_restore))
        .route("/{file}/download", get(client_download))
}

fn backups_dir() -> std::path::PathBuf {
    std::env::var("FPANEL_BACKUPS")
        .map(std::path::PathBuf::from)
        .unwrap_or_else(|_| provision::data_dir().join("backups"))
}

fn scan_backups() -> Result<Vec<BackupInfo>, ApiError> {
    let dir = backups_dir();
    std::fs::create_dir_all(&dir).map_err(|e| internal_error(anyhow::Error::new(e)))?;
    let mut out = Vec::new();
    let entries = std::fs::read_dir(&dir).map_err(|e| internal_error(anyhow::Error::new(e)))?;
    for entry in entries.flatten() {
        let path = entry.path();
        if path.extension().map(|e| e == "zip").unwrap_or(false) {
            let file = entry.file_name().to_string_lossy().into_owned();
            let username = account_from_file(&file).unwrap_or_default();
            let size = entry.metadata().map(|m| m.len() as i64).unwrap_or(0);
            let created = entry
                .metadata()
                .ok()
                .and_then(|m| m.modified().ok())
                .map(|t| unix_to_iso(t))
                .unwrap_or_else(|| "".into());
            out.push(BackupInfo { file, username, size, created_at: created });
        }
    }
    out.sort_by(|a, b| b.file.cmp(&a.file));
    Ok(out)
}

fn account_from_file(file: &str) -> Option<String> {
    let stem = file.strip_suffix(".zip")?;
    let ts_pos = stem.rfind('-')?;
    let username = &stem[..ts_pos];
    if username.is_empty() {
        return None;
    }
    Some(username.to_string())
}

fn unix_to_iso(t: SystemTime) -> String {
    let secs = t.duration_since(UNIX_EPOCH).map(|d| d.as_secs()).unwrap_or(0);
    chrono::DateTime::<chrono::Utc>::from_timestamp(secs as i64, 0)
        .map(|dt| dt.format("%Y-%m-%d %H:%M:%S").to_string())
        .unwrap_or_else(|| "".into())
}

fn safe_file_name(name: &str) -> Result<String, ApiError> {
    let n = name.trim();
    if n.is_empty() || n.contains('/') || n.contains('\\') || n.contains("..") || !n.ends_with(".zip") {
        return Err(ApiError::new(StatusCode::BAD_REQUEST, "Invalid backup file name"));
    }
    Ok(n.to_string())
}

async fn create(
    State(state): State<AppState>,
    Json(input): Json<CreateBackup>,
) -> Result<(StatusCode, Json<BackupInfo>), ApiError> {
    let username = sqlx::query_scalar::<_, String>("SELECT username FROM accounts WHERE id = ?")
        .bind(input.account_id)
        .fetch_optional(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?
        .ok_or_else(|| ApiError::new(StatusCode::NOT_FOUND, "Account not found"))?;

    let info = run_backup(&username).await?;
    Ok((StatusCode::CREATED, Json(info)))
}

async fn run_backup(username: &str) -> Result<BackupInfo, ApiError> {
    let dir = backups_dir();
    std::fs::create_dir_all(&dir).map_err(|e| internal_error(anyhow::Error::new(e)))?;
    let stamp = chrono::Utc::now().format("%Y%m%d%H%M%S").to_string();
    let file = format!("{username}-{stamp}.zip");
    let target = dir.join(&file);

    let htdocs = provision::account_htdocs(username);
    if !htdocs.exists() {
        return Err(ApiError::new(
            StatusCode::NOT_FOUND,
            "No htdocs directory found for this account",
        ));
    }

    let parent = htdocs.parent().unwrap_or(&htdocs);
    let result = std::process::Command::new("zip")
        .args(["-rq"])
        .arg(&target)
        .arg("htdocs")
        .current_dir(parent)
        .output()
        .map_err(|e| internal_error(anyhow::Error::new(e)))?;

    if !result.status.success() {
        let msg = String::from_utf8_lossy(&result.stderr);
        return Err(ApiError::new(
            StatusCode::INTERNAL_SERVER_ERROR,
            format!("Backup failed: {}", msg.trim()),
        ));
    }

    Ok(BackupInfo {
        file,
        username: username.to_string(),
        size: std::fs::metadata(&target).map(|m| m.len() as i64).unwrap_or(0),
        created_at: chrono::Utc::now().format("%Y-%m-%d %H:%M:%S").to_string(),
    })
}

async fn list(State(state): State<AppState>) -> Result<Json<Vec<BackupInfo>>, ApiError> {
    let mut out = scan_backups()?;
    let existing: std::collections::HashSet<String> =
        sqlx::query_scalar::<_, String>("SELECT username FROM accounts")
            .fetch_all(&state.db)
            .await
            .map_err(|e| internal_error(e.into()))?
            .into_iter()
            .collect();
    out.retain(|b| existing.contains(&b.username));
    Ok(Json(out))
}

async fn remove(
    State(state): State<AppState>,
    Path((_sess, file)): Path<(String, String)>,
) -> Result<StatusCode, ApiError> {
    let file = safe_file_name(&file)?;
    let username = account_from_file(&file).ok_or_else(|| ApiError::new(StatusCode::BAD_REQUEST, "Invalid backup file name"))?;
    let exists: bool = sqlx::query_scalar::<_, i64>("SELECT COUNT(*) FROM accounts WHERE username = ?")
        .bind(&username)
        .fetch_one(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?
        > 0;
    if !exists {
        return Err(ApiError::new(StatusCode::FORBIDDEN, "Backup does not belong to any account"));
    }
    let path = backups_dir().join(&file);
    if !path.exists() {
        return Err(ApiError::new(StatusCode::NOT_FOUND, "Backup not found"));
    }
    std::fs::remove_file(&path).map_err(|e| internal_error(anyhow::Error::new(e)))?;
    Ok(StatusCode::NO_CONTENT)
}

async fn restore(
    State(state): State<AppState>,
    Path((_sess, file)): Path<(String, String)>,
) -> Result<Json<serde_json::Value>, ApiError> {
    let file = safe_file_name(&file)?;
    let username = account_from_file(&file).ok_or_else(|| ApiError::new(StatusCode::BAD_REQUEST, "Invalid backup file name"))?;
    let exists: bool = sqlx::query_scalar::<_, i64>("SELECT COUNT(*) FROM accounts WHERE username = ?")
        .bind(&username)
        .fetch_one(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?
        > 0;
    if !exists {
        return Err(ApiError::new(StatusCode::FORBIDDEN, "Backup does not belong to any account"));
    }
    do_restore(&username, &file).await?;
    Ok(Json(serde_json::json!({ "status": "ok", "message": format!("Restored {file}") })))
}

async fn do_restore(username: &str, file: &str) -> Result<(), ApiError> {
    let src = backups_dir().join(file);
    if !src.exists() {
        return Err(ApiError::new(StatusCode::NOT_FOUND, "Backup not found"));
    }
    let htdocs = provision::account_htdocs(username);
    std::fs::create_dir_all(&htdocs).map_err(|e| internal_error(anyhow::Error::new(e)))?;
    let parent = htdocs.parent().unwrap_or(&htdocs);
    let result = std::process::Command::new("unzip")
        .args(["-o", "-q"])
        .arg(&src)
        .current_dir(parent)
        .output()
        .map_err(|e| internal_error(anyhow::Error::new(e)))?;
    if !result.status.success() {
        let msg = String::from_utf8_lossy(&result.stderr);
        return Err(ApiError::new(
            StatusCode::INTERNAL_SERVER_ERROR,
            format!("Restore failed: {}", msg.trim()),
        ));
    }
    Ok(())
}

async fn download(
    State(state): State<AppState>,
    Path((_sess, file)): Path<(String, String)>,
) -> Result<axum::response::Response, ApiError> {
    let file = safe_file_name(&file)?;
    let username = account_from_file(&file).ok_or_else(|| ApiError::new(StatusCode::BAD_REQUEST, "Invalid backup file name"))?;
    let exists: bool = sqlx::query_scalar::<_, i64>("SELECT COUNT(*) FROM accounts WHERE username = ?")
        .bind(&username)
        .fetch_one(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?
        > 0;
    if !exists {
        return Err(ApiError::new(StatusCode::FORBIDDEN, "Backup does not belong to any account"));
    }
    let path = backups_dir().join(&file);
    if !path.exists() {
        return Err(ApiError::new(StatusCode::NOT_FOUND, "Backup not found"));
    }
    let bytes = std::fs::read(&path).map_err(|e| internal_error(anyhow::Error::new(e)))?;
    Ok(axum::response::Response::builder()
        .status(StatusCode::OK)
        .header("Content-Type", "application/zip")
        .header("Content-Disposition", format!("attachment; filename=\"{file}\""))
        .body(axum::body::Body::from(bytes))
        .map_err(|e| internal_error(anyhow::Error::new(e)))?)
}

async fn client_list(
    State(state): State<AppState>,
    headers: axum::http::HeaderMap,
) -> Result<Json<Vec<BackupInfo>>, ApiError> {
    let (_, username) = bearer_account(&state, &headers).await?;
    let mut out = scan_backups()?;
    out.retain(|b| b.username == username);
    Ok(Json(out))
}

async fn client_create(
    State(state): State<AppState>,
    headers: axum::http::HeaderMap,
) -> Result<(StatusCode, Json<BackupInfo>), ApiError> {
    let (_, username) = bearer_account(&state, &headers).await?;
    let info = run_backup(&username).await?;
    Ok((StatusCode::CREATED, Json(info)))
}

async fn client_remove(
    State(state): State<AppState>,
    headers: axum::http::HeaderMap,
    Path((_sess, file)): Path<(String, String)>,
) -> Result<StatusCode, ApiError> {
    let file = safe_file_name(&file)?;
    let (_, username) = bearer_account(&state, &headers).await?;
    let bak_owner = account_from_file(&file).ok_or_else(|| ApiError::new(StatusCode::BAD_REQUEST, "Invalid backup file name"))?;
    if bak_owner != username {
        return Err(ApiError::new(StatusCode::FORBIDDEN, "Not your backup"));
    }
    let path = backups_dir().join(&file);
    if !path.exists() {
        return Err(ApiError::new(StatusCode::NOT_FOUND, "Backup not found"));
    }
    std::fs::remove_file(&path).map_err(|e| internal_error(anyhow::Error::new(e)))?;
    Ok(StatusCode::NO_CONTENT)
}

async fn client_restore(
    State(state): State<AppState>,
    headers: axum::http::HeaderMap,
    Path((_sess, file)): Path<(String, String)>,
) -> Result<Json<serde_json::Value>, ApiError> {
    let file = safe_file_name(&file)?;
    let (_, username) = bearer_account(&state, &headers).await?;
    let bak_owner = account_from_file(&file).ok_or_else(|| ApiError::new(StatusCode::BAD_REQUEST, "Invalid backup file name"))?;
    if bak_owner != username {
        return Err(ApiError::new(StatusCode::FORBIDDEN, "Not your backup"));
    }
    do_restore(&username, &file).await?;
    Ok(Json(serde_json::json!({ "status": "ok", "message": format!("Restored {file}") })))
}

async fn client_download(
    State(state): State<AppState>,
    headers: axum::http::HeaderMap,
    Path((_sess, file)): Path<(String, String)>,
) -> Result<axum::response::Response, ApiError> {
    let file = safe_file_name(&file)?;
    let (_, username) = bearer_account(&state, &headers).await?;
    let bak_owner = account_from_file(&file).ok_or_else(|| ApiError::new(StatusCode::BAD_REQUEST, "Invalid backup file name"))?;
    if bak_owner != username {
        return Err(ApiError::new(StatusCode::FORBIDDEN, "Not your backup"));
    }
    let path = backups_dir().join(&file);
    if !path.exists() {
        return Err(ApiError::new(StatusCode::NOT_FOUND, "Backup not found"));
    }
    let bytes = std::fs::read(&path).map_err(|e| internal_error(anyhow::Error::new(e)))?;
    Ok(axum::response::Response::builder()
        .status(StatusCode::OK)
        .header("Content-Type", "application/zip")
        .header("Content-Disposition", format!("attachment; filename=\"{file}\""))
        .body(axum::body::Body::from(bytes))
        .map_err(|e| internal_error(anyhow::Error::new(e)))?)
}