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

        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        $token = $_POST['_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        $sessionToken = Session::token();

        if ($token === null || $sessionToken === '' || !hash_equals($sessionToken, $token)) {
            return Response::json(['code' => 419, 'message' => 'CSRF token mismatch'], 419);
        }

        return $next($request);
    }
}
