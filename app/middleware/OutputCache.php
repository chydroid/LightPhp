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
            $this->sendCachedResponse($cached);
            return '';
        }

        ob_start();

        $response = $next($request);

        $output = ob_get_contents();
        ob_end_flush();

        $content = is_string($response) ? $response : $output;

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
        return $this->prefix . md5($request->uri() . '|' . serialize($request->get()));
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

    private function sendCachedResponse(array $cached): void
    {
        if (!empty($cached['headers'])) {
            foreach ($cached['headers'] as $header) {
                if (
                    stripos($header['name'], 'Set-Cookie') !== false ||
                    stripos($header['name'], 'X-Debug') !== false
                ) {
                    continue;
                }
                header($header['name'] . ': ' . $header['value']);
            }
        }

        header('X-Cache: HIT');
        echo $cached['content'] ?? '';
    }
}