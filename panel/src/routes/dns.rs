use axum::extract::{Path, Query, State};
use axum::http::StatusCode;
use axum::routing::{delete, get, put};
use axum::{Json, Router};
use serde::{Deserialize, Serialize};
use sqlx::FromRow;
use sqlx::Row;
use std::str::FromStr;

use crate::db::AppState;
use crate::error::{internal_error, ApiError};
use crate::provision;
use crate::routes::domains::bearer_account;

#[derive(Debug, Serialize, FromRow)]
pub struct DnsRecord {
    pub id: i64,
    pub account_id: i64,
    pub domain_id: i64,
    pub domain: String,
    pub name: String,
    pub rtype: String,
    pub value: String,
    pub ttl: i64,
    pub priority: Option<i64>,
    pub status: String,
    pub created_at: String,
}

#[derive(Debug, Serialize)]
pub struct DnsAdmin {
    pub id: i64,
    pub account_id: i64,
    pub username: String,
    pub domain_id: i64,
    pub domain: String,
    pub name: String,
    pub rtype: String,
    pub value: String,
    pub ttl: i64,
    pub priority: Option<i64>,
    pub status: String,
    pub created_at: String,
}

#[derive(Debug, Deserialize)]
pub struct CreateDns {
    pub domain_id: i64,
    pub name: Option<String>,
    pub rtype: String,
    pub value: String,
    pub ttl: Option<i64>,
    pub priority: Option<i64>,
}

#[derive(Debug, Deserialize)]
pub struct UpdateDns {
    pub name: Option<String>,
    pub rtype: Option<String>,
    pub value: Option<String>,
    pub ttl: Option<i64>,
    pub priority: Option<i64>,
}

const VALID_TYPES: &[&str] = &["A", "AAAA", "CNAME", "MX", "TXT", "NS", "SRV", "CAA"];

pub fn router() -> Router<AppState> {
    Router::new()
        .route("/", get(list).post(create))
        .route("/{id}", get(get_one).put(update).delete(remove))
}

pub fn client_router() -> Router<AppState> {
    Router::new()
        .route("/", get(client_list).post(client_create))
        .route("/{id}", put(client_update).delete(client_remove))
}

#[derive(Debug, Deserialize)]
struct ListQuery {
    domain_id: Option<i64>,
}

async fn list(
    State(state): State<AppState>,
    Query(q): Query<ListQuery>,
) -> Result<Json<Vec<DnsAdmin>>, ApiError> {
    let rows = if let Some(did) = q.domain_id {
        sqlx::query(
            "SELECT r.id, r.account_id, a.username, r.domain_id, r.domain, r.name, r.rtype, r.value, \
             r.ttl, r.priority, r.status, r.created_at \
             FROM dns_records r JOIN accounts a ON a.id = r.account_id \
             WHERE r.domain_id = ? ORDER BY r.rtype, r.name",
        )
        .bind(did)
        .fetch_all(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?
    } else {
        sqlx::query(
            "SELECT r.id, r.account_id, a.username, r.domain_id, r.domain, r.name, r.rtype, r.value, \
             r.ttl, r.priority, r.status, r.created_at \
             FROM dns_records r JOIN accounts a ON a.id = r.account_id \
             ORDER BY r.domain, r.rtype, r.name",
        )
        .fetch_all(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?
    };

    let mut out = Vec::with_capacity(rows.len());
    for r in rows {
        out.push(DnsAdmin {
            id: r.get(0),
            account_id: r.get(1),
            username: r.get(2),
            domain_id: r.get(3),
            domain: r.get(4),
            name: r.get(5),
            rtype: r.get(6),
            value: r.get(7),
            ttl: r.get(8),
            priority: r.get(9),
            status: r.get(10),
            created_at: r.get(11),
        });
    }
    Ok(Json(out))
}

async fn get_one(
    State(state): State<AppState>,
    Path((_sess, id)): Path<(String, i64)>,
) -> Result<Json<DnsAdmin>, ApiError> {
    let row = sqlx::query(
        "SELECT r.id, r.account_id, a.username, r.domain_id, r.domain, r.name, r.rtype, r.value, \
         r.ttl, r.priority, r.status, r.created_at \
         FROM dns_records r JOIN accounts a ON a.id = r.account_id WHERE r.id = ?",
    )
    .bind(id)
    .fetch_optional(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?
    .ok_or_else(|| ApiError::new(StatusCode::NOT_FOUND, "DNS record not found"))?;
    Ok(Json(DnsAdmin {
        id: row.get(0),
        account_id: row.get(1),
        username: row.get(2),
        domain_id: row.get(3),
        domain: row.get(4),
        name: row.get(5),
        rtype: row.get(6),
        value: row.get(7),
        ttl: row.get(8),
        priority: row.get(9),
        status: row.get(10),
        created_at: row.get(11),
    }))
}

async fn create(
    State(state): State<AppState>,
    Json(input): Json<CreateDns>,
) -> Result<(StatusCode, Json<DnsRecord>), ApiError> {
    let (account_id, domain) = owned_domain(&state, input.domain_id, None).await?;
    validate_record(&input.rtype, input.name.as_deref(), &input.value, input.ttl)?;
    let ttl = input.ttl.unwrap_or(3600);

    let result = sqlx::query(
        "INSERT INTO dns_records (account_id, domain_id, domain, name, rtype, value, ttl, priority) \
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
    )
    .bind(account_id)
    .bind(input.domain_id)
    .bind(&domain)
    .bind(input.name.unwrap_or_else(|| "@".into()).trim().to_lowercase())
    .bind(input.rtype.to_uppercase())
    .bind(input.value.trim())
    .bind(ttl)
    .bind(input.priority)
    .execute(&state.db)
    .await
    .map_err(|e| {
        if e.to_string().contains("UNIQUE") {
            ApiError::new(StatusCode::CONFLICT, "DNS record already exists")
        } else {
            internal_error(e.into())
        }
    })?;

    let rec = fetch_record(&state, result.last_insert_rowid()).await?;
    generate_zone(&state, input.domain_id).await?;
    Ok((StatusCode::CREATED, Json(rec)))
}

async fn update(
    State(state): State<AppState>,
    Path((_sess, id)): Path<(String, i64)>,
    Json(input): Json<UpdateDns>,
) -> Result<Json<DnsRecord>, ApiError> {
    let existing = sqlx::query_as::<_, DnsRecord>("SELECT * FROM dns_records WHERE id = ?")
        .bind(id)
        .fetch_optional(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?
        .ok_or_else(|| ApiError::new(StatusCode::NOT_FOUND, "DNS record not found"))?;

    let name = input.name.unwrap_or(existing.name);
    let rtype = input.rtype.unwrap_or(existing.rtype).to_uppercase();
    let value = input.value.unwrap_or(existing.value);
    let ttl = input.ttl.unwrap_or(existing.ttl);
    let priority = input.priority;
    validate_record(&rtype, Some(&name), &value, Some(ttl))?;

    sqlx::query(
        "UPDATE dns_records SET name = ?, rtype = ?, value = ?, ttl = ?, priority = ? WHERE id = ?",
    )
    .bind(name.trim().to_lowercase())
    .bind(rtype)
    .bind(value.trim())
    .bind(ttl)
    .bind(priority)
    .bind(id)
    .execute(&state.db)
    .await
    .map_err(|e| {
        if e.to_string().contains("UNIQUE") {
            ApiError::new(StatusCode::CONFLICT, "DNS record already exists")
        } else {
            internal_error(e.into())
        }
    })?;

    let rec = fetch_record(&state, id).await?;
    generate_zone(&state, existing.domain_id).await?;
    Ok(Json(rec))
}

async fn remove(
    State(state): State<AppState>,
    Path((_sess, id)): Path<(String, i64)>,
) -> Result<StatusCode, ApiError> {
    let existing = sqlx::query_as::<_, DnsRecord>("SELECT * FROM dns_records WHERE id = ?")
        .bind(id)
        .fetch_optional(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?
        .ok_or_else(|| ApiError::new(StatusCode::NOT_FOUND, "DNS record not found"))?;
    sqlx::query("DELETE FROM dns_records WHERE id = ?")
        .bind(id)
        .execute(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?;
    generate_zone(&state, existing.domain_id).await?;
    Ok(StatusCode::NO_CONTENT)
}

async fn client_list(
    State(state): State<AppState>,
    headers: axum::http::HeaderMap,
) -> Result<Json<Vec<DnsRecord>>, ApiError> {
    let (account_id, _) = bearer_account(&state, &headers).await?;
    let records = sqlx::query_as::<_, DnsRecord>(
        "SELECT * FROM dns_records WHERE account_id = ? ORDER BY domain, rtype, name",
    )
    .bind(account_id)
    .fetch_all(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?;
    Ok(Json(records))
}

async fn client_create(
    State(state): State<AppState>,
    headers: axum::http::HeaderMap,
    Json(input): Json<CreateDns>,
) -> Result<(StatusCode, Json<DnsRecord>), ApiError> {
    let (account_id, _) = bearer_account(&state, &headers).await?;
    let (_, domain) = owned_domain(&state, input.domain_id, Some(account_id)).await?;
    validate_record(&input.rtype, input.name.as_deref(), &input.value, input.ttl)?;
    let ttl = input.ttl.unwrap_or(3600);

    let result = sqlx::query(
        "INSERT INTO dns_records (account_id, domain_id, domain, name, rtype, value, ttl, priority) \
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
    )
    .bind(account_id)
    .bind(input.domain_id)
    .bind(&domain)
    .bind(input.name.unwrap_or_else(|| "@".into()).trim().to_lowercase())
    .bind(input.rtype.to_uppercase())
    .bind(input.value.trim())
    .bind(ttl)
    .bind(input.priority)
    .execute(&state.db)
    .await
    .map_err(|e| {
        if e.to_string().contains("UNIQUE") {
            ApiError::new(StatusCode::CONFLICT, "DNS record already exists")
        } else {
            internal_error(e.into())
        }
    })?;

    let rec = fetch_record(&state, result.last_insert_rowid()).await?;
    generate_zone(&state, input.domain_id).await?;
    Ok((StatusCode::CREATED, Json(rec)))
}

async fn client_update(
    State(state): State<AppState>,
    headers: axum::http::HeaderMap,
    Path((_sess, id)): Path<(String, i64)>,
    Json(input): Json<UpdateDns>,
) -> Result<Json<DnsRecord>, ApiError> {
    let (account_id, _) = bearer_account(&state, &headers).await?;
    let existing = sqlx::query_as::<_, DnsRecord>(
        "SELECT * FROM dns_records WHERE id = ? AND account_id = ?",
    )
    .bind(id)
    .bind(account_id)
    .fetch_optional(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?
    .ok_or_else(|| ApiError::new(StatusCode::NOT_FOUND, "DNS record not found"))?;

    let name = input.name.unwrap_or(existing.name);
    let rtype = input.rtype.unwrap_or(existing.rtype).to_uppercase();
    let value = input.value.unwrap_or(existing.value);
    let ttl = input.ttl.unwrap_or(existing.ttl);
    let priority = input.priority;
    validate_record(&rtype, Some(&name), &value, Some(ttl))?;

    sqlx::query(
        "UPDATE dns_records SET name = ?, rtype = ?, value = ?, ttl = ?, priority = ? WHERE id = ?",
    )
    .bind(name.trim().to_lowercase())
    .bind(rtype)
    .bind(value.trim())
    .bind(ttl)
    .bind(priority)
    .bind(id)
    .execute(&state.db)
    .await
    .map_err(|e| {
        if e.to_string().contains("UNIQUE") {
            ApiError::new(StatusCode::CONFLICT, "DNS record already exists")
        } else {
            internal_error(e.into())
        }
    })?;

    let rec = fetch_record(&state, id).await?;
    generate_zone(&state, existing.domain_id).await?;
    Ok(Json(rec))
}

async fn client_remove(
    State(state): State<AppState>,
    headers: axum::http::HeaderMap,
    Path((_sess, id)): Path<(String, i64)>,
) -> Result<StatusCode, ApiError> {
    let (account_id, _) = bearer_account(&state, &headers).await?;
    let existing = sqlx::query_as::<_, DnsRecord>(
        "SELECT * FROM dns_records WHERE id = ? AND account_id = ?",
    )
    .bind(id)
    .bind(account_id)
    .fetch_optional(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?
    .ok_or_else(|| ApiError::new(StatusCode::NOT_FOUND, "DNS record not found"))?;
    sqlx::query("DELETE FROM dns_records WHERE id = ?")
        .bind(id)
        .execute(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?;
    generate_zone(&state, existing.domain_id).await?;
    Ok(StatusCode::NO_CONTENT)
}

async fn fetch_record(state: &AppState, id: i64) -> Result<DnsRecord, ApiError> {
    sqlx::query_as::<_, DnsRecord>("SELECT * FROM dns_records WHERE id = ?")
        .bind(id)
        .fetch_one(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))
}

async fn owned_domain(
    state: &AppState,
    domain_id: i64,
    account_id: Option<i64>,
) -> Result<(i64, String), ApiError> {
    if let Some(aid) = account_id {
        sqlx::query_as::<_, (i64, String)>(
            "SELECT account_id, name FROM domains WHERE id = ? AND account_id = ?",
        )
        .bind(domain_id)
        .bind(aid)
        .fetch_optional(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?
        .ok_or_else(|| ApiError::new(StatusCode::NOT_FOUND, "Domain not found for this account"))
    } else {
        sqlx::query_as::<_, (i64, String)>("SELECT account_id, name FROM domains WHERE id = ?")
            .bind(domain_id)
            .fetch_optional(&state.db)
            .await
            .map_err(|e| internal_error(e.into()))?
            .ok_or_else(|| ApiError::new(StatusCode::NOT_FOUND, "Domain not found"))
    }
}

fn validate_record(rtype: &str, name: Option<&str>, value: &str, ttl: Option<i64>) -> Result<(), ApiError> {
    let rt = rtype.trim().to_uppercase();
    if !VALID_TYPES.contains(&rt.as_str()) {
        return Err(ApiError::new(
            StatusCode::BAD_REQUEST,
            format!("Unsupported record type (must be one of: {})", VALID_TYPES.join(", ")),
        ));
    }
    if let Some(n) = name {
        let n = n.trim();
        if n != "@" && !n.chars().all(|c| c.is_ascii_alphanumeric() || c == '-' || c == '.' || c == '*' || c == '_') {
            return Err(ApiError::new(StatusCode::BAD_REQUEST, "Invalid record name"));
        }
    }
    let v = value.trim();
    if v.is_empty() {
        return Err(ApiError::new(StatusCode::BAD_REQUEST, "Record value is required"));
    }
    if let Some(t) = ttl {
        if !(1..=604800).contains(&t) {
            return Err(ApiError::new(StatusCode::BAD_REQUEST, "TTL must be between 1 and 604800"));
        }
    }
    if v.contains(['\n', '\r', ';', '$', '(', ')']) {
        return Err(ApiError::new(
            StatusCode::BAD_REQUEST,
            "Record value contains invalid characters",
        ));
    }
    let hostname_ok = |s: &str| s.chars().all(|c| c.is_ascii_alphanumeric() || c == '.' || c == '-' || c == '_');
    match rt.as_str() {
        "A" => {
            if std::net::Ipv4Addr::from_str(v).is_err() {
                return Err(ApiError::new(StatusCode::BAD_REQUEST, "Invalid IPv4 address for A record"));
            }
        }
        "AAAA" => {
            if std::net::Ipv6Addr::from_str(v).is_err() {
                return Err(ApiError::new(StatusCode::BAD_REQUEST, "Invalid IPv6 address for AAAA record"));
            }
        }
        "TXT" => {
            if v.len() > 255 || v.chars().any(|c| !c.is_ascii() || c.is_control()) {
                return Err(ApiError::new(
                    StatusCode::BAD_REQUEST,
                    "TXT value must be printable ASCII up to 255 characters",
                ));
            }
        }
        "MX" | "SRV" | "CNAME" | "NS" | "ANAME" | "CAA" => {
            if !hostname_ok(v) {
                return Err(ApiError::new(
                    StatusCode::BAD_REQUEST,
                    format!("Invalid target for {rt} record"),
                ));
            }
        }
        _ => {}
    }
    Ok(())
}

async fn generate_zone(state: &AppState, domain_id: i64) -> Result<(), ApiError> {
    let Some(domain) = sqlx::query_scalar::<_, String>("SELECT name FROM domains WHERE id = ?")
        .bind(domain_id)
        .fetch_optional(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?
    else {
        return Ok(());
    };

    let rows = sqlx::query(
        "SELECT name, rtype, value, ttl, priority FROM dns_records \
         WHERE domain_id = ? AND status = 'active' ORDER BY rtype, name",
    )
    .bind(domain_id)
    .fetch_all(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?;

    if rows.is_empty() {
        let dir = provision::dns_dir();
        let path = dir.join(format!("{domain}.zone"));
        std::fs::remove_file(&path).ok();
        return Ok(());
    }

    let serial = chrono::Utc::now().format("%Y%m%d%H%M").to_string();
    let mut content = format!(
        "; FPanel generated DNS zone for {domain}\n$ORIGIN {domain}.\n$TTL 3600\n\
         @ IN SOA ns1.{domain}. hostmaster.{domain}. ( {serial} 7200 3600 1209600 300 )\n\
         @ IN NS ns1.{domain}.\n"
    );
    for r in &rows {
        let name: String = r.get(0);
        let rtype: String = r.get(1);
        let value: String = r.get(2);
        let ttl: i64 = r.get(3);
        let priority: Option<i64> = r.get(4);
        let label = if name == "@" { "@" } else { &name };
        match rtype.as_str() {
            "MX" | "SRV" => {
                content.push_str(&format!("{label} {ttl} IN {rtype} {} {value}\n", priority.unwrap_or(10)));
            }
            "TXT" => {
                let escaped = value.replace('"', "\\\"");
                content.push_str(&format!("{label} {ttl} IN TXT \"{escaped}\"\n"));
            }
            _ => {
                content.push_str(&format!("{label} {ttl} IN {rtype} {value}\n"));
            }
        }
    }

    let dir = provision::dns_dir();
    std::fs::create_dir_all(&dir).map_err(|e| internal_error(anyhow::Error::new(e)))?;
    let path = dir.join(format!("{domain}.zone"));
    let tmp = dir.join(format!("{domain}.zone.tmp"));
    std::fs::write(&tmp, content).map_err(|e| internal_error(anyhow::Error::new(e)))?;
    std::fs::rename(&tmp, &path).map_err(|e| internal_error(anyhow::Error::new(e)))?;
    Ok(())
}