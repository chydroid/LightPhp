<?php
declare(strict_types=1);

namespace middleware;

abstract class Middleware
{
    protected array $except = [];

    abstract public function handle(\core\Request $request, callable $next): mixed;

    protected function shouldSkip(): bool
    {
        $uri = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $uri = $uri !== '' ? $uri : '/';

        foreach ($this->except as $pattern) {
            $pattern = rtrim($pattern, '/');
            if ($pattern === '*' || $pattern === $uri) {
                return true;
            }
            if (str_contains($pattern, '*')) {
                $regex = preg_quote($pattern, '/');
                $regex = str_replace('\\*', '[^/]*', $regex);
                if (preg_match('/^' . $regex . '$/', $uri)) {
                    return true;
                }
            }
        }

        return false;
    }
}