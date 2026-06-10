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

        // 验证通过后重新生成 token，防止 token 重放攻击
        Session::regenerateToken();

        return $next($request);
    }
}
