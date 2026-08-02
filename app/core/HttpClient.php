<?php
declare(strict_types=1);

namespace core;

/**
 * HTTP 客户端 - 基于 cURL 的零依赖轻量级 HTTP 客户端
 *
 * 支持 GET/POST/PUT/PATCH/DELETE、自定义头、JSON 请求体、超时与错误抛出。
 * 返回 HttpResponse 对象（含 status / headers / body / json()）。
 *
 * 用法：
 *   $client = new \core\HttpClient(['timeout' => 5, 'throw' => true]);
 *   $resp = $client->get('https://api.example.com/users');
 *   $data = $resp->json();
 *
 *   $resp = $client->post('https://api.example.com/users', ['name' => 'Tom'], ['json' => true]);
 *
 * 配置选项（构造参数或请求 options）：
 *   - timeout: int 秒数（默认 30）
 *   - throw: bool HTTP >= 400 时抛 HttpClientException（默认 false）
 *   - headers: array<string,string> 默认请求头
 *   - user_agent: string 默认 User-Agent
 */
class HttpClient
{
    private array $defaults;

    public function __construct(array $options = [])
    {
        $this->defaults = array_merge([
            'timeout' => 30,
            'throw' => false,
            'headers' => [],
            'user_agent' => 'LightPHP/HttpClient',
        ], $options);
    }

    public function get(string $url, array $options = []): HttpResponse
    {
        return $this->request('GET', $url, $options);
    }

    public function post(string $url, mixed $body = null, array $options = []): HttpResponse
    {
        return $this->request('POST', $url, array_merge(['body' => $body], $options));
    }

    public function put(string $url, mixed $body = null, array $options = []): HttpResponse
    {
        return $this->request('PUT', $url, array_merge(['body' => $body], $options));
    }

    public function patch(string $url, mixed $body = null, array $options = []): HttpResponse
    {
        return $this->request('PATCH', $url, array_merge(['body' => $body], $options));
    }

    public function delete(string $url, array $options = []): HttpResponse
    {
        return $this->request('DELETE', $url, $options);
    }

    /**
     * 发起 HTTP 请求
     *
     * @param string $method HTTP 方法（大写）
     * @param string $url 完整 URL
     * @param array $options 单次请求选项，与构造选项合并覆盖
     *   - body: string|array 请求体；array 触发 json 模式（自动 json 编码 + Content-Type）
     *   - json: bool 显式标记 body 为 array 时按 JSON 发送（默认 true 当 body 是 array）
     *   - headers: array 单次请求头
     *   - timeout: int
     *   - throw: bool
     *   - query: array<string,mixed> URL 查询参数
     * @return HttpResponse
     * @throws HttpClientException curl 不可用、网络错误或 HTTP >= 400 且 throw=true
     */
    public function request(string $method, string $url, array $options = []): HttpResponse
    {
        if (!function_exists('curl_init')) {
            throw new HttpClientException('cURL extension is not available.');
        }

        $opts = array_merge($this->defaults, $options);
        $method = strtoupper($method);

        // 处理 query 参数
        if (!empty($opts['query']) && is_array($opts['query'])) {
            $sep = str_contains($url, '?') ? '&' : '?';
            $url .= $sep . http_build_query($opts['query']);
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new HttpClientException("Failed to init cURL for URL: {$url}");
        }

        try {
            $headers = $this->normalizeHeaders($opts['headers'] ?? []);

            // 请求体处理
            $bodyStr = '';
            if (array_key_exists('body', $opts) && $opts['body'] !== null) {
                $body = $opts['body'];
                $useJson = $opts['json'] ?? is_array($body);
                if ($useJson) {
                    $bodyStr = json_encode($body, JSON_UNESCAPED_UNICODE) ?: '';
                    if (!$this->hasHeader($headers, 'Content-Type')) {
                        $headers[] = 'Content-Type: application/json; charset=utf-8';
                    }
                } else {
                    $bodyStr = (string) $body;
                }
            }

            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, false);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, (int) ($opts['timeout'] ?? 30));
            curl_setopt($ch, CURLOPT_USERAGENT, (string) ($opts['user_agent'] ?? 'LightPHP/HttpClient'));
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

            if ($bodyStr !== '') {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $bodyStr);
            }

            // 捕获响应头
            $responseHeaders = [];
            curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($curl, $header) use (&$responseHeaders) {
                $len = strlen($header);
                $parts = explode(':', $header, 2);
                if (count($parts) === 2) {
                    $name = trim($parts[0]);
                    $value = trim($parts[1]);
                    if (isset($responseHeaders[$name])) {
                        if (!is_array($responseHeaders[$name])) {
                            $responseHeaders[$name] = [$responseHeaders[$name]];
                        }
                        $responseHeaders[$name][] = $value;
                    } else {
                        $responseHeaders[$name] = $value;
                    }
                }
                return $len;
            });

            $responseBody = curl_exec($ch);
            if ($responseBody === false) {
                $errMsg = curl_error($ch) ?: 'cURL request failed';
                $errNo = curl_errno($ch);
                throw new HttpClientException($errMsg, $errNo);
            }

            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $response = new HttpResponse($status, $responseHeaders, is_string($responseBody) ? $responseBody : '');

            if (!empty($opts['throw']) && $status >= 400) {
                throw new HttpClientException(
                    "HTTP request failed with status {$status}",
                    $errNo = 0,
                    null,
                    $response
                );
            }

            return $response;
        } finally {
            curl_close($ch);
        }
    }

    /**
     * @param array<string,string> $headers
     * @return list<string> 形如 "Name: Value"
     */
    private function normalizeHeaders(array $headers): array
    {
        $result = [];
        foreach ($headers as $name => $value) {
            if (is_int($name)) {
                $result[] = (string) $value;
            } else {
                $result[] = $name . ': ' . $value;
            }
        }
        return $result;
    }

    /**
     * @param list<string> $headers
     */
    private function hasHeader(array $headers, string $name): bool
    {
        $nameLower = strtolower($name);
        foreach ($headers as $h) {
            $parts = explode(':', $h, 2);
            if (count($parts) === 2 && strtolower(trim($parts[0])) === $nameLower) {
                return true;
            }
        }
        return false;
    }
}
