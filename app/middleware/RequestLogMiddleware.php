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
        $uri = $request->uri();

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

    private function resolveLogger(): ?Logger
    {
        try {
            $container = \core\Container::getInstance();
            if ($container !== null && $container->has('log')) {
                return $container->get('log');
            }
        } catch (\Throwable $e) {
        }
        return null;
    }
}