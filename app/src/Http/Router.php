<?php
declare(strict_types=1);
namespace Fpanel\Http;

final class Router
{
    /** @var array<int, array{method:string,regex:string,keys:array,handler:callable,middleware:array}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler, array $middleware = []): void
    {
        $keys = [];
        $regex = preg_replace('#\{([a-zA-Z_]+)\}#', '(?P<$1>[^/]+)', $pattern);
        $this->routes[] = [
            'method'     => strtoupper($method),
            'regex'      => '#^' . $regex . '$#',
            'keys'       => $keys,
            'handler'    => $handler,
            'middleware' => $middleware,
        ];
    }

    public function get(string $p, callable $h, array $m = []): void    { $this->add('GET', $p, $h, $m); }
    public function post(string $p, callable $h, array $m = []): void   { $this->add('POST', $p, $h, $m); }
    public function delete(string $p, callable $h, array $m = []): void { $this->add('DELETE', $p, $h, $m); }

    public function dispatch(Request $req): ?Response
    {
        $path   = $req->path();
        $method = $req->method();
        $pathHit = false;

        foreach ($this->routes as $r) {
            if (!preg_match($r['regex'], $path, $m)) {
                continue;
            }
            $pathHit = true;
            if ($r['method'] !== $method) {
                continue;
            }
            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            $result = ($r['handler'])($req, $params, $app = null);
            return $result instanceof Response ? $result : Response::html((string)$result);
        }

        if ($pathHit) {
            return Response::html('<h1>405 Method Not Allowed</h1>', 405);
        }
        return null;
    }
}
