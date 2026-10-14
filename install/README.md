# Instalasi FPanel

Direktori ini berisi **installer** dan semua aset sistem yang dibutuhkan
FPanel. Isinya sengaja dibedakan dari kode aplikasi supaya jelas mana yang
masuk `/srv/fpanel` dan mana yang masuk ke direktori sistem.

| Folder | Tujuan di server |
|---|---|
| `install.sh` | Skrip instalasi utama |
| `helpers/` | `/usr/local/sbin/fpanel-*` — helper privileged |
| `sudoers/` | `/etc/sudoers.d/fpanel-*` — aturan izin per-perintah |
| `systemd/` | contoh unit app runtime (`/etc/systemd/system/`) |
| `cron/` | `/etc/cron.d/fpanel-*` |
| `nginx/` | vhost panel + snippet SSL + catch-all |
| `logrotate/` | `/etc/logrotate.d/fpanel-backup` |
| `phpmyadmin/` | `/usr/share/phpmyadmin/fpma-index.php` (front controller token) |

---

## Pemakaian cepat

```bash
git clone https://github.com/flockman777/fpanel.git
cd fpanel
sudo ./install/install.sh
```

Installer interaktif akan meminta konfirmasi sebelum mengubah sistem.

### Non-interaktif

```bash
sudo ./install/install.sh --yes \
  --domain panel.contoh.tld \
  --email admin@contoh.tld \
  --admin-user admin
```

Sandi admin dibuat acak dan ditampilkan di akhir. Untuk menentukan sendiri:

```bash
sudo ./install/install.sh --yes --admin-pass 'Sandi!Kuat123'
```

---

## Yang dilakukan installer

1. **Prasyarat** — cek root, distro, dan keberadaan kode.
2. **Paket** — nginx, MariaDB, Redis, certbot, PHP-FPM (multi-versi),
   phpMyAdmin, WP-CLI, Node.js (opsional).
3. **Direktori** — `/var/www`, `/var/log/nginx/fpanel`,
   `/etc/nginx/sites-available/fpanel-domains`, conf.d per versi PHP,
   `/var/lib/fpanel`, `/etc/fpanel`, `/var/backups/fpanel`.
4. **Aplikasi** — rsync repositori → `/srv/fpanel` (tanpa `var/`,
   `node_modules/`, `.git/`, `install/`).
5. **Helper + sudoers + cron + logrotate** — dipasang ke lokasi sistem.
   Aturan sudo divalidasi `visudo -c`; kalau tidak valid, semuanya dibatalkan.
6. **phpMyAdmin** — front controller token `/pma/<token>/`.
7. **Kunci panel** — `/etc/fpanel/app-key` (32 byte acak, mode 0640).
8. **nginx** — vhost panel port `8090`, snippet SSL, catch-all.
9. **Database** — `db:migrate` + akun admin.
10. **Domain + SSL** (opsional) — vhost 443 + certbot webroot.
11. **Ringkasan** — URL panel dan kredensial admin.

Installer **aman dijalankan berulang**: konfigurasi ditimpa dengan versi repo,
tetapi database, `/var/www`, sertifikat, dan kunci panel tidak diubah.

---

## Opsi

```
--domain <d>      Domain panel (vhost 443 + SSL)
--email <e>       Email Let's Encrypt & admin
--admin-user <u>  Username admin        (default: admin)
--admin-email <e> Email admin
--admin-pass <p>  Sandi admin           (default: acak)
--php <ver>       Versi PHP default     (default: 8.3)
--port <n>        Port panel            (default: 8090)
--root <dir>      Lokasi pemasangan     (default: /srv/fpanel)
--no-node         Jangan pasang Node.js
--no-pma          Jangan pasang phpMyAdmin
--skip-packages   Lewati pemasangan paket apt
--yes             Non-interaktif
```

---

## Setelah instalasi

- Buka `http://<ip>:8090` (atau `https://<domain>` bila domain diset).
- Ganti sandi admin lewat dasbor, aktifkan TOTP di menu Keamanan.
- Atur **PTR/rDNS** IP server agar email yang dikirim panel tidak ditolak.
- Untuk SSL DNS-01 Cloudflare, isi `FPANEL_CF_API_TOKEN` & `FPANEL_CF_ZONE_ID`
  (lihat `env.example`).

## Rahasia

Semua rahasia berada **di luar repositori**:

| Berkas | Isi | Izin |
|---|---|---|
| `/etc/fpanel/app-key` | kunci enkripsi SecretBox | `0640 root:www-data` |
| `/etc/fpanel/offsite.conf` | kredensial S3 offsite | `0600 root` |
| `/srv/fpanel/var/db/fpanel.sqlite` | database panel | `0750 www-data` |

Jangan pernah `git add` berkas-berkas ini — `.gitignore` sudah menutupinya.
