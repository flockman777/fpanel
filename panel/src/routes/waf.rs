use axum::extract::{Path, Query, State};
use axum::http::{HeaderMap, StatusCode};
use axum::routing::{delete, get, put};
use axum::{Json, Router};
use serde::{Deserialize, Serialize};
use serde_json::Value;
use sqlx::Row;

use crate::db::AppState;
use crate::error::{internal_error, ApiError};
use crate::routes::domains::bearer_account;
use crate::routes::sec::{default_waf_rules_json, refresh_security};

#[derive(Serialize, Deserialize, Clone)]
pub struct WafRuleIn {
    pub id: String,
    pub name: String,
    pub pattern: String,
    pub severity: String,
    pub action: String,
}

#[derive(Serialize)]
struct RowOut {
    id: i64,
    account_id: i64,
    domain_id: i64,
    domain: String,
    enabled: bool,
    mode: String,
    rules: Value,
    created_at: String,
}

#[derive(Deserialize)]
struct UpsertBody {
    domain_id: i64,
    enabled: Option<bool>,
    mode: Option<String>,
    rules: Option<Vec<WafRuleIn>>,
}

#[derive(Deserialize)]
struct AccountQ {
    account_id: Option<i64>,
}

pub fn router() -> Router<AppState> {
    Router::new()
        .route("/", get(list_admin).put(upsert_admin))
        .route("/defaults", get(defaults_admin))
        .route("/{id}", delete(remove_admin))
}

pub fn client_router() -> Router<AppState> {
    Router::new()
        .route("/", get(list_client).put(upsert_client))
        .route("/defaults", get(defaults_client))
        .route("/{id}", delete(remove_client))
}

async fn list_rows(state: &AppState, aid: i64) -> Result<Vec<RowOut>, ApiError> {
    let rows = sqlx::query(
        "SELECT id, account_id, domain_id, domain, enabled, mode, rules, created_at \
         FROM waf_rules WHERE account_id = ? ORDER BY id DESC",
    )
    .bind(aid)
    .fetch_all(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?;
    rows.iter()
        .map(|r| {
            let rules: Option<String> = r.get(6);
            let parsed = rules
                .as_ref()
                .and_then(|s| serde_json::from_str::<Value>(s).ok())
                .unwrap_or_else(|| Value::Array(vec![]));
            Ok(RowOut {
                id: r.get(0),
                account_id: r.get(1),
                domain_id: r.get(2),
                domain: r.get(3),
                enabled: r.get::<i64, _>(4) != 0,
                mode: r.get(5),
                rules: parsed,
                created_at: r.get(7),
            })
        })
        .collect()
}

async fn upsert(state: &AppState, aid: i64, body: UpsertBody) -> Result<RowOut, ApiError> {
    let name: String = sqlx::query_scalar(
        "SELECT name FROM domains WHERE id = ? AND account_id = ? AND status = 'active'",
    )
    .bind(body.domain_id)
    .bind(aid)
    .fetch_optional(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?
    .ok_or_else(|| ApiError::new(StatusCode::NOT_FOUND, "Domain not found"))?;

    let enabled = body.enabled.unwrap_or(true);
    let mode = body.mode.unwrap_or_else(|| "block".to_string());
    if mode != "block" && mode != "log" {
        return Err(ApiError::new(
            StatusCode::BAD_REQUEST,
            "mode must be 'block' or 'log'",
        ));
    }
    let rules = match body.rules {
        Some(list) => serde_json::to_string(&list).map_err(|e| internal_error(e.into()))?,
        None => default_waf_rules_json(),
    };

    sqlx::query(
        "INSERT INTO waf_rules (account_id, domain_id, domain, enabled, mode, rules) VALUES (?, ?, ?, ?, ?, ?) \
         ON CONFLICT(domain_id) DO UPDATE SET enabled = excluded.enabled, mode = excluded.mode, rules = excluded.rules",
    )
    .bind(aid)
    .bind(body.domain_id)
    .bind(&name)
    .bind(enabled)
    .bind(&mode)
    .bind(&rules)
    .execute(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?;

    refresh_security(state, aid, body.domain_id, &name).await?;

    let row = sqlx::query(
        "SELECT id, account_id, domain_id, domain, enabled, mode, rules, created_at \
         FROM waf_rules WHERE domain_id = ?",
    )
    .bind(body.domain_id)
    .fetch_one(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?;
    let rules_raw: Option<String> = row.get(6);
    let parsed = rules_raw
        .as_ref()
        .and_then(|s| serde_json::from_str::<Value>(s).ok())
        .unwrap_or_else(|| Value::Array(vec![]));
    Ok(RowOut {
        id: row.get(0),
        account_id: row.get(1),
        domain_id: row.get(2),
        domain: row.get(3),
        enabled: row.get::<i64, _>(4) != 0,
        mode: row.get(5),
        rules: parsed,
        created_at: row.get(7),
    })
}

async fn remove(state: &AppState, aid: i64, id: i64) -> Result<(), ApiError> {
    let row = sqlx::query("SELECT domain_id, domain FROM waf_rules WHERE id = ? AND account_id = ?")
        .bind(id)
        .bind(aid)
        .fetch_optional(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?;
    let Some(row) = row else {
        return Err(ApiError::new(StatusCode::NOT_FOUND, "WAF rule set not found"));
    };
    let domain_id: i64 = row.get(0);
    let domain: String = row.get(1);

    sqlx::query("DELETE FROM waf_rules WHERE id = ? AND account_id = ?")
        .bind(id)
        .bind(aid)
        .execute(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?;

    refresh_security(state, aid, domain_id, &domain).await?;
    Ok(())
}

fn defaults() -> Vec<WafRuleIn> {
    // mirrors BUILTIN_WAF_PATTERNS
    let ids = [
        ("sqli", "SQL injection"),
        ("xss", "Cross-site scripting"),
        ("lfi", "Local file inclusion"),
        ("rfi", "Remote file inclusion"),
        ("cmdi", "Command injection"),
        ("phpi", "PHP function abuse"),
        ("cve", "Directory traversal"),
        ("scanner", "Vulnerability scanner"),
        ("traversal", "Encoded traversal"),
        ("dotenv", "Config exposure"),
    ];
    let pats = [
        r"(?i)(union\s+select|select\s+.*\s+from|insert\s+into|update\s+.*\s+set|drop\s+table|--|/\*|\b(or|and)\s+\d+=\d+)",
        r"(?i)(<script|javascript:|onerror\s*=|onload\s*=|<iframe|<object|<embed)",
        r"(?i)(\.\./|\.\.\\|/etc/passwd|/etc/shadow|windows\\win\.ini|php://filter)",
        r"(?i)(https?://\S+\.(php|txt|log))",
        r"(?i)(;\s*(cat|ls|wget|curl|nc|bash|sh|pwd)\b|\|\s*(cat|ls|sh)\b|`[^`]+`)",
        r"(?i)(\b(move_uploaded_file|eval|assert|system|passthru|shell_exec)\s*\()",
        r"(?i)(\.\./\.\./\.\./|/\.\.|\.\./)",
        r"(?i)((acunetix|nessus|nikto|sqlmap|burp|zap)\b)",
        r"(?i)(%2e%2e%2f|%252e|.\{2\}\/)",
        r"(?i)(\.env\b|wp-config\.php|config\.inc\.php)",
    ];
    ids
        .iter()
        .zip(pats.iter())
        .map(|((id, name), pattern)| WafRuleIn {
            id: id.to_string(),
            name: name.to_string(),
            pattern: pattern.to_string(),
            severity: "medium".to_string(),
            action: "block".to_string(),
        })
        .collect()
}

// endpoints
async fn list_client(State(state): State<AppState>, headers: HeaderMap) -> Result<Json<Vec<RowOut>>, ApiError> {
    let (aid, _) = bearer_account(&state, &headers).await?;
    Ok(Json(list_rows(&state, aid).await?))
}

async fn upsert_client(
    State(state): State<AppState>,
    headers: HeaderMap,
    Json(body): Json<UpsertBody>,
) -> Result<Json<RowOut>, ApiError> {
    let (aid, _) = bearer_account(&state, &headers).await?;
    Ok(Json(upsert(&state, aid, body).await?))
}

async fn remove_client(
    State(state): State<AppState>,
    headers: HeaderMap,
    Path((_sess, id)): Path<(String, i64)>,
) -> Result<StatusCode, ApiError> {
    let (aid, _) = bearer_account(&state, &headers).await?;
    remove(&state, aid, id).await?;
    Ok(StatusCode::NO_CONTENT)
}

async fn defaults_client(
    State(state): State<AppState>,
    headers: HeaderMap,
) -> Result<Json<Vec<WafRuleIn>>, ApiError> {
    let _ = bearer_account(&state, &headers).await?;
    Ok(Json(defaults()))
}

fn require_account(aid: Option<i64>) -> Result<i64, ApiError> {
    aid.ok_or_else(|| ApiError::new(StatusCode::BAD_REQUEST, "account_id is required"))
}

async fn list_admin(State(state): State<AppState>, Query(q): Query<AccountQ>) -> Result<Json<Vec<RowOut>>, ApiError> {
    let aid = require_account(q.account_id)?;
    Ok(Json(list_rows(&state, aid).await?))
}

async fn upsert_admin(
    State(state): State<AppState>,
    Query(q): Query<AccountQ>,
    Json(body): Json<UpsertBody>,
) -> Result<Json<RowOut>, ApiError> {
    let aid = require_account(q.account_id)?;
    Ok(Json(upsert(&state, aid, body).await?))
}

async fn remove_admin(
    State(state): State<AppState>,
    Query(q): Query<AccountQ>,
    Path((_sess, id)): Path<(String, i64)>,
) -> Result<StatusCode, ApiError> {
    let aid = require_account(q.account_id)?;
    remove(&state, aid, id).await?;
    Ok(StatusCode::NO_CONTENT)
}

async fn defaults_admin(State(state): State<AppState>, Query(q): Query<AccountQ>) -> Result<Json<Vec<WafRuleIn>>, ApiError> {
    let _ = require_account(q.account_id)?;
    let _ = &state;
    Ok(Json(defaults()))
}