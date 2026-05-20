<?php
declare(strict_types=1);

namespace core;

/**
 * 响应类
 * 
 * 封装 HTTP 响应，提供便捷的响应构建方法。
 * 支持 HTML、JSON、重定向等响应类型，并自动添加安全头。
 */
class Response
{
    /** @var string 响应内容 */
    private string $content = '';

    /** @var int HTTP 状态码 */
    private int $statusCode = 200;

    /** @var array 响应头 */
    private array $headers = [
        'Content-Type' => 'text/html; charset=utf-8'
    ];

    /** @var bool 是否启用安全头 */
    private bool $securityHeadersEnabled = true;

    /**
     * 构造函数
     * 
     * @param string $content 响应内容
     * @param int $statusCode HTTP 状态码
     */
    public function __construct(string $content = '', int $statusCode = 200)
    {
        $this->content = $content;
        $this->statusCode = $statusCode;
    }

    /**
     * 创建响应实例（工厂方法）
     * 
     * @param string $content 响应内容
     * @param int $statusCode HTTP 状态码
     * @return self 响应实例
     */
    public static function make(string $content = '', int $statusCode = 200): self
    {
        return new self($content, $statusCode);
    }

    /**
     * 创建 JSON 响应
     * 
     * @param array $data 数据数组
     * @param int $statusCode HTTP 状态码
     * @return self JSON 响应实例
     */
    public static function json(array $data, int $statusCode = 200): self
    {
        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            $encoded = json_encode(['error' => 'JSON encoding failed'], JSON_UNESCAPED_UNICODE);
        }
        $response = new self($encoded, $statusCode);
        $response->header('Content-Type', 'application/json; charset=utf-8');
        return $response;
    }

    /**
     * 创建重定向响应
     * 
     * @param string $url 重定向地址
     * @param int $statusCode HTTP 状态码（默认 302）
     * @return self 重定向响应实例
     */
    public static function redirect(string $url, int $statusCode = 302): self
    {
        $response = new self('', $statusCode);
        $response->header('Location', $url);
        return $response;
    }

    /**
     * 设置响应头
     * 
     * @param string $name 头名
     * @param string $value 头值
     * @return self
     */
    public function header(string $name, string $value): self
    {
        $name = str_replace(["\r", "\n"], '', $name);
        $value = str_replace(["\r", "\n"], '', $value);
        $this->headers[$name] = $value;
        return $this;
    }

    /**
     * 批量设置响应头
     * 
     * @param array $headers 头数组
     * @return self
     */
    public function withHeaders(array $headers): self
    {
        foreach ($headers as $name => $value) {
            $this->header((string) $name, (string) $value);
        }
        return $this;
    }

    /**
     * 禁用安全头
     * 
     * @return self
     */
    public function withoutSecurityHeaders(): self
    {
        $this->securityHeadersEnabled = false;
        return $this;
    }

    /**
     * 设置 HTTP 状态码
     * 
     * @param int $code 状态码
     * @return self
     */
    public function status(int $code): self
    {
        $this->statusCode = $code;
        return $this;
    }

    /**
     * 发送响应
     * 
     * 发送 HTTP 状态码、响应头和响应内容。
     */
    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->statusCode);

            // 发送自定义响应头
            foreach ($this->headers as $name => $value) {
                header("{$name}: {$value}");
            }

            // 添加安全头
            if ($this->securityHeadersEnabled) {
                $this->addSecurityHeaders();
            }
        }

        echo $this->content;
    }

    /**
     * 添加安全响应头
     * 
     * 添加 X-Content-Type-Options、X-Frame-Options、X-XSS-Protection 等安全头。
     */
    private function addSecurityHeaders(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');

        // 对于 HTML 响应，添加内容安全策略
        $contentType = '';
        foreach ($this->headers as $name => $value) {
            if (strcasecmp($name, 'Content-Type') === 0) {
                $contentType = $value;
                break;
            }
        }
        $isHtmlResponse = stripos($contentType, 'text/html') !== false;
        if ($isHtmlResponse) {
            header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'");
        }
    }

    /**
     * 获取响应内容
     * 
     * @return string 响应内容
     */
    public function getContent(): string
    {
        return $this->content;
    }

    /**
     * 魔术方法 - 转换为字符串
     * 
     * @return string 响应内容
     */
    public function __toString(): string
    {
        return $this->content;
    }

    /**
     * 设置响应内容
     * 
     * @param string $content 响应内容
     * @return self
     */
    public function content(string $content): self
    {
        $this->content = $content;
        return $this;
    }
}
