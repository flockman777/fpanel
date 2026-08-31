use std::path::PathBuf;
mod auth;
mod db;
mod error;
mod middleware;
mod nginx;
mod provision;
mod routes;
mod totp;
use axum::middleware::from_fn_with_state;
use axum::routing::get;
use axum::Router;
use tower_http::cors::CorsLayer;
use tower_http::trace::TraceLayer;

const PORT: u16 = 8181;

#[tokio::main]
async fn main() {
    tracing_subscriber::fmt()
        .with_target(false)
        .compact()
        .init();

    // Cross-compiled binaries are built on a different host, so a compile-time
    // CARGO_MANIFEST_DIR may point at a path that doesn't exist here. Resolve at
    // runtime instead: env override > next to the deployed executable > dev path.
    let exe_dir = std::env::current_exe()
        .ok()
        .and_then(|e| e.parent().map(|p| p.to_path_buf()));
    let deployed_db = exe_dir
        .as_ref()
        .and_then(|p| p.parent().and_then(|q| q.parent()))
        .map(|p| p.join("db/fpanel.db"));
    let dev_db = PathBuf::from(env!("CARGO_MANIFEST_DIR")).join("db/fpanel.db");
    let db_path = std::env::var("FPANEL_DB")
        .map(PathBuf::from)
        .unwrap_or_else(|_| deployed_db.filter(|p| p.exists()).unwrap_or(dev_db));
    let db_path = db_path.to_string_lossy().into_owned();
    let jwt_secret = std::env::var("FPANEL_SECRET")
        .unwrap_or_else(|_| "fpanel-dev-secret-change-me".into());

    let state = db::init_db(&db_path, jwt_secret)
        .await
        .expect("failed to init db");

    // Keep fserver vhost descriptors in sync with the DB on every boot so
    // layout changes (e.g. the cPanel-style docroot scheme) apply at once.
    if let Ok(domains) = sqlx::query_as::<_, (String, String, String, Option<String>)>(
        "SELECT a.username, d.name, d.kind, d.docroot \
         FROM domains d JOIN accounts a ON a.id = d.account_id \
         WHERE d.status = 'active'",
    )
    .fetch_all(&state.db)
    .await
    {
        for (username, name, kind, docroot) in domains {
            provision::write_vhost(&name, &username, &kind, docroot.as_deref());
        }
    }

    // AutoSSL: renew Let's Encrypt certificates for all accounts every 6 hours.
    {
        let st = state.clone();
        tokio::spawn(async move {
            loop {
                if let Err(e) = routes::ssl::autossl_all(&st).await {
                    tracing::warn!("[autossl] loop error: {e}");
                }
                tokio::time::sleep(std::time::Duration::from_secs(6 * 3600)).await;
            }
        });
    }

    let app = Router::new()
        .route("/", get(|| async { "FPanel API v0.1" }))
        .nest("/api", build_api(state.clone()))
        .layer(CorsLayer::permissive())
        .layer(TraceLayer::new_for_http())
        .with_state(state);

    let addr = std::net::SocketAddr::from(([0, 0, 0, 0], PORT));
    tracing::info!("FPanel listening on http://{addr}");
    let listener = tokio::net::TcpListener::bind(addr).await.unwrap();
    axum::serve(listener, app).await.unwrap();
}

fn build_api(state: db::AppState) -> Router<db::AppState> {
    let protected = Router::new()
        .nest("/accounts", routes::accounts::router())
        .nest("/packages", routes::packages::router())
        .nest("/domains", routes::domains::router())
        .nest("/dns", routes::dns::router())
        .nest("/cron", routes::cron::router())
        .nest("/backups", routes::backups::router())
        .nest("/cache", routes::cache::router())
        .nest("/logs", routes::logs::router())
        .nest("/stats", routes::stats::router())
        .nest("/redirects", routes::redirects::router())
        .nest("/files", routes::files::router())
        .nest("/databases", routes::databases::router())
        .nest("/email", routes::email::router())
        .nest("/ssl", routes::ssl::router())
        .nest("/runtime", routes::runtime::router())
        .nest("/php", routes::php::router())
        .nest("/apps", routes::apps::router())
        .nest("/ipblocker", routes::ipblocker::router())
        .nest("/hotlink", routes::hotlink::router())
        .nest("/waf", routes::waf::router())
        .nest("/ssh", routes::ssh::router())
        .nest("/totp", routes::totp::router())
        .nest("/deliverability", routes::deliverability::router())
        .nest("/delivery", routes::delivery::router())
        .nest("/tracking", routes::tracking::router())
        .nest(
            "/client",
            routes::client::router()
                .nest("/domains", routes::domains::client_router())
                .nest("/dns", routes::dns::client_router())
                .nest("/cron", routes::cron::client_router())
                .nest("/backups", routes::backups::client_router())
                .nest("/logs", routes::logs::client_router())
                .nest("/stats", routes::stats::client_router())
                .nest("/cache", routes::cache::client_router())
                .nest("/redirects", routes::redirects::client_router())
                .nest("/files", routes::files::client_router())
                .nest("/databases", routes::databases::client_router())
                .nest("/email", routes::email::client_router())
                .nest("/ssl", routes::ssl::client_router())
                .nest("/runtime", routes::runtime::client_router())
                .nest("/php", routes::php::client_router())
                .nest("/apps", routes::apps::client_router())
                .nest("/ipblocker", routes::ipblocker::client_router())
                .nest("/hotlink", routes::hotlink::client_router())
                .nest("/waf", routes::waf::client_router())
                .nest("/ssh", routes::ssh::client_router()),
        )
        .route("/me", get(routes::auth::me));

    Router::new()
        .nest("/auth", Router::new()
            .route("/login", axum::routing::post(routes::auth::login))
            .route("/register", axum::routing::post(routes::auth::register))
            .route("/client/login", axum::routing::post(routes::client::login)))
        .nest("/s/{sess}", protected)
        .layer(from_fn_with_state(state, middleware::session_guard))
}