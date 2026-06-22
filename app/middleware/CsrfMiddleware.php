<?php
declare(strict_types=1);

namespace middleware;

use core\Session;
use core\Response;

class CsrfMiddleware extends Middleware
{
    protected array $except = [];

    public function handle(\core\Request $request, callable $next): mixed
    {
        if ($this->shouldSkip()) {
            return $next($request);
        }

        $method = strtoupper($request->method());

        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        $token = $request->post('_token') ?? $request->header('X-CSRF-TOKEN');
        $sessionToken = Session::token();

        if ($token === null || $sessionToken === null || $sessionToken === '' || $token === '' || !hash_equals((string) $sessionToken, (string) $token)) {
            return Response::json(['code' => 419, 'message' => 'CSRF token mismatch'], 419);
        }

        // 验证通过，保持当前会话 token 不变，避免多标签页或连续 AJAX 请求失效
        return $next($request);
    }
}
