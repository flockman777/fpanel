<?php
declare(strict_types=1);
namespace Fpanel\Service;

/**
 * Backup & restore data panel.
 *
 * Arkip dibuat oleh helper root /usr/local/sbin/fpanel-backup (lihat sudoers),
 * berisi snapshot SQLite, /var/www, dump database, konfigurasi nginx, dan
 * sertifikat. Direktori hasil backup 0700 root, jadi PHP (www-data) tidak
 * bisa membaca file-nya langsung — semua operasi lewat helper.
 *
 * Restore TIDAK diekspos lewat UI. Restore menimpa database yang sedang
 * hidup, jadi harus dijalankan dari shell (bin/fpanel atau helper) di mana
 * operator bisa melihat seluruh log-nya dan bisa membatalkan.
 */
final class Backup
{
    private const HELPER = '/usr/local/sbin/fpanel-backup';

    /** Jumlah arsip yang disimpan oleh jadwal harian. */
    public const KEEP = 7;

    /** Pola nama file; sama persis dengan yang dipakai helper. */
    private const PAT_FILE = '/^fpanel-[0-9]{8}-[0-9]{6}\.tar\.gz$/';

    /** Jalur halaman log cron, supaya UI bisa menampilkan hasil run terakhir. */
    private const CRON_LOG = '/var/log/fpanel-backup.log';

    // ------------------------------------------------------------------- daftar

    /**
     * Daftar backup yang tersedia.
     *
     * Nama file divalidasi lagi di sini meski helper juga memvalidasinya:
     * output helper adalah input yang masuk ke HTML.
     */
    public static function listing(): array
    {
        $r = self::helper(['list']);
        if (!$r['ok']) {
            return ['ok' => false, 'error' => $r['error'], 'items' => []];
        }

        $items = [];
        $lines = array_slice(explode("\n", trim($r['out'])), 1);   // lewati header
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $cols = explode("\t", $line);
            if (count($cols) < 3) {
                continue;
            }
            [$name, $created, $size] = [$cols[0], $cols[1], (int)$cols[2]];
            if (!preg_match(self::PAT_FILE, $name)) {
                continue;
            }
            $items[] = [
                'name'       => $name,
                'created_utc'=> $created,
                'size'       => $size,
                'size_human' => self::human($size),
            ];
        }
        // Terbaru dulu.
        usort($items, static fn (array $a, array $b): int => strcmp($b['name'], $a['name']));

        return ['ok' => true, 'error' => null, 'items' => $items,
                'count' => count($items), 'keep' => self::KEEP,
                'last_log' => self::lastLog()];
    }

    /**
     * Nama arsip terbaru yang tersedia, atau '' kalau belum ada backup.
     *
     * Dipakai CLI offsite:upload supaya arsip terakhir bisa dikirim tanpa
     * harus menyebut namanya secara manual.
     */
    public static function latestName(): string
    {
        $r = self::listing();
        if (!$r['ok'] || $r['items'] === []) {
            return '';
        }
        return (string)$r['items'][0]['name'];
    }

    /** Buat satu backup sekarang. */
    public static function create(int $keep = self::KEEP): array
    {
        if ($keep < 1 || $keep > 100) {
            return ['ok' => false, 'error' => 'Jumlah simpanan di luar rentang.'];
        }
        $r = self::helper(['create', (string)$keep]);
        if (!$r['ok']) {
            return ['ok' => false, 'error' => $r['error']];
        }
        // Helper mencetak ringkasan lalu path di baris terakhir.
        $lines  = array_values(array_filter(explode("\n", trim($r['out']))));
        $path   = (string)end($lines);
        $name   = basename($path);
        $message = 'Backup selesai: ' . ($name !== '' ? $name : '(nama tidak terbaca)');
        // Sertakan pesan ringkas dari helper supaya alasannya terlihat di UI.
        $summary = trim(implode(' ', array_slice($lines, 0, -1)));
        if ($summary !== '') {
            $message .= ' — ' . $summary;
        }
        return ['ok' => true, 'error' => null, 'message' => $message, 'name' => $name];
    }

    /** Periksa integritas satu arsip (checksum per bagian + sqlite). */
    public static function verify(string $name): array
    {
        if (!preg_match(self::PAT_FILE, $name)) {
            return ['ok' => false, 'error' => 'Nama backup tidak valid.', 'report' => ''];
        }
        $r = self::helper(['verify', $name]);
        return ['ok' => $r['ok'], 'error' => $r['ok'] ? null : 'Verifikasi gagal.',
                'report' => $r['out'] . ($r['err'] !== '' ? "\n" . $r['err'] : '')];
    }

    /** Hapus satu arsip. */
    public static function delete(string $name): array
    {
        if (!preg_match(self::PAT_FILE, $name)) {
            return ['ok' => false, 'error' => 'Nama backup tidak valid.'];
        }
        $r = self::helper(['delete', $name]);
        return ['ok' => $r['ok'], 'error' => $r['error'],
                'message' => $r['ok'] ? 'Backup ' . $name . ' dihapus.' : null];
    }

    /** Jalankan retensi sesuai kebijakan (juga dijalankan otomatis tiap create). */
    public static function prune(int $keep = self::KEEP): array
    {
        if ($keep < 1 || $keep > 100) {
            return ['ok' => false, 'error' => 'Jumlah simpanan di luar rentang.'];
        }
        $r = self::helper(['prune', (string)$keep]);
        return ['ok' => $r['ok'], 'error' => $r['error'],
                'message' => $r['ok'] ? 'Retensi dijalankan, sisakan ' . $keep . ' arsip terbaru.' : null];
    }

    /** Helper tersedia? */
    public static function available(): bool
    {
        return is_executable(self::HELPER);
    }

    /**
     * Isi log cron terakhir (untuk ditampilkan di UI).
     *
     * Log dibaca tanpa sudo: file log dibuat root dengan group adm dan mode
     * 0640. Kalau tidak terbaca, kembalikan pesan singkat, bukan error fatal.
     */
    public static function lastLog(int $lines = 25): string
    {
        if (!is_readable(self::CRON_LOG)) {
            return '(log belum ada; backup akan berjalan otomatis tiap hari pukul 03:17 UTC)';
        }
        $fh = @fopen(self::CRON_LOG, 'r');
        if ($fh === false) {
            return '(log tidak bisa dibaca oleh www-data)';
        }
        $buf = '';
        while (($line = fgets($fh)) !== false) {
            $buf = $line . $buf;
            if (substr_count($buf, "\n") >= $lines) {
                break;
            }
        }
        fclose($fh);
        $out = trim($buf);
        return $out === '' ? '(log kosong)' : $out;
    }

    // ------------------------------------------------------------------ internal

    private static function helper(array $args): array
    {
        if (!is_executable(self::HELPER)) {
            return ['ok' => false, 'error' => 'Helper backup tidak terpasang di server.',
                    'out' => '', 'err' => ''];
        }
        $pipes = [];
        // Array argumen, tanpa shell: tidak ada interpolasi yang bisa disisipkan.
        $proc = @proc_open(array_merge(['sudo', '-n', self::HELPER], $args),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            return ['ok' => false, 'error' => 'Gagal menjalankan helper backup.', 'out' => '', 'err' => ''];
        }
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        $out = (string)$out;
        $err = (string)$err;
        if ($code !== 0) {
            $msg = trim(preg_replace('/^ERR:\s*/', '', $err) ?: '') ?: trim($out) ?: 'Operasi backup gagal.';
            return ['ok' => false, 'error' => $msg, 'out' => $out, 'err' => $err];
        }
        return ['ok' => true, 'error' => null, 'out' => $out, 'err' => $err];
    }

    private static function human(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $v = (float)$bytes;
        while ($v >= 1024 && $i < count($units) - 1) {
            $v /= 1024;
            $i++;
        }
        return ($i === 0 ? (string)$bytes : number_format($v, 1)) . ' ' . $units[$i];
    }
}