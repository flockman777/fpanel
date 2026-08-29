use axum::extract::{Path, Query, State};
use axum::http::{HeaderMap, StatusCode};
use axum::routing::{delete, get};
use axum::{Json, Router};
use serde::{Deserialize, Serialize};
use serde_json::{Map, Value};
use sqlx::sqlite::SqliteRow;
use sqlx::{Row, SqlitePool};

use crate::db::AppState;
use crate::error::{internal_error, ApiError};
use crate::provision;
use crate::routes::domains::bearer_account;

const SIZE_KEYS: [&str; 4] = ["memory_limit", "upload_max_filesize", "post_max_size", "realpath_cache_size"];
const INT_KEYS: [&str; 3] = ["max_execution_time", "max_input_time", "max_input_vars"];
const BOOL_KEYS: [&str; 1] = ["opcache.enable"];

#[derive(Serialize)]
struct PhpRow {
    id: i64,
    account_id: i64,
    domain_id: i64,
    domain: String,
    version: String,
    ini: Value,
    handler: String,
}

#[derive(Serialize)]
struct ListResp {
    rows: Vec<PhpRow>,
    versions: Vec<String>,
}

#[derive(Deserialize)]
struct SavePhp {
    domain_id: i64,
    version: String,
    handler: Option<String>,
    ini: Option<Map<String, Value>>,
}

#[derive(Deserialize)]
struct AccountQ {
    account_id: Option<i64>,
}

pub fn router() -> Router<AppState> {
    Router::new()
        .route("/", get(list_admin).put(save_admin))
        .route("/{domain_id}", delete(reset_admin))
}

pub fn client_router() -> Router<AppState> {
    Router::new()
        .route("/", get(list_client).put(save_client))
        .route("/{domain_id}", delete(reset_client))
}

// ---------- shared ----------

fn row_from(r: &SqliteRow, aid: i64) -> PhpRow {
    let ini_raw: Option<String> = r.get(5);
    let ini: Value = ini_raw
        .and_then(|s| serde_json::from_str(&s).ok())
        .unwrap_or_else(|| Value::Object(Map::new()));
    PhpRow {
        id: r.get(0),
        account_id: aid,
        domain_id: r.get(2),
        domain: r.get(3),
        version: r.get(4),
        ini,
        handler: r.get(6),
    }
}

async fn list_rows(db: &SqlitePool, aid: i64) -> Result<Vec<PhpRow>, ApiError> {
    let rows = sqlx::query(
        "SELECT id, account_id, domain_id, domain, version, ini_config, handler \
         FROM php_settings WHERE account_id = ? ORDER BY domain",
    )
    .bind(aid)
    .fetch_all(db)
    .await
    .map_err(|e| internal_error(e.into()))?;
    Ok(rows.iter().map(|r| row_from(r, aid)).collect())
}

fn detected_versions() -> Vec<String> {
    let out = std::process::Command::new("php")
        .arg("-r")
        .arg("echo PHP_MAJOR_VERSION,'.',PHP_MINOR_VERSION;")
        .output();
    match out {
        Ok(o) if o.status.success() => {
            let v = String::from_utf8(o.stdout).unwrap_or_default();
            let v = v.trim().to_string();
            if v.is_empty() { vec!["system".to_string()] } else { vec![v] }
        }
        _ => vec!["system".to_string()],
    }
}

fn validate_ini(map: &Map<String, Value>) -> Result<(), ApiError> {
    for (k, v) in map {
        let val = match v {
            Value::String(s) => s.clone(),
            Value::Number(n) => n.to_string(),
            Value::Bool(b) => if *b { "1".to_string() } else { "0".to_string() },
            _ => return Err(ApiError::new(StatusCode::BAD_REQUEST, format!("Invalid value for {k}"))),
        };
        if SIZE_KEYS.contains(&k.as_str()) {
            let size_ok = |s: &str| {
                let trimmed = s.trim().to_ascii_uppercase();
                let mut p = trimmed.split(|c: char| c.is_ascii_alphabetic());
                let num = p.next().unwrap_or("");
                let unit = p.next().unwrap_or("").to_string();
                let num_ok = !num.is_empty() && num.chars().all(|c| c.is_ascii_digit() || c == '.');
                let digit_only = num.chars().all(|c| c.is_ascii_digit());
                num_ok && digit_only && (unit.is_empty() || ["K", "M", "G"].contains(&unit.as_str()))
            };
            if !size_ok(&val) {
                return Err(ApiError::new(StatusCode::BAD_REQUEST, format!("{k} must be a size like 128M")));
            }
        } else if INT_KEYS.contains(&k.as_str()) {
            if val.trim().is_empty() || !val.trim().chars().all(|c| c.is_ascii_digit()) {
                return Err(ApiError::new(StatusCode::BAD_REQUEST, format!("{k} must be an integer")));
            }
        } else if BOOL_KEYS.contains(&k.as_str()) {
            let low = val.trim().to_ascii_lowercase();
            if !["1", "0", "on", "off", "true", "false"].contains(&low.as_str()) {
                return Err(ApiError::new(StatusCode::BAD_REQUEST, format!("{k} must be On or Off")));
            }
        } else {
            return Err(ApiError::new(
                StatusCode::BAD_REQUEST,
                format!("{k} is not an editable PHP setting"),
            ));
        }
    }
    Ok(())
}

async fn upsert(
    state: &AppState,
    aid: i64,
    username: &str,
    body: SavePhp,
) -> Result<PhpRow, ApiError> {
    let domain: (i64, String) = sqlx::query_as(
        "SELECT id, name FROM domains WHERE id = ? AND account_id = ? AND status = 'active'",
    )
    .bind(body.domain_id)
    .bind(aid)
    .fetch_optional(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?
    .ok_or_else(|| ApiError::new(StatusCode::NOT_FOUND, "Domain not found"))?;
    let (did, name) = domain;

    let version = body.version.trim().to_string();
    if version.is_empty() {
        return Err(ApiError::new(StatusCode::BAD_REQUEST, "A PHP version is required"));
    }
    let handler = body.handler.unwrap_or_else(|| "system".to_string());
    if handler != "system" && handler != "fpm" {
        return Err(ApiError::new(StatusCode::BAD_REQUEST, "Handler must be system or fpm"));
    }
    let ini = body.ini.unwrap_or_else(|| Map::new());
    validate_ini(&ini)?;
    let ini_raw = if ini.is_empty() {
        None
    } else {
        Some(serde_json::to_string(&Value::Object(ini.clone())).map_err(|e| internal_error(e.into()))?)
    };

    sqlx::query(
        "INSERT INTO php_settings (account_id, domain_id, domain, version, ini_config, handler) \
         VALUES (?, ?, ?, ?, ?, ?) \
         ON CONFLICT(domain_id) DO UPDATE SET version = excluded.version, \
             ini_config = excluded.ini_config, handler = excluded.handler",
    )
    .bind(aid)
    .bind(did)
    .bind(&name)
    .bind(&version)
    .bind(&ini_raw)
    .bind(&handler)
    .execute(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?;

    let row = sqlx::query(
        "SELECT id, account_id, domain_id, domain, version, ini_config, handler \
         FROM php_settings WHERE domain_id = ?",
    )
    .bind(did)
    .fetch_one(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?;
    let php = row_from(&row, aid);

    provision::write_php(
        &name,
        &provision::PhpProvision {
            domain: name.clone(),
            version: version.clone(),
            handler: handler.clone(),
            ini: if ini.is_empty() { None } else { Some(Value::Object(ini)) },
        },
    );
    let _ = username;
    Ok(php)
}

async fn reset(state: &AppState, aid: i64, domain_id: i64) -> Result<(), ApiError> {
    let row: (String,) = sqlx::query_as("SELECT name FROM domains WHERE id = ? AND account_id = ?")
        .bind(domain_id)
        .bind(aid)
        .fetch_optional(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?
        .ok_or_else(|| ApiError::new(StatusCode::NOT_FOUND, "Domain not found"))?;
    sqlx::query("DELETE FROM php_settings WHERE account_id = ? AND domain_id = ?")
        .bind(aid)
        .bind(domain_id)
        .execute(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?;
    provision::remove_php(&row.0);
    Ok(())
}

// ---------- clients ----------

async fn list_client(
    State(state): State<AppState>,
    headers: HeaderMap,
) -> Result<Json<ListResp>, ApiError> {
    let (aid, _) = bearer_account(&state, &headers).await?;
    Ok(Json(ListResp {
        rows: list_rows(&state.db, aid).await?,
        versions: detected_versions(),
    }))
}

async fn save_client(
    State(state): State<AppState>,
    headers: HeaderMap,
    Json(body): Json<SavePhp>,
) -> Result<Json<PhpRow>, ApiError> {
    let (aid, username) = bearer_account(&state, &headers).await?;
    Ok(Json(upsert(&state, aid, &username, body).await?))
}

async fn reset_client(
    State(state): State<AppState>,
    headers: HeaderMap,
    Path((_sess, domain_id)): Path<(String, i64)>,
) -> Result<StatusCode, ApiError> {
    let (aid, _) = bearer_account(&state, &headers).await?;
    reset(&state, aid, domain_id).await?;
    Ok(StatusCode::NO_CONTENT)
}

// ---------- admins ----------

fn require_account(aid: Option<i64>) -> Result<i64, ApiError> {
    aid.ok_or_else(|| ApiError::new(StatusCode::BAD_REQUEST, "account_id is required"))
}

async fn list_admin(
    State(state): State<AppState>,
    Query(q): Query<AccountQ>,
) -> Result<Json<ListResp>, ApiError> {
    let aid = require_account(q.account_id)?;
    Ok(Json(ListResp {
        rows: list_rows(&state.db, aid).await?,
        versions: detected_versions(),
    }))
}

async fn save_admin(
    State(state): State<AppState>,
    Query(q): Query<AccountQ>,
    Json(body): Json<SavePhp>,
) -> Result<Json<PhpRow>, ApiError> {
    let aid = require_account(q.account_id)?;
    let username: String =
        sqlx::query_scalar("SELECT username FROM accounts WHERE id = ?")
            .bind(aid)
            .fetch_optional(&state.db)
            .await
            .map_err(|e| internal_error(e.into()))?
            .ok_or_else(|| ApiError::new(StatusCode::NOT_FOUND, "Account not found"))?;
    Ok(Json(upsert(&state, aid, &username, body).await?))
}

async fn reset_admin(
    State(state): State<AppState>,
    Query(q): Query<AccountQ>,
    Path((_sess, domain_id)): Path<(String, i64)>,
) -> Result<StatusCode, ApiError> {
    let aid = require_account(q.account_id)?;
    reset(&state, aid, domain_id).await?;
    Ok(StatusCode::NO_CONTENT)
}