<?php
declare(strict_types=1);
namespace Fpanel\Http;

final class Request
{
    private array $get, $post, $server, $cookies, $files;
    private ?array $json = null;

    public function __construct(
        array $get, array $post, array $server, array $cookies, array $files
    ) {
        $this->get = $get; $this->post = $post;
        $this->server = $server; $this->cookies = $cookies; $this->files = $files;
    }

    public static function capture(): self
    {
        return new self($_GET, $_POST, $_SERVER, $_COOKIE, $_FILES);
    }

    public function method(): string
    {
        $m = strtoupper($this->server['REQUEST_METHOD'] ?? 'GET');
        if ($m === 'POST' && str_contains($this->header('X-HTTP-Method-Override') ?? '', 'DELETE')) {
            return 'DELETE';
        }
        return $m;
    }

    public function path(): string
    {
        $uri = $this->server['REQUEST_URI'] ?? '/';
        $p   = parse_url($uri, PHP_URL_PATH) ?: '/';
        $p   = '/' . trim($p, '/');
        return $p === '/' ? '/' : rtrim($p, '/');
    }

    public function header(string $name): ?string
    {
        $k = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $v = $this->server[$k] ?? null;
        return $v === null ? null : (string)$v;
    }

    public function ip(): string
    {
        return (string)($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function userAgent(): string
    {
        return substr((string)($this->server['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    public function cookie(string $n): ?string
    {
        $v = $this->cookies[$n] ?? null;
        return $v === null ? null : (string)$v;
    }

    public function query(string $k, mixed $d = null): mixed  { return $this->get[$k] ?? $d; }
    public function post_(string $k, mixed $d = null): mixed   { return $this->post[$k] ?? $d; }
    public function input(string $k, mixed $d = null): mixed
    {
        return $this->post[$k] ?? $this->get[$k] ?? $d;
    }

    public function json(): array
    {
        if ($this->json !== null) {
            return $this->json;
        }
        $raw = file_get_contents('php://input') ?: '';
        $d   = json_decode($raw, true);
        return $this->json = is_array($d) ? $d : [];
    }

    public function wantsJson(): bool
    {
        return str_contains((string)$this->header('Accept'), 'application/json')
            || str_contains((string)$this->header('Content-Type'), 'application/json');
    }
}
