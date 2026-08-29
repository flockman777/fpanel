use axum::extract::{Path, Query, State};
use axum::http::{HeaderMap, StatusCode};
use axum::routing::{delete, get};
use axum::{Json, Router};
use serde::{Deserialize, Serialize};

use sqlx::MySqlPool;
use sqlx::Row;

use crate::db::AppState;
use crate::error::{internal_error, ApiError};

#[derive(Debug, Serialize, sqlx::FromRow)]
pub struct Database {
    pub id: i64,
    pub account_id: i64,
    pub name: String,
    pub db_user: String,
    pub status: String,
    pub created_at: String,
    pub bound_users: Vec<BoundUser>,
}

#[derive(Debug, Serialize)]
pub struct BoundUser {
    pub user_id: i64,
    pub username: String,
    pub privileges: String,
}

#[derive(Debug, Serialize, sqlx::FromRow)]
pub struct DatabaseAdmin {
    pub id: i64,
    pub account_id: i64,
    pub username: String,
    pub name: String,
    pub db_user: String,
    pub status: String,
    pub created_at: String,
    pub bound_users: Vec<BoundUser>,
}

#[derive(Debug, Serialize, sqlx::FromRow)]
pub struct DbUser {
    pub id: i64,
    pub account_id: i64,
    pub username: String,
    pub status: String,
    pub created_at: String,
}

#[derive(Debug, Serialize, sqlx::FromRow)]
pub struct DbUserAdmin {
    pub id: i64,
    pub account_id: i64,
    pub username: String,
    pub name: String,
    pub status: String,
    pub created_at: String,
}

#[derive(Debug, Deserialize)]
pub struct CreateDatabase {
    pub name: String,
    pub account_id: Option<i64>,
}

#[derive(Debug, Deserialize)]
pub struct CreateUser {
    pub username: String,
    pub password: String,
    pub account_id: Option<i64>,
}

#[derive(Debug, Deserialize)]
pub struct GrantBody {
    pub db_id: i64,
    pub user_id: i64,
    pub privileges: Option<Vec<String>>,
    pub account_id: Option<i64>,
}

#[derive(Debug, Deserialize)]
pub struct PathQ {
    pub db_id: Option<i64>,
}

pub fn router() -> Router<AppState> {
    Router::new()
        .route("/", get(list_admin).post(create_admin))
        .route("/{id}", delete(drop_admin))
        .route("/db-users", get(users_admin).post(create_user_admin))
        .route("/db-users/{id}", delete(drop_user_admin))
        .route("/db-privileges", get(privileges_admin).post(grant_admin))
        .route("/db-privileges/{id}", delete(revoke_admin))
}

pub fn client_router() -> Router<AppState> {
    Router::new()
        .route("/", get(list_client).post(create_client))
        .route("/{id}", delete(drop_client))
        .route("/db-users", get(users_client).post(create_user_client))
        .route("/db-users/{id}", delete(drop_user_client))
        .route("/db-privileges", get(privileges_client).post(grant_client))
        .route("/db-privileges/{id}", delete(revoke_client))
}

// ---------- clients ----------

async fn list_client(State(state): State<AppState>, headers: HeaderMap) -> Result<Json<Vec<Database>>, ApiError> {
    let (aid, _) = super::domains::bearer_account(&state, &headers).await?;
    Ok(Json(list_dbs(&state, aid).await?))
}

async fn create_client(
    State(state): State<AppState>,
    headers: HeaderMap,
    Json(body): Json<CreateDatabase>,
) -> Result<(StatusCode, Json<Database>), ApiError> {
    let (aid, username) = super::domains::bearer_account(&state, &headers).await?;
    let db = create_db(&state, aid, &username, &body.name).await?;
    Ok((StatusCode::CREATED, Json(db)))
}

async fn drop_client(
    State(state): State<AppState>,
    headers: HeaderMap,
    Path((_sess, id)): Path<(String, i64)>,
) -> Result<StatusCode, ApiError> {
    let (aid, username) = super::domains::bearer_account(&state, &headers).await?;
    drop_db(&state, aid, &username, id).await?;
    Ok(StatusCode::NO_CONTENT)
}

async fn users_client(State(state): State<AppState>, headers: HeaderMap) -> Result<Json<Vec<DbUser>>, ApiError> {
    let (aid, _) = super::domains::bearer_account(&state, &headers).await?;
    Ok(Json(list_users(&state, aid).await?))
}

async fn create_user_client(
    State(state): State<AppState>,
    headers: HeaderMap,
    Json(body): Json<CreateUser>,
) -> Result<(StatusCode, Json<DbUser>), ApiError> {
    let (aid, username) = super::domains::bearer_account(&state, &headers).await?;
    let u = create_user(&state, aid, &username, &body.username, &body.password).await?;
    Ok((StatusCode::CREATED, Json(u)))
}

async fn drop_user_client(
    State(state): State<AppState>,
    headers: HeaderMap,
    Path((_sess, id)): Path<(String, i64)>,
) -> Result<StatusCode, ApiError> {
    let (aid, _) = super::domains::bearer_account(&state, &headers).await?;
    drop_user(&state, aid, id).await?;
    Ok(StatusCode::NO_CONTENT)
}

async fn privileges_client(
    State(state): State<AppState>,
    headers: HeaderMap,
    Query(q): Query<PathQ>,
) -> Result<Json<Vec<Privilege>>, ApiError> {
    let (aid, _) = super::domains::bearer_account(&state, &headers).await?;
    let Some(db_id) = q.db_id else {
        return Err(ApiError::new(StatusCode::BAD_REQUEST, "db_id is required"));
    };
    Ok(Json(list_privileges(&state, aid, db_id).await?))
}

async fn grant_client(
    State(state): State<AppState>,
    headers: HeaderMap,
    Json(body): Json<GrantBody>,
) -> Result<(StatusCode, Json<Privilege>), ApiError> {
    let (aid, _) = super::domains::bearer_account(&state, &headers).await?;
    let p = grant_db(&state, aid, body.db_id, body.user_id, body.privileges).await?;
    Ok((StatusCode::CREATED, Json(p)))
}

async fn revoke_client(
    State(state): State<AppState>,
    headers: HeaderMap,
    Path((_sess, id)): Path<(String, i64)>,
) -> Result<StatusCode, ApiError> {
    let (aid, _) = super::domains::bearer_account(&state, &headers).await?;
    revoke(&state, aid, id).await?;
    Ok(StatusCode::NO_CONTENT)
}

// ---------- admins ----------

#[derive(Debug, Deserialize)]
pub struct AdminPathQ {
    pub account_id: Option<i64>,
    pub db_id: Option<i64>,
}

async fn list_admin(State(state): State<AppState>, Query(q): Query<AdminPathQ>) -> Result<Json<Vec<DatabaseAdmin>>, ApiError> {
    let aid = require_account(q.account_id)?;
    let dbs = list_dbs(&state, aid).await?;
    let username = account_username(&state, aid).await?;
    let out = dbs
        .into_iter()
        .map(|d| DatabaseAdmin {
            username: username.clone(),
            account_id: aid,
            id: d.id,
            name: d.name,
            db_user: d.db_user,
            status: d.status,
            created_at: d.created_at,
            bound_users: d.bound_users,
        })
        .collect();
    Ok(Json(out))
}

async fn create_admin(
    State(state): State<AppState>,
    Json(body): Json<CreateDatabase>,
) -> Result<(StatusCode, Json<DatabaseAdmin>), ApiError> {
    let aid = require_account(body.account_id)?;
    let username = account_username(&state, aid).await?;
    let db = create_db(&state, aid, &username, &body.name).await?;
    Ok((StatusCode::CREATED, Json(to_admin(aid, username, db))))
}

async fn drop_admin(
    State(state): State<AppState>,
    Query(q): Query<AdminPathQ>,
    Path((_sess, id)): Path<(String, i64)>,
) -> Result<StatusCode, ApiError> {
    let aid = require_account(q.account_id)?;
    let username = account_username(&state, aid).await?;
    drop_db(&state, aid, &username, id).await?;
    Ok(StatusCode::NO_CONTENT)
}

async fn users_admin(State(state): State<AppState>, Query(q): Query<AdminPathQ>) -> Result<Json<Vec<DbUserAdmin>>, ApiError> {
    let aid = require_account(q.account_id)?;
    let username = account_username(&state, aid).await?;
    let users = list_users(&state, aid).await?;
    let out = users
        .into_iter()
        .map(|u| DbUserAdmin {
            account_id: aid,
            username: username.clone(),
            id: u.id,
            name: u.username,
            status: u.status,
            created_at: u.created_at,
        })
        .collect();
    Ok(Json(out))
}

async fn create_user_admin(
    State(state): State<AppState>,
    Json(body): Json<CreateUser>,
) -> Result<(StatusCode, Json<DbUserAdmin>), ApiError> {
    let aid = require_account(body.account_id)?;
    let username = account_username(&state, aid).await?;
    let u = create_user(&state, aid, &username, &body.username, &body.password).await?;
    Ok((
        StatusCode::CREATED,
        Json(DbUserAdmin {
            account_id: aid,
            username,
            id: u.id,
            name: u.username,
            status: u.status,
            created_at: u.created_at,
        }),
    ))
}

async fn drop_user_admin(
    State(state): State<AppState>,
    Query(q): Query<AdminPathQ>,
    Path((_sess, id)): Path<(String, i64)>,
) -> Result<StatusCode, ApiError> {
    let aid = require_account(q.account_id)?;
    drop_user(&state, aid, id).await?;
    Ok(StatusCode::NO_CONTENT)
}

async fn privileges_admin(
    State(state): State<AppState>,
    Query(q): Query<AdminPathQ>,
) -> Result<Json<Vec<Privilege>>, ApiError> {
    let aid = require_account(q.account_id)?;
    let Some(db_id) = q.db_id else {
        return Err(ApiError::new(StatusCode::BAD_REQUEST, "db_id is required"));
    };
    Ok(Json(list_privileges(&state, aid, db_id).await?))
}

async fn grant_admin(
    State(state): State<AppState>,
    Json(body): Json<GrantBody>,
) -> Result<(StatusCode, Json<Privilege>), ApiError> {
    let aid = require_account(body.account_id)?;
    let p = grant_db(&state, aid, body.db_id, body.user_id, body.privileges).await?;
    Ok((StatusCode::CREATED, Json(p)))
}

async fn revoke_admin(
    State(state): State<AppState>,
    Query(q): Query<AdminPathQ>,
    Path((_sess, id)): Path<(String, i64)>,
) -> Result<StatusCode, ApiError> {
    let aid = require_account(q.account_id)?;
    revoke(&state, aid, id).await?;
    Ok(StatusCode::NO_CONTENT)
}

// ---------- helpers ----------

#[derive(Debug, Serialize)]
pub struct Privilege {
    pub id: i64,
    pub db_id: i64,
    pub db_name: String,
    pub user_id: i64,
    pub username: String,
    pub privileges: String,
}

fn require_account(aid: Option<i64>) -> Result<i64, ApiError> {
    aid.ok_or_else(|| ApiError::new(StatusCode::BAD_REQUEST, "account_id is required"))
}

async fn account_username(state: &AppState, aid: i64) -> Result<String, ApiError> {
    sqlx::query_scalar::<_, String>("SELECT username FROM accounts WHERE id = ?")
        .bind(aid)
        .fetch_optional(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?
        .ok_or_else(|| ApiError::new(StatusCode::NOT_FOUND, "Account not found"))
}

fn pool(state: &AppState) -> Result<MySqlPool, ApiError> {
    state
        .mysql
        .clone()
        .ok_or_else(|| ApiError::new(StatusCode::SERVICE_UNAVAILABLE, "Database server (MariaDB) is unavailable"))
}

fn to_admin(aid: i64, username: String, d: Database) -> DatabaseAdmin {
    DatabaseAdmin {
        username,
        account_id: aid,
        id: d.id,
        name: d.name,
        db_user: d.db_user,
        status: d.status,
        created_at: d.created_at,
        bound_users: d.bound_users,
    }
}

fn valid_name(s: &str, what: &str) -> Result<String, ApiError> {
    if s.is_empty() || s.len() > 60
        || !s.chars().all(|c| c.is_ascii_alphanumeric() || c == '_')
    {
        return Err(ApiError::new(
            StatusCode::BAD_REQUEST,
            format!("Invalid {what}: use letters, numbers and underscores only (max 60)"),
        ));
    }
    Ok(s.to_string())
}

fn actual_db(username: &str, name: &str) -> String {
    format!("{username}_{name}")
}

fn actual_user(username: &str, uname: &str) -> String {
    format!("{username}_{uname}")
}

async fn bound_users(state: &AppState, db_id: i64) -> Vec<BoundUser> {
    match sqlx::query(
        "SELECT u.id, u.username, p.privileges FROM db_users u \
         JOIN db_privileges p ON p.user_id = u.id WHERE p.db_id = ? ORDER BY u.username",
    )
    .bind(db_id)
    .fetch_all(&state.db)
    .await
    {
        Ok(rows) => rows
            .iter()
            .map(|r| BoundUser {
                user_id: r.get(0),
                username: r.get(1),
                privileges: r.get(2),
            })
            .collect(),
        Err(_) => vec![],
    }
}

async fn list_dbs(state: &AppState, aid: i64) -> Result<Vec<Database>, ApiError> {
    let rows = sqlx::query("SELECT * FROM databases WHERE account_id = ? ORDER BY name")
        .bind(aid)
        .fetch_all(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?;
    let mut out = Vec::with_capacity(rows.len());
    for r in rows {
        let id: i64 = r.get(0);
        out.push(Database {
            id,
            account_id: r.get(1),
            name: r.get(2),
            db_user: r.get(3),
            status: r.get(4),
            created_at: r.get(5),
            bound_users: bound_users(state, id).await,
        });
    }
    Ok(out)
}

async fn list_users(state: &AppState, aid: i64) -> Result<Vec<DbUser>, ApiError> {
    sqlx::query_as::<_, DbUser>("SELECT * FROM db_users WHERE account_id = ? ORDER BY username")
        .bind(aid)
        .fetch_all(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))
}

async fn create_db(state: &AppState, aid: i64, username: &str, name: &str) -> Result<Database, ApiError> {
    let name = valid_name(name, "database name")?;
    let db = pool(state)?;
    let actual = actual_db(username, &name);

    let exists: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM databases WHERE account_id = ? AND name = ?")
        .bind(aid)
        .bind(&name)
        .fetch_one(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?;
    if exists > 0 {
        return Err(ApiError::new(StatusCode::CONFLICT, "Database already exists"));
    }

    sqlx::query(&format!(
        "CREATE DATABASE IF NOT EXISTS `{actual}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    ))
    .execute(&db)
    .await
    .map_err(|e| internal_error(e.into()))?;

    let result = sqlx::query("INSERT INTO databases (account_id, name, db_user) VALUES (?, ?, '')")
        .bind(aid)
        .bind(&name)
        .execute(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?;

    let id = result.last_insert_rowid();
    Ok(Database {
        id,
        account_id: aid,
        name,
        db_user: String::new(),
        status: "active".into(),
        created_at: String::new(),
        bound_users: vec![],
    })
}

async fn drop_db(state: &AppState, aid: i64, username: &str, id: i64) -> Result<(), ApiError> {
    let row = sqlx::query("SELECT name FROM databases WHERE id = ? AND account_id = ?")
        .bind(id)
        .bind(aid)
        .fetch_optional(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?;
    let Some(row) = row else {
        return Err(ApiError::new(StatusCode::NOT_FOUND, "Database not found"));
    };
    let name: String = row.get(0);

    if let Ok(db) = pool(state) {
        let actual = actual_db(username, &name);
        sqlx::query(&format!("DROP DATABASE IF EXISTS `{actual}`"))
            .execute(&db)
            .await
            .map_err(|e| internal_error(e.into()))?;
    }

    sqlx::query("DELETE FROM db_privileges WHERE db_id = ?")
        .bind(id)
        .execute(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?;
    sqlx::query("DELETE FROM databases WHERE id = ?")
        .bind(id)
        .execute(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?;
    Ok(())
}

async fn create_user(state: &AppState, aid: i64, username: &str, uname: &str, password: &str) -> Result<DbUser, ApiError> {
    let uname = valid_name(uname, "database username")?;
    if password.len() < 6 {
        return Err(ApiError::new(StatusCode::BAD_REQUEST, "Password must be at least 6 characters"));
    }
    let db = pool(state)?;
    let actual = actual_user(username, &uname);

    let exists: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM db_users WHERE account_id = ? AND username = ?")
        .bind(aid)
        .bind(&uname)
        .fetch_one(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?;
    if exists > 0 {
        return Err(ApiError::new(StatusCode::CONFLICT, "Database user already exists"));
    }

    sqlx::query(&format!(
        "CREATE USER IF NOT EXISTS `{actual}`@'localhost' IDENTIFIED BY '{}'",
        password.replace('\'', "''")
    ))
    .execute(&db)
    .await
    .map_err(|e| internal_error(e.into()))?;

    let result = sqlx::query("INSERT INTO db_users (account_id, username, password) VALUES (?, ?, ?)")
        .bind(aid)
        .bind(&uname)
        .bind(password)
        .execute(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?;

    let id = result.last_insert_rowid();
    Ok(DbUser {
        id,
        account_id: aid,
        username: uname,
        status: "active".into(),
        created_at: String::new(),
    })
}

async fn drop_user(state: &AppState, aid: i64, id: i64) -> Result<(), ApiError> {
    let row = sqlx::query("SELECT username FROM db_users WHERE id = ? AND account_id = ?")
        .bind(id)
        .bind(aid)
        .fetch_optional(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?;
    let Some(row) = row else {
        return Err(ApiError::new(StatusCode::NOT_FOUND, "Database user not found"));
    };
    let uname: String = row.get(0);

    let acc: String = sqlx::query_scalar(
        "SELECT username FROM accounts WHERE id = ?",
    )
    .bind(aid)
    .fetch_one(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?;

    if let Ok(db) = pool(state) {
        let actual = actual_user(&acc, &uname);
        sqlx::query(&format!("DROP USER IF EXISTS `{actual}`@'localhost'"))
            .execute(&db)
            .await
            .map_err(|e| internal_error(e.into()))?;
    }

    sqlx::query("DELETE FROM db_privileges WHERE user_id = ?")
        .bind(id)
        .execute(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?;
    sqlx::query("DELETE FROM db_users WHERE id = ?")
        .bind(id)
        .execute(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?;
    Ok(())
}

async fn list_privileges(state: &AppState, aid: i64, db_id: i64) -> Result<Vec<Privilege>, ApiError> {
    let db_owns: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM databases WHERE id = ? AND account_id = ?")
        .bind(db_id)
        .bind(aid)
        .fetch_one(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?;
    if db_owns == 0 {
        return Err(ApiError::new(StatusCode::NOT_FOUND, "Database not found for this account"));
    }

    let rows = sqlx::query(
        "SELECT p.id, p.db_id, d.name, p.user_id, u.username, p.privileges \
         FROM db_privileges p \
         JOIN databases d ON d.id = p.db_id \
         JOIN db_users u ON u.id = p.user_id \
         WHERE p.db_id = ? AND d.account_id = ? ORDER BY u.username",
    )
    .bind(db_id)
    .bind(aid)
    .fetch_all(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?;

    let mut out = Vec::with_capacity(rows.len());
    for r in rows {
        out.push(Privilege {
            id: r.get(0),
            db_id: r.get(1),
            db_name: r.get(2),
            user_id: r.get(3),
            username: r.get(4),
            privileges: r.get(5),
        });
    }
    Ok(out)
}

async fn grant_db(
    state: &AppState,
    aid: i64,
    db_id: i64,
    user_id: i64,
    privileges: Option<Vec<String>>,
) -> Result<Privilege, ApiError> {
    let db = pool(state)?;

    let info: Option<(String, String, String, i64)> = sqlx::query_as(
        "SELECT d.name, u.username, a.username, d.account_id FROM databases d \
         JOIN db_users u ON u.id = ? \
         JOIN accounts a ON a.id = d.account_id \
         WHERE d.id = ?",
    )
    .bind(user_id)
    .bind(db_id)
    .fetch_optional(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?;
    let Some((db_name, uname, acc, d_acc)) = info else {
        return Err(ApiError::new(StatusCode::NOT_FOUND, "Database or user not found"));
    };
    if d_acc != aid {
        return Err(ApiError::new(StatusCode::FORBIDDEN, "Database does not belong to this account"));
    }

    let used = privileges.unwrap_or_else(|| vec!["ALL PRIVILEGES".into()]);
    let mut clean: Vec<String> = vec![];
    for p in &used {
        let p = p.trim().to_uppercase();
        if p.is_empty() || p == "ALL PRIVILEGES" || p == "ALL" {
            clean = vec!["ALL PRIVILEGES".into()];
            break;
        }
        const ALLOWED: [&str; 12] = [
            "SELECT", "INSERT", "UPDATE", "DELETE", "CREATE", "DROP",
            "INDEX", "ALTER", "REFERENCES", "CREATE TEMPORARY TABLES",
            "LOCK TABLES", "EXECUTE",
        ];
        if ALLOWED.contains(&p.as_str()) && !clean.contains(&p) {
            clean.push(p);
        }
    }
    if clean.is_empty() {
        return Err(ApiError::new(StatusCode::BAD_REQUEST, "No valid privileges provided"));
    }

    let actual_db = actual_db(&acc, &db_name);
    let actual_user = actual_user(&acc, &uname);
    let grant = format!(
        "GRANT {} ON `{actual_db}`.* TO `{actual_user}`@'localhost'",
        clean.join(", ")
    );
    sqlx::query(&grant)
        .execute(&db)
        .await
        .map_err(|e| internal_error(e.into()))?;
    sqlx::query("FLUSH PRIVILEGES").execute(&db).await.map_err(|e| internal_error(e.into()))?;

    let joined = clean.join(", ");
    sqlx::query(
        "INSERT INTO db_privileges (db_id, user_id, privileges) VALUES (?, ?, ?) \
         ON CONFLICT(db_id, user_id) DO UPDATE SET privileges = excluded.privileges",
    )
    .bind(db_id)
    .bind(user_id)
    .bind(&joined)
    .execute(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?;

    let row = sqlx::query(
        "SELECT p.id, p.db_id, d.name, p.user_id, u.username, p.privileges \
         FROM db_privileges p \
         JOIN databases d ON d.id = p.db_id \
         JOIN db_users u ON u.id = p.user_id \
         WHERE p.db_id = ? AND p.user_id = ?",
    )
    .bind(db_id)
    .bind(user_id)
    .fetch_one(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?;

    Ok(Privilege {
        id: row.get(0),
        db_id: row.get(1),
        db_name: row.get(2),
        user_id: row.get(3),
        username: row.get(4),
        privileges: row.get(5),
    })
}

async fn revoke(state: &AppState, aid: i64, priv_id: i64) -> Result<(), ApiError> {
    let info: Option<(String, String, String, i64)> = sqlx::query_as(
        "SELECT u.username, a.username, d.name, d.account_id FROM db_privileges p \
         JOIN db_users u ON u.id = p.user_id \
         JOIN databases d ON d.id = p.db_id \
         JOIN accounts a ON a.id = d.account_id \
         WHERE p.id = ?",
    )
    .bind(priv_id)
    .fetch_optional(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?;
    let Some((uname, acc, db_name, d_acc)) = info else {
        return Err(ApiError::new(StatusCode::NOT_FOUND, "Grant not found"));
    };
    if d_acc != aid {
        return Err(ApiError::new(StatusCode::FORBIDDEN, "Grant does not belong to this account"));
    }

    if let Ok(db) = pool(state) {
        let actual_user = actual_user(&acc, &uname);
        let actual_db = actual_db(&acc, &db_name);
        sqlx::query(&format!(
            "REVOKE ALL PRIVILEGES ON `{actual_db}`.* FROM `{actual_user}`@'localhost'"
        ))
        .execute(&db)
        .await
        .map_err(|e| internal_error(e.into()))?;
        sqlx::query("FLUSH PRIVILEGES").execute(&db).await.map_err(|e| internal_error(e.into()))?;
    }

    sqlx::query("DELETE FROM db_privileges WHERE id = ?")
        .bind(priv_id)
        .execute(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?;
    Ok(())
}