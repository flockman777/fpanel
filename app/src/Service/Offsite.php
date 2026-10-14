<?php
declare(strict_types=1);
namespace Fpanel\Service;

/**
 * Backup offsite: kirim arsip backup ke storage S3-compatible (B2, R2, MinIO,
 * Wasabi, AWS S3).
 *
 * Kredensial dibaca dari /etc/fpanel/offsite.conf yang hanya root yang bisa baca.
 * Credential di luar repo, sama seperti password admin di /root/.fpanel-admin-pw.
 *
 * Arsip backup berisi database user, password MariaDB terenkripsi, private key
 * TLS, dan seed TOTP. Jadi sebelum dikirim, isinya dienkripsi lagi dengan kunci
 * lokal supaya file di sisi remote tidak bisa dibaca orang lain yang punya akses
 * bucket (provider, admin yang salah setelan, siapa pun yang dapat URL).
 */
final class Offsite
{
    private const CONF = '/etc/fpanel/offsite.conf';

    /** Jumlah arsip offsite yang dipertahankan di bucket. */
    public const KEEP = 14;

    /** Pola nama arsip lokal; sama dengan yang dipakai helper backup. */
    private const PAT_LOCAL = '/^fpanel-[0-9]{8}-[0-9]{6}\.tar\.gz$/';

    private function __construct() {}

    // ------------------------------------------------------------------ konfigurasi

    /**
     * Konfigurasi offsite.
     *
     * @return array{configured:bool,endpoint?:string,bucket?:string,region?:string,
     *               key_id?:string,secret_set?:bool,prefix?:string,error?:string}
     */
    public static function config(): array
    {
        if (!is_readable(self::CONF)) {
            return ['configured' => false,
                'error' => 'Belum dikonfigurasi (' . self::CONF . ' belum ada).'];
        }
        $cfg = self::parse((string)@file_get_contents(self::CONF));
        foreach (['endpoint', 'bucket', 'access_key', 'secret_key'] as $k) {
            if (trim((string)($cfg[$k] ?? '')) === '') {
                return ['configured' => false,
                    'error' => "Konfigurasi belum lengkap: variabel $k kosong."];
            }
        }
        return [
            'configured' => true,
            'endpoint'   => (string)$cfg['endpoint'],
            'bucket'     => (string)$cfg['bucket'],
            'region'     => (string)($cfg['region'] ?? 'us-east-1'),
            // Key id ditampilkan seperlunya: bisa dipakai untuk mengenali kunci
            // yang dipakai, tapi bukan rahasia.
            'key_id'     => self::maskKey((string)$cfg['access_key']),
            'secret_set' => true,
            'prefix'     => (string)($cfg['prefix'] ?? 'fpanel'),
        ];
    }

    /** Parse file KEY="value" ala shell, tanpa shell. */
    private static function parse(string $raw): array
    {
        $out = [];
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/', $line, $m)) {
                continue;
            }
            $v = trim($m[2]);
            // Buang tanda kutip luar bila ada.
            if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'")
                && $v[strlen($v) - 1] === $v[0]) {
                $v = substr($v, 1, -1);
            }
            $out[$m[1]] = $v;
        }
        return $out;
    }

    private static function maskKey(string $key): string
    {
        if (strlen($key) <= 8) {
            return str_repeat('*', strlen($key));
        }
        return substr($key, 0, 4) . str_repeat('*', 6) . substr($key, -4);
    }

    private static function client(): ?S3Client
    {
        if (!is_readable(self::CONF)) {
            return null;
        }
        return S3Client::fromConfig(self::parse((string)@file_get_contents(self::CONF)));
    }

    // ------------------------------------------------------------------- status

    /**
     * Ringkasan status offsite untuk UI.
     *
     * @return array{configured:bool,reachable?:bool,remote?:int,latest_remote?:string,
     *               error?:string,warning?:string}
     */
    public static function status(): array
    {
        $why = 'alasan tidak diketahui';
        $cfg = self::config();
        if (!$cfg['configured']) {
            return ['configured' => false, 'error' => (string)$cfg['error']];
        }
        $c = self::client();
        if ($c === null) {
            return ['configured' => false, 'error' => 'Konfigurasi tidak bisa dibaca.'];
        }
        $keys = self::listAllKeys($c, (string)$cfg['prefix'], $why);
        if ($keys === null) {
            return ['configured' => true, 'reachable' => false,
                'error' => 'Bucket tidak bisa dibaca: ' . $why];
        }
        $latest = $keys === [] ? null : (string)max($keys);
        return [
            'configured'    => true,
            'reachable'     => true,
            'remote'        => count($keys),
            'latest_remote' => $latest,
            'error'         => null,
        ];
    }

    /**
     * Kumpulkan semua kunci di bawah satu prefix, menyusuri semua halaman.
     *
     * ListObjectsV2 memotong hasil di 1000 kunci per panggilan, jadi satu
     * permintaan saja akan terlihat "lengkap" padahal bucket sudah jauh lebih
     * dari itu. Parameter $why diisi pesan error kalau gagal.
     *
     * @return array<int,string>|null null kalau gagal
     */
    private static function listAllKeys(S3Client $c, string $prefix, ?string &$why): ?array
    {
        $keys  = [];
        $token = null;
        // Batas 50 halaman (=50.000 kunci). Cukup untuk mencegah loop tak
        //-ending kalau provider selalu mengembalikan token yang sama.
        for ($page = 0; $page < 50; $page++) {
            $extra = $token === null ? [] : ['continuation-token' => $token];
            $r = $c->listObjects($prefix, $extra);
            if (!$r['ok']) {
                $why = self::brief($r);
                return null;
            }
            foreach (self::parseKeys($r['body']) as $k) {
                $keys[] = $k;
            }
            $token = self::nextToken($r['body']);
            if ($token === null) {
                return $keys;
            }
        }
        return $keys;
    }

    /** Ambil continuation token dari balasan ListObjectsV2, kalau ada. */
    private static function nextToken(string $body): ?string
    {
        if (str_starts_with(ltrim($body), '{')) {
            $j = json_decode($body, true);
            $t = $j['NextContinuationToken'] ?? $j['nextContinuationToken'] ?? null;
            return is_string($t) && $t !== '' ? $t : null;
        }
        $prev = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        libxml_use_internal_errors($prev);
        if ($xml === false) {
            return null;
        }
        if (strtolower((string)($xml['IsTruncated'] ?? 'false')) !== 'true') {
            return null;
        }
        $t = (string)($xml->NextContinuationToken ?? '');
        return $t !== '' ? $t : null;
    }

    /** Ambil nama kunci dari XML ListObjects; juga menerima JSON (beberapa provider). */
    private static function parseKeys(string $body): array
    {
        if (str_starts_with(ltrim($body), '{')) {
            $j = json_decode($body, true);
            $out = [];
            foreach ((array)($j['Contents'] ?? $j['contents'] ?? []) as $o) {
                $k = $o['Key'] ?? $o['key'] ?? null;
                if (is_string($k)) {
                    $out[] = $k;
                }
            }
            return $out;
        }
        $prev = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        libxml_use_internal_errors($prev);
        $out = [];
        if ($xml !== false) {
            foreach ($xml->Contents ?? [] as $c) {
                $k = (string)($c->Key ?? '');
                if ($k !== '') {
                    $out[] = $k;
                }
            }
        }
        return $out;
    }

    private static function brief(array $r): string
    {
        $msg = trim((string)($r['error'] ?? $r['body'] ?? ''));
        // Body error S3 panjang sekali (XML); potong supaya pesan UI ringkas.
        $msg = (string)preg_replace('/\s+/', ' ', $msg);
        return mb_substr($msg, 0, 200);
    }

    // -------------------------------------------------------------------- upload

    /**
     * Kirim satu arsip lokal ke bucket, setelah dienkripsi.
     *
     * @return array{ok:bool,error?:string,message?:string,key?:string}
     */
    public static function upload(string $localPath, string $name): array
    {
        if (!preg_match(self::PAT_LOCAL, $name)) {
            return ['ok' => false, 'error' => 'Nama arsip tidak valid.'];
        }
        if (!is_readable($localPath)) {
            return ['ok' => false, 'error' => 'Arsip lokal tidak terbaca.'];
        }
        $cfg = self::config();
        if (!$cfg['configured']) {
            return ['ok' => false, 'error' => (string)$cfg['error']];
        }
        $c = self::client();
        if ($c === null) {
            return ['ok' => false, 'error' => 'Konfigurasi tidak bisa dibaca.'];
        }
        if (!SecretBox::available()) {
            return ['ok' => false,
                'error' => 'Kunci enkripsi tidak tersedia; upload dibatalkan demi keamanan.'];
        }

        $key    = rtrim((string)$cfg['prefix'], '/') . '/' . $name . '.enc';
        $tmp    = null;
        try {
            $enc = self::encryptFile($localPath, $tmp);
            if ($enc === null) {
                return ['ok' => false, 'error' => 'Enkripsi arsip gagal.'];
            }
            $r = $c->put($key, $enc);
            if (!$r['ok']) {
                return ['ok' => false, 'error' => 'Upload ditolak: ' . self::brief($r)];
            }
            return ['ok' => true, 'error' => null,
                'message' => $name . ' terkirim ke ' . (string)$cfg['bucket'] . '/' . $key,
                'key' => $key];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Upload gagal: ' . $e->getMessage()];
        } finally {
            if ($tmp !== null && is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /**
     * Enkripsi arsip ke file sementara.
     *
     * Backup bisa berukuran GB sementara FPM punya RAM terbatas, jadi isinya
     * diproses per blok. Setiap blok dienkripsi sebagai pesan secretbox mandiri
     * dengan nonce acak sendiri:
     *
     *   "FPENC1\n"
     *   berulang: [panjang blok 4 byte big-endian][nonce 24][MAC 16][ciphertext]
     *   akhir    : [panjang blok 0]
     *
 * Setiap blok membawa MAC-nya sendiri, jadi file yang korup di tengah jalan
     * hanya membuat blok itu ditolak, bukan seluruh file. Panjang blok disimpan
     * eksplisit supaya pembaca tahu kapan blok terakhir berakhir.
     */
    private static function encryptFile(string $src, ?string &$tmp): ?string
    {
        $in = @fopen($src, 'rb');
        if ($in === false) {
            return null;
        }
        $tmp = (string)tempnam(sys_get_temp_dir(), 'fp-enc-');
        $out = @fopen($tmp, 'wb');
        if ($out === false) {
            fclose($in);
            @unlink($tmp);
            return null;
        }
        $key = SecretBox::key();
        if ($key === null) {
            fclose($in);
            fclose($out);
            @unlink($tmp);
            return null;
        }

        fwrite($out, "FPENC1\n");
        $buffer = '';
        $blockSize = 1048576;   // 1 MiB per blok
        $failed = false;

        while (!feof($in)) {
            $read = fread($in, $blockSize);
            if ($read === false) {
                break;
            }
            $buffer .= $read;
            if (strlen($buffer) < $blockSize) {
                continue;   // belum penuh, atau sudah EOF
            }
            if (!self::writeBlock($out, $key, $buffer)) {
                $failed = true;
                break;
            }
            $buffer = '';
        }
        // Blok terakhir berisi sisa (< blockSize). Kalau buffer kosong dan
        // file berakhir tepat di batas blok, blok kosong tetap ditulis supaya
        // pembaca tidak mengira file tidak pernah ditulis.
        if (!$failed) {
            if (!self::writeBlock($out, $key, $buffer)) {
                $failed = true;
            }
        }
        if (!$failed) {
            // Penanda akhir: panjang 0.
            fwrite($out, pack('N', 0));
        }
        fclose($in);
        fclose($out);

        if ($failed) {
            @unlink($tmp);
            return null;
        }
        return $tmp;
    }

    /** @return bool true kalau blok berhasil ditulis */
    private static function writeBlock($out, string $key, string $plain): bool
    {
        $nonce   = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $sealed  = sodium_crypto_secretbox($plain, $nonce, $key);   // MAC + ciphertext
        $written = fwrite($out, pack('N', strlen($plain)) . $nonce . $sealed);
        return $written !== false && $written === 4 + strlen($nonce) + strlen($sealed);
    }

    /**
     * Dekripsi file .enc kembali ke arsip biasa (untuk pemulihan).
     *
     * Dipakai CLI/pemulihan, bukan lewat UI: hasilnya berisi seluruh data
     * panel dalam bentuk polos, jadi tidak boleh dipamerkan di halaman web.
     */
    public static function decryptFile(string $encPath, string $dest): bool
    {
        $in = @fopen($encPath, 'rb');
        if ($in === false) {
            return false;
        }
        $magic = (string)fread($in, 7);
        if ($magic !== "FPENC1\n") {
            fclose($in);
            return false;
        }
        $key = SecretBox::key();
        if ($key === null) {
            fclose($in);
            return false;
        }
        $out = @fopen($dest, 'wb');
        if ($out === false) {
            fclose($in);
            return false;
        }
        $head = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES;

        while (true) {
            $lenRaw = fread($in, 4);
            if ($lenRaw === false || strlen($lenRaw) < 4) {
                $ok = false;   // file terpotong di tengah blok
                break;
            }
            $len = (int)unpack('N', $lenRaw)[1];
            if ($len === 0) {
                $ok = true;    // penanda akhir yang benar
                break;
            }
            // Panjang yang tidak masuk akal berarti file rusak atau bukan
            // keluaran Offsite; berhenti sebelum membaca sebesar itu ke RAM.
            if ($len > 67108864) {
                $ok = false;
                break;
            }
            $rest = (string)fread($in, $len + $head);
            if (strlen($rest) !== $len + $head) {
                $ok = false;
                break;
            }
            $nonce   = substr($rest, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $sealed  = substr($rest, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $plain   = sodium_crypto_secretbox_open($sealed, $nonce, $key);
            if ($plain === false) {
                // MAC gagal: file rusak, terpotong, atau bukan miliknya.
                $ok = false;
                break;
            }
            fwrite($out, $plain);
        }

        fclose($in);
        fclose($out);
        if (!$ok) {
            // Buang hasil separuh supaya tidak ada file pemulihan yang menyesatkan.
            @unlink($dest);
            return false;
        }
        return true;
    }

    /** Buat bucket kalau belum ada (opsional, diabaikan bila 4xx). */
    public static function pruneRemote(int $keep = self::KEEP): array
    {
        $cfg = self::config();
        if (!$cfg['configured']) {
            return ['ok' => false, 'error' => (string)$cfg['error']];
        }
        $c = self::client();
        if ($c === null) {
            return ['ok' => false, 'error' => 'Konfigurasi tidak bisa dibaca.'];
        }
        $why = 'alasan tidak diketahui';
        $keys = self::listAllKeys($c, rtrim((string)$cfg['prefix'], '/'), $why);
        if ($keys === null) {
            return ['ok' => false, 'error' => 'Bucket tidak bisa dibaca: ' . $why];
        }
        if (count($keys) <= $keep) {
            return ['ok' => true, 'error' => null, 'message' => 'Tidak ada yang dipangkas.',
                    'removed' => 0];
        }
        sort($keys);                       // nama memuat timestamp, urut = kronologis
        $stale = array_slice($keys, 0, count($keys) - $keep);
        $removed = 0;
        foreach ($stale as $k) {
            if ($c->delete($k)['ok']) {
                $removed++;
            }
        }
        return ['ok' => true, 'error' => null,
            'message' => $removed . ' arsip lama dihapus dari bucket.',
            'removed' => $removed];
    }
}
