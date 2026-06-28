<?php
declare(strict_types=1);

namespace middleware;

use cache\CacheManager;

class OutputCache extends Middleware
{
    protected int $ttl = 3600;

    /** @var string[] */
    protected array $except = ['/admin/*', '/api/*'];

    protected string $prefix = 'output_cache:';

    private CacheManager $cache;

    public function __construct(CacheManager $cache, int $ttl = 3600)
    {
        $this->cache = $cache;
        $this->ttl = $ttl;
    }

    public function setTtl(int $ttl): void
    {
        $this->ttl = $ttl;
    }

    public function setExcept(array $except): void
    {
        $this->except = $except;
    }

    public function handle(\core\Request $request, callable $next): mixed
    {
        if ($request->method() !== 'GET' && $request->method() !== 'HEAD') {
            return $next($request);
        }

        if ($this->shouldSkip()) {
            return $next($request);
        }

        $cacheKey = $this->buildCacheKey($request);

        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $this->buildCachedResponse($cached);
        }

        ob_start();
        $response = null;
        try {
            $response = $next($request);
        } finally {
            $output = ob_get_clean();
        }

        $content = $output !== false ? $output : '';

        if (is_object($response) && method_exists($response, 'getContent')) {
            $content = $response->getContent();
        } elseif (is_string($response)) {
            $content = $response;
        }

        $shouldCache = $content !== '' && $content !== null;
        if ($shouldCache && is_object($response) && method_exists($response, 'getStatusCode')) {
            $code = $response->getStatusCode();
            $shouldCache = $code >= 200 && $code < 400;
        }
        if ($shouldCache) {
            $statusCode = is_object($response) && method_exists($response, 'getStatusCode')
                ? $response->getStatusCode() : 200;
            $responseHeaders = is_object($response) && method_exists($response, 'getHeaders')
                ? $response->getHeaders() : [];
            $this->cache->set($cacheKey, [
                'content'    => $content,
                'status'     => $statusCode,
                'headers'    => $this->collectHeaders($responseHeaders),
                'created_at' => time(),
            ], $this->ttl);
        }

        return $response;
    }

    private function buildCacheKey(\core\Request $request): string
    {
        $path = parse_url($request->uri(), PHP_URL_PATH) ?: '/';
        $params = $request->get();
        if (is_array($params)) {
            ksort($params);
        }
        // 包含会话标识以防止跨用户缓存污染
        $sessionKey = '';
        $sessionName = session_name();
        if ($sessionName !== '' && isset($_COOKIE[$sessionName])) {
            $sessionKey = $_COOKIE[$sessionName];
        }
        return $this->prefix . hash('sha256', $path . '|' . json_encode($params) . '|' . $sessionKey);
    }

    /**
     * @param array<string,string> $responseHeaders Response 对象通过 setHeader() 设置的头
     * @return array<int, array{name: string, value: string}>
     */
    private function collectHeaders(array $responseHeaders = []): array
    {
        $merged = [];
        // Response 对象通过 setHeader() 设置的头（send() 之前尚未通过 header() 发出，
        // headers_list() 无法捕获，需直接读取）
        foreach ($responseHeaders as $name => $value) {
            $merged[$name] = $value;
        }
        // 合并已通过 header() 直接发送的头
        foreach (headers_list() as $header) {
            $parts = explode(':', $header, 2);
            if (count($parts) === 2) {
                $name = trim($parts[0]);
                if (!isset($merged[$name])) {
                    $merged[$name] = trim($parts[1]);
                }
            }
        }
        $headers = [];
        foreach ($merged as $name => $value) {
            $headers[] = ['name' => $name, 'value' => $value];
        }
        return $headers;
    }

    private function buildCachedResponse(array $cached): \core\Response
    {
        $content = $cached['content'] ?? '';
        $statusCode = $cached['status'] ?? 200;
        $response = \core\Response::make($content, $statusCode);

        // 响应头白名单：仅重放安全的响应头
        $safeHeaders = ['Content-Type', 'Content-Language', 'X-Cache'];
        if (!empty($cached['headers'])) {
            foreach ($cached['headers'] as $header) {
                if (in_array($header['name'], $safeHeaders, true)) {
                    $response->header($header['name'], $header['value']);
                }
            }
        }

        $response->header('X-Cache', 'HIT');
        return $response;
    }
}