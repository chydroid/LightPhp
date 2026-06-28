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
        $uri = rtrim($uri, '/');
        $uri = $uri !== '' ? $uri : '/';

        foreach ($this->except as $pattern) {
            $pattern = rtrim($pattern, '/');
            if ($pattern === '*' || $pattern === $uri) {
                return true;
            }
            if (str_contains($pattern, '*')) {
                // 使用 # 作分隔符，避免替换值 [^/]* 中的 / 被误当作正则结束分隔符
                $regex = preg_quote($pattern, '#');
                $regex = str_replace('\\*', '[^/]*', $regex);
                if (preg_match('#^' . $regex . '$#', $uri)) {
                    return true;
                }
            }
        }

        return false;
    }
}