# LightPHP 第四轮审计报告 —— 中间件 / 基础设施层

- **审计范围**：`app/core/Pipeline.php`、`Storage.php`、`LocalDisk.php`、`Disk.php`、`JsonResource.php`、`ApiDoc.php`、`Facade.php`、`Loader.php`、`helpers.php`、`traits/Macroable.php`、`ServiceProvider.php`、`Generator.php`、`HttpClient.php`、`Request.php`、`Response.php`；`app/middleware/{Throttle,Cors,CsrfMiddleware,RequestLogMiddleware,Middleware}.php`
- **审计日期**：2026-10-05
- **分支 / commit**：`cline/55bfe` / `f2dd2a2`
- **环境**：PHP 8.4.7 (NTS, Win32)，Windows / NTFS，curl + pdo_sqlite 可用
- **基线**：`php tests/run_tests.php` → **1084/1084 passed**；审计全程未修改仓库内任何文件（`git status --porcelain` 为空）
- **复现方式**：全部复现脚本写在 `sys_get_temp_dir()\lp_audit4\`，仓库内零文件改动。涉及真实 HTTP 行为的用例通过 `php -S 127.0.0.1:879x` + `curl -i` 验证。
- **约定**：下文「已验证缺陷」每条都附实测输出；仅靠静态阅读得出的推测一律放入末尾「疑似（未验证）」。
## 一、已验证缺陷

### 严重级别速览

| # | 级别 | 位置 | 一句话 |
|---|------|------|--------|
| 1 | HIGH | Router.php:759-796 | Router 不接受「中间件实例」对象 → 文档推荐的 `new Cors([...])` 全部 500 |
| 2 | HIGH | Router.php:564-569 vs Cors.php:67-74 | Router 在中间件之前吃掉所有 OPTIONS → CORS 预检分支是死代码 |
| 3 | HIGH | Cors.php:15-32, 68-70 | 传部分配置（含 `[]`）→ 构造函数/`handle()` 致命 TypeError |
| 4 | HIGH | Application.php:150 vs Router.php:790 | 代码注释承诺的 `'throttle:60,1'` 带参别名 → `Invalid middleware` 500 |
| 5 | MEDIUM | Middleware.php:14-16 vs Router.php:590-609 | `shouldSkip()` 归一化与 Router 不一致 → `$except` 白名单可被重复斜杠绕过（已用真实 HTTP 复现） |
| 6 | MEDIUM | HttpClient.php:122 | `timeout` 非数字/null → `(int)` 得 0 → **永不超时** |
| 7 | MEDIUM | HttpClient.php:108,156 | `json_encode` 失败静默发出**空请求体**，Content-Type 仍为 JSON |
| 8 | MEDIUM | HttpClient.php:209-220 | `normalizeHeaders()` 不过滤 CRLF → **请求头注入**（服务端实测收到注入头） |
| 9 | MEDIUM | Request.php:213-217 | `header(): ?string` 但 `$default` 是 mixed → 传非字符串默认值即 TypeError |
| 10 | MEDIUM | Request.php:527-542 | `integer()/float()/string()` 遇数组输入静默强转 → 类型混淆 |
| 11 | MEDIUM | Request.php:201-204 + RequestLogMiddleware.php:20 | `uri()` 含 query → 日志里出现明文 token/password |
| 12 | MEDIUM | Request.php:475-506 | `url()` 直接采信 `Host` 头 → 主机头投毒 |
| 13 | MEDIUM | Request.php:401-425 | `file()/hasFile()` 无法处理多文件字段 |
| 14 | MEDIUM | Response.php:107-135, 220-225 | `download()` 接受目录 → `send()` 把 PHP Warning 写进响应体，且声明 `Content-Length: 0` |
| 15 | MEDIUM | RequestLogMiddleware.php:62-72 | 返回类型硬编码 `\log\Logger` + 空 `catch` → 绑 PSR-3 logger 时**静默不记录** |
| 16 | MEDIUM | Loader.php:44-49 | 前缀校验缺分隔符 → 穿越到**共享前缀的兄弟目录**（实测成功 require） |
| 17 | MEDIUM | Loader.php:8-22, 38 | 前缀匹配顺序：先注册的 `zzz\` 永久遮蔽后注册的 `zzz\deep\` |
| 18 | MEDIUM | Macroable.php:45-62 | `mixin()` 对带公开构造器/带参方法的类必崩 |
| 19 | MEDIUM | Macroable.php:107-109 | `__call` 遇到静态闭包宏 → `Error: Value of type null is not callable` |
| 20 | MEDIUM | Macroable.php:21,72,80 | 宏表在兄弟子类间互相污染，`SubB::flushMacros()` 清掉 `SubA` 的宏 |
| 21 | MEDIUM | Throttle.php:77-141 | 限流计数文件**永不回收**，每个 (IP, path) 永久占一个 inode |
| 22 | MEDIUM | Cors.php:112-118 + 84-87 | `sanitizeOrigin()` 解析失败 ⇒ 403 拒绝分支被整体跳过 |
| 23 | MEDIUM | JsonResource.php:136-147 | `additional(['data'=>…])` 静默覆盖包装键，资源数据丢失 |
| 24 | LOW | HttpClient.php:104-115 | `json=false` + 数组 body → 发出字面量 `"Array"` |
| 25 | LOW | HttpClient.php:143-154 | `max_bytes` 超限时 `throw=false` 也抛异常，已下载数据丢弃 |
| 26 | LOW | Cors.php:62-64 | Origin 不被允许时仍发送 `Access-Control-Allow-Credentials: true` |
| 27 | LOW | LocalDisk.php:80-86 | `url()` 不清洗 `..` |
| 28 | LOW | LocalDisk.php:24-32 | `put('')` 泄漏 PHP Warning；`normalizePath()` 的 `$isDir` 是死参数 |
| 29 | LOW | RequestLogMiddleware.php:29-34 | 控制器抛异常时把 5xx 记成 200 |
| 30 | LOW | JsonResource.php:156-161 | `collection()` 对必填构造参数的子类抛 ArgumentCountError |
| 31 | LOW | Pipeline.php:144-153 | 不存在的中间件类名报「Invalid pipe type: string」，误导排障 |
| 32 | LOW | Middleware.php:23-29 | `except=['api/*']`（无前导斜杠）对任何真实 URI 永不匹配 |

---

### [1] HIGH — `Router::executeMiddleware()` 不支持「中间件实例」对象，文档推荐的 `new Cors([...])` 写法必然 500

**文件 / 行号**：`app/core/Router.php:759-796`（抛错点 `Router.php:790-791`）

**问题代码**
```php
// Router.php:764-793
foreach (array_reverse($middlewares) as $middleware) {
    $next = function () use ($middleware, $next, $request) {
        if (is_string($middleware) && class_exists($middleware)) { ... }   // 只认类名字符串
        if (is_array($middleware) && count($middleware) === 2) { ... }     // 只认 [类名, 方法名]
        if (is_callable($middleware)) { return $middleware($request, $next); }
        $identifier = is_string($middleware) ? $middleware : gettype($middleware);
        throw new \RuntimeException("Invalid middleware: {$identifier}");   // ← 对象落到这里
    };
}
```
`is_object($middleware)` 分支**根本不存在**。而 `core\Pipeline::carry()`（Pipeline.php:147-152）是有 `is_object` 分支的——同一框架两套中间件执行器能力不一致。

**复现代码**（`r14_final.php` 片段）
```php
$router = new \core\Router();
$router->setGlobalMiddleware([new \middleware\Cors(['allowed_origins' => ['*']])]);
$router->get('/api/user', fn() => \core\Response::make('ok'));
$res = $router->dispatch(new \core\Request());
```
**真实输出**
```
-- N3 Router::executeMiddleware 不支持对象形式的中间件 --
  RuntimeException: Invalid middleware: object
```
**真实 HTTP 验证**（`php -S 127.0.0.1:8796`，真实 `Router::dispatch()`）
```
$ curl -s -i -H "Origin: https://a.com" http://127.0.0.1:8796/api/user
HTTP/1.1 200 OK
<br /><b>Fatal error</b>:  Uncaught RuntimeException: Invalid middleware: object in ...\app\core\Router.php:791
#0 ...\app\core\Router.php(795): core\Router->{closure:core\Router::executeMiddleware():765}()
#1 ...\app\core\Router.php(556): core\Router->executeMiddleware(Array, Object(Closure), Object(core\Request))
```
**对照组**（证明是 Router 的能力缺失，而非中间件本身问题）
```
-- N4 对照：core\Pipeline 接受对象中间件 --
  Pipeline 结果类型: core\Request      ← 对象中间件正常执行
```

**根因**：`Cors::__construct(?array $config = null)` 要求把配置数组传入构造函数，因此**要自定义白名单就必然要 `new Cors([...])`**，而这个产物恰好是 Router 唯一不接受的中间件形态。

**受影响文档（均为官方推荐写法）**
- `docs/ecommerce-full-tutorial.md:265` — `$router->group(['middleware' => [new \middleware\Cors([...])]], ...)`
- `docs/security.md:176` / `docs/api.md:1875` — `$cors = new Cors([...]);`

**建议修复**：在 `Router.php:764` 之后、数组分支之前插入对象分支
```php
if (is_object($middleware)) {
    if (!method_exists($middleware, 'handle')) {
        throw new \RuntimeException(sprintf(
            'Middleware [%s] does not implement handle() method', get_class($middleware)));
    }
    return $middleware->handle($request, $next);
}
```
同时把闭包形参由 `function ()` 改为 `function ($request)`，让中间件的 `$next($request)` 与 `Pipeline` 语义对齐。

---

### [2] HIGH — `Router` 在执行任何中间件之前拦截所有 OPTIONS，`Cors` 的预检分支是死代码

**文件 / 行号**：`app/core/Router.php:564-569`（拦截） vs `app/middleware/Cors.php:67-74`（死代码）

**问题代码**
```php
// Router.php:537-569（dispatch 的路由循环尾部）
foreach ($this->routes as $route) {
    ...
    if (!$methodMatch) { $allowedMethods[$route['method']] = true; continue; }
    return $this->executeMiddleware($allMiddleware, $handler, $request);   // ← 只有方法匹配才进中间件
}
if ($allowedMethods !== []) {
    if ($method === 'OPTIONS') {
        $response = Response::make('', 204);          // ← 直接返回，globalMiddleware 一个都没跑
        $response->header('Allow', implode(', ', array_keys($allowedMethods)));
        return $response;
    }
}
```
```php
// Cors.php:66-74 —— 在 Router 路径下永远到不了
if ($method === 'OPTIONS') {
    header('Access-Control-Allow-Methods: ' . implode(', ', $this->config['allowed_methods']));
    header('Access-Control-Allow-Headers: ' . implode(', ', $this->config['allowed_headers']));
    header("Access-Control-Max-Age: {$this->config['max_age']}");
    http_response_code(204);
    return '';
}
```

**复现环境**：`router_endpoint.php`（真实 `core\Router`，`setGlobalMiddleware([SkipProbe::class])`，`php -S 127.0.0.1:8797`）

**真实输出**
```
$ curl -s -i -X OPTIONS -H "Origin: https://a.com" -H "Access-Control-Request-Method: POST" \
       http://127.0.0.1:8797/api/user
HTTP/1.1 204 No Content
Host: 127.0.0.1:8797
Content-Type: text/html; charset=utf-8
Allow: GET, POST
X-Content-Type-Options: nosniff
X-Frame-Options: SAMEORIGIN
X-XSS-Protection: 1; mode=block
Referrer-Policy: strict-origin-when-cross-origin

---

### [3] HIGH — `Cors` 传部分配置数组即致命 TypeError（`new Cors([])` 在构造函数就崩）

**文件 / 行号**：`app/middleware/Cors.php:15-32`（构造）、`Cors.php:47`、`Cors.php:48`、`Cors.php:62`、`Cors.php:68-70`、`Cors.php:76-77`、`Cors.php:83`

**问题代码**
```php
public function __construct(?array $config = null)
{
    $this->config = $config ?? [ /* 全量默认值 */ ];   // ← 只在 null 时给默认值
    if (in_array('*', $this->config['allowed_origins'], true)          // ← 26 行，无 ?? 兜底
        && !empty($this->config['supports_credentials'])) { ... }
}
```
构造函数的默认值只在 `$config === null` 时生效；一旦传入任何数组就**完全替换，且不与默认值合并**。

**复现代码**
```php
foreach ([[], ['allowed_origins' => ['*']],
          ['allowed_origins' => ['https://a.com'], 'supports_credentials' => true]] as $i => $cfg) {
    $co = new \middleware\Cors($cfg);
    $_SERVER['REQUEST_METHOD'] = 'OPTIONS';
    $_SERVER['HTTP_ORIGIN'] = 'https://a.com';
    $co->handle(new \core\Request(), fn() => 'NEXT');
}
```
**真实输出**
```
-- N5 new Cors(部分配置) --

Warning: Undefined array key "allowed_origins" in ...\app\middleware\Cors.php on line 26
  cfg#0 TypeError: in_array(): Argument #2 ($haystack) must be of type array, null given

Warning: Undefined array key "supports_credentials" in ...\app\middleware\Cors.php on line 48
Warning: Undefined array key "allowed_methods" in ...\app\middleware\Cors.php on line 68
  cfg#1 TypeError: implode(): If argument #1 ($separator) is of type string,
                     argument #2 ($array) must be of type array, null given

Warning: Undefined array key "allowed_methods" in ...\app\middleware\Cors.php on line 68
  cfg#2 TypeError: implode(): If argument #1 ($separator) is of type string,
                     argument #2 ($array) must be of type array, null given
```
`new Cors([])` 在**构造函数第 26 行**就致命；`new Cors(['allowed_origins'=>['*']])` 构造成功，但**第一个 OPTIONS 预检请求**立刻 500。

**根因**：构造函数只做 `??` 判空，而非 `array_merge` 归一化。

---

### [4] HIGH — 代码注释承诺的 `'throttle:60,1'` 带参别名形式不被支持，直接 500

**文件 / 行号**：`app/core/Application.php:150`（承诺） vs `app/core/Router.php:99-118`、`Router.php:790-791`（未实现）

**问题代码**
```php
// Application.php:150 —— 明确承诺这个语法
* 允许路由文件直接使用 `'cors'`、`'throttle:60,1'` 形式的短别名，
```
```php
// Router.php:99-118 resolveMiddleware()：别名是整串精确匹配，没有任何 ':' 语法解析
if (is_string($mw) && isset($this->middlewareAliases[$mw])) { $resolved[] = $this->middlewareAliases[$mw]; }
```
`'throttle:60,1'` 既不等于别名键 `'throttle'`，也不 `class_exists()`、不 `is_callable()`，一路透传到 `executeMiddleware()` 的抛错分支。

**复现代码**
```php
$router = new \core\Router();
$router->aliasMiddleware('throttle', \middleware\Throttle::class);  // 与 Application::registerMiddlewareAliases() 一致
$router->middleware('throttle:60,1');
$router->get('/api/y', fn() => \core\Response::make('ok'));
$router->dispatch(new \core\Request());
```
**真实输出**
```
-- N6 'throttle:60,1' 带参别名 --
  RuntimeException: Invalid middleware: throttle:60,1
```

**根因**：`resolveMiddleware()` 缺少 Laravel `parsePipeString()` 那样的 `alias:arg1,arg2` 解析步骤。

**建议修复**：在 `resolveMiddleware()` 的 `is_string($mw)` 分支最前面解析 `alias:args`；若不打算支持，应立即删除 `Application.php:150` 的注释以免误导。

**级别**：HIGH（框架自身注释宣传的语法 → 稳定 500）

---
Content-Security-Policy: default-src 'self'; ...
```
**响应里完全没有 `Access-Control-Allow-Origin` / `-Allow-Methods` / `-Allow-Headers` / `-Max-Age`**，只有 Router 自己加的 `Allow: GET, POST`。

**根因**：`dispatch()` 在路由循环里只有 `$methodMatch` 为真时才 `executeMiddleware()`；OPTIONS 永远不匹配任何已注册方法（除非显式注册 `OPTIONS` 路由），于是走 `$allowedMethods` 分支直接 204。浏览器按 Fetch 规范判定预检失败 → **任何需要预检的跨域请求（带 `Authorization`、自定义头、JSON POST）在文档所述配置下全部失败**。

**对照组**：不经 Router、直接调用 `Cors::handle()` 时这些头是齐的（见第 26 条实测输出），证明只是 Router 抢跑。

**建议修复**：把 OPTIONS 自动应答移到中间件**之后**
```php
if ($method === 'OPTIONS' && $allowedMethods !== []) {
    $handler = fn () => Response::make('', 204)
        ->header('Allow', implode(', ', array_keys($allowedMethods)));
    return $this->executeMiddleware($uriMatchedMiddleware, $handler, $request);
}
```
需要同时在方法不匹配分支收集 URI 命中的路由中间件。

**级别**：HIGH（CORS 功能在框架推荐配置下整体失效）

---

**级别**：HIGH（文档推荐路径全量 500；`Cors` 是唯一能自定义白名单的入口）

---

## 二、MEDIUM 级缺陷

---

### [5] MEDIUM — `Middleware::shouldSkip()` 的 URI 归一化与 `Router::normalizeUri()` 不一致 → `$except` 白名单可被重复斜杠绕过

**文件 / 行号**：`app/middleware/Middleware.php:14-16` vs `app/core/Router.php:590-609`

**问题代码**
```php
// Middleware.php:12-18 —— 直读 $_SERVER，parse_url 不折叠重复斜杠
protected function shouldSkip(): bool
{
    $uri = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $uri = rtrim($uri, '/');
    $uri = $uri !== '' ? $uri : '/';
    ...
}
```
```php
// Router.php:590-609 —— 先折叠重复斜杠（含 authority-form）再 parse_url
$collapsed = preg_replace('#/+#', '/', $raw);
$path = parse_url($collapsed, PHP_URL_PATH);
return '/' . trim($path, '/');
```

**复现（真实 HTTP，`php -S 127.0.0.1:8797`，中间件 `except = ['/api/user']`）**
```
### 1) POST /api/user        （except 命中，应跳过 CSRF）
{"skipped":true}
HTTP=200

### 2) POST //api//user      （Router 折叠后命中同一路由）
{"error":"CSRF token mismatch"}
HTTP=419
```
**根因定位（同进程内直接对比两条归一化路径）**
```
Router::normalizeUri('//api//user//') = '/api/user'
dispatch result = 'HANDLER-RAN'
shouldSkip() for except=['/api/user'] on '//api//user//' => false
shouldSkip() for except=['/api/user'] on '/api/user'     => true
parse_url('//api//user//', PHP_URL_PATH) = '//user//'     ← shouldSkip 拿到的是这个
parse_url('/api/user/',  PHP_URL_PATH) = '/api/user/'
```

---

### [6] MEDIUM — `HttpClient` 的 `timeout` 为非数字或 null 时静默变成「永不超时」

**文件 / 行号**：`app/core/HttpClient.php:122`

**问题代码**
```php
curl_setopt($ch, CURLOPT_TIMEOUT, (int) ($opts['timeout'] ?? 30));
```
`(int) 'abc'` = 0、`(int) null` = 0，而 libcurl 的 `CURLOPT_TIMEOUT = 0` 语义是「不设超时」。构造函数 `array_merge` 允许任意 `timeout` 值进来，没有任何校验。

**复现**（本地 `php -S 127.0.0.1:8792`，`/slow` 端点 `sleep(6)` 后返回）
```php
$c = new \core\HttpClient(['timeout' => 'abc']);  // 或 ['timeout' => null]
$t0 = microtime(true);
try { $c->get($B . '/slow'); } catch (\Throwable $e) { /* ... */ }
echo 'elapsed=' . round(microtime(true) - $t0, 2) . 's';
```
**真实输出**
```
---- E5 timeout="abc" 打 /slow
elapsed=6.02s                      ← 等到了服务端完整响应
---- E5a timeout=2 打 /slow (对照组)
core\HttpClientException: Operation timed out after 2011 milliseconds with 0 bytes received
---- E5b timeout=null 打 /slow
elapsed=10.02s                    ← 排在队列后面也一直等
---- E5c timeout=-1 打 /slow
elapsed=6.01s
```
timeout 是防止 worker 被慢服务挂死的最后一道闸门，静默失效意味着单次外部调用可以永久占用 worker。

**建议修复**
```php
$timeout = (int) ($opts['timeout'] ?? 30);
if ($timeout <= 0) { $timeout = 30; }               // 或按配置抛 InvalidArgumentException
curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min($timeout, 5));   // 连接阶段单独设限
```

**级别**：MEDIUM

---
**与历史报告的关系**：`.comate/audit/support.md:913` 已记录第 68 行 `implode()` 的 TypeError。本条**新增**两点：(a) 第 26 行 `new Cors([])` 在**构造期**就失败（更早、更致命）；(b) `supports_credentials`（48/62/83 行）与 `exposed_headers`（76 行）同样缺兜底。

**建议修复**
```php
private const DEFAULTS = [
    'allowed_origins' => [], 'allowed_methods' => ['GET','POST','PUT','DELETE','PATCH','OPTIONS'],
    'allowed_headers' => ['Content-Type','Authorization','X-Requested-With','X-CSRF-TOKEN'],
    'exposed_headers' => [], 'max_age' => 86400, 'supports_credentials' => false,
];
public function __construct(?array $config = null)
{
    $this->config = array_merge(self::DEFAULTS, $config ?? []);
}
```

**级别**：HIGH（配置少写一个键就是 500，且无任何降级路径）

---

### [7] MEDIUM — `HttpClient` 在 `json_encode` 失败时静默发出空请求体，但 `Content-Type` 仍标为 JSON

**文件 / 行号**：`app/core/HttpClient.php:106-115`、`:156-158`

**问题代码**
```php
$useJson = $opts['json'] ?? is_array($body);
if ($useJson) {
    $bodyStr = json_encode($body, JSON_UNESCAPED_UNICODE) ?: '';   // ← 失败静默变 ''
    if (!$this->hasHeader($headers, 'Content-Type')) {
        $headers[] = 'Content-Type: application/json; charset=utf-8'; // ← 头照加
    }
}
...
if ($bodyStr !== '') {                        // ← 空串时整段被跳过
    curl_setopt($ch, CURLOPT_POSTFIELDS, $bodyStr);
}
```
`json_encode()` 返回 `false` 时被 `?: ''` 吞掉，随后 `$bodyStr !== ''` 判空又让 `CURLOPT_POSTFIELDS` 完全不被设置——**请求方法仍是 POST，但一个字节的 body 都没有**，而 `Content-Type` 依然宣称是 JSON。

**复现**（本地 echo 服务端回显实际收到的 body）
```php
$c = new \core\HttpClient();
$r = $c->post($B . '/echo', ['bad' => "\xB1\x31"]);   // "\xB1\x31" 是非法 UTF-8
echo $r->body();
```
**真实输出**
```
---- E10 body 含非法 UTF-8
{"method":"POST","body":"","ct":"application\/json; charset=utf-8"}
---- E10b 对照：合法 UTF-8
{"method":"POST","body":"{\"bad\":\"ok\"}","ct":"application\/json; charset=utf-8"}
```
调用方**收不到任何错误**，服务端却收到一个空 POST；对写接口而言这是一次静默的数据丢失。

**建议修复**
```php
$encoded = json_encode($body, JSON_UNESCAPED_UNICODE);
if ($encoded === false) {
    throw new HttpClientException('Failed to JSON-encode request body: ' . json_last_error_msg());
}
$bodyStr = $encoded;
```

**级别**：MEDIUM

---

### [8] MEDIUM — `HttpClient::normalizeHeaders()` 不过滤 CRLF → 请求头注入（服务端实测收到注入头）

**文件 / 行号**：`app/core/HttpClient.php:209-220`（对比 `Response::header()` 已做过滤，`Response.php:146-147`）

**问题代码**
```php
private function normalizeHeaders(array $headers): array
{
    $result = [];
    foreach ($headers as $name => $value) {
        if (is_int($name)) { $result[] = (string) $value; }
        else { $result[] = $name . ': ' . $value; }   // ← 原样拼接，不剔除 \r\n
    }
    return $result;
}
```
框架自己在 `Response::header()` 里做了 `str_replace(["\r","\n"], '', …)`，`HttpClient` 这条对称路径却漏了。

**复现**（本地 echo 服务端回显收到的 `HTTP_*` 头）
```php
$c = new \core\HttpClient();
$r = $c->get($B . '/echo', ['headers' => ["X-A: 1\r\nX-Evil: pwned"]]);
echo json_encode($r->json()['headers']);
```
**真实输出（服务端实际收到的头）**
```
---- E15 header 值含 CRLF（注入尝试）
{
    "HTTP_ACCEPT": "*/*",
    "HTTP_HOST": "127.0.0.1:8794",
    "HTTP_X_A": "1",
    "HTTP_X_EVIL": "pwned"          ← 注入成功，成为独立请求头
}
---- E15b header 名含 CRLF
{
    "HTTP_X_A": "1",
    "HTTP_X_EVIL": "pwned: v"       ← 同样注入成功
}
---- E16 覆盖 Host 头
{ "HTTP_HOST": "evil.example.com" } ← Host 可被覆盖（影响缓存/虚拟主机判定）
```
只要 `headers` 里有任何一处来自用户输入或未清洗的数据源（SSRF 代理、Webhook 转发、按请求定制 header 的场景），即可注入任意请求头。

**建议修复**
```php
$clean = static fn (string $s): string => str_replace(["\r", "\n", "\0"], '', $s);
$result[] = $clean((string) $name) . ': ' . $clean((string) $value);
```
并建议默认禁止调用方覆盖 `Host` / `Content-Length` / `Transfer-Encoding`。

**级别**：MEDIUM

---
两处差异叠加：(a) `shouldSkip()` 不折叠 `//`；(b) `parse_url()` 对以 `//` 开头的串仍走 authority-form，把 `api` 当主机名丢掉。

**影响**：`$except` 是「跳过 CSRF / 跳过限流 / 跳过输出缓存 / 跳过日志」的唯一开关。攻击者只要在路径里多加几个斜杠，就能让所有 `$except` 精确匹配条目全部失效，而请求本身仍会被 Router 正常分发。

**与历史报告的关系**：`.comate/audit/core.md:946` 已从 `//evil.com/api/pay`（authority-form 角度）记录过同一根因。本条给出**不依赖 authority 混淆**的更干净复现（纯重复斜杠 `//api//user`），并首次给出真实 HTTP 层面的 419/200 对照。

**建议修复**：抽取共享归一化函数（如 `Request::decodedPath()`），Router 与 `shouldSkip()` 都用它；`shouldSkip()` 改为接收 `handle()` 已拿到的 `\core\Request`，不再直读 `$_SERVER`。

**级别**：MEDIUM（`$except` 形同虚设；不直接越权，但依赖它的所有「跳过型」防护均可被绕过）

---

### [9] MEDIUM — `Request::header()` 返回类型声明 `?string`，但 `$default` 是 `mixed` → 传非字符串默认值即 TypeError

**文件 / 行号**：`app/core/Request.php:213-217`

**问题代码**
```php
public function header(string $key, $default = null): ?string
{
    $key = $this->normalizeHeaderKey($key);
    return $this->headers[$key] ?? $default;    // ← $default 未收窄
}
```
签名把 `$default` 声明为无类型（`mixed`），返回类型却是 `?string`。同一文件里 `string()/integer()/float()`（`Request.php:515-542`）都写成了 `string $default = ''` 之类的一致收窄，唯独 `header()` 漏了。

**复现**
```php
$_SERVER['HTTP_X_CUSTOM'] = 'v1';
$r = new \core\Request();
$r->header('X-Missing', ['a', 'b']);
$r->header('X-Missing', 123);
```
**真实输出**
```
---- F3 header 默认值类型
TypeError: core\Request::header(): Return value must be of type ?string, array returned
---- F3b header 默认值 int
TypeError: core\Request::header(): Return value must be of type ?string, int returned
---- F3c header 默认值 null
NULL
```
此外 `parseHeaders()`（`Request.php:62-83`）把 `$_SERVER` 的值原样塞进 `$this->headers`，所以任何 SAPI 或测试代码把某个 `HTTP_*` 设成非字符串（`$_SERVER['HTTP_X'] = 123;`）也会在读取时抛同样的 TypeError。

**建议修复**
```php
public function header(string $key, ?string $default = null): ?string
{
    $key = $this->normalizeHeaderKey($key);
    $v = $this->headers[$key] ?? null;
    return $v === null ? $default : (string) $v;
}
```

**级别**：MEDIUM

---

### [10] MEDIUM — `Request::integer()/float()/string()` 遇数组输入静默强转，造成类型混淆

**文件 / 行号**：`app/core/Request.php:515-542`

---

### [11] MEDIUM — `Request::uri()` 返回含 query string，`RequestLogMiddleware` 把明文 token/password 写进日志

**文件 / 行号**：`app/core/Request.php:201-204`（`uri()`）、`app/middleware/RequestLogMiddleware.php:20` 与 `:37-44`、`:48-55`

**问题代码**
```php
// Request.php:201-204 —— 注释写「请求路径」，实际返回完整 REQUEST_URI（含 ?query）
public function uri(): string { return $this->server['REQUEST_URI'] ?? '/'; }
```
```php
// RequestLogMiddleware.php:20, 37-44
$uri = $request->uri();
$message = sprintf('%s %s → %d [%sms] [%s]', $method, $uri, $statusCode, $duration, $ip);
...
'uri' => $uri,
```
`Throttle::resolveKey()`（`Throttle.php:59`）自己补了 `parse_url(..., PHP_URL_PATH)` 来规避这一点，但 `RequestLogMiddleware` 没有。

**复现**
```php
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI']   = '/api/user/login?token=SECRET_TOKEN_123&password=hunter2';
(new \middleware\RequestLogMiddleware())->handle(new \core\Request(), fn() => \core\Response::make('ok'));
```
**真实输出**
```
N1 日志行: POST /api/user/login?token=SECRET_TOKEN_123&password=hunter2 → 200 [0.51ms] [203.0.113.5]
```
`access_token`、`password`、`reset_token`、签名参数等全部以明文落盘。`Throttle` 之所以正确，正是因为它没依赖 `uri()`。

**建议修复**：让 `RequestLogMiddleware` 记录前剥离 query，或增加一个 `Request::path(): string`（纯路径）并统一改用 `path()`；若确实需要记录 query，应做白名单参数过滤。

**级别**：MEDIUM（凭据泄漏到日志文件）

---

### [12] MEDIUM — `Request::url()` 直接采信 `Host` 请求头 → 主机头投毒

**文件 / 行号**：`app/core/Request.php:475-506`

**问题代码**
```php
public function host(): string { return $this->server['HTTP_HOST'] ?? 'localhost'; }   // 475-478
public function url(): string
{
    $host = $this->host();
    $url = $this->scheme() . '://' . $host;      // ← 未做可信主机名校验
    ...
    return $url . $this->uri();                   // ← 还叠加了含 query 的 uri()
}
```

**复现**：`$_SERVER['HTTP_HOST'] = 'evil.example.com'; $_SERVER['SERVER_PORT'] = 8080; $_SERVER['HTTPS'] = 'on';`
**真实输出**
```
---- F1 uri() 是否含 query string
/api/user/1?token=secret123&x=2
---- F2 url()
https://evil.example.com:8080/api/user/1?token=secret123&x=2
```
`url()` 常被用于「找回密码链接」「OAuth 回调」「分页链接」「canonical URL」。攻击者只要发一个自定义 `Host` 的请求，拿到的就是指向自己域名的绝对 URL。框架里没有任何 `trusted_hosts` / `APP_URL` 之类的白名单来约束它。

**建议修复**：新增 `trustedHosts` 配置，`host()` 只接受白名单内的 `HTTP_HOST`，否则回落到配置的 `APP_URL`；同时 `url()` 应使用不含 query 的 `path()`。

**级别**：MEDIUM

---

### [13] MEDIUM — `Request::file()` / `hasFile()` 无法处理多文件字段，产出「看起来正常」的坏 `Upload`

**文件 / 行号**：`app/core/Request.php:401-425`

**问题代码**
```php
public function file(?string $key = null): ?Upload
{
    if ($key === null) { ... return new Upload($this->files[$firstKey]); }
    if (!isset($this->files[$key])) { return null; }
    return new Upload($this->files[$key]);        // ← 直接把整个数组塞进 Upload
}
public function hasFile(string $key): bool
{
    return isset($this->files[$key]) && $this->files[$key]['error'] !== UPLOAD_ERR_NO_FILE;
}

---

### [14] MEDIUM — `Response::download()` 接受目录 → `send()` 把 PHP Warning 写进响应体，且声明 `Content-Length: 0`

**文件 / 行号**：`app/core/Response.php:107-135`（`download()`）、`:220-225`（`send()` 的 `readfile`）

**问题代码**
```php
// Response.php:115-117 —— file_exists() 对目录同样返回 true
if (!file_exists($filePath)) { throw new \InvalidArgumentException("File not found: {$filePath}"); }
...
$fileSize = filesize($filePath);     // 目录在 Windows 上返回 0，不报错
if ($fileSize === false) { throw new \RuntimeException(...); }
$response->header('Content-Length', (string) $fileSize);   // → "0"
```
```php
// Response.php:220-223
if ($this->filePath !== null) { readfile($this->filePath); }
```

**复现**
```php
$dir = sys_get_temp_dir() . '/lp_audit4/dl_dir_' . getmypid();
@mkdir($dir, 0755, true);
$dl = \core\Response::download($dir);
$dl->send();
```
**真实输出**
```
-- M6 Response::download(目录) --
  download(目录) 构造成功: true
  headers: {"Content-Type":"application\/octet-stream",
            "Content-Disposition":"attachment; filename=\"dl_dir_22280\"",
            "Content-Length":"0","Cache-Control":"no-cache"}
  send() 输出字节数: 121      ← 这 121 字节就是被写进 HTTP body 的 PHP Warning 文本
```
**影响**：响应对外声明 `Content-Length: 0`，实际却吐出 121 字节的 PHP 错误文本。这是协议层截断 + 内部路径信息泄漏（Warning 里含服务器绝对路径），在 `display_errors=On` 的生产环境下必然触发。

**建议修复**
```php
if (!is_file($filePath)) {
    throw new \InvalidArgumentException("Not a regular file: {$filePath}");
}
if (!is_readable($filePath)) {
    throw new \RuntimeException("File not readable: {$filePath}");
}
```
并在 `send()` 里对 `readfile()` 的返回值做检查，失败时不要把 warning 混入响应体。

**级别**：MEDIUM

---
**问题代码**
```php
public function string(string $key, string $default = ''): string   { return (string) $this->input($key, $default); }
public function integer(string $key, int $default = 0): int        { return (int) $this->input($key, $default); }
public function float(string $key, float $default = 0.0): float     { return (float) $this->input($key, $default); }
```
`input()` 会原样返回 `$_GET/$_POST` 里的任意值，包括数组。`(int) ['1','2']` = 1（非空数组恒为 1），**调用方拿到的是一个凭空捏造的整数而不是默认值**。

**复现**：`$_GET = ['zero'=>'0', 'arr'=>['1','2'], 'boolFalse'=>'false', 'big'=>'999999999999999999999'];`
**真实输出**
```
---- F9 integer("zero")                 0            ← 正确
---- F9b integer("arr")  (数组强转 int)  1            ← 应回落到 $default=0
---- F9c boolean("boolFalse")           false         ← 正确
---- F9d boolean("nul")                 false
---- F9e boolean("zero")                false
---- F9f integer("big") 溢出            9223372036854775807
---- F9g float("arr")                   1.0           ← 同上
---- F9h string("arr")
Warning: Array to string conversion in ...\app\core\Request.php on line 517
'Array'                                             ← 且泄漏 Warning
---- F9i arrayInput("zero") 非数组时回落默认值  array(0 => 'DEF')   ← 正确
```
**影响**：`?id[]=1&id[]=2` 会让 `$request->integer('id')` 返回 1 而不是 0/默认值，任何以 `integer()` 取值后再做 `WHERE id = ?` 的代码都会命中 id=1 这条记录；`string()` 还会往错误日志里灌 Warning。

**建议修复**
```php
private function scalar(string $key, mixed $default): mixed
{
    $v = $this->input($key, $default);
    return is_array($v) || is_object($v) ? $default : $v;
}
public function integer(string $key, int $default = 0): int { return (int) $this->scalar($key, $default); }
// string() / float() / boolean() 同理
```

**级别**：MEDIUM

---

### [15] MEDIUM — `RequestLogMiddleware::resolveLogger()` 返回类型硬编码 `\log\Logger` + 空 `catch` → 绑 PSR-3 logger 时完全静默

**文件 / 行号**：`app/middleware/RequestLogMiddleware.php:62-72`

**问题代码**
```php
private function resolveLogger(): ?Logger          // ← \log\Logger（具体类，不是 LoggerInterface）
{
    try {
        $container = \core\Container::getInstance();
        if ($container !== null && $container->has('log')) {
            return $container->get('log');          // ← 若不是 \log\Logger，这里抛 TypeError
        }
    } catch (\Throwable $e) {
    }                                                // ← 空 catch，异常被彻底吞掉
    return null;
}
```
`log\Logger` 实现了 `core\contract\LoggerInterface`（PSR-3），但 `resolveLogger()` 的返回类型写死成具体类。绑定 Monolog、任何第三方 PSR-3 适配器、或自定义 logger 时，`return $container->get('log')` 会抛 `TypeError`，而这个 `TypeError` 正好落在 `try` 里被空 `catch` 吃掉。

**复现**
```php
class Psr3Logger { public array $lines = [];
    public function info(string $m, array $c = []): void { $this->lines[] = [$m, $c]; } }

$psr = new Psr3Logger();
$c2 = new \core\Container();
$c2->instance('log', $psr);
\core\Container::setInstance($c2);
(new \middleware\RequestLogMiddleware())->handle(new \core\Request(), fn() => \core\Response::make('ok'));
echo count($psr->lines);
```
**真实输出**
```
-- M0b 绑定 Psr3Logger（非 \log\Logger）时 RequestLogMiddleware 的行为 --
  Psr3Logger 收到的日志条数 = 0  <= 完全静默，无任何 warning/error_log
  resolveLogger() 返回 = NULL (被 catch(\Throwable) 吞掉的 TypeError)
```
换成 `\log\Logger` 子类后同一条链路立刻正常记录，进一步确认是返回类型而非其他原因：
```
  日志消息: GET /api/x → 201 [0ms] [203.0.113.5]
```
**影响**：接入方会以为「日志中间件装上了、容器里也绑了 logger」，结果一个请求都不记，且没有任何错误提示；排障时完全无从下手。

**建议修复**
```php
private function resolveLogger(): ?\core\contract\LoggerInterface
{
    try {
        $container = \core\Container::getInstance();
        $logger = $container?->get('log');
        return $logger instanceof \core\contract\LoggerInterface ? $logger : null;
    } catch (\Throwable $e) {
        error_log('LightPHP RequestLogMiddleware: cannot resolve logger: ' . $e->getMessage());
        return null;
    }
}
```
（`$logger` 用 `instanceof` 判定，不再依赖返回类型强转，也就不会有 TypeError 被吞。）

**级别**：MEDIUM

---

### [16] MEDIUM — `Loader::autoload()` 的前缀校验缺目录分隔符 → 穿越到「共享前缀的兄弟目录」

**文件 / 行号**：`app/core/Loader.php:44-49`

**问题代码**
```php
$realBase = realpath($path);
$realFile = realpath($file);
if ($realBase === false || $realFile === false || !str_starts_with($realFile, $realBase)) {
    continue;
}
```
`str_starts_with()` 比较的是**原始字符串**，没有补分隔符。`C:\...\ns\coreEvil\X.php` 以 `C:\...\ns\core` 开头，于是被判为「在 base 目录内」。

**复现**（全程在临时目录内，未在仓库内创建任何文件）
```
布局：
  {tmp}/ns/core/Good.php         class core\Good

---

### [17] MEDIUM — `Loader` 前缀匹配顺序：先注册的短前缀永久遮蔽后注册的更具体前缀

**文件 / 行号**：`app/core/Loader.php:8-22`（`$prefixes` 顺序）、`Loader.php:38-40`（`foreach` 顺序匹配 + 首个命中即 `require`）

**问题代码**
```php
private static array $prefixes = [
    'core\\'          => APP_PATH . 'core/',
    'core\\console\\' => APP_PATH . 'core/console/',      // ← 被上一行永久遮蔽
    'core\\traits\\'  => APP_PATH . 'core/traits/',       // ← 同上
    ...
];
...
foreach (self::$prefixes as $prefix => $path) {
    if (strncmp($prefix, $class, $len) === 0) {
        ...
        if (file_exists($file)) { require $file; return; }   // 命中即返回，不再试后面的前缀
    }
}
```
框架自带的表里 `core\` 排在 `core\console\` / `core\traits\` 之前，只是因为 `core/` 目录下恰好也有 `console/`、`traits/` 才「碰巧能加载」。任何后加的更具体前缀都会被静默忽略。

**复现**
```php
\core\Loader::addNamespace('zzz\\',      $tmp . '/ns/aaa');
\core\Loader::addNamespace('zzz\\deep\\', $tmp . '/ns/bbb');
// {tmp}/ns/aaa/deep/Thing.php 里定义 namespace zzz\deep; class Thing
\core\Loader::autoload('zzz\\deep\\Thing');
```
**真实输出**
```
-- L8 前缀匹配顺序 --
  zzz\      => ...\ns\aaa
  zzz\deep\ => ...\ns\bbb
  载入 zzz\deep\Thing：strncmp('zzz\','zzz\deep\Thing',4)==0 -> 先命中 'zzz\' 前缀
  结果: FROM-SHORT-PREFIX          ← 加载的是 ns/aaa 下的文件，而不是 ns/bbb
```
`addNamespace('zzz\deep\', …)` 被静默吞掉，没有任何警告。开发者以为映射生效了，实际所有 `zzz\deep\*` 类都从错误的目录加载。

**建议修复**：匹配前按前缀长度降序排序
```php
$prefixes = self::$prefixes;
uksort($prefixes, fn ($a, $b) => strlen($b) <=> strlen($a));   // 最长前缀优先
foreach ($prefixes as $prefix => $path) { ... }
```
（`addNamespace()` 里也应对已存在的键就地更新而非依赖插入顺序。）

**级别**：MEDIUM

---
```
PHP 对 `<input type="file" name="docs[]" multiple>` 会把 `$_FILES['docs']` 展开成**二维数组** `['error'=>[...],'name'=>[...],...]` 或 `[0=>[...],1=>[...]]`。两种形态下 `$this->files[$key]['error']` 都不是单个 int。

**复现**
```php
$_FILES = ['docs' => [
    ['error'=>UPLOAD_ERR_OK,'name'=>'a.txt','tmp_name'=>'C:/tmp/aaa','size'=>10,'type'=>'text/plain'],
    ['error'=>UPLOAD_ERR_OK,'name'=>'b.txt','tmp_name'=>'C:/tmp/bbb','size'=>20,'type'=>'text/plain'],
]];
$r = new \core\Request();
$r->hasFile('docs');
$u = $r->file('docs');
$u->getSize();
```
**真实输出**
```
---- F12 hasFile("docs") 多文件字段
    [PHP warning] Undefined array key "error"
true
---- F12b file("docs") 得到的 Upload
core\Upload
---- F12d $u->getSize()
0                      ← 真实是 10 / 20，被静默吞成 0
```
`hasFile()` 先吐一条 PHP Warning 再返回 `true`；`file()` 返回的 `Upload` 里 `getSize()` 恒为 0、`getExtension()` 恒为 `''`，开发者却拿到一个合法的 `Upload` 对象。任何基于 size/扩展名的上传校验都会被静默绕过。

**另一条崩溃路径**（首个字段结构异常时）
```
---- F12g file() 首个字段结构异常
TypeError: core\Upload::__construct(): Argument #1 ($file) must be of type array, string given,
called in ...\app\core\Request.php on line 408
```

**建议修复**：识别二维结构并提供 `files(string $key): array<Upload>`；`file()` 对多文件字段应抛明确异常或返回 `null`，而不是返回半残对象。
```php
public function hasFile(string $key): bool
{
    if (!isset($this->files[$key])) { return false; }
    $f = $this->files[$key];
    $err = $f['error'] ?? (is_array($f[0] ?? null) ? null : null);
    if (is_array($err)) { throw new \LogicException("字段 {$key} 是多文件上传，请改用 files()"); }
    return ($err ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
}
```

**级别**：MEDIUM

---

### [18] MEDIUM — `Macroable::mixin()` 对「带公开构造器的类」和「带参方法」必崩

**文件 / 行号**：`app/core/traits/Macroable.php:45-62`

**问题代码**
```php
public static function mixin(object $mixin, bool $replace = true): void
{
    $methods = (new \ReflectionClass($mixin))->getMethods(\ReflectionMethod::IS_PUBLIC);  // ← 收录 __construct
    foreach ($methods as $method) {
        $name = $method->getName();
        if (! $replace && static::hasMacro($name)) { continue; }
        $closure = $mixin->{$name}();        // ← 无条件以 0 参数调用
        static::macro($name, $closure);      // ← callable 类型，null 直接 TypeError
    }
}
```
两个独立缺陷：(a) `getMethods(IS_PUBLIC)` **会收录显式声明的 `public function __construct`**，调用它返回 `null`，`macro()` 的 `callable` 参数立刻 TypeError；(b) 所有方法都以 **0 个参数**调用，任何需要参数的 mixin 方法抛 `ArgumentCountError`。Laravel 的实现会用 `ReflectionMethod::getNumberOfParameters() > 0` 判断并把 `$mixin` 自身传进去，本实现没有这一步。

**复现**
```php
class MixinExplicitCtor {
    public function hello(): \Closure { return fn() => 'hi'; }
    public function __construct() {}
}
class MixinWithArgs3 {
    public function greet(string $who): \Closure { return fn() => "hi $who"; }
}
\MTarget::mixin(new MixinExplicitCtor());
\MTarget::mixin(new MixinWithArgs3());
```
**真实输出**
```
B0: getMethods(IS_PUBLIC) 是否收录 __construct
  MixinExplicitCtor: hello, __construct        ← 收录了
  anonymous:         hello
  MixinImplicitCtor: hello                     ← 隐式构造器不收录

---- B1a mixin(new MixinExplicitCtor)
TypeError: MTarget::macro(): Argument #2 ($macro) must be of type callable, null given,
called in ...\app\core\traits\Macroable.php on line 60
---- B1b mixin(anonymous class)        ok
---- B1c mixin(new MixinImplicitCtor)  ok
---- B2b mixin(MixinWithArgs3) 隐式构造器 + 带参方法
ArgumentCountError: Too few arguments to function MixinWithArgs3::greet(), 0 passed
in ...\app\core\traits\Macroable.php on line 58 and exactly 1 expected
```

---

### [19] MEDIUM — `Macroable::__call` 遇到静态闭包宏 → `Error: Value of type null is not callable`

**文件 / 行号**：`app/core/traits/Macroable.php:97-112`（`__call`）、`:124-139`（`__callStatic`）

**问题代码**
```php
$macro = static::$macros[$method];
if ($macro instanceof \Closure) {
    $macro = $macro->bindTo($this, static::class);   // ← 静态闭包 bindTo 返回 null
}
return $macro(...$args);
```
`Closure::bindTo($object, ...)` 在 `$object !== null` 且闭包为 static 时返回 `null` 并抛 Warning。宏被调用方用 `static fn () => …` 注册（相当常见，因为宏内部不需要 `$this`）就必然触发。

**复现**
```php
\MTarget::macro('staticMacro', static fn() => 'static-closure-result');
(new \MTarget())->staticMacro();
```
**真实输出**
```
---- B4 __call with static closure

Warning: Cannot bind an instance to a static closure in ...\app\core\traits\Macroable.php on line 108
Error: Value of type null is not callable
---- B5 __callStatic with static closure
static-closure-result                      ← __callStatic 路径正常（bindTo(null, ...) 合法）
```
**建议修复**
```php
if ($macro instanceof \Closure && !(new \ReflectionFunction($macro))->isStatic()) {
    $macro = $macro->bindTo($this, static::class) ?? $macro;
}
```

**级别**：MEDIUM

---

### [20] MEDIUM — `Macroable` 的宏表在兄弟子类间互相污染，`SubB::flushMacros()` 会清掉 `SubA` 的宏

**文件 / 行号**：`app/core/traits/Macroable.php:21`、`:31-33`、`:70-73`、`:80-83`

**问题代码**
```php
protected static array $macros = [];      // trait 静态属性：同一继承树下所有类共用同一份存储
public static function macro(string $name, callable $macro): void { static::$macros[$name] = $macro; }
public static function flushMacros(): void { static::$macros = []; }
```
`static::$macros` 在未被子类重新声明时指向**同一块存储**。这正是 `JsonResource::resolveWrap()`（`JsonResource.php:85-113`）专门写了一大段注释去规避的问题（「任一子类设置 `$wrap = 'items'` 会同时污染其父类与所有兄弟类（实测 A 设 items 后，B/C 也变成 items）」），但 `Macroable` 完全没做同样处理。

**复现**
```php
class MTarget { use \core\traits\Macroable; }
class SubA extends \MTarget {}
class SubB extends \MTarget {}
\SubA::macro('onlyA', fn() => 'A');
var_dump(\SubA::hasMacro('onlyA'), \SubB::hasMacro('onlyA'));
\SubB::flushMacros();
var_dump(\SubA::hasMacro('onlyA'));
```
**真实输出**
```
B6 SubA::hasMacro('onlyA')=true  SubB::hasMacro('onlyA')=true
B6 after SubB::flushMacros(): SubA::hasMacro('onlyA')=false
```
（A 从未调用过 `flushMacros()`，宏却被 B 清掉了。）

**框架内当前的实际影响**：`Request` 与 `Response` 各自 `use Macroable;` 且都不是子类，二者的 `$macros` 存储是隔离的（实测 `Request::hasMacro('onlyA')=false  Response::hasMacro('onlyA')=false`）。所以这是一个**尚未爆发但已成形的 API 陷阱**：任何第三方对 `Request`/`Response` 做子类化（如 `ApiRequest extends Request`）并注册宏，就会在整棵继承树内互相污染，且 `flushMacros()` 的影响范围完全不可预期。

**建议修复**：采用与 `JsonResource::resolveWrap()` 相同的「按声明类隔离存储」策略（`self::$store[static::class][$name]`，`flushMacros()` 只 `unset(self::$store[static::class])`）。若为兼容 Laravel 语义而保留共享行为，至少应把 `flushMacros()` 的文档注释改为「会清空整条继承链的宏」。

**级别**：MEDIUM

---
  {tmp}/ns/coreEvil/X.php        class core\X       ← 与前缀 "core" 共享字符串前缀的兄弟目录
  {tmp}/secret/Flag.php          class secret\Flag

\core\Loader::addNamespace('core\\', $tmp . '/ns/core');
\core\Loader::autoload('core\\..\\coreEvil\\X');     // 类名里的 '..' 被拼成真实路径
```
**真实输出**
```
L2 前缀 realpath     = 'C:\...\Temp\lp_audit4\loader_30936\ns\core'
L2 目标文件 realpath = 'C:\...\Temp\lp_audit4\loader_30936\ns\coreEvil\X.php'
L2 str_starts_with(realFile, realBase) = true
---- L3 穿越到共享前缀的兄弟目录 core\..\coreEvil\X
ESCAPED-TO-SIBLING                        ← 成功 require 了 base 目录之外的文件
---- L4 穿越到完全无关目录 core\..\..\secret\Flag
not loaded (被正确拦截)                    ← 无共同前缀时确实挡住了
```
**与历史报告的关系**：`CHANGELOG.md:694` 记录了「Loader 路径穿越」并声称用 realpath 修复；`.comate/audit/*` 也据此判定「已被前缀匹配挡住」。该结论**只在目标目录与 base 无字符串前缀关系时成立**——只要存在 `core` / `coreEvil` 这样的兄弟目录就会漏判（上一轮结论部分误报，此处给出精确边界）。

**可利用性**：需要 (a) 应用存在与命名空间目录同前缀的兄弟目录（`app/core` 与 `app/coreEvil`、`app/model` 与 `app/modelHelpers` 等，实践中很常见）；(b) 类名来自可控输入（`new $className`、`class_exists($userInput)`、`Container::get($userInput)` 等动态解析路径）。二者同时满足即可加载命名空间根目录之外的 PHP 文件。

**建议修复**
```php
$realBase = rtrim((string) realpath($path), '/\\') . DIRECTORY_SEPARATOR;
$realFile = realpath($file);
if ($realFile === false || !str_starts_with($realFile, $realBase)) { continue; }
```
（同时建议在拼 `$file` 之前就拒绝 `$relativeClass` 中的 `.` / `..` 段。）

**级别**：MEDIUM

---

### [21] MEDIUM — `Throttle` 计数文件永不回收，每个 (IP, path) 组合永久占用一个文件

**文件 / 行号**：`app/middleware/Throttle.php:77-125`（`attempt()`）、`:127-141`（`retryAfter()`）、`:146-157`（`clear()`）

**问题代码**
```php
private function getCacheFile(string $key): string { return $this->storagePath . $key . '.data'; }
// attempt() 只在「同一 key 再次命中」时覆写文件；窗口过期不会删除文件
// clear() 只在开发者显式调用 Throttle::clear($ip) 时才 glob + unlink
```
`attempt()` 在检测到 `expire <= time()` 时只是把计数重置为 1 并**重新写入同一个文件**，从不 `unlink`。而 key 是 `throttle_{sha256(ip)}_{sha256(path)}.data`，每个唯一 (IP, path) 对生成一个独立文件。IP 与路径空间都由攻击者可控（IPv6 地址空间尤其大）。

**复现**
```php
$t1 = new \middleware\Throttle(5, 1);      // 1 秒窗口
$t1->handle($req, fn() => 'OK');
sleep(2);
$t1->handle($req, fn() => 'OK');
```
**真实输出**
```
-- G10: 过期后的计数文件是否被回收 --
  窗口内: 文件存在=true 内容={"attempts":1,"expire":1791163431}
  窗口过期后再请求一次: OK
  过期后文件仍存在=true 内容={"attempts":1,"expire":1791163433}
  => 过期计数文件从不删除，只有再次命中同一 key 时才被覆写

-- G11: 唯一 (ip,path) 组合会永久占用一个文件 --
  5 个 (ip,path) 组合 => 文件数=6
  窗口过期后文件数=6 (无任何回收机制)
```
另注：该目录是 `STORAGE_PATH . '/cache/'`，与框架 Cache 的 `tag_*.json` 共用（实测目录内容里两者混在一起），没有任何组件会清理 `throttle_*.data`。CC 攻击只需轮换源 IP 即可无限增长。

**建议修复**：加独立清理命令（`bin/console throttle:clear --expired`）或在 `attempt()` 里做概率触发的 `gcExpiredFiles()`；并把文件目录从 `storage/cache/` 迁到 `storage/throttle/` 与 Cache 隔离。

**级别**：MEDIUM（磁盘耗尽型 DoS）

---

### [22] MEDIUM — `Cors::sanitizeOrigin()` 解析失败时 403 拒绝分支被整体跳过

**文件 / 行号**：`app/middleware/Cors.php:112-118`（`sanitizeOrigin`）、`:84-87`（拒绝判断）

**问题代码**
```php
// 84-87
if ($origin !== '' && !$this->isOriginAllowed($origin) && !$wildcardWithCredentials) {
    http_response_code(403);
    return '';
}
```
```php
// 112-118 —— 解析失败时返回空串
private function sanitizeOrigin(string $origin): string
{
    $origin = str_replace(["\r", "\n", "\0"], '', $origin);
    if ($origin !== '' && !preg_match('#^https?://[^\s/]+(:\d+)?$#', $origin)) {
        return '';                       // ← 用「空串」同时表达「无 Origin」和「Origin 非法」
    }
    return $origin;
}
```
`sanitizeOrigin()` 用同一个 `''` 同时表示「请求根本没有 Origin 头」和「Origin 头格式非法」。而第 84 行的拒绝条件恰好以 `$origin !== ''` 为前提——于是**任何不符合该正则的 Origin 都会跳过 403 检查、直接放行到 `$next($request)`**。

**复现（真实 HTTP，`cors_endpoint.php`，白名单 `['https://a.com']`）**
```bash
$ curl -s -i -H "Origin: https://a.com/" http://127.0.0.1:8794/
```
**真实输出**
```
HTTP/1.1 200 OK
Access-Control-Allow-Credentials: true
Access-Control-Expose-Headers: X-Total
Content-type: text/html; charset=UTF-8

---

### [23] MEDIUM — `JsonResource::additional()` 的同名键会静默覆盖包装键，资源数据整体丢失

**文件 / 行号**：`app/core/JsonResource.php:136-147`

**问题代码**
```php
if ($wrap === null) { return array_merge($data, $meta); }
return array_merge([$wrap => $data], $meta);   // ← $meta 里同名键会覆盖整块资源
```
`additional()` 的语义是「追加顶层元数据」，但由于用 `array_merge` 且 `$meta` 排在后面，`additional(['data' => …])` 会把整个资源数组替换掉。

**复现**
```php
(new UserRes(['id'=>1]))->additional(['data' => 'OVERRIDDEN'])->resolve();
UserResItems::collection([['id'=>1]])->additional(['data' => 'OVERRIDDEN'])->resolve();
```
**真实输出**
```
---- J8 additional(["data"=>...]) 覆盖 wrap
{"data":"OVERRIDDEN"}                                  ← 资源数据彻底消失
---- J8b 集合模式 additional(["data"=>...])
{"items":[{"id":1}],"data":"OVERRIDDEN"}               ← 集合模式下反而共存（wrap 是 items）
```
**根因**：单资源模式与集合模式的合并顺序不一致（`[$wrap => ...]` 在前 vs 在后），且没有对保留键做保护。

**建议修复**
```php
if (isset($meta[$wrap])) {
    throw new \LogicException("additional() 不能覆盖保留的包装键 '{$wrap}'");
}
return array_merge([$wrap => $data], $meta);
```

**级别**：MEDIUM

---
**影响边界（务必区分）**：隐式构造器 + 无参方法的组合是好的（现有测试 `Macroable - mixin 不覆盖模式` 用匿名类，因此一直没暴露）。只要 mixin 类写了 `public function __construct(...)`（带配置/依赖的 mixin 几乎都会写），`mixin()` 100% 崩溃；只要有一个方法带参数，也 100% 崩溃。

**建议修复**
```php
foreach ($methods as $method) {
    $name = $method->getName();
    if ($name === '__construct' || str_starts_with($name, '__')) { continue; }
    if (! $replace && static::hasMacro($name)) { continue; }

    $closure = $method->getNumberOfParameters() > 0
        ? $method->invoke($mixin, $mixin)     // Laravel 行为：需要参数则传入 mixin 自身
        : $method->invoke($mixin);

    if (! is_callable($closure)) { continue; }
    static::macro($name, $closure);
}
```

**级别**：MEDIUM（文档公开 API，两类常见 mixin 写法直接 Fatal）

---

## 三、LOW 级缺陷

---

### [24] LOW — `HttpClient` 在 `json=false` + 数组 body 时发出字面量 `"Array"`

**文件 / 行号**：`app/core/HttpClient.php:104-115`
```php
$useJson = $opts['json'] ?? is_array($body);
if ($useJson) { ... } else { $bodyStr = (string) $body; }   // ← 数组强转
```
**真实输出**（服务端回显实际收到的 body）
```
---- E4 post(array body, json=false)

Warning: Array to string conversion in ...\app\core\HttpClient.php on line 113
{"method":"POST","body":"Array","ct":"application\/x-www-form-urlencoded"}
```
**修复**：`if (!is_scalar($body) && $body !== null) { throw new HttpClientException('...'); }`

---

### [25] LOW — `HttpClient` 的 `max_bytes` 超限时即便 `throw=false` 也抛异常，且已下载数据被丢弃

**文件 / 行号**：`app/core/HttpClient.php:143-154`（progressfunction）、`:180-185`

**真实输出**（本地 `/big` 端点返回 3 MB）
```
---- E6 max_bytes=1024 拉 3MB (throw=false)
core\HttpClientException: Callback aborted          ← 报错信息完全看不出是体积超限
---- E6b max_bytes=0 (关闭限制) 拉 3MB
status=200 len=3145731
---- E6c 默认 max_bytes 拉 3MB (默认 5MB)
status=200 len=3145731
```
两个问题：(a) 错误消息 `Callback aborted` 无诊断价值；(b) `max_bytes=0` 会**静默关闭**体积上限（与 `timeout=0` 同样的「0 表示无限制」陷阱）。

**修复**：捕获 `CURLE_ABORTED_BY_CALLBACK` 后抛带明确信息的异常；并把 `max_bytes <= 0` 视为非法配置而非「关闭限制」。

---

### [26] LOW — `Cors` 在 Origin 不被允许时仍发送 `Access-Control-Allow-Credentials: true`

**文件 / 行号**：`app/middleware/Cors.php:62-64`（无条件发送），对照 `:47-60`

**真实输出**（`cors_endpoint.php`，白名单 `['https://a.com']`）

---

### [27] LOW — `LocalDisk::url()` 不做任何路径清洗

**文件 / 行号**：`app/core/LocalDisk.php:80-86`
```php
public function url(string $path): string
{
    if ($this->urlPrefix === null) { throw new \RuntimeException(...); }
    return rtrim($this->urlPrefix, '/') . '/' . ltrim(str_replace('\\', '/', $path), '/');
}
```
`url()` 完全绕开了 `normalizePath()`（同一个类的私有路径校验，`LocalDisk.php:151-172`）。

**真实输出**
```
---- D5 url("../../secret.txt")
/uploads/../../secret.txt
---- D10 url("..\..\windows\win.ini")
/uploads/../../windows/win.ini
---- D10b url("/etc/passwd")
/uploads/etc/passwd
```
浏览器会把 `/uploads/../../secret.txt` 归一化成 `/secret.txt`。只要 `$path` 里有用户输入成分，生成的就是指向任意站内路径的链接。

**修复**：`url()` 内部先复用 `normalizePath($path, true)` 的词法检查，再对每段做 `rawurlencode`。
> 注：`.comate/audit/support.md:1597-1599` 已提出同一修复建议，本条为再次确认复现。

---

NEXT-RAN                     ← 处理函数被执行，且没有任何 ACAO 头
```
同批探测中返回 200（而非 403）的畸形 Origin：`https://a.com/`（末尾斜杠）、`HTTPS://A.COM`（大小写）、` https://a.com`（前导空格）、`https://evil.com/https://a.com`、`null`、`https://a.com\r\nX-Evil: 1`。返回 403 的则是能被正则接受的、白名单外的 `https://evil.com` 等——说明拦截与否完全取决于正则是否匹配，而非白名单。

**根因**：缺少「非法 Origin」与「无 Origin」的区分。

**影响评估**：浏览器发送的 `Origin` 一定是规范的 scheme+host+port，所以这条路径**不会被浏览器直接利用**；真正的损害是：`Cors` 是框架里唯一会返回 403 的 Origin 闸门，而它对畸形 Origin 完全不设防，同时开发者会看到「handler 跑了但没有 CORS 头」这种难以排查的现象。另外正则无 `i` 修饰符，导致 `HTTPS://` 这种合法 Origin 也走不通。

**建议修复**：让 `sanitizeOrigin()` 返回 `?string`（`null` = 无 Origin 头，`false` = 格式非法），并分开处理：
```php
$raw = $request->header('Origin');
if ($raw === null || $raw === '') { return $next($request); }      // 同源请求
$origin = $this->sanitizeOrigin($raw);
if ($origin === false) { http_response_code(403); return ''; }    // 畸形 Origin 直接拒
if (!$this->isOriginAllowed($origin) && !$wildcardWithCredentials) { http_response_code(403); return ''; }
```
并给正则加 `i` 修饰符、显式处理末尾斜杠。

**级别**：MEDIUM

---

### [28] LOW — `LocalDisk::put('')` 泄漏 PHP Warning；`normalizePath()` 的 `$isDir` 是死参数

**文件 / 行号**：`app/core/LocalDisk.php:24-32`、`:151-172`

**真实输出**
```
-- D11: put('') 空路径 --
---- D11 put("")
Warning: file_put_contents(C:\...\disk_32096/local): Failed to open stream: Permission denied
        in ...\app\core\LocalDisk.php on line 47
false
D12 normalizePath 方法体中出现 $isDir 的次数: 1  (仅签名 1 次 = 参数从未被使用)
```
`put('')` 会把 `root` 目录本身当成目标文件；`file_put_contents` 的 Warning 未被 `@` 抑制。另外 `normalizePath(string $path, bool $isDir = false)` 的 `$isDir` 在方法体内**一次都没被引用**（`files()`/`directories()` 传了 `true` 但没有任何效果），属于死参数。

**修复**：`put()` 开头加 `if (trim($path) === '' || trim($path) === '.') { return false; }`；删除 `$isDir` 参数。

---

### [29] LOW — `RequestLogMiddleware` 在控制器抛异常时把 5xx 记成 200

**文件 / 行号**：`app/middleware/RequestLogMiddleware.php:23-34`
```php
$result = null;
try { $result = $next($request); }
finally {
    $statusCode = 200;
    if (is_object($result) && method_exists($result, 'getStatusCode')) { $statusCode = $result->getStatusCode(); }
    else { $statusCode = http_response_code() ?: 200; }   // ← 异常路径下 $result 恒为 null
}
```
`finally` 在异常传播时 `$result` 仍是 `null`，落到 `http_response_code() ?: 200`；而此刻 `Response::send()` 还没执行，`http_response_code()` 返回的是默认值。

**真实输出**
```
-- M2 / N2 --
控制器抛异常时记录的行数=1
N2 控制器抛异常时记录的行: POST /api/user/login?... → 200 [0.01ms] [203.0.113.5]
                                                       ^^^^ 实际是 500
```
以响应码做告警/统计的日志管道会**完全看不到 5xx**。

**修复**
```php
$statusCode = 200;
try { $result = $next($request); }
catch (\Throwable $e) {

---

### [30] LOW — `JsonResource::collection()` 对「构造器有必填参数」或「抽象」的子类抛异常

**文件 / 行号**：`app/core/JsonResource.php:156-161`、`resolve()` 内的 `new static()` / `new static($item)`（`:130`）

**真实输出**
```
---- J10 collection() 对构造器有默认参数的子类
{"data":[{"id":"x"}]}
---- J10b collection() 对构造器必填参数的子类
ArgumentCountError: Too few arguments to function NeedsArg2::__construct(), 0 passed
in ...\app\core\JsonResource.php on line 158 and exactly 2 expected
---- J15 抽象子类 collection
Error: Cannot instantiate abstract class RAbs
```
`collection()` 无条件 `new static()` / `new static($item)`，不检查子类构造器签名，也不排除抽象类。

**修复**：`collection()` 里先检查 `isAbstract()`，并为子类提供 `makeForCollection($item)` 钩子供覆写实例化逻辑。

---

### [31] LOW — `Pipeline` 对「不存在的中间件类名」报「Invalid pipe type: string」，误导排障

**文件 / 行号**：`app/core/Pipeline.php:144-153`
```php
if (is_string($pipe) && class_exists($pipe)) { ... }
if (is_callable($pipe)) { ... }
if (is_object($pipe)) { ... }
throw new \RuntimeException('Invalid pipe type: ' . gettype($pipe));   // ← 拼错的类名落到这里
```
**真实输出**
```
---- I5 无效 pipe 类型
RuntimeException: Invalid pipe type: integer
---- I5b 不存在的类名字符串
RuntimeException: Invalid pipe type: string          ← 应为 "class not found"
---- I13 静态方法 "Class::method"
RuntimeException: Invalid pipe type: string          ← 'Class::method' 形式完全不支持（Laravel 支持）
```
**修复**
```php
if (is_string($pipe)) {
    throw new \RuntimeException(sprintf('Middleware class [%s] not found', $pipe));
}
```

---

### [32] LOW — `Middleware::shouldSkip()` 的通配模式 `api/*`（无前导斜杠）对任何真实 URI 永不匹配

**文件 / 行号**：`app/middleware/Middleware.php:23-29`
```php
if (str_contains($pattern, '*')) {
    $regex = preg_quote($pattern, '#');
    $regex = str_replace('\\*', '.*', $regex);
    if (preg_match('#^' . $regex . '$#', $uri)) { return true; }
}
```
`$uri` 始终保留前导 `/`，因此 `api/*` 编译出的 `^api/.*$` 永远匹配不上。

**真实输出**
```
-- M5 shouldSkip 通配匹配 --
  except=['api/*']   uri='/api/user'    -> skipped=false
  except=['api/*']   uri='/api/user/1'  -> skipped=false
  except=['api/*']   uri='/API/user'    -> skipped=false
  except=['api/*']   uri='/apix/y'      -> skipped=false
  except=['api/*']   uri='/api'         -> skipped=false
  except=['api/*']   uri='api/user'     -> skipped=true    ← 只有不带前导斜杠才命中

  except=['/api/*']  uri='/api/user'    -> skipped=true    ← 框架自带 OutputCache 用的就是这个写法
  except=['/api/*']  uri='/api/user/1'  -> skipped=true
  except=['/api/*']  uri='/API/user'    -> skipped=false   ← 大小写敏感（预期行为）
  except=['/api/*']  uri='/apix/y'      -> skipped=false   ← 不越界匹配（正确）
  except=['/api/*']  uri='/api'         -> skipped=false
  except=['/api/*']  uri='/api2'        -> skipped=false
```
框架自身的 `OutputCache`（`app/middleware/OutputCache.php:13`）写的是 `['/admin/*', '/api/*']`，带前导斜杠，因此安全。但开发者照直觉写 `api/*` 时，所有「跳过」规则会**静默失效**且无任何提示。

**修复**：比较前把 `$pattern` 也 `ltrim('/')` 并对 `$uri` 做同样处理，或在初始化时校验 pattern 是否以 `/` 开头并 `trigger_error()` 提示。

---
```bash
$ curl -s -i -H "Origin: https://evil.com" http://127.0.0.1:8794/
HTTP/1.1 200 OK
Access-Control-Allow-Credentials: true          ← Origin 不在白名单，凭证头照发
Access-Control-Expose-Headers: X-Total
```
按 Fetch 规范，缺少 `Access-Control-Allow-Origin` 时浏览器本就会拦截，故当前不可直接利用；但这是规范违规 + 信息面暴露。

**修复**：把 `:62-64` 挪进「Origin 已被允许」的分支内。

**对照（同一端点的正常路径，证明 CORS 头生成本身是对的）**
```bash
$ curl -s -i -H "Origin: https://a.com" http://127.0.0.1:8794/
HTTP/1.1 200 OK
Access-Control-Allow-Origin: https://a.com
Vary: Origin
Access-Control-Allow-Credentials: true
Access-Control-Expose-Headers: X-Total
NEXT-RAN
```

---

## 四、疑似（未验证）—— 仅靠代码阅读得出，未能构造出实际复现

> 以下条目**不计入缺陷统计**。列出是为了避免下轮重复投入，同时标明为什么没验证成功。

---

### S1. `LocalDisk::put()` 的 `mkdir` TOCTOU（并发首次写入同一新目录时可能误报失败）

- **文件**：`app/core/LocalDisk.php:24-32`
- **代码**
  ```php
  $dir = dirname($full);
  if (!is_dir($dir)) {
      if (!@mkdir($dir, 0755, true)) { return false; }   // ← 目录被别人先建好时 mkdir 返回 false
  }
  ```
  已确认 `mkdir(已存在目录, recursive=true)` 在本环境返回 `false`（实测），因此 `is_dir()` 与 `mkdir()` 之间被并发抢跑时会把**本可成功的写入**判为失败。
- **未能复现的原因**：用 48 个进程在同一绝对时刻（`microtime(true)` 自旋屏障）起跑、全部写同一个尚不存在的 `shared/deep/file.txt`，**0 次失败**：
  ```
  -- D6b: 48 进程同步并发 \core\Storage::put() 到同一个新目录 --
    成功 put: 48   失败 put(false): 0   进程总数: 48
    => 目标文件最终是否存在: true
  ```
  也尝试用 PHP stat 缓存构造确定性复现（先 `is_dir()` 得到 false，再由另一段代码 `mkdir()`，然后在同一进程里调 `put()`），实测 `mkdir()` 会清掉该路径的 stat 缓存，`put()` 依旧成功：
  ```
  is_dir($target) 第一次            = false
  目录现在真实存在                   = true
  Storage::put('b/c.txt') 返回       = true
  文件最终是否写入                   = true
  ```
- **结论**：竞态窗口客观存在（Windows 上 PHP 进程启动开销远大于 `is_dir()+mkdir()` 之间的窗口），但在本地环境**无法稳定触发**，不作为已验证缺陷。
- **建议（低成本的防御性修复，仍值得做）**
  ```php
  if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) { return false; }
  // ↑ 第二次 is_dir 兜住「别人刚建好」的情况
  ```

---

### S2. `Response::header()` 不过滤 NUL 字节，NUL 是否会被 SAPI 截断未验证

---

### S3. `HttpClient` 未设置 `CURLOPT_CONNECTTIMEOUT`，仅靠 `CURLOPT_TIMEOUT` 覆盖连接阶段

- **文件**：`app/core/HttpClient.php:117-124` —— 只设了 `CURLOPT_TIMEOUT`（连接+传输总时长）。
- **未验证的原因**：需要一个「TCP 连接建立后不返回任何字节」的端点来区分连接超时与总超时；本轮本地 `php -S`（单进程串行）无法构造可靠的半开连接场景。
- **建议**：补上 `CURLOPT_CONNECTTIMEOUT = min($timeout, 5)`，属于零风险的加固。

---
    $statusCode = $e instanceof \core\HttpException ? $e->getStatusCode() : 500;
    throw $e;
} finally { /* 使用 $statusCode */ }
```

---

## 五、已验证正确的重要逻辑

> 这些是本轮逐方法读过、并**实际跑过验证**的关键路径，记录下来避免下轮重复怀疑（历史上这批逻辑曾多次被误报）。

---

### 5.1 `core\Pipeline` —— 洋葱模型全链路正确

| 验证点 | 实测输出 |
|---|---|
| 洋葱进出顺序 | `{"result":"REQ-dest+closure","trace":["in","out"]}` |
| **短路语义**（中间件不调 `$next` 时，外层后半段仍执行、内层不执行） | `A-in -> B-short-circuit -> A-out` |
| 异常穿透到外层并被捕获 | `CAUGHT:RuntimeException` |
| 嵌套 Pipeline | `outer[inner(X)]` |
| 同一实例复用 `then()` 两次 | `["A1","A2"]` |
| `via('process')` 自定义方法名 | `P:X` |
| 空管道 `thenReturn()` | `P` |
| 容器解析字符串中间件 | `from-container>X`；无容器时 `default>X` |
| 缺 `handle` 方法 | `RuntimeException: Middleware [PNoMethod] missing method [handle]` |
| 数组可调用 `[obj,'method']` | `arr(X)` |
| **能接受中间件实例对象**（与 Router 不一致，见缺陷 1） | `Pipeline 结果类型: core\Request` |

---

### 5.2 `middleware\Throttle` —— 并发计数与时间窗口正确

20 个进程 × 每人 5 次请求同步并发，配额 30：
```
-- G9: 20 进程 x 5 请求并发, maxAttempts=30 (期望恰好 30 次通过) --
  进程数=20  实际放行=30  实际拒绝=70  (配额 30)
  计数文件内容: {"attempts":30,"expire":1791163473}
```
**恰好 30 次放行，`attempts` 精确停在 30** —— `fopen('c+')` + `flock(LOCK_EX)` 的读-改-写是原子的，`finally` 里的 `LOCK_UN`/`fclose` 在 `return false` 路径也执行。补充验证：
```
  第 1 次: OK   第 2 次: OK   第 3 次: OK   第 4 次: 429   第 5 次: 429
  429 body: {"code":429,"message":"Too Many Attempts. Please try again in 60 seconds.","data":{"retry_after":60}}
  Retry-After 头: '60'
  缺 REMOTE_ADDR 时 ip() = '0.0.0.0'                   ← 兜底正确
  未设 trustedProxies, 带 XFF 时 ip() = '203.0.113.5'   ← 伪造 XFF 无效
  设 trustedProxies 后 ip() = '1.1.1.1'                 ← 仅 REMOTE_ADDR 命中白名单时才采信 XFF 最左项
  maxAttempts=0 时 5 次全部通过: 5/5                    ← ≤0 时 fail-open 合理
```
唯一缺陷是文件不回收（缺陷 21），**计数逻辑本身没有问题**。

---

### 5.3 `core\LocalDisk` / `core\Storage` —— 路径穿越被正确拦截

```
---- D3 exists("../../etc/passwd")
InvalidArgumentException: Path traversal is not allowed: ../../etc/passwd
---- D4 get("../../x")
InvalidArgumentException: Path traversal is not allowed: ../../x
---- D1 put("a/b.txt")   true
---- D2 get("a/b.txt")   hello
---- D13 local.url() 未配置 url
RuntimeException: Disk has no public URL configured for path: a/b.txt
---- D14 disk("nope")   InvalidArgumentException: Storage disk [nope] is not configured.
---- D15 未知驱动 s3     InvalidArgumentException: Unsupported storage driver [s3] for disk [x].
---- D9b files("dd")     array ( 0 => 'dd/1.txt', 1 => 'dd/2.txt' )
---- D9c directories("") array ( 0 => 'a', 1 => 'dd' )
```
`normalizePath()` 的词法检查（剥离 `.`、遇到 `..` 直接抛）对 `get/put/exists/delete/files/directories` **全路径生效**，且不依赖 `realpath()`，因此对尚未创建的文件同样有效。**上一轮「LocalDisk 路径穿越」的怀疑是误报** —— 真正有问题的只有绕过检查的 `url()`（缺陷 27）。

---

### 5.4 `middleware\Cors` —— Origin 白名单的核心判定正确

构造期的 `* + credentials` 冲突校验（`Cors.php:26-32`）有效：
```
---- K4 new Cors(* + credentials)
InvalidArgumentException: Cors: "allowed_origins: *" cannot be used together with "supports_credentials: true".
```
真实 HTTP 下的正常路径（CORS 头本身没问题）：
```
$ curl -s -i -H "Origin: https://a.com" http://127.0.0.1:8794/
Access-Control-Allow-Origin: https://a.com
Vary: Origin
Access-Control-Allow-Credentials: true
Access-Control-Expose-Headers: X-Total
```
畸形 Origin 变形逐项测试 —— **没有任何一种变形能骗过白名单**（要么被 403 拒绝，要么因正则不匹配而无 ACAO 头，浏览器仍会拦截，见缺陷 22）：
```
Origin='https://a.com.evil.com'      → 403    Origin='https://a.com#@evil.com' → 403
Origin='https://a.com@evil.com'       → 403    Origin='https://a.com:8080'     → 403
```
`sanitizeOrigin()` 的 CRLF/NUL 剥离有效，不会造成响应头注入。

---
- **文件**：`app/core/Response.php:144-150`（只 `str_replace(["\r","\n"], '', …)`，未处理 `"\0"`）
- **实测**：值里的 `\0` 被完整保留进 headers 数组
  ```
  ---- H4c header 值含 NUL
  array ( 'Content-Type' => 'text/html; charset=utf-8', 'X-A' => 'a' . "\0" . 'b' )
  ```
- **未验证部分**：`header()` 在真实 SAPI 下对含 NUL 的值是截断、报错还是原样发出，需要真实 HTTP 响应才能判定；本轮未构造该用例。理论上最坏情况也只是头值被截断（不会变成新头），风险有限。
- **建议**：顺手把 `"\0"` 加进 `Response::header()` / `withHeaders()` 的替换字符集，与 `HttpClient` 侧的修复（缺陷 8）保持对称。

---

### 5.5 `core\Request` —— IP / HTTPS / 头归一化正确

```
未设 trustedProxies, 带 XFF 时 ip() = '203.0.113.5'   ← 默认不信任 XFF（CHANGELOG 里的 CRITICAL 已修复）
设 trustedProxies 后 ip() = '1.1.1.1'                 ← 仅 REMOTE_ADDR 命中白名单时才采信，且用 filter_var 校验
header("x_custom") → 'v1'   header("X-CUSTOM") → 'v1'  ← 头名归一化正确，查找与存储共用同一方法
isSecure() with HTTPS=off → false；无 HTTPS → false    ← 'off' 字符串处理正确
```
CLI 下 `php://input` **不会**吞掉 STDIN（曾怀疑会，实测不会）：
```
raw() = ''
随后从 STDIN 读到的内容 = 'line1-from-pipe
line2-from-pipe
'
```
`input()` / `all()` 的优先级链（POST > JSON > GET）三处一致：
```
F5 all()    array ( 'a' => 'G', 'b' => 'P' )
F6 post(null) array ( 'b' => 'P' )
F7 has()    array ( 0 => true, 1 => true, 2 => false )
F10a body="{}" → isJson()=true      F10c body="null" → isJson()=false
F10g ct=form 但 body 是 json          → isJson()=false  ← Content-Type 判定正确
F10h 非法 json                        → isJson()=false
```

---

### 5.6 `core\Response` —— 边界值处理正确

```
---- H1  new Response("", 99) / 600        → InvalidArgumentException（状态码区间校验）
---- H1c status(0) / H1d json(...,0)       → InvalidArgumentException
---- H2  json 非法 UTF-8 / 含资源          → {"error":"JSON encoding failed"}  ← 降级而非抛异常
---- H3b redirect("//evil.com")            → 拒绝（开放重定向防护有效）
---- H3c redirect("http://x")               → 拒绝
---- H3d redirect("/a\\b")                  → 拒绝
---- H3e redirect("/a%0d%0aX-Evil:%201")   → Location 原样保留（编码态，浏览器不会解析为新头）
---- H4  header 值含 CRLF → '1X-Evil: yes'  ← 两段被拼接而非分成两个头（清洗正确，无注入）
---- H4b header 名含 CRLF → 'X-AX-Evil'
---- H5c download("php://filter/...")       → InvalidArgumentException: Unsupported file path scheme
---- H5d download(不存在)                   → InvalidArgumentException: File not found
---- H5f download($file,'../../etc/passwd') → filename="passwd"   ← basename 生效
---- H7  json 响应不加 CSP；html 响应加 CSP  ← 安全头按 Content-Type 分流正确
---- H9b 调不存在宏                          → BadMethodCallException（而不是静默）
```

---

### 5.7 `core\JsonResource` —— `$wrap` 静态属性污染已被正确规避

`resolveWrap()`（`JsonResource.php:95-113`）按「子类是否显式声明了自己的 `$wrap`」逐层回溯，实测六条路径全部正确：
```
---- J1 默认 wrap                     {"data":{"id":1,"name":"Tom"}}
---- J2 子类 wrap=items 单资源         {"items":{"id":1}}
---- J3 子类 wrap=items 集合           {"items":[{"id":1},{"id":1}]}   ← 集合模式也用了 items
---- J4 wrap=null 单资源               {"id":1}
---- J5 wrap=null 集合                 [{"id":1}]
---- J6 孙类继承父类的 wrap             {"items":{"a":1}}
---- J13 JsonSerializable 资源          {"data":{"s":1}}
```
唯一缺陷是 `additional()` 的同名键覆盖（缺陷 23）。

---

### 5.8 `core\Facade` —— 静态状态未出现跨子类污染

`Facade::$container` / `$resolved` 声明在抽象基类上并用 `static::` 访问，实测**各子类共享同一份容器**（不是上一轮担心的「每个子类各自一份、另一个子类拿不到」）：
```
---- C1 Fa1::ping() (只给 Fa1 设置容器)        pong
---- C2 Fa2::ping() (Fa2 未单独设置容器)        pong2      ← 容器是共享的
---- C5 Fa1::ping() (clearResolved 后)          REBOUND
---- C6 Fa2::ping() after Fa1::clearResolved()  pong2
```

---

### 5.9 `middleware\CsrfMiddleware` —— 核心校验正确

```
-- M3 CsrfMiddleware --
  未启动会话 + 任意 token: HTTP 419 {"code":419,"message":"CSRF token mismatch"}
  OPTIONS 走 CsrfMiddleware: NEXT-RAN
```
- `hash_equals()` 做时序安全比较（`CsrfMiddleware.php:28`）
- `GET/HEAD/OPTIONS` 直接放行（`:21-23`）
- 会话未启动 / token 为空 / 两侧为空字符串，都被同一条件拒绝（`:28`）
- `post('_token')` 会同时查 POST 与 JSON 体（`Request.php:135-150`），因此 JSON 请求也能带 token
- 验证通过后**不轮换** token —— 有意为之且有测试覆盖（`tests/run_tests.php:2495-2510`），连续 AJAX 不会莫名 419

---

### 5.10 `middleware\Middleware::shouldSkip()` —— 通配不越界

```
except=['/api/*']  uri='/apix/y'   → false
except=['/api/*']  uri='/api2'     → false
except=['/api/*']  uri='/API/user' → false   （大小写敏感，符合预期）
```
`*` 被编译成 `.*`，其余部分经 `preg_quote`，且带 `$` 锚点，**没有把 `/api` 前缀过度匹配到 `/apix`** 的问题。正则长度受模式串本身长度限制，也不存在用户可控的超长 `.*`。

---

## 六、复现脚本清单

全部位于 `sys_get_temp_dir()\lp_audit4\`（**仓库内未创建任何文件**，`git status --porcelain` 为空）：

| 脚本 | 覆盖内容 |
|---|---|
| `boot.php` | 公共引导：常量定义、`Loader::register()`、`show()` 辅助函数 |
| `r01_shouldskip.php` | 缺陷 5：`shouldSkip()` vs `Router::normalizeUri()` |
| `r02_macroable.php` | 缺陷 18/19/20：`mixin()` 崩溃、静态闭包、兄弟子类污染 |
| `r03_facade.php` | Facade 静态共享（第 5.8 节） |
| `r04_disk.php` | 缺陷 27/28、S1：Storage/LocalDisk |
| `w_throttle.php` + `r05_throttle.php` | 缺陷 21、第 5.2 节：Throttle 并发与文件回收 |
| `server2.php` / `cors_endpoint.php` / `router_endpoint.php` / `mw.php` | 缺陷 1/2/5/22/26：真实 HTTP 的 CORS 与中间件行为 |
| `r06b_httpclient.php` | 缺陷 6/7/24/25：HttpClient 超时、JSON 编码、max_bytes |
| `r06d_hdr.php` | 缺陷 8：请求头注入 |
| `r07_request.php` | 缺陷 9/10/12/13：Request 边界值与类型混淆 |
| `r08_response.php` | 缺陷 14、第 5.6 节：Response 边界值 |
| `r09_pipeline.php` | 第 5.1 节 + 缺陷 31：Pipeline 洋葱模型 |
| `r10_jsonresource.php` | 缺陷 23/30、第 5.7 节：JsonResource |
| `r11_cors.php` | 缺陷 3/22/26、CORS Origin 变形测试 |
| `r12_loader.php` | 缺陷 16/17：Loader 路径校验与前缀顺序 |
| `r13_misc.php` + `r13b_stdin.php` | 缺陷 15/29、缺陷 32、`php://input` CLI 行为 |
| `r14_final.php` | 缺陷 1/3/4/11/29：Router 对象中间件、OPTIONS 拦截、带参别名、query 泄漏 |

**基线未受影响**：`php tests/run_tests.php` → `Results: 1084/1084 passed`（审计前后一致）。

---

## 七、给下一轮的重点提示

1. **`core\Router::executeMiddleware()` 是本轮最值得修的地方**：它同时缺对象分支（缺陷 1）、OPTIONS 抢跑（缺陷 2）、带参别名解析（缺陷 4），三处都在同一个 40 行方法里。
2. **`Cors` 需要一次「配置归一化 + 语义分层」的重构**：`sanitizeOrigin()` 用 `''` 混淆「无 Origin」与「非法 Origin」（缺陷 22）、构造函数不合并默认值（缺陷 3）、凭证头无条件发送（缺陷 26）——三者同源。
3. **`HttpClient` 的所有数值选项都缺少下界/类型校验**：`timeout=0`（缺陷 6）与 `max_bytes=0`（缺陷 25）是同一个反模式；`json_encode` 失败被 `?: ''` 吞掉（缺陷 7）也是同一类。
4. **两条路径清洗的实现必须对称**：`LocalDisk::url()` 绕过了 `normalizePath()`（缺陷 27），`Response::header()` 少过滤 `\0`（S2），`HttpClient::normalizeHeaders()` 少过滤 `\r\n`（缺陷 8）。建议抽一个共享的 `sanitizeHeader()`。
5. **静态状态污染的三处处理不一致**：`JsonResource` 已修（`resolveWrap()`）、`Macroable` 未修（缺陷 20）、`Facade` 本来就正确。建议统一到 `JsonResource` 的做法。

---
