use axum::extract::{Path, Query, State};
use axum::http::{HeaderMap, StatusCode};
use axum::routing::{delete, get, post};
use axum::{Json, Router};
use serde::{Deserialize, Serialize};
use sqlx::Row;

use crate::db::AppState;
use crate::error::{internal_error, ApiError};
use crate::provision;

#[derive(Debug, Serialize)]
pub struct EmailAccount {
    pub id: i64,
    pub account_id: i64,
    pub domain_id: i64,
    pub domain: String,
    pub local: String,
    pub address: String,
    pub forward_to: Option<String>,
    pub quota_mb: i64,
    pub status: String,
}

#[derive(Debug, Serialize)]
pub struct EmailAccountAdmin {
    pub id: i64,
    pub account_id: i64,
    pub username: String,
    pub domain_id: i64,
    pub domain: String,
    pub local: String,
    pub address: String,
    pub forward_to: Option<String>,
    pub quota_mb: i64,
    pub status: String,
}

#[derive(Debug, Serialize)]
pub struct Forwarder {
    pub id: i64,
    pub account_id: i64,
    pub domain_id: i64,
    pub domain: String,
    pub from: String,
    pub to: String,
    pub status: String,
}

#[derive(Debug, Serialize)]
pub struct ForwarderAdmin {
    pub id: i64,
    pub account_id: i64,
    pub username: String,
    pub domain_id: i64,
    pub domain: String,
    pub from: String,
    pub to: String,
    pub status: String,
}

#[derive(Debug, Serialize)]
pub struct Autoresponder {
    pub id: i64,
    pub account_id: i64,
    pub domain_id: i64,
    pub domain: String,
    pub local: String,
    pub address: String,
    pub subject: String,
    pub body: String,
    pub start_date: Option<String>,
    pub end_date: Option<String>,
    pub status: String,
}

#[derive(Debug, Serialize)]
pub struct AutoresponderAdmin {
    pub id: i64,
    pub account_id: i64,
    pub username: String,
    pub domain_id: i64,
    pub domain: String,
    pub local: String,
    pub address: String,
    pub subject: String,
    pub body: String,
    pub start_date: Option<String>,
    pub end_date: Option<String>,
    pub status: String,
}

#[derive(Debug, Serialize)]
pub struct DefaultAddr {
    pub id: i64,
    pub account_id: i64,
    pub domain_id: i64,
    pub domain: String,
    pub action: String,
    pub forward_to: Option<String>,
}

#[derive(Debug, Serialize)]
pub struct DefaultAddrAdmin {
    pub id: i64,
    pub account_id: i64,
    pub username: String,
    pub domain_id: i64,
    pub domain: String,
    pub action: String,
    pub forward_to: Option<String>,
}

#[derive(Debug, Deserialize)]
pub struct CreateAccount {
    pub domain_id: i64,
    pub local: String,
    pub password: String,
    pub quota_mb: Option<i64>,
    pub forward_to: Option<String>,
    pub account_id: Option<i64>,
}

#[derive(Debug, Deserialize)]
pub struct ChangePassword {
    pub password: String,
}

#[derive(Debug, Deserialize)]
pub struct CreateForwarder {
    pub domain_id: i64,
    pub from: String,
    pub to: String,
    pub account_id: Option<i64>,
}

#[derive(Debug, Deserialize)]
pub struct CreateAutoresponder {
    pub domain_id: i64,
    pub local: String,
    pub subject: String,
    pub body: Option<String>,
    pub start_date: Option<String>,
    pub end_date: Option<String>,
    pub account_id: Option<i64>,
}

#[derive(Debug, Deserialize)]
pub struct DefaultBody {
    pub domain_id: i64,
    pub action: String,
    pub forward_to: Option<String>,
    pub account_id: Option<i64>,
}

#[derive(Debug, Deserialize)]
pub struct AdminPathQ {
    pub account_id: Option<i64>,
}

pub fn router() -> Router<AppState> {
    Router::new()
        .route("/accounts", get(accounts_admin).post(create_account_admin))
        .route("/accounts/{id}", delete(delete_account_admin))
        .route("/accounts/{id}/password", post(set_password_admin))
        .route("/forwarders", get(forwarders_admin).post(create_forwarder_admin))
        .route("/forwarders/{id}", delete(delete_forwarder_admin))
        .route(
            "/autoresponders",
            get(autoresponders_admin).post(create_autoresponder_admin),
        )
        .route("/autoresponders/{id}", delete(delete_autoresponder_admin))
        .route("/default", get(default_admin).post(set_default_admin))
}

pub fn client_router() -> Router<AppState> {
    Router::new()
        .route("/accounts", get(accounts_client).post(create_account_client))
        .route("/accounts/{id}", delete(delete_account_client))
        .route("/accounts/{id}/password", post(set_password_client))
        .route("/forwarders", get(forwarders_client).post(create_forwarder_client))
        .route("/forwarders/{id}", delete(delete_forwarder_client))
        .route(
            "/autoresponders",
            get(autoresponders_client).post(create_autoresponder_client),
        )
        .route("/autoresponders/{id}", delete(delete_autoresponder_client))
        .route("/default", get(default_client).post(set_default_client))
}

// ---------- clients ----------

async fn accounts_client(
    State(state): State<AppState>,
    headers: HeaderMap,
) -> Result<Json<Vec<EmailAccount>>, ApiError> {
    let (aid, _) = super::domains::bearer_account(&state, &headers).await?;
    Ok(Json(list_accounts(&state, aid).await?))
}

async fn create_account_client(
    State(state): State<AppState>,
    headers: HeaderMap,
    Json(body): Json<CreateAccount>,
) -> Result<(StatusCode, Json<EmailAccount>), ApiError> {
    let (aid, _) = super::domains::bearer_account(&state, &headers).await?;
    let acc = create_account(&state, aid, &body).await?;
    Ok((StatusCode::CREATED, Json(acc)))
}

async fn delete_account_client(
    State(state): State<AppState>,
    headers: HeaderMap,
    Path((_sess, id)): Path<(String, i64)>,
) -> Result<StatusCode, ApiError> {
    let (aid, _) = super::domains::bearer_account(&state, &headers).await?;
    delete_account(&state, aid, id).await?;
    Ok(StatusCode::NO_CONTENT)
}

async fn set_password_client(
    State(state): State<AppState>,
    headers: HeaderMap,
    Path((_sess, id)): Path<(String, i64)>,
    Json(body): Json<ChangePassword>,
) -> Result<StatusCode, ApiError> {
    let (aid, _) = super::domains::bearer_account(&state, &headers).await?;
    set_password(&state, aid, id, &body.password).await?;
    Ok(StatusCode::NO_CONTENT)
}

async fn forwarders_client(
    State(state): State<AppState>,
    headers: HeaderMap,
) -> Result<Json<Vec<Forwarder>>, ApiError> {
    let (aid, _) = super::domains::bearer_account(&state, &headers).await?;
    Ok(Json(list_forwarders(&state, aid).await?))
}

async fn create_forwarder_client(
    State(state): State<AppState>,
    headers: HeaderMap,
    Json(body): Json<CreateForwarder>,
) -> Result<(StatusCode, Json<Forwarder>), ApiError> {
    let (aid, _) = super::domains::bearer_account(&state, &headers).await?;
    let f = create_forwarder(&state, aid, &body).await?;
    Ok((StatusCode::CREATED, Json(f)))
}

async fn delete_forwarder_client(
    State(state): State<AppState>,
    headers: HeaderMap,
    Path((_sess, id)): Path<(String, i64)>,
) -> Result<StatusCode, ApiError> {
    let (aid, _) = super::domains::bearer_account(&state, &headers).await?;
    delete_forwarder(&state, aid, id).await?;
    Ok(StatusCode::NO_CONTENT)
}

async fn autoresponders_client(
    State(state): State<AppState>,
    headers: HeaderMap,
) -> Result<Json<Vec<Autoresponder>>, ApiError> {
    let (aid, _) = super::domains::bearer_account(&state, &headers).await?;
    Ok(Json(list_autoresponders(&state, aid).await?))
}

async fn create_autoresponder_client(
    State(state): State<AppState>,
    headers: HeaderMap,
    Json(body): Json<CreateAutoresponder>,
) -> Result<(StatusCode, Json<Autoresponder>), ApiError> {
    let (aid, _) = super::domains::bearer_account(&state, &headers).await?;
    let a = create_autoresponder(&state, aid, &body).await?;
    Ok((StatusCode::CREATED, Json(a)))
}

async fn delete_autoresponder_client(
    State(state): State<AppState>,
    headers: HeaderMap,
    Path((_sess, id)): Path<(String, i64)>,
) -> Result<StatusCode, ApiError> {
    let (aid, _) = super::domains::bearer_account(&state, &headers).await?;
    delete_autoresponder(&state, aid, id).await?;
    Ok(StatusCode::NO_CONTENT)
}

async fn default_client(
    State(state): State<AppState>,
    headers: HeaderMap,
) -> Result<Json<Vec<DefaultAddr>>, ApiError> {
    let (aid, _) = super::domains::bearer_account(&state, &headers).await?;
    Ok(Json(list_defaults(&state, aid).await?))
}

async fn set_default_client(
    State(state): State<AppState>,
    headers: HeaderMap,
    Json(body): Json<DefaultBody>,
) -> Result<(StatusCode, Json<DefaultAddr>), ApiError> {
    let (aid, _) = super::domains::bearer_account(&state, &headers).await?;
    let d = set_default(&state, aid, &body).await?;
    Ok((StatusCode::CREATED, Json(d)))
}

// ---------- admins ----------

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

async fn accounts_admin(
    State(state): State<AppState>,
    Query(q): Query<AdminPathQ>,
) -> Result<Json<Vec<EmailAccountAdmin>>, ApiError> {
    let aid = require_account(q.account_id)?;
    let username = account_username(&state, aid).await?;
    let accs = list_accounts(&state, aid).await?;
    Ok(Json(
        accs.into_iter()
            .map(|a| EmailAccountAdmin {
                id: a.id,
                account_id: aid,
                username: username.clone(),
                domain_id: a.domain_id,
                domain: a.domain,
                local: a.local,
                address: a.address,
                forward_to: a.forward_to,
                quota_mb: a.quota_mb,
                status: a.status,
            })
            .collect(),
    ))
}

async fn create_account_admin(
    State(state): State<AppState>,
    Json(body): Json<CreateAccount>,
) -> Result<(StatusCode, Json<EmailAccountAdmin>), ApiError> {
    let aid = require_account(body.account_id)?;
    let username = account_username(&state, aid).await?;
    let acc = create_account(&state, aid, &body).await?;
    Ok((
        StatusCode::CREATED,
        Json(EmailAccountAdmin {
            id: acc.id,
            account_id: aid,
            username,
            domain_id: acc.domain_id,
            domain: acc.domain,
            local: acc.local,
            address: acc.address,
            forward_to: acc.forward_to,
            quota_mb: acc.quota_mb,
            status: acc.status,
        }),
    ))
}

async fn delete_account_admin(
    State(state): State<AppState>,
    Query(q): Query<AdminPathQ>,
    Path((_sess, id)): Path<(String, i64)>,
) -> Result<StatusCode, ApiError> {
    let aid = require_account(q.account_id)?;
    delete_account(&state, aid, id).await?;
    Ok(StatusCode::NO_CONTENT)
}

async fn set_password_admin(
    State(state): State<AppState>,
    Query(q): Query<AdminPathQ>,
    Path((_sess, id)): Path<(String, i64)>,
    Json(body): Json<ChangePassword>,
) -> Result<StatusCode, ApiError> {
    let aid = require_account(q.account_id)?;
    set_password(&state, aid, id, &body.password).await?;
    Ok(StatusCode::NO_CONTENT)
}

async fn forwarders_admin(
    State(state): State<AppState>,
    Query(q): Query<AdminPathQ>,
) -> Result<Json<Vec<ForwarderAdmin>>, ApiError> {
    let aid = require_account(q.account_id)?;
    let username = account_username(&state, aid).await?;
    let fs = list_forwarders(&state, aid).await?;
    Ok(Json(
        fs.into_iter()
            .map(|f| ForwarderAdmin {
                id: f.id,
                account_id: aid,
                username: username.clone(),
                domain_id: f.domain_id,
                domain: f.domain,
                from: f.from,
                to: f.to,
                status: f.status,
            })
            .collect(),
    ))
}

async fn create_forwarder_admin(
    State(state): State<AppState>,
    Json(body): Json<CreateForwarder>,
) -> Result<(StatusCode, Json<ForwarderAdmin>), ApiError> {
    let aid = require_account(body.account_id)?;
    let username = account_username(&state, aid).await?;
    let f = create_forwarder(&state, aid, &body).await?;
    Ok((
        StatusCode::CREATED,
        Json(ForwarderAdmin {
            id: f.id,
            account_id: aid,
            username,
            domain_id: f.domain_id,
            domain: f.domain,
            from: f.from,
            to: f.to,
            status: f.status,
        }),
    ))
}

async fn delete_forwarder_admin(
    State(state): State<AppState>,
    Query(q): Query<AdminPathQ>,
    Path((_sess, id)): Path<(String, i64)>,
) -> Result<StatusCode, ApiError> {
    let aid = require_account(q.account_id)?;
    delete_forwarder(&state, aid, id).await?;
    Ok(StatusCode::NO_CONTENT)
}

async fn autoresponders_admin(
    State(state): State<AppState>,
    Query(q): Query<AdminPathQ>,
) -> Result<Json<Vec<AutoresponderAdmin>>, ApiError> {
    let aid = require_account(q.account_id)?;
    let username = account_username(&state, aid).await?;
    let as_ = list_autoresponders(&state, aid).await?;
    Ok(Json(
        as_.into_iter()
            .map(|a| AutoresponderAdmin {
                id: a.id,
                account_id: aid,
                username: username.clone(),
                domain_id: a.domain_id,
                domain: a.domain,
                local: a.local,
                address: a.address,
                subject: a.subject,
                body: a.body,
                start_date: a.start_date,
                end_date: a.end_date,
                status: a.status,
            })
            .collect(),
    ))
}

async fn create_autoresponder_admin(
    State(state): State<AppState>,
    Json(body): Json<CreateAutoresponder>,
) -> Result<(StatusCode, Json<AutoresponderAdmin>), ApiError> {
    let aid = require_account(body.account_id)?;
    let username = account_username(&state, aid).await?;
    let a = create_autoresponder(&state, aid, &body).await?;
    Ok((
        StatusCode::CREATED,
        Json(AutoresponderAdmin {
            id: a.id,
            account_id: aid,
            username,
            domain_id: a.domain_id,
            domain: a.domain,
            local: a.local,
            address: a.address,
            subject: a.subject,
            body: a.body,
            start_date: a.start_date,
            end_date: a.end_date,
            status: a.status,
        }),
    ))
}

async fn delete_autoresponder_admin(
    State(state): State<AppState>,
    Query(q): Query<AdminPathQ>,
    Path((_sess, id)): Path<(String, i64)>,
) -> Result<StatusCode, ApiError> {
    let aid = require_account(q.account_id)?;
    delete_autoresponder(&state, aid, id).await?;
    Ok(StatusCode::NO_CONTENT)
}

async fn default_admin(
    State(state): State<AppState>,
    Query(q): Query<AdminPathQ>,
) -> Result<Json<Vec<DefaultAddrAdmin>>, ApiError> {
    let aid = require_account(q.account_id)?;
    let username = account_username(&state, aid).await?;
    let ds = list_defaults(&state, aid).await?;
    Ok(Json(
        ds.into_iter()
            .map(|d| DefaultAddrAdmin {
                id: d.id,
                account_id: aid,
                username: username.clone(),
                domain_id: d.domain_id,
                domain: d.domain,
                action: d.action,
                forward_to: d.forward_to,
            })
            .collect(),
    ))
}

async fn set_default_admin(
    State(state): State<AppState>,
    Json(body): Json<DefaultBody>,
) -> Result<(StatusCode, Json<DefaultAddrAdmin>), ApiError> {
    let aid = require_account(body.account_id)?;
    let username = account_username(&state, aid).await?;
    let d = set_default(&state, aid, &body).await?;
    Ok((
        StatusCode::CREATED,
        Json(DefaultAddrAdmin {
            id: d.id,
            account_id: aid,
            username,
            domain_id: d.domain_id,
            domain: d.domain,
            action: d.action,
            forward_to: d.forward_to,
        }),
    ))
}

// ---------- helpers ----------

async fn list_accounts(state: &AppState, aid: i64) -> Result<Vec<EmailAccount>, ApiError> {
    let rows = sqlx::query(
        "SELECT a.id, a.account_id, a.domain_id, d.name, a.local, a.forward_to, a.quota_mb, a.status \
         FROM email_accounts a JOIN domains d ON d.id = a.domain_id \
         WHERE a.account_id = ? ORDER BY a.local",
    )
    .bind(aid)
    .fetch_all(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?;
    let mut out = Vec::with_capacity(rows.len());
    for r in rows {
        let local: String = r.get(4);
        let domain: String = r.get(3);
        out.push(EmailAccount {
            id: r.get(0),
            account_id: r.get(1),
            domain_id: r.get(2),
            domain: domain.clone(),
            address: format!("{local}@{domain}"),
            local,
            forward_to: r.get(5),
            quota_mb: r.get(6),
            status: r.get(7),
        });
    }
    Ok(out)
}

async fn create_account(
    state: &AppState,
    aid: i64,
    body: &CreateAccount,
) -> Result<EmailAccount, ApiError> {
    let local = valid_local(&body.local)?;
    let domain = domain_owned(state, aid, body.domain_id).await?;
    if body.password.len() < 6 {
        return Err(ApiError::new(
            StatusCode::BAD_REQUEST,
            "Password must be at least 6 characters",
        ));
    }

    let exists: i64 =
        sqlx::query_scalar("SELECT COUNT(*) FROM email_accounts WHERE domain_id = ? AND local = ?")
            .bind(body.domain_id)
            .bind(&local)
            .fetch_one(&state.db)
            .await
            .map_err(|e| internal_error(e.into()))?;
    if exists > 0 {
        return Err(ApiError::new(
            StatusCode::CONFLICT,
            "Email address already exists",
        ));
    }

    let hash = crate::auth::hash_password(&body.password).map_err(|e| internal_error(e))?;
    let quota = body.quota_mb.unwrap_or(256);
    let quota = if quota > 0 { quota } else { 256 };
    let result = sqlx::query(
        "INSERT INTO email_accounts (account_id, domain_id, local, password_hash, forward_to, quota_mb) \
         VALUES (?, ?, ?, ?, ?, ?)",
    )
    .bind(aid)
    .bind(body.domain_id)
    .bind(&local)
    .bind(&hash)
    .bind(&body.forward_to)
    .bind(quota)
    .execute(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?;

    sync_mail(state, aid, body.domain_id).await;

    let address = format!("{local}@{domain}");
    Ok(EmailAccount {
        id: result.last_insert_rowid(),
        account_id: aid,
        domain_id: body.domain_id,
        domain,
        address,
        local,
        forward_to: body.forward_to.clone(),
        quota_mb: quota,
        status: "active".into(),
    })
}

async fn delete_account(state: &AppState, aid: i64, id: i64) -> Result<(), ApiError> {
    let row = sqlx::query(
        "SELECT domain_id, local FROM email_accounts WHERE id = ? AND account_id = ?",
    )
    .bind(id)
    .bind(aid)
    .fetch_optional(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?;
    let Some(row) = row else {
        return Err(ApiError::new(StatusCode::NOT_FOUND, "Email account not found"));
    };
    let domain_id: i64 = row.get(0);
    let local: String = row.get(1);

    sqlx::query(
        "DELETE FROM email_autoresponders WHERE domain_id = ? AND local = ?",
    )
    .bind(domain_id)
    .bind(&local)
    .execute(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?;
    sqlx::query("DELETE FROM email_accounts WHERE id = ?")
        .bind(id)
        .execute(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?;

    sync_mail(state, aid, domain_id).await;
    Ok(())
}

async fn set_password(state: &AppState, aid: i64, id: i64, password: &str) -> Result<(), ApiError> {
    if password.len() < 6 {
        return Err(ApiError::new(
            StatusCode::BAD_REQUEST,
            "Password must be at least 6 characters",
        ));
    }
    let domain_id: Option<i64> =
        sqlx::query_scalar("SELECT domain_id FROM email_accounts WHERE id = ? AND account_id = ?")
            .bind(id)
            .bind(aid)
            .fetch_optional(&state.db)
            .await
            .map_err(|e| internal_error(e.into()))?;
    let Some(domain_id) = domain_id else {
        return Err(ApiError::new(
            StatusCode::NOT_FOUND,
            "Email account not found",
        ));
    };
    let hash = crate::auth::hash_password(password).map_err(|e| internal_error(e))?;
    sqlx::query("UPDATE email_accounts SET password_hash = ? WHERE id = ?")
        .bind(&hash)
        .bind(id)
        .execute(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?;

    sync_mail(state, aid, domain_id).await;
    Ok(())
}

async fn list_forwarders(state: &AppState, aid: i64) -> Result<Vec<Forwarder>, ApiError> {
    let rows = sqlx::query(
        "SELECT f.id, f.account_id, f.domain_id, d.name, f.from_address, f.to_address, f.status \
         FROM email_forwarders f JOIN domains d ON d.id = f.domain_id \
         WHERE f.account_id = ? ORDER BY f.from_address",
    )
    .bind(aid)
    .fetch_all(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?;
    let mut out = Vec::with_capacity(rows.len());
    for r in rows {
        out.push(Forwarder {
            id: r.get(0),
            account_id: r.get(1),
            domain_id: r.get(2),
            domain: r.get(3),
            from: r.get(4),
            to: r.get(5),
            status: r.get(6),
        });
    }
    Ok(out)
}

async fn create_forwarder(
    state: &AppState,
    aid: i64,
    body: &CreateForwarder,
) -> Result<Forwarder, ApiError> {
    let domain = domain_owned(state, aid, body.domain_id).await?;
    let from = forward_address(&domain, &body.from)?;
    let to = forward_to(&body.to)?;

    let exists: i64 =
        sqlx::query_scalar("SELECT COUNT(*) FROM email_forwarders WHERE domain_id = ? AND from_address = ?")
            .bind(body.domain_id)
            .bind(&from)
            .fetch_one(&state.db)
            .await
            .map_err(|e| internal_error(e.into()))?;
    if exists > 0 {
        return Err(ApiError::new(
            StatusCode::CONFLICT,
            "Forwarder for this address already exists",
        ));
    }

    let result = sqlx::query(
        "INSERT INTO email_forwarders (account_id, domain_id, from_address, to_address) \
         VALUES (?, ?, ?, ?)",
    )
    .bind(aid)
    .bind(body.domain_id)
    .bind(&from)
    .bind(&to)
    .execute(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?;

    sync_mail(state, aid, body.domain_id).await;

    Ok(Forwarder {
        id: result.last_insert_rowid(),
        account_id: aid,
        domain_id: body.domain_id,
        domain,
        from,
        to,
        status: "active".into(),
    })
}

async fn delete_forwarder(state: &AppState, aid: i64, id: i64) -> Result<(), ApiError> {
    let domain_id: Option<i64> =
        sqlx::query_scalar("SELECT domain_id FROM email_forwarders WHERE id = ? AND account_id = ?")
            .bind(id)
            .bind(aid)
            .fetch_optional(&state.db)
            .await
            .map_err(|e| internal_error(e.into()))?;
    let Some(domain_id) = domain_id else {
        return Err(ApiError::new(
            StatusCode::NOT_FOUND,
            "Forwarder not found",
        ));
    };
    sqlx::query("DELETE FROM email_forwarders WHERE id = ?")
        .bind(id)
        .execute(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?;

    sync_mail(state, aid, domain_id).await;
    Ok(())
}

async fn list_autoresponders(state: &AppState, aid: i64) -> Result<Vec<Autoresponder>, ApiError> {
    let rows = sqlx::query(
        "SELECT a.id, a.account_id, a.domain_id, d.name, a.local, a.subject, a.body, \
                a.start_date, a.end_date, a.status \
         FROM email_autoresponders a JOIN domains d ON d.id = a.domain_id \
         WHERE a.account_id = ? ORDER BY a.local",
    )
    .bind(aid)
    .fetch_all(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?;
    let mut out = Vec::with_capacity(rows.len());
    for r in rows {
        let local: String = r.get(4);
        let domain: String = r.get(3);
        out.push(Autoresponder {
            id: r.get(0),
            account_id: r.get(1),
            domain_id: r.get(2),
            domain: domain.clone(),
            local: local.clone(),
            address: format!("{local}@{domain}"),
            subject: r.get(5),
            body: r.get(6),
            start_date: r.get(7),
            end_date: r.get(8),
            status: r.get(9),
        });
    }
    Ok(out)
}

async fn create_autoresponder(
    state: &AppState,
    aid: i64,
    body: &CreateAutoresponder,
) -> Result<Autoresponder, ApiError> {
    let local = valid_local(&body.local)?;
    let domain = domain_owned(state, aid, body.domain_id).await?;
    if body.subject.trim().is_empty() {
        return Err(ApiError::new(
            StatusCode::BAD_REQUEST,
            "Subject is required",
        ));
    }

    let exists: i64 =
        sqlx::query_scalar("SELECT COUNT(*) FROM email_autoresponders WHERE domain_id = ? AND local = ?")
            .bind(body.domain_id)
            .bind(&local)
            .fetch_one(&state.db)
            .await
            .map_err(|e| internal_error(e.into()))?;
    if exists > 0 {
        return Err(ApiError::new(
            StatusCode::CONFLICT,
            "Autoresponder for this address already exists",
        ));
    }

    let result = sqlx::query(
        "INSERT INTO email_autoresponders (account_id, domain_id, local, subject, body, start_date, end_date) \
         VALUES (?, ?, ?, ?, ?, ?, ?)",
    )
    .bind(aid)
    .bind(body.domain_id)
    .bind(&local)
    .bind(body.subject.trim())
    .bind(body.body.as_deref().unwrap_or(""))
    .bind(&body.start_date)
    .bind(&body.end_date)
    .execute(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?;

    sync_mail(state, aid, body.domain_id).await;

    let address = format!("{local}@{domain}");
    Ok(Autoresponder {
        id: result.last_insert_rowid(),
        account_id: aid,
        domain_id: body.domain_id,
        domain,
        local: local.clone(),
        address,
        subject: body.subject.trim().to_string(),
        body: body.body.clone().unwrap_or_default(),
        start_date: body.start_date.clone(),
        end_date: body.end_date.clone(),
        status: "active".into(),
    })
}

async fn delete_autoresponder(state: &AppState, aid: i64, id: i64) -> Result<(), ApiError> {
    let domain_id: Option<i64> = sqlx::query_scalar(
        "SELECT domain_id FROM email_autoresponders WHERE id = ? AND account_id = ?",
    )
    .bind(id)
    .bind(aid)
    .fetch_optional(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?;
    let Some(domain_id) = domain_id else {
        return Err(ApiError::new(
            StatusCode::NOT_FOUND,
            "Autoresponder not found",
        ));
    };
    sqlx::query("DELETE FROM email_autoresponders WHERE id = ?")
        .bind(id)
        .execute(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?;

    sync_mail(state, aid, domain_id).await;
    Ok(())
}

async fn list_defaults(state: &AppState, aid: i64) -> Result<Vec<DefaultAddr>, ApiError> {
    let rows = sqlx::query(
        "SELECT e.id, e.account_id, e.domain_id, d.name, e.action, e.forward_to \
         FROM email_defaults e JOIN domains d ON d.id = e.domain_id \
         WHERE e.account_id = ? ORDER BY d.name",
    )
    .bind(aid)
    .fetch_all(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?;
    let mut out = Vec::with_capacity(rows.len());
    for r in rows {
        out.push(DefaultAddr {
            id: r.get(0),
            account_id: r.get(1),
            domain_id: r.get(2),
            domain: r.get(3),
            action: r.get(4),
            forward_to: r.get(5),
        });
    }
    Ok(out)
}

async fn set_default(
    state: &AppState,
    aid: i64,
    body: &DefaultBody,
) -> Result<DefaultAddr, ApiError> {
    let _domain = domain_owned(state, aid, body.domain_id).await?;
    let action = body.action.to_lowercase();
    if !["discard", "blackhole", "forward"].contains(&action.as_str()) {
        return Err(ApiError::new(
            StatusCode::BAD_REQUEST,
            "action must be discard, blackhole or forward",
        ));
    }
    let forward_to = if action == "forward" {
        Some(forward_to(body.forward_to.as_deref().unwrap_or(""))?.to_lowercase())
    } else {
        None
    };

    sqlx::query(
        "INSERT INTO email_defaults (account_id, domain_id, action, forward_to) \
         VALUES (?, ?, ?, ?) \
         ON CONFLICT(account_id, domain_id) DO UPDATE \
         SET action = excluded.action, forward_to = excluded.forward_to",
    )
    .bind(aid)
    .bind(body.domain_id)
    .bind(&action)
    .bind(&forward_to)
    .execute(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?;

    let row = sqlx::query(
        "SELECT e.id, e.account_id, e.domain_id, d.name, e.action, e.forward_to \
         FROM email_defaults e JOIN domains d ON d.id = e.domain_id \
         WHERE e.account_id = ? AND e.domain_id = ?",
    )
    .bind(aid)
    .bind(body.domain_id)
    .fetch_one(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?;

    sync_mail(state, aid, body.domain_id).await;

    Ok(DefaultAddr {
        id: row.get(0),
        account_id: row.get(1),
        domain_id: row.get(2),
        domain: row.get(3),
        action: row.get(4),
        forward_to: row.get(5),
    })
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
    name.ok_or_else(|| ApiError::new(StatusCode::NOT_FOUND, "Domain not found for this account"))
}

fn valid_local(s: &str) -> Result<String, ApiError> {
    let s = s.trim();
    if s.is_empty()
        || s.len() > 64
        || s.starts_with('.')
        || s.ends_with('.')
        || !s
            .chars()
            .all(|c| c.is_ascii_alphanumeric() || c == '.' || c == '_' || c == '-')
    {
        return Err(ApiError::new(
            StatusCode::BAD_REQUEST,
            "Invalid email local part: letters, numbers, . _ - only (max 64)",
        ));
    }
    Ok(s.to_lowercase())
}

fn forward_address(domain: &str, raw: &str) -> Result<String, ApiError> {
    let raw = raw.trim().to_lowercase();
    let Some((local, dom)) = raw.split_once('@') else {
        return Err(ApiError::new(
            StatusCode::BAD_REQUEST,
            "Forwarder address must be an email address with @",
        ));
    };
    if dom != domain {
        return Err(ApiError::new(
            StatusCode::BAD_REQUEST,
            "Forwarder address must be on this domain",
        ));
    }
    Ok(format!("{}@{}", valid_local(local)?, domain))
}

fn forward_to(raw: &str) -> Result<String, ApiError> {
    let parts: Vec<String> = raw
        .split(',')
        .map(|p| p.trim().to_lowercase())
        .filter(|p| !p.is_empty())
        .collect();
    if parts.is_empty() {
        return Err(ApiError::new(
            StatusCode::BAD_REQUEST,
            "Forward destination is required",
        ));
    }
    for p in &parts {
        if !p.contains('@') || p.starts_with('@') || p.ends_with('@') {
            return Err(ApiError::new(
                StatusCode::BAD_REQUEST,
                format!("Invalid forward destination: {p}"),
            ));
        }
    }
    Ok(parts.join(","))
}

async fn sync_mail(state: &AppState, aid: i64, domain_id: i64) {
    let name = match domain_owned(state, aid, domain_id).await {
        Ok(n) => n,
        Err(_) => return,
    };

    let accounts: Vec<provision::MailAccount> = match sqlx::query(
        "SELECT local, password_hash, forward_to, quota_mb FROM email_accounts \
         WHERE domain_id = ? AND status = 'active' ORDER BY local",
    )
    .bind(domain_id)
    .fetch_all(&state.db)
    .await
    {
        Ok(rows) => rows
            .iter()
            .map(|r| provision::MailAccount {
                local: r.get(0),
                password_hash: r.get(1),
                forward_to: r.get(2),
                quota_mb: r.get(3),
            })
            .collect(),
        Err(e) => {
            tracing::warn!("[email] sync accounts failed: {e}");
            return;
        }
    };

    let forwarders: Vec<provision::MailForwarder> = match sqlx::query(
        "SELECT from_address, to_address FROM email_forwarders \
         WHERE domain_id = ? AND status = 'active' ORDER BY from_address",
    )
    .bind(domain_id)
    .fetch_all(&state.db)
    .await
    {
        Ok(rows) => rows
            .iter()
            .map(|r| provision::MailForwarder {
                from: r.get(0),
                to: r
                    .get::<String, _>(1)
                    .split(',')
                    .map(|p| p.trim().to_string())
                    .filter(|p| !p.is_empty())
                    .collect(),
            })
            .collect(),
        Err(e) => {
            tracing::warn!("[email] sync forwarders failed: {e}");
            return;
        }
    };

    let autoresponders: Vec<provision::MailAutoresponder> = match sqlx::query(
        "SELECT local, subject, body FROM email_autoresponders \
         WHERE domain_id = ? AND status = 'active' ORDER BY local",
    )
    .bind(domain_id)
    .fetch_all(&state.db)
    .await
    {
        Ok(rows) => rows
            .iter()
            .map(|r| provision::MailAutoresponder {
                local: r.get(0),
                subject: r.get(1),
                body: r.get(2),
            })
            .collect(),
        Err(e) => {
            tracing::warn!("[email] sync autoresponders failed: {e}");
            return;
        }
    };

    let default = match sqlx::query(
        "SELECT action, forward_to FROM email_defaults \
         WHERE account_id = ? AND domain_id = ? AND status = 'active'",
    )
    .bind(aid)
    .bind(domain_id)
    .fetch_optional(&state.db)
    .await
    {
        Ok(Some(r)) => Some(provision::MailDefault {
            action: r.get(0),
            to: r.get(1),
        }),
        Ok(None) => None,
        Err(e) => {
            tracing::warn!("[email] sync default failed: {e}");
            return;
        }
    };

    provision::write_mail(
        &name,
        &provision::MailProvision {
            domain: name.clone(),
            accounts,
            forwarders,
            autoresponders,
            default,
        },
    );
}