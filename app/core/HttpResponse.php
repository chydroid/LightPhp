<?php
declare(strict_types=1);

namespace core;

/**
 * HTTP 响应 - HttpClient::request() 返回值
 */
class HttpResponse
{
    public function __construct(
        private int $status,
        private array $headers,
        private string $body
    ) {
    }

    public function status(): int
    {
        return $this->status;
    }

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function failed(): bool
    {
        return $this->status >= 400;
    }

    /**
     * @return array<string, string|string[]>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $k => $v) {
            if (strcasecmp($k, $name) === 0) {
                return is_array($v) ? ($v[0] ?? null) : $v;
            }
        }
        return null;
    }

    public function body(): string
    {
        return $this->body;
    }

    /**
     * 将响应体解析为 JSON 数组
     *
     * @return mixed
     * @throws \RuntimeException 当 JSON 解析失败时
     */
    public function json(): mixed
    {
        $decoded = json_decode($this->body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Failed to parse response body as JSON: ' . json_last_error_msg());
        }
        return $decoded;
    }
}
