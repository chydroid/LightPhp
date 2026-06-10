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

        if ($content !== '' && $content !== null) {
            $this->cache->set($cacheKey, [
                'content'    => $content,
                'headers'    => $this->collectHeaders(),
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
        return $this->prefix . md5($path . '|' . json_encode($params));
    }

    /**
     * @return array<int, array{name: string, value: string}>
     */
    private function collectHeaders(): array
    {
        $headers = [];
        foreach (headers_list() as $header) {
            $parts = explode(':', $header, 2);
            if (count($parts) === 2) {
                $headers[] = ['name' => trim($parts[0]), 'value' => trim($parts[1])];
            }
        }
        return $headers;
    }

    private function buildCachedResponse(array $cached): string
    {
        // 响应头白名单：仅重放安全的响应头
        $safeHeaders = ['Content-Type', 'Content-Language', 'X-Cache'];
        if (!empty($cached['headers'])) {
            foreach ($cached['headers'] as $header) {
                if (in_array($header['name'], $safeHeaders, true)) {
                    header($header['name'] . ': ' . $header['value']);
                }
            }
        }

        header('X-Cache: HIT');
        return $cached['content'] ?? '';
    }
}