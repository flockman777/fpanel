use std::str::FromStr;

use axum::extract::{Query, State};
use axum::routing::get;
use axum::{Json, Router};
use serde::{Deserialize, Serialize};
use sqlx::sqlite::{SqliteConnectOptions, SqlitePoolOptions};
use sqlx::Row;

use crate::db::AppState;
use crate::error::{internal_error, ApiError};

#[derive(Debug, Serialize)]
pub struct TrackingDelivery {
    pub token: String,
    pub msgid: String,
    pub from_addr: String,
    pub to_addr: String,
    pub subject: String,
    pub ts: String,
    pub html: bool,
    pub opens: i64,
    pub clicks: i64,
    pub first_open: Option<String>,
    pub first_click: Option<String>,
}

#[derive(Debug, Deserialize)]
struct ListQuery {
    limit: Option<i64>,
}

pub fn router() -> Router<AppState> {
    Router::new().route("/", get(list))
}

async fn track_pool() -> Result<sqlx::SqlitePool, ApiError> {
    let opts = SqliteConnectOptions::from_str("sqlite:///var/log/mailtrack.db")
        .map_err(|e| internal_error(e.into()))?
        .read_only(true);
    SqlitePoolOptions::new()
        .max_connections(1)
        .connect_with(opts)
        .await
        .map_err(|e| internal_error(e.into()))
}

async fn list(
    State(_state): State<AppState>,
    Query(q): Query<ListQuery>,
) -> Result<Json<Vec<TrackingDelivery>>, ApiError> {
    let pool = track_pool().await?;
    let limit = q.limit.unwrap_or(100).min(500);

    let rows = sqlx::query(
        "SELECT d.token, d.msgid, d.from_addr, d.to_addr, d.subject, d.ts, d.html, \
                COALESCE((SELECT COUNT(*) FROM opens o WHERE o.token = d.token), 0), \
                COALESCE((SELECT COUNT(*) FROM clicks c WHERE c.token = d.token), 0), \
                (SELECT MIN(ts) FROM opens o2 WHERE o2.token = d.token), \
                (SELECT MIN(ts) FROM clicks c2 WHERE c2.token = d.token) \
         FROM deliveries d ORDER BY d.rowid DESC LIMIT ?",
    )
    .bind(limit)
    .fetch_all(&pool)
    .await
    .map_err(|e| internal_error(e.into()))?;

    let mut out = Vec::with_capacity(rows.len());
    for r in rows {
        out.push(TrackingDelivery {
            token: r.get(0),
            msgid: r.get(1),
            from_addr: r.get(2),
            to_addr: r.get(3),
            subject: r.get(4),
            ts: r.get(5),
            html: r.get::<i64, _>(6) != 0,
            opens: r.get(7),
            clicks: r.get(8),
            first_open: r.get(9),
            first_click: r.get(10),
        });
    }
    Ok(Json(out))
}