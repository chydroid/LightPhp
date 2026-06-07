<?php
declare(strict_types=1);

namespace core;

use core\traits\Macroable;

/**
 * 请求类
 * 
 * 封装 HTTP 请求数据，提供便捷的访问方法。
 * 支持 GET、POST、JSON 请求数据的获取，以及文件上传处理。
 * 通过 Macroable trait 支持运行时动态扩展方法。
 */
class Request
{
    use Macroable;
    /** @var array GET 参数 */
    private array $get;

    /** @var array POST 参数 */
    private array $post;

    /** @var array SERVER 变量 */
    private array $server;

    /** @var array 请求头 */
    private array $headers;

    /** @var string 原始请求体内容 */
    private string $rawContent;

    /** @var array|null 解析后的 JSON 数据 */
    private ?array $json = null;

    /** @var array 文件上传数据 */
    private array $files;

    /**
     * 构造函数 - 初始化请求数据
     */
    public function __construct()
    {
        $this->get = $_GET;
        $this->post = $_POST;
        $this->server = $_SERVER;
        $this->headers = $this->parseHeaders();
        $this->rawContent = file_get_contents('php://input');
        $this->files = $_FILES;
        $this->parseJson();
    }

    /**
     * 解析请求头
     * 
     * 从 $_SERVER 中提取 HTTP_* 开头的变量作为请求头。
     * 
     * @return array 请求头数组
     */
    private function parseHeaders(): array
    {
        $headers = [];
        foreach ($this->server as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $header = strtoupper(substr($key, 5));
                $headers[$header] = $value;
            }
        }

        // 添加 Content-Type 和 Content-Length
        if (isset($this->server['CONTENT_TYPE'])) {
            $headers['CONTENT_TYPE'] = $this->server['CONTENT_TYPE'];
        }

        if (isset($this->server['CONTENT_LENGTH'])) {
            $headers['CONTENT_LENGTH'] = $this->server['CONTENT_LENGTH'];
        }

        return $headers;
    }

    /**
     * 解析 JSON 请求体
     * 
     * 如果请求的 Content-Type 是 application/json，则解析请求体为数组。
     */
    private function parseJson(): void
    {
        $contentType = $this->header('Content-Type', '');
        if (str_contains($contentType, 'application/json') && !empty($this->rawContent)) {
            $decoded = json_decode($this->rawContent, true);
            if (is_array($decoded)) {
                $this->json = $decoded;
            }
        }
    }

    /**
     * 获取 GET 参数
     * 
     * @param string|null $key 参数名，为 null 时返回所有 GET 参数
     * @param mixed $default 默认值
     * @return mixed 参数值
     */
    public function get(?string $key = null, $default = null)
    {
        if ($key === null) {
            return $this->get;
        }
        return $this->get[$key] ?? $default;
    }

    /**
     * 获取 POST 参数（优先 JSON）
     * 
     * @param string|null $key 参数名，为 null 时返回所有 POST 参数
     * @param mixed $default 默认值
     * @return mixed 参数值
     */
    public function post(?string $key = null, $default = null)
    {
        if ($key === null) {
            return $this->json ?? $this->post;
        }
        return $this->json[$key] ?? $this->post[$key] ?? $default;
    }

    /**
     * 获取输入参数（POST > JSON > GET）
     * 
     * @param string $key 参数名
     * @param mixed $default 默认值
     * @return mixed 参数值
     */
    public function input(string $key, $default = null)
    {
        if (isset($this->post[$key])) {
            return $this->post[$key];
        }
        if (isset($this->json[$key])) {
            return $this->json[$key];
        }
        if (isset($this->get[$key])) {
            return $this->get[$key];
        }
        return $default;
    }

    /**
     * 检查参数是否存在
     * 
     * @param string $key 参数名
     * @return bool 是否存在
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->post)
            || array_key_exists($key, $this->get)
            || array_key_exists($key, $this->json ?? []);
    }

    /**
     * 获取 HTTP 方法
     * 
     * @return string HTTP 方法名
     */
    public function method(): string
    {
        return $this->server['REQUEST_METHOD'] ?? 'GET';
    }

    /**
     * 获取请求 URI
     * 
     * @return string 请求路径
     */
    public function uri(): string
    {
        return $this->server['REQUEST_URI'] ?? '/';
    }

    /**
     * 获取请求头
     * 
     * @param string $key 头名
     * @param mixed $default 默认值
     * @return string|null 头值
     */
    public function header(string $key, $default = null): ?string
    {
        $key = strtoupper(str_replace('-', '_', $key));
        return $this->headers[$key] ?? $default;
    }

    /**
     * 获取所有请求头
     * 
     * @return array 请求头数组
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * 判断是否为 AJAX 请求
     * 
     * @return bool 是否为 AJAX
     */
    public function isAjax(): bool
    {
        return strtoupper($this->server['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHTTPREQUEST';
    }

    /**
     * 判断是否为 GET 请求
     * 
     * @return bool 是否为 GET
     */
    public function isGet(): bool
    {
        return $this->method() === 'GET';
    }

    /**
     * 判断是否为 POST 请求
     * 
     * @return bool 是否为 POST
     */
    public function isPost(): bool
    {
        return $this->method() === 'POST';
    }

    /**
     * 判断是否为 PUT 请求
     * 
     * @return bool 是否为 PUT
     */
    public function isPut(): bool
    {
        return $this->method() === 'PUT';
    }

    /**
     * 判断是否为 DELETE 请求
     * 
     * @return bool 是否为 DELETE
     */
    public function isDelete(): bool
    {
        return $this->method() === 'DELETE';
    }

    /**
     * 判断是否为 JSON 请求
     * 
     * @return bool 是否包含 JSON 数据
     */
    public function isJson(): bool
    {
        return $this->json !== null;
    }

    /**
     * 获取所有输入参数
     * 
     * @return array 所有参数的合并数组
     */
    public function all(): array
    {
        if ($this->json !== null) {
            return array_merge($this->get, $this->json);
        }
        return array_merge($this->get, $this->post);
    }

    /**
     * 只获取指定的参数
     * 
     * @param array $keys 参数名列表
     * @return array 指定参数的数组
     */
    public function only(array $keys): array
    {
        $data = [];
        $all = $this->all();
        foreach ($keys as $key) {
            if (array_key_exists($key, $all)) {
                $data[$key] = $all[$key];
            }
        }
        return $data;
    }

    /**
     * 获取除指定参数外的所有参数
     * 
     * @param array $keys 排除的参数名列表
     * @return array 排除后的参数数组
     */
    public function except(array $keys): array
    {
        $data = $this->all();
        foreach ($keys as $key) {
            unset($data[$key]);
        }
        return $data;
    }

    /**
     * 获取原始请求体内容
     * 
     * @return string 原始请求体
     */
    public function raw(): string
    {
        return $this->rawContent;
    }

    /**
     * 获取客户端 IP 地址
     * 
     * @return string IP 地址
     */
    public function ip(): string
    {
        if (!empty($this->server['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $this->server['HTTP_X_FORWARDED_FOR']);
            $ip = trim($ips[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
        if (!empty($this->server['HTTP_X_REAL_IP'])) {
            $ip = trim($this->server['HTTP_X_REAL_IP']);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
        return $this->server['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    /**
     * 获取 User-Agent
     * 
     * @return string User-Agent 字符串
     */
    public function userAgent(): string
    {
        return $this->server['HTTP_USER_AGENT'] ?? '';
    }

    /**
     * 获取上传文件
     * 
     * @param string|null $key 文件字段名，为 null 时返回第一个文件
     * @return Upload|null 上传文件对象
     */
    public function file(?string $key = null): ?Upload
    {
        if ($key === null) {
            return !empty($this->files) ? Upload::file(array_key_first($this->files)) : null;
        }
        return Upload::file($key);
    }

    /**
     * 检查是否有上传文件
     * 
     * @param string $key 文件字段名
     * @return bool 是否存在上传文件
     */
    public function hasFile(string $key): bool
    {
        return isset($this->files[$key]) && $this->files[$key]['error'] !== UPLOAD_ERR_NO_FILE;
    }

    /**
     * 获取请求协议（http/https）
     * 
     * @return string 协议名
     */
    public function scheme(): string
    {
        return (!empty($this->server['HTTPS']) && $this->server['HTTPS'] !== 'off') ? 'https' : 'http';
    }

    /**
     * 获取主机名
     * 
     * @return string 主机名
     */
    public function host(): string
    {
        return $this->server['HTTP_HOST'] ?? 'localhost';
    }

    /**
     * 获取端口号
     * 
     * @return int 端口号
     */
    public function port(): int
    {
        return (int) ($this->server['SERVER_PORT'] ?? 80);
    }

    /**
     * 获取完整 URL
     * 
     * @return string 完整 URL
     */
    public function url(): string
    {
        $url = $this->scheme() . '://' . $this->host();
        $port = $this->port();
        if (($this->scheme() === 'http' && $port !== 80) || ($this->scheme() === 'https' && $port !== 443)) {
            $url .= ':' . $port;
        }
        return $url . $this->uri();
    }

    /**
     * 获取输入值并转为字符串
     * 
     * @param string $key 参数名
     * @param string $default 默认值
     * @return string 字符串值
     */
    public function string(string $key, string $default = ''): string
    {
        return (string) $this->input($key, $default);
    }

    /**
     * 获取输入值并转为整数
     * 
     * @param string $key 参数名
     * @param int $default 默认值
     * @return int 整数值
     */
    public function integer(string $key, int $default = 0): int
    {
        return (int) $this->input($key, $default);
    }

    /**
     * 获取输入值并转为浮点数
     * 
     * @param string $key 参数名
     * @param float $default 默认值
     * @return float 浮点数值
     */
    public function float(string $key, float $default = 0.0): float
    {
        return (float) $this->input($key, $default);
    }

    /**
     * 获取输入值并转为布尔值
     * 
     * @param string $key 参数名
     * @param bool $default 默认值
     * @return bool 布尔值
     */
    public function boolean(string $key, bool $default = false): bool
    {
        $value = $this->input($key, $default);
        if (is_bool($value)) {
            return $value;
        }
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * 获取输入值并转为数组
     * 
     * @param string $key 参数名
     * @param array $default 默认值
     * @return array 数组值
     */
    public function arrayInput(string $key, array $default = []): array
    {
        $value = $this->input($key, $default);
        return is_array($value) ? $value : $default;
    }

    /**
     * 合并数据到请求
     * 
     * @param array $data 合并的数据
     * @return self
     */
    public function merge(array $data): self
    {
        $this->post = array_merge($this->post, $data);
        return $this;
    }
}
