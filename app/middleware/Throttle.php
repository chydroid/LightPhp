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
        if ($this->decaySeconds <= 0 || $this->maxAttempts <= 0) {
            return $next($request);
        }

        // 机会式回收：偶尔清理已过期的计数文件，避免每个 (IP, path)
        // 组合永久占用一个 inode（长期运行的服务尤其明显）。
        $this->maybeSweep();

        $key = $this->resolveKey($request);

        if (!$this->attempt($key)) {
            $retryAfter = $this->retryAfter($key);
            $response = \core\Response::json([
                'code' => 429,
                'message' => "Too Many Attempts. Please try again in {$retryAfter} seconds.",
                'data' => ['retry_after' => $retryAfter],
            ], 429);
            $response->header('Retry-After', (string) $retryAfter);
            return $response;
        }

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

    /**
     * 机会式清理已过期的计数文件
     *
     * 每个 (IP, path) 组合对应一个 throttle_*.data 文件。此前窗口过期后
     * 只在内存里重置计数，文件本身永不删除 —— 长期运行的服务会累积
     * 大量僵尸 inode。
     *
     * 这里按概率触发（避免每个请求都扫描目录）并删除已过期文件。
     * 删除在 attempt() 之外进行，不会与计数写入产生锁竞争：
     * attempt() 对已过期键本来就是重置为 1，删掉文件不影响配额语义。
     *
     * @return void
     */
    private function maybeSweep(): void
    {
        // 1/100 概率触发；同一请求内只可能触发一次
        if (random_int(1, 100) !== 1) {
            return;
        }
        // 目录不存在说明还没用过，无需扫描
        if (!is_dir($this->storagePath)) {
            return;
        }

        $files = @glob($this->storagePath . 'throttle_*.data');
        if ($files === false) {
            return;
        }
        $now = time();
        foreach ($files as $file) {
            $raw = @file_get_contents($file);
            if ($raw === false || $raw === '') {
                continue;
            }
            $decoded = json_decode($raw, true);
            // 只回收带有效过期时间且已过期的文件；
            // 损坏/无 expire 的文件保留，避免误删正在使用的计数
            if (!is_array($decoded) || !isset($decoded['expire'])) {
                continue;
            }
            $expire = (int) $decoded['expire'];
            if ($expire > 0 && $expire <= $now) {
                @unlink($file);
            }
        }
    }

    private function getCacheFile(string $key): string
    {
        return $this->storagePath . $key . '.data';
    }

    /**
     * 原子性地检查并递增请求计数（消除 TOCTOU 竞态条件）
     *
     * @param string $key 缓存键
     * @return bool true 表示允许请求，false 表示超限
     */
    private function attempt(string $key): bool
    {
        $file = $this->getCacheFile($key);

        $fp = @fopen($file, 'c+');
        if ($fp === false) {
            error_log("LightPHP Throttle: Failed to open cache file: {$file}");
            return true;
        }

        try {
            if (!flock($fp, LOCK_EX)) {
                return true;
            }

            $content = stream_get_contents($fp);
            $attempts = 0;
            $expire = $this->decaySeconds > 0 ? time() + $this->decaySeconds : 0;

            if ($content !== false && $content !== '') {
                $decoded = json_decode($content, true);
                if (is_array($decoded) && isset($decoded['attempts'])) {
                    // 仅当 expire 为有效未来时间戳时才保留计数；
                    // expire<=0 或缺失视为无效数据，重置计数
                    if (isset($decoded['expire']) && $decoded['expire'] > 0 && $decoded['expire'] > time()) {
                        $attempts = (int) $decoded['attempts'];
                        $expire = $decoded['expire'];
                    }
                }
            }

            if ($attempts >= $this->maxAttempts) {
                return false;
            }

            $attempts++;

            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode([
                'attempts' => $attempts,
                'expire'   => $expire,
            ]));
            return true;
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