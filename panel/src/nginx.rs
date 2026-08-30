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

pub fn ensure_https_vhost(domain: &str) -> Result<(), String> {
    if !nginx_present() {
        return Ok(());
    }
    let crt = provision::certs_dir().join(format!("{domain}.crt"));
    let key = provision::certs_dir().join(format!("{domain}.key"));
    if !crt.exists() || !key.exists() {
        return Err(format!("ssl files missing for {domain}"));
    }

    let enabled = "/etc/nginx/sites-enabled";
    if let Ok(out) = Command::new("grep")
        .args(["-rls", &format!("server_name {domain};"), enabled])
        .output()
    {
        if out.status.success() {
            return Ok(());
        }
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

    if let Some(parent) = Path::new(&conf_path).parent() {
        let _ = std::fs::create_dir_all(parent);
    }
    std::fs::write(&conf_path, content).map_err(|e| format!("write {conf_path}: {e}"))?;

    let link = format!("{enabled}/fpssl-{domain}.conf");
    if !Path::new(&link).exists() {
        if let Some(parent) = Path::new(link.as_str()).parent() {
            let _ = std::fs::create_dir_all(parent);
        }
        let _ = std::fs::remove_file(&link);
        std::os::unix::fs::symlink(&conf_path, &link)
            .map_err(|e| format!("symlink {link}: {e}"))?;
    }

    let test = Command::new("nginx").arg("-t").output();
    match test {
        Ok(o) if o.status.success() => {
            reload_nginx();
            tracing::info!("[nginx] https vhost enabled for {domain}");
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