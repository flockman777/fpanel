use axum::extract::State;
use axum::http::StatusCode;
use axum::routing::{get, post};
use axum::{Json, Router};
use serde::{Deserialize, Serialize};
use std::collections::HashMap;
use tokio::io::{AsyncBufReadExt, AsyncReadExt, AsyncWriteExt, BufReader};
use tokio::net::TcpStream;

use crate::db::AppState;
use crate::error::{internal_error, ApiError};

fn addr() -> String {
    std::env::var("FPANEL_VALKEY_ADDR").unwrap_or_else(|_| "127.0.0.1:6379".to_string())
}

enum Resp {
    Simple(String),
    Integer(i64),
    Bulk(Vec<u8>),
    Array(Vec<Resp>),
}

struct Conn {
    reader: BufReader<TcpStream>,
}

impl Conn {
    async fn connect() -> Result<Self, ApiError> {
        let stream = TcpStream::connect(addr())
            .await
            .map_err(|_| ApiError::new(StatusCode::SERVICE_UNAVAILABLE, "Valkey is not reachable"))?;
        let _ = stream.set_nodelay(true);
        Ok(Self {
            reader: BufReader::new(stream),
        })
    }

    async fn send(&mut self, args: &[&[u8]]) -> Result<Resp, ApiError> {
        let mut buf = Vec::new();
        buf.extend_from_slice(format!("*{}\r\n", args.len()).as_bytes());
        for a in args {
            buf.extend_from_slice(format!("${}\r\n", a.len()).as_bytes());
            buf.extend_from_slice(a);
            buf.extend_from_slice(b"\r\n");
        }
        self.reader
            .get_mut()
            .write_all(&buf)
            .await
            .map_err(|e| internal_error(e.into()))?;
        self.read_resp().await
    }

    async fn read_resp(&mut self) -> Result<Resp, ApiError> {
        let mut stacks: Vec<Vec<Resp>> = Vec::new();
        let mut pending: Vec<i64> = Vec::new();
        loop {
            let mut line = String::new();
            self.reader
                .read_line(&mut line)
                .await
                .map_err(|e| internal_error(e.into()))?;
            let line = line.trim_end_matches(['\r', '\n']).to_string();
            if line.is_empty() {
                return Err(ApiError::new(
                    StatusCode::SERVICE_UNAVAILABLE,
                    "Valkey connection closed",
                ));
            }
            let b = line.as_bytes()[0];
            let atom = match b {
                b'+' => Ok(Some(Resp::Simple(line[1..].trim().to_string()))),
                b'-' => Err(ApiError::new(
                    StatusCode::BAD_GATEWAY,
                    format!("Valkey error: {}", line[1..].trim()),
                )),
                b':' => {
                    let n: i64 = line[1..]
                        .trim()
                        .parse::<i64>()
                        .map_err(|e: std::num::ParseIntError| internal_error(e.into()))?;
                    Ok(Some(Resp::Integer(n)))
                }
                b'$' => {
                    let len: i64 = line[1..]
                        .trim()
                        .parse::<i64>()
                        .map_err(|e: std::num::ParseIntError| internal_error(e.into()))?;
                    if len == -1 {
                        Ok(Some(Resp::Bulk(Vec::new())))
                    } else {
                        let mut data = vec![0u8; len as usize];
                        self.reader
                            .read_exact(&mut data)
                            .await
                            .map_err(|e| internal_error(e.into()))?;
                        let mut crlf = [0u8; 2];
                        self.reader
                            .read_exact(&mut crlf)
                            .await
                            .map_err(|e| internal_error(e.into()))?;
                        Ok(Some(Resp::Bulk(data)))
                    }
                }
                b'*' => {
                    let n: i64 = line[1..]
                        .trim()
                        .parse::<i64>()
                        .map_err(|e: std::num::ParseIntError| internal_error(e.into()))?;
                    if n == -1 {
                        Ok(Some(Resp::Array(Vec::new())))
                    } else {
                        pending.push(n);
                        stacks.push(Vec::with_capacity(n as usize));
                        Ok(None)
                    }
                }
                _ => Err(ApiError::new(
                    StatusCode::BAD_GATEWAY,
                    "Valkey: unexpected response",
                )),
            };
            let Some(item) = atom? else {
                continue;
            };
            if stacks.is_empty() {
                return Ok(item);
            }
            stacks.last_mut().unwrap().push(item);
            *pending.last_mut().unwrap() -= 1;
            loop {
                let done = pending
                    .last()
                    .map(|n| *n <= 0)
                    .unwrap_or(false);
                if !done {
                    break;
                }
                let arr = stacks.pop().unwrap();
                pending.pop();
                if stacks.is_empty() {
                    return Ok(Resp::Array(arr));
                }
                stacks.last_mut().unwrap().push(Resp::Array(arr));
                *pending.last_mut().unwrap() -= 1;
            }
        }
    }
}

#[derive(Debug, Serialize)]
pub struct CacheInfo {
    pub connected: bool,
    pub version: Option<String>,
    pub uptime_seconds: Option<i64>,
    pub used_memory: Option<i64>,
    pub used_memory_human: Option<String>,
    pub peak_memory_human: Option<String>,
    pub maxmemory: Option<i64>,
    pub maxmemory_human: Option<String>,
    pub maxmemory_policy: Option<String>,
    pub connected_clients: Option<i64>,
    pub total_connections: Option<i64>,
    pub total_commands: Option<i64>,
    pub total_keys: Option<i64>,
}

impl CacheInfo {
    fn offline() -> Self {
        Self {
            connected: false,
            version: None,
            uptime_seconds: None,
            used_memory: None,
            used_memory_human: None,
            peak_memory_human: None,
            maxmemory: None,
            maxmemory_human: None,
            maxmemory_policy: None,
            connected_clients: None,
            total_connections: None,
            total_commands: None,
            total_keys: None,
        }
    }
}

fn parse_info(bulk: &[u8]) -> HashMap<String, String> {
    let mut out = HashMap::new();
    let text = String::from_utf8_lossy(bulk);
    for line in text.lines() {
        if line.starts_with('#') || line.trim().is_empty() {
            continue;
        }
        if let Some((k, v)) = line.split_once(':') {
            out.insert(k.trim().to_string(), v.trim().to_string());
        }
    }
    out
}

pub async fn info() -> Result<Json<CacheInfo>, ApiError> {
    let offline = CacheInfo::offline();
    let mut conn = match Conn::connect().await {
        Ok(c) => c,
        Err(_) => return Ok(Json(offline)),
    };
    if conn.send(&[b"PING"]).await.is_err() {
        return Ok(Json(offline));
    }
    let total_keys = match conn.send(&[b"DBSIZE"]).await {
        Ok(Resp::Integer(n)) => Some(n),
        _ => None,
    };
    let map = match conn.send(&[b"INFO"]).await {
        Ok(Resp::Bulk(b)) => parse_info(&b),
        _ => HashMap::new(),
    };
    let get_s = |k: &str| map.get(k).cloned();
    let get_i = |k: &str| map.get(k).and_then(|v| v.parse::<i64>().ok());
    Ok(Json(CacheInfo {
        connected: true,
        version: get_s("valkey_version").or_else(|| get_s("redis_version")),
        uptime_seconds: get_i("uptime_in_seconds"),
        used_memory: get_i("used_memory"),
        used_memory_human: get_s("used_memory_human"),
        peak_memory_human: get_s("used_memory_peak_human"),
        maxmemory: get_i("maxmemory"),
        maxmemory_human: get_s("maxmemory_human"),
        maxmemory_policy: get_s("maxmemory_policy"),
        connected_clients: get_i("connected_clients"),
        total_connections: get_i("total_connections_received"),
        total_commands: get_i("total_commands_processed"),
        total_keys,
    }))
}

pub async fn flush() -> Result<Json<serde_json::Value>, ApiError> {
    let mut conn = Conn::connect().await?;
    let before = match conn.send(&[b"DBSIZE"]).await? {
        Resp::Integer(n) => n,
        _ => 0,
    };
    match conn.send(&[b"FLUSHALL"]).await? {
        Resp::Simple(s) if s.eq_ignore_ascii_case("OK") => Ok(Json(serde_json::json!({
            "ok": true,
            "flushed": before
        }))),
        _ => Err(ApiError::new(
            StatusCode::BAD_GATEWAY,
            "FLUSHALL failed",
        )),
    }
}

#[derive(Deserialize)]
pub struct MaxmemoryBody {
    pub mb: i64,
}

pub async fn set_maxmemory(
    State(_state): State<AppState>,
    Json(body): Json<MaxmemoryBody>,
) -> Result<Json<serde_json::Value>, ApiError> {
    if !(8..=2048).contains(&body.mb) {
        return Err(ApiError::new(
            StatusCode::BAD_REQUEST,
            "maxmemory must be between 8 and 2048 MB",
        ));
    }
    let bytes = body.mb * 1024 * 1024;
    let bs = bytes.to_string();
    let mut conn = Conn::connect().await?;
    match conn.send(&[b"CONFIG", b"SET", b"maxmemory", bs.as_bytes()]).await? {
        Resp::Simple(s) if s.eq_ignore_ascii_case("OK") => {}
        _ => {
            return Err(ApiError::new(
                StatusCode::BAD_GATEWAY,
                "Failed to set maxmemory",
            ))
        }
    }
    let current = match conn.send(&[b"CONFIG", b"GET", b"maxmemory"]).await {
        Ok(Resp::Array(a)) if a.len() >= 2 => match &a[1] {
            Resp::Bulk(b) => std::str::from_utf8(b).ok().and_then(|x| x.parse().ok()),
            _ => None,
        },
        _ => None,
    };
    Ok(Json(serde_json::json!({
        "ok": true,
        "maxmemory_bytes": current.unwrap_or(bytes),
        "maxmemory_mb": (current.unwrap_or(bytes)) / 1048576,
    })))
}

pub fn router() -> Router<AppState> {
    Router::new()
        .route("/info", get(info))
        .route("/flush", post(flush))
        .route("/maxmemory", post(set_maxmemory))
}

pub fn client_router() -> Router<AppState> {
    Router::new().route("/info", get(info))
}