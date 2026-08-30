use axum::extract::{Path, State};
use axum::http::StatusCode;
use axum::routing::get;
use serde::{Deserialize, Serialize};
use sqlx::FromRow;

use axum::Json;
use axum::Router;

use crate::db::AppState;
use crate::error::{internal_error, ApiError};
use crate::provision;

#[derive(Debug, Serialize, FromRow)]
pub struct Account {
    pub id: i64,
    pub username: String,
    pub email: String,
    pub package_id: i64,
    pub status: String,
    pub name: Option<String>,
}

#[derive(Debug, Deserialize)]
pub struct CreateAccount {
    pub username: String,
    pub email: String,
    pub password: Option<String>,
    pub package_id: i64,
    pub name: Option<String>,
    #[serde(default)]
    pub domain: Option<String>,
}

#[derive(Debug, Deserialize)]
pub struct UpdateAccount {
    pub email: Option<String>,
    pub password: Option<String>,
    pub package_id: Option<i64>,
    pub status: Option<String>,
    pub name: Option<String>,
}

pub fn router() -> Router<AppState> {
    Router::new()
        .route("/", get(list).post(create))
        .route("/{id}", get(get_one).put(update).delete(remove))
}

async fn list(State(state): State<AppState>) -> Result<Json<Vec<Account>>, ApiError> {
    let accounts = sqlx::query_as::<_, Account>("SELECT * FROM accounts ORDER BY id")
        .fetch_all(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?;
    Ok(Json(accounts))
}

async fn get_one(
    State(state): State<AppState>,
    Path((_sess, id)): Path<(String, i64)>,
) -> Result<Json<Account>, ApiError> {
    let account = sqlx::query_as::<_, Account>("SELECT * FROM accounts WHERE id = ?")
        .bind(id)
        .fetch_optional(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?
        .ok_or_else(|| ApiError::new(StatusCode::NOT_FOUND, "Account not found"))?;
    Ok(Json(account))
}

async fn create(
    State(state): State<AppState>,
    Json(input): Json<CreateAccount>,
) -> Result<(StatusCode, Json<Account>), ApiError> {
    if input.username.trim().is_empty() || !input.email.contains('@') {
        return Err(ApiError::new(
            StatusCode::BAD_REQUEST,
            "Invalid username or email",
        ));
    }

    let password_hash = match input.password.as_deref() {
        Some(p) if p.len() >= 6 => Some(
            crate::auth::hash_password(p).map_err(|e| internal_error(e.into()))?,
        ),
        _ => None,
    };

    let result = sqlx::query(
        "INSERT INTO accounts (username, email, password_hash, package_id, name) VALUES (?, ?, ?, ?, ?)",
    )
    .bind(input.username.trim())
    .bind(input.email.trim())
    .bind(password_hash)
    .bind(input.package_id)
    .bind(input.name)
    .execute(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?;

    let account = sqlx::query_as::<_, Account>("SELECT * FROM accounts WHERE id = ?")
        .bind(result.last_insert_rowid())
        .fetch_one(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?;

    // cPanel-style: creating a hosting account can provision its main domain
    // right away, seeding the DNS zone automatically.
    if let Some(domain) = input.domain.map(|d| d.trim().to_lowercase()).filter(|d| !d.is_empty()) {
        if !crate::routes::domains::valid_domain(&domain) {
            return Err(ApiError::new(StatusCode::BAD_REQUEST, "Invalid domain name"));
        }
        let clash = sqlx::query_scalar::<_, i64>("SELECT COUNT(*) FROM domains WHERE name = ?")
            .bind(&domain)
            .fetch_one(&state.db)
            .await
            .map_err(|e| internal_error(e.into()))?;
        if clash > 0 {
            return Err(ApiError::new(
                StatusCode::CONFLICT,
                format!("Domain {domain} is already assigned to another account"),
            ));
        }
        let insert = sqlx::query("INSERT INTO domains (account_id, name, kind) VALUES (?, ?, 'main')")
            .bind(account.id)
            .bind(&domain)
            .execute(&state.db)
            .await
            .map_err(|e| internal_error(e.into()))?;
        provision::write_vhost(&domain, &account.username, "main");
        crate::routes::dns::seed_domain_dns(&state, insert.last_insert_rowid()).await?;
    }

    trace::log_provision(&format!("create account {}", account.username));

    Ok((StatusCode::CREATED, Json(account)))
}

async fn update(
    State(state): State<AppState>,
    Path((_sess, id)): Path<(String, i64)>,
    Json(input): Json<UpdateAccount>,
) -> Result<Json<Account>, ApiError> {
    let current = sqlx::query_as::<_, Account>("SELECT * FROM accounts WHERE id = ?")
        .bind(id)
        .fetch_optional(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?
        .ok_or_else(|| ApiError::new(StatusCode::NOT_FOUND, "Account not found"))?;

    let email = input.email.unwrap_or(current.email);
    let package_id = input.package_id.unwrap_or(current.package_id);
    let status = input.status.unwrap_or(current.status);
    let name = input.name;

    let password_hash = match input.password.as_deref() {
        Some(p) if p.len() >= 6 => Some(
            crate::auth::hash_password(p).map_err(|e| internal_error(e.into()))?,
        ),
        _ => None,
    };

    if let Some(hash) = password_hash {
        sqlx::query("UPDATE accounts SET email = ?, package_id = ?, status = ?, name = ?, password_hash = ? WHERE id = ?")
            .bind(&email)
            .bind(package_id)
            .bind(&status)
            .bind(&name)
            .bind(hash)
            .bind(id)
            .execute(&state.db)
            .await
            .map_err(|e| internal_error(e.into()))?;
    } else {
        sqlx::query("UPDATE accounts SET email = ?, package_id = ?, status = ?, name = ? WHERE id = ?")
            .bind(&email)
            .bind(package_id)
            .bind(&status)
            .bind(&name)
            .bind(id)
            .execute(&state.db)
            .await
            .map_err(|e| internal_error(e.into()))?;
    }

    let account = sqlx::query_as::<_, Account>("SELECT * FROM accounts WHERE id = ?")
        .bind(id)
        .fetch_one(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?;
    Ok(Json(account))
}

async fn remove(
    State(state): State<AppState>,
    Path((_sess, id)): Path<(String, i64)>,
) -> Result<StatusCode, ApiError> {
    let username = sqlx::query_scalar::<_, String>("SELECT username FROM accounts WHERE id = ?")
        .bind(id)
        .fetch_optional(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?
        .ok_or_else(|| ApiError::new(StatusCode::NOT_FOUND, "Account not found"))?;

    // Collect this account's domains so we can clean up provisioning afterwards.
    let domains: Vec<(i64, String, String)> =
        sqlx::query_as("SELECT id, name, kind FROM domains WHERE account_id = ?")
            .bind(id)
            .fetch_all(&state.db)
            .await
            .map_err(|e| internal_error(e.into()))?;

    let mut tx = state
        .db
        .begin()
        .await
        .map_err(|e| internal_error(e.into()))?;

    // db_privileges links databases <-> db_users (no account_id column), so it
    // must go first to satisfy the FK from databases/db_users (and vice versa).
    sqlx::query(
        "DELETE FROM db_privileges \
         WHERE user_id IN (SELECT id FROM db_users WHERE account_id = ?) \
            OR db_id IN (SELECT id FROM databases WHERE account_id = ?)",
    )
    .bind(id)
    .bind(id)
    .execute(&mut *tx)
    .await
    .map_err(|e| internal_error(e.into()))?;

    // Delete child rows first (FK-safe order, leaves -> parents -> account).
    for table in [
        // reference domains / databases / db_users
        "dns_records",
        "email_accounts",
        "email_forwarders",
        "email_autoresponders",
        "email_defaults",
        "redirects",
        "ssl_certs",
        "php_settings",
        "installed_apps",
        "ip_blocker",
        "hotlink",
        "waf_rules",
        "run_apps",
        // reference accounts directly
        "databases",
        "db_users",
        "ssh_access",
        "cron_jobs",
    ] {
        sqlx::query(&format!("DELETE FROM {table} WHERE account_id = ?"))
            .bind(id)
            .execute(&mut *tx)
            .await
            .map_err(|e| internal_error(e.into()))?;
    }
    sqlx::query("DELETE FROM domains WHERE account_id = ?")
        .bind(id)
        .execute(&mut *tx)
        .await
        .map_err(|e| internal_error(e.into()))?;
    sqlx::query("DELETE FROM accounts WHERE id = ?")
        .bind(id)
        .execute(&mut *tx)
        .await
        .map_err(|e| internal_error(e.into()))?;

    tx.commit().await.map_err(|e| internal_error(e.into()))?;

    // Cleanup provisioning artifacts (vhosts, mail, ssl, dns zones, ssh, home).
    for (domain_id, name, kind) in &domains {
        provision::remove_vhost(name);
        provision::remove_mail(name);
        provision::remove_ssl(name);
        provision::remove_redirects(name);
        provision::remove_runtime(name);
        provision::remove_php(name);
        provision::remove_security(name);
        crate::routes::dns::cleanup_domain_dns(&state, *domain_id, name, kind).await?;
    }
    provision::remove_ssh(&username);
    let home = provision::account_home(&username);
    if home.exists() {
        let _ = std::fs::remove_dir_all(&home);
    }

    trace::log_provision(&format!("delete account {id} ({username})"));
    Ok(StatusCode::NO_CONTENT)
}

mod trace {
    pub fn log_provision(msg: &str) {
        tracing::info!("[provision-stub] {msg}");
    }
}