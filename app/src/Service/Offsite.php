<?php
declare(strict_types=1);
namespace Fpanel\Service;

/**
 * Offsite backup: ship backup archives to S3-compatible storage (B2, R2,
 * MinIO, Wasabi, AWS S3).
 *
 * Credentials are read from /etc/fpanel/offsite.conf, readable by root only.
 * Credentials live outside the repo, like the admin password in /root/.fpanel-admin-pw.
 *
 * A backup archive holds user databases, encrypted MariaDB passwords, TLS
 * private keys, and TOTP seeds. So before it is sent, the contents are
 * encrypted again with the local key, so the remote file cannot be read by
 * anyone else with bucket access (provider, a misconfigured admin, anyone who gets the URL).
 */
final class Offsite
{
    private const CONF = '/etc/fpanel/offsite.conf';

    /** Number of offsite archives kept in the bucket. */
    public const KEEP = 14;

    /** Local archive name pattern; the same one used by the backup helper. */
    private const PAT_LOCAL = '/^fpanel-[0-9]{8}-[0-9]{6}\.tar\.gz$/';

    private function __construct() {}

    // ------------------------------------------------------------------ configuration

    /**
     * Offsite configuration.
     *
     * @return array{configured:bool,endpoint?:string,bucket?:string,region?:string,
     *               key_id?:string,secret_set?:bool,prefix?:string,error?:string}
     */
    public static function config(): array
    {
        if (!is_readable(self::CONF)) {
            return ['configured' => false,
                'error' => 'Not configured (' . self::CONF . ' does not exist yet).'];
        }
        $cfg = self::parse((string)@file_get_contents(self::CONF));
        foreach (['endpoint', 'bucket', 'access_key', 'secret_key'] as $k) {
            if (trim((string)($cfg[$k] ?? '')) === '') {
                return ['configured' => false,
                    'error' => "Configuration is incomplete: variable $k is empty."];
            }
        }
        return [
            'configured' => true,
            'endpoint'   => (string)$cfg['endpoint'],
            'bucket'     => (string)$cfg['bucket'],
            'region'     => (string)($cfg['region'] ?? 'us-east-1'),
            // The key id is shown only as needed: it identifies which key is
            // in use, but it is not a secret.
            'key_id'     => self::maskKey((string)$cfg['access_key']),
            'secret_set' => true,
            'prefix'     => (string)($cfg['prefix'] ?? 'fpanel'),
        ];
    }

    /** Parse a shell-style KEY="value" file, without a shell. */
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
            // Strip outer quotes when present.
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
     * Offsite status summary for the UI.
     *
     * @return array{configured:bool,reachable?:bool,remote?:int,latest_remote?:string,
     *               error?:string,warning?:string}
     */
    public static function status(): array
    {
        $why = 'reason unknown';
        $cfg = self::config();
        if (!$cfg['configured']) {
            return ['configured' => false, 'error' => (string)$cfg['error']];
        }
        $c = self::client();
        if ($c === null) {
            return ['configured' => false, 'error' => 'The configuration cannot be read.'];
        }
        $keys = self::listAllKeys($c, (string)$cfg['prefix'], $why);
        if ($keys === null) {
            return ['configured' => true, 'reachable' => false,
                'error' => 'The bucket cannot be read: ' . $why];
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
     * Collect every key under one prefix, walking all pages.
     *
     * ListObjectsV2 truncates at 1000 keys per call, so a single request
     * would look "complete" while the bucket holds far more. The $why
     * parameter receives an error message on failure.
     *
     * @return array<int,string>|null null on failure
     */
    private static function listAllKeys(S3Client $c, string $prefix, ?string &$why): ?array
    {
        $keys  = [];
        $token = null;
        // A 50-page limit (=50,000 keys). Enough to prevent an endless loop
        // when a provider keeps returning the same token.
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

    /** Take the continuation token from a ListObjectsV2 reply, when present. */
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

    /** Take key names from the ListObjects XML; also accepts JSON (some providers). */
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
        // S3 error bodies are very long (XML); truncate so the UI message stays short.
        $msg = (string)preg_replace('/\s+/', ' ', $msg);
        return mb_substr($msg, 0, 200);
    }

    // -------------------------------------------------------------------- upload

    /**
     * Send one local archive to the bucket, after encrypting it.
     *
     * @return array{ok:bool,error?:string,message?:string,key?:string}
     */
    public static function upload(string $localPath, string $name): array
    {
        if (!preg_match(self::PAT_LOCAL, $name)) {
            return ['ok' => false, 'error' => 'Invalid archive name.'];
        }
        if (!is_readable($localPath)) {
            return ['ok' => false, 'error' => 'The local archive is not readable.'];
        }
        $cfg = self::config();
        if (!$cfg['configured']) {
            return ['ok' => false, 'error' => (string)$cfg['error']];
        }
        $c = self::client();
        if ($c === null) {
            return ['ok' => false, 'error' => 'The configuration cannot be read.'];
        }
        if (!SecretBox::available()) {
            return ['ok' => false,
                'error' => 'The encryption key is unavailable; the upload is cancelled for safety.'];
        }

        $key    = rtrim((string)$cfg['prefix'], '/') . '/' . $name . '.enc';
        $tmp    = null;
        try {
            $enc = self::encryptFile($localPath, $tmp);
            if ($enc === null) {
                return ['ok' => false, 'error' => 'Failed to encrypt the archive.'];
            }
            $r = $c->put($key, $enc);
            if (!$r['ok']) {
                return ['ok' => false, 'error' => 'Upload rejected: ' . self::brief($r)];
            }
            return ['ok' => true, 'error' => null,
                'message' => $name . ' sent to ' . (string)$cfg['bucket'] . '/' . $key,
                'key' => $key];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Upload failed: ' . $e->getMessage()];
        } finally {
            if ($tmp !== null && is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /**
     * Encrypt the archive to a temporary file.
     *
     * Backups can be gigabytes while FPM has limited RAM, so the contents are
     * processed block by block. Each block is encrypted as a standalone
     * secretbox message with its own random nonce:
     *
     *   "FPENC1\n"
     *   repeated: [block length 4-byte big-endian][nonce 24][MAC 16][ciphertext]
     *   end     : [block length 0]
     *
 * Each block carries its own MAC, so a file corrupted midway only makes
     * that block fail, not the whole file. The block length is stored
     * explicitly so the reader knows when the last block ends.
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
        $blockSize = 1048576;   // 1 MiB per block
        $failed = false;

        while (!feof($in)) {
            $read = fread($in, $blockSize);
            if ($read === false) {
                break;
            }
            $buffer .= $read;
            if (strlen($buffer) < $blockSize) {
                continue;   // not full yet, or already EOF
            }
            if (!self::writeBlock($out, $key, $buffer)) {
                $failed = true;
                break;
            }
            $buffer = '';
        }
        // The last block holds the remainder (< blockSize). When the buffer is
        // empty and the file ends exactly on a block boundary, an empty block
        // is still written so the reader does not think the file was never written.
        if (!$failed) {
            if (!self::writeBlock($out, $key, $buffer)) {
                $failed = true;
            }
        }
        if (!$failed) {
            // End marker: length 0.
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

    /** @return bool true when the block was written */
    private static function writeBlock($out, string $key, string $plain): bool
    {
        $nonce   = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $sealed  = sodium_crypto_secretbox($plain, $nonce, $key);   // MAC + ciphertext
        $written = fwrite($out, pack('N', strlen($plain)) . $nonce . $sealed);
        return $written !== false && $written === 4 + strlen($nonce) + strlen($sealed);
    }

    /**
     * Decrypt a .enc file back into a plain archive (for recovery).
     *
     * Used by the CLI/recovery, not through the UI: the result holds the whole
     * panel data in plain form, so it must never be shown on a web page.
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
                $ok = false;   // file truncated mid-block
                break;
            }
            $len = (int)unpack('N', $lenRaw)[1];
            if ($len === 0) {
                $ok = true;    // correct end marker
                break;
            }
            // An absurd length means the file is corrupt or not an Offsite
            // output; stop before reading that much into RAM.
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
                // MAC failed: the file is corrupt, truncated, or not ours.
                $ok = false;
                break;
            }
            fwrite($out, $plain);
        }

        fclose($in);
        fclose($out);
        if (!$ok) {
            // Drop the partial result so no misleading recovery file remains.
            @unlink($dest);
            return false;
        }
        return true;
    }

    /** Create the bucket when missing (optional, ignored on 4xx). */
    public static function pruneRemote(int $keep = self::KEEP): array
    {
        $cfg = self::config();
        if (!$cfg['configured']) {
            return ['ok' => false, 'error' => (string)$cfg['error']];
        }
        $c = self::client();
        if ($c === null) {
            return ['ok' => false, 'error' => 'The configuration cannot be read.'];
        }
        $why = 'reason unknown';
        $keys = self::listAllKeys($c, rtrim((string)$cfg['prefix'], '/'), $why);
        if ($keys === null) {
            return ['ok' => false, 'error' => 'The bucket cannot be read: ' . $why];
        }
        if (count($keys) <= $keep) {
            return ['ok' => true, 'error' => null, 'message' => 'Nothing to prune.',
                    'removed' => 0];
        }
        sort($keys);                       // names contain a timestamp, so sorting = chronological
        $stale = array_slice($keys, 0, count($keys) - $keep);
        $removed = 0;
        foreach ($stale as $k) {
            if ($c->delete($k)['ok']) {
                $removed++;
            }
        }
        return ['ok' => true, 'error' => null,
            'message' => $removed . ' old archives deleted from the bucket.',
            'removed' => $removed];
    }
}
