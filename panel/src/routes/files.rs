use std::path::{Path, PathBuf};
use std::os::unix::fs::PermissionsExt;
use std::io::{Cursor, Read, Seek, Write};
use std::fs;

use axum::extract::{Multipart, Query, State};
use axum::http::{header, HeaderMap, StatusCode};
use axum::response::IntoResponse;
use axum::{Json, Router};
use serde::Deserialize;
use serde::Serialize;
use zip::write::SimpleFileOptions;
use zip::{CompressionMethod, ZipArchive, ZipWriter};

use crate::db::AppState;
use crate::error::{internal_error, ApiError};
use crate::provision;

#[derive(Debug, Deserialize)]
pub struct PathQ {
    pub path: Option<String>,
    pub account_id: Option<i64>,
}

#[derive(Debug, Deserialize)]
pub struct DownloadQ {
    #[serde(default)]
    pub path: Vec<String>,
    pub account_id: Option<i64>,
}

#[derive(Debug, Deserialize)]
pub struct WriteBody {
    pub path: String,
    pub content: String,
    pub account_id: Option<i64>,
}

#[derive(Debug, Deserialize)]
pub struct CreateBody {
    pub path: String,
    pub name: String,
    pub account_id: Option<i64>,
}

#[derive(Debug, Deserialize)]
pub struct MoveBody {
    pub from: String,
    pub to: String,
    pub account_id: Option<i64>,
}

#[derive(Debug, Deserialize)]
pub struct DeleteBody {
    pub path: Option<String>,
    pub paths: Option<Vec<String>>,
    pub account_id: Option<i64>,
}

#[derive(Debug, Deserialize)]
pub struct OnePathBody {
    pub path: String,
    pub account_id: Option<i64>,
}

#[derive(Debug, Deserialize)]
pub struct ChmodBody {
    pub path: String,
    pub mode: String,
    pub account_id: Option<i64>,
}

#[derive(Debug, Serialize)]
struct Entry {
    name: String,
    kind: String,
    size: u64,
    modified: String,
    perms: String,
}

#[derive(Debug, Serialize)]
struct ListResponse {
    path: String,
    parent: String,
    entries: Vec<Entry>,
}

#[derive(Debug, Serialize)]
struct UploadResult {
    uploaded: usize,
}

pub fn router() -> Router<AppState> {
    Router::new()
        .route("/list", axum::routing::get(list_admin))
        .route("/read", axum::routing::get(read_admin))
        .route("/download", axum::routing::get(download_admin))
        .route("/write", axum::routing::post(write_admin))
        .route("/create-dir", axum::routing::post(create_dir_admin))
        .route("/create-file", axum::routing::post(create_file_admin))
        .route("/rename", axum::routing::post(rename_admin))
        .route("/copy", axum::routing::post(copy_admin))
        .route("/delete", axum::routing::post(delete_admin))
        .route("/chmod", axum::routing::post(chmod_admin))
        .route("/upload", axum::routing::post(upload_admin))
        .route("/compress", axum::routing::post(compress_admin))
        .route("/extract", axum::routing::post(extract_admin))
}

pub fn client_router() -> Router<AppState> {
    Router::new()
        .route("/list", axum::routing::get(list_client))
        .route("/read", axum::routing::get(read_client))
        .route("/download", axum::routing::get(download_client))
        .route("/write", axum::routing::post(write_client))
        .route("/create-dir", axum::routing::post(create_dir_client))
        .route("/create-file", axum::routing::post(create_file_client))
        .route("/rename", axum::routing::post(rename_client))
        .route("/copy", axum::routing::post(copy_client))
        .route("/delete", axum::routing::post(delete_client))
        .route("/chmod", axum::routing::post(chmod_client))
        .route("/upload", axum::routing::post(upload_client))
        .route("/compress", axum::routing::post(compress_client))
        .route("/extract", axum::routing::post(extract_client))
}

// ---------- admin ----------

async fn list_admin(State(state): State<AppState>, Query(q): Query<PathQ>) -> Result<Json<ListResponse>, ApiError> {
    let root = admin_root(&state, q.account_id).await?;
    Ok(Json(list_dir(&root, q.path.as_deref())?))
}

async fn read_admin(State(state): State<AppState>, Query(q): Query<PathQ>) -> Result<String, ApiError> {
    let root = admin_root(&state, q.account_id).await?;
    read_file(&root, q.path.as_deref())
}

async fn download_admin(State(state): State<AppState>, Query(q): Query<DownloadQ>) -> Result<impl IntoResponse, ApiError> {
    let root = admin_root(&state, q.account_id).await?;
    download_many(&root, &q.path)
}

async fn write_admin(State(state): State<AppState>, Json(body): Json<WriteBody>) -> Result<StatusCode, ApiError> {
    let root = admin_root(&state, body.account_id).await?;
    write_file(&root, &body.path, &body.content)
}

async fn create_dir_admin(State(state): State<AppState>, Json(body): Json<CreateBody>) -> Result<StatusCode, ApiError> {
    let root = admin_root(&state, body.account_id).await?;
    create_dir(&root, &body.path, &body.name)
}

async fn create_file_admin(State(state): State<AppState>, Json(body): Json<CreateBody>) -> Result<StatusCode, ApiError> {
    let root = admin_root(&state, body.account_id).await?;
    create_file(&root, &body.path, &body.name)
}

async fn rename_admin(State(state): State<AppState>, Json(body): Json<MoveBody>) -> Result<StatusCode, ApiError> {
    let root = admin_root(&state, body.account_id).await?;
    move_entry(&root, &body.from, &body.to)
}

async fn copy_admin(State(state): State<AppState>, Json(body): Json<MoveBody>) -> Result<StatusCode, ApiError> {
    let root = admin_root(&state, body.account_id).await?;
    copy_entry(&root, &body.from, &body.to)
}

async fn delete_admin(State(state): State<AppState>, Json(body): Json<DeleteBody>) -> Result<StatusCode, ApiError> {
    let root = admin_root(&state, body.account_id).await?;
    match body.paths {
        Some(paths) if !paths.is_empty() => {
            for p in paths {
                delete_entry(&root, &p)?;
            }
            Ok(StatusCode::NO_CONTENT)
        }
        _ => delete_entry(&root, body.path.as_deref().unwrap_or("")),
    }
}

async fn chmod_admin(State(state): State<AppState>, Json(body): Json<ChmodBody>) -> Result<StatusCode, ApiError> {
    let root = admin_root(&state, body.account_id).await?;
    chmod_entry(&root, &body.path, &body.mode)
}

async fn upload_admin(
    State(state): State<AppState>,
    Query(q): Query<PathQ>,
    mut multipart: Multipart,
) -> Result<Json<UploadResult>, ApiError> {
    let root = admin_root(&state, q.account_id).await?;
    Ok(Json(handle_upload(&root, q.path.as_deref(), &mut multipart).await?))
}

async fn compress_admin(
    State(state): State<AppState>,
    Json(body): Json<OnePathBody>,
) -> Result<StatusCode, ApiError> {
    let root = admin_root(&state, body.account_id).await?;
    compress_entry(&root, &body.path)
}

async fn extract_admin(
    State(state): State<AppState>,
    Json(body): Json<OnePathBody>,
) -> Result<StatusCode, ApiError> {
    let root = admin_root(&state, body.account_id).await?;
    extract_archive(&root, &body.path)
}

// ---------- client ----------

async fn list_client(State(state): State<AppState>, headers: HeaderMap, Query(q): Query<PathQ>) -> Result<Json<ListResponse>, ApiError> {
    let root = client_root(&state, &headers).await?;
    Ok(Json(list_dir(&root, q.path.as_deref())?))
}

async fn read_client(State(state): State<AppState>, headers: HeaderMap, Query(q): Query<PathQ>) -> Result<String, ApiError> {
    let root = client_root(&state, &headers).await?;
    read_file(&root, q.path.as_deref())
}

async fn download_client(State(state): State<AppState>, headers: HeaderMap, Query(q): Query<DownloadQ>) -> Result<impl IntoResponse, ApiError> {
    let root = client_root(&state, &headers).await?;
    download_many(&root, &q.path)
}

async fn write_client(State(state): State<AppState>, headers: HeaderMap, Json(body): Json<WriteBody>) -> Result<StatusCode, ApiError> {
    let root = client_root(&state, &headers).await?;
    write_file(&root, &body.path, &body.content)
}

async fn create_dir_client(State(state): State<AppState>, headers: HeaderMap, Json(body): Json<CreateBody>) -> Result<StatusCode, ApiError> {
    let root = client_root(&state, &headers).await?;
    create_dir(&root, &body.path, &body.name)
}

async fn create_file_client(State(state): State<AppState>, headers: HeaderMap, Json(body): Json<CreateBody>) -> Result<StatusCode, ApiError> {
    let root = client_root(&state, &headers).await?;
    create_file(&root, &body.path, &body.name)
}

async fn rename_client(State(state): State<AppState>, headers: HeaderMap, Json(body): Json<MoveBody>) -> Result<StatusCode, ApiError> {
    let root = client_root(&state, &headers).await?;
    move_entry(&root, &body.from, &body.to)
}

async fn copy_client(State(state): State<AppState>, headers: HeaderMap, Json(body): Json<MoveBody>) -> Result<StatusCode, ApiError> {
    let root = client_root(&state, &headers).await?;
    copy_entry(&root, &body.from, &body.to)
}

async fn delete_client(State(state): State<AppState>, headers: HeaderMap, Json(body): Json<DeleteBody>) -> Result<StatusCode, ApiError> {
    let root = client_root(&state, &headers).await?;
    match body.paths {
        Some(paths) if !paths.is_empty() => {
            for p in paths {
                delete_entry(&root, &p)?;
            }
            Ok(StatusCode::NO_CONTENT)
        }
        _ => delete_entry(&root, body.path.as_deref().unwrap_or("")),
    }
}

async fn chmod_client(State(state): State<AppState>, headers: HeaderMap, Json(body): Json<ChmodBody>) -> Result<StatusCode, ApiError> {
    let root = client_root(&state, &headers).await?;
    chmod_entry(&root, &body.path, &body.mode)
}

async fn upload_client(
    State(state): State<AppState>,
    headers: HeaderMap,
    Query(q): Query<PathQ>,
    mut multipart: Multipart,
) -> Result<Json<UploadResult>, ApiError> {
    let root = client_root(&state, &headers).await?;
    Ok(Json(handle_upload(&root, q.path.as_deref(), &mut multipart).await?))
}

async fn compress_client(
    State(state): State<AppState>,
    headers: HeaderMap,
    Json(body): Json<OnePathBody>,
) -> Result<StatusCode, ApiError> {
    let root = client_root(&state, &headers).await?;
    compress_entry(&root, &body.path)
}

async fn extract_client(
    State(state): State<AppState>,
    headers: HeaderMap,
    Json(body): Json<OnePathBody>,
) -> Result<StatusCode, ApiError> {
    let root = client_root(&state, &headers).await?;
    extract_archive(&root, &body.path)
}

// ---------- owners ----------

async fn client_root(state: &AppState, headers: &HeaderMap) -> Result<PathBuf, ApiError> {
    let (_account_id, username) = super::domains::bearer_account(state, headers).await?;
    Ok(provision::account_htdocs(&username))
}

async fn admin_root(state: &AppState, account_id: Option<i64>) -> Result<PathBuf, ApiError> {
    let Some(aid) = account_id else {
        return Err(ApiError::new(
            StatusCode::BAD_REQUEST,
            "account_id is required",
        ));
    };
    let username = sqlx::query_scalar::<_, String>("SELECT username FROM accounts WHERE id = ?")
        .bind(aid)
        .fetch_optional(&state.db)
        .await
        .map_err(|e| internal_error(e.into()))?
        .ok_or_else(|| ApiError::new(StatusCode::NOT_FOUND, "Account not found"))?;
    Ok(provision::account_htdocs(&username))
}

// ---------- core ----------

fn safe_resolve(root: &Path, rel: &str) -> Result<PathBuf, ApiError> {
    let mut cur = root.to_path_buf();
    for comp in rel.trim_start_matches('/').split('/') {
        if comp.is_empty() || comp == "." {
            continue;
        }
        if comp == ".." || comp.contains('\\') || comp.contains('\0') {
            return Err(ApiError::new(
                StatusCode::BAD_REQUEST,
                "Invalid path (traversal blocked)",
            ));
        }
        cur.push(comp);
    }
    if !cur.starts_with(root) {
        return Err(ApiError::new(
            StatusCode::BAD_REQUEST,
            "Invalid path (outside document root)",
        ));
    }
    Ok(cur)
}

fn parent_of(rel: &str) -> String {
    let clean = rel.trim_matches('/');
    match clean.rfind('/') {
        Some(i) => clean[..i].to_string(),
        None => "".to_string(),
    }
}

fn fmt_modified(t: std::time::SystemTime) -> String {
    let dt: chrono::DateTime<chrono::Utc> = t.into();
    dt.format("%Y-%m-%d %H:%M").to_string()
}

fn fmt_perms(mode: u32) -> String {
    let mut out = String::new();
    for i in 0..3 {
        let v = (mode >> (6 - i * 3)) & 0o7;
        out.push(if v & 0o4 != 0 { 'r' } else { '-' });
        out.push(if v & 0o2 != 0 { 'w' } else { '-' });
        out.push(if v & 0o1 != 0 { 'x' } else { '-' });
    }
    out
}

fn list_dir(root: &Path, rel: Option<&str>) -> Result<ListResponse, ApiError> {
    let rel = rel.unwrap_or("");
    let dir = safe_resolve(root, rel)?;
    let read = std::fs::read_dir(&dir)
        .map_err(|e| ApiError::new(StatusCode::NOT_FOUND, format!("Cannot open directory: {e}")))?;

    let mut entries: Vec<Entry> = Vec::new();
    for entry in read.flatten() {
        let name = entry.file_name().to_string_lossy().into_owned();
        let meta = match entry.metadata() {
            Ok(m) => m,
            Err(_) => continue,
        };
        let kind = if meta.is_dir() { "dir" } else { "file" };
        let modified = meta
            .modified()
            .map(fmt_modified)
            .unwrap_or_else(|_| "-".into());
        entries.push(Entry {
            name,
            kind: kind.into(),
            size: meta.len(),
            modified,
            perms: fmt_perms(meta.permissions().mode()),
        });
    }
    entries.sort_by(|a, b| {
        b.kind.cmp(&a.kind).then(a.name.to_lowercase().cmp(&b.name.to_lowercase()))
    });

    Ok(ListResponse {
        path: rel.trim_end_matches('/').to_string(),
        parent: parent_of(rel),
        entries,
    })
}

fn read_file(root: &Path, rel: Option<&str>) -> Result<String, ApiError> {
    let path = safe_resolve(root, rel.unwrap_or(""))?;
    if path.is_dir() {
        return Err(ApiError::new(StatusCode::BAD_REQUEST, "Is a directory"));
    }
    let bytes = std::fs::read(&path)
        .map_err(|e| ApiError::new(StatusCode::NOT_FOUND, format!("File not found: {e}")))?;
    String::from_utf8(bytes)
        .map_err(|_| ApiError::new(StatusCode::BAD_REQUEST, "Binary file (cannot edit as text)"))
}

fn download_many(root: &Path, paths: &[String]) -> Result<impl IntoResponse, ApiError> {
    let rels: Vec<&str> = paths.iter().map(String::as_str).collect();
    let cleaned: Vec<&str> = if rels.is_empty() { vec![""] } else { rels };
    let spaths: Vec<PathBuf> = cleaned
        .iter()
        .map(|r| safe_resolve(root, r))
        .collect::<Result<_, _>>()?;
    for p in &spaths {
        if !p.exists() {
            return Err(ApiError::new(StatusCode::NOT_FOUND, "File not found"));
        }
    }
    let single_file = spaths.len() == 1 && !spaths[0].is_dir();
    let bytes: Vec<u8> = if single_file {
        std::fs::read(&spaths[0])
            .map_err(|e| ApiError::new(StatusCode::NOT_FOUND, format!("File not found: {e}")))?
    } else {
        // cPanel-style: downloading a folder or a multi-selection zips it.
        let mut buf: Vec<u8> = Vec::new();
        {
            let mut w = ZipWriter::new(Cursor::new(&mut buf));
            for (p, r) in spaths.iter().zip(cleaned.iter()) {
                let base = p
                    .file_name()
                    .and_then(|n| n.to_str())
                    .unwrap_or("download")
                    .trim_end_matches('/');
                if spaths.len() == 1 {
                    zip_tree(&mut w, p, base)?;
                } else {
                    let rel = r.trim_start_matches('/');
                    let prefix = format!("{base}/{}", rel);
                    zip_tree(&mut w, p, &prefix)?;
                }
            }
            w.finish().map_err(|e| internal_error(e.into()))?;
        }
        buf
    };
    let first = spaths[0].file_name().and_then(|n| n.to_str()).unwrap_or("download");
    let out_name = if single_file {
        first.to_string()
    } else {
        format!("{}.zip", first.trim_end_matches('/'))
    };
    let mut hm = axum::http::HeaderMap::new();
    hm.insert(
        header::CONTENT_TYPE,
        "application/octet-stream".parse().map_err(|_| ApiError::new(StatusCode::INTERNAL_SERVER_ERROR, "header error"))?,
    );
    hm.insert(
        header::CONTENT_DISPOSITION,
        format!("attachment; filename=\"{out_name}\"")
            .parse()
            .map_err(|_| ApiError::new(StatusCode::INTERNAL_SERVER_ERROR, "header error"))?,
    );
    Ok((StatusCode::OK, hm, bytes))
}

fn write_file(root: &Path, rel: &str, content: &str) -> Result<StatusCode, ApiError> {
    let path = safe_resolve(root, rel)?;
    if path.is_dir() {
        return Err(ApiError::new(StatusCode::BAD_REQUEST, "Is a directory"));
    }
    std::fs::write(&path, content)
        .map_err(|e| ApiError::new(StatusCode::INTERNAL_SERVER_ERROR, format!("Write failed: {e}")))?;
    Ok(StatusCode::NO_CONTENT)
}

fn create_dir(root: &Path, rel: &str, name: &str) -> Result<StatusCode, ApiError> {
    if name.is_empty() || name.contains('/') || name.contains('\\') || name == "." || name == ".." {
        return Err(ApiError::new(StatusCode::BAD_REQUEST, "Invalid directory name"));
    }
    let dir = safe_resolve(root, rel)?;
    let target = dir.join(name);
    if target.exists() {
        return Err(ApiError::new(StatusCode::CONFLICT, "Already exists"));
    }
    std::fs::create_dir(&target)
        .map_err(|e| ApiError::new(StatusCode::INTERNAL_SERVER_ERROR, format!("Create failed: {e}")))?;
    Ok(StatusCode::NO_CONTENT)
}

fn create_file(root: &Path, rel: &str, name: &str) -> Result<StatusCode, ApiError> {
    if name.is_empty() || name.contains('/') || name.contains('\\') || name == "." || name == ".." {
        return Err(ApiError::new(StatusCode::BAD_REQUEST, "Invalid file name"));
    }
    let dir = safe_resolve(root, rel)?;
    let target = dir.join(name);
    if target.exists() {
        return Err(ApiError::new(StatusCode::CONFLICT, "Already exists"));
    }
    std::fs::write(&target, "")
        .map_err(|e| ApiError::new(StatusCode::INTERNAL_SERVER_ERROR, format!("Create failed: {e}")))?;
    Ok(StatusCode::NO_CONTENT)
}

fn move_entry(root: &Path, from: &str, to: &str) -> Result<StatusCode, ApiError> {
    let src = safe_resolve(root, from)?;
    let dst = safe_resolve(root, to)?;
    if !src.exists() {
        return Err(ApiError::new(StatusCode::NOT_FOUND, "Source not found"));
    }
    if dst.exists() {
        return Err(ApiError::new(StatusCode::CONFLICT, "Destination already exists"));
    }
    std::fs::rename(&src, &dst)
        .map_err(|e| ApiError::new(StatusCode::INTERNAL_SERVER_ERROR, format!("Rename failed: {e}")))?;
    Ok(StatusCode::NO_CONTENT)
}

fn copy_entry(root: &Path, from: &str, to: &str) -> Result<StatusCode, ApiError> {
    let src = safe_resolve(root, from)?;
    let dst = safe_resolve(root, to)?;
    if !src.exists() {
        return Err(ApiError::new(StatusCode::NOT_FOUND, "Source not found"));
    }
    if dst.exists() {
        return Err(ApiError::new(StatusCode::CONFLICT, "Destination already exists"));
    }
    let r = copy_tree(&src, &dst);
    r.map_err(|e| ApiError::new(StatusCode::INTERNAL_SERVER_ERROR, format!("Copy failed: {e}")))?;
    Ok(StatusCode::NO_CONTENT)
}

fn copy_tree(s: &std::path::Path, d: &std::path::Path) -> Result<(), String> {
    if s.is_dir() {
        std::fs::create_dir_all(d).map_err(|e| e.to_string())?;
        for e in std::fs::read_dir(s).map_err(|e| e.to_string())?.flatten() {
            copy_tree(&e.path(), &d.join(e.file_name())).map_err(|e| e)?;
        }
    } else {
        std::fs::copy(s, d).map_err(|e| e.to_string())?;
    }
    Ok(())
}

fn delete_entry(root: &Path, rel: &str) -> Result<StatusCode, ApiError> {
    if rel.trim().is_empty() {
        return Err(ApiError::new(StatusCode::BAD_REQUEST, "Cannot delete root"));
    }
    let path = safe_resolve(root, rel)?;
    if !path.exists() {
        return Err(ApiError::new(StatusCode::NOT_FOUND, "Not found"));
    }
    let r = if path.is_dir() {
        std::fs::remove_dir_all(&path)
    } else {
        std::fs::remove_file(&path)
    };
    r.map_err(|e| ApiError::new(StatusCode::INTERNAL_SERVER_ERROR, format!("Delete failed: {e}")))?;
    Ok(StatusCode::NO_CONTENT)
}

fn chmod_entry(root: &Path, rel: &str, mode: &str) -> Result<StatusCode, ApiError> {
    let path = safe_resolve(root, rel)?;
    if !path.exists() {
        return Err(ApiError::new(StatusCode::NOT_FOUND, "Not found"));
    }
    let mode = u32::from_str_radix(mode.trim_start_matches("0o"), 8)
        .map_err(|_| ApiError::new(StatusCode::BAD_REQUEST, "Invalid mode (octal expected)"))?;
    if mode > 0o7777 {
        return Err(ApiError::new(StatusCode::BAD_REQUEST, "Invalid mode"));
    }
    std::fs::set_permissions(&path, std::fs::Permissions::from_mode(mode))
        .map_err(|e| ApiError::new(StatusCode::INTERNAL_SERVER_ERROR, format!("Chmod failed: {e}")))?;
    Ok(StatusCode::NO_CONTENT)
}

// ---------- zip ----------

fn zip_tree<W: Write + Seek>(
    w: &mut ZipWriter<W>,
    base: &Path,
    prefix: &str,
) -> Result<(), ApiError> {
    let opts = SimpleFileOptions::default().compression_method(CompressionMethod::Deflated);
    if base.is_dir() {
        let dirname = prefix.trim_end_matches('/').to_string();
        if !dirname.is_empty() {
            w.add_directory(&dirname, opts)
                .map_err(|e| internal_error(e.into()))?;
        }
        for e in fs::read_dir(base)
            .map_err(|e| internal_error(e.into()))
            .map(|i| i.filter_map(Result::ok))?
        {
            let name = e.file_name().to_string_lossy().into_owned();
            let child = if dirname.is_empty() {
                name.clone()
            } else {
                format!("{dirname}/{name}")
            };
            zip_tree(w, &e.path(), &child)?;
        }
    } else {
        let name = prefix.trim_start_matches('/').to_string();
        w.start_file(name, opts)
            .map_err(|e| internal_error(e.into()))?;
        let mut f = fs::File::open(base).map_err(|e| internal_error(e.into()))?;
        let mut buf = vec![0u8; 65536];
        loop {
            let n = f.read(&mut buf).map_err(|e| internal_error(e.into()))?;
            if n == 0 {
                break;
            }
            w.write_all(&buf[..n]).map_err(|e| internal_error(e.into()))?;
        }
    }
    Ok(())
}

fn compress_entry(root: &Path, rel: &str) -> Result<StatusCode, ApiError> {
    let src = safe_resolve(root, rel)?;
    if !src.exists() {
        return Err(ApiError::new(StatusCode::NOT_FOUND, "Not found"));
    }
    let name = src
        .file_name()
        .and_then(|n| n.to_str())
        .ok_or_else(|| ApiError::new(StatusCode::BAD_REQUEST, "Invalid name"))?;
    let parent = src
        .parent()
        .ok_or_else(|| ApiError::new(StatusCode::BAD_REQUEST, "Invalid path"))?;
    let zip_path = parent.join(format!("{name}.zip"));
    if zip_path.exists() {
        fs::remove_file(&zip_path)
            .map_err(|e| ApiError::new(StatusCode::INTERNAL_SERVER_ERROR, format!("Zip failed: {e}")))?;
    }
    let file = fs::File::create(&zip_path)
        .map_err(|e| ApiError::new(StatusCode::INTERNAL_SERVER_ERROR, format!("Zip failed: {e}")))?;
    let mut w = ZipWriter::new(file);
    // Keep the basename as the top-level entry (cPanel keeps dir contents inside `<name>/`).
    zip_tree(&mut w, &src, name)?;
    w.finish().map_err(|e| internal_error(e.into()))?;
    Ok(StatusCode::NO_CONTENT)
}

fn extract_archive(root: &Path, rel: &str) -> Result<StatusCode, ApiError> {
    let src = safe_resolve(root, rel)?;
    if !src.exists() || !src.is_file() {
        return Err(ApiError::new(StatusCode::BAD_REQUEST, "Archive not found"));
    }
    let dir = src
        .parent()
        .ok_or_else(|| ApiError::new(StatusCode::BAD_REQUEST, "Invalid path"))?;
    let file = fs::File::open(&src).map_err(|e| internal_error(e.into()))?;
    let mut ar = ZipArchive::new(file)
        .map_err(|_| ApiError::new(StatusCode::BAD_REQUEST, "Not a valid .zip archive"))?;
    for i in 0..ar.len() {
        let mut entry = ar.by_index(i).map_err(|e| internal_error(e.into()))?;
        let rel_name = sanitize_zip_name(entry.name())?;
        let dest = dir.join(&rel_name);
        if !dest.starts_with(dir) {
            return Err(ApiError::new(StatusCode::BAD_REQUEST, "Unsafe archive entry"));
        }
        if entry.is_dir() {
            fs::create_dir_all(&dest).map_err(|e| internal_error(e.into()))?;
        } else {
            if let Some(parent) = dest.parent() {
                fs::create_dir_all(parent).map_err(|e| internal_error(e.into()))?;
            }
            let mut out = fs::File::create(&dest).map_err(|e| internal_error(e.into()))?;
            std::io::copy(&mut entry, &mut out).map_err(|e| internal_error(e.into()))?;
        }
    }
    Ok(StatusCode::NO_CONTENT)
}

fn sanitize_zip_name(name: &str) -> Result<PathBuf, ApiError> {
    let mut pb = PathBuf::new();
    for comp in name.split('/') {
        if comp.is_empty() || comp == "." {
            continue;
        }
        if comp == ".." || comp.contains('\\') || comp.contains('\0') {
            return Err(ApiError::new(
                StatusCode::BAD_REQUEST,
                "Unsafe archive entry path",
            ));
        }
        pb.push(comp);
    }
    Ok(pb)
}

async fn handle_upload(
    root: &Path,
    rel: Option<&str>,
    multipart: &mut Multipart,
) -> Result<UploadResult, ApiError> {
    let dir = safe_resolve(root, rel.unwrap_or(""))?;
    if !dir.is_dir() {
        return Err(ApiError::new(StatusCode::BAD_REQUEST, "Not a directory"));
    }
    let mut uploaded = 0usize;
    while let Some(field) = multipart
        .next_field()
        .await
        .map_err(|e| ApiError::new(StatusCode::BAD_REQUEST, format!("Multipart error: {e}")))?
    {
        let Some(fname) = field.file_name().map(String::from) else {
            continue;
        };
        if std::path::Path::new(&fname)
            .file_name()
            .map(|f| f != std::ffi::OsStr::new(&fname))
            .unwrap_or(false)
        {
            return Err(ApiError::new(StatusCode::BAD_REQUEST, "Invalid file name"));
        }
        let data = field
            .bytes()
            .await
            .map_err(|e| ApiError::new(StatusCode::BAD_REQUEST, format!("Upload failed: {e}")))?;
        let dest = dir.join(&fname);
        std::fs::write(&dest, &data)
            .map_err(|e| ApiError::new(StatusCode::INTERNAL_SERVER_ERROR, format!("Save failed: {e}")))?;
        uploaded += 1;
    }
    if uploaded == 0 {
        return Err(ApiError::new(StatusCode::BAD_REQUEST, "No file received"));
    }
    Ok(UploadResult { uploaded })
}