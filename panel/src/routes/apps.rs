use std::path::{Path, PathBuf};
use std::process::Command;

use axum::extract::{Path as AxPath, Query, State};
use axum::http::{HeaderMap, StatusCode};
use axum::routing::{delete, get};
use axum::{Json, Router};
use serde::{Deserialize, Serialize};
use sqlx::Row;

use crate::db::AppState;
use crate::error::{internal_error, ApiError};
use crate::provision;
use crate::routes::domains::bearer_account;

const WPCLI_URL: &str = "https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar";
const OJS_TARBALL: &str = "https://pkp.sfu.ca/ojs/download/ojs-3.4.0-5.tar.gz";
const OJS_VERSION: &str = "3.4.0-5";

#[derive(Serialize)]
struct AppRow {
    id: i64,
    account_id: i64,
    domain_id: i64,
    domain: String,
    app: String,
    path: String,
    version: Option<String>,
    db_name: Option<String>,
    db_user: Option<String>,
    admin_user: Option<String>,
    admin_email: Option<String>,
    status: String,
    created_at: String,
}

#[derive(Serialize)]
struct ToolsInfo {
    wpcli: bool,
    composer: bool,
    php: bool,
    php_version: String,
    ojs: bool,
}

#[derive(Serialize)]
struct ListResp {
    rows: Vec<AppRow>,
    tools: ToolsInfo,
}

#[derive(Deserialize)]
struct InstallBody {
    domain_id: i64,
    app: String,
    site_title: Option<String>,
    admin_user: Option<String>,
    admin_password: Option<String>,
    admin_email: Option<String>,
}

#[derive(Deserialize)]
struct AccountQ {
    account_id: Option<i64>,
}

pub fn router() -> Router<AppState> {
    Router::new()
        .route("/", get(list_admin).post(install_admin))
        .route("/{id}", delete(uninstall_admin))
}

pub fn client_router() -> Router<AppState> {
    Router::new()
        .route("/", get(list_client).post(install_client))
        .route("/{id}", delete(uninstall_client))
}

// ---------- shared helpers ----------

fn tools_dir() -> PathBuf {
    provision::data_dir().join("tools")
}

fn wpcli_phar() -> PathBuf {
    tools_dir().join("wp-cli.phar")
}

fn mysql_pool(state: &AppState) -> Result<sqlx::MySqlPool, ApiError> {
    state
        .mysql
        .clone()
        .ok_or_else(|| ApiError::new(StatusCode::SERVICE_UNAVAILABLE, "Database server (MariaDB) is unavailable"))
}

fn sanitize(s: &str) -> String {
    s.chars().filter(|c| c.is_ascii_alphanumeric() || *c == '_').collect::<String>().to_lowercase()
}

fn gen_pass() -> String {
    use std::time::{SystemTime, UNIX_EPOCH};
    let seed = SystemTime::now().duration_since(UNIX_EPOCH).unwrap_or_default().as_nanos();
    let s = format!("{}{}", seed, uuid::Uuid::new_v4().simple());
    let chars = s.chars().filter(|c| c.is_ascii_alphanumeric()).collect::<String>();
    let mut out = String::new();
    for c in chars.chars().take(18) {
        out.push(c.to_ascii_uppercase());
    }
    out
}

fn run_cmd(dir: &Path, bin: &str, args: &[&str]) -> Result<String, String> {
    let out = Command::new(bin)
        .args(args)
        .current_dir(dir)
        .output()
        .map_err(|e| format!("could not run {bin}: {e}"))?;
    let log = String::from_utf8_lossy(&out.stdout).to_string()
        + &String::from_utf8_lossy(&out.stderr);
    if out.status.success() {
        Ok(log.trim().to_string())
    } else {
        Err(log.trim().to_string())
    }
}

fn run_stdout(dir: &Path, bin: &str, args: &[&str]) -> String {
    Command::new(bin)
        .args(args)
        .current_dir(dir)
        .output()
        .ok()
        .filter(|o| o.status.success())
        .map(|o| String::from_utf8_lossy(&o.stdout).trim().to_string())
        .unwrap_or_default()
}

fn wipe_dir(root: &Path) {
    if let Ok(rd) = std::fs::read_dir(root) {
        for e in rd.flatten() {
            let p = e.path();
            if p.is_dir() {
                let _ = std::fs::remove_dir_all(&p);
            } else {
                let _ = std::fs::remove_file(&p);
            }
        }
    }
}

fn htdocs_ready(root: &Path) -> Result<(), ApiError> {
    if !root.exists() {
        return Ok(());
    }
    if root.is_file() {
        return Err(ApiError::new(StatusCode::CONFLICT, "The document root is a file, not a directory"));
    }
    let allowed = ["index.html", "index.htm", ".htaccess"];
    for e in std::fs::read_dir(root).map_err(|e| internal_error(e.into()))?.flatten() {
        let name = e.file_name().to_string_lossy().into_owned();
        if e.path().is_file() {
            if !allowed.contains(&name.as_str()) {
                return Err(ApiError::new(
                    StatusCode::CONFLICT,
                    format!("The document root already contains files. Install requires an empty document root (found '{name}')."),
                ));
            }
        } else {
            return Err(ApiError::new(
                StatusCode::CONFLICT,
                format!("The document root already contains a directory '{name}'. Install requires an empty document root."),
            ));
        }
    }
    Ok(())
}

async fn domain_owned(state: &AppState, aid: i64, domain_id: i64) -> Result<String, ApiError> {
    let name: Option<String> = sqlx::query_scalar(
        "SELECT name FROM domains WHERE id = ? AND account_id = ? AND status = 'active'",
    )
    .bind(domain_id)
    .bind(aid)
    .fetch_optional(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?;
    name.ok_or_else(|| ApiError::new(StatusCode::NOT_FOUND, "Domain not found"))
}

async fn ensure_no_app(state: &AppState, domain_id: i64) -> Result<(), ApiError> {
    let a: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM installed_apps WHERE domain_id = ?")
        .bind(domain_id)
        .fetch_one(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?;
    if a > 0 {
        return Err(ApiError::new(StatusCode::CONFLICT, "An application is already installed on this domain"));
    }
    let b: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM run_apps WHERE domain_id = ?")
        .bind(domain_id)
        .fetch_one(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?;
    if b > 0 {
        return Err(ApiError::new(StatusCode::CONFLICT, "A runtime app is already bound to this domain"));
    }
    Ok(())
}

fn db_ident(username: &str, app: &str, suffix: u32) -> (String, String) {
    let tag = sanitize(username);
    let app = app.chars().take(3).collect::<String>();
    let mut base = format!("fp_{tag}_{app}");
    if suffix > 0 {
        base.push_str(&suffix.to_string());
    }
    let db = base[..base.len().min(58)].to_string();
    let user = base[..base.len().min(27)].to_string();
    (db, user)
}

async fn ensure_db_user(state: &AppState, username: &str, app: &str) -> Result<(String, String, String), ApiError> {
    let db = mysql_pool(state)?;
    let pass = gen_pass();
    let mut suffix = 0u32;
    let mut pair = db_ident(username, app, suffix);
    loop {
        let exists: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM installed_apps WHERE db_name = ?")
            .bind(&pair.0)
            .fetch_one(&state.db)
            .await
            .map_err(|e| internal_error(e.into()))?;
        if exists == 0 {
            break;
        }
        suffix += 1;
        pair = db_ident(username, app, suffix);
    }
    let (db_name, user_name) = pair;
    sqlx::query(&format!(
        "CREATE DATABASE IF NOT EXISTS `{db_name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    ))
    .execute(&db)
    .await
    .map_err(|e| internal_error(e.into()))?;
    for host in ["localhost", "127.0.0.1"] {
        let create = format!(
            "CREATE USER IF NOT EXISTS `{user_name}`@'{host}' IDENTIFIED BY '{}'",
            pass.replace('\'', "''")
        );
        sqlx::query(&create).execute(&db).await.map_err(|e| internal_error(e.into()))?;
        let alt = format!(
            "ALTER USER `{user_name}`@'{host}' IDENTIFIED BY '{}'",
            pass.replace('\'', "''")
        );
        sqlx::query(&alt).execute(&db).await.map_err(|e| internal_error(e.into()))?;
        let grant = format!("GRANT ALL PRIVILEGES ON `{db_name}`.* TO `{user_name}`@'{host}'");
        sqlx::query(&grant).execute(&db).await.map_err(|e| internal_error(e.into()))?;
    }
    sqlx::query("FLUSH PRIVILEGES").execute(&db).await.map_err(|e| internal_error(e.into()))?;
    Ok((db_name, user_name, pass))
}

async fn drop_db_user(state: &AppState, db_name: &str, user_name: &str) {
    if let Ok(db) = mysql_pool(state) {
        for host in ["localhost", "127.0.0.1"] {
            let _ = sqlx::query(&format!("DROP USER IF EXISTS `{user_name}`@'{host}'"))
                .execute(&db)
                .await;
        }
        let _ = sqlx::query(&format!("DROP DATABASE IF EXISTS `{db_name}`"))
            .execute(&db)
            .await;
    }
}

fn tool_present(name: &str) -> bool {
    Command::new(name).arg("--version").output().map(|o| o.status.success()).unwrap_or(false)
}

fn php_version() -> String {
    Command::new("php")
        .arg("-r")
        .arg("echo PHP_VERSION;")
        .output()
        .ok()
        .filter(|o| o.status.success())
        .map(|o| String::from_utf8_lossy(&o.stdout).trim().to_string())
        .unwrap_or_default()
}

fn tools_info() -> ToolsInfo {
    ToolsInfo {
        wpcli: wpcli_phar().is_file() || tool_present("curl"),
        composer: tool_present("composer"),
        php: true,
        php_version: php_version(),
        ojs: true,
    }
}

// ---------- WordPress ----------

fn ensure_wpcli() -> Result<PathBuf, String> {
    let dir = tools_dir();
    let _ = std::fs::create_dir_all(&dir);
    let target = wpcli_phar();
    if target.is_file() {
        return Ok(target);
    }
    let tmp = target.with_extension("part");
    let out = Command::new("curl")
        .args(["-fSL", "--max-time", "180", "-o"])
        .arg(&tmp)
        .arg(WPCLI_URL)
        .output()
        .map_err(|e| format!("curl unavailable: {e}"))?;
    if !out.status.success() || !tmp.is_file() || !tmp.metadata().map(|m| m.len() > 1000).unwrap_or(false) {
        let _ = std::fs::remove_file(&tmp);
        return Err("Could not download wp-cli (network unavailable)".to_string());
    }
    let _ = std::fs::rename(&tmp, &target);
    Ok(target)
}

fn install_wordpress(
    root: &Path,
    domain: &str,
    db_name: &str,
    db_user: &str,
    db_pass: &str,
    body: &InstallBody,
) -> Result<(String, String), String> {
    let wpcli = ensure_wpcli()?;
    let title = body.site_title.clone().unwrap_or_else(|| domain.to_string());
    let admin_user = body.admin_user.clone().ok_or("admin_user is required")?;
    if admin_user.len() < 3 {
        return Err("admin_user must be at least 3 characters".to_string());
    }
    let admin_pass = body.admin_password.clone().ok_or("admin_password is required")?;
    if admin_pass.len() < 6 {
        return Err("admin_password must be at least 6 characters".to_string());
    }
    let admin_email = body.admin_email.clone().ok_or("admin_email is required")?;

    let wpcli_s = wpcli.to_str().unwrap();
    let stages: Vec<Vec<String>> = vec![
        vec!["core".to_string(), "download".to_string(), "--force".to_string()],
        vec![
            "config".to_string(), "create".to_string(),
            format!("--dbname={db_name}"),
            format!("--dbuser={db_user}"),
            format!("--dbpass={db_pass}"),
            "--dbhost=127.0.0.1:3306".to_string(),
            "--force".to_string(),
        ],
        vec![
            "core".to_string(), "install".to_string(),
            format!("--url=http://{domain}/"),
            format!("--title={title}"),
            format!("--admin_user={admin_user}"),
            format!("--admin_password={admin_pass}"),
            format!("--admin_email={admin_email}"),
            "--skip-email".to_string(),
        ],
    ];
    for stage in &stages {
        let mut full =
            vec!["-d".to_string(), "display_errors=0".to_string(), "-d".to_string(), "memory_limit=512M".to_string(), wpcli_s.to_string()];
        full.extend(stage.iter().cloned());
        let refs: Vec<&str> = full.iter().map(|s| s.as_str()).collect();
        if let Err(e) = run_cmd(root, "php", &refs) {
            return Err(e);
        }
    }
    let ver = vec![
        "-d".to_string(),
        "display_errors=0".to_string(),
        "-d".to_string(),
        "memory_limit=512M".to_string(),
        wpcli_s.to_string(),
        "core".to_string(),
        "version".to_string(),
    ];
    let ver_refs: Vec<&str> = ver.iter().map(|s| s.as_str()).collect();
    let version = run_stdout(root, "php", &ver_refs);
    let version = if version.is_empty() { "wordpress".to_string() } else { version };
    Ok((version, String::new()))
}

// ---------- Laravel ----------

fn install_laravel(
    root: &Path,
    domain: &str,
    db_name: &str,
    db_user: &str,
    db_pass: &str,
) -> Result<(String, String), String> {
    if !tool_present("composer") {
        return Err("composer is not installed on this server".to_string());
    }
    run_cmd(root, "composer", &["create-project", "laravel/laravel", ".", "--no-interaction", "--no-progress"])?;

    let env = root.join(".env");
    let text = std::fs::read_to_string(&env).map_err(|e| format!("could not read .env: {e}"))?;
    let mut out = String::new();
    for line in text.lines() {
        let updated = if line.starts_with("APP_URL=") {
            format!("APP_URL=http://{domain}/")
        } else if line.starts_with("DB_CONNECTION=") {
            "DB_CONNECTION=mysql".to_string()
        } else if line.starts_with("DB_HOST=") {
            "DB_HOST=127.0.0.1".to_string()
        } else if line.starts_with("DB_PORT=") {
            "DB_PORT=3306".to_string()
        } else if line.starts_with("DB_DATABASE=") {
            format!("DB_DATABASE={db_name}")
        } else if line.starts_with("DB_USERNAME=") {
            format!("DB_USERNAME={db_user}")
        } else if line.starts_with("DB_PASSWORD=") {
            format!("DB_PASSWORD={db_pass}")
        } else {
            line.to_string()
        };
        out.push_str(&updated);
        out.push('\n');
    }
    std::fs::write(&env, out).map_err(|e| format!("could not write .env: {e}"))?;

    run_cmd(root, "php", &["artisan", "key:generate"])?;
    run_cmd(root, "php", &["artisan", "migrate", "--force"])?;
    let version = run_stdout(root, "php", &["artisan", "--version"]);
    Ok((version.trim().to_string(), String::new()))
}

// ---------- OJS ----------

fn install_ojs(root: &Path, db_name: &str, db_user: &str, db_pass: &str) -> Result<(String, String), String> {
    let dir = tools_dir();
    let _ = std::fs::create_dir_all(&dir);
    let tgz = dir.join(format!("ojs-{OJS_VERSION}.tar.gz"));
    if !tgz.is_file() {
        let out = Command::new("curl")
            .args(["-fSL", "--max-time", "300", "-o"])
            .arg(&tgz)
            .arg(OJS_TARBALL)
            .output()
            .map_err(|e| format!("curl unavailable: {e}"))?;
        if !out.status.success() || !tgz.is_file() || !tgz.metadata().map(|m| m.len() > 1000).unwrap_or(false) {
            let _ = std::fs::remove_file(&tgz);
            return Err("Could not download OJS (network unavailable)".to_string());
        }
    }
    let _ = std::fs::create_dir_all(root);
    let out = Command::new("tar")
        .args(["-xzf"])
        .arg(&tgz)
        .args(["-C"])
        .arg(root)
        .args(["--strip-components=1"])
        .output()
        .map_err(|e| format!("could not extract OJS: {e}"))?;
    if !out.status.success() || !root.join("index.php").is_file() {
        return Err("OJS archive seems invalid after extraction".to_string());
    }
    let conf = root.join("config.inc.php");
    if !conf.is_file() {
        return Err("OJS config template missing after extraction".to_string());
    }
    let text = std::fs::read_to_string(&conf).map_err(|e| format!("could not read config: {e}"))?;
    let mut new = String::new();
    for line in text.lines() {
        let n = if line.starts_with("driver =") {
            "driver = mysqli".to_string()
        } else if line.starts_with("host =") {
            "host = 127.0.0.1".to_string()
        } else if line.starts_with("username =") {
            format!("username = {db_user}")
        } else if line.starts_with("password =") {
            format!("password = {db_pass}")
        } else if line.starts_with("name =") {
            format!("name = {db_name}")
        } else {
            line.to_string()
        };
        new.push_str(&n);
        new.push('\n');
    }
    std::fs::write(&conf, new).map_err(|e| format!("could not write config: {e}"))?;
    Ok((OJS_VERSION.to_string(), String::new()))
}

// ---------- core install flow ----------

async fn run_install(
    state: &AppState,
    aid: i64,
    username: String,
    body: InstallBody,
) -> Result<AppRow, ApiError> {
    let app = body.app.trim().to_lowercase();
    if !["wordpress", "laravel", "ojs"].contains(&app.as_str()) {
        return Err(ApiError::new(
            StatusCode::BAD_REQUEST,
            format!("Unknown application '{app}'. Choose wordpress, laravel or ojs."),
        ));
    }
    let domain = domain_owned(state, aid, body.domain_id).await?;
    ensure_no_app(state, body.domain_id).await?;

    let root = provision::account_htdocs(&username);
    if !root.exists() {
        std::fs::create_dir_all(&root).map_err(|e| internal_error(e.into()))?;
    }
    htdocs_ready(&root)?;

    let (db_name, db_user, db_pass) = ensure_db_user(state, &username, &app).await?;

    let app_kind = app.clone();
    let root_b = root.clone();
    let domain_b = domain.clone();
    let body_b = InstallBody {
        domain_id: body.domain_id,
        app: app.clone(),
        site_title: body.site_title.clone(),
        admin_user: body.admin_user.clone(),
        admin_password: body.admin_password.clone(),
        admin_email: body.admin_email.clone(),
    };
    let db_name_b = db_name.clone();
    let db_user_b = db_user.clone();
    let db_pass_b = db_pass.clone();

    let out = tokio::task::spawn_blocking(move || -> Result<(String, String), String> {
        match app_kind.as_str() {
            "wordpress" => install_wordpress(&root_b, &domain_b, &db_name_b, &db_user_b, &db_pass_b, &body_b),
            "laravel" => install_laravel(&root_b, &domain_b, &db_name_b, &db_user_b, &db_pass_b),
            "ojs" => install_ojs(&root_b, &db_name_b, &db_user_b, &db_pass_b),
            _ => unreachable!(),
        }
    })
    .await
    .map_err(|e| internal_error(e.into()))?;

    let (version, _notes) = match out {
        Ok(x) => x,
        Err(msg) => {
            wipe_root(&root, &username);
            drop_db_user(state, &db_name, &db_user).await;
            return Err(ApiError::new(StatusCode::BAD_REQUEST, msg));
        }
    };
    let now = chrono::Utc::now().to_rfc3339();
    let result = sqlx::query(
        "INSERT INTO installed_apps \
         (account_id, domain_id, domain, app, path, version, db_name, db_user, admin_user, admin_email, status) \
         VALUES (?, ?, ?, ?, '/', ?, ?, ?, ?, ?, 'installed')",
    )
    .bind(aid)
    .bind(body.domain_id)
    .bind(&domain)
    .bind(&app)
    .bind(version.trim())
    .bind(&db_name)
    .bind(&db_user)
    .bind(body.admin_user.clone())
    .bind(body.admin_email.clone())
    .execute(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?;

    let id = result.last_insert_rowid();
    Ok(AppRow {
        id,
        account_id: aid,
        domain_id: body.domain_id,
        domain,
        app,
        path: "/".to_string(),
        version: Some(version.trim().to_string()),
        db_name: Some(db_name),
        db_user: Some(db_user),
        admin_user: body.admin_user,
        admin_email: body.admin_email,
        status: "installed".to_string(),
        created_at: now,
    })
}

fn wipe_root(root: &Path, _username: &str) {
    wipe_dir(root);
    let _ = std::fs::remove_dir_all(root);
}

async fn list_rows(db: &sqlx::SqlitePool, aid: i64) -> Result<Vec<AppRow>, ApiError> {
    let rows = sqlx::query(
        "SELECT id, account_id, domain_id, domain, app, path, version, db_name, db_user, \
         admin_user, admin_email, status, created_at \
         FROM installed_apps WHERE account_id = ? ORDER BY created_at DESC",
    )
    .bind(aid)
    .fetch_all(db)
    .await
    .map_err(|e| internal_error(e.into()))?;
    Ok(rows
        .iter()
        .map(|r| AppRow {
            id: r.get(0),
            account_id: r.get(1),
            domain_id: r.get(2),
            domain: r.get(3),
            app: r.get(4),
            path: r.get(5),
            version: r.get(6),
            db_name: r.get(7),
            db_user: r.get(8),
            admin_user: r.get(9),
            admin_email: r.get(10),
            status: r.get(11),
            created_at: r.get(12),
        })
        .collect())
}

async fn uninstall(state: &AppState, aid: i64, id: i64) -> Result<(), ApiError> {
    let row = sqlx::query(
        "SELECT domain_id, domain, app, db_name, db_user FROM installed_apps WHERE id = ? AND account_id = ?",
    )
    .bind(id)
    .bind(aid)
    .fetch_optional(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?;
    let Some(row) = row else {
        return Err(ApiError::new(StatusCode::NOT_FOUND, "Installed app not found"));
    };
    let domain_id: i64 = row.get(0);
    let domain: String = row.get(1);
    let app: String = row.get(2);
    let db_name: Option<String> = row.get(3);
    let db_user: Option<String> = row.get(4);

    let username: String = sqlx::query_scalar("SELECT username FROM accounts WHERE id = ?")
        .bind(aid)
        .fetch_one(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?;

    let rrow = sqlx::query("SELECT id FROM run_apps WHERE domain_id = ?")
        .bind(domain_id)
        .fetch_optional(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?;
    if let Some(r) = rrow {
        let rid: i64 = r.get(0);
        if let Some(mut c) = state.runtimes.lock().unwrap().remove(&rid) {
            let _ = c.kill();
            let _ = c.wait();
        }
        sqlx::query("DELETE FROM run_apps WHERE id = ?")
            .bind(rid)
            .execute(&state.db)
            .await
            .map_err(|e| internal_error(e.into()))?;
        provision::remove_runtime(&domain);
    }

    let root = provision::account_htdocs(&username);
    wipe_dir(&root);
    if let Ok(rd) = std::fs::read_dir(&root) {
        for e in rd.flatten() {
            let p = e.path();
            if p.is_dir() {
                let _ = std::fs::remove_dir_all(&p);
            }
        }
    }

    if let (Some(d), Some(u)) = (db_name.as_ref(), db_user.as_ref()) {
        drop_db_user(state, d, u).await;
    }

    sqlx::query("DELETE FROM installed_apps WHERE id = ? AND account_id = ?")
        .bind(id)
        .bind(aid)
        .execute(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?;
    let _ = &app;
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
        tools: tools_info(),
    }))
}

async fn install_client(
    State(state): State<AppState>,
    headers: HeaderMap,
    Json(body): Json<InstallBody>,
) -> Result<Json<AppRow>, ApiError> {
    let (aid, username) = bearer_account(&state, &headers).await?;
    Ok(Json(run_install(&state, aid, username, body).await?))
}

async fn uninstall_client(
    State(state): State<AppState>,
    headers: HeaderMap,
    AxPath((_sess, id)): AxPath<(String, i64)>,
) -> Result<StatusCode, ApiError> {
    let (aid, _) = bearer_account(&state, &headers).await?;
    uninstall(&state, aid, id).await?;
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
        tools: tools_info(),
    }))
}

async fn install_admin(
    State(state): State<AppState>,
    Query(q): Query<AccountQ>,
    Json(body): Json<InstallBody>,
) -> Result<Json<AppRow>, ApiError> {
    let aid = require_account(q.account_id)?;
    let username: String =
        sqlx::query_scalar("SELECT username FROM accounts WHERE id = ?")
            .bind(aid)
            .fetch_optional(&state.db)
            .await
            .map_err(|e| internal_error(e.into()))?
            .ok_or_else(|| ApiError::new(StatusCode::NOT_FOUND, "Account not found"))?;
    Ok(Json(run_install(&state, aid, username, body).await?))
}

async fn uninstall_admin(
    State(state): State<AppState>,
    Query(q): Query<AccountQ>,
    AxPath((_sess, id)): AxPath<(String, i64)>,
) -> Result<StatusCode, ApiError> {
    let aid = require_account(q.account_id)?;
    uninstall(&state, aid, id).await?;
    Ok(StatusCode::NO_CONTENT)
}