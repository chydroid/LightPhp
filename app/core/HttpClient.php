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
                    // json_encode 失败必须显式报错：此前 ?: '' 会静默变成空体，
                    // 但 Content-Type 仍标 JSON，服务端收到一个零字节的 POST。
                    $encoded = json_encode($body, JSON_UNESCAPED_UNICODE);
                    if ($encoded === false) {
                        throw new HttpClientException(
                            'Failed to JSON-encode request body: ' . json_last_error_msg()
                        );
                    }
                    $bodyStr = $encoded;
                    if (!$this->hasHeader($headers, 'Content-Type')) {
                        $headers[] = 'Content-Type: application/json; charset=utf-8';
                    }
                } else {
                    // 非 JSON 模式下的数组不能转字符串（会得到字面量 "Array"）
                    if (is_array($body)) {
                        throw new HttpClientException(
                            'Request body is an array but json is disabled; '
                            . 'set json => true to send it as a JSON payload'
                        );
                    }
                    $bodyStr = (string) $body;
                }
            }

            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, false);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
            // timeout 必须校验：libcurl 的 CURLOPT_TIMEOUT=0 表示「永不超时」，
            // 而 (int)'abc' 与 (int)null 都是 0，会让慢服务永久占用 worker。
            $timeout = (int) ($opts['timeout'] ?? 30);
            if ($timeout <= 0) {
                $timeout = 30;
            }
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            // 连接阶段单独设限，避免 DNS/TCP 握手挂死吃满整个超时预算
            $connectTimeout = (int) ($opts['connect_timeout'] ?? min($timeout, 5));
            if ($connectTimeout > 0) {
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min($connectTimeout, $timeout));
            }
            curl_setopt($ch, CURLOPT_USERAGENT, (string) ($opts['user_agent'] ?? 'LightPHP/HttpClient'));
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

            // SSRF 防护：仅允许 http/https。
            // 不限制时 file:// / gopher:// / dict:// 等协议可读取本地文件、
            // 探测内网服务；同时设置 NO_PROXY 禁止走环境代理绕过限制。
            $allowedProtocols = CURLPROTO_HTTP | CURLPROTO_HTTPS;
            if (defined('CURLOPT_PROTOCOLS')) {
                curl_setopt($ch, CURLOPT_PROTOCOLS, $allowedProtocols);
            }
            if (defined('CURLOPT_PROTOCOLS_STR')) {
                curl_setopt($ch, CURLOPT_PROTOCOLS_STR, 'http,https');
            }
            curl_setopt($ch, CURLOPT_PROXY, '');
            curl_setopt($ch, CURLOPT_NOPROXY, '*');

            // TLS 校验必须显式开启（避免被外部配置意外关闭）
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

            // 响应体积上限：防止恶意/异常服务端返回超大响应打满内存
            $maxBytes = (int) ($opts['max_bytes'] ?? (5 * 1024 * 1024));
            if ($maxBytes > 0) {
                // CURLOPT_NOPROGRESS + progressfunction 组合可中止超限传输
                $downloaded = 0;
                curl_setopt($ch, CURLOPT_NOPROGRESS, false);
                curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function ($curl, $dltotal, $dlnow) use (&$downloaded, $maxBytes) {
                    $downloaded = (int) $dlnow;
                    // 返回非 0 中止传输
                    return $downloaded > $maxBytes ? 1 : 0;
                });
            }

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
 * 规范化请求头，剔除可用于注入的字符
 *
 * 原实现直接拼接 `$name . ': ' . $value`，未剔除 CRLF/NUL。
 * 实测传入 "X-A: 1\r\nX-Evil: pwned" 时，服务端会收到两个独立请求头
 * （HTTP_X_A=1 与 HTTP_X_EVIL=pwned），构成请求头注入。
 * 框架的 Response::header() 已做同样处理，此处补齐对称路径。
 *
 * @param array<string,string> $headers
 * @return list<string> 形如 "Name: Value"
 */
private function normalizeHeaders(array $headers): array
    {
        // 调用方不得覆盖这些头：否则可劫持虚拟主机判定/缓存键，
        // 或与 curl 自动生成的长度、传输编码冲突
        $forbidden = ['host', 'content-length', 'transfer-encoding'];

        $strip = static fn($v): string => str_replace(["\r", "\n", "\0"], '', (string) $v);
        $result = [];
        foreach ($headers as $name => $value) {
            if (is_int($name)) {
                // 整行形式 "Name: Value"
                $clean = $strip($value);
                $headerName = trim(explode(':', $clean, 2)[0]);
                if (strtolower($headerName) === 'host') {
                    continue;
                }
                $result[] = $clean;
                continue;
            }
            $headerName = trim((string) $name);
            if (in_array(strtolower($headerName), $forbidden, true)) {
                continue;
            }
            $result[] = $strip($headerName) . ': ' . $strip($value);
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
