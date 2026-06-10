<?php
declare(strict_types=1);

namespace middleware;

/**
 * 请求频率限制中间件 - 参考 Laravel Throttle
 *
 * 使用独立的缓存文件存储，避免双重md5导致无法按IP清理的问题。
 */
class Throttle
{
    private int $maxAttempts;
    private int $decaySeconds;
    private string $storagePath;

    public function __construct(int $maxAttempts = 60, int $decaySeconds = 60)
    {
        $this->maxAttempts = $maxAttempts;
        $this->decaySeconds = $decaySeconds;
        $this->storagePath = rtrim(STORAGE_PATH, '/') . '/cache/';
        if (!is_dir($this->storagePath)) {
            mkdir($this->storagePath, 0755, true);
        }
    }

    /**
     * 处理请求
     * 
     * @param \core\Request $request 请求对象
     * @param callable $next 下一个处理者
     * @return mixed 响应
     */
    public function handle(\core\Request $request, callable $next): mixed
    {
        $key = $this->resolveKey($request);

        if ($this->tooManyAttempts($key)) {
            $retryAfter = $this->retryAfter($key);
            $response = \core\Response::json([
                'code' => 429,
                'message' => "Too Many Attempts. Please try again in {$retryAfter} seconds.",
                'data' => ['retry_after' => $retryAfter],
            ], 429);
            $response->header('Retry-After', (string) $retryAfter);
            return $response;
        }

        $this->hit($key);

        return $next($request);
    }

    private function resolveKey(\core\Request $request): string
    {
        $ip = $request->ip();
        $route = (string) parse_url($request->uri(), PHP_URL_PATH);
        $route = $route !== '' ? $route : '/';
        $ipHash = hash('sha256', $ip);
        $routeHash = hash('sha256', $route);
        return 'throttle_' . $ipHash . '_' . $routeHash;
    }

    private function getCacheFile(string $key): string
    {
        return $this->storagePath . $key . '.data';
    }

    private function getAttempts(string $key): int
    {
        $file = $this->getCacheFile($key);
        if (!file_exists($file)) {
            return 0;
        }
        $data = @file_get_contents($file);
        if ($data === false) return 0;
        $decoded = json_decode($data, true);
        if (!is_array($decoded) || !isset($decoded['attempts'], $decoded['expire'])) {
            return 0;
        }
        if ($decoded['expire'] > 0 && $decoded['expire'] < time()) {
            @unlink($file);
            return 0;
        }
        return (int) $decoded['attempts'];
    }

    private function tooManyAttempts(string $key): bool
    {
        return $this->getAttempts($key) >= $this->maxAttempts;
    }

    private function hit(string $key): void
    {
        $file = $this->getCacheFile($key);

        $fp = @fopen($file, 'c+');
        if ($fp === false) {
            error_log("LightPHP Throttle: Failed to open cache file: {$file}");
            return;
        }

        try {
            if (!flock($fp, LOCK_EX)) {
                return;
            }

            $content = stream_get_contents($fp);
            $attempts = 0;
            $expire = $this->decaySeconds > 0 ? time() + $this->decaySeconds : 0;

            if ($content !== false && $content !== '') {
                $decoded = json_decode($content, true);
                if (is_array($decoded) && isset($decoded['attempts'])) {
                    if (!isset($decoded['expire']) || $decoded['expire'] <= 0 || $decoded['expire'] >= time()) {
                        $attempts = (int) $decoded['attempts'];
                    }
                }
            }

            $attempts++;

            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode([
                'attempts' => $attempts,
                'expire'   => $expire,
            ]));
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    private function retryAfter(string $key): int
    {
        $file = $this->getCacheFile($key);
        if (!file_exists($file)) {
            return $this->decaySeconds;
        }
        $data = @file_get_contents($file);
        if ($data === false) return $this->decaySeconds;
        $decoded = json_decode($data, true);
        if (!is_array($decoded) || !isset($decoded['expire'])) {
            return $this->decaySeconds;
        }
        $remaining = $decoded['expire'] - time();
        return $remaining > 0 ? $remaining : $this->decaySeconds;
    }

    /**
     * 清除指定 IP 的所有限流记录
     */
    public static function clear(string $ip): void
    {
        $storagePath = rtrim(STORAGE_PATH, '/') . '/cache/';
        $ipHash = hash('sha256', $ip);
        $prefix = 'throttle_' . $ipHash . '_';
        $files = glob($storagePath . $prefix . '*.data');
        if ($files !== false) {
            foreach ($files as $file) {
                @unlink($file);
            }
        }
    }
}