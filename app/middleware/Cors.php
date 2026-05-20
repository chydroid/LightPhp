<?php
declare(strict_types=1);

namespace middleware;

/**
 * CORS 中间件 - 处理跨域请求
 */
class Cors
{
    private array $config;

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? [
            'allowed_origins' => ['*'],
            'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'],
            'allowed_headers' => ['Content-Type', 'Authorization', 'X-Requested-With', 'X-CSRF-TOKEN'],
            'exposed_headers' => [],
            'max_age' => 86400,
            'supports_credentials' => false,
        ];
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
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $origin = $this->sanitizeOrigin($origin);

        if (in_array('*', $this->config['allowed_origins'], true)) {
            header('Access-Control-Allow-Origin: *');
        } elseif ($this->isOriginAllowed($origin)) {
            header("Access-Control-Allow-Origin: {$origin}");
        }

        if ($this->config['supports_credentials']) {
            header('Access-Control-Allow-Credentials: true');
        }

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method === 'OPTIONS') {
            header('Access-Control-Allow-Methods: ' . implode(', ', $this->config['allowed_methods']));
            header('Access-Control-Allow-Headers: ' . implode(', ', $this->config['allowed_headers']));
            header("Access-Control-Max-Age: {$this->config['max_age']}");

            if (!empty($this->config['exposed_headers'])) {
                header('Access-Control-Expose-Headers: ' . implode(', ', $this->config['exposed_headers']));
            }

            http_response_code(204);
            exit;
        }

        if (!empty($this->config['exposed_headers'])) {
            header('Access-Control-Expose-Headers: ' . implode(', ', $this->config['exposed_headers']));
        }

        return $next($request);
    }

    private function isOriginAllowed(string $origin): bool
    {
        if (empty($origin)) {
            return false;
        }

        $allowed = $this->config['allowed_origins'];

        if (in_array('*', $allowed, true)) {
            return true;
        }

        return in_array($origin, $allowed, true);
    }

    private function sanitizeOrigin(string $origin): string
    {
        $origin = str_replace(["\r", "\n", "\0"], '', $origin);
        if ($origin !== '' && !preg_match('#^https?://[^\s/]+(:\d+)?$#', $origin)) {
            return '';
        }
        return $origin;
    }
}