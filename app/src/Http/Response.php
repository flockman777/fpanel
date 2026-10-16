<?php
declare(strict_types=1);
namespace Fpanel\Http;

final class Response
{
    private int $status = 200;
    private array $headers = [];
    private string $body = '';
    private ?string $streamPath = null;
    private mixed $streamHandle = null;

    public static function html(string $b, int $s = 200): self
    {
        $r = new self();
        $r->status = $s; $r->body = $b;
        $r->headers['Content-Type'] = 'text/html; charset=utf-8';
        return $r;
    }

    public static function json(mixed $d, int $s = 200): self
    {
        $r = new self();
        $r->status = $s;
        $r->body = (string)json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $r->headers['Content-Type'] = 'application/json; charset=utf-8';
        return $r;
    }

    public static function text(string $b, int $s = 200): self
    {
        $r = new self();
        $r->status = $s; $r->body = $b;
        $r->headers['Content-Type'] = 'text/plain; charset=utf-8';
        return $r;
    }

    public static function redirect(string $to, int $s = 302): self
    {
        $r = new self();
        $r->status = $s;
        $r->headers['Location'] = $to;
        return $r;
    }

    /**
     * Send a file from disk as a download.
     *
     * The contents are read block by block, not loaded into memory all at
     * once: a 500 MB file must not make PHP-FPM run out of RAM. The filename
     * is sanitized so the Content-Disposition header cannot be injected with
     * CR/LF.
     */
    public static function download(string $path, string $filename, ?string $mime = null): self
    {
        $r = new self();
        $size = @filesize($path);
        if ($size === false) {
            return self::text('File cannot be read.', 404);
        }

        // The header filename may only contain ASCII characters without newline/quotes.
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?? 'download';
        $safe = ltrim($safe, '.') ?: 'download';
        if ($safe === '') {
            $safe = 'download';
        }

        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return self::text('File cannot be read.', 404);
        }
        // Detect the mime from the contents when not provided, not from the
        // extension supplied by attackers (e.g. .php that is really HTML).
        if ($mime === null) {
            $fi = @finfo_open(FILEINFO_MIME_TYPE);
            $mime = $fi !== false ? (@finfo_file($fi, $path) ?: null) : null;
            if ($fi !== false) {
                @finfo_close($fi);
            }
        }
        $r->headers['Content-Type'] = $mime ?? 'application/octet-stream';
        $r->headers['Content-Length'] = (string)$size;
        $r->headers['Content-Disposition'] = 'attachment; filename="' . $safe . '"';
        $r->headers['X-Content-Type-Options'] = 'nosniff';
        $r->headers['Cache-Control'] = 'private, no-store';

        // Store the handle, not the contents, so send() does the streaming.
        $r->streamPath = $path;
        $r->streamHandle = $fh;
        return $r;
    }

    public function withHeader(string $k, string $v): self
    {
        $this->headers[$k] = $v;
        return $this;
    }

    public function status(): int       { return $this->status; }
    public function body(): string      { return $this->body; }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $k => $v) {
                header("$k: $v");
            }
        }
        if ($this->streamPath !== null && is_resource($this->streamHandle)) {
            // 256 KB chunks: big enough for I/O, small enough for RAM
            while (!feof($this->streamHandle)) {
                $buf = fread($this->streamHandle, 262144);
                if ($buf === false) {
                    break;
                }
                echo $buf;
                flush();
            }
            fclose($this->streamHandle);
            return;
        }
        echo $this->body;
    }
}
