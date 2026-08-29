use axum::extract::{Path, Query, State};
use axum::http::StatusCode;
use axum::routing::{delete, get, post};
use axum::{Json, Router};
use serde::{Deserialize, Serialize};
use sqlx::sqlite::SqliteRow;
use sqlx::{Row, SqlitePool};
use std::process::{Command, Stdio};

use crate::db::AppState;
use crate::error::{internal_error, ApiError};
use crate::provision;
use crate::routes::domains::bearer_account;

const RUNTIMES: [(&str, &str); 7] = [
    ("node", "Node.js"),
    ("python", "Python"),
    ("php", "PHP"),
    ("bun", "Bun"),
    ("deno", "Deno"),
    ("go", "Go"),
    ("ruby", "Ruby"),
];

fn bin_cmd(runtime: &str) -> Option<&'static str> {
    Some(match runtime {
        "node" => "node",
        "python" => "python3",
        "php" => "php",
        "bun" => "bun",
        "deno" => "deno",
        "go" => "go",
        "ruby" => "ruby",
        _ => return None,
    })
}

#[derive(Serialize)]
struct Toolchain {
    id: String,
    label: String,
    available: bool,
    version: Option<String>,
}

#[derive(Serialize, Clone)]
struct RunApp {
    id: i64,
    account_id: i64,
    domain_id: i64,
    domain: String,
    app: String,
    runtime: String,
    entrypoint: String,
    port: i64,
    auto_restart: bool,
    status: String,
    pid: Option<i64>,
    created_at: String,
    env: Option<String>,
}

#[derive(Serialize)]
struct ListResp {
    apps: Vec<RunApp>,
    toolchains: Vec<Toolchain>,
}

#[derive(Deserialize)]
struct CreateApp {
    domain_id: i64,
    app: Option<String>,
    runtime: String,
    entrypoint: String,
    port: Option<i64>,
    env: Option<String>,
    auto_restart: Option<bool>,
}

#[derive(Deserialize)]
struct AccountQ {
    account_id: Option<i64>,
}

#[derive(Deserialize)]
struct LogQ {
    account_id: Option<i64>,
    lines: Option<i64>,
}

pub fn router() -> Router<AppState> {
    Router::new()
        .route("/", get(list_admin).post(create_admin))
        .route("/{id}/log", get(log_admin))
        .route("/{id}/start", post(start_admin))
        .route("/{id}/stop", post(stop_admin))
        .route("/{id}/restart", post(restart_admin))
        .route("/{id}", delete(delete_admin))
}

pub fn client_router() -> Router<AppState> {
    Router::new()
        .route("/", get(list_client).post(create_client))
        .route("/{id}/log", get(log_client))
        .route("/{id}/start", post(start_client))
        .route("/{id}/stop", post(stop_client))
        .route("/{id}/restart", post(restart_client))
        .route("/{id}", delete(delete_client))
}

// ---------- shared ----------

const COLS: &str = "id, account_id, domain_id, domain, app, runtime, entrypoint, port, \
                    auto_restart, status, pid, created_at, env";

fn row_from(r: &SqliteRow, account_id: i64) -> RunApp {
    RunApp {
        id: r.get(0),
        account_id,
        domain_id: r.get(2),
        domain: r.get(3),
        app: r.get(4),
        runtime: r.get(5),
        entrypoint: r.get(6),
        port: r.get(7),
        auto_restart: r.get::<i64, _>(8) != 0,
        status: r.get(9),
        pid: r.get(10),
        created_at: r.get(11),
        env: r.get(12),
    }
}

async fn list_apps(db: &SqlitePool, account_id: i64) -> Result<Vec<RunApp>, ApiError> {
    let rows = sqlx::query(
        &format!(
            "SELECT {COLS} FROM run_apps WHERE account_id = ? ORDER BY domain, id"
        ),
    )
    .bind(account_id)
    .fetch_all(db)
    .await
    .map_err(|e| internal_error(e.into()))?;
    Ok(rows.iter().map(|r| row_from(r, account_id)).collect())
}

async fn fetch_app(
    db: &SqlitePool,
    account_id: i64,
    id: i64,
) -> Result<RunApp, ApiError> {
    let r = sqlx::query(&format!(
        "SELECT {COLS} FROM run_apps WHERE id = ? AND account_id = ?"
    ))
    .bind(id)
    .bind(account_id)
    .fetch_optional(db)
    .await
    .map_err(|e| internal_error(e.into()))?
    .ok_or_else(|| ApiError::new(StatusCode::NOT_FOUND, "App not found"))?;
    Ok(row_from(&r, account_id))
}

async fn set_status(db: &SqlitePool, id: i64, status: &str, pid: Option<i64>) {
    if let Err(e) = sqlx::query("UPDATE run_apps SET status = ?, pid = ? WHERE id = ?")
        .bind(status)
        .bind(pid)
        .bind(id)
        .execute(db)
        .await
    {
        tracing::warn!("[runtime] failed to set status {id}: {e}");
    }
}

fn reconciling(state: &AppState, apps: &mut [RunApp]) {
    let mut map = state.runtimes.lock().unwrap();
    for app in apps.iter_mut() {
        if app.status != "running" {
            continue;
        }
        let alive = match map.get_mut(&app.id) {
            Some(c) => matches!(c.try_wait(), Ok(None)),
            None => false,
        };
        if !alive {
            map.remove(&app.id);
            app.status = "stopped".to_string();
            app.pid = None;
        }
    }
}

fn toolchains() -> Vec<Toolchain> {
    RUNTIMES
        .iter()
        .map(|(id, label)| {
            let out = bin_cmd(id).and_then(|b| Command::new(b).arg("--version").output().ok());
            let version = match out {
                Some(o) if o.status.success() => String::from_utf8(o.stdout)
                    .ok()
                    .and_then(|s| {
                        let first = s.trim().lines().next().unwrap_or("").to_string();
                        if first.is_empty() { None } else { Some(first) }
                    }),
                _ => None,
            };
            Toolchain {
                id: (*id).to_string(),
                label: (*label).to_string(),
                available: version.is_some(),
                version,
            }
        })
        .collect()
}

fn sync_provision(app: &RunApp) {
    provision::write_runtime(
        &app.domain,
        &provision::RuntimeProvision {
            domain: app.domain.clone(),
            app: app.app.clone(),
            runtime: app.runtime.clone(),
            entrypoint: app.entrypoint.clone(),
            target: format!("127.0.0.1:{}", app.port),
            auto_restart: app.auto_restart,
        },
    );
}

fn validate_entrypoint(root: &std::path::Path, entrypoint: &str) -> Result<(), ApiError> {
    if entrypoint.is_empty()
        || entrypoint.starts_with('/')
        || entrypoint.split('/').any(|p| p == ".." || p.is_empty())
    {
        return Err(ApiError::new(
            StatusCode::BAD_REQUEST,
            "Entry point must be a relative path without '..'",
        ));
    }
    let candidate = root.join(entrypoint);
    if !candidate.is_file() {
        return Err(ApiError::new(
            StatusCode::BAD_REQUEST,
            format!("Entry point not found: {entrypoint}"),
        ));
    }
    Ok(())
}

fn stop_child(state: &AppState, id: i64) {
    let mut map = state.runtimes.lock().unwrap();
    if let Some(mut c) = map.remove(&id) {
        let _ = c.kill();
        let _ = c.wait();
    }
}

fn env_map(app: &RunApp) -> Vec<(String, String)> {
    let mut out = Vec::new();
    if let Some(env) = &app.env {
        if let Ok(serde_json::Value::Object(map)) = serde_json::from_str::<serde_json::Value>(env) {
            for (k, v) in map {
                if let serde_json::Value::String(s) = v {
                    out.push((k, s));
                }
            }
        }
    }
    out
}

fn start_child(
    state: &AppState,
    username: &str,
    app: &RunApp,
) -> Result<(), ApiError> {
    let cmd = bin_cmd(&app.runtime).ok_or_else(|| {
        ApiError::new(
            StatusCode::BAD_REQUEST,
            format!("Unsupported runtime '{}'", app.runtime),
        )
    })?;

    let root = provision::account_htdocs(username);
    validate_entrypoint(&root, &app.entrypoint)?;

    let log = provision::runtime_log_file(&app.domain);
    if let Some(parent) = log.parent() {
        let _ = std::fs::create_dir_all(parent);
    }
    let logf = std::fs::OpenOptions::new()
        .create(true)
        .append(true)
        .open(&log)
        .map_err(|e| internal_error(e.into()))?;

    let mut cmd = Command::new(cmd);
    cmd.current_dir(&root)
        .arg(&app.entrypoint)
        .env("PORT", app.port.to_string())
        .env("DOMAIN", &app.domain)
        .env("FPANEL_ACCOUNT", username)
        .stdin(Stdio::null())
        .stdout(Stdio::from(logf.try_clone().map_err(|e| internal_error(e.into()))?))
        .stderr(Stdio::from(logf));

    for (k, v) in env_map(app) {
        cmd.env(k, v);
    }

    let child = cmd.spawn().map_err(|e| {
        ApiError::new(
            StatusCode::BAD_REQUEST,
            format!("Could not start app: {e}"),
        )
    })?;

    state.runtimes.lock().unwrap().insert(app.id, child);
    Ok(())
}

fn mark_running(state: &AppState, app: &mut RunApp) {
    app.status = "running".to_string();
    if let Some(c) = state.runtimes.lock().unwrap().get(&app.id) {
        app.pid = Some(c.id() as i64);
    }
}

fn log_lines(app: &RunApp, lines: i64) -> serde_json::Value {
    let path = provision::runtime_log_file(&app.domain);
    let text = std::fs::read_to_string(&path).unwrap_or_default();
    let n = lines.clamp(1, 2000) as usize;
    let tail: Vec<&str> = text.lines().rev().take(n).collect();
    let tail: Vec<String> = tail.into_iter().rev().map(|s| s.to_string()).collect();
    serde_json::json!({ "lines": tail })
}

async fn username_of(state: &AppState, aid: i64) -> Result<String, ApiError> {
    sqlx::query_scalar::<_, String>("SELECT username FROM accounts WHERE id = ?")
        .bind(aid)
        .fetch_optional(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?
        .ok_or_else(|| ApiError::new(StatusCode::NOT_FOUND, "Account not found"))
}

async fn create_app(
    state: &AppState,
    aid: i64,
    username: String,
    body: CreateApp,
) -> Result<RunApp, ApiError> {
    if bin_cmd(&body.runtime).is_none() {
        return Err(ApiError::new(
            StatusCode::BAD_REQUEST,
            format!("Unsupported runtime '{}'", body.runtime),
        ));
    }

    let (domain_name,): (String,) = sqlx::query_as(
        "SELECT name FROM domains WHERE id = ? AND account_id = ? AND status = 'active'",
    )
    .bind(body.domain_id)
    .bind(aid)
    .fetch_optional(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?
    .ok_or_else(|| ApiError::new(StatusCode::NOT_FOUND, "Domain not found"))?;

    let port = body.port.unwrap_or(0);
    if !(1..=65535).contains(&port) {
        return Err(ApiError::new(
            StatusCode::BAD_REQUEST,
            "A valid port (1-65535) is required",
        ));
    }

    let entrypoint = body.entrypoint.trim().to_string();
    let root = provision::account_htdocs(&username);
    validate_entrypoint(&root, &entrypoint)?;

    let existing_domain: i64 = sqlx::query_scalar(
        "SELECT COUNT(*) FROM run_apps WHERE domain_id = ?",
    )
    .bind(body.domain_id)
    .fetch_one(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?;
    if existing_domain > 0 {
        return Err(ApiError::new(
            StatusCode::CONFLICT,
            format!("An app for {domain_name} already exists"),
        ));
    }

    let conflict: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM run_apps WHERE port = ?")
        .bind(port)
        .fetch_one(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?;
    if conflict > 0 {
        return Err(ApiError::new(
            StatusCode::CONFLICT,
            format!("Port {port} is already in use"),
        ));
    }

    let env = body.env.filter(|e| !e.trim().is_empty());
    let auto = body.auto_restart.unwrap_or(false);

    let result = sqlx::query(
        "INSERT INTO run_apps (account_id, domain_id, domain, app, runtime, entrypoint, port, env, auto_restart, status) \
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'stopped')",
    )
    .bind(aid)
    .bind(body.domain_id)
    .bind(&domain_name)
    .bind(body.app.as_deref().unwrap_or("app"))
    .bind(&body.runtime)
    .bind(&entrypoint)
    .bind(port)
    .bind(env)
    .bind(if auto { 1 } else { 0 })
    .execute(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?;
    let id = result.last_insert_rowid();

    let app = fetch_app(&state.db, aid, id).await?;
    sync_provision(&app);
    Ok(app)
}

async fn delete_app(state: &AppState, aid: i64, id: i64) -> Result<(), ApiError> {
    let app = fetch_app(&state.db, aid, id).await?;
    stop_child(state, id);
    provision::remove_runtime(&app.domain);
    sqlx::query("DELETE FROM run_apps WHERE id = ? AND account_id = ?")
        .bind(id)
        .bind(aid)
        .execute(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?;
    Ok(())
}

// ---------- clients ----------

async fn list_client(
    State(state): State<AppState>,
    headers: axum::http::HeaderMap,
) -> Result<Json<ListResp>, ApiError> {
    let (aid, _) = bearer_account(&state, &headers).await?;
    let mut apps = list_apps(&state.db, aid).await?;
    reconciling(&state, &mut apps);
    Ok(Json(ListResp {
        apps,
        toolchains: toolchains(),
    }))
}

async fn create_client(
    State(state): State<AppState>,
    headers: axum::http::HeaderMap,
    Json(body): Json<CreateApp>,
) -> Result<(StatusCode, Json<RunApp>), ApiError> {
    let (aid, username) = bearer_account(&state, &headers).await?;
    let app = create_app(&state, aid, username, body).await?;
    Ok((StatusCode::CREATED, Json(app)))
}

async fn start_client(
    State(state): State<AppState>,
    headers: axum::http::HeaderMap,
    Path((_sess, id)): Path<(String, i64)>,
) -> Result<Json<RunApp>, ApiError> {
    let (aid, username) = bearer_account(&state, &headers).await?;
    let mut app = fetch_app(&state.db, aid, id).await?;
    stop_child(&state, id);
    start_child(&state, &username, &app)?;
    mark_running(&state, &mut app);
    set_status(&state.db, id, "running", app.pid).await;
    Ok(Json(app))
}

async fn stop_client(
    State(state): State<AppState>,
    headers: axum::http::HeaderMap,
    Path((_sess, id)): Path<(String, i64)>,
) -> Result<Json<RunApp>, ApiError> {
    let (aid, _) = bearer_account(&state, &headers).await?;
    let mut app = fetch_app(&state.db, aid, id).await?;
    stop_child(&state, id);
    app.status = "stopped".to_string();
    app.pid = None;
    set_status(&state.db, id, "stopped", None).await;
    Ok(Json(app))
}

async fn restart_client(
    State(state): State<AppState>,
    headers: axum::http::HeaderMap,
    Path((_sess, id)): Path<(String, i64)>,
) -> Result<Json<RunApp>, ApiError> {
    let (aid, username) = bearer_account(&state, &headers).await?;
    let mut app = fetch_app(&state.db, aid, id).await?;
    stop_child(&state, id);
    start_child(&state, &username, &app)?;
    mark_running(&state, &mut app);
    set_status(&state.db, id, "running", app.pid).await;
    Ok(Json(app))
}

async fn log_client(
    State(state): State<AppState>,
    headers: axum::http::HeaderMap,
    Path((_sess, id)): Path<(String, i64)>,
    Query(q): Query<LogQ>,
) -> Result<Json<serde_json::Value>, ApiError> {
    let (aid, _) = bearer_account(&state, &headers).await?;
    let app = fetch_app(&state.db, aid, id).await?;
    Ok(Json(log_lines(&app, q.lines.unwrap_or(200))))
}

async fn delete_client(
    State(state): State<AppState>,
    headers: axum::http::HeaderMap,
    Path((_sess, id)): Path<(String, i64)>,
) -> Result<StatusCode, ApiError> {
    let (aid, _) = bearer_account(&state, &headers).await?;
    delete_app(&state, aid, id).await?;
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
    let mut apps = list_apps(&state.db, aid).await?;
    reconciling(&state, &mut apps);
    Ok(Json(ListResp {
        apps,
        toolchains: toolchains(),
    }))
}

async fn create_admin(
    State(state): State<AppState>,
    Query(q): Query<AccountQ>,
    Json(body): Json<CreateApp>,
) -> Result<(StatusCode, Json<RunApp>), ApiError> {
    let aid = require_account(q.account_id)?;
    let username = username_of(&state, aid).await?;
    let app = create_app(&state, aid, username, body).await?;
    Ok((StatusCode::CREATED, Json(app)))
}

async fn start_admin(
    State(state): State<AppState>,
    Query(q): Query<AccountQ>,
    Path((_sess, id)): Path<(String, i64)>,
) -> Result<Json<RunApp>, ApiError> {
    let aid = require_account(q.account_id)?;
    let username = username_of(&state, aid).await?;
    let mut app = fetch_app(&state.db, aid, id).await?;
    stop_child(&state, id);
    start_child(&state, &username, &app)?;
    mark_running(&state, &mut app);
    set_status(&state.db, id, "running", app.pid).await;
    Ok(Json(app))
}

async fn stop_admin(
    State(state): State<AppState>,
    Query(q): Query<AccountQ>,
    Path((_sess, id)): Path<(String, i64)>,
) -> Result<Json<RunApp>, ApiError> {
    let aid = require_account(q.account_id)?;
    let mut app = fetch_app(&state.db, aid, id).await?;
    stop_child(&state, id);
    app.status = "stopped".to_string();
    app.pid = None;
    set_status(&state.db, id, "stopped", None).await;
    Ok(Json(app))
}

async fn restart_admin(
    State(state): State<AppState>,
    Query(q): Query<AccountQ>,
    Path((_sess, id)): Path<(String, i64)>,
) -> Result<Json<RunApp>, ApiError> {
    let aid = require_account(q.account_id)?;
    let username = username_of(&state, aid).await?;
    let mut app = fetch_app(&state.db, aid, id).await?;
    stop_child(&state, id);
    start_child(&state, &username, &app)?;
    mark_running(&state, &mut app);
    set_status(&state.db, id, "running", app.pid).await;
    Ok(Json(app))
}

async fn log_admin(
    State(state): State<AppState>,
    Query(q): Query<LogQ>,
    Path((_sess, id)): Path<(String, i64)>,
) -> Result<Json<serde_json::Value>, ApiError> {
    let aid = require_account(q.account_id)?;
    let app = fetch_app(&state.db, aid, id).await?;
    Ok(Json(log_lines(&app, q.lines.unwrap_or(200))))
}

async fn delete_admin(
    State(state): State<AppState>,
    Query(q): Query<AccountQ>,
    Path((_sess, id)): Path<(String, i64)>,
) -> Result<StatusCode, ApiError> {
    let aid = require_account(q.account_id)?;
    delete_app(&state, aid, id).await?;
    Ok(StatusCode::NO_CONTENT)
}