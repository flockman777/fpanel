use anyhow::{Context, Result};
use sqlx::mysql::{MySqlConnectOptions, MySqlPoolOptions};
use sqlx::sqlite::SqlitePoolOptions;
use sqlx::{MySqlPool, SqlitePool};
use std::collections::HashMap;
use std::process::Child;
use std::sync::{Arc, Mutex};

#[derive(Clone)]
pub struct AppState {
    pub db: SqlitePool,
    pub jwt_secret: String,
    pub mysql: Option<MySqlPool>,
    pub runtimes: Arc<Mutex<HashMap<i64, Child>>>,
}

pub async fn init_db(db_path: &str, jwt_secret: String) -> Result<AppState> {
    if db_path != ":memory:" {
        if let Some(parent) = std::path::Path::new(db_path).parent() {
            std::fs::create_dir_all(parent).context("create db dir")?;
        }
        std::fs::OpenOptions::new()
            .create(true)
            .write(true)
            .truncate(false)
            .open(db_path)
            .context("create db file")?;
    }

    let pool = SqlitePoolOptions::new()
        .max_connections(5)
        .connect(&format!("sqlite:{db_path}"))
        .await
        .context("connect to sqlite")?;

    let schema = include_str!("../../db/schema.sql");
    sqlx::raw_sql(schema)
        .execute(&pool)
        .await
        .context("run schema")?;

    let cols: Vec<String> = sqlx::query_scalar("SELECT name FROM pragma_table_info('accounts')")
        .fetch_all(&pool)
        .await
        .context("read accounts columns")?;
    if !cols.iter().any(|name| name == "password_hash") {
        sqlx::raw_sql("ALTER TABLE accounts ADD COLUMN password_hash TEXT")
            .execute(&pool)
            .await
            .context("add password_hash column")?;
    }

    let mysql = init_mysql().await;

    Ok(AppState {
        db: pool,
        jwt_secret,
        mysql,
        runtimes: Arc::new(Mutex::new(HashMap::new())),
    })
}

async fn init_mysql() -> Option<MySqlPool> {
    let user = std::env::var("FPANEL_MYSQL_USER").unwrap_or_else(|_| "felix".into());
    let socket = std::env::var("FPANEL_MYSQL_SOCKET").unwrap_or_else(|_| "/tmp/mysql.sock".into());
    let opts = MySqlConnectOptions::new()
        .username(&user)
        .socket(&socket);
    match MySqlPoolOptions::new()
        .max_connections(5)
        .connect_with(opts)
        .await
    {
        Ok(pool) => {
            tracing::info!("Connected to MariaDB via unix socket {socket}");
            Some(pool)
        }
        Err(e) => {
            tracing::warn!("MariaDB unavailable (unix socket {socket}): {e}");
            None
        }
    }
}