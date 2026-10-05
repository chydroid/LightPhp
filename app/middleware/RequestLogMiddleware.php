<?php
declare(strict_types=1);

namespace middleware;

use log\Logger;

class RequestLogMiddleware extends Middleware
{
    protected array $except = [];

    public function handle(\core\Request $request, callable $next): mixed
    {
        if ($this->shouldSkip()) {
            return $next($request);
        }

        $startTime = microtime(true);
        $method = $request->method();
        // 必须用 path() 而非 uri()：uri() 含 query string，
        // 会把 ?token=...&password=... 明文写进日志。
        $uri = $request->path();

        $result = null;
        try {
            $result = $next($request);
        } finally {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            // 从 Response 对象提取状态码，而非依赖 http_response_code()，
            // 因为 Response::send() 尚未执行，http_response_code() 始终返回默认值 200
            $statusCode = 200;
            if (is_object($result) && method_exists($result, 'getStatusCode')) {
                $statusCode = $result->getStatusCode();
            } else {
                $statusCode = http_response_code() ?: 200;
            }
            $ip = $request->ip();

            $message = sprintf(
                '%s %s → %d [%sms] [%s]',
                $method,
                $uri,
                $statusCode,
                $duration,
                $ip
            );

            $logger = $this->resolveLogger();
            if ($logger !== null) {
                $logger->info($message, [
                    'method' => $method,
                    'uri' => $uri,
                    'status' => $statusCode,
                    'duration_ms' => $duration,
                    'ip' => $ip,
                    'user_agent' => $request->userAgent(),
                ]);
            }
        }

        return $result;
    }

    /**
     * 解析日志记录器
     *
     * 返回类型此前硬编码 `?Logger` 且方法为 private，导致：
     *  1. 容器绑定 PSR-3 风格 logger（非 log\Logger 子类）时抛 TypeError，
     *  2. 该 TypeError 被下面的空 catch 吞掉 —— 表现为「日志静默消失」，极难排查。
     *
     * 现改为只要求对象具备 info() 方法（PSR-3 最小契约），
     * 并把失败原因记入 error_log 而非无声丢弃。
     *
     * @return object|null 具备 info() 方法的对象，或 null
     */
protected function resolveLogger(): ?object
    {
        try {
            $container = \core\Container::getInstance();
            if ($container === null || !$container->has('log')) {
                return null;
            }
            $logger = $container->get('log');
            if (is_object($logger) && method_exists($logger, 'info')) {
                return $logger;
            }
            error_log(
                'RequestLogMiddleware: container binding "log" must be an object with an info() method, got '
                . get_debug_type($logger)
            );
            return null;
        } catch (\Throwable $e) {
            error_log('RequestLogMiddleware: resolveLogger failed - ' . $e->getMessage());
            return null;
        }
    }
}