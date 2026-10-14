<?php
declare(strict_types=1);
namespace Fpanel\Service;

/**
 * Klien S3 minimal untuk backup offsite, ditandatangani dengan AWS SigV4.
 *
 * Ditulis tanpa SDK supaya panel tidak menambah dependensi. Cukup operasi yang
 * benar-benar dipakai: list bucket, upload, unduh, dan hapus objek.
 *
 *_md5 wajib diisi (bukan opsional) karena beberapa provider membutuhkannya
 * untuk upload lewat chunked transfer.
 */
final class S3Client
{
    private function __construct(
        private readonly string $endpoint,   // https://host (tanpa path bucket)
        private readonly string $region,
        private readonly string $bucket,
        private readonly string $accessKey,
        private readonly string $secretKey,
        private readonly string $md5Hex,
        private readonly bool $pathStyle,
    ) {}

    /**
     * @param array<string,string> $cfg
     */
    public static function fromConfig(array $cfg): ?self
    {
        foreach (['endpoint', 'bucket', 'access_key', 'secret_key'] as $k) {
            if (trim((string)($cfg[$k] ?? '')) === '') {
                return null;
            }
        }
        $ep = rtrim(trim((string)$cfg['endpoint']), '/');
        if (!preg_match('#^https?://#i', $ep)) {
            return null;
        }
        // md5Hex = sidik jari certificate publik, dikirim sebagai header
        // Content-MD5 base64. Provider yang tidak mewajibkan tetap menerimanya.
        $md5 = trim((string)($cfg['content_md5'] ?? ''));
        return new self(
            $ep,
            trim((string)($cfg['region'] ?? 'us-east-1')) ?: 'us-east-1',
            trim((string)$cfg['bucket']),
            trim((string)$cfg['access_key']),
            trim((string)$cfg['secret_key']),
            preg_match('/^[0-9a-f]{32}$/', $md5) === 1 ? $md5 : '',
            // Default path-style: lebih banyak kompatibel dengan provider non-AWS
            // (B2, MinIO, R2) yang tidak mendukung virtual-host addressing.
            ($cfg['path_style'] ?? '1') === '1',
        );
    }

    // ------------------------------------------------------------------- request

    /**
     * @param  array<string,string> $headers
     * @return array{ok:bool,status:int,body:string,error?:string}
     */
    private function request(string $method, string $key, array $headers = [],
                             ?string $file = null, string $body = '',
                             array $query = []): array
    {
        $key = ltrim($key, '/');
        $host = (string)parse_url($this->endpoint, PHP_URL_HOST);
        $port = parse_url($this->endpoint, PHP_URL_PORT);
        if ($port) {
            $host .= ':' . $port;
        }

        $uri = $this->pathStyle
            ? $this->endpoint . '/' . rawurlencode($this->bucket) . ($key === '' ? '' : '/' . self::encodePath($key))
            : $this->endpoint . '/' . self::encodePath($key);

        // Query string harus ikut di-signature, dan bentuk canonical-nya harus
        // sama persis dengan yang dikirim ke server: diurutkan berdasarkan nama,
        // dengan '=' dan '&' juga di-encode.
        $canonicalQuery = self::canonicalQuery($query);
        if ($canonicalQuery !== '') {
            $uri .= '?' . $canonicalQuery;
        }

        $amzDate = gmdate('Ymd\THis\Z');
        $dateOnly = substr($amzDate, 0, 8);
        $payloadHash = $file !== null
            ? hash_file('sha256', $file)
            : hash('sha256', $body);

        $headers += [
            'host'                 => $host,
            'x-amz-date'           => $amzDate,
            'x-amz-content-sha256' => $payloadHash,
        ];
        if ($this->md5Hex !== '') {
            $headers['content-md5'] = base64_encode(hex2bin($this->md5Hex));
        }

        $signed = $headers;
        ksort($signed);
        $canonicalHeaders = '';
        foreach ($signed as $h => $v) {
            $canonicalHeaders .= strtolower($h) . ':' . trim(preg_replace('/\s+/', ' ', (string)$v)) . "\n";
        }
        $signedHeaders = implode(';', array_map('strtolower', array_keys($signed)));

        $canonicalRequest = implode("\n", [
            $method,
            $this->canonicalUri($uri),
            $canonicalQuery,
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);

        $scope = "$dateOnly/{$this->region}/s3/aws4_request";
        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $amzDate,
            $scope,
            hash('sha256', $canonicalRequest),
        ]);

        $signingKey = hash_hmac('sha256', 'aws4_request',
            hash_hmac('sha256', $this->region,
                hash_hmac('sha256', 's3', hash_hmac('sha256', $dateOnly, $this->secretKey, true), true), true), true);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        $headers['Authorization'] = "AWS4-HMAC-SHA256 Credential={$this->accessKey}/{$scope}, "
            . "SignedHeaders={$signedHeaders}, Signature={$signature}";

        $curlHeaders = [];
        foreach ($headers as $h => $v) {
            if (strtolower($h) === 'host') {
                continue;   // curl mengaturnya sendiri dari URL
            }
            $curlHeaders[] = "$h: $v";
        }

        $ch = curl_init($uri);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $curlHeaders,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => 1800,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if ($file !== null) {
            // Upload dari file: stream, bukan dibaca ke memori. Backup bisa
            // berukuran GB dan FPM punya RAM terbatas.
            curl_setopt($ch, CURLOPT_UPLOAD, true);
            curl_setopt($ch, CURLOPT_INFILE, fopen($file, 'rb'));
            curl_setopt($ch, CURLOPT_INFILESIZE, (int)filesize($file));
        } elseif ($body !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        if ($method === 'HEAD') {
            curl_setopt($ch, CURLOPT_NOBODY, true);
        }

        $res  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($res === false && $err !== '') {
            return ['ok' => false, 'status' => 0, 'body' => '', 'error' => $err];
        }
        // S3 mengembalikan 2xx/3xx sebagai sukses; 404 juga "berhasil" untuk
        // pemeriksaan keberadaan, jadi status dibaca terpisah oleh pemanggil.
        return ['ok' => $code >= 200 && $code < 400, 'status' => $code,
                'body' => (string)$res, 'error' => $code >= 400 ? (string)$res : null];
    }

    private function canonicalUri(string $uri): string
    {
        $p = parse_url($uri);
        $path = $p['path'] ?? '/';
        // Setiap segmen di-encode terpisah; slash tidak boleh ter-encode.
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    /**
     * Bangun canonical query string untuk SigV4.
     *
     * Aturannya: urutkan berdasarkan nama parameter (RFC 3986 byte order),
     * lalu encode setiap kunci DAN nilai memakai percent-encoding. '&' dan '='
     * di dalam nilai ikut ter-encode, kalau tidak signature tidak akan cocok.
     *
     * @param array<string,string|int> $query
     */
    private static function canonicalQuery(array $query): string
    {
        if ($query === []) {
            return '';
        }
        $params = [];
        foreach ($query as $k => $v) {
            $params[rawurlencode((string)$k)] = rawurlencode((string)$v);
        }
        ksort($params, SORT_STRING);
        $parts = [];
        foreach ($params as $k => $v) {
            $parts[] = $k . '=' . $v;
        }
        return implode('&', $parts);
    }

    /** Selisikan segmen dengan %2F dalam gaya path untuk kunci bersarang. */
    private static function encodePath(string $key): string
    {
        $parts = array_map(static fn (string $s): string => str_replace('%2F', '/', rawurlencode($s)),
            explode('/', $key));
        return implode('/', $parts);
    }

    // ------------------------------------------------------------------ operasi

    /**
     * Daftar objek dengan prefix tertentu.
     *
     * Memakai ListObjectsV2 (?list-type=2&prefix=...). Path polos seperti
     * GET /bucket/fpanel/ bukan daftar: S3 membacanya sebagai pengambilan objek
     * dan membalas NoSuchKey kalau key itu memang tidak ada.
     *
     * @param array<string,string|int> $extra parameter tambahan (mis. continuation-token)
     * @return array{ok:bool,status:int,body:string,error?:string}
     */
    public function listObjects(string $prefix = '', array $extra = []): array
    {
        $query = ['list-type' => 2, 'max-keys' => 1000];
        if ($prefix !== '') {
            $query['prefix'] = rtrim($prefix, '/') . '/';
        }
        foreach ($extra as $k => $v) {
            $query[$k] = $v;
        }
        return $this->request('GET', '', [], null, '', $query);
    }

    /** @return array{ok:bool,status:int,body:string,error?:string} */
    public function put(string $key, string $file): array
    {
        return $this->request('PUT', $key, [], $file);
    }

    /** @return array{ok:bool,status:int,body:string,error?:string} */
    public function head(string $key): array
    {
        return $this->request('HEAD', $key);
    }

    /** @return array{ok:bool,status:int,body:string,error?:string} */
    public function delete(string $key): array
    {
        return $this->request('DELETE', $key);
    }
}
