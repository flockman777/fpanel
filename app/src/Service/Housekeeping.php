<?php
declare(strict_types=1);
namespace Fpanel\Service;

use Fpanel\Database\Connection;

/**
 * Pembersihan data yang tumbuh terus: sesi, audit log, percobaan login, dan
 * access log nginx yang domainnya sudah tidak ada.
 *
 * Semua operasi di sini menghapus data, jadi setiap langkah punya batas usia
 * eksplisit. Kalau suatu saat ada bug, yang hilang adalah log lama, bukan akun.
 */
final class Housekeeping
{
    /** Hari minimal sebelum audit log boleh dihapus. */
    public const AUDIT_KEEP_DAYS = 90;

    /** Hari minimal sebelum percobaan login boleh dihapus. */
    public const ATTEMPT_KEEP_DAYS = 7;

    /** Umur minimum file log (hari) sebelum dianggap layak dihapus. */
    public const LOG_MIN_AGE_DAYS = 3;

    private function __construct(private readonly string $logDir) {}

    public static function make(?string $logDir = null): self
    {
        return new self($logDir ?? self::defaultLogDir());
    }

    /**
     * Lokasi access log nginx milik panel.
     *
     * Dicek dari pool FPM karena CLI bisa punya environment berbeda; kalau
     * tidak bisa ditemukan, job ini hanya melewati pembersihan log.
     */
    public static function defaultLogDir(): string
    {
        $candidates = [
            '/var/log/nginx/fpanel',
            '/srv/fpanel/var/log/nginx',
        ];
        foreach ($candidates as $d) {
            if (is_dir($d)) {
                return $d;
            }
        }
        return $candidates[0];
    }

    /**
     * Jalankan seluruh pembersihan.
     *
     * $opt hanya dipakai suite uji untuk memakai batas usia kecil. Nilai
     * kosong di produksi, jadi retensi selalu mengikuti konstanta di atas.
     *
     * @param  array{auditDays?:int,attemptDays?:int} $opt
     * @return array{ok:bool,error?:string,counts?:array<string,int>,warnings?:string[]}
     */
    public function run(bool $dryRun = false, array $opt = []): array
    {
        $counts = [];
        try {
            $counts['sessions']   = $this->pruneSessions($dryRun);
            $counts['audit_log']  = $this->pruneAuditLog($dryRun, $opt);
            $counts['login']      = $this->pruneLoginAttempts($dryRun, $opt);
            // Tabel ini belum ada di server yang belum menjalankan
            // db:migrate-devices, jadi pruneDeviceTable mengecek keberadaannya dulu.
            $counts['login_links'] = $this->pruneDeviceTable('login_links', 'used_at', $dryRun);
            $counts['devices']     = $this->pruneDeviceTable('remember_devices', 'revoked_at', $dryRun);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Pembersihan DB gagal: ' . $e->getMessage()];
        }

        $warnings = [];
        try {
            $counts['nginx_logs'] = $this->pruneOrphanLogs($dryRun);
        } catch (\Throwable $e) {
            // Log bukan hal kritis: kegagalan di sini tidak boleh menggagalkan
            // pembersihan DB yang sudah berhasil.
            $warnings[] = 'Log nginx dilewati: ' . $e->getMessage();
        }

        return ['ok' => true, 'counts' => $counts, 'warnings' => $warnings];
    }

    // ------------------------------------------------------------------ database

    /** Sesi kedaluwarsa, termasuk sesi antar-langkah 2FA yang ditinggalkan. */
    private function pruneSessions(bool $dryRun): int
    {
        if ($dryRun) {
            return (int)Connection::value(
                "SELECT COUNT(*) FROM sessions WHERE expires_at <= datetime('now')"
            );
        }
        return Connection::run("DELETE FROM sessions WHERE expires_at <= datetime('now')")->rowCount();
    }

    /**
     * Audit log dipangkas, tapi baris yang gagal ('denied'/'error') disimpan
     * lebih lama karena itu rekaman insiden keamanan.
     */
    private function pruneAuditLog(bool $dryRun, array $opt = []): int
    {
        $okDays  = (int)($opt['auditDays'] ?? self::AUDIT_KEEP_DAYS);
        $badDays = $okDays * 3;
        $sql = "DELETE FROM audit_log
                WHERE (result = 'ok'  AND created_at < datetime('now', :okWin))
                   OR (result <> 'ok' AND created_at < datetime('now', :badWin))";
        if ($dryRun) {
            return (int)Connection::value(
                "SELECT COUNT(*) FROM audit_log
                  WHERE (result = 'ok'  AND created_at < datetime('now', ?))
                     OR (result <> 'ok' AND created_at < datetime('now', ?))",
                ['-' . $okDays . ' days', '-' . $badDays . ' days']
            );
        }
        return Connection::run($sql, [
            'okWin'  => '-' . $okDays . ' days',
            'badWin' => '-' . $badDays . ' days',
        ])->rowCount();
    }

    private function pruneLoginAttempts(bool $dryRun, array $opt = []): int
    {
        $keep = '-' . (int)($opt['attemptDays'] ?? self::ATTEMPT_KEEP_DAYS) . ' days';
        if ($dryRun) {
            return (int)Connection::value(
                'SELECT COUNT(*) FROM login_attempts WHERE created_at < datetime("now", ?)',
                [$keep]
            );
        }
        return Connection::run(
            'DELETE FROM login_attempts WHERE created_at < datetime("now", ?)',
            [$keep]
        )->rowCount();
    }

    // ---------------------------------------------------------------------- log

    /**
     * Pangkas tabel perangkat / login link.
     *
     * Baris yang sudah dipakai ditandai dengan kolom waktu; kalau kolomnya
     * NULL (mis. token masih aktif), cukup pangkas berdasarkan tanggal
     * kedaluwarsa. Tabel dicek lebih dulu supaya job ini tetap jalan di server
     * yang belum menjalankan db:migrate-devices.
     */
    private function pruneDeviceTable(string $table, string $doneCol, bool $dryRun): int
    {
        $exists = (int)Connection::value(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = :t",
            ['t' => $table]
        );
        if ($exists < 1) {
            return 0;
        }
        $keep = '-' . self::ATTEMPT_KEEP_DAYS . ' days';
        if ($dryRun) {
            return (int)Connection::value(
                "SELECT COUNT(*) FROM $table
                  WHERE expires_at < datetime('now')
                     OR ($doneCol IS NOT NULL AND $doneCol < datetime('now', ?))",
                [$keep]
            );
        }
        return Connection::run(
            "DELETE FROM $table
              WHERE expires_at < datetime('now')
                 OR ($doneCol IS NOT NULL AND $doneCol < datetime('now', :win))",
            ['win' => $keep]
        )->rowCount();
    }

    /**
     * Hapus file log yang domainnya sudah tidak terdaftar.
     *
     * Dua kondisi yang ditangani:
     *   - nama file tidak cocok domain mana pun (domain dihapus tanpa log dibersihkan)
     *   - nama file cocok, tapi lognya sudah lebih tua dari LOG_MIN_AGE_DAYS
     *
     * Umur minimum itu penting: domain yang baru dibuat bisa punya nama yang
     * kebetulan mirip, dan file log aktif tidak boleh dihapus di tengah jalan.
     */
    public function pruneOrphanLogs(bool $dryRun = false): int
    {
        if (!is_dir($this->logDir)) {
            return 0;
        }
        $known = [];
        foreach (Connection::all('SELECT name FROM domains') as $r) {
            $known[strtolower((string)$r['name'])] = true;
        }

        $removed = 0;
        $minMtime = time() - (self::LOG_MIN_AGE_DAYS * 86400);
        $entries  = @scandir($this->logDir);
        if ($entries === false) {
            return 0;
        }

        foreach ($entries as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $this->logDir . '/' . $name;
            if (!is_file($path)) {
                continue;
            }
            $domain = self::domainFromLogName($name);
            if ($domain === null) {
                continue;
            }
            if (isset($known[$domain])) {
                continue;
            }
            $mtime = (int)@filemtime($path);
            if ($mtime > $minMtime) {
                // Terlalu baru untuk dipastikan yatim; coba lagi run berikutnya.
                continue;
            }
            if ($dryRun) {
                $removed++;
                continue;
            }
            if (@unlink($path)) {
                $removed++;
            }
        }
        return $removed;
    }

    /**
     * Ambil nama domain dari nama file log.
     *
     * Pola panel: <domain>.access.log dan <domain>.error.log. Dipisah juga
     * untuk log yang dirotasi ( access.log.1 ). Return null kalau tidak cocok,
     * supaya file asing tidak ikut terhapus.
     */
    public static function domainFromLogName(string $filename): ?string
    {
        $name = $filename;
        // Buang sufiks rotasi: .1, .2, .gz
        $name = (string)preg_replace('/\.(gz|\d+)$/', '', $name);
        foreach (['.access.log', '.error.log', '.access', '.error'] as $suffix) {
            if (str_ends_with($name, $suffix)) {
                $domain = substr($name, 0, -strlen($suffix));
                return self::validDomain($domain);
            }
        }
        return null;
    }

    private static function validDomain(string $d): ?string
    {
        $d = strtolower(trim($d));
        if ($d === '' || strlen($d) > 253) {
            return null;
        }
        // Hostname, bukan path relatif: mencegah "../../etc/passwd" ikut terbaca
        // sebagai nama domain bila ada file aneh di direktori log.
        if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $d)) {
            return null;
        }
        return $d;
    }

    /** Nama file log untuk sebuah domain (dipakai juga saat domain dihapus). */
    public static function logFiles(string $domain): array
    {
        return [$domain . '.access.log', $domain . '.error.log'];
    }

    /**
     * Hapus log milik satu domain yang baru saja dihapus dari panel.
     *
     * Dipanggil dari DomainManager::delete supaya log tidak menggantung sampai
     * run housekeeping berikutnya. Tidak ada syarat usia di sini: domainnya
     * baru saja sengaja dihapus, jadi isinya memang tidak dibutuhkan lagi.
     *
     * @return int jumlah file yang benar-benar terhapus
     */
    public function purgeDomainLogs(string $domain): int
    {
        $domain = self::validDomain($domain);
        if ($domain === null || !is_dir($this->logDir)) {
            return 0;
        }
        $removed = 0;
        foreach (self::logFiles($domain) as $name) {
            $path = $this->logDir . '/' . $name;
            if (is_file($path) && @unlink($path)) {
                $removed++;
            }
        }
        // Log hasil rotasi (access.log.1) juga ikut dibuang.
        foreach ((array)@glob($this->logDir . '/' . $domain . '.{access,error}.log*') as $path) {
            if (is_string($path) && is_file($path) && @unlink($path)) {
                $removed++;
            }
        }
        return $removed;
    }
}
