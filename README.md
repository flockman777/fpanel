# FPanel

Panel kontrol hosting *self-hosted*: kelola domain, PHP, database MariaDB,
Node.js/Python, backup, dan sertifikat SSL dari satu antarmuka web.

Ditulis dengan **PHP 8.3 murni tanpa framework** (tanpa Composer runtime),
database panel **SQLite**, dan operasi privileged dijalankan lewat helper
root yang dikunci dengan `sudoers` per-perintah.

---

## Fitur

| Area | Yang bisa dilakukan |
|---|---|
| **Domain** | Tambah/hapus vhost nginx, docroot per domain, log akses/error per domain |
| **PHP** | Multi-versi (7.4–8.4), pool FPM per domain, on/off extension, override `php.ini` |
| **Database** | Buat/hapus database & user MariaDB, grant/revoke, cek ukuran |
| **phpMyAdmin** | Akses per-database lewat token sekali pakai (`/pma/<token>/`), token otomatis di-sweep |
| **SSL** | Let's Encrypt lewat certbot (HTTP-01 & DNS-01 Cloudflare) |
| **App runtime** | Jalankan app Node.js / Python sebagai unit systemd (`fpanel-app-<nama>`) |
| **File manager** | Telusur, unggah, ubah, hapus berkas di docroot |
| **Backup** | Backup lokal (SQLite, `/var/www`, database, konfigurasi, sertifikat) + offsite S3 |
| **Cron** | Kelola job cron per-user lewat helper root |
| **WordPress** | Instal & update WordPress via WP-CLI |
| **Keamanan** | Login + TOTP, remember-device, CSRF, audit log, rate-limit login |
| **Statistik** | Pemakaian disk/bandwidth/request per domain |

---

## Arsitektur

```
                    ┌─────────────────────────────┐
   Browser  ──────▶ │  nginx  :8090 (panel)       │
                    │  nginx  :80/443 (domain)    │
                    └──────────────┬──────────────┘
                                   │ fastcgi
                    ┌──────────────▼──────────────┐
                    │  php8.3-fpm                 │
                    │  /srv/fpanel/app/public     │
                    └──────────────┬──────────────┘
             ┌─────────────────────┼─────────────────────┐
             │                     │                     │
     ┌───────▼────────┐  ┌─────────▼─────────┐  ┌────────▼────────┐
     │ SQLite         │  │ MariaDB + Redis   │  │ helper root     │
     │ var/db/*.sqlite│  │ (domain, app)     │  │ /usr/local/sbin │
     └────────────────┘  └───────────────────┘  └─────────────────┘
```

- **Panel** jalan sebagai user `www-data`, tidak punya akses root langsung.
- **Aplikasi** memanggil operasi privileged lewat
  `/usr/local/sbin/fpanel-*` yang diizinkan spesifik di `/etc/sudoers.d/fpanel-*`.
  Setiap helper memvalidasi argumennya sendiri (nama, path, port) sebelum
  menyentuh systemd/nginx/MariaDB.
- **phpMyAdmin** diakses lewat front controller `fpma-index.php` yang
  memvalidasi token dari URI; tidak ada kredensial yang melewati file ini.

---

## Struktur repositori

```
.
├── app/                    # aplikasi PHP (autoload SPL, tanpa framework)
│   ├── bootstrap.php       #   entry point CLI & web (FPANEL_ROOT, autoload, config)
│   ├── public/             #   docroot: index.php + aset statis
│   ├── src/                #   Auth, Database, Http, Service, View, Support
│   ├── views/              #   template .php
│   └── i18n/               #   terjemahan (id)
├── bin/fpanel              # CLI (migrasi, user, backup, dll)
├── config/
│   ├── fpanel.php          #   konfigurasi utama (bisa di-override env FPANEL_*)
│   └── schema.sql          #   skema database panel
├── install/                # INSTALLER + aset sistem
│   ├── install.sh          #   skrip instalasi utama
│   ├── helpers/            #   → /usr/local/sbin/fpanel-*
│   ├── sudoers/            #   → /etc/sudoers.d/fpanel-*
│   ├── systemd/            #   unit contoh
│   ├── cron/               #   → /etc/cron.d/fpanel-*
│   ├── nginx/              #   vhost panel + snippet + catch-all
│   ├── logrotate/          #   → /etc/logrotate.d/fpanel-backup
│   └── phpmyadmin/         #   → /usr/share/phpmyadmin/fpma-index.php
├── assets-src/             # sumber Tailwind (kanonik) + config
├── tools/                  # skrip build (CSS, ikon, fitur)
├── tests/                  # suite integrasi
└── web/mockup/             # mockup UI lama (referensi tampilan)
```

---

## Instalasi

Prasyarat: **Ubuntu 24.04** (atau Debian turunannya), akses **root**, dan
koneksi internet. Domain bersifat opsional (panel bisa diakses lewat IP:8090).

```bash
git clone https://github.com/flockman777/fpanel.git
cd fpanel
sudo ./install/install.sh
```

Installer akan memasang paket, membuat direktori, menyalin aplikasi ke
`/srv/fpanel`, memasang helper + sudoers + cron, membuat database, dan
membuat akun admin. Lihat [`install/README.md`](install/README.md) untuk opsi
lanjutan (domain, SSL, non-interaktif).

Setelah selesai, panel dapat dibuka di:

- `http://<ip-server>:8090` — selalu tersedia;
- `https://<domain-panel>` — setelah domain & SSL dikonfigurasi.

---

## Konfigurasi

Konfigurasi utama ada di `config/fpanel.php`. Setiap nilai bisa di-override
lewat variabel lingkungan `FPANEL_*` (lihat `install/env.example`):

| Env | Default | Guna |
|---|---|---|
| `FPANEL_BASE_URL` | — (auto) | URL absolut panel |
| `FPANEL_APP_DEBUG` | `0` | Mode debug |
| `FPANEL_CF_API_TOKEN` | — | Token Cloudflare (SSL DNS-01) |
| `FPANEL_CF_ZONE_ID` | — | Zone Cloudflare |
| `FPANEL_MAIL_RELAY_*` | — | SMTP relay untuk notifikasi |

Nilai khusus server (mis. domain panel & email SSL) ditulis installer ke
`config/local.php`. Berkas itu **tidak** ikut ke Git, sehingga nilai bawaan di
repositori tetap generik untuk semua orang:

```php
<?php
return [
    'app'         => ['base_url' => 'https://panel.contoh.tld'],
    'letsencrypt' => ['email'    => 'admin@contoh.tld'],
];
```

Rahasia disimpan **di luar** repositori:

- `/etc/fpanel/app-key` — kunci enkripsi (SecretBox), mode `0640 root:www-data`;
- `/etc/fpanel/offsite.conf` — kredensial S3 offsite, mode `0600 root`.

---

## CLI

```bash
php bin/fpanel help              # daftar perintah
php bin/fpanel db:migrate        # jalankan skema
php bin/fpanel user:create admin 'Sandi!Kuat123' admin@example.tld admin
php bin/fpanel user:list
php bin/fpanel backup:create
php bin/fpanel pma:sweep
```

---

## Pengembangan

```bash
# CSS (Tailwind) — hasil dipasang ke app/public & web/mockup
./tools/build-css.sh

# Ikon (Lucide)
node tools/build-icons.mjs

# Uji integrasi (butuh stack terpasang; lihat header tests/run.sh)
sudo tests/run.sh
```

Suite uji integrasi menjalankan ±657 assertion yang mencakup alur domain,
database, SSL, backup, dan keamanan.

Installer bisa diuji di container bersih tanpa menyentuh server:

```bash
# butuh Docker (di macOS bisa lewat: brew install colima docker && colima start)
./tools/test-install-container.sh
```

Skrip itu menjalankan `install/install.sh` di Ubuntu 24.04 + systemd, lalu
memeriksa layanan, kunci panel, database, aturan sudo, dan respons HTTP —
termasuk memastikan installer aman dijalankan dua kali.

---

## Keamanan

- Panel tidak pernah menyimpan sandi MariaDB/SSH pengguna dalam bentuk terbuka.
- Operasi root hanya lewat helper yang diizinkan **per-perintah** di
  `/etc/sudoers.d/` — bukan `NOPASSWD: ALL`.
- Semua rahasia (kunci panel, kredensial S3) berada di `/etc/fpanel` dengan
  izin ketat dan **tidak** ikut ke repositori.
- Direktori `var/` (database, log, snapshot) diabaikan oleh git.

---

## Lisensi

MIT.
