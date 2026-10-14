<?php
declare(strict_types=1);
namespace Fpanel\Service;

use Fpanel\Database\Connection;

/**
 * Nilai php.ini per-domain (memory_limit, upload, opcache, ...).
 *
 * Diterapkan lewat fastcgi_param PHP_VALUE di vhost domain, karena itu satu-
 * satunya cara yang benar-benar bekerja: PHP-FPM mengabaikan
 * PHP_INI_SCAN_DIR yang dikirim lewat fastcgi_param, sehingga file .ini
 * per-domain tidak akan pernah terbaca.
 *
 * PHP_VALUE hanya bisa mengubah directive yang berextensi PHP_INI_ALL, jadi
 * daftar di bawah ini tidak bisa diperluas sembarangan. Nilai ikut masuk ke
 * file konfigurasi nginx, jadi setiap directive DAN nilainya divalidasi ketat
 * di sini — tidak boleh ada karakter yang bisa memutus baris konfigurasi.
 */
final class PhpSettings
{
    /**
     * Directive yang boleh diubah per domain.
     *
     * 'type' menentukan cara nilainya divalidasi:
     *   size  -> integer opsional satuan K/M/G
     *   time  -> integer opsional satuan M/S
     *   int   -> integer biasa
     *   bool  -> 0 atau 1
     *   flags -> daftar nama flag yang diizinkan (untuk error_reporting)
     */
    private const SCHEMA = [
        'memory_limit'           => ['type' => 'size',  'default' => '128M'],
        'upload_max_filesize'    => ['type' => 'size',  'default' => '2M'],
        'post_max_size'          => ['type' => 'size',  'default' => '8M'],
        'max_execution_time'     => ['type' => 'time',  'default' => '30'],
        'max_input_time'         => ['type' => 'time',  'default' => '60'],
        'max_input_vars'         => ['type' => 'int',   'default' => '1000'],
        'display_errors'         => ['type' => 'bool',  'default' => '0'],
        'display_startup_errors' => ['type' => 'bool',  'default' => '0'],
        'error_reporting'        => ['type' => 'flags', 'default' => 'E_ALL & ~E_DEPRECATED & ~E_STRICT'],
        'opcache.enable'                  => ['type' => 'bool', 'default' => '1'],
        'opcache.memory_consumption'      => ['type' => 'size', 'default' => '128M'],
        'opcache.max_accelerated_files'   => ['type' => 'int',  'default' => '10000'],
        'opcache.validate_timestamps'     => ['type' => 'bool', 'default' => '1'],
        'opcache.revalidate_freq'         => ['type' => 'int',  'default' => '2'],
    ];

    /** Nilai error_reporting yang boleh dipakai. */
    private const REPORTING_FLAGS = [
        'E_ALL', 'E_ERROR', 'E_WARNING', 'E_NOTICE', 'E_DEPRECATED', 'E_STRICT',
        'E_PARSE', 'E_CORE_ERROR', 'E_CORE_WARNING', 'E_COMPILE_ERROR',
    ];

    /** Batas atas supaya user tidak bisa menghabiskan seluruh RAM server. */
    private const MAX_SIZE_MB = 2048;
    private const MAX_TIME_S  = 3600;

    /** Nilai default server (dari php.ini), dipakai kalau domain tidak mengatur. */
    public static function defaults(): array
    {
        $out = [];
        foreach (self::SCHEMA as $k => $spec) {
            $out[$k] = $spec['default'];
        }
        return $out;
    }

    /**
     * Settings milik satu domain.
     *
     * Karena kolom php_ini belum ada di semua instalasi, hasilnya di-cache di
     * memori per-process dan kolomnya dibuat oleh bin/fpanel db:migrate-ini.
     */
    public static function forDomain(string $username, int $domainId): array
    {
        $row = self::owned($username, $domainId);
        if ($row === null) {
            return [];
        }
        $raw = (string)($row['php_ini'] ?? '');
        if (trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Muat ulang pool FPM sebuah versi PHP.
     *
     * Ini wajib dilakukan setelah vhost ditulis ulang. Tanpa itu, pool FPM
     * menahan nilai PHP_VALUE dari request sebelumnya: begitu override
     * dihapus (mis. saat reset), domain itu masih berjalan dengan nilai lama
     * sampai pool dimuat ulang.
     *
     * Pakai "reload" (graceful), bukan "restart": reload tidak menutup socket
     * listener sehingga tidak ada jendela 502 untuk site lain yang sedang
     * dilayani pool yang sama.
     */
    public static function reloadPool(?string $php): void
    {
        $ver  = preg_match('/^[0-9]+\.[0-9]+$/', (string)$php) === 1 ? (string)$php : '8.3';
        $cmd  = ['sudo', '-n', '/usr/local/sbin/fpanel-php', 'reload', $ver];
        $proc = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (is_resource($proc)) {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    stream_get_contents($pipe);
                    fclose($pipe);
                }
            }
            proc_close($proc);
        }
    }

    /** Gabungan default + override domain, untuk ditampilkan di form. */
    public static function merged(string $username, int $domainId): array
    {
        return array_merge(self::defaults(), self::forDomain($username, $domainId));
    }

    /**
     * Kumpulkan directive dari nama field form.
     *
     * PHP mengganti titik dan spasi dengan underscore pada nama field
     * POST/GET, jadi "ini_opcache.memory_consumption" sebenarnya terkirim
     * sebagai "ini_opcache_memory_consumption". Karena itu nama yang
     * diterima dicocokkan ke SCHEMA lebih dulu: kalau persis ada, dipakai;
     * kalau tidak, coba tiap titik yang mungkin disisipkan sampai cocok.
     *
     * Contoh: "opcache_memory_consumption" -> dicoba apa adanya (tidak ada di
     * SCHEMA), lalu "opcache.memory.consumption" (tidak ada), lalu
     * "opcache.memory_consumption" (ada) -> itu yang dipakai.
     *
     * Directive yang aslinya memakai underscore seperti max_input_vars tetap
     * aman karena dicocokkan persis lebih dulu, sebelum titik dicoba.
     */
    public static function fromForm(array $fields): array
    {
        $out = [];
        foreach ($fields as $raw => $value) {
            if (!is_string($raw) || !is_string($value) || !str_starts_with($raw, 'ini_')) {
                continue;
            }
            $name = substr($raw, 4);
            if ($name === '' || isset($out[$name])) {
                continue;
            }
            if (isset(self::SCHEMA[$name])) {
                $out[$name] = $value;
                continue;
            }
            // Coba tiap kombinasi titik pada underscore (maksimal 4 underscore).
            $parts = explode('_', $name);
            $n     = count($parts);
            if ($n > 5) {
                continue;
            }
            $found = null;
            for ($mask = 1; $mask < (1 << ($n - 1)) && $found === null; $mask++) {
                $cand = $parts[0];
                for ($i = 1; $i < $n; $i++) {
                    $cand .= (($mask >> ($i - 1)) & 1) ? '.' . $parts[$i] : '_' . $parts[$i];
                }
                if (isset(self::SCHEMA[$cand])) {
                    $found = $cand;
                }
            }
            if ($found !== null) {
                $out[$found] = $value;
            }
        }
        return $out;
    }

    /**
     * Simpan settings domain.
     *
     * $input datang dari form: key = nama directive. Directive di luar daftar
     * SCHEMA diabaikan (bukan error) supaya field tak dikenal di form versi
     * lama tidak merusak halaman.
     */
    /**
     * @param bool $reloadPool Muat ulang pool setelah vhost ditulis. Pass false
     *        kalau pemanggil ingin menulis banyak domain sekaligus lalu
     *        memuat ulang pool-nya satu kali di akhir (lihat route scope=all).
     *
     */
    public static function save(string $username, int $domainId, array $input, bool $reloadPool = true): array
    {
        $row = self::owned($username, $domainId);
        if ($row === null) {
            return ['ok' => false, 'error' => 'Domain tidak ditemukan.'];
        }

        $clean = [];
        foreach ($input as $key => $value) {
            if (!isset(self::SCHEMA[$key])) {
                continue;
            }
            $v = self::sanitize((string)$key, (string)$value);
            if ($v === null) {
                return ['ok' => false, 'error' => 'Nilai untuk ' . $key . ' tidak valid.'];
            }
            if ($v !== '') {
                $clean[$key] = $v;
            }
        }

        $json = json_encode($clean, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return ['ok' => false, 'error' => 'Gagal menyimpan pengaturan.'];
        }
        Connection::run('UPDATE domains SET php_ini = :v WHERE id = :i',
            ['v' => $json, 'i' => $domainId]);

        // Terapkan ulang vhost supaya PHP_VALUE ikut berubah.
        $d = self::owned($username, $domainId);
        if ($d !== null) {
            Vhost::deploy((string)$d['name'], $username, (string)$d['docroot'],
                (string)$d['type'], $d['php_version'] === null ? null : (string)$d['php_version'], $clean);

            // Pool dimuat ulang supaya nilai yang barusan dihapus (terutama saat
            // reset) benar-benar berhenti berlaku, bukan tertahan di memori
            // worker FPM.
            if ($reloadPool) {
                self::reloadPool($d['php_version'] === null ? null : (string)$d['php_version']);
            }
        }

        return ['ok' => true, 'error' => null, 'settings' => $clean];
    }

    /**
     * Bangun ulang vhost sebuah domain dengan pengaturan php_ini yang tersimpan.
     *
     * Dipakai setiap kali vhost perlu di-deploy ulang dari luar PhpSettings
     * (mis. setelah sertifikat dicabut), supaya override per-domain tidak
     * ikut hilang.
     */
    public static function apply(string $domain, string $username, array $d): void
    {
        $raw = (string)($d['php_ini'] ?? '');
        $ini = [];
        if (trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $ini = $decoded;
            }
        }
        Vhost::deploy($domain, $username, (string)$d['docroot'], (string)$d['type'],
            ($d['php_version'] ?? null) === null ? null : (string)$d['php_version'], $ini);
    }

    /** Bersihkan semua override domain (kembali ke default server). */
    public static function reset(string $username, int $domainId): array
    {
        return self::save($username, $domainId, []);
    }

    // ------------------------------------------------------------------ internal

    /**
     * Validasi satu nilai. Return null kalau tidak valid.
     *
     * Semua hasil sudah dipastikan hanya berisi karakter yang aman untuk
     * fastcgi_param: huruf, angka, tanda baca putih, dan Operator & ~ | saja.
     */
    private static function sanitize(string $key, string $value): ?string
    {
        $spec  = self::SCHEMA[$key] ?? null;
        if ($spec === null) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        switch ($spec['type']) {
            case 'size':
                if (!preg_match('/^(\d{1,5})([KMGkmg]?)$/', $value, $m)) {
                    return null;
                }
                $n = (int)$m[1];
                $mb = match (strtoupper($m[2])) {
                    'G' => $n * 1024,
                    'M' => $n,
                    'K' => (int)ceil($n / 1024),
                    default => (int)ceil($n / 1048576),
                };
                if ($mb > self::MAX_SIZE_MB || $n === 0) {
                    return null;
                }
                return $n . ($m[2] === '' ? '' : strtoupper($m[2]));

            case 'time':
                if (!preg_match('/^(\d{1,5})([SMsm]?)$/', $value, $m)) {
                    return null;
                }
                $sec = strtoupper($m[2]) === 'M' ? (int)$m[1] * 60 : (int)$m[1];
                if ($sec > self::MAX_TIME_S) {
                    return null;
                }
                return $m[1] . ($m[2] === '' ? '' : strtoupper($m[2]));

            case 'int':
                if (!preg_match('/^\d{1,7}$/', $value)) {
                    return null;
                }
                return $value;

            case 'bool':
                return in_array($value, ['0', '1'], true) ? $value : null;

            case 'flags':
                // Hanya boleh nama flag yang dikenal, dipisah & atau |.
                $parts = preg_split('/[&|]/', $value) ?: [];
                $parts = array_values(array_filter(array_map('trim', $parts), static fn ($v) => $v !== ''));
                if ($parts === []) {
                    return null;
                }
                foreach ($parts as $p) {
                    if (!in_array(strtoupper($p), self::REPORTING_FLAGS, true)) {
                        return null;
                    }
                }
                return implode(' & ', array_map('strtoupper', $parts));
        }
        return null;
    }

    /** Baris domain milik user tersebut, atau null (IDOR guard). */
    private static function owned(string $username, int $domainId): ?array
    {
        $uid = (int)Connection::value('SELECT id FROM users WHERE username = :u', ['u' => $username]);
        if ($uid === 0) {
            return null;
        }
        return Connection::one('SELECT * FROM domains WHERE id = :i AND user_id = :u',
            ['i' => $domainId, 'u' => $uid]);
    }
}