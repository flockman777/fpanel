use axum::http::StatusCode;
use serde_json::Value;
use sqlx::Row;

use crate::db::AppState;
use crate::error::{internal_error, ApiError};
use crate::provision;

pub const BUILTIN_WAF_PATTERNS: [(&str, &str); 10] = [
    ("sqli", r"(?i)(union\s+select|select\s+.*\s+from|insert\s+into|update\s+.*\s+set|drop\s+table|--|/\*|\b(or|and)\s+\d+=\d+)"),
    ("xss", r"(?i)(<script|javascript:|onerror\s*=|onload\s*=|<iframe|<object|<embed)"),
    ("lfi", r"(?i)(\.\./|\.\.\\|/etc/passwd|/etc/shadow|windows\\win\.ini|php://filter)"),
    ("rfi", r"(?i)(https?://\S+\.(php|txt|log))"),
    ("cmdi", r"(?i)(;\s*(cat|ls|wget|curl|nc|bash|sh|pwd)\b|\|\s*(cat|ls|sh)\b|`[^`]+`)"),
    ("phpi", r"(?i)(\b(move_uploaded_file|eval|assert|system|passthru|shell_exec)\s*\()"),
    ("cve", r"(?i)(\.\./\.\./\.\./|/\.\.|\.\./)"),
    ("scanner", r"(?i)((acunetix|nessus|nikto|sqlmap|burp|zap)\b)"),
    ("traversal", r"(?i)(%2e%2e%2f|%252e|.\{2\}\/)"),
    ("dotenv", r"(?i)(\.env\b|wp-config\.php|config\.inc\.php)"),
];

pub fn default_waf_rules_json() -> String {
    let rules: Vec<Value> = BUILTIN_WAF_PATTERNS
        .iter()
        .map(|(id, pat)| {
            serde_json::json!({
                "id": *id,
                "name": *id,
                "pattern": *pat,
                "severity": "medium",
                "action": "block",
            })
        })
        .collect();
    serde_json::to_string(&rules).unwrap_or_else(|_| "[]".to_string())
}

pub async fn refresh_security(state: &AppState, aid: i64, domain_id: i64, name: &str) -> Result<(), ApiError> {
    let global: Vec<String> = sqlx::query_scalar(
        "SELECT ip FROM ip_blocker WHERE account_id = ? AND domain_id IS NULL",
    )
    .bind(aid)
    .fetch_all(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?;

    let owned: Vec<String> = sqlx::query_scalar("SELECT ip FROM ip_blocker WHERE domain_id = ?")
        .bind(domain_id)
        .fetch_all(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?;

    let mut ips: Vec<String> = global;
    for ip in owned {
        if !ips.contains(&ip) {
            ips.push(ip);
        }
    }

    let hotlink = sqlx::query(
        "SELECT extensions, allow_empty, allowed_domains, status FROM hotlink WHERE domain_id = ?",
    )
    .bind(domain_id)
    .fetch_optional(&state.db)
    .await
    .map_err(|e| internal_error(e.into()))?
    .map(|r| provision::HotlinkCfg {
        enabled: r.get::<i64, _>(3) != 0,
        extensions: r.get::<String, _>(0),
        allow_empty: r.get::<i64, _>(1) != 0,
        allowed: r
            .get::<Option<String>, _>(2)
            .map(|s| {
                s.split(|c: char| c == ',' || c == ' ' || c == '\n')
                    .map(|x| x.trim().trim_start_matches("http://").trim_start_matches("https://").trim_end_matches('/').to_string())
                    .filter(|x| !x.is_empty())
                    .collect()
            })
            .unwrap_or_default(),
    });

    let waf = sqlx::query("SELECT enabled, mode, rules FROM waf_rules WHERE domain_id = ?")
        .bind(domain_id)
        .fetch_optional(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?
        .map(|r| {
            let enabled = r.get::<i64, _>(0) != 0;
            let mode = r.get::<String, _>(1);
            let rules_raw: Option<String> = r.get(2);
            let rules: Vec<Value> = rules_raw
                .as_ref()
                .and_then(|s| serde_json::from_str::<Vec<Value>>(s).ok())
                .unwrap_or_default();
            let patterns: Vec<String> = rules
                .iter()
                .filter_map(|rule| {
                    let act = rule.get("action").and_then(|v| v.as_str()).unwrap_or("block");
                    if act == "block" {
                        rule.get("pattern").and_then(|v| v.as_str()).map(|p| p.to_string())
                    } else {
                        None
                    }
                })
                .collect();
            provision::WafCfg {
                enabled,
                mode: if mode.is_empty() { "block".to_string() } else { mode },
                patterns,
            }
        });

    if ips.is_empty() && hotlink.is_none() && waf.is_none() {
        provision::remove_security(name);
        return Ok(());
    }

    provision::write_security(
        name,
        &provision::SecurityProvision {
            domain: name.to_string(),
            blocked_ips: ips,
            hotlink,
            waf,
        },
    );
    Ok(())
}

pub fn valid_ip(ip: &str) -> Result<String, ApiError> {
    let s = ip.trim().to_string();
    if s.is_empty() {
        return Err(ApiError::new(StatusCode::BAD_REQUEST, "IP address is required"));
    }
    let mut ok = s.parse::<std::net::IpAddr>().is_ok();
    if let Some((ip_part, cidr)) = s.split_once('/') {
        ok = ip_part.parse::<std::net::IpAddr>().is_ok()
            && cidr.parse::<u8>().map(|n| n <= 32).unwrap_or(false);
    }
    if !ok {
        return Err(ApiError::new(
            StatusCode::BAD_REQUEST,
            format!("'{s}' is not a valid IP address or CIDR"),
        ));
    }
    Ok(s)
}