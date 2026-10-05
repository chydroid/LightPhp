<?php
declare(strict_types=1);

namespace middleware;

abstract class Middleware
{
    protected array $except = [];

    abstract public function handle(\core\Request $request, callable $next): mixed;

    protected function shouldSkip(): bool
    {
        // 必须与 Router::normalizeUri() 保持一致的归一化：
        // 直接 parse_url('//api//user') 会按 authority-form 解析出 host，
        // 得到 /user，导致 $except 白名单被重复斜杠绕过。
        $uri = $this->normalizeUri();

        foreach ($this->except as $pattern) {
            $pattern = rtrim($pattern, '/');
            if ($pattern === '*' || $pattern === $uri) {
                return true;
            }
            if (str_contains($pattern, '*')) {
                // 使用 # 作分隔符，* 替换为 .* 以匹配包括 / 在内的任意字符
                $regex = preg_quote($pattern, '#');
                $regex = str_replace('\\*', '.*', $regex);
                if (preg_match('#^' . $regex . '$#', $uri)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 规范化请求 URI（与 core\Router::normalizeUri 语义一致）
     *
     * 折叠重复斜杠后再 parse_url，避免 `//api//user` 被当成
     * authority-form（host=api、path=/user）而绕过 $except 白名单。
     *
     * @return string 规范化后的路径
     */
    protected function normalizeUri(): string
    {
        $raw = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $collapsed = preg_replace('#/+#', '/', $raw);
        $path = parse_url(is_string($collapsed) ? $collapsed : '/', PHP_URL_PATH);
        $path = is_string($path) ? $path : '/';
        $path = rtrim($path, '/');
        return $path !== '' ? $path : '/';
    }
}