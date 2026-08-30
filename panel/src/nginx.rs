use std::path::Path;
use std::process::Command;

use crate::provision;

fn nginx_present() -> bool {
    Path::new("/etc/nginx/nginx.conf").exists()
}

fn reload_nginx() {
    let systemd = Command::new("systemctl")
        .args(["reload", "nginx"])
        .output();
    if let Ok(o) = systemd {
        if o.status.success() {
            return;
        }
    }
    let _ = Command::new("nginx").args(["-s", "reload"]).output();
}

fn server_name_conflict(domain: &str) -> bool {
    let Ok(entries) = std::fs::read_dir("/etc/nginx/sites-enabled") else {
        return false;
    };
    for ent in entries.flatten() {
        let name = ent.file_name().to_string_lossy().to_string();
        if name.starts_with("fpssl-") || name.starts_with("fphttp-") {
            continue;
        }
        let Ok(content) = std::fs::read_to_string(ent.path()) else {
            continue;
        };
        for line in content.lines() {
            let l = line.trim();
            if l.starts_with("server_name") && l.contains(domain) {
                return true;
            }
        }
    }
    false
}

fn write_conf(conf_path: &str, content: &str) -> Result<(), String> {
    if let Some(parent) = Path::new(conf_path).parent() {
        let _ = std::fs::create_dir_all(parent);
    }
    std::fs::write(conf_path, content).map_err(|e| format!("write {conf_path}: {e}"))?;

    let link = conf_path.replace("sites-available", "sites-enabled");
    if !Path::new(&link).exists() {
        if let Some(parent) = Path::new(link.as_str()).parent() {
            let _ = std::fs::create_dir_all(parent);
        }
        let _ = std::fs::remove_file(&link);
        std::os::unix::fs::symlink(conf_path, &link)
            .map_err(|e| format!("symlink {link}: {e}"))?;
    }

    let test = Command::new("nginx").arg("-t").output();
    match test {
        Ok(o) if o.status.success() => {
            reload_nginx();
            Ok(())
        }
        Ok(o) => {
            let _ = std::fs::remove_file(&link);
            Err(format!(
                "nginx -t failed: {}",
                String::from_utf8_lossy(&o.stderr).trim()
            ))
        }
        Err(e) => Err(format!("nginx not available: {e}")),
    }
}

/// Enables an HTTP (port 80) vhost that serves ACME challenges from the
/// domain's document root and redirects everything else to HTTPS.
pub fn ensure_http_vhost(domain: &str, webroot: &str) -> Result<(), String> {
    if !nginx_present() {
        return Ok(());
    }
    if server_name_conflict(domain) {
        return Ok(());
    }
    let conf_path = format!("/etc/nginx/sites-available/fphttp-{domain}.conf");
    let root_disp = webroot.to_string();
    let content = format!(
        "server {{\n\
            \x20   listen 80;\n\
            \x20   server_name {domain};\n\
            \n\
            \x20   location /.well-known/acme-challenge/ {{\n\
            \x20       root {root_disp};\n\
            \x20   }}\n\
            \n\
            \x20   location / {{\n\
            \x20       return 301 https://$host$request_uri;\n\
            \x20   }}\n\
            }}\n"
    );
    write_conf(&conf_path, &content)
}

pub fn ensure_https_vhost(domain: &str) -> Result<(), String> {
    if !nginx_present() {
        return Ok(());
    }
    let crt = provision::certs_dir().join(format!("{domain}.crt"));
    let key = provision::certs_dir().join(format!("{domain}.key"));
    if !crt.exists() || !key.exists() {
        return Err(format!("ssl files missing for {domain}"));
    }

    if server_name_conflict(domain) {
        return Ok(());
    }

    let conf_path = format!("/etc/nginx/sites-available/fpssl-{domain}.conf");
    let crt_disp = crt.display().to_string();
    let key_disp = key.display().to_string();
    let content = format!(
        "server {{\n\
            \x20   listen 443 ssl http2;\n\
            \x20   server_name {domain};\n\
            \n\
            \x20   ssl_certificate {crt_disp};\n\
            \x20   ssl_certificate_key {key_disp};\n\
            \x20   ssl_protocols TLSv1.2 TLSv1.3;\n\
            \n\
            \x20   location / {{\n\
            \x20       proxy_pass http://127.0.0.1:8080;\n\
            \x20       proxy_set_header Host $host;\n\
            \x20       proxy_set_header X-Real-IP $remote_addr;\n\
            \x20       proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;\n\
            \x20       proxy_set_header X-Forwarded-Proto https;\n\
            \x20   }}\n\
            }}\n"
    );
    write_conf(&conf_path, &content)
}