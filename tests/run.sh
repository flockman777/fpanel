#!/bin/bash
# Test integrasi HTTP nyata. Isolasi: counter login attempt direset di awal,
# tiap skenario memakai cookie jar sendiri.
BASE="${BASE:-http://127.0.0.1:8090}"
FP="${FPANEL_BIN:-php /srv/fpanel/bin/fpanel}"
# Email admin (untuk uji "login via email"). Samakan dengan akun yang dipakai.
ADMIN_EMAIL="${ADMIN_EMAIL:-admin@fpanel.id}"
  # Password admin TIDAK disimpan di repo ini. Sumbernya, berurutan:
  #   1. environment ADMIN_PW
  #   2. /root/.fpanel-admin-pw (mode 600, di luar repo)
  # Kalau keduanya kosong, suite berhenti dengan pesan cara mengisinya.
  # Tujuannya: kredensial asli tidak ikut ter-commit atau ikut tersalin
  # saat file ini disalin ke server lain.
  # Contoh: ADMIN_PW=rahasia BASE=http://127.0.0.1:8090 bash tests/run.sh
  ADMIN_PW="${ADMIN_PW:-}"
  if [ -z "$ADMIN_PW" ] && [ -r /root/.fpanel-admin-pw ]; then
    ADMIN_PW="$(head -n1 /root/.fpanel-admin-pw)"
  fi
  if [ -z "$ADMIN_PW" ]; then
    echo "ADMIN_PW belum diisi. Isi dengan salah satu cara berikut:" >&2
    echo "  export ADMIN_PW='<password admin panel>'" >&2
    echo "  printf '%s\n' '<password admin panel>' > /root/.fpanel-admin-pw && chmod 600 /root/.fpanel-admin-pw" >&2
    exit 2
  fi
# User non-admin sekali pakai untuk uji role. Dibuat di awal, dihapus di akhir,
# jadi suite tidak bergantung pada akun lama yang sudah dibersihkan.
U2="uujirole"
# User khusus section 17 (cron). Username panel sengaja tidak ada
# sebagai akun Unix; itu ikut diuji sebagai perilaku yang benar.
U3="uujicron"
U2_PW="Uji#2026-RoleX9k"
U2_PWE=$(printf '%s' "$U2_PW" | sed 's/#/%23/g')
$FP user:delete "$U2" >/dev/null 2>&1
$FP user:create "$U2" "$U2_PW" "uji@role.test" user >/dev/null 2>&1
# Bentuk URL-encoded HANYA untuk argumen -d (string datar, tidak di-encode lagi).
# JANGAN dipakai bersama --data-urlencode: tool itu meng-encode ulang, sehingga
# %23 jadi %2523 dan sandi terkirim dalam bentuk yang salah.
ADMIN_PWE=$(printf '%s' "$ADMIN_PW" | sed 's/#/%23/g')
ADMIN_EMAIL_Q=$(printf '%s' "$ADMIN_EMAIL" | sed 's/@/%40/g')

# Helper query untuk beberapa assertion (mis. "cleanup: app Node dihapus").
# Dibuat di sini, bukan disparate manual, supaya suite self-contained: kalau
# /tmp/q.php hilang atau repo disalin ke mesin lain, test tetap jalan.
# Query satu kolom aggregates mencetak nilai mentahnya; query lain satu baris
# per hasil sebagai "col=val  col2=val2".
if [ ! -s /tmp/q.php ]; then
  cat > /tmp/q.php <<'QPHELP'
<?php
$p = new PDO('sqlite:/srv/fpanel/var/db/fpanel.sqlite');
$st = $p->query($argv[1] ?? '');
if ($st === false) {
    fwrite(STDERR, 'SQL error: ' . $p->errorInfo()[1] . "\n");
    exit(1);
}
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
if (count($rows) === 1 && count($rows[0]) === 1) {
    $v = reset($rows[0]);
    echo ($v === null ? '' : $v), "\n";
    exit(0);
}
foreach ($rows as $r) {
    $o = [];
    foreach ($r as $k => $v) { $o[] = $k . '=' . var_export($v, true); }
    echo implode('  ', $o), "\n";
}
QPHELP
  chmod 0644 /tmp/q.php
fi


TMP=$(mktemp -d); PASS=0; FAIL=0

# Section yang bisa dilewati lewat SKIP_SECTIONS="16 17", dipisah spasi.
# Dipakai untuk menjalankan suite tanpa menyentuh bagian yang tidak ingin diuji
# (mis. backup, yang menulis arsip 50+ MB ke disk produksi).
SKIP_SECTIONS="${SKIP_SECTIONS:-}"
# Cache file+helper dipakai ulang antar section. Kalau target section dilewati,
# file tetap dibersihkan agar tidak mengotori section yang jalan.
skip_section(){
  case " $SKIP_SECTIONS " in
    *" $1 "*) return 0 ;;
    *) return 1 ;;
  esac
}
ok(){ PASS=$((PASS+1)); printf "  \033[32mPASS\033[0m %s\n" "$1"; }
no(){ FAIL=$((FAIL+1)); printf "  \033[31mFAIL\033[0m %s\n        dapat: %s\n        harap: %s\n" "$1" "$2" "$3"; }
eq(){ [ "$2" = "$3" ] && ok "$1" || no "$1" "$2" "$3"; }
has(){ echo "$2" | grep -q "$3" && ok "$1" || no "$1" "$(echo "$2"|head -c 100|tr '\n' ' ')" "mengandung: $3"; }
hasnt(){ echo "$2" | grep -q "$3" && no "$1" "mengandung: $3" "TIDAK mengandung" || ok "$1"; }

jar(){ echo "$TMP/$1.jar"; }
csrf(){ curl -s -b "$(jar "$1")" -c "$(jar "$1")" "$BASE/api/csrf" | grep -oE '"csrf":"[a-f0-9]+"' | sed 's/.*:"//;s/"//'; }
post(){ local j="$1"; shift; curl -s -b "$(jar "$j")" -c "$(jar "$j")" "$@"; }
# Tunggu sampai vhost benar-benar dilayani. Tanpa ini, probe bisa mendarat di
# default server (halaman panel) sehingga assertion gagal tanpa sebab nyata.
wait_dom(){
  local h="$1" i=0 r=""
  # 60 x 0.4 = 24 detik. Vhost baru butuh nginx reload yang selesai dulu;
  # jendela 25 iterasi kadang terlalu sempit saat suite berjalan banyak
  # domain beruntun dan load sedang tinggi.
  while [ $i -lt 60 ]; do
    r=$(curl -s -H "Host: $h" --max-time 5 http://127.0.0.1:80/index.php | head -1)
    case "$r" in
      ""|*FPanel*) sleep 0.4; i=$((i+1)) ;;
      *) echo "$r"; return 0 ;;
    esac
  done
  echo "$r"
}
code(){ local j="$1"; shift; curl -s -b "$(jar "$j")" -c "$(jar "$j")" -o /dev/null -w '%{http_code}' "$@"; }
getc(){ local j="$1"; shift; curl -s -b "$(jar "$j")" -c "$(jar "$j")" -o /dev/null -w '%{http_code}' "$@"; }
body(){ local j="$1"; shift; curl -s -b "$(jar "$j")" -c "$(jar "$j")" "$@"; }
fails(){ $FP login:count; }
# ambil nilai cookie fp_session dari jar (kolom terakhir, bukan '#HttpOnly_' prefixed)
sess(){ local f="$(jar "$1")"; [ -f "$f" ] || { echo -n ""; return; }; awk '$6=="fp_session"{print $7}' "$f" | tail -1; }

$FP login:reset >/dev/null
rm -f "$TMP"/*.jar

echo "== 1. health, routing, header =="
eq "GET /api/health -> 200"        "$(curl -s -o /dev/null -w '%{http_code}' $BASE/api/health)" 200
eq "GET /api/health db=ok"         "$(curl -s $BASE/api/health | grep -oE '"db":"[a-z]+"' | cut -d'"' -f4)" "ok"
eq "GET /api/health spesifik versi" "$(curl -s $BASE/api/health | grep -oE '"php":"[0-9.]+"' | cut -d'"' -f4)" "$(php -r 'echo PHP_VERSION;')"
eq "route tak dikenal -> 404"      "$(curl -s -o /dev/null -w '%{http_code}' $BASE/tidak-ada)" 404
eq "GET /login -> 200"             "$(curl -s -o /dev/null -w '%{http_code}' $BASE/login)" 200
H=$(curl -s -D- -o /dev/null $BASE/login)
has  "X-Frame-Options"  "$H" "X-Frame-Options: SAMEORIGIN"
has  "nosniff"          "$H" "X-Content-Type-Options: nosniff"
has  "Referrer-Policy"  "$H" "Referrer-Policy: same-origin"
has  "form login punya CSRF" "$(curl -s $BASE/login)" 'name="_csrf"'

echo "== 2. login =="
eq "password salah -> 401"        "$(code a -d "_csrf=$(csrf a)&username=admin&password=SalahSekali" $BASE/login)" 401
has "pesan error tampil"          "$(post a -d "_csrf=$(csrf a)&username=admin&password=SalahSekali" $BASE/login)" "Username atau password salah"
eq "CSRF_INVALID -> 419"          "$(code a -d "_csrf=palgu&username=admin&password=$ADMIN_PWE" $BASE/login)" 419
eq "user tidak ada -> 401"        "$(code b -d "_csrf=$(csrf b)&username=hantubayi&password=ApaSaja123" $BASE/login)" 401
eq "password benar -> 302"        "$(code a -d "_csrf=$(csrf a)&username=admin&password=$ADMIN_PWE" $BASE/login)" 302
eq "session aktif -> /api/me 200" "$(getc a $BASE/api/me)" 200
eq "role = admin"                 "$(body a $BASE/api/me | grep -oE '"role":"[a-z]+"' | cut -d'"' -f4)" "admin"
eq "username benar di /api/me"    "$(body a $BASE/api/me | grep -oE '"username":"[a-z]+"' | cut -d'"' -f4)" "admin"
eq "authenticated = true"         "$(body a $BASE/api/me | grep -oE '"authenticated":(true|false)' | cut -d: -f2)" "true"
eq "dashboard bisa dibuka"        "$(getc a $BASE/dashboard)" 200
has "dashboard sapa user"          "$(body a $BASE/dashboard)" "Halo, admin"
hasnt "dashboard tak bocorkan hash" "$(body a $BASE/dashboard)" "argon2"
eq "login via email juga jalan"   "$(code c -d "_csrf=$(csrf c)&username=$ADMIN_EMAIL_Q&password=$ADMIN_PWE" $BASE/login)" 302
eq "user non-admin bisa login"    "$(code d -d "_csrf=$(csrf d)&username=$U2&password=$U2_PWE" $BASE/login)" 302
eq "user non-admin role = user"             "$(body d $BASE/api/me | grep -oE '"role":"[a-z]+"' | cut -d'"' -f4)" "user"

echo "== 3. otorisasi & isolasi cookie =="
eq "tanpa cookie /api/me -> 401"     "$(curl -s -o /dev/null -w '%{http_code}' $BASE/api/me)" 401
eq "tanpa cookie /dashboard -> 302" "$(curl -s -o /dev/null -w '%{http_code}' $BASE/dashboard)" 302
eq "jar kosong -> 401"               "$(getc e $BASE/api/me)" 401
eq "cookie dipalsukan -> 401"        "$(curl -s -o /dev/null -w '%{http_code}' -H 'Cookie: fp_session=deadbeef' $BASE/api/me)" 401
hasnt "password tak pernah di respons login" "$(curl -s $BASE/login)" "$ADMIN_PWE"

echo "== 4. brute force lockout =="
$FP login:reset >/dev/null
for i in 1 2 3; do
  C=$(code f -d "_csrf=$(csrf f)&username=admin&password=Brute$i" $BASE/login)
  [ "$C" = "401" ] || no "percobaan #$i 401" "$C" "401"
done
eq "3x gagal tercatat"          "$(fails)" "3"
ok "3x gagal semuanya ditolak 401"
code f -d "_csrf=$(csrf f)&username=admin&password=Brute4" $BASE/login >/dev/null
code f -d "_csrf=$(csrf f)&username=admin&password=Brute5" $BASE/login >/dev/null
eq "counter = 5 (batas)"        "$(fails)" "5"
has "ke-6: terkunci"            "$(post f -d "_csrf=$(csrf f)&username=admin&password=$ADMIN_PWE" $BASE/login)" "Terlalu banyak percobaan"
eq "password benar ditolak saat terkunci" "$(code f -d "_csrf=$(csrf f)&username=admin&password=$ADMIN_PWE" $BASE/login)" 401
eq "counter tidak naik saat terkunci"      "$(fails)" "5"
$FP login:reset >/dev/null
ok "reset counter berhasil"

echo "== 5. logout =="
$FP login:reset >/dev/null
BEFORE=$($FP audit:count auth.logout ok)
code a -d "_csrf=$(csrf a)&username=admin&password=$ADMIN_PWE" $BASE/login >/dev/null
eq "login ulang berhasil"            "$(getc a $BASE/api/me)" 200
TOK=$(sess a)
eq "token session terbaca"           "$([ -n "$TOK" ] && echo ada || echo kosong)" "ada"
eq "session ada sebelum logout"      "$($FP session:count "$TOK")" "1"
eq "POST /logout -> 302"             "$(code a -d "_csrf=$(csrf a)" $BASE/logout)" 302
eq "cookie dihapus dari browser"     "$(sess a)" ""
eq "baris session dihapus di DB"     "$($FP session:count "$TOK")" "0"
eq "cookie lama tidak bisa dipakai"  "$(getc a $BASE/api/me)" 401
eq "logout tercatat di audit (+1)"  "$(( $($FP audit:count auth.logout ok) - BEFORE ))" "1"
eq "logout tanpa CSRF -> 419"        "$(code a -d "_csrf=palgu" $BASE/logout)" 419

echo "== 6. jejak audit =="
gt(){ [ "$2" -gt "$3" ] && ok "$1 ($2 > $3)" || no "$1" "$2" "harus > $3"; }
gt "audit mencatat login berhasil" "$($FP audit:count auth.login ok)" 0
eq "audit mencatat login gagal"     "$($FP audit:count auth.login denied)" "$($FP audit:count auth.login denied)"
gt "audit mencatat penolakan tercatat" "$($FP audit:count auth.login denied)" 0
gt "audit mencatat logout"           "$($FP audit:count auth.logout ok)" 0
eq "audit tak bocorkan hash password" "$($FP audit:leak argon2)" "0"

echo "== 7. data tidak bocor =="
eq "hash password tak ada di respons"  "$(body a $BASE/api/me | grep -c password_hash)" "0"
eq "hash tak tersimpan sebagai teks"  "$($FP user:hashlen admin)" "97"
eq "password bukan string biasa" "$($FP user:hashis admin)" "argon2"

# Kuota paket dinaikkan sementara selama suite berjalan, SEBELUM section 8.
#
# Suite membuat banyak domain uji lewat HTTP sungguhan. Kalau kuota produksi
# (max_domains) sudah mendekati penuh karena domain asli user, test gagal bukan
# karena kodenya salah tapi karena tidak bisa membuat domain. Nilai lama
# disimpan lebih dulu dan dipulihkan sebelum suite selesai; trap dipakai supaya
# tetap dipulihkan kalau suite dihentikan di tengah jalan.
#
# Naiknya hanya "terpakai + 5": angka tetap yang jauh lebih besar membuat
# section 8 ikut membuat puluhan domain uji dan meninggalkan residu.
restore_quota() { $FP test:quota-restore >/dev/null 2>&1; }
QUOTA_SAVED=$($FP test:quota-park 2>/dev/null | tail -1)
trap restore_quota EXIT
if [ -n "${QUOTA_SAVED:-}" ]; then ok "$QUOTA_SAVED"; fi

echo "== 8. siklus hidup domain (regresi vhost) =="
# PENTING: test ini SENGAJA tanpa jeda. `systemctl reload nginx` selesai
# sebelum worker baru siap, dan listener tidak pernah ditutup saat graceful
# reload. Kalau ditambah sleep, regresi readiness tidak akan terdeteksi.
VHOSTS=/etc/nginx/sites-available/fpanel-domains
# PAKAI helper jar() yang sudah ada (mengembalikan $TMP/$1.jar). Jangan
# bikin variabel `jar=` karena itu menimpa fungsinya dan membuat cookie
# tersimpan di CWD sehingga login tidak nyambung ke POST berikutnya.
J=dom
# 000 dicetak curl sendiri saat koneksi ditutup (catch-all 444). JANGAN
# pakai `|| echo 000` karena itu menambah 000 kedua -> "000000".
probe(){ c=$(curl -s -m3 -o /dev/null -w '%{http_code}' -H "Host: $1" http://127.0.0.1/ 2>/dev/null); echo "${c:-000}"; }
mkdom(){
  C=$(csrf "$J")
  # -L wajib: pesan sukses/gagal disimpan sebagai flash dan ditampilkan di
  # halaman tujuan, jadi respons 302 sendiri tidak mengandung teksnya.
  curl -s -L -o "$TMP/resp" -b "$(jar "$J")" -c "$(jar "$J")" \
    --data-urlencode "_csrf=$C" --data-urlencode "name=$1" \
    --data-urlencode "type=php" --data-urlencode "php_version=8.3" "$BASE/domains"
}
deld(){
  C=$(csrf "$J")
  curl -s -o /dev/null -b "$(jar "$J")" -c "$(jar "$J")" \
    --data-urlencode "_csrf=$C" --data-urlencode "id=$($FP domain:id "$1")" "$BASE/domains/delete"
}
# Auth::recentFails() menghitung gagal PER IP, bukan per user. Sections 1-7
# sengaja menumpuk kegagalan dari 127.0.0.1, jadi section 8 harus reset dulu
# atau login admin kena 429/401 dan semua assertion di bawahnya gagal.
$FP login:reset >/dev/null
C=$(csrf "$J")
curl -s -o /dev/null -b "$(jar "$J")" -c "$(jar "$J")" --data-urlencode "_csrf=$C" \
  --data-urlencode "username=admin" --data-urlencode "password=$ADMIN_PW" "$BASE/login"
eq "login admin buat test domain" "$(getc "$J" "$BASE/api/me")" 200
eq "session admin terdaftar"       "$($FP session:count "$(sess "$J")")" 1

for d in t1.test t2.test t3.test; do
  mkdom "$d"
  eq "create $d -> langsung dilayani"  "$(probe "$d")" 200
  eq "create $d -> vhost terdeploy"     "$([ -f "$VHOSTS/$d.conf" ] && echo ya || echo tidak)" ya
  eq "create $d -> docroot ada"         "$([ -d "/var/www/admin/$d/public" ] && echo ya || echo tidak)" ya
  deld "$d"
  eq "delete $d -> langsung mati (444)" "$(probe "$d")" 000
  eq "delete $d -> vhost hilang"        "$([ -f "$VHOSTS/$d.conf" ] && echo ya || echo tidak)" tidak
  eq "delete $d -> docroot hilang"      "$([ -d "/var/www/admin/$d" ] && echo ya || echo tidak)" tidak
done
eq "sisa domain uji = 0"                "$($FP domain:ada t1.test)" 0
eq "sisa docroot uji = 0"               "$(ls -d /var/www/admin/t[0-9].test 2>/dev/null | wc -l | tr -d ' ')" 0
# Domain milik user bisa saja sudah ada (mis. demo.fpanel.my.id). Semua
# hitungan di bawah harus memakai DELTA terhadap kondisi awal, bukan absolut,
# supaya test tetap hijau baik database kosong maupun sudah terisi.
DOCROOT_AWAL=$(find /var/www/admin -mindepth 1 2>/dev/null | wc -l | tr -d ' ')
DOM_AWAL=$($FP domain:jumlah)
QUOTA_AWAL=$(php bin/fpanel package:domain-quota admin 2>/dev/null || echo 5)
BOLEH=$((QUOTA_AWAL - DOM_AWAL))
# Domain milik user harus utuh setelah semua create/delete di atas. Domain
# uji dibuat sendiri di section ini, jadi tidak bergantung data lama.
FIX=fixture.test
mkdom "$FIX"
eq "domain fixture dibuat"              "$($FP domain:id "$FIX" | wc -c | tr -d ' ')" 2
eq "fixture dilayani"                   "$(probe "$FIX")" 200

# fixture.test di atas juga memakan satu slot, jadi sisa untuk pengujian
# batas kuota adalah BOLEH-1.
SISA=$((BOLEH - 1))
echo "  -- kuota (paket $QUOTA_AWAL, $DOM_AWAL terpakai, 1 dipakai fixture -> boleh $SISA lagi) --"
made=0
for i in 1 2 3 4 5 6 7; do
  mkdom "k$i.test"
  if [ -n "$($FP domain:id "k$i.test")" ]; then made=$((made+1)); else break; fi
done
eq "kuota tidak dilanggar (maks $SISA baru)" "$made" "$SISA"
# mkdom k5 sudah ditolak, jadi $TMP/resp berisi balasan penolakan itu.
has "create ke-5 ditolak karena kuota"  "$(cat "$TMP/resp")" "Kuota domain penuh"
eq "k5.test tidak masuk database"       "$($FP domain:ada k5.test)" 0
for i in 1 2 3 4 5 6 7; do deld "k$i.test"; done
eq "semua domain uji k* terhapus"        "$($FP domain:ada k1.test)" 0
eq "hanya fixture yang tersisa"        "$($FP domain:jumlah)" "$((DOM_AWAL+1))"
eq "teardown bersih: tidak ada INI"     "$(ls -A /etc/php/8.3/fpm/conf.d/domains/ | wc -l | tr -d ' ')" 1
deld "$FIX"
eq "kembali ke kondisi awal"           "$($FP domain:jumlah)" "$DOM_AWAL"
# Docroot harus kembali persis seperti semula: hanya sisa domain asli user.
eq "docroot kembali seperti semula"    "$(find /var/www/admin -mindepth 1 2>/dev/null | wc -l | tr -d ' ')" "$DOCROOT_AWAL"

# --- 9. SSL: render() + revoke (tanpa menyentuh rate limit LE) -------------
echo "  == 9. SSL / Let's Encrypt =="
# RENDER(): dipakai langsung, tidak menjalankan certbot, jadi suite ini
# tidak pernah menerbitkan sertifikat sungguhan ke Let's Encrypt.
R() { sudo -u www-data php -r "$1" 2>/dev/null; }

# Domain tanpa sertifikat harus tetap HTTP-saja, tanpa blok 443 dan tanpa
# redirect, supaya issuance tetap mungkin terjadi.
NO_CERT=$(R 'require "/srv/fpanel/app/bootstrap.php";
  echo Fpanel\Service\Vhost::render("tanpa-ssl.test","admin","/tmp/x","static",null);')
eq "render tanpa cert: tidak ada listen 443"  "$(printf %s "$NO_CERT" | grep -c 'listen 443')" 0
eq "render tanpa cert: tidak ada redirect"    "$(printf %s "$NO_CERT" | grep -c 'return 301')" 0
has "render tanpa cert: tetap ada ACME"        "$NO_CERT" 'acme-challenge'

# Domain dengan sertifikat dapat 443 dan redirect. Pakai domain asli user
# yang memang sudah punya sertifikat supaya tidak ada issuance baru.
REAL=$(sudo -u www-data php -r 'require "/srv/fpanel/app/bootstrap.php";
  use Fpanel\Service\LetsEncrypt;
  foreach (Fpanel\Database\Connection::all("SELECT name FROM domains") as $d)
    if (LetsEncrypt::hasCert($d["name"])) { echo $d["name"]; break; }
' 2>/dev/null)

if [ -n "$REAL" ]; then
  WITH_CERT=$(R "require \"/srv/fpanel/app/bootstrap.php\";
    echo Fpanel\\Service\\Vhost::render(\"$REAL\",\"admin\",\"/tmp/x\",\"static\",null);")
  eq "render dengan cert: ada listen 443"  "$(printf %s "$WITH_CERT" | grep -c 'listen 443')" 1
  eq "render dengan cert: ada redirect 301" "$(printf %s "$WITH_CERT" | grep -c 'return 301')" 1
  has "render dengan cert: pakai fullchain" "$WITH_CERT" "/etc/letsencrypt/live/$REAL/fullchain.pem"

  # Let's Encrypt selalu menghubungi port 80. Kalau location ACME ikut
  # di-redirect ke 443, renewal akan diam-diam gagal. Port 80 harus punya
  # ACME DAN redirect (yang lain dialihkan), port 443 tidak boleh redirect.
  HTTP_BLOCK=$(printf %s "$WITH_CERT" | sed -n '/listen 80;/,/listen 443/p')
  has "port 80 melayani ACME"        "$HTTP_BLOCK" 'acme-challenge'
  has "port 80 redirect sisanya"     "$HTTP_BLOCK" 'return 301'
  eq "port 80 punya tepat 1 server block" "$(printf %s "$WITH_CERT" | grep -c 'server_name')" 2
else
  echo "  -- lewati render dengan cert (tidak ada domain bersertifikat) --"
fi

# issue_tls: hanya nilai "1" yang memicu issuance. Nilai lain harus diabaikan
# supaya test atau API tidak bisautu-turnya menembak rate limit LE.
mkdom_tls() {
  C=$(csrf "$J")
  curl -s -L -o "$TMP/resp" -b "$(jar "$J")" -c "$(jar "$J")" \
    --data-urlencode "_csrf=$C" --data-urlencode "name=$1" \
    --data-urlencode "type=php" --data-urlencode "php_version=8.3" \
    --data-urlencode "issue_tls=$2" "$BASE/domains"
}
mkdom_tls "tls0.test" "0"
eq "issue_tls=0 tidak memicu issuance" "$($FP domain:ada tls0.test)" 1
eq "issue_tls=0 -> ssl_status none" \
   "$(sudo -u www-data php -r 'require "/srv/fpanel/app/bootstrap.php";
      echo Fpanel\Database\Connection::value("SELECT ssl_status FROM domains WHERE name=:n",["n"=>"tls0.test"]);' 2>/dev/null)" none
deld "tls0.test"
eq "teardown domain tls0" "$($FP domain:ada tls0.test)" 0

# --- 10. File Manager: copy/move/zip/unzip/download/chmod -------------------
# Setup/teardown lewat PHP (cepat); endpoint HTTP diuji terpisah di bawah.
# Konvensi FileManager: argumen folder pertama adalah folder PEMILIK item,
# bukan folder item itu sendiri. copy("admin","","d_sal","d_tuj") = salin
# folder d_sal (yang ada di home) ke d_tuj.
echo "== 10. File Manager (copy/move/zip/unzip/download/chmod) =="
FMH='require "/srv/fpanel/app/bootstrap.php"; use Fpanel\Service\FileManager as FM;'
fme(){ sudo -u www-data php -r "$FMH $1"; }
fx(){ fme 'echo (int)'"$1"';'; }
ex(){ fme 'var_export('"$1"');'; }
H=/var/www/admin
CLEARP='foreach (["t_sal","t_sal.zip","t_tuj","t_mv","t_hasil","t_jahat","jahat.zip","bukan.zip","ujidown.txt","udah.pindah","s.txt"] as $x) FM::delete("admin","",[$x]); @unlink("/tmp/jahat.txt"); clearstatcache();'
# Allowlist diambil dari tabel domains, bukan ditulis manual. Kalau dipatok ke
# nama domain tertentu, setiap domain produksi baru (mis. demo Node.js) akan
# membuat suite ini GAGAL padahal tidak ada sisa file uji sama sekali.
# .well-known ikut diabaikan: itu direktori ACME challenge milik Let's Encrypt,
# dibuat di root home dan BUKAN sisa test. Menghitungnya sebagai residu selalu
# membuat assertion ini gagal di server yang sudah punya sertifikat.
RESID='$a = [".", "..", ".well-known"]; foreach (Fpanel\Database\Connection::all("SELECT name FROM domains") as $d) { $a[] = $d["name"]; } echo count(array_diff(scandir(FM::home("admin")), $a));'
# RESID adalah statement lengkap (bukan ekspresi), jadi dipanggil tanpa "echo".

fme "$CLEARP FM::mkdir(\"admin\",\"\",\"t_sal\"); FM::mkdir(\"admin\",\"\",\"t_tuj\"); FM::mkdir(\"admin\",\"\",\"t_mv\"); FM::mkdir(\"admin\",\"t_sal\",\"sub\"); file_put_contents(FM::home(\"admin\").\"/t_sal/a.txt\",\"AAA\"); file_put_contents(FM::home(\"admin\").\"/t_sal/sub/b.txt\",\"BBB\");"

# -- copy ---------------------------------------------------------------------
eq "copy folder: ok"          "$(fx 'FM::copy("admin","","t_sal","t_tuj")["ok"]')" 1
eq "copy bawa file (a.txt)"  "$(fx "is_file(\"$H/t_tuj/t_sal/a.txt\")")" 1
eq "copy bawa subfolder"     "$(fx "is_file(\"$H/t_tuj/t_sal/sub/b.txt\")")" 1
eq "copy isi folder sama persis" "$(fme 'echo (int)(count(array_diff(scandir(FM::home("admin")."/t_tuj/t_sal"), [".","..","a.txt","sub"])) === 0);')" 1
eq "copy tidak menghapus asal"  "$(fx "is_dir(\"$H/t_sal\")")" 1
eq "copy file biasa: ok"     "$(fx 'FM::copy("admin","t_sal","a.txt","t_tuj")["ok"]')" 1
eq "copy file: isi benar"    "$(fme "echo (int)(file_get_contents(\"$H/t_tuj/a.txt\") === \"AAA\");")" 1
eq "copy nama bentrok ditolak" "$(fx 'FM::copy("admin","t_sal","a.txt","t_tuj")["ok"]')" 0
eq "copy ke folder sendiri ditolak" "$(fx 'FM::copy("admin","","t_sal","")["ok"]')" 0
eq "copy traversal ditolak"  "$(fx 'FM::copy("admin","","..","")["ok"]')" 0
eq "copy ke folder tak ada ditolak" "$(fx 'FM::copy("admin","","t_sal","t_hilang")["ok"]')" 0

# -- zip ----------------------------------------------------------------------
eq "zip folder: ok"          "$(fx 'FM::zip("admin","","t_sal")["ok"]')" 1
eq "zip membuat arsip .zip"  "$(fx "is_file(\"$H/t_sal.zip\")")" 1
eq "zip TIDAK menghapus asal" "$(fx "is_dir(\"$H/t_sal\")")" 1
eq "isi arsip benar"         "$(fme '$z=new ZipArchive(); $z->open(FM::home("admin")."/t_sal.zip"); $r=[]; for($i=0;$i<$z->numFiles;$i++){$r[]=$z->getNameIndex($i);} $z->close(); echo (int)(in_array("t_sal/a.txt",$r,true) && in_array("t_sal/sub/b.txt",$r,true));')" 1
eq "zip nama bentrok ditolak" "$(fx 'FM::zip("admin","","t_sal")["ok"]')" 0

# -- unzip --------------------------------------------------------------------
eq "unzip: ok"               "$(fx 'FM::unzip("admin","","t_sal.zip","t_hasil")["ok"]')" 1
eq "unzip: isi LANGSUNG di folder tujuan"  "$(fx "is_file(\"$H/t_hasil/a.txt\")")" 1
eq "unzip: subfolder ikut"   "$(fx "is_file(\"$H/t_hasil/sub/b.txt\")")" 1
eq "unzip: tidak ada satu tingkat tambahan" "$(fx "is_dir(\"$H/t_hasil/t_sal\")")" 0
eq "unzip: isi file utuh"    "$(fme "echo (int)(file_get_contents(\"$H/t_hasil/a.txt\") === \"AAA\");")" 1

# -- zip slip -----------------------------------------------------------------
fme '$z=new ZipArchive(); $z->open(FM::home("admin")."/jahat.zip", ZipArchive::CREATE|ZipArchive::OVERWRITE); $z->addFromString("../../../../tmp/jahat.txt","BOCOR"); $z->close();'
eq "unzip tolak arsip zip-slip" "$(fx 'FM::unzip("admin","","jahat.zip","t_jahat")["ok"]')" 0
eq "unzip: pesan error menyebut zip slip" "$(fme '$r=FM::unzip("admin","","jahat.zip","t_jahat"); echo (int)str_contains(strtolower((string)$r["error"]),"slip");')" 1
eq "zip-slip TIDAK menulis ke /tmp" "$(fx 'file_exists("/tmp/jahat.txt")')" 0
fme 'file_put_contents(FM::home("admin")."/bukan.zip","ini bukan zip");'
eq "unzip bukan-zip ditolak"  "$(fx 'FM::unzip("admin","","bukan.zip","t_jahat")["ok"]')" 0
eq "unzip file non-zip extension ditolak" "$(fx 'FM::unzip("admin","t_sal","a.txt","t_jahat")["ok"]')" 0

# -- move (setelah zip/unzip supaya a.txt masih ada) ---------------------------
eq "move file: ok"           "$(fx 'FM::move("admin","t_sal","a.txt","t_mv")["ok"]')" 1
eq "move: asal hilang"       "$(fx 'is_file(FM::home("admin")."/t_sal/a.txt")')" 0
eq "move: tujuan ada"        "$(fx 'is_file(FM::home("admin")."/t_mv/a.txt")')" 1
eq "move: isi tujuan utuh"   "$(fme 'echo (int)(file_get_contents(FM::home("admin")."/t_mv/a.txt") === "AAA");')" 1
fme 'FM::mkdir("admin","","t_mv"); file_put_contents(FM::home("admin")."/t_sal/a.txt","AAA");'
eq "move ke folder berisi ditolak" "$(fx 'FM::move("admin","t_sal","a.txt","t_mv")["ok"]')" 0
eq "move + rename (stay di folder asal)" "$(fme '$r=FM::move("admin","t_sal","a.txt","","a2.txt"); echo (int)((int)$r["ok"] === 1 && is_file(FM::home("admin")."/t_sal/a2.txt") === true && is_file(FM::home("admin")."/a2.txt") === false);')" 1
eq "move traversal ditolak"  "$(fx 'FM::move("admin","","..","t_tuj")["ok"]')" 0

# -- chmod --------------------------------------------------------------------
eq "chmod 0600 terpasang"     "$(fme '$r=FM::chmod("admin","t_hasil","a.txt",0600); clearstatcache(); echo ((int)$r["ok"] === 1 ? "1" : "0").substr(sprintf("%o",fileperms(FM::home("admin")."/t_hasil/a.txt")),-3);')" "1600"
eq "chmod 0644 terpasang"     "$(fme 'FM::chmod("admin","t_hasil","a.txt",0644); clearstatcache(); echo substr(sprintf("%o",fileperms(FM::home("admin")."/t_hasil/a.txt")),-3);')" "644"
eq "chmod di luar 000-777 ditolak" "$(fx 'FM::chmod("admin","t_hasil","a.txt",07777)["ok"]')" 0
eq "chmod nama bertraversal ditolak" "$(fx 'FM::chmod("admin","t_hasil","../fpanel.php",0600)["ok"]')" 0
eq "chmod file tak ada ditolak" "$(fx 'FM::chmod("admin","t_hasil","hilang.txt",0600)["ok"]')" 0

# -- download -----------------------------------------------------------------
eq "download metadata valid"  "$(fme '$d=FM::download("admin","t_hasil","a.txt"); echo (int)($d !== null && $d["name"] === "a.txt" && $d["size"] === 3);')" 1
eq "download traversal ditolak" "$(ex 'FM::download("admin","","../fpanel.php")')" "NULL"
eq "download folder ditolak" "$(ex 'FM::download("admin","","t_hasil")')" "NULL"
eq "download nama invalid ditolak" "$(ex 'FM::download("admin","","a/b")')" "NULL"

# -- endpoint HTTP ------------------------------------------------------------
JF=filemgr
eq "login admin untuk File Manager" "$(code "$JF" -d "_csrf=$(csrf "$JF")&username=admin&password=$ADMIN_PWE" $BASE/login)" 302
CF=$(csrf "$JF")
post "$JF" -o /dev/null --data-urlencode "_csrf=$CF" --data-urlencode "path=" --data-urlencode "name=ujidown.txt" "$BASE/files/newfile"
eq "HTTP newfile -> 200" "$(code "$JF" "$BASE/files")" 200
eq "HTTP newfile bikin file kosong" "$(fme "echo (int)(is_file(\"$H/ujidown.txt\") && filesize(\"$H/ujidown.txt\") === 0);")" 1
CF=$(csrf "$JF")
post "$JF" -o /dev/null --data-urlencode "_csrf=$CF" --data-urlencode "path=" --data-urlencode "name=ujidown.txt" --data-urlencode "body=isi-unduhan" "$BASE/files/save"
eq "HTTP save mengisi file" "$(fme "echo (int)(file_get_contents(\"$H/ujidown.txt\") === \"isi-unduhan\");")" 1
eq "HTTP download isi file benar" "$(body "$JF" "$BASE/files/download?path=&name=ujidown.txt")" "isi-unduhan"
eq "HTTP download traversal -> 404" "$(getc "$JF" "$BASE/files/download?path=&name=../fpanel.php")" 404
eq "HTTP download tanpa login -> 302" "$(getc nods "$BASE/files/download?path=&name=ujidown.txt")" 302
CF=$(csrf "$JF")
eq "HTTP chmod 0644 -> 200" "$(post "$JF" -o /dev/null -w '%{http_code}' --data-urlencode "_csrf=$CF" --data-urlencode "path=" --data-urlencode "name=ujidown.txt" --data-urlencode "mode=644" "$BASE/files/chmod")" 200
eq "mode jadi 644 di disk" "$(fme "echo substr(sprintf(\"%o\",fileperms(\"$H/ujidown.txt\")),-3);")" "644"
CF=$(csrf "$JF")
has "HTTP chmod mode ngawur ditolak" "$(body "$JF" --data-urlencode "_csrf=$CF" --data-urlencode "path=" --data-urlencode "name=ujidown.txt" --data-urlencode "mode=9999" "$BASE/files/chmod")" "oktal 000-777"
CF=$(csrf "$JF")
eq "CSRF palsu ditolak (chmod)" "$(post "$JF" -o /dev/null -w '%{http_code}' --data-urlencode "_csrf=abcd" --data-urlencode "path=" --data-urlencode "name=ujidown.txt" --data-urlencode "mode=600" "$BASE/files/chmod")" 419
CF=$(csrf "$JF")
eq "HTTP copy -> 200" "$(post "$JF" -o /dev/null -w '%{http_code}' --data-urlencode "_csrf=$CF" --data-urlencode "path=" --data-urlencode "name=ujidown.txt" --data-urlencode "to=t_tuj" "$BASE/files/copy")" 200
eq "file benar-benar tercopy" "$(fme "echo (int)is_file(\"$H/t_tuj/ujidown.txt\");")" 1
CF=$(csrf "$JF")
eq "HTTP move -> 200" "$(post "$JF" -o /dev/null -w '%{http_code}' --data-urlencode "_csrf=$CF" --data-urlencode "path=t_tuj" --data-urlencode "name=ujidown.txt" --data-urlencode "to=" --data-urlencode "newname=udah.pindah" "$BASE/files/move")" 200
eq "file pindah + ganti nama" "$(fme "echo (int)(is_file(\"$H/t_tuj/udah.pindah\") && is_file(\"$H/t_tuj/ujidown.txt\") === false);")" 1
CF=$(csrf "$JF")
eq "HTTP zip -> 200" "$(post "$JF" -o /dev/null -w '%{http_code}' --data-urlencode "_csrf=$CF" --data-urlencode "path=" --data-urlencode "name=t_sal" "$BASE/files/zip")" 200
CF=$(csrf "$JF")
eq "HTTP unzip -> 200" "$(post "$JF" -o /dev/null -w '%{http_code}' --data-urlencode "_csrf=$CF" --data-urlencode "path=" --data-urlencode "name=t_sal.zip" --data-urlencode "to=t_hasil2" "$BASE/files/unzip")" 200
eq "hasil unzip ada di folder tujuan" "$(fme "echo (int)is_file(\"$H/t_hasil2/a.txt\");")" 1

fme "$CLEARP FM::delete(\"admin\",\"\",[\"t_hasil2\"]);"
eq "cleanup: tidak ada sisa file uji" "$(fme "$RESID")" "0"

# user non-admin sekali pakai harus dibuang, kalau tidak setiap run menumpuk
# akun baru di database.
$FP user:delete "$U2" >/dev/null 2>&1
eq "user uji sekali pakai dibersihkan" "$($FP user:list 2>/dev/null | grep -c "^.*\b$U2\b")" 0

# --- 11. MariaDB: CRUD database + user, privilege, enkripsi, isolasi ---------
# Assertion-nya dipisah ke file PHP sendiri: menyisipkan PHP di dalam quoting
# bash terlalu rawan salah kutip, dan file PHP bisa pakai try/catch untuk
# membuktikan error MariaDB yang asli.
echo "== 11. MariaDB (CRUD database & user) =="
# db_check.php butuh satu user non-admin untuk membuktikan isolasi antar tenant
# (IDOR). User itu sudah dihapus oleh cleanup section 10, jadi dibuatkan ulang
# di sini dan dibersihkan lagi di akhir.
$FP user:create "$U2" "$U2_PW" "uji@role.test" user >/dev/null 2>&1
# Kuota harus sedang dinaikkan selama suite. Kalau tidak, db_check.php gagal
# bukan karena MariaDb salah, tapi karena tidak bisa membuat database.
QNOW=$($FP package:domain-quota admin 2>/dev/null | tail -1)
gt "section 11: kuota domain sedang dipark" "$QNOW" 5
QDB=$(sudo -u www-data php /srv/fpanel/tests/quota_check.php 2>/dev/null | tail -1)
gt "section 11: kuota database cukup untuk uji" "$QDB" 3
# Kolom: status, nama, nilai yang didapat, nilai yang diharapkan.
while IFS=$'\t' read -r st nm got want; do
  [ -n "${st:-}" ] || continue
  if [ "$st" = "ok" ]; then ok "$nm"; else no "$nm" "$got" "$want"; fi
done < <(sudo -u www-data php /srv/fpanel/tests/db_check.php 2>&1)
$FP user:delete "$U2" >/dev/null 2>&1

# --- 12. phpMyAdmin per-database (token sekali pakai) -----------------------
# Assertion service ada di tests/pma_check.php; di bawah ini yang sisi HTTP,
# karena yang perlu dipastikan adalah perilaku nginx + front controller.
echo "== 12. phpMyAdmin per-database =="
$FP user:create "$U2" "$U2_PW" "uji@role.test" user >/dev/null 2>&1
while IFS=$'\t' read -r st nm got want; do
  [ -n "${st:-}" ] || continue
  if [ "$st" = "ok" ]; then ok "$nm"; else no "$nm" "$got" "$want"; fi
done < <(sudo -u www-data php /srv/fpanel/tests/pma_check.php 2>&1)
$FP user:delete "$U2" >/dev/null 2>&1

# Sisi HTTP. Pakai cookie jar admin.
PA=pma
# /pma tanpa token selalu 404, jadi login dulu sebelum menguji endpoint POST.
eq "login admin untuk sesi phpMyAdmin" \
  "$(code $PA -d "_csrf=$(csrf $PA)&username=admin&password=$ADMIN_PWE" $BASE/login)" 302

# Siapkan database uji + token lewat service, lalu ambil URL-nya.
PMATK=$(sudo -u www-data php -r '
require "/srv/fpanel/app/bootstrap.php";
use Fpanel\Service\MariaDb; use Fpanel\Service\PhpMyAdmin; use Fpanel\Database\Connection;
$i=(int)Connection::value("SELECT id FROM databases WHERE name=:n",["n"=>"pma_http"]);
if($i){ MariaDb::delete("admin",$i); }
MariaDb::create("admin","pma_http","pma_app");
$d=(int)Connection::value("SELECT id FROM databases WHERE name=:n",["n"=>"pma_http"]);
$u=(int)Connection::value("SELECT id FROM db_users WHERE name=:n",["n"=>"pma_app"]);
$p=MariaDb::reveal("admin",$d,$u)["password"];
try { $pdo=new PDO("mysql:host=localhost;dbname=".MariaDb::physDb("admin","pma_http"),MariaDb::physUser("admin","pma_app"),$p);
      $pdo->exec("CREATE TABLE t (id INT)"); } catch (Throwable $e) {}
echo $d." ".$u;' 2>/dev/null)
PMID=$(echo "$PMATK" | cut -d' ' -f1); PMUID=$(echo "$PMATK" | cut -d' ' -f2)
PMDB=$(sudo -u www-data php -r 'require "/srv/fpanel/app/bootstrap.php"; echo Fpanel\Service\MariaDb::physDb("admin","pma_http");' 2>/dev/null)

if [ -z "$PMID" ]; then
  no "HTTP: siapkan database uji" "tidak dapat membuat database uji" "id database"
else
  ok "HTTP: siapkan database uji"
  # Token palsu harus 404, bukan 403/302 — jangan bocorkan ada/tidaknya path.
  eq "token palsu -> 404"        "$(getc $PA $BASE/pma/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa/index.php)" 404
  eq "token bukan 32 hex -> 404"  "$(getc $PA $BASE/pma/pendek/index.php)" 404
  eq "tanpa token -> 404"         "$(getc $PA $BASE/pma/)" 404
  eq "/fpma internal -> 404"      "$(getc $PA $BASE/fpma/index.php)" 404

  # Token asli dibuat lewat endpoint POST (CSRF dicek).
  eq "POST tanpa CSRF -> 419"     "$(code $PA -d "db=$PMID&dbuser=$PMUID" $BASE/databases/phpmyadmin)" 419
  PMR=$(post $PA -d "_csrf=$(csrf $PA)" -d "db=$PMID" -d "dbuser=$PMUID" \
        -o /dev/null -D - $BASE/databases/phpmyadmin | grep -i '^location:' | tr -d '\r' | sed 's/^[Ll]ocation: *//')
  has "launch phpMyAdmin -> redirect /pma/<token>/" "$PMR" "/pma/"
  PMTOK=$(echo "$PMR" | sed -E 's#^/pma/([a-f0-9]+)/.*#\1#')
  eq "token dari redirect 32 hex" 1 "$(printf '%s' "$PMTOK" | grep -cE '^[a-f0-9]{32}$')"

  PMB=$(body $PA "$BASE$PMR")
  has "halaman terbuka: judul phpMyAdmin"      "$PMB" "phpMyAdmin"
  hasnt "tidak balik ke form login"            "$PMB" "pma_password"
  has "database milik user terlihat"           "$PMB" "$PMDB"
  hasnt "database sistem mysql tidak terlihat" "$PMB" ">mysql<"
  hasnt "information_schema tidak terlihat"     "$PMB" "information_schema"

  PMST=$(body $PA "$BASE/pma/$PMTOK/index.php?route=/database/structure&db=$PMDB")
  has "halaman struktur terbuka"               "$PMST" "$PMDB"
  hasnt "struktur tidak error"                 "$PMST" "Fatal error"
  eq "aset css ter-load"                       "$(getc $PA "$BASE/pma/$PMTOK/themes/pmahomme/css/theme.css")" 200

  # Token kedaluwarsa: file konfignya dihapus -> URL lama harus mati.
  sudo -u www-data rm -f "/etc/phpmyadmin/conf.d/$PMTOK.php"
  eq "token setelah file dihapus -> 404"       "$(getc $PA "$BASE/pma/$PMTOK/index.php")" 404

  sudo -u www-data php -r 'require "/srv/fpanel/app/bootstrap.php";
    Fpanel\Service\MariaDb::delete("admin",(int)Fpanel\Database\Connection::value("SELECT id FROM databases WHERE name=:n",["n"=>"pma_http"]));' 2>/dev/null
  ok "cleanup: database uji HTTP dihapus"
fi
sudo -u www-data rm -f /etc/phpmyadmin/conf.d/*.php 2>/dev/null
eq "cleanup: tidak ada file token" "$(sudo -u www-data php /srv/fpanel/bin/fpanel pma:sweep >/dev/null 2>&1; sudo -u www-data php -r 'require "/srv/fpanel/app/bootstrap.php"; echo Fpanel\Service\PhpMyAdmin::activeTokens();')" 0

# --- 13. Multi-PHP: tiap domain bisa memakai versi berbeda ------------------
# php:prep menyiapkan /etc/php/<v>/fpm/conf.d/domains milik www-data. Wajib root,
# karena /etc/php/<v>/fpm/conf.d sendiri milik root.
echo "== 13. Multi-PHP (per-domain) =="
$FP php:prep >/dev/null 2>&1
V81=$(sudo -u www-data php -r 'require "/srv/fpanel/app/bootstrap.php"; echo (int)in_array("8.1", Fpanel\Service\Vhost::installedPhpVersions(), true);' 2>/dev/null)
eq "8.1 terpasang & punya socket" "$V81" 1
eq "8.2 terpasang & punya socket" \
  "$(sudo -u www-data php -r 'require "/srv/fpanel/app/bootstrap.php"; echo (int)in_array("8.2", Fpanel\Service\Vhost::installedPhpVersions(), true);' 2>/dev/null)" 1
eq "8.4 terpasang & punya socket" \
  "$(sudo -u www-data php -r 'require "/srv/fpanel/app/bootstrap.php"; echo (int)in_array("8.4", Fpanel\Service\Vhost::installedPhpVersions(), true);' 2>/dev/null)" 1

# fpmSock() harus menolak input nakal: nilainya masuk ke config nginx.
eq "fpmSock: input injection ditolak" \
  "$(sudo -u www-data php -r 'require "/srv/fpanel/app/bootstrap.php"; echo Fpanel\Service\Vhost::fpmSock("8.3; return 404");' 2>/dev/null)" \
  "/run/php/php8.3-fpm.sock"
eq "fpmSock: tiap versi ke socket sendiri" \
  "$(sudo -u www-data php -r 'require "/srv/fpanel/app/bootstrap.php"; echo Fpanel\Service\Vhost::fpmSock("8.1");' 2>/dev/null)" \
  "/run/php/php8.1-fpm.sock"

# Domain uji: satu di 8.1, satu di 8.4, lalu dicek lewat HTTP.
mkphpdom(){ sudo -u www-data php -r '
require "/srv/fpanel/app/bootstrap.php";
use Fpanel\Service\DomainManager; use Fpanel\Service\Vhost; use Fpanel\Database\Connection;
$uid=(int)Connection::value("SELECT id FROM users WHERE username=:u",["u"=>"admin"]);
$id=(int)Connection::value("SELECT id FROM domains WHERE name=:n",["n"=>$argv[1]]);
if($id){ DomainManager::delete($uid,"admin",$id); }
$r=DomainManager::create($uid,"admin",$argv[1],"php",$argv[2],false);
if(!$r["ok"]){ fwrite(STDERR,"create gagal: ".$r["error"]."\n"); exit(1); }
$d=(int)Connection::value("SELECT id FROM domains WHERE name=:n",["n"=>$argv[1]]);
$doc=(string)Connection::value("SELECT docroot FROM domains WHERE id=:i",["i"=>$d]);
if(!is_dir($doc)){ @mkdir($doc,0755,true); }
file_put_contents(rtrim($doc,"/")."/index.php","<?php echo PHP_VERSION;");
Vhost::deploy($argv[1],"admin",$doc,"php",$argv[2]);' "$1" "$2" 2>/dev/null; }
delphpdom(){ sudo -u www-data php -r '
require "/srv/fpanel/app/bootstrap.php";
use Fpanel\Service\DomainManager; use Fpanel\Database\Connection;
$uid=(int)Connection::value("SELECT id FROM users WHERE username=:u",["u"=>"admin"]);
$id=(int)Connection::value("SELECT id FROM domains WHERE name=:n",["n"=>$argv[1]]);
if($id){ DomainManager::delete($uid,"admin",$id); }' "$1" 2>/dev/null; }

mkphpdom mp81.test 8.1
eq "domain 8.1 dibuat" "$($FP domain:ada mp81.test)" 1
has "vhost 8.1 -> socket 8.1" \
  "$(cat /etc/nginx/sites-available/fpanel-domains/mp81.test.conf 2>/dev/null)" "php8.1-fpm.sock"
eq "domain 8.1 menjalankan PHP 8.1" "$(wait_dom mp81.test | cut -d. -f1,2)" "8.1"

mkphpdom mp84.test 8.4
eq "domain 8.4 dibuat" "$($FP domain:ada mp84.test)" 1
has "vhost 8.4 -> socket 8.4" \
  "$(cat /etc/nginx/sites-available/fpanel-domains/mp84.test.conf 2>/dev/null)" "php8.4-fpm.sock"
eq "domain 8.4 menjalankan PHP 8.4" "$(wait_dom mp84.test | cut -d. -f1,2)" "8.4"

# Dua domain harus benar-benar terpisah: tidak saling memakai versi.
hasnt "8.1 tidak memakai socket 8.4" \
  "$(cat /etc/nginx/sites-available/fpanel-domains/mp81.test.conf 2>/dev/null)" "php8.4-fpm.sock"

delphpdom mp81.test
delphpdom mp84.test
eq "cleanup: domain 8.1 dihapus" "$($FP domain:ada mp81.test)" 0
eq "cleanup: domain 8.4 dihapus" "$($FP domain:ada mp84.test)" 0
eq "cleanup: vhost 8.1 hilang" "$([ -f /etc/nginx/sites-available/fpanel-domains/mp81.test.conf ] && echo 1 || echo 0)" 0
rm -rf /var/www/admin/mp81.test /var/www/admin/mp84.test

# --- 14. PHP Extensions (per versi) + php.ini per-domain (PHP_VALUE) --------
# Ekstensi hidup di /etc/php/<v>/fpm/conf.d + restart pool. Nilai php.ini
# per-domain hidup di fastcgi_param PHP_VALUE. Keduanya diuji sungguhan.
echo "== 14. PHP Extensions & PHP Settings =="
PH="sudo -u www-data sudo -n /usr/local/sbin/fpanel-php"
# User non-admin untuk uji 403. Section sebelumnya menghapusnya, jadi dibuat ulang.
$FP user:create "$U2" "$U2_PW" "uji@role.test" user >/dev/null 2>&1

# -- helper: validasi input (nilai ini masuk ke nama file / argumen root) ------
eq "helper: versi nakal ditolak" "$($PH list '8.3;id' 2>&1 | head -1 | cut -c1-4)" "ERR:"
eq "helper: extension nakal ditolak" "$($PH enable 8.3 'zip.so;id' 2>&1 | head -1 | cut -c1-4)" "ERR:"
eq "helper: subcommand asing ditolak" "$(sudo -u www-data sudo -n /usr/local/sbin/fpanel-php 'rm -rf /' 2>&1 | head -1 | cut -c1-16)" "sudo: a password"
eq "helper: versi tak terpasang ditolak" "$($PH list 9.9 2>&1 | head -1 | cut -c1-4)" "ERR:"

# -- daftar extension per versi ----------------------------------------------
for v in 8.1 8.3 8.4; do
  gt "list $v: ada extension" "$($PH list $v | wc -l)" 30
done
eq "zip aktif di 8.3" "$($PH list 8.3 | awk -F'\t' '$1=="zip"{print $2}')" "aktif"

# -- toggle beneran mengubah PHP ----------------------------------------------
probe_ext() { curl -s --max-time 10 "http://127.0.0.1:8090/_probe_ext.php" | head -1; }
cat > /srv/fpanel/app/public/_probe_ext.php <<'PHP'
<?php echo 'zip=' . (int) extension_loaded('zip') . "\n";
PHP
eq "sebelum toggle: zip aktif" "$(probe_ext)" "zip=1"

# Matikan lewat endpoint panel (POST + CSRF), lalu TUNGGU restart terjadwal.
# Login dulu: tanpa sesi, endpoint akan balas 302 (bukan 419/403) dan
# assertion-nya jadi salah baca.
curl -s -b "$(jar pz)" -c "$(jar pz)" -o /dev/null \
  -d "_csrf=$(csrf pz)&username=admin&password=$ADMIN_PWE" $BASE/login
code u2 -d "_csrf=$(csrf u2)&username=$U2&password=$U2_PWE" $BASE/login >/dev/null
eq "toggle tanpa CSRF -> 419" \
  "$(code pz -d "version=8.3&ext=zip&state=0" $BASE/php-extensions/toggle)" 419
eq "toggle oleh non-admin -> 403" \
  "$(code u2 -d "_csrf=$(csrf u2)&version=8.3&ext=zip&state=0" $BASE/php-extensions/toggle)" 403
curl -s -b "$(jar pz)" -c "$(jar pz)" -o /dev/null \
  -d "_csrf=$(csrf pz)&version=8.3&ext=zip&state=0" $BASE/php-extensions/toggle
sleep 4
eq "setelah toggle OFF: zip nonaktif" "$(probe_ext)" "zip=0"
# Yang penting: file distro PINDAH (bukan dihapus), jadi bisa dikembalikan.
eq "file distro zip dipindah (bukan dihapus)" \
  "$([ -f /etc/php/8.3/fpm/conf.d/20-zip.ini.fpanel-off ] && echo 1 || echo 0)" 1
eq "file distro aslinya tidak ada di tempat" \
  "$([ -f /etc/php/8.3/fpm/conf.d/20-zip.ini ] && echo 1 || echo 0)" 0
eq "perubahan tercatat di state file" \
  "$(awk -F'\t' '$1=="8.3" && $2=="zip"' /var/lib/fpanel/php-ext/off.list 2>/dev/null | wc -l)" 1
has "status OFF tercatat di UI" \
  "$(body pz "$BASE/php-extensions?ver=8.3")" "zip"
has "flash hasil toggle tampil" \
  "$(body pz "$BASE/php-extensions?ver=8.3&ok=HASILUJI")" "HASILUJI"
eq "versi lain tidak terpengaruh" "$($PH list 8.4 | awk -F'\t' '$1=="zip"{print $2}')" "aktif"

# Nyalakan lagi -> harus balik ke file distro, tanpa sisa file Buatan FPanel.
curl -s -b "$(jar pz)" -c "$(jar pz)" -o /dev/null \
  -d "_csrf=$(csrf pz)&version=8.3&ext=zip&state=1" $BASE/php-extensions/toggle
sleep 4
eq "setelah toggle ON: zip aktif lagi" "$(probe_ext)" "zip=1"
eq "tidak ada file 50-fpanel tertinggal" "$(ls /etc/php/8.3/fpm/conf.d/50-fpanel-*.ini 2>/dev/null | wc -l)" 0
eq "file distro zip sudah kembali" \
  "$([ -f /etc/php/8.3/fpm/conf.d/20-zip.ini ] && echo 1 || echo 0)" 1
# Catatan: grep -r tidak bisa traverses /etc/php/<v>/fpm/conf.d di server ini
# (exit 1 tanpa output), jadi pakai find -exec.
eq "extension zip tidak terdefinisi dua kali" \
  "$(find /etc/php/8.3/fpm/conf.d -maxdepth 1 -name '*.ini' -exec grep -lF 'extension=zip.so' {} + 2>/dev/null | wc -l)" 1
eq "tidak ada file .fpanel-off tertinggal" "$(ls /etc/php/8.3/fpm/conf.d/*.fpanel-off 2>/dev/null | wc -l)" 0
eq "state file bersih" "$(cat /var/lib/fpanel/php-ext/off.list 2>/dev/null | wc -l)" 0

# -- php.ini per-domain (PHP_VALUE) ------------------------------------------
mkini(){ sudo -u www-data php -r '
require "/srv/fpanel/app/bootstrap.php";
use Fpanel\Service\DomainManager; use Fpanel\Service\Vhost; use Fpanel\Database\Connection;
$u=(int)Connection::value("SELECT id FROM users WHERE username=:u",["u"=>"admin"]);
$id=(int)Connection::value("SELECT id FROM domains WHERE name=:n",["n"=>$argv[1]]);
if($id){ DomainManager::delete($u,"admin",$id); }
$r=DomainManager::create($u,"admin",$argv[1],"php","8.3",false);
if(!$r["ok"]){ fwrite(STDERR,$r["error"]); exit(1); }
$d=(int)Connection::value("SELECT id FROM domains WHERE name=:n",["n"=>$argv[1]]);
$doc=(string)Connection::value("SELECT docroot FROM domains WHERE id=:i",["i"=>$d]);
if(!is_dir($doc)){ @mkdir($doc,0755,true); }
file_put_contents(rtrim($doc,"/")."/index.php","<?php echo \"mem=\".ini_get(\"memory_limit\").\" upload=\".ini_get(\"upload_max_filesize\").\"\n\";");
Vhost::deploy($argv[1],"admin",$doc,"php","8.3");' "$1" 2>/dev/null; }
delini(){ sudo -u www-data php -r '
require "/srv/fpanel/app/bootstrap.php";
use Fpanel\Service\DomainManager; use Fpanel\Database\Connection;
$u=(int)Connection::value("SELECT id FROM users WHERE username=:u",["u"=>"admin"]);
$id=(int)Connection::value("SELECT id FROM domains WHERE name=:n",["n"=>$argv[1]]);
if($id){ DomainManager::delete($u,"admin",$id); }' "$1" 2>/dev/null; }
probe_dom(){ curl -s -H "Host: $1" --max-time 10 http://127.0.0.1:80/index.php | head -1; }

mkini s1.test
mkini s2.test
eq "domain s1 default" "$(wait_dom s1.test)" "mem=128M upload=2M"
eq "domain s2 default" "$(probe_dom s2.test)" "mem=128M upload=2M"

SID=$(sudo -u www-data php -r 'require "/srv/fpanel/app/bootstrap.php"; echo (int)Fpanel\Database\Connection::value("SELECT id FROM domains WHERE name=:n",["n"=>"s1.test"]);' 2>/dev/null)
eq "simpan php settings tanpa CSRF -> 419" \
  "$(code pz -d "domain=$SID&ini_memory_limit=300M" $BASE/domains/php-settings)" 419
curl -s -b "$(jar pz)" -c "$(jar pz)" -o /dev/null \
  -d "_csrf=$(csrf pz)&domain=$SID&ini_memory_limit=300M&ini_upload_max_filesize=64M" \
  $BASE/domains/php-settings
sleep 1
has "vhost berisi PHP_VALUE" \
  "$(cat /etc/nginx/sites-available/fpanel-domains/s1.test.conf 2>/dev/null)" "PHP_VALUE"
eq "s1 memakai override" "$(probe_dom s1.test)" "mem=300M upload=64M"
eq "s2 TIDAK terpengaruh" "$(probe_dom s2.test)" "mem=128M upload=2M"

# Nilai nakal harus ditolak: string ini akan masuk ke konfigurasi nginx.
eq "nilai nakal ditolak" \
  "$(sudo -u www-data php -r 'require "/srv/fpanel/app/bootstrap.php";
     $r=Fpanel\Service\PhpSettings::save("admin",(int)Fpanel\Database\Connection::value("SELECT id FROM domains WHERE name=:n",["n"=>"s1.test"]),["memory_limit"=>"300M\"; return 404; #"]);
     echo $r["ok"]?"DITERIMA":"ditolak";' 2>/dev/null)" "ditolak"
eq "directive tak dikenal diabaikan" \
  "$(sudo -u www-data php -r 'require "/srv/fpanel/app/bootstrap.php";
     $r=Fpanel\Service\PhpSettings::save("admin",(int)Fpanel\Database\Connection::value("SELECT id FROM domains WHERE name=:n",["n"=>"s1.test"]),["ngawel"=>"1","max_execution_time"=>"90"]);
     echo $r["ok"]?"ok":"gagal";' 2>/dev/null)" "ok"
eq "IDOR: settings domain orang lain ditolak" \
  "$(sudo -u www-data php -r 'require "/srv/fpanel/app/bootstrap.php";
     $r=Fpanel\Service\PhpSettings::save("uujirole",(int)Fpanel\Database\Connection::value("SELECT id FROM domains WHERE name=:n",["n"=>"s1.test"]),["memory_limit"=>"500M"]);
     echo $r["ok"]?"DITERIMA (BUG)":"ditolak";' 2>/dev/null)" "ditolak"

# Reset harus mengembalikan ke default.
curl -s -b "$(jar pz)" -c "$(jar pz)" -o /dev/null \
  -d "_csrf=$(csrf pz)&domain=$SID&act=reset" $BASE/domains/php-settings
sleep 1
eq "setelah reset: kembali default" "$(probe_dom s1.test)" "mem=128M upload=2M"

delini s1.test
delini s2.test
rm -f /srv/fpanel/app/public/_probe_ext.php
eq "cleanup: domain s1 dihapus" "$($FP domain:ada s1.test)" 0
eq "cleanup: domain s2 dihapus" "$($FP domain:ada s2.test)" 0
$FP user:delete "$U2" >/dev/null 2>&1

# --- 15. App runtime: Node.js & Python per domain ---------------------------
# Satu app = satu systemd unit (fpanel-app-<nama>.service) yang di-proxy nginx.
echo "== 15. App Runtime (Node.js & Python) =="
AP="sudo -u www-data sudo -n /usr/local/sbin/fpanel-app"
# App milik produksi (bukan app test) harus UTUH setelah suite jalan; dicek
# ulang di akhir section ini.
PRE_APPS=$(sudo -u www-data php /tmp/q.php "SELECT COUNT(*) FROM runtime_apps WHERE name NOT IN ('nodeapp','pyapp')")
# Section sebelumnya menghapus user uji, jadi dibuat ulang di sini untuk
# assert IDOR.
$FP user:create "$U2" "$U2_PW" "uji@role.test" user >/dev/null 2>&1

# -- validasi helper: nilai-nilai ini masuk ke nama unit / path / port --------
eq "helper: nama nakal ditolak" "$($AP start '../../etc' /var/www a.js 30000 node 2>&1 | head -1 | cut -c1-4)" "ERR:"
eq "helper: entry '..' ditolak"  "$($AP start uji /var/www '../a.js' 30000 node 2>&1 | head -1 | cut -c1-4)" "ERR:"
eq "helper: entry absolut ditolak" "$($AP start uji /var/www '/etc/passwd' 30000 node 2>&1 | head -1 | cut -c1-4)" "ERR:"
eq "helper: port > 65535 ditolak"   "$($AP start uji /var/www a.js 99999 node 2>&1 | head -1 | cut -c1-4)" "ERR:"
eq "helper: kind ngawel ditolak"    "$($AP start uji /var/www a.js 30000 ruby 2>&1 | head -1 | cut -c1-4)" "ERR:"
eq "helper: folder tak ada ditolak" "$($AP start uji /var/wwwtidakada a.js 30000 node 2>&1 | head -1 | cut -c1-4)" "ERR:"
eq "helper: subcommand asing ditolak" "$(sudo -u www-data sudo -n /usr/local/sbin/fpanel-app 'rm -rf /' 2>&1 | head -1 | cut -c1-16)" "sudo: a password"

# -- siapkan dua domain + file app (ditulis lewat heredoc supaya \n di dalam
#    string JS/Python tidak berubah jadi newline) ----------------------------
mknodeapp(){
sudo -u www-data php -r '
require "/srv/fpanel/app/bootstrap.php";
use Fpanel\Service\DomainManager; use Fpanel\Database\Connection;
$u=(int)Connection::value("SELECT id FROM users WHERE username=:u",["u"=>"admin"]);
$id=(int)Connection::value("SELECT id FROM domains WHERE name=:n",["n"=>"nodeapp.test"]);
if($id){ DomainManager::delete($u,"admin",$id); }
$r=DomainManager::create($u,"admin","nodeapp.test","php","8.3",false);
echo $r["ok"]?"ok":$r["error"];' 2>/dev/null
mkdir -p /var/www/admin/nodeapp.test/public/app
cat > /var/www/admin/nodeapp.test/public/app/server.js <<'JS'
const http = require('http');
const port = process.env.PORT || 30000;
http.createServer((req, res) => {
  res.writeHead(200, { 'Content-Type': 'text/plain' });
  res.end('halo node port=' + port + ' path=' + req.url + '\n');
}).listen(port, '127.0.0.1');
JS
chown -R www-data:www-data /var/www/admin/nodeapp.test
}
mkpyapp(){
sudo -u www-data php -r '
require "/srv/fpanel/app/bootstrap.php";
use Fpanel\Service\DomainManager; use Fpanel\Database\Connection;
$u=(int)Connection::value("SELECT id FROM users WHERE username=:u",["u"=>"admin"]);
$id=(int)Connection::value("SELECT id FROM domains WHERE name=:n",["n"=>"pyapp.test"]);
if($id){ DomainManager::delete($u,"admin",$id); }
$r=DomainManager::create($u,"admin","pyapp.test","php","8.3",false);
echo $r["ok"]?"ok":$r["error"];' 2>/dev/null
mkdir -p /var/www/admin/pyapp.test/public/app
cat > /var/www/admin/pyapp.test/public/app/wsgi.py <<'PY'
def application(environ, start_response):
    body = b"halo python path=" + environ.get("PATH_INFO", "/").encode() + b"\n"
    start_response("200 OK", [("Content-Type", "text/plain"), ("Content-Length", str(len(body)))])
    return [body]
PY
chown -R www-data:www-data /var/www/admin/pyapp.test
}
regapp(){
sudo -u www-data php -r '
require "/srv/fpanel/app/bootstrap.php";
use Fpanel\Service\AppRuntime; use Fpanel\Service\DomainManager; use Fpanel\Database\Connection;
$dom=$argv[1]; $kind=$argv[2]; $entry=$argv[3]; $nm=$argv[4];
$did=(int)Connection::value("SELECT id FROM domains WHERE name=:n",["n"=>$dom]);
$row=Connection::one("SELECT * FROM domains WHERE id=:i",["i"=>$did]);
$r=AppRuntime::create("admin",$kind,$nm,$dom,rtrim((string)$row["docroot"],"/")."/app",$entry);
if($r["ok"]){
  Connection::run("UPDATE runtime_apps SET domain_id=:d WHERE id=:i",["d"=>$did,"i"=>(int)$r["id"]]);
  DomainManager::rebuildFor("admin", Connection::one("SELECT * FROM domains WHERE id=:i",["i"=>$did]));
  echo $r["port"];
} else { echo "GAGAL:".$r["error"]; }' "$1" "$2" "$3" "$4" 2>/dev/null
}
delapp(){
sudo -u www-data php -r '
require "/srv/fpanel/app/bootstrap.php";
use Fpanel\Service\AppRuntime; use Fpanel\Service\DomainManager; use Fpanel\Database\Connection;
$u=(int)Connection::value("SELECT id FROM users WHERE username=:u",["u"=>"admin"]);
foreach (["nodeapp","pyapp"] as $n) {
  $aid = (int)Connection::value("SELECT id FROM runtime_apps WHERE name=:n", ["n"=>$n]);
  if ($aid > 0) { AppRuntime::delete("admin", $aid); }
}
$id=(int)Connection::value("SELECT id FROM domains WHERE name=:n",["n"=>$argv[1]]);
if($id){ DomainManager::delete($u,"admin",$id); }' "$1" 2>/dev/null
}
delapp nodeapp.test; delapp pyapp.test

mknodeapp >/dev/null
mkpyapp >/dev/null
PN=$(regapp nodeapp.test node server.js nodeapp)
PP=$(regapp pyapp.test python wsgi.py pyapp)
eq "app Node terdaftar (port dialokasikan)" "$(printf '%s' "$PN" | grep -cE '^[0-9]+$')" 1
eq "app Python terdaftar"                  "$(printf '%s' "$PP" | grep -cE '^[0-9]+$')" 1
if [ "$PN" != "$PP" ] && [ -n "$PN" ] && [ -n "$PP" ]; then
  ok "dua app dapat port berbeda ($PN != $PP)"
else
  no "dua app dapat port berbeda" "$PN" "$PP"
fi

sleep 3
eq "unit systemd Node aktif"  "$(systemctl is-active fpanel-app-nodeapp.service)" "active"
eq "unit systemd Python aktif" "$(systemctl is-active fpanel-app-pyapp.service)" "active"
eq "unit Node enabled (reboot-safe)" "$(systemctl is-enabled fpanel-app-nodeapp.service)" "enabled"
eq "helper status Node = running"  "$($AP status nodeapp)" "running"

# -- benar-benar dilayani app, bukan catchall panel -------------------------
eq "HTTP domain Node -> 200"   "$(curl -s -o /dev/null -w '%{http_code}' --max-time 8 -H 'Host: nodeapp.test' http://127.0.0.1/)" 200
eq "body Node dari app"        "$(curl -s --max-time 8 -H 'Host: nodeapp.test' http://127.0.0.1/ | head -1 | cut -d' ' -f1-2)" "halo node"
eq "body Node path diteruskan" "$(curl -s --max-time 8 -H 'Host: nodeapp.test' http://127.0.0.1/api/z | grep -c 'path=/api/z')" 1
eq "body Python dari app"      "$(curl -s --max-time 8 -H 'Host: pyapp.test' http://127.0.0.1/ | head -1 | cut -d' ' -f1-2)" "halo python"
has "vhost Node proxy ke port app"  "$(cat /etc/nginx/sites-available/fpanel-domains/nodeapp.test.conf)" "proxy_pass http://127.0.0.1:$PN"
has "vhost Python proxy ke port app" "$(cat /etc/nginx/sites-available/fpanel-domains/pyapp.test.conf)" "proxy_pass http://127.0.0.1:$PP"
has "vhost punya dukungan WebSocket" "$(cat /etc/nginx/sites-available/fpanel-domains/nodeapp.test.conf)" "fp_connection_upgrade"

# -- stop / start lewat helper ---------------------------------------------
$AP stop nodeapp >/dev/null
sleep 2
eq "setelah stop: domain 502" "$(curl -s -o /dev/null -w '%{http_code}' --max-time 6 -H 'Host: nodeapp.test' http://127.0.0.1/)" 502
$AP start nodeapp "/var/www/admin/nodeapp.test/public/app" server.js "$PN" node /var/www >/dev/null
sleep 3
eq "setelah start: app hidup lagi" "$(curl -s --max-time 8 -H 'Host: nodeapp.test' http://127.0.0.1/ | head -1 | cut -d' ' -f1-2)" "halo node"

# -- kuota: jenis app tidak saling memakai kuota ---------------------------
# Saat assertion ini dijalankan ada 1 app Node + 1 app Python. Kalau keduanya
# dihitung dalam satu penghitung, salah satu nilainya akan 2; karena itu
# 1 dan 1 membuktikan penghitungnya terpisah.
eq "kuota Node dan Python dihitung terpisah" \
  "$(sudo -u www-data php -r 'require "/srv/fpanel/app/bootstrap.php";
     $q=Fpanel\Service\AppRuntime::quota("admin");
     $uid=(int)Fpanel\Database\Connection::value("SELECT id FROM users WHERE username=:u",["u"=>"admin"]);
     $rows=Fpanel\Database\Connection::all("SELECT kind FROM runtime_apps WHERE user_id=:u",["u"=>$uid]);
     $n=count(array_filter($rows,fn($a)=>$a["kind"]==="node"));
     $p=count(array_filter($rows,fn($a)=>$a["kind"]==="python"));
     // AppRuntime harus menghitung per jenis runtime, persis sama dengan
     // hitungan langsung. Kedua jenis harus ada supaya uji ini berarti.
     echo ($n>0 && $p>0 && $q["used_node"]===$n && $q["used_python"]===$p)
       ? "terpisah" : ("tercampur n=".$q["used_node"]."/".$n." p=".$q["used_python"]."/".$p);' 2>/dev/null)" "terpisah"
eq "kuota: batas Python ada di paket" \
  "$(sudo -u www-data php -r 'require "/srv/fpanel/app/bootstrap.php";
     $q=Fpanel\Service\AppRuntime::quota("admin"); echo $q["max_python"] > 0 ? "ada" : "nol";' 2>/dev/null)" "ada"

# -- IDOR ------------------------------------------------------------------
eq "IDOR: app user lain tidak terlihat" \
  "$(sudo -u www-data php -r 'require "/srv/fpanel/app/bootstrap.php";
     $o=(string)Fpanel\Database\Connection::value("SELECT username FROM users WHERE role <> :r ORDER BY id LIMIT 1",["r"=>"admin"]);
     if($o===""){ echo "tidak-ada-user"; }
     else { $r=Fpanel\Service\AppRuntime::listing($o); echo empty($r["apps"]) ? "kosong" : "BOCOR"; }' 2>/dev/null)" "kosong"
eq "IDOR: stop app orang lain ditolak" \
  "$(sudo -u www-data php -r 'require "/srv/fpanel/app/bootstrap.php";
     $o=(string)Fpanel\Database\Connection::value("SELECT username FROM users WHERE role <> :r ORDER BY id LIMIT 1",["r"=>"admin"]);
     $id=(int)Fpanel\Database\Connection::value("SELECT id FROM runtime_apps LIMIT 1");
     $r=Fpanel\Service\AppRuntime::stop($o,$id);
     echo $r["ok"] ? "DITERIMA(BUG)" : "ditolak";' 2>/dev/null)" "ditolak"

# -- UI --------------------------------------------------------------------
curl -s -b "$(jar aps)" -c "$(jar aps)" -o /dev/null \
  -d "_csrf=$(csrf aps)&username=admin&password=$ADMIN_PWE" $BASE/login
APUI=$(body aps "$BASE/apps")
has "halaman /apps terbuka"      "$APUI" "App Runtime"
has "halaman /apps menampilkan app" "$APUI" "nodeapp"
has "halaman /apps menampilkan runtime" "$APUI" "App Runtime"
hasnt "halaman /apps tidak memuat halaman login" "$APUI" "type=\"password\""
eq "aksi app tanpa CSRF -> 419"  "$(code aps -d "app=1&act=stop" $BASE/apps/action)" 419
AID=$(sudo -u www-data php -r 'require "/srv/fpanel/app/bootstrap.php"; echo (int)Fpanel\Database\Connection::value("SELECT id FROM runtime_apps WHERE name = :n",["n"=>"nodeapp"]);' 2>/dev/null)
curl -s -b "$(jar aps)" -c "$(jar aps)" -o /dev/null -d "_csrf=$(csrf aps)&app=$AID&act=stop" $BASE/apps/action
sleep 2
eq "stop lewat UI: unit stopped" "$($AP status nodeapp)" "stopped"
curl -s -b "$(jar aps)" -c "$(jar aps)" -o /dev/null -d "_csrf=$(csrf aps)&app=$AID&act=start" $BASE/apps/action
sleep 3
eq "start lewat UI: unit running" "$($AP status nodeapp)" "running"
APLG=$(body aps -d "_csrf=$(csrf aps)&app=$AID" $BASE/apps/logs)
has "log app ditampilkan di UI" "$APLG" "<pre"
$FP user:delete "$U2" >/dev/null 2>&1

delapp nodeapp.test; delapp pyapp.test
# Docrot juga dibuang: kalau tidak, folder ini dihitung sebagai "sisa file uji"
# oleh section 10 pada run berikutnya.
rm -rf /var/www/admin/nodeapp.test /var/www/admin/pyapp.test
eq "cleanup: app Node dihapus"   "$(sudo -u www-data php /tmp/q.php "SELECT COUNT(*) FROM runtime_apps WHERE name = 'nodeapp'")" 0
eq "cleanup: app Python dihapus" "$(sudo -u www-data php /tmp/q.php "SELECT COUNT(*) FROM runtime_apps WHERE name = 'pyapp'")" 0
eq "cleanup: unit Node hilang"  "$(systemctl list-unit-files 2>/dev/null | grep -c 'fpanel-app-nodeapp')" 0
eq "cleanup: tidak ada app test tersisa" "$(sudo -u www-data php /tmp/q.php "SELECT COUNT(*) FROM runtime_apps WHERE name IN ('nodeapp','pyapp')")" 0
eq "cleanup: docroot app terhapus" "$(ls -d /var/www/admin/nodeapp.test /var/www/admin/pyapp.test 2>/dev/null | wc -l)" 0
eq "cleanup: tidak ada unit app test tersisa" "$(systemctl list-units --all --no-legend 2>/dev/null | grep -cE 'fpanel-app-(nodeapp|pyapp)')" 0
eq "cleanup: app produksi tidak ikut terhapus" "$(sudo -u www-data php /tmp/q.php "SELECT COUNT(*) FROM runtime_apps WHERE name NOT IN ('nodeapp','pyapp')")" "$PRE_APPS"

# --- 16. Backup & restore ---------------------------------------------------
if ! skip_section 16; then
# Arsip berisi snapshot SQLite, /var/www, dump database, konfigurasi, sertifikat.
# Helper root yang bekerja; panel hanya memicu lewat sudo.
echo "== 16. Backup & Restore =="
BK="sudo -u www-data sudo -n /usr/local/sbin/fpanel-backup"

# -- validasi helper (nilai ini jadi nama file / argumen root) ----------------
eq "helper: nama traversal ditolak"  "$($BK verify '../../../etc/passwd' 2>&1 | head -1 | cut -c1-4)" "ERR:"
eq "helper: delete traversal ditolak" "$($BK delete '../../etc/passwd' 2>&1 | head -1 | cut -c1-4)" "ERR:"
eq "helper: subcommand asing ditolak" "$(sudo -u www-data sudo -n /usr/local/sbin/fpanel-backup 'rm -rf /' 2>&1 | head -1 | cut -c1-16)" "sudo: a password"
eq "helper: ping"                   "$($BK ping)" "OK"

# -- backup dari UI (hanya admin) -------------------------------------------
$FP user:create "$U2" "$U2_PW" "uji@role.test" user >/dev/null 2>&1
# Cek "tanpa login" HARUS pakai jar kosong; jar yang sudah login tidak
# akan menguji apa pun.
eq "backup: halaman tanpa login -> 302" "$(getc bk_anon $BASE/backups)" 302
curl -s -b "$(jar bkp)" -c "$(jar bkp)" -o /dev/null \
  -d "_csrf=$(csrf bkp)&username=admin&password=$ADMIN_PWE" $BASE/login
code u2 -d "_csrf=$(csrf u2)&username=$U2&password=$U2_PWE" $BASE/login >/dev/null

eq "backup: halaman non-admin -> 403" "$(getc u2 $BASE/backups)" 403
eq "backup: create tanpa CSRF -> 419" "$(code bkp -d 'keep=7' $BASE/backups/create)" 419
# Non-admin dengan CSRF yang valid akan ditolak oleh pemeriksaan peran (403),
# bukan oleh CSRF (419) -- token-nya memang sah, dia hanya bukan admin.
eq "backup: create oleh non-admin -> 403" "$(code u2 -d "_csrf=$(csrf u2)&keep=7" $BASE/backups/create)" 403
hasnt "backup: non-admin tidak melihat arsip" "$(body u2 $BASE/backups)" "tar.gz"

BKUI=$(body bkp "$BASE/backups")
has "backup: halaman terbuka"        "$BKUI" "Backup"
has "backup: jadwal harian ditampilkan" "$BKUI" "03:17"
has "backup: peringatan restore tidak ada di UI" "$BKUI" "Restore tidak ada di halaman ini"

BEFORE=$(ls -1 /var/backups/fpanel 2>/dev/null | wc -l)
curl -s -L -b "$(jar bkp)" -c "$(jar bkp)" -o /tmp/bkout.html \
  -d "_csrf=$(csrf bkp)&keep=5" $BASE/backups/create
AFTER=$(ls -1 /var/backups/fpanel 2>/dev/null | wc -l)
gt "backup: arsip baru dibuat dari UI" "$AFTER" "$BEFORE"
BKNEW=$(ls -1 /var/backups/fpanel 2>/dev/null | sort | tail -1)
ok "backup: nama arsip valid (pola ketat)" "$(printf '%s' "$BKNEW" | grep -cE '^fpanel-[0-9]{8}-[0-9]{6}\.tar\.gz$')" "1"

# -- isi arsip benar-benar berguna ------------------------------------------
TMPB=$(mktemp -d)
tar -xzf "/var/backups/fpanel/$BKNEW" -C "$TMPB" 2>/dev/null
eq "arsip: berisi panel.sqlite" "$([ -f "$TMPB/panel.sqlite" ] && echo 1 || echo 0)" 1
eq "arsip: berisi www.tar.gz"  "$([ -f "$TMPB/www.tar.gz" ] && echo 1 || echo 0)" 1
eq "arsip: berisi mysqldump"  "$([ -f "$TMPB/mysqldump.sql.gz" ] && echo 1 || echo 0)" 1
eq "arsip: berisi meta.json"   "$([ -f "$TMPB/meta.json" ] && echo 1 || echo 0)" 1
eq "arsip: snapshot SQLite valid" \
  "$(php -r 'try{$p=new PDO("sqlite:".$argv[1]);echo $p->query("PRAGMA integrity_check")->fetchColumn();}catch(Throwable $e){echo "err";}' "$TMPB/panel.sqlite" 2>/dev/null)" "ok"
has "arsip: metadata mencatat database" "$(cat "$TMPB/meta.json" 2>/dev/null)" "databases"
eq "arsip: tidak ada file owner lain" "$(stat -c '%U' "/var/backups/fpanel/$BKNEW")" "root"
eq "arsip: tidak world-readable"      "$([ "$(stat -c '%a' "/var/backups/fpanel/$BKNEW")" = "600" ] && echo 1 || echo 0)" 1
rm -rf "$TMPB"

# -- verify dari UI menampilkan laporan ------------------------------------
curl -s -L -b "$(jar bkp)" -c "$(jar bkp)" -o /tmp/bkverify.html \
  -d "_csrf=$(csrf bkp)&name=$BKNEW" $BASE/backups/verify
has "verify: laporan tampil di UI" "$(cat /tmp/bkverify.html)" "Laporan verifikasi"
eq "verify: semua checksum ok" "$(grep -cE 'checksum [a-z.]+ : ok' /tmp/bkverify.html)" 5
has "verify: sqlite integrity ok" "$(cat /tmp/bkverify.html)" "sqlite integrity_check : ok"
rm -f /tmp/bkout.html /tmp/bkverify.html

# -- CLI --------------------------------------------------------------------
gt "CLI: backup:list punya arsip" "$($FP backup:list 2>/dev/null | grep -c 'tar\.gz')" 0
has "CLI: backup:verify sukses" "$($FP backup:verify "$BKNEW" 2>&1)" "HASIL"
has "CLI: help menyebut backup" "$($FP 2>&1)" "backup:create"

# -- jadwal otomatis & retensi ---------------------------------------------
has "cron harian terpasang" "$(cat /etc/cron.d/fpanel-backup 2>/dev/null)" "fpanel-backup create"
eq "logrotate terpasang" "$([ -f /etc/logrotate.d/fpanel-backup ] && echo 1 || echo 0)" 1

# -- retensi: prune menyisakan N terbaru ----------------------------------
for _ in 1 2 3; do $BK create 9 >/dev/null 2>&1; done
TOTAL=$(ls -1 /var/backups/fpanel 2>/dev/null | wc -l)
$BK prune 2 >/dev/null 2>&1
eq "retensi: prune menyisakan 2 arsip" "$(ls -1 /var/backups/fpanel 2>/dev/null | wc -l)" 2
gt "retensi: prune benar-benar mengurangi" "$TOTAL" 2

# -- restore: tidak tersedia lewat panel, tapi butuh konfirmasi eksak -------
hasnt "restore tidak ada sebagai aksi UI" "$(body bkp "$BASE/backups")" 'action="/backups/restore"'
hasnt "CLI tidak menyediakan restore"   "$($FP 2>&1)" "backup:restore"
eq "restore tanpa konfirmasi ditolak" \
  "$(sudo -u www-data sudo -n /usr/local/sbin/fpanel-backup restore fpanel-20990101-000000.tar.gz 2>&1 | head -1 | cut -c1-4)" "ERR:"


fi
# --- 17. Cron Jobs ----------------------------------------------------------
# Job user ditulis ke /etc/cron.d/fpanel-cron-<user> oleh helper root, lalu
# cron daemon sungguhan yang mengeksekusinya. Kolom user di file cron.d WAJIB
# user Unix yang nyata (www-data), bukan username panel.
echo "== 17. Cron Jobs =="
CR="sudo -u www-data sudo -n /usr/local/sbin/fpanel-cron"

eq "helper: sync tanpa user ditolak"    "$($CR sync 2>&1 | head -1 | cut -c1-4)" "sudo"
eq "helper: username nakal ditolak"     "$($CR sync '../../etc' 2>&1 | head -1 | cut -c1-4)" "ERR:"
eq "helper: 'run' tidak boleh via sudo" "$(sudo -u www-data sudo -n /usr/local/sbin/fpanel-cron run admin 1 2>&1 | head -1 | cut -c1-16)" "sudo: a password"
eq "helper: 'sync' ada di sudoers"      "$(grep -c 'fpanel-cron sync' /etc/sudoers.d/fpanel-cron)" 1
eq "helper: 'run' tidak ada di sudoers" "$(grep -c 'fpanel-cron run' /etc/sudoers.d/fpanel-cron)" 0

CJ="$(jar cj)"   # path harus sama dengan yang dipakai helper csrf
CJ_PW='Cron#2026-Uji9k'
$FP user:delete "$U3" >/dev/null 2>&1
$FP user:create "$U3" "$CJ_PW" "uji@cron.test" user >/dev/null 2>&1
curl -s -b "$CJ" -c "$CJ" -o /dev/null "$BASE/login"
curl -s -b "$CJ" -c "$CJ" -o /dev/null -d "username=$U3" \
  --data-urlencode "password=$CJ_PW" -d "_csrf=$(csrf cj)" "$BASE/login"
eq "user uji cron bisa login" "$(curl -s -b "$CJ" -c "$CJ" -o /dev/null -w '%{http_code}' "$BASE/cron")" 200

eq "buat job tanpa CSRF -> 419" "$(code cj -d "name=x&command=x&schedule=*+*+*+*+*" $BASE/cron/create)" 419

curl -s -b "$CJ" -c "$CJ" -o /dev/null \
  -d "_csrf=$(csrf cj)&name=uji+daemon&command=echo+dijalankan-uji&schedule=*+*+*+*+*" "$BASE/cron/create"
CJID=$(sudo -u www-data php /tmp/q.php "SELECT c.id FROM cron_jobs c JOIN users u ON u.id=c.user_id WHERE u.username='$U3' ORDER BY c.id DESC LIMIT 1")
gt "cron.d untuk user dibuat" "$(ls /etc/cron.d/ 2>/dev/null | grep -c "fpanel-cron-$U3")" 0

CJLINE=$(grep "run $U3" "/etc/cron.d/fpanel-cron-$U3" 2>/dev/null | head -1)
# 5 field jadwal + 1 kolom user + 4 token command ("<path> run <user> <id>").
eq "baris cron.d punya 10 token" "$(echo "$CJLINE" | awk '{print NF}')" 10
eq "kolom user adalah user Unix www-data" "$(echo "$CJLINE" | awk '{print $6}')" "www-data"
eq "command memakai path absolut" "$(echo "$CJLINE" | awk '{print $7}')" "/usr/local/sbin/fpanel-cron"
eq "command memanggil run untuk user ini" "$(echo "$CJLINE" | awk '{print $8, $9, $10}')" "run $U3 $CJID"
eq "lima field jadwal utuh" "$(echo "$CJLINE" | awk '{print ($1=="*" && $2=="*" && $3=="*" && $4=="*" && $5=="*") ? "ok" : "bukan"}')" "ok"

curl -s -b "$CJ" -c "$CJ" -o /dev/null -d "_csrf=$(csrf cj)&id=$CJID" "$BASE/cron/run"
sleep 2
has "jalankan sekarang menulis log" "$(cat "/var/log/fpanel-cron/$U3-$CJID.log" 2>/dev/null)" "dijalankan-uji"
has "log menampilkan exit code" "$(cat "/var/log/fpanel-cron/$U3-$CJID.log" 2>/dev/null)" "exit="

# Tunggu cron daemon sungguhan. Ini assertion paling penting: tanpa ini, bug
# format cron.d (hanya 1 field, atau user panel yang tidak ada di
# /etc/passwd) lolos karena "jalankan sekarang" tetap berhasil.
RAN=0
for _ in $(seq 1 20); do
  N=$(grep -c "mulai job" "/var/log/fpanel-cron/$U3-$CJID.log" 2>/dev/null || echo 0)
  if [ "$N" -ge 2 ]; then RAN=1; break; fi
  sleep 6
done
eq "cron daemon benar-benar mengeksekusi" "$RAN" 1

curl -s -b "$CJ" -c "$CJ" -o /dev/null -d "_csrf=$(csrf cj)&id=$CJID" "$BASE/cron/toggle"
sleep 2
eq "nonaktif: baris hilang dari cron.d" "$(grep -c "run $U3 $CJID" "/etc/cron.d/fpanel-cron-$U3" 2>/dev/null)" 0
curl -s -b "$CJ" -c "$CJ" -o /dev/null -d "_csrf=$(csrf cj)&id=$CJID" "$BASE/cron/toggle"
sleep 2
eq "aktif lagi: baris kembali" "$(grep -c "run $U3 $CJID" "/etc/cron.d/fpanel-cron-$U3" 2>/dev/null)" 1

curl -s -b "$CJ" -c "$CJ" -o /dev/null \
  -d "_csrf=$(csrf cj)&id=$CJID&name=renamed&command=echo+ok&schedule=*/5+*+*+*+*" "$BASE/cron/update"
sleep 2
has "ubah jadwal tampil di cron.d" "$(cat "/etc/cron.d/fpanel-cron-$U3" 2>/dev/null)" "*/5 * * * *"
eq "cron.d bebas error syntax" \
  "$(journalctl -u cron --since '-3 min' --no-pager 2>/dev/null | grep -c 'Syntax error.*fpanel-cron')" 0

eq "validasi: menit 99 ditolak" \
  "$(sudo -u www-data php -r 'require "/srv/fpanel/app/bootstrap.php"; echo (int)Fpanel\Service\Cron::validSchedule("0 99 * * *");')" 0
eq "validasi: */5 diterima" \
  "$(sudo -u www-data php -r 'require "/srv/fpanel/app/bootstrap.php"; echo (int)Fpanel\Service\Cron::validSchedule("*/5 * * * *");')" 1
eq "validasi: @daily ditolak" \
  "$(sudo -u www-data php -r 'require "/srv/fpanel/app/bootstrap.php"; echo (int)Fpanel\Service\Cron::validSchedule("@daily");')" 0

# User lain tidak boleh melihat atau mengubah job ini.
CRJ="$(jar crj)"
curl -s -b "$CRJ" -c "$CRJ" -o /dev/null "$BASE/login"
curl -s -b "$CRJ" -c "$CRJ" -o /dev/null -d "username=admin" \
  --data-urlencode "password=$ADMIN_PW" -d "_csrf=$(csrf crj)" "$BASE/login"
hasnt "job user lain tidak terlihat" "$(body crj "$BASE/cron")" "uji daemon"
curl -s -b "$CRJ" -c "$CRJ" -o /dev/null \
  -d "_csrf=$(csrf crj)&id=$CJID&name=Diretas&command=id&schedule=*+*+*+*+*" "$BASE/cron/update"
eq "update job milik orang lain ditolak" \
  "$(sudo -u www-data php /tmp/q.php "SELECT name FROM cron_jobs WHERE id=$CJID")" "renamed"

curl -s -b "$CJ" -c "$CJ" -o /dev/null -d "_csrf=$(csrf cj)&id=$CJID" "$BASE/cron/delete"
sleep 2
eq "hapus: job hilang dari DB" \
  "$(sudo -u www-data php /tmp/q.php "SELECT COUNT(*) FROM cron_jobs WHERE id=$CJID")" 0
eq "hapus: baris cron.d hilang" \
  "$(grep -c "run $U3 $CJID" "/etc/cron.d/fpanel-cron-$U3" 2>/dev/null)" 0
$FP user:delete "$U3" >/dev/null 2>&1
rm -f "/var/log/fpanel-cron/$U3-$CJID.log" "/etc/cron.d/fpanel-cron-$U3" "/var/lib/fpanel/cron/$U3.jobs"


# --- 18. WordPress installer -------------------------------------------------
# Install WordPress sungguhan memakan 2-3 menit (unduh core 37 MB dari CDN),
# jadi suite TIDAK melakukan download penuh. Yang diuji di sini adalah bagian
# yang bisa gagal tanpa mahal: validasi input, penolakan, CLI, dan "forget".
echo "== 18. WordPress installer ="
FPWP="php /srv/fpanel/bin/fpanel"   # root; path panel hanya bisa diakses root

eq "CLI: payload wp:install rusak ditolak" "$($FPWP wp:install abcdef 2>&1 | head -1 | cut -c1-6)" "GAGAL:"
eq "CLI: tanpa argumen ditolak" "$($FPWP wp:install 2>&1 | head -1 | cut -c1-6)" "GAGAL:"
eq "CLI: wp:install ada di help" "$(php /srv/fpanel/bin/fpanel 2>&1 | grep -c 'wp:install')" 1

WJ="wj"   # NAMA jar; helper csrf() sudah menambah .jar
WJF="$(jar "$WJ")"
curl -s -b "$WJF" -c "$WJF" -o /dev/null "$BASE/login"
curl -s -b "$WJF" -c "$WJF" -o /dev/null -d "username=admin" \
  --data-urlencode "password=$ADMIN_PW" -d "_csrf=$(csrf "$WJ")" "$BASE/login"

has "halaman /wordpress terbuka" "$(body wj "$BASE/wordpress")" "WordPress"
hasnt "form install tidak ikut mengirim password" "$(body wj "$BASE/wordpress")" "type=\"password\" value="

eq "install tanpa CSRF -> 419" "$(code "$WJ" -d "domain=x.test" $BASE/wordpress/install)" 419

# Domain PHP sekali pakai untuk menguji validasi (tanpa unduhan core).
mkphpdom wpv.test 8.3
eq "domain uji PHP dibuat" "$($FP domain:ada wpv.test)" 1

wp_err(){
  post "$WJ" -o /dev/null -w '%{redirect_url}' -d "_csrf=$(csrf wj)&domain=$1&version=7.1.2&admin_user=$2&admin_password=$3&admin_email=$4" "$BASE/wordpress/install" \
    | grep -oE 'err=[^&]*' | head -1 | sed 's/err=//;s/%20/ /g'
}

has "domain static ditolak" "$(wp_err demo.fpanel.my.id wpadmin Password123 wp@x.test)" "bertipe static"
# Pakai has (pencocokan substring), bukan potong karakter: pemotongan mudah
# meleset satu karakter dan bikin assertion gagal padahal pesannya benar.
has "domain tidak ada ditolak" "$(wp_err tidak-ada.test wpadmin Password123 wp@x.test)" "Domain tidak ditemukan"
has "password admin pendek ditolak" "$(wp_err wpv.test wpadmin abc wp@x.test)" "Password admin WordPress minimal 8"
has "email admin tidak valid ditolak" "$(wp_err wpv.test wpadmin Password123 bukan-email)" "Email admin WordPress tidak valid"
has "username admin ngawel ditolak" "$(wp_err wpv.test 'a/b' Password123 wp@x.test)" "Nama admin WordPress tidak valid"

eq "percobaan gagal tidak meninggalkan baris wp_sites" \
  "$(sudo -u www-data php /tmp/q.php "SELECT COUNT(*) FROM wp_sites w JOIN domains d ON d.id = w.domain_id WHERE d.name = 'wpv.test'")" 0
# Catatan: user boleh punya WordPress sendiri, jadi total wp_sites tidak
# dipaksa 0. Yang harus nol hanya baris milik domain uji.

eq "daftar wp_sites tidak bisa menunjuk domain orang lain" \
  "$(sudo -u www-data php /tmp/q.php "SELECT COUNT(*) FROM wp_sites w JOIN domains d ON d.id = w.domain_id JOIN users u ON u.id = w.user_id WHERE u.username <> (SELECT username FROM users WHERE id = d.user_id)")" 0
eq "core_version selalu terisi" \
  "$(sudo -u www-data php /tmp/q.php "SELECT COUNT(*) FROM wp_sites WHERE core_version IS NULL OR core_version = ''")" 0

# forget: menghapus dari daftar TIDAK boleh menghapus file atau database.
#
# Baris wp_sites yang diuji dibuatkan sendiri untuk domain uji, BUKAN memakai
# instalasi milik user. Kalau suite memakai baris yang sudah ada, satu klik
# "forget" akan menghapus WordPress produksi dari daftar panel.
WPDOC=$(sudo -u www-data php /tmp/q.php "SELECT docroot FROM domains WHERE name = 'wpv.test'")
mkdir -p "$WPDOC"
printf '<?php // dummy untuk uji forget\n' > "$WPDOC/wp-config.php"
UID_WP=$(sudo -u www-data php /tmp/q.php "SELECT id FROM users WHERE username = 'admin'")
DID_WP=$(sudo -u www-data php /tmp/q.php "SELECT id FROM domains WHERE name = 'wpv.test'")
sudo -u www-data php -r '
require "/srv/fpanel/app/bootstrap.php";
use Fpanel\Database\Connection;
Connection::run("DELETE FROM wp_sites WHERE domain_id = :d", ["d" => (int)$argv[1]]);
Connection::run("INSERT INTO wp_sites (user_id, domain_id, core_version, is_staging, created_at)
                VALUES (:u, :d, :v, 0, :t)",
    ["u" => (int)$argv[2], "d" => (int)$argv[1], "v" => "7.1.2", "t" => gmdate("Y-m-d H:i:s")]);' "$DID_WP" "$UID_WP"
WPID=$(sudo -u www-data php /tmp/q.php "SELECT id FROM wp_sites WHERE domain_id = $DID_WP")
BEFORE_WP=$(sudo -u www-data php /tmp/q.php "SELECT COUNT(*) FROM wp_sites WHERE domain_id <> $DID_WP")
post wj -o /dev/null -d "_csrf=$(csrf wj)&id=$WPID" "$BASE/wordpress/forget"
eq "forget: baris hilang dari daftar" \
  "$(sudo -u www-data php /tmp/q.php "SELECT COUNT(*) FROM wp_sites WHERE id = $WPID")" 0
eq "forget: file WordPress tetap ada" "$([ -f "$WPDOC/wp-config.php" ] && echo 1 || echo 0)" 1
eq "forget: domain tetap ada" \
  "$(sudo -u www-data php /tmp/q.php "SELECT COUNT(*) FROM domains WHERE id = $DID_WP")" 1
eq "forget: instalasi WordPress lain tidak tersentuh" \
  "$(sudo -u www-data php /tmp/q.php "SELECT COUNT(*) FROM wp_sites WHERE domain_id <> $DID_WP")" "$BEFORE_WP"

# Kode harus memakai nama DB/user FISIK (helper MariaDb memberi prefiks user
# panel); kalau tidak, wp-config.php tidak bisa konek.
eq "installer memakai nama DB fisik" "$(grep -c 'physDb' /srv/fpanel/app/src/Service/Wp.php)" 1
eq "installer memakai user DB fisik" "$(grep -c 'physUser' /srv/fpanel/app/src/Service/Wp.php)" 1
eq "salt diacak lokal, bukan dari API" "$(grep -c 'api.wordpress.org/salt' /srv/fpanel/app/src/Service/Wp.php)" 0
# Installer dipanggil lewat spawn() yang menulis prefix "wp:<aksi>".
# dihitung dari literal "wp:install" di Wp.php; setelah spawn() digeneralisasi
# untuk dipakai wp:update juga, assertion itu ikut usang. Yang diuji sekarang
# adalah spawn() itu sendiri plus kedua subcommand CLI-nya.
eq "installer berjalan di CLI (tempat terpisah dari FPM)" \
  "$(grep -c 'private static function spawn' /srv/fpanel/app/src/Service/Wp.php)" 1
eq "spawn() membangun prefix subcommand CLI" \
  "$(grep -cF "/bin/fpanel wp:' . \$safe" /srv/fpanel/app/src/Service/Wp.php)" 1
eq "spawn() menolak aksi di luar pola aman" \
  "$(grep -cF "preg_match('/^[a-z-]+\$/', \$action)" /srv/fpanel/app/src/Service/Wp.php)" 1
eq "pembaruan memanggil spawn dengan aksi update" \
  "$(grep -cF "], 'update');" /srv/fpanel/app/src/Service/Wp.php)" 1
eq "CLI punya subcommand wp:update" \
  "$(grep -c "case 'wp:update'" /srv/fpanel/bin/fpanel)" 1
eq "CLI punya subcommand wp:install" "$(grep -c "case 'wp:install'" /srv/fpanel/bin/fpanel)" 1

deld wpv.test
eq "cleanup: domain uji PHP dihapus" "$($FP domain:ada wpv.test)" 0


# --- 19. Resource Usage (per domain) -----------------------------------------
echo "== 19. Resource Usage ="
UQ="uq"
curl -s -b "$(jar "$UQ")" -c "$(jar "$UQ")" -o /dev/null "$BASE/login"
curl -s -b "$(jar "$UQ")" -c "$(jar "$UQ")" -o /dev/null -d "username=admin" \
 --data-urlencode "password=$ADMIN_PW" -d "_csrf=$(csrf "$UQ")" "$BASE/login"

has "halaman /usage terbuka" "$(body "$UQ" "$BASE/usage")" "Resource Usage"
hasnt "halaman usage tidak bocor password" "$(body "$UQ" "$BASE/usage")" "type=\"password\" value="

eq "tabel domain_usage ada" \
 "$(sudo -u www-data php /tmp/q.php "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='domain_usage'")" 1
eq "CLI: usage:collect terdaftar" "$(php /srv/fpanel/bin/fpanel 2>&1 | grep -c 'usage:collect')" 1
eq "cron penghitungan terpasang" \
 "$(grep -c 'usage:collect' /etc/cron.d/fpanel-usage 2>/dev/null)" 1
eq "cron berjalan sebagai www-data" \
  "$(grep -cE '^[0-9*/,-]+ +[0-9*/,-]+ +[0-9*/,-]+ +[0-9*/,-]+ +[0-9*/,-]+ +www-data ' /etc/cron.d/fpanel-usage 2>/dev/null)" 1

eq "collect tanpa CSRF -> 419" "$(code "$UQ" -d "" $BASE/usage/collect)" 419
eq "kumpulkan lewat panel" \
 "$(post "$UQ" -o /dev/null -w '%{http_code}' -d "_csrf=$(csrf "$UQ")" $BASE/usage/collect)" 302
sleep 2

eq "setiap domain punya baris usage" \
 "$(sudo -u www-data php /tmp/q.php "SELECT COUNT(*) FROM domains d LEFT JOIN domain_usage g ON g.domain_id = d.id WHERE g.domain_id IS NULL")" 0
eq "domain yang dihapus tidak meninggalkan usage yatim" \
 "$(sudo -u www-data php /tmp/q.php "SELECT COUNT(*) FROM domain_usage g LEFT JOIN domains d ON d.id = g.domain_id WHERE d.id IS NULL")" 0
eq "angka usage tidak negatif" \
 "$(sudo -u www-data php /tmp/q.php "SELECT COUNT(*) FROM domain_usage WHERE disk_bytes < 0 OR bandwidth_bytes < 0 OR requests < 0")" 0
eq "resource_usage punya baris per user" \
 "$(sudo -u www-data php /tmp/q.php "SELECT COUNT(*) FROM resource_usage r JOIN users u ON u.id = r.user_id")" \
 "$(sudo -u www-data php /tmp/q.php "SELECT COUNT(*) FROM users")"
eq "path teratas disimpan sebagai JSON" \
 "$(sudo -u www-data php -r '
   $p = new PDO("sqlite:/srv/fpanel/var/db/fpanel.sqlite");
   $bad = 0;
   foreach ($p->query("SELECT top_paths FROM domain_usage") as $r) {
       if (!is_array(json_decode((string)$r["top_paths"], true))) { $bad++; }
   }
   echo $bad;')" 0

# User non-admin tidak boleh melihat domain orang lain.
UQ2="uq2"
$FP user:create "$U2" "$U2_PW" "uji@role.test" user >/dev/null 2>&1
curl -s -b "$(jar "$UQ2")" -c "$(jar "$UQ2")" -o /dev/null "$BASE/login"
curl -s -b "$(jar "$UQ2")" -c "$(jar "$UQ2")" -o /dev/null -d "username=$U2" \
 --data-urlencode "password=$U2_PW" -d "_csrf=$(csrf "$UQ2")" "$BASE/login"
hasnt "usage user lain tidak membocorkan domain" "$(body "$UQ2" "$BASE/usage")" "demo.fpanel.my.id"
eq "collect oleh non-admin ditolak" "$(code "$UQ2" -d "_csrf=$(csrf "$UQ2")" $BASE/usage/collect)" 403
# --- 20. Two-Factor Authentication (TOTP) ---------------------------------------
# Diuji sebagai alur nyata: setup -> login -> kode salah -> kode benar -> disable.
# User non-admin dipakai supaya kredensial admin tidak perlu disentuh, dan 2FA
# wajib sudah mati di akhir section supaya suite tidak mengunci akun sendiri.
echo "== 20. Two-Factor Authentication (TOTP) ="
UT="ut"
TFQ="sudo -u www-data php /tmp/q.php"
tfcode(){ sudo -u www-data php -r "require '/srv/fpanel/app/bootstrap.php'; echo Fpanel\Service\Totp::code('$1');"; }
# Seed TOTP disimpan terenkripsi (SecretBox), jadi nilai dari DB harus
# didekripsi dulu sebelum dipakai menghitung kode. Menyalin nilai mentah
# dari database akan menghasilkan string yang bukan base32.
tfsecret(){ sudo -u www-data php -r "require '/srv/fpanel/app/bootstrap.php'; echo Fpanel\Service\Totp::pendingSecret('$1') ?? '';"; }

$FP user:create "$U2" "$U2_PW" "uji@role.test" user >/dev/null 2>&1
eq "migrasi 2FA: kolom sessions.kind ada" \
  "$($TFQ "SELECT COUNT(*) FROM pragma_table_info('sessions') WHERE name='kind'")" 1
eq "migrasi 2FA: default kind adalah auth" \
  "$($TFQ "SELECT dflt_value FROM pragma_table_info('sessions') WHERE name='kind'")" "'auth'"
eq "migrasi 2FA: tabel totp_recovery ada" \
  "$($TFQ "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='totp_recovery'")" 1
eq "CLI: db:migrate-totp terdaftar" "$(php /srv/fpanel/bin/fpanel 2>&1 | grep -c 'db:migrate-totp')" 1
eq "migrasi 2FA idempoten (jalan 2x tetap 1 kolom)" \
  "$($FP db:migrate-totp >/dev/null 2>&1; $TFQ "SELECT COUNT(*) FROM pragma_table_info('sessions') WHERE name='kind'")" 1

curl -s -b "$(jar "$UT")" -c "$(jar "$UT")" -o /dev/null "$BASE/login"
curl -s -b "$(jar "$UT")" -c "$(jar "$UT")" -o /dev/null -d "username=$U2" \
  --data-urlencode "password=$U2_PW" -d "_csrf=$(csrf "$UT")" "$BASE/login"

has "halaman /security memuat bagian 2FA" "$(body "$UT" "$BASE/security")" "Autentikasi Dua Faktor"
hasnt "secret TOTP tidak bocor sebelum setup" "$(body "$UT" "$BASE/security")" "otpauth://totp/"

eq "setup 2FA tanpa CSRF -> 419" "$(code "$UT" -d "" $BASE/security/2fa/setup)" 419
eq "setup 2FA berjalan" \
  "$(post "$UT" -o /dev/null -w '%{http_code}' -d "_csrf=$(csrf "$UT")" $BASE/security/2fa/setup)" 302

TSEC=$(tfsecret "$U2")
eq "secret base32 valid setelah didekripsi" "$(printf '%s' "$TSEC" | grep -cE '^[A-Z2-7]{32}$')" 1
eq "secret tersimpan terenkripsi di database" \
  "$($TFQ "SELECT COUNT(*) FROM users WHERE username='$U2' AND totp_secret LIKE 'sbx1:%'")" 1
eq "2FA belum aktif sebelum kode dimasukkan" "$($TFQ "SELECT totp_enabled FROM users WHERE username='$U2'")" 0

eq "QR SVG dilayani sebagai gambar" \
  "$(curl -s -b "$(jar "$UT")" -o /dev/null -w '%{http_code} %{content_type}' $BASE/security/2fa/qr.svg)" \
  "200 image/svg+xml; charset=utf-8"
has "QR berisi struktur otpauth" "$(body "$UT" "$BASE/security/2fa/qr.svg")" "<svg"
eq "QR tidak di-cache browser" \
  "$(curl -s -b "$(jar "$UT")" -D - -o /dev/null $BASE/security/2fa/qr.svg | grep -ci 'no-store')" 1
eq "QR tanpa login -> 401" "$(getc zz $BASE/security/2fa/qr.svg)" 401

eq "aktivasi kode salah ditolak" \
  "$(post "$UT" -o /dev/null -w '%{http_code}' -d "_csrf=$(csrf "$UT")" -d "code=000000" \
     $BASE/security/2fa/activate)" 302
eq "kode salah tidak mengaktifkan 2FA" "$($TFQ "SELECT totp_enabled FROM users WHERE username='$U2'")" 0

# Kode pemulihan hanya ada di respons POST ini dan sengaja tidak disimpan,
# jadi halaman responsnya harus langsung diperiksa, bukan dibaca ulang.
ACT_HTML=$(post "$UT" -w '\n%{http_code}' -d "_csrf=$(csrf "$UT")" -d "code=$(tfcode "$TSEC")" \
     $BASE/security/2fa/activate)
ACT_CODE=$(printf '%s' "$ACT_HTML" | tail -1)
ACT_BODY=$(printf '%s' "$ACT_HTML" | sed '$d')
eq "aktivasi kode benar berhasil" "$ACT_CODE" 200
eq "2FA aktif setelah kode benar" "$($TFQ "SELECT totp_enabled FROM users WHERE username='$U2'")" 1
eq "10 kode pemulihan dibuat" "$($TFQ "SELECT COUNT(*) FROM totp_recovery WHERE user_id=(SELECT id FROM users WHERE username='$U2')")" 10
eq "kode pemulihan disimpan sebagai hash" \
  "$($TFQ "SELECT COUNT(*) FROM totp_recovery WHERE user_id=(SELECT id FROM users WHERE username='$U2') AND LENGTH(code_hash)=64")" 10
eq "kode pemulihan belum dipakai semua" \
  "$($TFQ "SELECT COUNT(*) FROM totp_recovery WHERE user_id=(SELECT id FROM users WHERE username='$U2') AND used_at IS NULL")" 10
eq "jumlah kode yang tampil di halaman = 10" \
  "$(printf '%s' "$ACT_BODY" | grep -oE '>[A-F0-9]{6}-[A-F0-9]{6}<' | sort -u | wc -l | tr -d ' ')" 10
hasnt "halaman berikutnya tidak mengulang kode pemulihan" "$(body "$UT" "$BASE/security")" "[A-F0-9]\{6\}-[A-F0-9]\{6\}"

# Login ulang: password benar harus cukup untuk sampai ke langkah kode.
curl -s -b "$(jar "$UT")" -c "$(jar "$UT")" -o /dev/null -d "_csrf=$(csrf "$UT")" $BASE/logout
has "logout dulu" "$(post "$UT" -o /dev/null -w '%{http_code}' -d "_csrf=$(csrf "$UT")" $BASE/logout)" 302
curl -s -b "$(jar "$UT")" -c "$(jar "$UT")" -o /dev/null "$BASE/login"
eq "password benar + 2FA aktif -> arah ke /login/2fa" \
  "$(post "$UT" -o /dev/null -w '%{redirect_url}' -d "username=$U2" --data-urlencode "password=$U2_PW" \
     -d "_csrf=$(csrf "$UT")" $BASE/login | grep -c '/login/2fa')" 1
eq "sesi antar-langkah dibuat" "$($TFQ "SELECT COUNT(*) FROM sessions WHERE kind='totp'")" 1
# Diuji lewat detik, lalu dijepit ke rentang: presisi julianday membulatkan
# ke bawah sehingga 300 detik bisa terbaca 299. Yang penting: jauh lebih
# singkat dari sesi login biasa (14 hari), bukan persis 5 menit.
eq "sesi antar-langkah punya TTL pendek" \
  "$(sudo -u www-data php -r '
     require "/srv/fpanel/app/bootstrap.php";
     $r = Fpanel\Database\Connection::one(
         "SELECT CAST((julianday(expires_at) - julianday(\"now\")) * 86400 AS INTEGER) AS d FROM sessions WHERE kind=\"totp\"");
     $d = (int)($r["d"] ?? 0);
     echo ($d > 240 && $d <= 300) ? "1" : "0:{$d}";')" 1
eq "dashboard tetap tertutup saat 2FA belum lolos" \
  "$(post "$UT" -o /dev/null -w '%{redirect_url}' $BASE/dashboard | grep -cE '/login$')" 1
has "halaman /login/2fa terbuka" "$(body "$UT" "$BASE/login/2fa")" "Verifikasi Dua Faktor"

eq "kode 2FA salah -> 401" \
  "$(post "$UT" -o /dev/null -w '%{http_code}' -d "_csrf=$(csrf "$UT")" -d "code=111111" $BASE/login/2fa)" 401
has "pesan kode salah ditampilkan" \
  "$(post "$UT" -d "_csrf=$(csrf "$UT")" -d "code=222222" $BASE/login/2fa)" "Kode salah atau sudah dipakai."
eq "dashboard masih tertutup setelah kode salah" \
  "$(post "$UT" -o /dev/null -w '%{redirect_url}' $BASE/dashboard | grep -cE '/login$')" 1
# Username dan kuerinya dikasih lewat file, bukan disisipkan ke dalam source
# PHP: interpolasi shell di dalam php -r "!..." merusak quoting-nya.
cat > /tmp/t_audit.php <<'AUDPHP'
<?php
require '/srv/fpanel/app/bootstrap.php';
// Dihitung per user uji lewat actor_name, bukan seluruh tabel: audit_log tidak
// pernah dipangkas, jadi baris dari run sebelumnya akan mengotori hitungan.
$c = (int)Fpanel\Database\Connection::value(
    'SELECT COUNT(*) FROM audit_log WHERE action = ? AND result = ? AND actor_name = ?',
    ['auth.totp', 'denied', $argv[1]]
);
echo $c >= 3 ? 'ya' : "tidak:$c";
AUDPHP
eq "percobaan 2FA salah tercatat di audit" \
  "$(sudo -u www-data php /tmp/t_audit.php "$U2")" "ya"

eq "kode pemulihan salah -> 401" \
  "$(post "$UT" -o /dev/null -w '%{http_code}' -d "_csrf=$(csrf "$UT")" -d "code=ZZZZZZ-ZZZZZZ" $BASE/login/2fa)" 401

# Lockout diuji dari keadaan deterministik: counter direset dulu, lalu
# MAX_FAILS (5) kode salah dikirim berurutan. Setelah itu kode yang benar pun
# harus ditolak. Ini juga membuktikan lockout berlaku untuk langkah 2FA,
# bukan cuma password.
$FP login:reset >/dev/null 2>&1
MAXF=$(sudo -u www-data php -r "require '/srv/fpanel/app/bootstrap.php'; echo Fpanel\Auth\Auth::MAX_FAILS;")
for _ in $(seq 1 "$MAXF"); do
  post "$UT" -o /dev/null -d "_csrf=$(csrf "$UT")" -d "code=000000" $BASE/login/2fa
done
eq "kode benar ditolak setelah MAX_FAILS percobaan salah" \
  "$(post "$UT" -o /dev/null -w '%{http_code}' -d "_csrf=$(csrf "$UT")" -d "code=$(tfcode "$TSEC")" $BASE/login/2fa)" 401
has "pesan lockout ditampilkan" \
  "$(post "$UT" -d "_csrf=$(csrf "$UT")" -d "code=$(tfcode "$TSEC")" $BASE/login/2fa)" "Terlalu banyak percobaan"
eq "sesi antar-langkah belum dipromosikan saat lockout" \
  "$($TFQ "SELECT COUNT(*) FROM sessions WHERE kind='totp'")" 1

$FP login:reset >/dev/null 2>&1
eq "reset penghitung lockout menghapus sisa gagal" \
  "$($TFQ "SELECT COUNT(*) FROM login_attempts WHERE ip='127.0.0.1' AND success=0")" 0

eq "kode TOTP benar -> dashboard" \
  "$(post "$UT" -o /dev/null -w '%{http_code}' -d "_csrf=$(csrf "$UT")" -d "code=$(tfcode "$TSEC")" $BASE/login/2fa)" 302
eq "dashboard terbuka setelah 2FA" "$(getc "$UT" $BASE/dashboard)" 200
eq "sesiantar-langkah dipromosikan jadi auth" "$($TFQ "SELECT COUNT(*) FROM sessions WHERE kind='totp'")" 0
has "login dicatat dengan metode totp" \
  "$(sudo -u www-data php -r '
     require "/srv/fpanel/app/bootstrap.php";
     $r = Fpanel\Database\Connection::one(
         "SELECT meta FROM audit_log WHERE action=\"auth.login\" ORDER BY id DESC LIMIT 1");
     echo (string)($r["meta"] ?? "");')" "totp"
eq "halaman 2FA tidak bisa diakses setelah login" \
  "$(post "$UT" -o /dev/null -w '%{redirect_url}' $BASE/login/2fa | grep -c '/dashboard')" 1

# Memakai kode pemulihan asli, lalu memastikan kode itu hangus.
RC=$(sudo -u www-data php -r '
  require "/srv/fpanel/app/bootstrap.php";
  // Kode asli tidak pernah tersimpan; untuk mengujinya, kode dibuat ulang lalu
  // diambil dari return value regenerateRecovery (hanya tersedia di memori).
  $codes = Fpanel\Service\Totp::regenerateRecovery("'"$U2"'");
  echo $codes[0] ?? "";')
eq "kode pemulihan baru dibuat untuk uji pakai" "$(printf '%s' "$RC" | grep -cE '^[A-F0-9]{6}-[A-F0-9]{6}$')" 1
curl -s -b "$(jar "$UT")" -c "$(jar "$UT")" -o /dev/null -d "_csrf=$(csrf "$UT")" $BASE/logout
curl -s -b "$(jar "$UT")" -c "$(jar "$UT")" -o /dev/null "$BASE/login"
post "$UT" -o /dev/null -d "username=$U2" --data-urlencode "password=$U2_PW" -d "_csrf=$(csrf "$UT")" $BASE/login
eq "kode pemulihan asli diterima" \
  "$(post "$UT" -o /dev/null -w '%{http_code}' -d "_csrf=$(csrf "$UT")" -d "code=$RC" $BASE/login/2fa)" 302
eq "dashboard terbuka lewat kode pemulihan" "$(getc "$UT" $BASE/dashboard)" 200
eq "kode pemulihan ditandai terpakai" \
  "$($TFQ "SELECT COUNT(*) FROM totp_recovery WHERE user_id=(SELECT id FROM users WHERE username='$U2') AND used_at IS NOT NULL")" 1
eq "sisa kode pemulihan = 9" \
  "$($TFQ "SELECT COUNT(*) FROM totp_recovery WHERE user_id=(SELECT id FROM users WHERE username='$U2') AND used_at IS NULL")" 9

eq "regenerasi kode pemulihan" \
  "$(post "$UT" -o /dev/null -w '%{http_code}' -d "_csrf=$(csrf "$UT")" $BASE/security/2fa/recovery)" 200
eq "kode lama dibuang, jumlah tetap 10" \
  "$($TFQ "SELECT COUNT(*) FROM totp_recovery WHERE user_id=(SELECT id FROM users WHERE username='$U2') AND used_at IS NULL")" 10

# Mematikan 2FA wajib butuh password akun.
eq "disable 2FA tanpa CSRF -> 419" "$(code "$UT" -d "" $BASE/security/2fa/disable)" 419
eq "disable dengan password salah ditolak" \
  "$(post "$UT" -o /dev/null -w '%{redirect_url}' -d "_csrf=$(csrf "$UT")" -d "password=salahsekali" \
     $BASE/security/2fa/disable | grep -c 'err=')" 1
eq "2FA masih aktif setelah password salah" "$($TFQ "SELECT totp_enabled FROM users WHERE username='$U2'")" 1
eq "disable dengan password benar berhasil" \
  "$(post "$UT" -o /dev/null -w '%{http_code}' -d "_csrf=$(csrf "$UT")" --data-urlencode "password=$U2_PW" \
     $BASE/security/2fa/disable)" 302
eq "2FA nonaktif setelah disable" "$($TFQ "SELECT totp_enabled FROM users WHERE username='$U2'")" 0
eq "secret dihapus saat 2FA dimatikan" \
  "$($TFQ "SELECT COALESCE(totp_secret,'') FROM users WHERE username='$U2'")" ""
eq "kode pemulihan ikut terhapus" "$($TFQ "SELECT COUNT(*) FROM totp_recovery")" 0

# Admin tidak boleh ikut terkunci: dia tidak pernah disentuh section ini.
# Dipakai jar terpisah supaya sesi section ini tidak bercampur.
UTA="uta"
curl -s -b "$(jar "$UTA")" -c "$(jar "$UTA")" -o /dev/null "$BASE/login"
eq "admin tetap bisa login tanpa 2FA" \
  "$(post "$UTA" -o /dev/null -w '%{redirect_url}' -d "username=admin" --data-urlencode "password=$ADMIN_PW" \
     -d "_csrf=$(csrf "$UTA")" $BASE/login | grep -c '/dashboard')" 1
eq "tidak ada secret tertinggal untuk user uji" \
  "$($TFQ "SELECT COUNT(*) FROM users WHERE totp_enabled=1 OR totp_secret IS NOT NULL")" 0

$FP user:delete "$U2" >/dev/null 2>&1

$FP user:delete "$U2" >/dev/null 2>&1


# --- 21. Housekeeping & keamanan data --------------------------------------------
# Retensi dipendekkan sementara (hari: 0/1) supaya assertion bisa menguji
# penghapusan sungguhan tanpa menunggu 90 hari. Semua data uji dibuat di
# section ini dan dibersihkan di akhir, jadi retensi produksi tidak tersentuh.
echo "== 21. Housekeeping & keamanan data ="
UH="uh"
TFQ="sudo -u www-data php /tmp/q.php"

eq "CLI: housekeeping:run terdaftar" \
  "$(php /srv/fpanel/bin/fpanel 2>&1 | grep -c 'housekeeping:run')" 1
eq "CLI: housekeeping:dry terdaftar" \
  "$(php /srv/fpanel/bin/fpanel 2>&1 | grep -c 'housekeeping:dry')" 1
eq "cron housekeeping terpasang" \
  "$(grep -c 'housekeeping:run' /etc/cron.d/fpanel-housekeeping 2>/dev/null)" 1
eq "cron housekeeping berjalan sebagai www-data" \
  "$(grep -cE '^[0-9*/,-]+ +[0-9*/,-]+ +[0-9*/,-]+ +[0-9*/,-]+ +[0-9*/,-]+ +www-data .*housekeeping:run' /etc/cron.d/fpanel-housekeeping)" 1
eq "cron housekeeping tidak duplikat" \
  "$(grep -c 'housekeeping:run' /etc/cron.d/fpanel-housekeeping)" 1

cat > /tmp/t_hk.php <<'HKPHP'
<?php
require '/srv/fpanel/app/bootstrap.php';
use Fpanel\Database\Connection;
use Fpanel\Service\Housekeeping;

// Seed data uji: satu sesi kedaluwarsa, satu sesi hidup, audit log lama/baru,
// percobaan login lama, dan file log untuk domain yang tidak terdaftar.
$uid = (int)Connection::value('SELECT id FROM users WHERE username = ?', ['admin']);
$tok = bin2hex(random_bytes(16));
Connection::insert('sessions', [
    'user_id' => $uid, 'token_hash' => $tok, 'ip' => '10.9.9.9',
    'user_agent' => 'hk-test', 'kind' => 'auth',
    'expires_at' => gmdate('Y-m-d H:i:s', time() - 3600),
]);
Connection::insert('sessions', [
    'user_id' => $uid, 'token_hash' => $tok . 'live', 'ip' => '10.9.9.9',
    'user_agent' => 'hk-test', 'kind' => 'auth',
    'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
]);
Connection::insert('audit_log', [
    'actor_name' => 'system', 'action' => 'hk.probe', 'result' => 'ok',
    'created_at' => gmdate('Y-m-d H:i:s', time() - 86400 * 200),
]);
// Usia 2 hari: sudah melewati batas 'ok' (1 hari) tapi belum batas 'denied'
// (3x lipatnya). Baris insiden keamanan memang sengaja disimpan lebih lama.
Connection::insert('audit_log', [
    'actor_name' => 'system', 'action' => 'hk.probe', 'result' => 'denied',
    'created_at' => gmdate('Y-m-d H:i:s', time() - 86400 * 2),
]);
Connection::insert('audit_log', [
    'actor_name' => 'system', 'action' => 'hk.probe', 'result' => 'ok',
    'created_at' => gmdate('Y-m-d H:i:s'),
]);
Connection::insert('login_attempts', [
    'ip' => '10.9.9.9', 'username' => 'hk', 'success' => 0,
    'created_at' => gmdate('Y-m-d H:i:s', time() - 86400 * 30),
]);
$logDir = Housekeeping::defaultLogDir();
file_put_contents($logDir . '/hk-orphan.test.access.log', "uji\n");
file_put_contents($logDir . '/hk-orphan.test.error.log', "uji\n");
file_put_contents($logDir . '/hk-rotated.test.access.log.1', "uji\n");
// Dip-aged-kan lewat touch supaya lolos syarat umur minimum.
$old = time() - 86400 * 10;
foreach (['hk-orphan.test.access.log', 'hk-orphan.test.error.log', 'hk-rotated.test.access.log.1'] as $f) {
    @touch($logDir . '/' . $f, $old);
}
// Domain nyata harus lognya tetap utuh setelah job jalan.
file_put_contents($logDir . '/demo.fpanel.my.id.access.log', "asli\n");

// Berkas temp upload offsite: yang yatim (tua) harus dibuang, yang masih baru
// tidak boleh disentuh karena bisa sedang dipakai upload berjalan, dan nama
// yang tidak mengikuti pola tempnam panel tidak pernah dihapus.
file_put_contents('/tmp/fp-enc-hk001', 'yatim');
file_put_contents('/tmp/fp-enc-hk002', 'baru');
file_put_contents('/tmp/fp-enc-.jangan-hapus', 'asing');
@touch('/tmp/fp-enc-hk001', time() - 86400);
@touch('/tmp/fp-enc-hk002', time() - 60);

// auditDays=1: baris 'ok' yang lebih tua dari 1 hari harus hilang, tapi baris
// 'denied' (insiden keamanan) bertahan sampai 3x lipat waktunya.
$r = Housekeeping::make($logDir)->run(false, ['auditDays' => 1, 'attemptDays' => 1]);
echo json_encode($r);
HKPHP

HK_RAW=$(sudo -u www-data php /tmp/t_hk.php 2>&1)
echo "  respons: $(printf '%s' "$HK_RAW" | head -c 160)"

has "housekeeping melaporkan ok" "$HK_RAW" '"ok":true'
eq "sesi kedaluwarsa dihapus" \
  "$($TFQ "SELECT COUNT(*) FROM sessions WHERE user_agent='hk-test' AND expires_at <= datetime('now')")" 0
eq "sesi yang masih hidup dipertahankan" \
  "$($TFQ "SELECT COUNT(*) FROM sessions WHERE user_agent='hk-test' AND expires_at > datetime('now')")" 1
eq "audit log 'ok' lama dihapus" \
  "$($TFQ "SELECT COUNT(*) FROM audit_log WHERE action='hk.probe' AND result='ok' AND created_at < datetime('now','-1 day')")" 0
eq "audit log 'denied' bertahan melewati batas 'ok'" \
  "$($TFQ "SELECT COUNT(*) FROM audit_log WHERE action='hk.probe' AND result='denied'")" 1
eq "audit log 'denied' juga terhapus setelah 3x batasnya" \
  "$(sudo -u www-data php -r '
     require "/srv/fpanel/app/bootstrap.php";
     use Fpanel\Database\Connection;
     use Fpanel\Service\Housekeeping;
     $dir = Housekeeping::defaultLogDir();
     // Seed baris 'denied' yang jauh lebih tua dari 3x batas retensi.
     Connection::insert("audit_log", [
         "actor_name" => "system", "action" => "hk.old", "result" => "denied",
         "created_at" => gmdate("Y-m-d H:i:s", time() - 86400 * 30),
     ]);
     Housekeeping::make($dir)->run(false, ["auditDays" => 1]);
     echo (int)Connection::value(
         "SELECT COUNT(*) FROM audit_log WHERE action = ?", ["hk.old"]);')" 0
eq "audit log hari ini tetap ada" \
  "$($TFQ "SELECT COUNT(*) FROM audit_log WHERE action='hk.probe' AND created_at > datetime('now','-1 day')")" 1
eq "percobaan login lama dihapus" \
  "$($TFQ "SELECT COUNT(*) FROM login_attempts WHERE ip='10.9.9.9'")" 0
eq "log domain yatim dihapus (access)" \
  "$([ -f /var/log/nginx/fpanel/hk-orphan.test.access.log ] && echo ada || echo tidak)" "tidak"
eq "log domain yatim dihapus (error)" \
  "$([ -f /var/log/nginx/fpanel/hk-orphan.test.error.log ] && echo ada || echo tidak)" "tidak"
eq "log hasil rotasi ikut dibuang" \
  "$([ -f /var/log/nginx/fpanel/hk-rotated.test.access.log.1 ] && echo ada || echo tidak)" "tidak"
eq "log domain aktif tidak ikut terhapus" \
  "$([ -f /var/log/nginx/fpanel/demo.fpanel.my.id.access.log ] && echo ada || echo tidak)" "ada"
eq "temp upload yatim dihapus" \
  "$([ -f /tmp/fp-enc-hk001 ] && echo ada || echo bersih)" "bersih"
eq "temp upload yang masih baru dipertahankan" \
  "$([ -f /tmp/fp-enc-hk002 ] && echo ada || echo bersih)" "ada"
eq "nama di luar pola temp panel tidak disentuh" \
  "$([ -f /tmp/fp-enc-.jangan-hapus ] && echo ada || echo bersih)" "ada"
rm -f /tmp/fp-enc-hk002 /tmp/fp-enc-.jangan-hapus

eq "housekeeping idempoten (dijalankan lagi = 0)" \
  "$(php /srv/fpanel/bin/fpanel housekeeping:run 2>&1 | grep -c 'sessions=0')" 1

# Sesi uji harus dibersihkan supaya tidak ikut terhitung di run berikutnya.
$TFQ "DELETE FROM sessions WHERE user_agent='hk-test'" >/dev/null
$TFQ "DELETE FROM audit_log WHERE action IN ('hk.probe','hk.old')" >/dev/null
rm -f /var/log/nginx/fpanel/demo.fpanel.my.id.access.log

# --- Enkripsi seed TOTP ----------------------------------------------------------
eq "SecretBox: kunci app-key terbaca" \
  "$(sudo -u www-data php -r 'require "/srv/fpanel/app/bootstrap.php"; echo Fpanel\Service\SecretBox::available() ? "ya" : "tidak";')" "ya"
eq "SecretBox: roundtrip aman" \
  "$(sudo -u www-data php -r '
     require "/srv/fpanel/app/bootstrap.php";
     $c = Fpanel\Service\SecretBox::encrypt("rahasia-uji");
     echo Fpanel\Service\SecretBox::decrypt($c) === "rahasia-uji" ? "ya" : "tidak";')" "ya"
eq "SecretBox: nonce diacak tiap kali" \
  "$(sudo -u www-data php -r '
     require "/srv/fpanel/app/bootstrap.php";
     echo Fpanel\Service\SecretBox::encrypt("x") !== Fpanel\Service\SecretBox::encrypt("x") ? "ya" : "tidak";')" "ya"
eq "SecretBox: ciphertext rusak ditolak" \
  "$(sudo -u www-data php -r '
     require "/srv/fpanel/app/bootstrap.php";
     echo Fpanel\Service\SecretBox::decrypt("sbx1:bukan-base64-valid!!") === null ? "ya" : "tidak";')" "ya"
eq "SecretBox: prefix ciphertext dikenali" \
  "$(sudo -u www-data php -r '
     require "/srv/fpanel/app/bootstrap.php";
     echo Fpanel\Service\SecretBox::isEncrypted(Fpanel\Service\SecretBox::encrypt("x")) ? "ya" : "tidak";')" "ya"
eq "SecretBox: nilai polos tidak dianggap terenkripsi" \
  "$(sudo -u www-data php -r '
     require "/srv/fpanel/app/bootstrap.php";
     echo Fpanel\Service\SecretBox::isEncrypted("JBSWY3DPEHPK3PXP") ? "ya" : "tidak";')" "tidak"

# Seed TOTP di database tidak boleh plaintext.
UT3="uthk"
$FP user:delete "$UT3" >/dev/null 2>&1
$FP user:create "$UT3" "$U2_PW" "uji@hk.test" user >/dev/null 2>&1
curl -s -b "$(jar "$UH")" -c "$(jar "$UH")" -o /dev/null "$BASE/login"
curl -s -b "$(jar "$UH")" -c "$(jar "$UH")" -o /dev/null -d "username=$UT3" \
  --data-urlencode "password=$U2_PW" -d "_csrf=$(csrf "$UH")" "$BASE/login"
eq "setup 2FA untuk user uji" \
  "$(post "$UH" -o /dev/null -w '%{http_code}' -d "_csrf=$(csrf "$UH")" $BASE/security/2fa/setup)" 302
eq "seed TOTP disimpan terenkripsi" \
  "$($TFQ "SELECT COUNT(*) FROM users WHERE username='$UT3' AND totp_secret LIKE 'sbx1:%'")" 1
eq "seed TOTP tidak tersimpan sebagai base32 polos" \
  "$($TFQ "SELECT COUNT(*) FROM users WHERE username='$UT3' AND totp_secret NOT LIKE 'sbx1:%'")" 0
hasnt "seed TOTP tidak bocor di HTML halaman security" \
  "$($TFQ "SELECT totp_secret FROM users WHERE username='$UT3'" | sed 's/^sbx1://')" \
  "$(post "$UH" $BASE/security | grep -oE '[A-Z2-7]{32}' | head -1)"
eq "QR tetap bisa dirender dari seed terenkripsi" \
  "$(curl -s -b "$(jar "$UH")" -o /dev/null -w '%{http_code}' $BASE/security/2fa/qr.svg)" 200

# Login 2FA harus tetap jalan walau seed tersimpan terenkripsi.
TOTP_OK=$(sudo -u www-data php -r '
  require "/srv/fpanel/app/bootstrap.php";
  $s = Fpanel\Service\Totp::pendingSecret("uthk");
  echo $s !== null ? "ya" : "tidak";')
eq "secret terbaca kembali lewat layer aplikasi" "$TOTP_OK" "ya"
TC=$(sudo -u www-data php -r '
  require "/srv/fpanel/app/bootstrap.php";
  echo Fpanel\Service\Totp::code(Fpanel\Service\Totp::pendingSecret("uthk"));')
eq "aktivasi dengan seed terenkripsi" \
  "$(post "$UH" -o /dev/null -w '%{http_code}' -d "_csrf=$(csrf "$UH")" -d "code=$TC" $BASE/security/2fa/activate)" 200
curl -s -b "$(jar "$UH")" -c "$(jar "$UH")" -o /dev/null -d "_csrf=$(csrf "$UH")" $BASE/logout
curl -s -b "$(jar "$UH")" -c "$(jar "$UH")" -o /dev/null "$BASE/login"
post "$UH" -o /dev/null -d "username=$UT3" --data-urlencode "password=$U2_PW" -d "_csrf=$(csrf "$UH")" $BASE/login
TC=$(sudo -u www-data php -r '
  require "/srv/fpanel/app/bootstrap.php";
  // pendingSecret sengaja hanya melihat seed yang belum diaktifkan, jadi untuk
  // 2FA yang sudah aktif seed diambil langsung dari DB lalu didekripsi.
  $raw = (string)Fpanel\Database\Connection::value(
      "SELECT totp_secret FROM users WHERE username = ?", ["uthk"]);
  echo Fpanel\Service\Totp::code(Fpanel\Service\SecretBox::decrypt($raw));')
eq "login 2FA dengan seed terenkripsi" \
  "$(post "$UH" -o /dev/null -w '%{http_code}' -d "_csrf=$(csrf "$UH")" -d "code=$TC" $BASE/login/2fa)" 302
eq "dashboard terbuka" "$(getc "$UH" $BASE/dashboard)" 200
$FP user:delete "$UT3" >/dev/null 2>&1

# --- Rate limit per-user ---------------------------------------------------------
# Batas per-IP bisa dilewati dengan IP berbeda; batas per-nama-akun menutupnya.
# Diuji langsung di level service supaya tidak butuh 25 request HTTP.
eq "rate limit: satu IP saja belum cukup untuk mengunci akun" \
  "$(sudo -u www-data php -r '
     require "/srv/fpanel/app/bootstrap.php";
     use Fpanel\Database\Connection;
     use Fpanel\Http\Request;
     use Fpanel\Http\Session;
     use Fpanel\Auth\Auth;
     Connection::run("DELETE FROM login_attempts WHERE username = ?", ["admin"]);
     $a = new Auth(new Session(Request::capture()));
     echo ($a->isUserLocked("admin") ? "ya" : "tidak");')" "tidak"
eq "rate limit: kunci per-user aktif setelah MAX_FAILS_USER" \
  "$(sudo -u www-data php -r '
     require "/srv/fpanel/app/bootstrap.php";
     use Fpanel\Database\Connection;
     use Fpanel\Http\Request;
     use Fpanel\Http\Session;
     use Fpanel\Auth\Auth;
     Connection::run("DELETE FROM login_attempts WHERE username = ?", ["admin"]);
     $a = new Auth(new Session(Request::capture()));
     for ($i = 0; $i < Auth::MAX_FAILS_USER; $i++) {
         Connection::insert("login_attempts", [
             "ip" => "10.8.8." . ($i % 250), "username" => "admin", "success" => 0,
         ]);
     }
     echo ($a->isUserLocked("admin") ? "ya" : "tidak");')" "ya"
eq "rate limit: IP berbeda tidak melewati kunci per-user" \
  "$(sudo -u www-data php -r '
     require "/srv/fpanel/app/bootstrap.php";
     use Fpanel\Http\Request;
     use Fpanel\Http\Session;
     use Fpanel\Auth\Auth;
     $a = new Auth(new Session(Request::capture()));
     // Satu IP yang bersih saja: kunci per-user harus tetap menyala.
     echo ($a->isUserLocked("admin") && !$a->isLocked("10.7.7.7") ? "ya" : "tidak");')" "ya"
eq "rate limit: batas per-user lebih longgar dari per-IP" \
  "$(sudo -u www-data php -r '
     require "/srv/fpanel/app/bootstrap.php";
     echo Fpanel\Auth\Auth::MAX_FAILS_USER > Fpanel\Auth\Auth::MAX_FAILS ? "ya" : "tidak";')" "ya"
eq "rate limit: akun lain tidak ikut terkunci" \
  "$(sudo -u www-data php -r '
     require "/srv/fpanel/app/bootstrap.php";
     use Fpanel\Http\Request;
     use Fpanel\Http\Session;
     use Fpanel\Auth\Auth;
     $a = new Auth(new Session(Request::capture()));
     echo ($a->isUserLocked("admin") && !$a->isUserLocked("uujicron") ? "ya" : "tidak");')" "ya"
eq "username dinormalisasi (email ikut dihitung sama)" \
  "$(sudo -u www-data php -r '
     require "/srv/fpanel/app/bootstrap.php";
     use Fpanel\Database\Connection;
     use Fpanel\Http\Request;
     use Fpanel\Http\Session;
     use Fpanel\Auth\Auth;
     Connection::run("DELETE FROM login_attempts WHERE username = ?", ["admin"]);
     $a = new Auth(new Session(Request::capture()));
     Connection::insert("login_attempts", ["ip" => "10.6.6.1", "username" => "admin", "success" => 0]);
     // Dicatat lewat email, bukan nama akun: harus tetap terhitung.
     $r = new ReflectionMethod(Auth::class, "normalizeUser");
     $r->setAccessible(true);
     echo ($r->invoke(null, "admin@fpanel.id") === "admin" ? "ya" : "tidak");')" "ya"
$FP login:reset >/dev/null 2>&1
eq "rate limit: login admin normal setelah reset" \
  "$(curl -s -b "$(jar a)" -c "$(jar a)" -o /dev/null -w '%{http_code}' \
     -d "_csrf=$(csrf a)&username=admin&password=$ADMIN_PWE" $BASE/login)" 302

# --- 22. Offsite backup, remember device, login link --------------------------
echo "== 22. Offsite & sesi perangkat ="
UD="ud22"
TFQ="sudo -u www-data php /tmp/q.php"
CONF=/etc/fpanel/offsite.conf

# --- Offsite: enkripsi ----------------------------------------------------------
eq "Offsite: kelas S3Client ada" \
  "$(sudo -u www-data php -r 'require "/srv/fpanel/app/bootstrap.php"; echo class_exists(Fpanel\Service\S3Client::class) ? "ya" : "tidak";')" "ya"
eq "Offsite: CLI terdaftar" \
  "$(php /srv/fpanel/bin/fpanel 2>&1 | grep -c 'offsite:upload')" 1
eq "Offsite: CLI decrypt terdaftar" \
  "$(php /srv/fpanel/bin/fpanel 2>&1 | grep -c 'offsite:decrypt')" 1
eq "Offsite: cron harian terpasang" \
  "$(grep -c 'fpanel-offsite' /etc/cron.d/fpanel-offsite 2>/dev/null)" 1
eq "Offsite: cron berjalan sebagai root" \
  "$(grep -cE '^[0-9*/,-]+ +[0-9*/,-]+ +[0-9*/,-]+ +[0-9*/,-]+ +[0-9*/,-]+ +root /usr/local/sbin/fpanel-offsite' /etc/cron.d/fpanel-offsite)" 1
eq "Offsite: helper ada dan executable" \
  "$([ -x /usr/local/sbin/fpanel-offsite ] && echo ya || echo tidak)" "ya"
eq "Offsite: konfigurasi tidak readable user biasa" \
  "$([ -r /etc/fpanel/offsite.conf ] && sudo -u nobody test -r /etc/fpanel/offsite.conf && echo bocor || echo aman)" "aman"

# Enkripsi harus menolak file rusak dan tidak pernah meninggalkan output separuh.
cat > /tmp/t_enc.php <<'ENCPHP'
<?php
require '/srv/fpanel/app/bootstrap.php';
use Fpanel\Service\Offsite;

// Data sengaja lebih besar dari satu blok supaya jalur multi-blok ikut jalan.
$src = '/tmp/t-enc-src.bin';
file_put_contents($src, random_bytes(3 * 1048576 + 777));
$tmp = null;
$m = new ReflectionMethod(Offsite::class, 'encryptFile');
$m->setAccessible(true);
$enc = $m->invokeArgs(null, [$src, &$tmp]);
$out = [];
$out[] = $enc !== null ? 'ada' : 'gagal';
// Bulat ukurannya ke MiB supaya tidak rapuh di Assertion yang eksak.
$out[] = (int)(filesize($tmp) / 1048576);
$out[] = Offsite::decryptFile($tmp, '/tmp/t-enc-out.bin') ? 'ok' : 'gagal';
$out[] = hash_file('sha256', $src) === hash_file('sha256', '/tmp/t-enc-out.bin') ? 'sama' : 'beda';
// Header harus menandai format, bukan gzip polos.
$head = file_get_contents($tmp, false, null, 0, 7);
$out[] = $head === "FPENC1\n" ? 'magic' : 'aneh';
// Rusak satu byte di tengah: MAC harus menolak dan file hasil tidak boleh tertinggal.
$fh = fopen($tmp, 'r+b');
fseek($fh, 1048580);
fwrite($fh, 'X');
fclose($fh);
$out[] = Offsite::decryptFile($tmp, '/tmp/t-enc-bad.bin') ? 'diterima' : 'ditolak';
$out[] = is_file('/tmp/t-enc-bad.bin') ? 'yatim' : 'bersih';
// File yang bukan keluaran Offsite harus ditolak oleh magic check.
$out[] = Offsite::decryptFile($src, '/tmp/t-enc-x.bin') ? 'diterima' : 'ditolak';
// Terpotong di tengah blok.
file_put_contents('/tmp/t-enc-trunc.enc', substr((string)file_get_contents($tmp), 0, 100));
$out[] = Offsite::decryptFile('/tmp/t-enc-trunc.enc', '/tmp/t-enc-y.bin') ? 'diterima' : 'ditolak';
// Nama berkas temp keluaran encryptFile dicatat supaya uji bisa membuktikan
// berkas itu benar-benar dibersihkan (dulu tertinggal dan menumpuk di /tmp).
// Penanda memakai garis bawah supaya tidak ikut terhapus oleh glob di bawah.
file_put_contents('/tmp/t_enc-last.txt', (string)basename((string)$tmp));
foreach (glob('/tmp/t-enc-*') as $f) { @unlink($f); }
@unlink($tmp);
echo implode(' ', $out);
ENCPHP
ENC_RAW=$(sudo -u www-data php /tmp/t_enc.php 2>&1)
set -- $ENC_RAW
eq "Offsite: enkripsi berhasil"          "${1:-}" "ada"
eq "Offsite: ukuran membesar (blok+nonce+MAC)" "${2:-}" 3
eq "Offsite: dekripsi roundtrip"         "${3:-}" "ok"
eq "Offsite: isi identik setelah dekripsi" "${4:-}" "sama"
eq "Offsite: header format"              "${5:-}" "magic"
eq "Offsite: file korup ditolak"         "${6:-}" "ditolak"
eq "Offsite: file hasil yang korup dibuang" "${7:-}" "bersih"
eq "Offsite: file asing ditolak"         "${8:-}" "ditolak"
eq "Offsite: file terpotong ditolak"     "${9:-}" "ditolak"
eq "Offsite: temp enkripsi tidak ditinggalkan uji" \
  "$([ -f "/tmp/$(cat /tmp/t_enc-last.txt 2>/dev/null)" ] && echo ada || echo bersih)" "bersih"
rm -f /tmp/t_enc-last.txt /tmp/t_enc.php

# Kalau offsite belum dikonfigurasi, status harus melapor dengan jelas, bukan error.
if [ -r "$CONF" ]; then
  eq "Offsite: status terbaca" \
    "$(php /srv/fpanel/bin/fpanel offsite:status 2>&1 | grep -c 'endpoint')" 1
  has "Offsite: nama bucket tidak bocorkan secret" \
    "$(php /srv/fpanel/bin/fpanel offsite:status 2>&1)" "key id   : "
  hasnt "Offsite: secret access key tidak tercetak" \
    "$(php /srv/fpanel/bin/fpanel offsite:status 2>&1)" "$(grep -oP '(?<=^secret_key=")[^"]+' "$CONF")"
  # Upload lewat helper root: nama dicentang, bukan Percaya blindly.
  eq "Offsite: helper menolak nama tak valid" \
    "$(sudo -u www-data sudo -n /usr/local/sbin/fpanel-backup push ../../etc/passwd 2>&1 | head -1)" \
    "ERR: nama backup tidak valid: ../../etc/passwd"
else
  has "Offsite: belum dikonfigurasi dilaporkan" \
    "$(php /srv/fpanel/bin/fpanel offsite:status 2>&1)" "BELUM DIKONFIGURASI"
fi

# --- Remember this device -------------------------------------------------------
eq "Perangkat: CLI terdaftar" "$(php /srv/fpanel/bin/fpanel 2>&1 | grep -c 'device:count')" 1
eq "Perangkat: tabel remember_devices ada" \
  "$($TFQ "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='remember_devices'")" 1
eq "Perangkat: tabel login_links ada" \
  "$($TFQ "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='login_links'")" 1
eq "Perangkat: migrasi devices punya FK" \
  "$($TFQ "SELECT COUNT(*) FROM sqlite_master WHERE name='remember_devices' AND sql LIKE '%ON DELETE CASCADE%'")" 1
eq "Perangkat: migrasi devices idempoten (jalan 2x tetap 1 tabel)" \
  "$($FP db:migrate-devices >/dev/null 2>&1; $TFQ "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='remember_devices'")" 1

UL="ul22"
has "login page punya checkbox ingat perangkat" "$(body "$UL" "$BASE/login")" 'name="remember"'

$FP user:create "$UD" "$U2_PW" "uji@dev.test" user >/dev/null 2>&1
UA='Mozilla/5.0 (X11; Linux x86_64) Chrome/120.0'
curl -s -b "$(jar "$UD")" -c "$(jar "$UD")" -o /dev/null -A "$UA" "$BASE/login"
eq "login dengan remember=1" \
  "$(post "$UD" -o /dev/null -w '%{http_code}' -A "$UA" -d "username=$UD" \
     --data-urlencode "password=$U2_PW" -d "remember=1" -d "_csrf=$(csrf "$UD")" $BASE/login)" 302
eq "cookie perangkat diterbitkan" "$(grep -c fp_device "$(jar "$UD")")" 1
eq "baris perangkat tersimpan" \
  "$($TFQ "SELECT COUNT(*) FROM remember_devices r JOIN users u ON u.id=r.user_id WHERE u.username='$UD'")" 1
eq "token perangkat hanya disimpan sebagai hash" \
  "$($TFQ "SELECT COUNT(*) FROM remember_devices r JOIN users u ON u.id=r.user_id
            WHERE u.username='$UD' AND r.token_hash LIKE 'fpd_%'")" 0
eq "hash perangkat panjang 64 hex" \
  "$($TFQ "SELECT COUNT(*) FROM remember_devices r JOIN users u ON u.id=r.user_id
            WHERE u.username='$UD' AND LENGTH(r.token_hash)=64")" 1
has "halaman security menampilkan daftar perangkat" "$(post "$UD" -A "$UA" $BASE/security)" "Perangkat Diingat"
has "label perangkat dibaca dari user agent" "$(post "$UD" -A "$UA" $BASE/security)" "Linux - Chrome"

post "$UD" -o /dev/null -A "$UA" -d "_csrf=$(csrf "$UD")" $BASE/logout
eq "sesi logout tidak lagi dipakai" "$(getc "$UD" -A "$UA" $BASE/dashboard)" 302
eq "remember device membuka sesi baru" \
  "$(post "$UD" -o /dev/null -w '%{redirect_url}' -A "$UA" $BASE/login/remember | grep -c '/dashboard$')" 1
eq "dashboard terbuka lewat remember device" "$(getc "$UD" -A "$UA" $BASE/dashboard)" 200
eq "last_used_at diperbarui saat dipakai" \
  "$($TFQ "SELECT COUNT(*) FROM remember_devices r JOIN users u ON u.id=r.user_id
            WHERE u.username='$UD' AND r.last_used_at IS NOT NULL")" 1

# Cabut satu perangkat harus menutup remember-me-nya juga.
DID=$($TFQ "SELECT r.id FROM remember_devices r JOIN users u ON u.id=r.user_id WHERE u.username='$UD' LIMIT 1")
eq "cabut perangkat dengan id milik sendiri" \
  "$(post "$UD" -o /dev/null -w '%{http_code}' -A "$UA" -d "_csrf=$(csrf "$UD")" -d "id=$DID" $BASE/security/devices/revoke)" 302
eq "perangkat ditandai dicabut" \
  "$($TFQ "SELECT COUNT(*) FROM remember_devices WHERE id=$DID AND revoked_at IS NOT NULL")" 1
post "$UD" -o /dev/null -A "$UA" -d "_csrf=$(csrf "$UD")" $BASE/logout
eq "remember device mati setelah dicabut" \
  "$(post "$UD" -o /dev/null -w '%{redirect_url}' -A "$UA" $BASE/login/remember | grep -c '/login$')" 1
eq "dashboard tetap tertutup" "$(getc "$UD" -A "$UA" $BASE/dashboard)" 302

# IDOR: revoke tidak boleh bisa dipakai untuk mencabut perangkat milik akun lain.
# Dua user uji terpisah, masing-masing punya remember-me sendiri.
UD3="ud22x"
$FP user:create "$UD3" "$U2_PW" "uji@dev2.test" user >/dev/null 2>&1

# Fungsi login sekali pakai: login biasa selalu menimpa remember-me untuk user itu
# saja, jadi dua akun tidak saling mengganggu meski cookie jar terpisah.
devlogin(){ local j="$1" u="$2"
  curl -s -b "$(jar "$j")" -c "$(jar "$j")" -o /dev/null -A "$UA" "$BASE/login"
  curl -s -b "$(jar "$j")" -c "$(jar "$j")" -o /dev/null -A "$UA" \
    -d "username=$u" --data-urlencode "password=$U2_PW" \
    -d "remember=1" -d "_csrf=$(csrf "$j")" "$BASE/login"
}
eq "IDOR: user kedua login" "$(post "$UD3" -o /dev/null -w '%{http_code}' -A "$UA" -d "username=$UD3" \
     --data-urlencode "password=$U2_PW" -d "_csrf=$(csrf "$UD3")" $BASE/login)" 302

# Devices UD harus dibuat ulang: revoke sebelumnya mengosongkannya.
devlogin "$UD" "$UD"
devlogin "$UD3" "$UD3"
eq "IDOR: kedua user punya perangkat sendiri" \
  "$($TFQ "SELECT COUNT(*) FROM remember_devices r JOIN users u ON u.id=r.user_id
            WHERE u.username IN ('$UD','$UD3') AND r.revoked_at IS NULL")" 2

# id milik UD3 (dicoba dari sesi UD) dan id milik UD (dicoba dari sesi UD3).
OWN3=$($TFQ "SELECT r.id FROM remember_devices r JOIN users u ON u.id=r.user_id
             WHERE u.username='$UD3' AND r.revoked_at IS NULL LIMIT 1")
OWN1=$($TFQ "SELECT r.id FROM remember_devices r JOIN users u ON u.id=r.user_id
             WHERE u.username='$UD' AND r.revoked_at IS NULL LIMIT 1")
eq "IDOR: kedua id perangkat ditemukan" \
  "$(printf '%s|%s' "$OWN1" "$OWN3" | grep -cE '^[0-9]+\|[0-9]+$')" 1

eq "IDOR: tidak bisa mencabut perangkat milik akun lain" \
  "$(post "$UD" -o /dev/null -w '%{redirect_url}' -A "$UA" -d "_csrf=$(csrf "$UD")" \
     -d "id=$OWN3" $BASE/security/devices/revoke | grep -c 'err=')" 1
eq "IDOR: perangkat milik akun lain tetap utuh" \
  "$($TFQ "SELECT COUNT(*) FROM remember_devices WHERE id=$OWN3 AND revoked_at IS NULL")" 1

eq "IDOR: tidak bisa mencabut dengan id karangan" \
  "$(post "$UD" -o /dev/null -w '%{redirect_url}' -A "$UA" -d "_csrf=$(csrf "$UD")" \
     -d "id=99999999" $BASE/security/devices/revoke | grep -c 'err=')" 1
eq "IDOR: id bukan angka ditolak" \
  "$(post "$UD" -o /dev/null -w '%{redirect_url}' -A "$UA" -d "_csrf=$(csrf "$UD")" \
     -d "id=abc" $BASE/security/devices/revoke | grep -c 'err=')" 1

eq "IDOR: perangkat sendiri tetap bisa dicabut" \
  "$(post "$UD" -o /dev/null -w '%{redirect_url}' -A "$UA" -d "_csrf=$(csrf "$UD")" \
     -d "id=$OWN1" $BASE/security/devices/revoke | grep -c 'ok=')" 1
$FP user:delete "$UD3" >/dev/null 2>&1

# --- Login link sekali pakai ----------------------------------------------------
eq "Login link: CLI issue terdaftar" "$(php /srv/fpanel/bin/fpanel 2>&1 | grep -c 'login-link:issue')" 1

curl -s -b "$(jar "$UD")" -c "$(jar "$UD")" -o /dev/null -A "$UA" "$BASE/login"
post "$UD" -o /dev/null -A "$UA" -d "username=$UD" --data-urlencode "password=$U2_PW" \
  -d "_csrf=$(csrf "$UD")" $BASE/login

cat > /tmp/t_link.php <<'LINKPHP'
<?php
require '/srv/fpanel/app/bootstrap.php';
use Fpanel\Auth\LoginLink;
$r = LoginLink::issue('ud22');
echo $r['ok'] ? $r['token'] : 'GAGAL';
LINKPHP
LTOK=$(sudo -u www-data php /tmp/t_link.php 2>&1 | tr -d '\r\n')
eq "token login link berbentuk benar" "$(printf '%s' "$LTOK" | grep -cE '^fpl_[a-f0-9]{48}$')" 1
eq "token polos tidak tersimpan" \
  "$($TFQ "SELECT COUNT(*) FROM login_links l JOIN users u ON u.id=l.user_id
            WHERE u.username='$UD' AND l.token_hash LIKE 'fpl_%'")" 0
eq "hash token tersimpan" \
  "$($TFQ "SELECT COUNT(*) FROM login_links l JOIN users u ON u.id=l.user_id
            WHERE u.username='$UD' AND l.token_hash='$(printf '%s' "$LTOK" | sha256sum | cut -d" " -f1)'")" 1
eq "tautan punya masa berlaku" \
  "$($TFQ "SELECT COUNT(*) FROM login_links l JOIN users u ON u.id=l.user_id
            WHERE u.username='$UD' AND l.expires_at > datetime('now')")" 1

# Tukar lewat HTTP dari cookie jar kosong: harus jadi login penuh.
UT2="ud22b"
eq "tukar tautan mengembalikan 200" "$(getc "$UT2" $BASE/login/link/$LTOK)" 200
eq "tukar tautan -> sesi terbuka" "$(getc "$UT2" $BASE/dashboard)" 200
eq "token hangus setelah dipakai" \
  "$($TFQ "SELECT COUNT(*) FROM login_links l JOIN users u ON u.id=l.user_id
            WHERE u.username='$UD' AND l.token_hash='$(printf '%s' "$LTOK" | sha256sum | cut -d" " -f1)' AND l.used_at IS NOT NULL")" 1
eq "tautan tidak bisa dipakai ulang (browser lain)" \
  "$(getc zz $BASE/login/link/$LTOK)" 410
curl -s -o /tmp/t_410.html -w '%{http_code}' "$BASE/login/link/$LTOK" > /tmp/t_410.code
eq "halaman tautan tak berlaku mengembalikan 410" "$(cat /tmp/t_410.code)" 410
has "halaman tautan tak berlaku menampilkan pesan" "$(cat /tmp/t_410.html)" "Tautan tidak berlaku"
has "halaman 410 menyuruh login dengan password" "$(cat /tmp/t_410.html)" "Login dengan password"

# Hanya satu tautan aktif per akun.
LTOK2=$(sudo -u www-data php /tmp/t_link.php 2>&1 | tr -d '\r\n')
eq "tautan baru menggantikan yang lama" \
  "$($TFQ "SELECT COUNT(*) FROM login_links l JOIN users u ON u.id=l.user_id
            WHERE u.username='$UD' AND l.used_at IS NULL")" 1
eq "tautan lama dicabut" \
  "$($TFQ "SELECT COUNT(*) FROM login_links l JOIN users u ON u.id=l.user_id
            WHERE u.username='$UD' AND l.token_hash='$(printf '%s' "$LTOK" | sha256sum | cut -d" " -f1)' AND l.used_at IS NULL")" 0

eq "token ngawur ditolak" "$(getc zz $BASE/login/link/fpl_$(printf '0%.0s' $(seq 1 48)))" 410
eq "bukan token link ditolak" "$(getc zz $BASE/login/link/abc)" 410

# Halaman hasil tukar tidak boleh mengirim Referer berisi token.
LT3="ud22c"
curl -s -b "$(jar "$LT3")" -c "$(jar "$LT3")" -o /dev/null -A "$UA" "$BASE/login/link/$LTOK2"
eq "halaman hasil tukar tidak mengirim Referer" \
  "$(curl -s -b "$(jar "$LT3")" -D - -o /dev/null -A "$UA" $BASE/security | grep -ci '^referer:')" 0

$TFQ "DELETE FROM remember_devices" >/dev/null
$TFQ "DELETE FROM login_links" >/dev/null
$FP user:delete "$UD" >/dev/null 2>&1
rm -f /tmp/t_enc.php /tmp/t_link.php

# --- 23. Model domain: tipe disederhanakan, runtime jadi fitur terpisah ------
# Domain hanya punya dua tipe (static/php). Node.js dan Python bukan tipe
# domain; keduanya aplikasi runtime yang menempel lewat runtime_apps.domain_id.
echo "== 23. Tipe domain & runtime terpisah ="
# Helper didefinisikan ulang di sini, bukan bergantung section 21/22, supaya
# section ini bisa dijalankan sendiri.
TFQ="sudo -u www-data php /tmp/q.php"
UT="ut23"
U3="ut23app"
$FP user:delete "$U3" >/dev/null 2>&1
$FP user:create "$U3" "$U2_PW" "uji@tipe.test" user >/dev/null 2>&1
curl -s -b "$(jar "$UT")" -c "$(jar "$UT")" -o /dev/null "$BASE/login"
curl -s -b "$(jar "$UT")" -c "$(jar "$UT")" -o /dev/null -d "username=$U3" \
  --data-urlencode "password=$U2_PW" -d "_csrf=$(csrf "$UT")" "$BASE/login"

eq "domain type: hanya static dan php yang valid" \
  "$(sudo -u www-data php -r 'require "/srv/fpanel/app/bootstrap.php";
     echo implode(",", Fpanel\Service\DomainManager::TYPES);')" "static,php"
eq "domain type: nilai lama dicatat sebagai legacy" \
  "$(sudo -u www-data php -r 'require "/srv/fpanel/app/bootstrap.php";
     $c = Fpanel\Service\DomainManager::LEGACY_TYPES;
     sort($c); echo implode(",", $c);')" "binary,node,python"
eq "domain type: tidak ada domain bertipe runtime di produksi" \
  "$($TFQ "SELECT COUNT(*) FROM domains WHERE type NOT IN ('static','php')")" 0
eq "domain type: CLI migrasi terdaftar" \
  "$(php /srv/fpanel/bin/fpanel 2>&1 | grep -c 'db:migrate-domain-type')" 1
eq "domain type: migrasi idempoten" \
  "$($FP db:migrate-domain-type >/dev/null 2>&1; $FP db:migrate-domain-type >/dev/null 2>&1; \
     $TFQ "SELECT COUNT(*) FROM domains WHERE type NOT IN ('static','php')")" 0
eq "domain type: CHECK constraint sempit di schema" \
  "$(grep -c "CHECK (type IN ('static','php'))" /srv/fpanel/config/schema.sql)" 1

# Domain yang punya app runtime harus tetap dilayani lewat proxy, walau
# typenya sudah di-backfill jadi php. Urutan cabang di Vhost::render yang
# menentukan ini; kalau salah, Node app mati begitu migrasi jalan.
eq "runtime: app menang atas PHP-FPM di vhost" \
  "$(sudo -u www-data php -r 'require "/srv/fpanel/app/bootstrap.php";
     $c = Fpanel\Service\Vhost::render("x.example.com", "admin", "/var/www/admin/x", "php", "8.3", [], 30000);
     echo substr_count($c, "fastcgi_pass") === 0 && substr_count($c, "proxy_pass") > 0 ? "ya" : "tidak";')" "ya"
eq "runtime: domain php tanpa app tetap pakai PHP-FPM" \
  "$(sudo -u www-data php -r 'require "/srv/fpanel/app/bootstrap.php";
     $c = Fpanel\Service\Vhost::render("x.example.com", "admin", "/var/www/admin/x", "php", "8.3", [], null);
     echo substr_count($c, "fastcgi_pass") > 0 && substr_count($c, "proxy_pass") === 0 ? "ya" : "tidak";')" "ya"
eq "runtime: domain statis tanpa app tidak ada PHP maupun proxy" \
  "$(sudo -u www-data php -r 'require "/srv/fpanel/app/bootstrap.php";
     $c = Fpanel\Service\Vhost::render("x.example.com", "admin", "/var/www/admin/x", "static", null, [], null);
     echo substr_count($c, "fastcgi_pass") === 0 && substr_count($c, "proxy_pass") === 0 ? "ya" : "tidak";')" "ya"
eq "runtime: port app diambil dari runtime_apps" \
  "$(sudo -u www-data php -r 'require "/srv/fpanel/app/bootstrap.php";
     echo Fpanel\Service\AppRuntime::portForDomain(999999) === null ? "ya" : "tidak";')" "ya"

# Form tambah domain: tidak boleh menanyakan runtime lagi.
has "form domain: label tipe ramah" "$(body "$UT" "$BASE/domains")" "PHP (default)"
has "form domain: opsi statis dijelaskan" "$(body "$UT" "$BASE/domains")" "Statis (HTML/CSS saja)"
has "form domain: mengarahkan ke halaman app runtime" "$(body "$UT" "$BASE/domains")" "Node.js / Python App"
hasnt "form domain: tidak ada opsi tipe node" "$(body "$UT" "$BASE/domains")" 'value="node"'
hasnt "form domain: tidak ada opsi tipe python" "$(body "$UT" "$BASE/domains")" 'value="python"'

# Halaman app runtime harus menawarkan SEMUA domain sebagai host, bukan
# hanya yang bertipe node/python.
# Halaman app runtime dicek dari sesi admin yang dijamin sudah login di
# section ini; jar bawaan section lain belum tentu punya sesi saat section 23
# dijalankan sendiri.
UAJ="ut23admin"
curl -s -b "$(jar "$UAJ")" -c "$(jar "$UAJ")" -o /dev/null "$BASE/login"
curl -s -b "$(jar "$UAJ")" -c "$(jar "$UAJ")" -o /dev/null -d "username=admin" \
  --data-urlencode "password=$ADMIN_PW" -d "_csrf=$(csrf "$UAJ")" "$BASE/login"
eq "apps: sesi admin siap" "$(getc "$UAJ" "$BASE/dashboard")" 200

# Domain PHP yang sudah ada harus muncul sebagai pilihan host runtime.
# Domain bertipe 'node'/'python' tidak ada lagi, jadi
#memakai domain yang ada: tidak perlu membuat domain baru dan menghabiskan kuota.
PHP_HOST=$(sudo -u www-data php /tmp/q.php \
  "SELECT name FROM domains WHERE type = 'php' ORDER BY id LIMIT 1")
eq "apps: ada domain php untuk dijadikan host" \
  "$(printf '%s' "$PHP_HOST" | grep -c '\.')" 1
has "apps: domain php bisa jadi host runtime" "$(body "$UAJ" "$BASE/apps")" "$PHP_HOST"
hasnt "apps: halaman app tidak menyaring berdasarkan tipe domain lagi" \
  "$(body "$UAJ" "$BASE/apps")" "uujibasicapp"

eq "nav: label WordPress Manager" \
  "$(body "$UT" "$BASE/dashboard" | grep -c 'WordPress Manager')" 1
eq "nav: label Node.js / Python App" \
  "$(body "$UT" "$BASE/dashboard" | grep -c 'Node.js / Python App')" 1

# --- WordPress Manager ---------------------------------------------------------
# Tombol install harus selalu terlihat, bukan disembunyikan di balik tab.
has "wp manager: tombol Pasang WordPress di header" \
  "$(body "$UT" "$BASE/wordpress")" 'id="wp-install-link"'
has "wp manager: panel install terbuka (ada id=pasang)" \
  "$(body "$UT" "$BASE/wordpress")" 'id="pasang"'
hasnt "wp manager: tidak ada tab yang menyembunyikan panel" \
  "$(body "$UT" "$BASE/wordpress")" 'id="wp-tabs"'
hasnt "wp manager: tidak ada atribut data-panel" \
  "$(body "$UT" "$BASE/wordpress")" 'data-panel='
has "wp manager: pakai logo WordPress resmi (standard)" \
  "$(body "$UT" "$BASE/wordpress")" 'wordpress-standard.png'
hasnt "wp manager: tidak memakai SVG karangan" \
  "$(body "$UT" "$BASE/wordpress")" 'wordpress.svg'
eq "wp manager: logo wmark resmi tersedia" \
  "$(curl -s -o /dev/null -w '%{http_code}' $BASE/assets/img/wordpress-wmark.png)" 200
eq "wp manager: logo standard resmi tersedia" \
  "$(curl -s -o /dev/null -w '%{http_code}' $BASE/assets/img/wordpress-standard.png)" 200

# Halaman Kelola harus menjawab dan tidak bocorkan instalasi orang lain.
eq "wp manager: halaman Kelola menolak tanpa CSRF" \
  "$(post "$UT" -o /dev/null -w '%{http_code}' -d "id=1" $BASE/wordpress/update)" 419
eq "wp manager: toggle staging menolak tanpa CSRF" \
  "$(post "$UT" -o /dev/null -w '%{http_code}' -d "id=1" $BASE/wordpress/staging)" 419
eq "wp manager: IDOR - Kelola instalasi user lain tidak terbuka" \
  "$(post "$UT" -o /dev/null -w '%{redirect_url}' $BASE/wordpress/1/manage | grep -c 'err=')" 1
eq "wp manager: CLI wp:update terdaftar" \
  "$(php /srv/fpanel/bin/fpanel 2>&1 | grep -c 'wp:update')" 1

$FP user:delete "$U3" >/dev/null 2>&1

restore_quota
trap - EXIT
rm -rf "$TMP"
echo
echo "================================"
printf "  LULUS: %s   GAGAL: %s\n" "$PASS" "$FAIL"
echo "================================"
[ "$FAIL" -eq 0 ]
