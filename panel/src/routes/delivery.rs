use std::collections::HashMap;
use std::sync::OnceLock;

use axum::extract::{Query, State};
use axum::routing::get;
use axum::{Json, Router};
use regex::Regex;
use serde::{Deserialize, Serialize};
use sqlx::Row;

use crate::db::AppState;
use crate::error::{internal_error, ApiError};

#[derive(Debug, Serialize)]
pub struct DeliveryEvent {
    pub ts: String,
    pub qid: String,
    pub from_addr: String,
    pub to_addr: String,
    pub relay: String,
    pub status: String,
    pub detail: String,
}

#[derive(Debug, Deserialize)]
struct ListQuery {
    status: Option<String>,
    limit: Option<i64>,
}

fn re_queue() -> &'static Regex {
    static RE: OnceLock<Regex> = OnceLock::new();
    RE.get_or_init(|| {
        Regex::new(
            r"(?P<ts>(?:[A-Z][a-z]{2} \d{1,2} \d{2}:\d{2}:\d{2})|\S+) (?P<host>\S+) postfix/(?:qmgr|cleanup)\[\d+\]: (?P<qid>[0-9A-F]+): from=<(?P<from>[^>]*)>",
        )
        .unwrap()
    })
}

fn re_deliv() -> &'static Regex {
    static RE: OnceLock<Regex> = OnceLock::new();
    RE.get_or_init(|| {
        Regex::new(
            r"(?P<ts>(?:[A-Z][a-z]{2} \d{1,2} \d{2}:\d{2}:\d{2})|\S+) (?P<host>\S+) postfix/(?:smtp|smtp_out|smtp_in|lmtp|pipe|local)\[\d+\]: (?P<qid>[0-9A-F]+): to=<(?P<to>[^>]*)>, relay=(?P<relay>[^,]*), .*?status=(?P<status>[a-z]+)(?: \((?P<detail>[^)]*)\))?",
        )
        .unwrap()
    })
}

pub fn router() -> Router<AppState> {
    Router::new().route("/", get(list))
}

fn mail_log_path() -> std::path::PathBuf {
    std::path::PathBuf::from("/var/log/mail.log")
}

async fn sync_from_file(state: &AppState) {
    let path = mail_log_path();
    let text = match std::fs::read_to_string(&path) {
        Ok(t) => t,
        Err(_) => return,
    };

    for line in text.lines().skip(text.lines().count().saturating_sub(20000)) {
        if !line.contains("postfix") {
            continue;
        }
        if let Some(caps) = re_deliv().captures(line) {
            let st: &str = &caps["status"];
            if !matches!(st, "sent" | "bounced" | "deferred" | "expired") {
                continue;
            }
            let ts = caps["ts"].trim();
            let qid = caps["qid"].trim();
            let to = caps["to"].trim();
            let relay = caps["relay"].trim();
            let detail = caps
                .name("detail")
                .map(|m| m.as_str().trim())
                .unwrap_or_default();
            let _ = sqlx::query(
                "INSERT OR IGNORE INTO mail_log (ts, qid, kind, from_addr, to_addr, relay, status, detail) \
                 VALUES (?, ?, 'delivery', '', ?, ?, ?, ?)",
            )
            .bind(ts)
            .bind(qid)
            .bind(to)
            .bind(relay)
            .bind(st)
            .bind(detail)
            .execute(&state.db)
            .await;
        } else if let Some(caps) = re_queue().captures(line) {
            let _ = sqlx::query(
                "INSERT OR IGNORE INTO mail_log (ts, qid, kind, from_addr) VALUES (?, ?, 'queue', ?)",
            )
            .bind(caps["ts"].trim())
            .bind(caps["qid"].trim())
            .bind(caps["from"].trim())
            .execute(&state.db)
            .await;
        }
    }
}

async fn list(
    State(state): State<AppState>,
    Query(q): Query<ListQuery>,
) -> Result<Json<Vec<DeliveryEvent>>, ApiError> {
    sync_from_file(&state).await;

    let limit = q.limit.unwrap_or(100).min(500);
    let rows = if let Some(st) = &q.status {
        sqlx::query(
            "SELECT ts, qid, from_addr, to_addr, relay, status, detail FROM mail_log \
             WHERE kind = 'delivery' AND status = ? ORDER BY ts DESC LIMIT ?",
        )
        .bind(st)
        .bind(limit)
        .fetch_all(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?
    } else {
        sqlx::query(
            "SELECT ts, qid, from_addr, to_addr, relay, status, detail FROM mail_log \
             WHERE kind = 'delivery' ORDER BY ts DESC LIMIT ?",
        )
        .bind(limit)
        .fetch_all(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?
    };

    let mut froms: HashMap<String, String> = HashMap::new();
    if let Ok(qrows) = sqlx::query("SELECT qid, from_addr FROM mail_log WHERE kind = 'queue'")
        .fetch_all(&state.db)
        .await
    {
        for r in qrows {
            froms.insert(r.get(0), r.get(1));
        }
    }

    let mut out = Vec::with_capacity(rows.len());
    for r in rows {
        let qid: String = r.get(1);
        out.push(DeliveryEvent {
            ts: r.get(0),
            qid: qid.clone(),
            from_addr: froms.get(&qid).cloned().unwrap_or_default(),
            to_addr: r.get(3),
            relay: r.get(4),
            status: r.get(5),
            detail: r.get(6),
        });
    }
    Ok(Json(out))
}