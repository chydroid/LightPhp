<?php
declare(strict_types=1);

namespace middleware;

/**
 * CORS 涓棿浠?- 澶勭悊璺ㄥ煙璇锋眰
 */
class Cors
{
    private array $config;

    public function __construct(?array $config = null)
    {
        // 濮嬬粓涓庨粯璁ゅ€煎悎骞讹細姝ゅ墠鐢?`$config ?? defaults`锛?
        // 浼犲叆銆岄儴鍒嗛厤缃€嶏紙濡備粎 allowed_origins锛夋椂鍏朵綑閿叏閮ㄧ己澶憋紝
        // 绗?26 琛?in_array('*', $this->config['allowed_origins']) 鐩存帴鎶?
        // TypeError: Argument #2 must be of type array, null given銆?
        $defaults = [
            'allowed_origins' => [],
            'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'],
            'allowed_headers' => ['Content-Type', 'Authorization', 'X-Requested-With', 'X-CSRF-TOKEN'],
            'exposed_headers' => [],
            'max_age' => 86400,
            'supports_credentials' => false,
        ];
        $this->config = $config === null ? $defaults : ($config + $defaults);

        // W3C CORS 瑙勮寖鏄庣‘绂佹閫氶厤绗?Origin 涓?Credentials 鍚屾椂鍚敤
        // 姝ょ粍鍚堜細瀵艰嚧浠绘剰绔欑偣鍙惡甯?Cookie 鍙戣捣璺ㄥ煙璇锋眰
        if (in_array('*', $this->config['allowed_origins'], true)
            && !empty($this->config['supports_credentials'])) {
            throw new \InvalidArgumentException(
                'Cors: "allowed_origins: *" cannot be used together with "supports_credentials: true". '
                . 'Use an explicit origin whitelist instead.'
            );
        }
    }

    /**
     * 澶勭悊璇锋眰
     * 
     * @param \core\Request $request 璇锋眰瀵硅薄
     * @param callable $next 涓嬩竴涓鐞嗚€?
     * @return mixed 鍝嶅簲
     */
    public function handle(\core\Request $request, callable $next): mixed
    {
        $origin = $request->header('Origin', '');
        $origin = $this->sanitizeOrigin($origin);

        if (in_array('*', $this->config['allowed_origins'], true)) {
            if ($this->config['supports_credentials']) {
                // 鏈夊嚟璇佹椂涓嶅厑璁哥敤閫氶厤绗︼紝鍥為€€鍒板洖鏄捐姹?Origin
                if ($origin !== '') {
                    $this->sendHeader('Access-Control-Allow-Origin', $origin);
                    $this->sendHeader('Vary', 'Origin');
                }
            } else {
                $this->sendHeader('Access-Control-Allow-Origin', '*');
            }
        } elseif ($this->isOriginAllowed($origin)) {
            $this->sendHeader('Access-Control-Allow-Origin', $origin);
            $this->sendHeader('Vary', 'Origin');
        }

        if ($this->config['supports_credentials']) {
            $this->sendHeader('Access-Control-Allow-Credentials', 'true');
        }

        $method = $request->method();
        if ($method === 'OPTIONS') {
            // CORS 澶存棦鐢?header() 鐩村彂锛堢湡瀹?HTTP 鍦烘櫙锛夛紝涔熷啓鍏ヨ繑鍥炵殑
            // Response 瀵硅薄锛歊outer 鐨?OPTIONS 鑷姩搴旂瓟杩斿洖 Response锛?
            // 鑻ュ彧璋?header()锛屼腑闂村眰/娴嬭瘯璇?getHeaders() 灏嗘嬁涓嶅埌杩欎簺澶淬€?
            $allowMethods = implode(', ', $this->config['allowed_methods']);
            $allowHeaders = implode(', ', $this->config['allowed_headers']);
            $maxAge = (string) $this->config['max_age'];

            $this->sendHeader('Access-Control-Allow-Methods', $allowMethods);
            $this->sendHeader('Access-Control-Allow-Headers', $allowHeaders);
            $this->sendHeader('Access-Control-Max-Age', $maxAge);

            $response = \core\Response::make('', 204);
            $response->header('Access-Control-Allow-Methods', $allowMethods);
            $response->header('Access-Control-Allow-Headers', $allowHeaders);
            $response->header('Access-Control-Max-Age', $maxAge);
            $allowedOrigin = $this->resolveAllowOriginHeader($origin);
            if ($allowedOrigin !== null) {
                $response->header('Access-Control-Allow-Origin', $allowedOrigin);
            }
            if ($this->config['supports_credentials']) {
                $response->header('Access-Control-Allow-Credentials', 'true');
            }
            return $response;
        }

        if (!empty($this->config['exposed_headers'])) {
            $this->sendHeader('Access-Control-Expose-Headers', implode(', ', $this->config['exposed_headers']));
        }

        // 闈?OPTIONS 璇锋眰涓?Origin 涓嶈鍏佽鏃讹紝鎷掔粷璇锋眰
        // 娌℃湁 Origin 澶寸殑璇锋眰锛堝悓婧愯姹傦級涓嶉渶瑕?CORS 妫€鏌?
        // 娉ㄦ剰锛氶€氶厤绗?鍑瘉妯″紡宸插湪涓婃柟澶勭悊锛屾澶勯渶鎺掗櫎璇ユ儏鍐?
        $wildcardWithCredentials = in_array('*', $this->config['allowed_origins'], true) && $this->config['supports_credentials'];
        if ($origin !== '' && !$this->isOriginAllowed($origin) && !$wildcardWithCredentials) {
            http_response_code(403);
            return '';
        }

        return $next($request);
    }

    /**
     * 鍙戦€佸師鐢?HTTP 鍝嶅簲澶?
     *
     * CLI / 娴嬭瘯鐜涓?headers already sent锛岀洿鎺ヨ皟鐢?header() 浼氫骇鐢?
     * 璀﹀憡鍣煶锛屾鏃惰烦杩囧嵆鍙€斺€擟ORS 澶村悓鏃朵篃浼氬啓鍏?Response 瀵硅薄銆?
     *
     * @param string $name 澶村悕绉?
     * @param string $value 澶村€?
     */
    private function sendHeader(string $name, string $value): void
    {
        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }
        header($name . ': ' . $value);
    }

    /**
     * 璁＄畻 Access-Control-Allow-Origin 澶寸殑鍊?
     *
     * @param string $origin 宸插噣鍖栫殑璇锋眰 Origin
     * @return string|null 澶村€硷紱鏃?Origin 鎴栦笉琚厑璁告椂杩斿洖 null
     */
    private function resolveAllowOriginHeader(string $origin): ?string
    {
        if (in_array('*', $this->config['allowed_origins'], true)) {
            if ($this->config['supports_credentials']) {
                // 閫氶厤绗?+ 鍑瘉锛氳鑼冪姝?'*'锛屽繀椤诲洖鏄惧叿浣?Origin
                return $origin !== '' ? $origin : null;
            }
            return '*';
        }
        return $this->isOriginAllowed($origin) ? $origin : null;
    }

    private function isOriginAllowed(string $origin): bool
    {
        if (empty($origin)) {
            return false;
        }

        $allowed = $this->config['allowed_origins'];

        // 鏈夊嚟璇佹椂锛屼笉鍏佽閫氶厤绗﹀尮閰?
        if (!empty($this->config['supports_credentials'])) {
            return in_array($origin, $allowed, true);
        }

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