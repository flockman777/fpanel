use async_trait::async_trait;
use log::{info, warn};
use pingora_core::Result;
use pingora_core::server::Server;
use pingora_core::server::configuration::Opt;
use pingora_core::upstreams::peer::HttpPeer;
use pingora_http::ResponseHeader;
use pingora_proxy::{ProxyHttp, Session};
use serde::Deserialize;
use std::collections::HashMap;
use std::io::Write;
use std::path::PathBuf;
use std::process::Command;
use std::process::Stdio;
use std::sync::{Arc, RwLock};

#[derive(Debug, Clone, Deserialize)]
#[allow(dead_code)]
struct Vhost {
    domain: String,
    account: String,
    root: String,
}

#[derive(Debug, Clone, Deserialize)]
struct RedirectEntry {
    from: String,
    to: String,
    permanent: bool,
}

#[derive(Debug, Clone, Deserialize)]
#[allow(dead_code)]
struct RuntimeEntry {
    domain: String,
    target: String,
}

#[derive(Debug, Clone, Default, Deserialize)]
#[allow(dead_code)]
struct HotlinkCfg {
    enabled: bool,
    extensions: String,
    allow_empty: bool,
    #[serde(default)]
    allowed: Vec<String>,
}

#[derive(Debug, Clone, Default, Deserialize)]
#[allow(dead_code)]
struct WafCfg {
    enabled: bool,
    mode: String,
    #[serde(default)]
    patterns: Vec<String>,
}

#[derive(Debug, Clone, Default, Deserialize)]
#[allow(dead_code)]
struct SecurityCfg {
    domain: String,
    #[serde(default)]
    blocked_ips: Vec<String>,
    #[serde(default)]
    hotlink: Option<HotlinkCfg>,
    #[serde(default)]
    waf: Option<WafCfg>,
}

struct Vhosts {
    map: Arc<RwLock<HashMap<String, Vhost>>>,
    redirects: Arc<RwLock<HashMap<String, Vec<RedirectEntry>>>>,
    runtimes: Arc<RwLock<HashMap<String, String>>>,
    security: Arc<RwLock<HashMap<String, SecurityCfg>>>,
    mtime: Arc<RwLock<std::time::SystemTime>>,
}

struct FS {
    vhosts: Vhosts,
    dir: PathBuf,
    log_dir: PathBuf,
}

struct Ctx {
    upstream: Option<HttpPeer>,
}

fn vhosts_dir() -> PathBuf {
    std::env::var("FPANEL_VHOSTS")
        .map(PathBuf::from)
        .unwrap_or_else(|_| PathBuf::from(env!("CARGO_MANIFEST_DIR")).join("../panel/vhosts"))
}

fn logs_dir() -> PathBuf {
    std::env::var("FPANEL_LOGS")
        .map(PathBuf::from)
        .unwrap_or_else(|_| PathBuf::from(env!("CARGO_MANIFEST_DIR")).join("../panel/data/logs"))
}

fn access_line(log_dir: &std::path::Path, host: &str, ip: &str, method: &str, uri: &str, status: u16, bytes: u64) {
    use std::io::Write;
    let ts = chrono::Utc::now().format("%Y-%m-%dT%H:%M:%SZ");
    let line = format!("{ts} {ip} \"{method} {uri} HTTP/1.1\" {status} {bytes}\n");
    let _ = std::fs::create_dir_all(log_dir);
    let path = log_dir.join(format!("{host}.access.log"));
    if let Ok(mut f) = std::fs::OpenOptions::new().create(true).append(true).open(&path) {
        let _ = f.write_all(line.as_bytes());
    }
}

fn error_line(log_dir: &std::path::Path, host: &str, msg: &str) {
    use std::io::Write;
    let ts = chrono::Utc::now().format("%Y-%m-%dT%H:%M:%SZ");
    let line = format!("[{ts}] {msg}\n");
    let _ = std::fs::create_dir_all(log_dir);
    let path = log_dir.join(format!("{host}.error.log"));
    if let Ok(mut f) = std::fs::OpenOptions::new().create(true).append(true).open(&path) {
        let _ = f.write_all(line.as_bytes());
    }
}

fn load(dir: &std::path::Path) -> HashMap<String, Vhost> {
    let mut map = HashMap::new();
    let Ok(entries) = std::fs::read_dir(dir) else {
        return map;
    };
    for entry in entries.flatten() {
        let path = entry.path();
        let fname = path.file_name().and_then(|n| n.to_str()).unwrap_or("");
        if path.extension().and_then(|e| e.to_str()) != Some("json") || fname.ends_with(".redirects.json") {
            continue;
        }
        if let Ok(raw) = std::fs::read_to_string(&path) {
            if let Ok(v) = serde_json::from_str::<Vhost>(&raw) {
                map.insert(v.domain.to_lowercase(), v);
            }
        }
    }
    map
}

fn load_redirects(dir: &std::path::Path) -> HashMap<String, Vec<RedirectEntry>> {
    let mut map = HashMap::new();
    let Ok(entries) = std::fs::read_dir(dir) else {
        return map;
    };
    for entry in entries.flatten() {
        let path = entry.path();
        let name = match path.file_name().and_then(|n| n.to_str()) {
            Some(n) if n.ends_with(".redirects.json") => n.trim_end_matches(".redirects.json"),
            _ => continue,
        };
        if let Ok(raw) = std::fs::read_to_string(&path) {
            if let Ok(r) = serde_json::from_str::<Vec<RedirectEntry>>(&raw) {
                map.insert(name.to_lowercase(), r);
            }
        }
    }
    map
}

fn load_runtimes(dir: &std::path::Path) -> HashMap<String, String> {
    let mut map = HashMap::new();
    let Ok(entries) = std::fs::read_dir(dir) else {
        return map;
    };
    for entry in entries.flatten() {
        let path = entry.path();
        let name = match path.file_name().and_then(|n| n.to_str()) {
            Some(n) if n.ends_with(".runtime.json") => n.trim_end_matches(".runtime.json"),
            _ => continue,
        };
        if let Ok(raw) = std::fs::read_to_string(&path) {
            if let Ok(r) = serde_json::from_str::<RuntimeEntry>(&raw) {
                map.insert(name.to_lowercase(), r.target);
            }
        }
    }
    map
}

fn load_security(dir: &std::path::Path) -> HashMap<String, SecurityCfg> {
    let mut map = HashMap::new();
    let Ok(entries) = std::fs::read_dir(dir) else {
        return map;
    };
    for entry in entries.flatten() {
        let path = entry.path();
        let name = match path.file_name().and_then(|n| n.to_str()) {
            Some(n) if n.ends_with(".security.json") => n.trim_end_matches(".security.json"),
            _ => continue,
        };
        if let Ok(raw) = std::fs::read_to_string(&path) {
            if let Ok(s) = serde_json::from_str::<SecurityCfg>(&raw) {
                map.insert(name.to_lowercase(), s);
            }
        }
    }
    map
}

fn ip_in_block(ip: &str, entries: &[String]) -> bool {
    for e in entries {
        let e = e.trim();
        if e.is_empty() {
            continue;
        }
        if let Ok(addr) = e.parse::<std::net::IpAddr>() {
            if let Ok(client) = ip.parse::<std::net::IpAddr>() {
                if addr == client {
                    return true;
                }
            }
            continue;
        }
        if let Some((net, cidr)) = e.split_once('/') {
            if let (Ok(net_ip), Ok(prefix)) = (
                net.parse::<std::net::IpAddr>(),
                cidr.parse::<u8>(),
            ) {
                if let Ok(client) = ip.parse::<std::net::IpAddr>() {
                    match (net_ip, client) {
                        (std::net::IpAddr::V4(n), std::net::IpAddr::V4(c)) => {
                            let mask = if prefix >= 32 { u32::MAX } else { u32::MAX << (32 - prefix) };
                            if (u32::from(n) & mask) == (u32::from(c) & mask) {
                                return true;
                            }
                        }
                        (std::net::IpAddr::V6(n), std::net::IpAddr::V6(c)) => {
                            let mask = if prefix >= 128 { u128::MAX } else { u128::MAX << (128 - prefix) };
                            if (u128::from(n) & mask) == (u128::from(c) & mask) {
                                return true;
                            }
                        }
                        _ => {}
                    }
                }
            }
        }
    }
    false
}

fn sync_if_changed(vhosts: &Vhosts, dir: &std::path::Path) {
    let Ok(meta) = std::fs::metadata(dir) else {
        return;
    };
    let now = meta
        .modified()
        .unwrap_or(std::time::SystemTime::UNIX_EPOCH);
    let last = *vhosts.mtime.read().unwrap();
    if now != last {
        let map = load(dir);
        *vhosts.map.write().unwrap() = map;
        let redirs = load_redirects(dir);
        *vhosts.redirects.write().unwrap() = redirs;
        let rt = load_runtimes(dir);
        *vhosts.runtimes.write().unwrap() = rt;
        let sec = load_security(dir);
        *vhosts.security.write().unwrap() = sec;
        *vhosts.mtime.write().unwrap() = now;
        info!("reloaded {} vhosts", vhosts.map.read().unwrap().len());
    }
}

fn mime_for(path: &PathBuf) -> &'static str {
    match path.extension().and_then(|e| e.to_str()) {
        Some("html" | "htm") => "text/html; charset=utf-8",
        Some("css") => "text/css; charset=utf-8",
        Some("js" | "mjs") => "text/javascript; charset=utf-8",
        Some("json") => "application/json",
        Some("txt") => "text/plain; charset=utf-8",
        Some("xml") => "application/xml",
        Some("png") => "image/png",
        Some("jpg" | "jpeg") => "image/jpeg",
        Some("gif") => "image/gif",
        Some("svg") => "image/svg+xml",
        Some("webp") => "image/webp",
        Some("ico") => "image/x-icon",
        Some("pdf") => "application/pdf",
        Some("woff" | "woff2") => "application/font-woff",
        Some("ttf") => "font/ttf",
        Some("zip") => "application/zip",
        Some("tar" | "gz") => "application/gzip",
        _ => "application/octet-stream",
    }
}

fn safe_join(root: &std::path::Path, uri_path: &str) -> Option<PathBuf> {
    let decoded = percent_encoding::percent_decode_str(uri_path).decode_utf8_lossy();
    let mut base = root.to_path_buf();
    for comp in decoded.split('/') {
        if comp.is_empty() || comp == "." {
            continue;
        }
        if comp == ".." || comp.contains('\\') {
            return None;
        }
        base.push(comp);
    }
    Some(base)
}

fn resolve(root: &std::path::Path, uri_path: &str) -> Option<PathBuf> {
    let candidate = safe_join(root, uri_path)?;
    let mut out = candidate.clone();
    if candidate.is_dir() {
        for index in ["index.html", "index.htm", "index.php"] {
            let idx = candidate.join(index);
            if idx.is_file() {
                out = idx;
                break;
            }
        }
        if out == candidate {
            return None;
        }
    }
    if !out.is_file() {
        return None;
    }
    Some(out)
}

fn is_php(path: &std::path::Path) -> bool {
    path.extension().and_then(|e| e.to_str()) == Some("php")
}

struct PhpOut {
    status: u16,
    location: Option<String>,
    content_type: Option<String>,
    body: Vec<u8>,
}

fn run_php_cgi(
    root: std::path::PathBuf,
    file: std::path::PathBuf,
    host: String,
    uri: String,
    query: String,
    method: String,
    body: Option<Vec<u8>>,
    content_type: Option<String>,
    log_dir: std::path::PathBuf,
) -> Option<PhpOut> {
    let script = uri.split('?').next().unwrap_or("").to_string();
    let mut cmd = Command::new("php-cgi");
    cmd.arg("-q")
        .arg(&file)
        .current_dir(&root)
        .stdin(Stdio::piped())
        .stdout(Stdio::piped())
        .stderr(Stdio::piped())
        .env("REDIRECT_STATUS", "1")
        .env("SCRIPT_FILENAME", &file)
        .env("SCRIPT_NAME", &script)
        .env("DOCUMENT_ROOT", &root)
        .env("SERVER_NAME", &host)
        .env("SERVER_ADDR", "127.0.0.1")
        .env("SERVER_PORT", "8080")
        .env("SERVER_PROTOCOL", "HTTP/1.1")
        .env("REQUEST_METHOD", &method)
        .env("REQUEST_URI", &uri)
        .env("QUERY_STRING", &query)
        .env("HTTP_HOST", &host)
        .env("REMOTE_ADDR", "127.0.0.1");
    if let Some(ct) = &content_type {
        cmd.env("CONTENT_TYPE", ct).env("HTTP_CONTENT_TYPE", ct);
    }
    let mut child = cmd.spawn().ok()?;
    if let Some(b) = body {
        if let Some(mut stdin) = child.stdin.take() {
            let _ = stdin.write_all(&b);
        }
    }
    let out = child.wait_with_output().ok()?;
    if !out.stderr.is_empty() {
        let msg = String::from_utf8_lossy(&out.stderr);
        for line in msg.lines() {
            error_line(&log_dir, &host, line);
        }
    }
    let mut raw = out.stdout;
    let head_end = raw
        .windows(4)
        .position(|w| w == b"\r\n\r\n")
        .map(|i| i + 4)
        .or_else(|| raw.windows(2).position(|w| w == b"\n\n").map(|i| i + 2))
        .unwrap_or(0);
    let head = String::from_utf8_lossy(&raw[..head_end.min(raw.len())]).into_owned();
    let mut status = 200u16;
    let mut location: Option<String> = None;
    let mut ctype: Option<String> = None;
    for line in head.lines() {
        let l = line.trim_end_matches('\r');
        if let Some(rest) = l.strip_prefix("Status:") {
            if let Some(code) = rest.trim().split(' ').next().and_then(|c| c.parse::<u16>().ok()) {
                status = code;
            }
        } else if let Some(rest) = l.strip_prefix("Location:") {
            location = Some(rest.trim().to_string());
        } else if let Some(rest) = l.strip_prefix("Content-type:") {
            ctype = Some(rest.trim().to_string());
        }
    }
    raw.drain(..head_end);
    Some(PhpOut { status, location, content_type: ctype, body: raw })
}

async fn send_status(session: &mut Session, status: u16, body: &str) -> Result<bool> {
    let mut resp = ResponseHeader::build(status, None).unwrap();
    resp.insert_header("Content-Type", "text/html; charset=utf-8")
        .unwrap();
    if body.is_empty() {
        session.write_response_header_ref(&resp, true).await?;
    } else {
        session.write_response_header_ref(&resp, false).await?;
        session
            .write_response_body(Some(bytes::Bytes::copy_from_slice(body.as_bytes())), true)
            .await?;
    }
    Ok(true)
}

#[async_trait]
impl ProxyHttp for FS {
    type CTX = Ctx;

    fn new_ctx(&self) -> Self::CTX {
        Ctx { upstream: None }
    }

    async fn request_filter(&self, session: &mut Session, ctx: &mut Self::CTX) -> Result<bool> {
        sync_if_changed(&self.vhosts, &self.dir);

        let host = session
            .req_header()
            .headers
            .get("host")
            .and_then(|h| h.to_str().ok())
            .unwrap_or("")
            .split(':')
            .next()
            .unwrap_or("")
            .trim()
            .to_lowercase();

        let uri_path = session.req_header().uri.path().to_string();
        if uri_path.is_empty() {
            return Ok(true);
        }

        let client_ip = session
            .client_addr()
            .and_then(|a| a.as_inet())
            .map(|a| a.ip().to_string())
            .unwrap_or_default();
        let method = session.req_header().method.as_str().to_string();

        let vhost = {
            let map = self.vhosts.map.read().unwrap();
            map.get(&host).cloned()
        };

        let Some(vhost) = vhost else {
            access_line(&self.log_dir, &host, &client_ip, &method, &uri_path, 404, 0);
            return send_status(session, 404, &format!(
                "<h1>404 Not Found</h1><p>No vhost configured for <b>{}</b></p>",
                host
            ))
            .await;
        };

        let sec = {
            let m = self.vhosts.security.read().unwrap();
            m.get(&host).cloned()
        };
        if let Some(sec) = sec {
            if !sec.blocked_ips.is_empty() && ip_in_block(&client_ip, &sec.blocked_ips) {
                info!("blocked {} (ip_blocker)", client_ip);
                access_line(&self.log_dir, &host, &client_ip, &method, &uri_path, 403, 0);
                return send_status(session, 403, "<h1>403 Forbidden</h1><p>Your IP address is blocked on this domain.</p>")
                    .await;
            }
            if let Some(hl) = &sec.hotlink {
                if hl.enabled {
                    let method = session.req_header().method.as_str();
                    if method == "GET" || method == "HEAD" {
                        let path = session.req_header().uri.path().to_string();
                        let ext = path.rsplit('.').next().unwrap_or("").to_lowercase();
                        if !ext.is_empty() && hl.extensions.split('|').any(|e| e.eq_ignore_ascii_case(&ext)) {
                            let referer = session
                                .req_header()
                                .headers
                                .get("referer")
                                .and_then(|v| v.to_str().ok())
                                .unwrap_or("")
                                .to_string();
                            if !referer.is_empty() || !hl.allow_empty {
                                let ok = if referer.is_empty() {
                                    hl.allow_empty
                                } else {
                                    let host_part = referer
                                        .trim_start_matches("https://")
                                        .trim_start_matches("http://")
                                        .split('/')
                                        .next()
                                        .unwrap_or("")
                                        .split(':')
                                        .next()
                                        .unwrap_or("");
                                    hl.allowed.iter().any(|a| a.eq_ignore_ascii_case(host_part))
                                        || (!referer.is_empty() && host_part.eq_ignore_ascii_case(&sec.domain))
                                };
                                if !ok {
                                    info!("blocked hotlink on {} for {}", sec.domain, ext);
                                    access_line(&self.log_dir, &host, &client_ip, &method, &uri_path, 403, 0);
                                    return send_status(session, 403, "<h1>403 Forbidden</h1><p>Hotlink protection is enabled on this domain.</p>")
                                        .await;
                                }
                            }
                        }
                    }
                }
            }
            if let Some(waf) = &sec.waf {
                if waf.enabled && !waf.patterns.is_empty() {
                    let decoded_uri = percent_encoding::percent_decode_str(
                        session.req_header().uri.path(),
                    )
                    .decode_utf8_lossy()
                    .replace('+', " ");
                    let query = percent_encoding::percent_decode_str(
                        session.req_header().uri.query().unwrap_or(""),
                    )
                    .decode_utf8_lossy()
                    .replace('+', " ")
                    .to_string();
                    for pat in &waf.patterns {
                        let hit = std::panic::catch_unwind(|| {
                            regex::Regex::new(pat)
                                .map(|re| {
                                    re.is_match(&decoded_uri) || (!query.is_empty() && re.is_match(&query))
                                })
                                .unwrap_or(false)
                        })
                        .unwrap_or(false);
                        if hit {
                            if waf.mode == "log" {
                                warn!("WAF (log) matched on {}: {}", sec.domain, pat);
                                break;
                            } else {
                                info!("WAF blocked on {}: {}", sec.domain, pat);
                                access_line(&self.log_dir, &host, &client_ip, &method, &uri_path, 403, 0);
                                return send_status(session, 403, "<h1>403 Forbidden</h1><p>The request was blocked by the application firewall.</p>")
                                    .await;
                            }
                        }
                    }
                }
            }
        }

        let decoded = percent_encoding::percent_decode_str(&uri_path)
            .decode_utf8_lossy()
            .to_string();
        let rules = {
            let rr = self.vhosts.redirects.read().unwrap();
            rr.get(&host).cloned().unwrap_or_default()
        };
        let hit = rules.iter().find(|r| r.from == decoded || (r.from == "/" && (decoded == "/" || decoded.is_empty())));
        if let Some(r) = hit {
            let status = if r.permanent { 301 } else { 302 };
            let mut resp = ResponseHeader::build(status, None).unwrap();
            resp.insert_header("Location", r.to.as_str()).unwrap();
            resp.insert_header("X-FPanel-Vhost", &vhost.domain).unwrap();
            access_line(&self.log_dir, &host, &client_ip, &method, &uri_path, status, 0);
            session.write_response_header_ref(&resp, true).await?;
            return Ok(true);
        }

        if let Some(target) = {
            let rt = self.vhosts.runtimes.read().unwrap();
            rt.get(&host).cloned()
        } {
            ctx.upstream = Some(HttpPeer::new(target.as_str(), false, String::new()));
            info!("proxy {} -> {}", host, target);
            access_line(&self.log_dir, &host, &client_ip, &method, &uri_path, 200, 0);
            return Ok(false);
        }

        let root = std::path::PathBuf::from(&vhost.root);
        match resolve(&root, &uri_path) {
            Some(file) => {
                if is_php(&file) && (method == "GET" || method == "POST") {
                    let mime = "text/html; charset=utf-8";
                    let body = if method == "POST" {
                        session.read_request_body().await.ok().flatten().map(|b| b.to_vec())
                    } else {
                        None
                    };
                    let content_type = session
                        .req_header()
                        .headers
                        .get("content-type")
                        .and_then(|h| h.to_str().ok())
                        .map(|s| s.to_string());
                    let block_root = root.clone();
                    let block_file = file.clone();
                    let block_host = host.clone();
                    let block_uri = decoded.clone();
                    let block_query = uri_path.split('?').nth(1).unwrap_or("").to_string();
                    let block_method = method.clone();
                    let block_body = body.clone();
                    let block_ct = content_type.clone();
                    let block_log = self.log_dir.clone();
                    let result = tokio::task::spawn_blocking(move || {
                        run_php_cgi(block_root, block_file, block_host, block_uri, block_query, block_method, block_body, block_ct, block_log)
                    })
                    .await
                    .ok()
                    .flatten()
                    .unwrap_or(PhpOut {
                        status: 500,
                        location: None,
                        content_type: None,
                        body: Vec::new(),
                    });
                    let status = result.status;
                    let body = result.body;
                    let mime = result.content_type.as_deref().unwrap_or(mime);
                    access_line(&self.log_dir, &host, &client_ip, &method, &uri_path, status, body.len() as u64);
                    let mut resp = ResponseHeader::build(status, None).unwrap();
                    resp.insert_header("Content-Type", mime).unwrap();
                    if let Some(loc) = result.location {
                        resp.insert_header("Location", loc.as_str()).unwrap();
                    }
                    resp.insert_header("X-FPanel-Vhost", &vhost.domain).unwrap();
                    session.write_response_header_ref(&resp, false).await?;
                    if !body.is_empty() {
                        session
                            .write_response_body(
                                Some(bytes::Bytes::from(body)),
                                true,
                            )
                            .await?;
                    } else {
                        session.write_response_body(None, true).await?;
                    }
                    return Ok(true);
                }
                let mime = mime_for(&file);
                let body = match std::fs::read(&file) {
                    Ok(b) => b,
                    Err(_) => {
                        warn!("read failed: {}", file.display());
                        access_line(&self.log_dir, &host, &client_ip, &method, &uri_path, 500, 0);
                        return send_status(session, 500, "<h1>500 Internal Server Error</h1>").await;
                    }
                };
                access_line(&self.log_dir, &host, &client_ip, &method, &uri_path, 200, body.len() as u64);
                let mut resp = ResponseHeader::build(200, None).unwrap();
                resp.insert_header("Content-Type", mime).unwrap();
                resp.insert_header("X-FPanel-Vhost", &vhost.domain).unwrap();
                session.write_response_header_ref(&resp, false).await?;
                if !body.is_empty() {
                    session
                        .write_response_body(Some(bytes::Bytes::from(body)), true)
                        .await?;
                } else {
                    session.write_response_body(None, true).await?;
                }
            }
            None => {
                access_line(&self.log_dir, &host, &client_ip, &method, &uri_path, 404, 0);
                return send_status(session, 404, &format!(
                    "<h1>404 Not Found</h1><p><b>{}</b> not found under <b>{}</b></p>",
                    uri_path, vhost.root
                ))
                .await;
            }
        }
        Ok(true)
    }

    async fn upstream_peer(
        &self,
        _session: &mut Session,
        ctx: &mut Self::CTX,
    ) -> Result<Box<HttpPeer>> {
        Ok(Box::new(
            ctx.upstream
                .take()
                .unwrap_or_else(|| HttpPeer::new("127.0.0.1:9", false, String::new())),
        ))
    }
}

fn main() {
    env_logger::init();
    let opt = Opt::parse_args();
    let mut my_server = Server::new(Some(opt)).unwrap();
    my_server.bootstrap();

    let dir = vhosts_dir();
    let map = load(&dir);
    info!("loaded {} vhosts from {}", map.len(), dir.display());
    let vhosts = Vhosts {
        map: Arc::new(RwLock::new(map)),
        redirects: Arc::new(RwLock::new(load_redirects(&dir))),
        runtimes: Arc::new(RwLock::new(load_runtimes(&dir))),
        security: Arc::new(RwLock::new(load_security(&dir))),
        mtime: Arc::new(RwLock::new(std::time::SystemTime::UNIX_EPOCH)),
    };

    let mut svc = pingora_proxy::http_proxy_service(&my_server.configuration, FS { vhosts, dir, log_dir: logs_dir() });
    svc.add_tcp("0.0.0.0:8080");
    my_server.add_service(svc);
    info!("FPanel web server (Pingora) on 0.0.0.0:8080");
    my_server.run_forever();
}