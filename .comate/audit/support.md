# LightPHP 支撑层（Support Layer）安全与质量审计报告

- **审计范围**：`app/core/{Validate,FormRequest,helpers,Session,Cookie,Env,Collection,EventDispatcher,Facade,Loader,Hash,Captcha,Upload,Storage,Disk,LocalDisk,Generator,HttpClient,HttpResponse,HttpClientException,ApiDoc,JsonResource,Application}.php`、`app/core/traits/Macroable.php`、`app/core/console/{Console,Command}.php`、`app/config/{Config,app,cache,database,storage}.php`、`app/middleware/*.php`、`app/log/Logger.php`、`app/cache/CacheManager.php`
- **审计基线**：分支 `cline/55bfe`（HEAD `f2dd2a2`），PHP 8.4.7 (cli) NTS
- **方法**：全量通读 + 针对性 PoC 实测。标注「实测」的结论均已用脚本在本地复现并附输出。
- **原则**：本报告只提出修改建议，未改动任何被审计文件。

## 0. 结论摘要

| 级别 | 数量 | 代表问题 |
|------|------|----------|
| CRITICAL | 1 | 执行 `config:cache` 后全站 Web 请求直接 403 空白（CF-01） |
| HIGH | 15 | Logger 记日志自身抛 TypeError、Loader 目录越界、Validate 数组类型混淆、Upload 多文件 TypeError / `.user.ini` / 强制写入 web 根、OutputCache 缓存污染与 Set-Cookie 落盘、HttpClient 无协议限制与 TLS/超时缺配、Cors 缺 `Vary: Origin`、CSRF token 不轮换、Middleware 路径归一化不一致、Cookie 无签名、JsonResource 静态属性全局污染 |
| MEDIUM | 79 | 见第 3 节（Validate 12 / FormRequest 2 / Session 3 / Cookie 2 / Env 2 / Config 3 / Hash 1 / Captcha 3 / Upload 4 / Storage 4 / EventDispatcher 4 / Collection 7 / Facade 1 / Loader 2 / Macroable 1 / Console 5 / Generator 4 / Middleware 5 / Cors 3 / Csrf 3 / OutputCache 5 / Throttle 3 / RequestLog 2 / Logger 3 / CacheManager 1 / HttpClient 2 / ApiDoc 2 / JsonResource 2） |
| LOW | 23 | 见第 4 节 |

**建议修复顺序**：CF-01 → LG-01 → HC-01/HC-02 → LD-01 → U-02/U-01/U-03 → OC-01/OC-02 → CO-01 → JR-01 → CS-01 → MW-01 → C-01。

**核心判断**：Validate / Session / LocalDisk / Captcha / CsrfMiddleware 的**核心算法本身是正确的**（详见第 5 节「已验证正确」清单）。问题集中在四处：

1. **异常路径健壮性不足**——大量未捕获的 `TypeError`（Logger、Upload、Config、Collection、OutputCache），把本可降级的错误升级为 500；
2. **配置与代码不一致**——`cache.output_cache.*`、`storage.disks.*.throw`、`app.timezone`、Cors 部分配置项、`CsrfMiddleware::$except` 形同虚设，产生「已配置 = 已生效」的错觉；
3. **静态 / 全局状态污染**——`JsonResource::$wrap`、`Facade::$resolved`、`Macroable::$macros`、`Env::putenv()`；
4. **并发与长驻假设**——文件锁 fail-open、OutputCache 缓存键维度不足、Throttle 文件无上界、`Command` 解析歧义、Loader 每次 autoload 的 `realpath` 开销。

---

## 1. CRITICAL

### [CRITICAL] CF-01 执行 `config:cache` 后所有 Web 请求返回空白 403（应用完全不可用）
- 文件: `app/config/Config.php` 行号: 108-127（生成）；`app/core/Application.php` 行号: 70-97（加载）
- 代码:
```php
// app/config/Config.php:110
$content = '<?php if(!defined(\'LIGHTPHP_CONFIG_CACHE\')){http_response_code(403);exit;} return '
         . var_export(self::$items, true) . ';';

// app/core/Application.php:73-79
$cachedFile = STORAGE_PATH . 'cache/config_cache.php';
if (file_exists($cachedFile)) {
    $cached = require $cachedFile;   // ← 从未 define('LIGHTPHP_CONFIG_CACHE')
    if (is_array($cached)) { $this->config = $cached; return; }
}
```
- 问题: 缓存文件首行是「防直连」守卫：常量未定义就 `http_response_code(403); exit;`。而 `Application::loadConfig()` 直接 `require` 该文件却**从不定义** `LIGHTPHP_CONFIG_CACHE`——全仓库只有 `Config::loadCached()` 定义它，而 `Application` 根本不调用 `loadCached()`。守卫在 require 期间被执行，整个请求进程被 `exit` 掉，返回空白 403，路由 / 中间件 / 控制器一个都不会运行。
- 触发场景:
  1. `php bin/console config:cache` → 生成 `storage/cache/config_cache.php`
  2. 任意 Web 请求 `GET /` → `Application::__construct() → loadConfig() → require` → `http_response_code(403); exit;`
  3. 页面返回 **HTTP 403 + 空 body**；CLI 侧 `exit;` 无返回码 → 返回 0，诊断命令同样「静默无输出」
- 实测验证:
```
$ php te.php   # $c = require "storage/cache/cc_test.php"; echo "AFTER-REQUIRE";
Warning: http_response_code(): Cannot set response code - headers already sent ...
（无 "AFTER-REQUIRE" 输出 —— 进程在 require 内终止，exitcode=0）
```
- 影响: 按 `docs/deployment.md:190` 的部署清单执行 `config:cache` 后 100% 宕机；因退出码为 0，CI / 健康检查可能判定「启动成功」。
- 建议修复:
```php
private function loadConfig(): void
{
    if (!defined('LIGHTPHP_CONFIG_CACHE')) { define('LIGHTPHP_CONFIG_CACHE', true); }
    $cachedFile = STORAGE_PATH . 'cache/config_cache.php';
    if (file_exists($cachedFile)) {
        $cached = require $cachedFile;      // 守卫放行
        if (is_array($cached)) { $this->config = $cached; return; }
    }
    $this->config = \config\Config::all();  // 复用 Config，消除两套加载逻辑
}
```
  更稳妥：把守卫从「运行时 `exit`」改为「文件不可被 Web 命中」（Nginx / `.htaccess` 显式 deny `storage/`）。`exit` 型守卫在任何 `require` 路径遗漏常量时都会造成不可恢复的进程终止，属反模式。最后补回归测试：`config:cache` 后模拟 `new Application()`，断言 `getConfig('app.name') !== null`。

---

## 2. HIGH

### [HIGH] LG-01 Logger 在 `json_encode` 失败时抛出未捕获 TypeError（记日志本身成为 500 根因）
- 文件: `app/log/Logger.php` 行号: 64-82
- 代码:
```php
$remaining = $this->remainingContext($message, $context);
$remainingJson = !empty($remaining) ? json_encode($remaining, JSON_UNESCAPED_UNICODE) : '';   // 可能返回 false
$remainingJson = str_replace(["\r\n", "\r", "\n"], ' ', $remainingJson);                       // ← TypeError
```
- 问题: `json_encode()` 在**非法 UTF-8**、**资源**、**NAN/INF**、**递归引用**四种情况下返回 `false`。`app/core/helpers.php` 顶部是 `declare(strict_types=1)`，因此 `str_replace(..., false)` 抛出 `TypeError: str_replace(): Argument #3 ($subject) must be of type array|string, false given`。日志调用出现在 `Application::handleException()`、`RequestLogMiddleware::finally` 等**错误处理链路**上 → 「记录错误」的动作本身抛出致命异常，掩盖原始异常，把本可降级的错误升级为 500。
- 触发场景（实测）:
```php
$l->info("x", ["ua" => "\xB1\x31"]);                  // 非法 UTF-8 → TypeError
$l->info("y", ["r" => fopen("php://memory", "r")]);  // 资源       → TypeError
$l->info("z", ["o" => new \stdClass()]);             // OK（remainingContext 转成类名）
```
  现实攻击面：`RequestLogMiddleware:54` 把 `$request->userAgent()`（**攻击者可控**）放进 context。构造 `User-Agent: \xB1\x31` 发任意请求 → `Logger::info()` 抛 TypeError。
- 建议修复:
```php
$remainingJson = '';
if (!empty($remaining)) {
    $encoded = json_encode($remaining,
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    $remainingJson = $encoded === false ? '[unserializable context]' : $encoded;
}
$remainingJson = str_replace(["\r\n", "\r", "\n"], ' ', $remainingJson);
```
  同时在 `remainingContext()` 中剔除 resource/Closure；并在 `log()` 外层加 `try/catch(\Throwable)`，保证「日志失败永不影响业务」。

### [HIGH] LD-01 Loader 目录越界校验缺少 `DIRECTORY_SEPARATOR`，可跳出预期目录加载文件
- 文件: `app/core/Loader.php` 行号: 36-57
- 代码:
```php
$file = $path . str_replace('\\', '/', $relativeClass) . '.php';
$realBase = realpath($path);          // 如 C:/app/core   （realpath 不带尾部分隔符）
$realFile = realpath($file);
if ($realBase === false || $realFile === false || !str_starts_with($realFile, $realBase)) {
    continue;
}
if (file_exists($file)) { require $file; return; }
```
- 问题: 前缀检查是**字符串前缀**而非**目录前缀**。`$realBase` 不含尾部分隔符，因此 `app/coreevil/X.php` 也满足 `str_starts_with('…/app/coreevil/X.php', '…/app/core')`。类名中的 `..` 被原样拼进路径：`core\..\coreevil\X` → `app/core/../coreevil/X.php` → realpath 落到 `app/coreevil/X.php` → **检查通过**。这与 CHANGELOG:385 记录的 `[SEC] Blade 路径遍历` 是完全相同的缺陷模式——**Blade 当年修了，Loader 漏修**。
- 触发场景:
```php
// 前提：攻击者已有任意写文件原语（上传落到 app/ 兄弟目录、写缓存、写日志等）
\core\Loader::autoload("core\\..\\coreevil\\Payload");
// 实际 require 的是 APP_PATH/core/../coreevil/Payload.php —— 跳出 app/core
// require 会执行该文件顶层语句（即使类名最终未定义）→ 任意文件包含 + 顶层代码执行
```
- 建议修复:
```php
$realBase = realpath($path); $realFile = realpath($file);
if ($realBase === false || $realFile === false) { continue; }
$norm = fn(string $p): string => rtrim(str_replace('\\', '/', $p), '/') . '/';
if (!str_starts_with($norm($realFile), $norm($realBase))) { continue; }
require $file;   // realpath !== false 已保证存在，删掉死代码 file_exists
```
  另建议对 `$relativeClass` 先 `preg_replace('/[^A-Za-z0-9_\\\\]/', '', ...)` 从源头消灭 `..`，并缓存 `realpath` 结果（见 LD-02）。

### [HIGH] V-01 Validate：`alpha` / `alphaNum` 对数组做 `(string)` 强转，类型混淆导致校验绕过
- 文件: `app/core/Validate.php` 行号: 388-396（`regex` 同类问题见 408-424）
- 代码:
```php
private function validateAlpha(string $field, $value, array $params): bool
{ return preg_match('/^[a-zA-Z]+$/', (string) $value) === 1; }

private function validateAlphaNum(string $field, $value, array $params): bool
{ return preg_match('/^[a-zA-Z0-9]+$/', (string) $value) === 1; }
```
- 问题: PHP 中 `(string)['a','b'] === "Array"`，而 `"Array"` **完全匹配** `/^[a-zA-Z]+$/`。攻击者把字段提交为数组（`name[]=a&name[]=b`，或 JSON 体 `{"name":["a"]}`）即可让 `alpha` / `alphaNum` 规则**通过**，同时产生 `Array to string conversion` warning。
- 触发场景（实测）:
```php
$v = new Validate();
var_dump($v->validate(['name' => ['a','b']], ['name' => 'alpha']));
// Warning: Array to string conversion in .../Validate.php on line 390
// bool(true)   ← 校验通过
$v->validate(['n' => ['a1']], ['n' => 'alphaNum']);   // bool(true)
```
  业务侧随后执行 `strtolower($name)` / 数组入库 / 拼进 SQL 或模板，产生二次问题。
- 建议修复: 在 `applyRule()` 入口统一收敛标量，对数组**判定为失败**而非强转：
```php
$needsArray = in_array($rule, ['array', 'required'], true);
if (!$needsArray && !is_scalar($value) && !$value instanceof \Stringable) {
    $this->errors[$field][] = "{$field} must be a scalar value";
    return;
}
```
  并把各规则内的强转改为显式 `is_string()` 判断。
### [HIGH] U-01 Upload 危险扩展名黑名单缺失 `.user.ini`（PHP-FPM 下可直接 RCE）
- 文件: `app/core/Upload.php` 行号: 20-28
- 代码:
```php
private const DANGEROUS_EXTENSIONS = [
    'php','phtml','php3','php4','php5','php7','php8','phar',
    'pht','phps','shtml','htaccess','htpasswd',
    'jsp','jspx','asp','aspx','cgi','pl','py',
    'sh','bash','bat','cmd','ps1',
    'html','htm','xhtml','svg','xml','swf',
];
```
- 问题: 遗漏 **`.user.ini`**——PHP-FPM 会解析上传目录下的 `.user.ini`，其中 `auto_prepend_file=/path/shell.jpg` 可让任意 PHP 请求先执行攻击者上传的脚本。这是与 `.htaccess` 同级、且**不受 Apache 是否启用 mod_php 影响**的持久化手段，在 Nginx + PHP-FPM（本项目默认形态）下必然生效。此外还缺 `phtm`、`shtm`、`stm`、`inc`、`php-s`、`phpt`、`module`、`aspz`、`cer`、`mht`。
- 触发场景:
```
POST /upload  filename="x.user.ini"
body: auto_prepend_file=/var/www/public/uploads/<hex>.jpg
随后任意 PHP 请求命中该上传目录 → 预先执行 x.jpg 中的 PHP 代码
```
- 建议修复: 补齐黑名单（`user.ini`、`phtm`、`shtm`、`stm`、`inc`、`php-s`、`phpt`、`module`、`aspz`、`cer`、`mht`、`htaccess` 的各种变体）并改为大小写无关 + 归一化比较；更关键的是按 U-03 改为白名单机制，黑名单只作为兜底。

### [HIGH] U-02 Upload：`file()` 未区分单文件/多文件数组，多文件字段抛未捕获 TypeError
- 文件: `app/core/Upload.php` 行号: 30-36、101-103、256-258
- 代码:
```php
public static function file(string $name): ?self
{
    if (!isset($_FILES[$name]) || $_FILES[$name]['error'] === UPLOAD_ERR_NO_FILE) {  // ← error 可能是数组
        return null;
    }
    return new self($_FILES[$name]);
}
// validate()
if ($this->file['error'] !== UPLOAD_ERR_OK) {                     // 数组 !== 4 → 恒为 true
    $this->error = $this->getErrorMessage($this->file['error']);  // ← TypeError
}
private function getErrorMessage(int $errorCode): string            // 形参是 int
```
- 问题: 表单同时存在单文件字段 `avatar` 与多文件字段 `photos[]` 是极常见写法。开发者按字段名调用 `Upload::file('photos')` 时 `$_FILES['photos']['error']` 是数组，`=== UPLOAD_ERR_NO_FILE` 对数组为 false，于是进入 `validate()`，把数组传给 `getErrorMessage(int)` → strict_types 下**未捕获 TypeError**，整个请求 500，且 `getError()` 返回空串。
- 触发场景（实测复现）:
```php
$_FILES['multi'] = ['name'=>['a.php','b.txt'],'type'=>[…],'tmp_name'=>[…],'error'=>[0,0],'size'=>[3,3]];
Upload::file('multi')->validate();
// TypeError: core\Upload::getErrorMessage(): Argument #1 ($errorCode) must be of type int, array given
```
- 建议修复:
```php
public static function file(string $name): ?self
{
    if (!isset($_FILES[$name])) { return null; }
    if (is_array($_FILES[$name]['name'] ?? null)) {
        throw new \InvalidArgumentException("[{$name}] is a multi-file field; use Upload::files('{$name}').");
    }
    if ($_FILES[$name]['error'] === UPLOAD_ERR_NO_FILE) { return null; }
    return new self($_FILES[$name]);
}
// validate() 内再兜底
$err = $this->file['error'];
if (!is_int($err)) { $this->error = 'Malformed upload entry'; return false; }
```

### [HIGH] U-03 Upload：`save()` 目标目录硬编码为 `PUBLIC_PATH`，框架无「存到 web 根之外」的通道
- 文件: `app/core/Upload.php` 行号: 88-92、151-193；对照 `app/config/storage.php` 行号: 29-41
- 代码:
```php
public function path(string $path): self
{ $this->uploadPath = rtrim($path, '/') . '/';   // 形似「可配置目录」，实为 PUBLIC_PATH 的相对子路径
  return $this; }

public function save(?string $path = null): ?string
{
    …
    $fullPath = PUBLIC_PATH . $path;             // :159
    $realBase = realpath(PUBLIC_PATH);           // :168 白名单硬绑 PUBLIC_PATH
}
```
- 问题: `path()` 的命名让人以为能指定任意目录，实际只是在 `PUBLIC_PATH` 下拼接；第 168 行 realpath 白名单进一步锁死。同时 `config/storage.php` 中的 `local` 盘（`root = STORAGE_PATH.'app/'`，明确是私有盘）在 Upload 侧完全没被使用。只要扩展名不在 `DANGEROUS_EXTENSIONS` 里（`.txt`/`.json`/`.md`/`.csv`/`.log`/`.zip`…），用户内容就以**同源 URL** 可被浏览器访问，是存储型 XSS 的常见载体。
- 触发场景:
```php
Upload::file('doc')->allowedExtensions(['txt','md'])->maxSize(1_000_000)->save('/uploads/');
// → public/uploads/<hex>.txt，浏览器可直接 GET 原文
Upload::file('id')->path('/storage/private/')->save();   // 期望落到 storage/，实际仍在 public/storage/private/
```
- 建议修复: ①给 `Upload` 增加 `toDisk(?string $disk)`，默认 `public` 盘，私有场景显式 `toDisk('local')`，`save()` 从 `LocalDisk::getRoot()` 取基准路径而非 `PUBLIC_PATH`；②对 PUBLIC 磁盘强制「扩展名白名单 + `X-Content-Type-Options: nosniff` + 存储文件名与原扩展名解耦」；③至少把 `path()` 重命名为 `subPath()` 消除误导。
### [HIGH] OC-01 OutputCache 匿名请求共享同一缓存键 → 跨用户内容污染
- 文件: `app/middleware/OutputCache.php` 行号: 94-108
- 代码:
```php
private function buildCacheKey(\core\Request $request): string
{
    $path = parse_url($request->uri(), PHP_URL_PATH) ?: '/';
    $params = $request->get();
    if (is_array($params)) { ksort($params); }
    $sessionKey = '';
    $sessionName = session_name();
    if ($sessionName !== '' && isset($_COOKIE[$sessionName])) { $sessionKey = $_COOKIE[$sessionName]; }
    return $this->prefix . hash('sha256', $path . '|' . json_encode($params) . '|' . $sessionKey);
}
```
- 问题: 键中唯一的「用户维度」是 **session cookie 的原始值**。两个致命场景：
  1. **未建立会话的请求（`$sessionKey === ''`）全部共享同一份缓存**。首访客渲染出的 HTML 会被后续所有匿名访客复用。若页面依赖 `Accept-Language`、`User-Agent`、非 session 的业务 Cookie（`locale`、`remember_token`）或 `Authorization` 头渲染，即产生**跨用户串页面**。
  2. 会话之外的身份载体（JWT 放 Cookie/Header）完全不在键中，登录态页面会被匿名缓存覆盖。
  即使有会话，键里放明文 session id 也意味着每用户一份完整 HTML 落盘，命中率与存储成本都极差。
- 触发场景:
```
访客 A（无 session cookie）：GET /dashboard → key = HASH('/dashboard|[]|')，缓存 HTML
访客 B（无 session cookie，Accept-Language: en，X-Tenant: b）
  GET /dashboard → 同一个 key → 直接 HIT 访客 A 的中文/租户 a 页面
```
- 建议修复: ①默认**只缓存显式标记为可缓存**的路由（白名单），而非「未排除即缓存」；②键中加入显式 Vary 维度且默认安全：
```php
$vary = [
    'path'   => $path,
    'query'  => $params,
    'accept' => $request->header('Accept-Language', ''),
    'auth'   => $this->authFingerprint($request), // 已登录：hash(userId)；未登录：固定 'anon'
];
return $this->prefix . hash('sha256', json_encode($vary));
```
  ③对「未登录且响应含 `Set-Cookie` / `Vary: *` / `Cache-Control: private`」直接跳过缓存；④绝不要把原始 session id 放进缓存键。

### [HIGH] OC-02 OutputCache 把含 `Set-Cookie`（即 session id）的全部响应头写入缓存存储
- 文件: `app/middleware/OutputCache.php` 行号: 114-137（写入）、139-157（回放）
- 代码:
```php
private function collectHeaders(array $responseHeaders = []): array
{
    $merged = [];
    foreach ($responseHeaders as $name => $value) { $merged[$name] = $value; }
    foreach (headers_list() as $header) {        // ← 这里包含 Set-Cookie
        $parts = explode(':', $header, 2);
        if (count($parts) === 2) { … $merged[$name] = trim($parts[1]); }
    }
    …
}
private function buildCachedResponse(array $cached): \core\Response
{ $safeHeaders = ['Content-Type', 'Content-Language', 'X-Cache'];   // ← 只在「回放时」过滤
```
- 问题: 安全过滤只发生在**回放**阶段，**写入**阶段把 `headers_list()` 的全部响应头原样序列化进缓存值。`Session::start()` 几乎必然在本中间件之前被触发（CSRF / RequestLog / 控制器），它下发的 `Set-Cookie: PHPSESSID=…` 会被捕获并持久化到 Redis / `storage/cache/*.cache`。后果：
  - 缓存后端成为会话凭证的旁路存储：Redis 未设密码或 `storage/` 可读 ⇒ 能读缓存的人就能拿到**其他用户**的会话 id，直接会话劫持；
  - 缓存条目在 TTL 内持续存在，等价于会话的「第二副本」，扩大 CSRF / 会话固定的影响面；
  - 一旦有人日后把 `Set-Cookie` 加进 `$safeHeaders`（很常见的「修 bug」改动），立刻变成跨用户会话劫持。
- 建议修复: 在**写入前**剔除敏感头，而不是回放时才过滤：
```php
private const NEVER_STORE = ['set-cookie','authorization','www-authenticate',
                             'proxy-authenticate','proxy-authorization'];

private function collectHeaders(array $responseHeaders = []): array
{
    $merged = [...$responseHeaders, ...$this->headersList()];
    foreach (array_keys($merged) as $name) {
        if (in_array(strtolower((string) $name), self::NEVER_STORE, true)) { unset($merged[$name]); }
    }
    …
}
```
  同时把 `$safeHeaders` 改为「先剔 NEVER_STORE，再放行其余」，避免「白名单被扩展即出事」。

### [HIGH] CO-01 Cors 缺少无条件 `Vary: Origin`，共享缓存可跨 Origin 复用响应
- 文件: `app/middleware/Cors.php` 行号: 42-90
- 代码:
```php
if (in_array('*', $this->config['allowed_origins'], true)) {
    if ($this->config['supports_credentials']) { … header("ACAO: {$origin}"); header('Vary: Origin'); }
    else { header('Access-Control-Allow-Origin: *'); }                    // ← 无 Vary
} elseif ($this->isOriginAllowed($origin)) {
    header("Access-Control-Allow-Origin: {$origin}"); header('Vary: Origin');  // ← 仅此分支有 Vary
}
…
if ($origin !== '' && !$this->isOriginAllowed($origin) && !$wildcardWithCredentials) {
    http_response_code(403); return '';                                     // ← 拒绝分支无 Vary
}
```
- 问题: `Vary: Origin` 只在「白名单命中」一条路径上出现。缺 `Vary` 的响应会被 CDN / `proxy_cache` / 共享 Varnish 按 URL 缓存并复用给**其它 Origin**。只要缓存里存有一份带 `Access-Control-Allow-Origin: https://evil.example` 的响应，就会被投递给所有来源的浏览器，攻击者即可跨域读取受保护页面内容。
- 触发场景:
```
① CDN miss，Origin=https://trusted.example 命中白名单 → 响应含 ACAO + Vary（存入）
② 某次 403 分支命中未带 Vary 的响应 → CDN 直接复用 ① 的条目给 Origin=https://evil.example
→ evil.example 的 JS 看到 ACAO 与自身匹配 → 读取成功
```
- 建议修复: **无条件**在 `handle()` 开头设置 `Vary: Origin`（只要存在白名单而非纯 `*`），并让 403 分支同样带 `Vary`：
```php
public function handle(\core\Request $request, callable $next): mixed
{
    header('Vary: Origin');
    …
}
```
  另外建议把「Origin 不在白名单」改为「不设置任何 ACAO 头并继续处理请求」（交由浏览器拦截），而不是服务端 403——后者与 CDN 缓存语义冲突，也会让未登记来源直接报错。

### [HIGH] CS-01 CSRF token 在整个 session 生命周期内固定，`regenerateToken()` 无任何调用点
- 文件: `app/middleware/CsrfMiddleware.php` 行号: 25-33；`app/core/Session.php` 行号: 172-189
- 代码:
```php
$token = $request->post('_token') ?? $request->header('X-CSRF-TOKEN');
$sessionToken = Session::token();
if ($token === null || … || !hash_equals((string) $sessionToken, (string) $token)) { … 419 … }
// 验证通过，保持当前会话 token 不变，避免多标签页或连续 AJAX 请求失效
return $next($request);
```
```php
public static function token(): string
{
    self::start();
    if (!self::has('_token')) { self::set('_token', bin2hex(random_bytes(32))); }
    return self::get('_token');          // 生命周期 == session 生命周期
}
public static function regenerateToken(): void { … }   // ← 全仓库无调用点
```
- 问题: `hash_equals` 使用正确（时序安全，见第 5 节），但 token 只在 session 首次创建时生成一次，之后**永不轮换**。`regenerateToken()` 写好了却无人调用（`grep -rn regenerateToken` 只有定义处）。一旦 token 从任何渠道泄露（Referer 头、日志、CDN 缓存、浏览器历史、`RequestLogMiddleware` 记录的 URL、共享机器的 session 文件），攻击者可在**整个 session 有效期**（`session.gc_maxlifetime` 默认 1440s，业务可更长）内对所有写接口重放。
- 触发场景:
```
用户在 Referer 可控的页面提交表单 → token 落入第三方日志
攻击者用该 token + 受害者 Cookie 反复 POST /transfer（token 从不轮换 ⇒ 全部成功）
```
- 建议修复: 采用**双 token**方案：①session 内保存 `csrf_token`（稳定）+ `csrf_prev`；②表单同时下发两个；③校验时任一匹配即通过，**通过后立即轮换**（匹配者降级为 `csrf_prev`）；④登录 / 登出 / 权限变更时调用 `Session::regenerateId(true)` + `regenerateToken()`。
### [HIGH] HC-01 HttpClient 未限制 cURL 协议 + 跟随重定向 → 任意本地文件读取 / SSRF
- 文件: `app/core/HttpClient.php` 行号: 94-128
- 代码:
```php
$ch = curl_init($url);                            // :94  ← 无任何协议/目标校验
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);   // :120
curl_setopt($ch, CURLOPT_MAXREDIRS, 5);           // :121
```
- 问题: 未设置 `CURLOPT_PROTOCOLS` / `CURLOPT_PROTOCOLS_STR` / `CURLOPT_REDIR_PROTOCOLS`。libcurl 默认允许构建时启用的全部协议，因此：
  1. `HttpClient::get('file:///etc/passwd')` 直接把本地文件读成响应体（**任意本地文件读取**）；
  2. 跟随重定向到 `file://`、`gopher://`、`dict://`、`ftp://`（构造 `302 Location: gopher://127.0.0.1:6379/_…` 可打 Redis）；
  3. 未对内网地址做任何拦截 → 云环境 `http://169.254.169.254/latest/meta-data/` 元数据凭据可被读取。
  三者组合是典型的 SSRF 打内网 / 取云凭据链路。
- 触发场景:
```php
echo (new \core\HttpClient())->get('file:///C:/Windows/win.ini')->body();   // 泄露服务器本地文件
(new \core\HttpClient(['throw' => true]))->get('http://attacker.tld/redirect'); // 302 → gopher://127.0.0.1:6379/_SET k v
```
- 建议修复:
```php
$schemes = CURLPROTO_HTTP | CURLPROTO_HTTPS;
curl_setopt($ch, CURLOPT_PROTOCOLS, $schemes);              // PHP 8.3+ 用 CURLOPT_PROTOCOLS_STR
curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, $schemes);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);            // 由调用方显式 opt-in
```
  并在 `request()` 入口用 `parse_url()` 拒绝非 `http(s)` scheme；若确需跟随重定向，先解析 `Location` 并对每一跳做 CIDR 黑名单（RFC1918 / 169.254 / ::1 / metadata）。

### [HIGH] HC-02 HttpClient 缺少超时 / 体积 / TLS 显式加固 → 挂起与内存耗尽
- 文件: `app/core/HttpClient.php` 行号: 117-128
- 代码:
```php
curl_setopt($ch, CURLOPT_TIMEOUT, (int) ($opts['timeout'] ?? 30));   // 只有总超时
// 没有 CURLOPT_CONNECTTIMEOUT / LOW_SPEED_* / MAXFILESIZE / SSL 显式项
```
- 问题: ①只有 `CURLOPT_TIMEOUT`（总时长），无 `CURLOPT_CONNECTTIMEOUT` → 对端 accept 后不响应即可挂满 30s，配合并发即 worker 耗尽；②无 `CURLOPT_LOW_SPEED_LIMIT/TIME` → 慢速滴流长期占用；③无 `CURLOPT_MAXFILESIZE` → 恶意 / 异常上游返回超大响应时 `CURLOPT_RETURNTRANSFER` 把整个 body 读进内存（OOM）；④TLS 校验完全依赖 libcurl 默认，无显式 `SSL_VERIFYPEER=true` / `VERIFYHOST=2` 与可配置 `CAINFO`。
- 触发场景:
```php
(new HttpClient())->get('http://10.0.0.5/blackhole');   // 30s × N 并发 → PHP-FPM worker 耗尽
(new HttpClient())->get('http://evil/2GB.bin');        // OOM
```
- 建议修复:
```php
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, (int) ($opts['connect_timeout'] ?? 5));
curl_setopt($ch, CURLOPT_LOW_SPEED_LIMIT, 1024);
curl_setopt($ch, CURLOPT_LOW_SPEED_TIME, 10);
curl_setopt($ch, CURLOPT_BUFFERSIZE, 16384);
curl_setopt($ch, CURLOPT_MAXFILESIZE, (int) ($opts['max_bytes'] ?? 10 * 1024 * 1024));
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
```

### [HIGH] MW-01 中间件 `shouldSkip()` 与 Router 的 URI 归一化不一致 → `except` 白名单可被构造 URI 绕过
- 文件: `app/middleware/Middleware.php` 行号: 12-34；对照 `app/core/Router.php` 行号: 491-494
- 代码:
```php
// Middleware.php
$uri = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);  // 无 trim + 无 rtrim
$uri = rtrim($uri, '/');  $uri = $uri !== '' ? $uri : '/';

// Router.php:491
$uri = '/' . trim((string) parse_url($request->uri(), PHP_URL_PATH), '/');   // 多一步 trim
```
- 问题: 两处对同一请求独立解析 / 归一化路径，且规则不同。`parse_url()` 会把 `//admin/secret` 解析成 `host=admin, path=/secret`（实测确认）。凡是依赖 `$except` 做**安全排除**的中间件（`CsrfMiddleware`、`OutputCache`）都建立在这个不可靠的归一化之上 → 可通过构造 URI 让「被排除的路径」看起来不是被排除的路径（反之亦然，可把 `/admin/x` 伪装成非 admin 路径而被 `OutputCache` 缓存）。
- 触发场景（实测 `parse_url` 行为）:
```
REQUEST_URI = "//admin/secret"
  Middleware::shouldSkip() → parse_url → "/secret" → 不匹配 '/admin/*' → 不跳过
  Router::dispatch()       → '/'.trim(parse_url(...),'/') = "/secret" → 路由也按 /secret 匹配
```
  另一例：`parse_url` 失败返回 `null` 时 `(string)null === ''` → 归一为 `/`，可能误命中 `'*'` 例外规则。
- 建议修复: 把归一化收敛为**单一权威函数**并放进 `Request`，所有中间件共用：
```php
// core\Request
public function path(): string
{
    $path = parse_url($this->server['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $path = preg_replace('#/+#', '/', rawurldecode($path));   // 统一解码 + 压缩重复斜杠
    $path = '/' . trim($path, '/');
    return $path === '' ? '/' : $path;
}
// Middleware —— 用 $this->request->path()，不要再读 $_SERVER
```
  并让 `Router::dispatch()` 也改用 `$request->path()`，保证「路由匹配目标」与「中间件排除判断目标」是同一个字符串。

### [HIGH] JR-01 JsonResource `$wrap` 是继承共享的静态属性，单个子类污染全站 API 结构
- 文件: `app/core/JsonResource.php` 行号: 26、101-104
- 代码:
```php
public static ?string $wrap = 'data';        // 声明在基类，未 final、未在子类 redeclare
…
if (static::$wrap === null) { return array_merge($data, $meta); }
return array_merge([static::$wrap => $data], $meta);
```
- 问题: PHP 中「子类未 redeclare 的继承静态属性」共享同一份存储。文档注释写「子类可重写」，但如果开发者按最自然的方式写 `SomeResource::$wrap = 'items';`（不 redeclare 属性），会**同时改掉所有 Resource**。
- 触发场景（实测）:
```php
class A { public static ?string $wrap = "data"; }
class B extends A {}  class C extends A {}
B::$wrap = "items";
echo A::$wrap, " ", B::$wrap, " ", C::$wrap;   // items items items ← 三个全部被改
```
  真实项目：`UserResource::$wrap = 'items';` 之后 `OrderResource::response()` 的输出键从 `data` 变成 `items`，**所有 API 客户端在一次无关改动后集体解析失败**；测试之间也会互相污染（顺序依赖）。
- 建议修复: 用「每类独立」的显式模式替代共享静态：
```php
// 方案 A：final + 方法覆盖
final public static function wrapKey(): ?string { return static::$wrapKey ?? 'data'; }
// 方案 B（推荐）：改成实例属性，天然隔离
protected ?string $wrap = 'data';
public function withoutWrapping(): static { $this->wrap = null; return $this; }
public function wrapWith(string $key): static { $this->wrap = $key; return $this; }
```
  若必须保留静态 API，至少加 `final` 并在文档中明确「必须 redeclare」，同时补一条断言类间隔离的单元测试。

### [HIGH] C-01 框架未提供 Cookie 签名/加密封装，任意基于 Cookie 的状态可被客户端篡改
- 文件: `app/core/Cookie.php` 行号: 1-63（全文）
- 代码:
```php
public static function set(string $key, mixed $value, int $expire = 0, …): bool
{ … return setcookie($key, (string) $value, $options); }   // ← 明文写入，无 MAC/HMAC
public static function get(string $key, mixed $default = null): mixed
{ return $_COOKIE[$key] ?? $default; }                    // ← 明文读取，无验签
```
- 问题: `Cookie` 是**纯明文读写器**，框架层没有任何「加签 / 验签」中间件（对比 Laravel 的 `EncryptCookies`）。任何把业务状态放进 Cookie 的用法（remember-me 登录令牌、购物车、权限标记、分页游标、主题开关）都**完全由客户端掌控**：改一个字节即可提权 / 伪造。`httponly` / `samesite` 默认值正确（见第 5 节），但它们只防读取与跨站发送，防不住篡改。
- 触发场景:
```php
Cookie::set('is_admin', '1', 3600);   // 任何代码读到 '1' 就当管理员
// 攻击者直接发请求：Cookie: is_admin=1 → 服务端无条件信任
```
- 建议修复: 新增 `app/middleware/EncryptCookies.php`：①出站 cookie 值改为 `base64url(payload) . '.' . base64url(HMAC-SHA256(payload, subkey))`，子密钥由 `hash_hmac('sha256', 'cookie', Hash 子密钥)` 派生（与加密用途分离）；②入站 `hash_equals` 验签，失败按「不存在」处理（不抛异常，避免 oracle）；③支持 `$except` 白名单放行第三方 cookie；④强制 `__Host-` / `__Secure-` 前缀。在此之前，至少在类注释中标注「本类不提供完整性保护，仅可用于存放非敏感偏好」。

---

## 3. MEDIUM
### [MEDIUM] V-02 未知规则 `trigger_error(E_USER_WARNING)` 在生产环境会中断请求并泄露字段名
- 文件: `app/core/Validate.php` 行号: 215-225
- 代码:
```php
if (method_exists($this, $ruleMethod)) { … }
else {
    trigger_error("Validate: Unknown validation rule '{$rule}' for field '{$field}'", E_USER_WARNING);
    $this->errors[$field][] = … ?? "{$field} has unknown rule '{$rule}'";
}
```
- 问题: 「未知规则按失败处理」的思路正确，但用 `trigger_error(E_USER_WARNING)` 报告是危险选择：`display_errors=On` 时警告直接回显（泄露字段名与规则名）；很多生产配置会把 warning 转成 `ErrorException`（`set_error_handler` 抛异常），此时**校验失败被替换成 500**——攻击者可用一个不存在的规则名把任意校验接口打成 500。
- 触发场景: `rules(): ['email' => 'requred']`（拼写错误）→ 生产 warning→exception 处理器下整站 500。
- 建议修复: 降级为「记录 + 失败」，不做 PHP 级 warning：
```php
if (!method_exists($this, $ruleMethod)) {
    $this->errors[$field][] = $this->messages[$field] ?? "{$field} has unknown rule '{$rule}'";
    error_log("Validate: unknown rule '{$rule}' for field '{$field}'");
    return;
}
```

### [MEDIUM] V-03 `regex` 规则静默吞掉所有 PHP 错误，且允许不可编译的模式
- 文件: `app/core/Validate.php` 行号: 408-424
- 代码:
```php
$pattern = $params[0];
set_error_handler(fn() => true);                       // ← 吞掉一切
$result = preg_match($pattern, (string) $value);
restore_error_handler();
```
- 问题: ①`set_error_handler(fn() => true)` 只能屏蔽 warning，**屏蔽不了 `TypeError` 等 Throwable**，而 `(string) $value` 对数组正是抛 TypeError 的路径之一；②`pcre.backtrack_limit` 触发的回溯失败被当成「校验不通过」，无法区分「值非法」与「规则本身有问题」；③对灾难性回溯（ReDoS）模式零防护。
- 触发场景: 规则 `regex:/^(a+)+$/` + 40 个 `a` 结尾接 `!` 的输入 → 单请求即耗尽 CPU（`preg_match` 无超时机制）。
- 建议修复: ①先 `@preg_match($pattern, '')` 校验模式有效性并缓存编译结果，失败即报「规则无效」；②包 try/catch 兜底 Throwable；③对用户可控长度的输入设置长度上限。

### [MEDIUM] V-04 `in` / `notIn` 使用严格比较 + 参数按逗号切分为字符串，导致整数入参永远校验失败
- 文件: `app/core/Validate.php` 行号: 398-406
- 代码:
```php
private function validateIn(string $field, $value, array $params): bool
{ return in_array($value, $params, true); }   // $params 全是 explode(',') 出来的字符串
```
- 问题: `$params` 来自 `explode(',', $paramStr)`，永远是字符串数组，而 `in_array(..., true)` 是严格比较。JSON 请求体 `{"id": 1}` 得到的是 PHP `int`，于是 `in:1,2,3` 判定失败；`{"id":"1"}` 却通过。规则语义随数据来源（form vs JSON）漂移。`notIn` 反向风险：类型不一致时会把本应禁止的值放行。
- 触发场景（实测）: `$v->validate(['id'=>1], ['id'=>'in:1,2,3'])` → `bool(false)`。
- 建议修复:
```php
private function validateIn(string $field, $value, array $params): bool
{
    foreach ($params as $p) {
        if ($p === $value) { return true; }
        if (!is_bool($p) && !is_bool($value) && (string) $p === (string) $value) { return true; }
    }
    return false;
}
```

### [MEDIUM] V-05 `addError` 把规则参数无条件当 `:min` / `:max` / `:length` 填充，错误信息张冠李戴
- 文件: `app/core/Validate.php` 行号: 236-261
- 代码:
```php
if ($rule === 'digits')  { $placeholders = [':length', ':min', ':max']; }
elseif ($rule === 'max') { $placeholders = [':max', ':min', ':length']; }
else                     { $placeholders = [':min', ':max', ':length']; }
```
- 问题: 除 `digits` / `max` 外，**所有**规则都用 `[':min', ':max', ':length']` 映射。于是 `in:a,b,c` 会把 `'a'` 填进 `:min`、`'b'` 填进 `:max`、`'c'` 填进 `:length`；`before:2030-01-01` 会把日期填进 `:min`。自定义消息里写 `:min` 就会输出意料之外的内容。
- 建议修复:
```php
private const PLACEHOLDERS = [
    'min' => [':min'], 'max' => [':max'], 'size' => [':size'],
    'between' => [':min', ':max'], 'digits' => [':min'],
    'digits_between' => [':min', ':max'], 'in' => [], 'notIn' => [],
];
$placeholders = self::PLACEHOLDERS[$rule] ?? [];
```

### [MEDIUM] V-06 `min` / `max` / `size` / `between` 先判 `is_numeric`，数字型字符串被当数值校验
- 文件: `app/core/Validate.php` 行号: 332-355、452-487
- 代码:
```php
if (is_numeric($value)) return (float) $value >= (float) $params[0];
$min = (int) $params[0];
if (is_string($value)) return \strlen($value) >= $min;
```
- 问题: `is_numeric()` 对 `"1e5"`、`" 10"`、`"+3"` 均返回 true。后果：①手机号 / 邮编类字段（`"013800138000"`）用 `max:11` 时按**数值**比较，几乎必然通过；②`" 10"` 被判为 10 而 `"10"` 被判为字符串长度 2，同一规则在不同输入下语义不同；③`is_numeric(true)` 为 false，于是 `boolean|max:1` 在 `true` 时落到最后 `return false` 而失败。注释已承认该顺序「需与 … 保持一致」，但顺序本身对「既像数字又像编码」的字段是错的。
- 建议修复: 引入显式语义（`minValue:` 表数值、`min:` 表长度），或至少对 `is_string && ctype_digit` 的情形优先按字符串处理。

### [MEDIUM] V-07 `splitRules` 在 regex 未闭合时吞并后续所有规则，丢失 `required` 等关键规则
- 文件: `app/core/Validate.php` 行号: 124-156
- 代码:
```php
while ($i + 1 < $count && !$this->isRegexClosed(substr($merged, 6), $delimiter)) {
    $i++;  $merged .= '|' . $segments[$i];      // ← 一直吞到结束
}
```
- 问题: 若分隔符本身是 `|`、正则中出现未转义的 `|`、或作者漏写结尾分隔符，`while` 会把**后面所有规则**吞进 regex 参数，导致 `required|max:10` 被静默丢弃，只剩一条坏正则。表现为「我的 required 没生效」，极难发现。
- 建议修复: 吞并到末尾仍未闭合时显式记录错误：
```php
if ($i + 1 >= $count && !$this->isRegexClosed(substr($merged, 6), $delimiter)) {
    error_log("Validate: unterminated regex on field '{$field}'; subsequent rules swallowed");
}
```

### [MEDIUM] V-08 `nullable` / `optional` 是死规则，永远不会被执行
- 文件: `app/core/Validate.php` 行号: 206-211、538-546
- 代码:
```php
$value = $this->data[$field] ?? null;
if ($rule !== 'required' && ($value === null || $value === '')) { return; }   // ← 早已 return
…
private function validateNullable(string $field, $value, array $params): bool { return true; }
private function validateOptional(string $field, $value, array $params): bool { return true; }
```
- 问题: 空值短路发生在调用具体规则**之前**，因此这两个方法永远不会执行；即便执行也恒为 true。`'nullable'|'integer'` 与 `'integer'` 完全等价。`Generator::generateValidationRules()`（:343）仍在生成 `'optional'`，说明作者以为它有语义。读者会误以为「写了 optional 就不会校验」，从而掩盖真实的必填约束。
- 建议修复: 明确区分语义并真正实现：`nullable` = 允许 null 但非 null 时必须通过其余规则；`optional` = 未提供时跳过。二者当前等价，建议直接移除，改为提供 `sometimes` / `present` / `filled` 等真正有用的规则。

### [MEDIUM] V-09 不支持嵌套字段，规则被静默跳过且 `validated()` 丢弃数据
- 文件: `app/core/Validate.php` 行号: 83-93、580-589
- 代码:
```php
foreach ($this->rules as $field => $rule) { … $this->applyRule($field, $r); }
…
foreach (array_keys($this->rules) as $field) {
    if (!isset($this->errors[$field]) && array_key_exists($field, $this->data)) { $data[$field] = $this->data[$field]; }
}
```
- 问题: 规则键 `'user.email'` / `'items.*.qty'` 被当作**字面量键**去 `$this->data['user.email']` 查找 → 永远取到 `null` → 被空值短路跳过 → **该字段的所有规则静默不执行**。这是最危险的一类静默失效：开发者写 `'user.email' => 'required|email'` 实际什么校验都没发生；同时 `validated()` 中 `array_key_exists('user.email', $data)` 为 false，字段被丢弃；若控制器改用 `$req->all()` 取数据，则拿到完全未校验的原始输入。
- 触发场景:
```php
class StoreReq extends FormRequest {
  public function rules(): array { return ['user.email' => 'required|email']; }
}
// POST {"user":{"email":"not-an-email"}}  → validate() 返回 true，validated() 返回 []
```
- 建议修复: 实现 `Arr::get/set` 风格的点号路径取值（支持 `*` 通配），`validated()` 按路径回填嵌套结构；在检测到规则键含 `.` / `*` 而当前不支持时**显式抛 LogicException**，绝不静默跳过。
### [MEDIUM] F-01 FormRequest 用 `$this->all()` 校验，查询串可污染验证数据与 `validated()`
- 文件: `app/core/FormRequest.php` 行号: 71-80；`app/core/Request.php` 行号: 294-300
- 代码:
```php
$validator->rules($this->rules())->messages($this->messages());
$ok = $validator->validate($this->all());     // all() = array_merge(get, json, post)
```
- 问题: `Request::all()` 是 `array_merge($this->get, $this->json, $this->post)`，**GET 参数同样参与校验与输出**。后果：①本应由 POST 提供的必填字段可以用 `?email=x` 满足；②`validated()` 会把 GET 同名字段一并返回，控制器 `$model->fill($req->validated())` 就引入一条不受 CSRF 保护、且可被链接预填的数据通路；③GET 幂等且可缓存，参数在 CDN 场景下易污染缓存。
- 触发场景:
```php
POST /users?name=admin    （body 为空，rules = ['name' => 'required']）
// all() 含 name=admin → 校验通过 → validated() 返回 ['name' => 'admin']
```
- 建议修复: 按内容类型选择数据源，禁止 GET 参与写操作校验：
```php
$data = $this->isJson() ? ($this->json ?? []) : $this->post;
$ok = $validator->validate($data);
```
  并增加 `queryRules()` 之类的显式入口，让查询参数校验必须显式声明。

### [MEDIUM] F-02 `authorize()` 默认放行且只在 Router 注入路径生效，手工构造 FormRequest 完全绕过授权
- 文件: `app/core/FormRequest.php` 行号: 61-64、114-122；`app/core/Router.php` 行号: 745-749
- 代码:
```php
public function authorize(): bool { return true; }        // fail-open 默认
public function validateResolved(): void
{
    if (!$this->authorize()) { throw new HttpException(403, …); }
    if (!$this->validate())   { throw new ValidationException($this->errors()); }
}
```
- 问题: ①默认 `authorize()` 返回 `true`，子类忘记覆写即为完全放行；②`validateResolved()` 只在 Router 通过类型提示注入控制器参数时调用（Router:748）。任何手工 `new StoreUserRequest()`、服务容器 `resolve()`、或直接使用 `Request::all()` 的代码路径都**完全跳过授权与校验**，且无任何提示。
- 触发场景: `$req = new StoreUserRequest(); $data = $req->all();` → 拿到完全未校验的原始输入。
- 建议修复: ①`authorize()` 默认改为读取请求上下文或显式抛 `LogicException` 要求覆写；②在 `all()` / `input()` 中检测「未调用 `validateResolved()`」时抛异常，或提供 `safe()` 作为唯一取数入口。

### [MEDIUM] S-01 `Session::regenerate()` 在 headers 已发送时静默 return，session fixation 防护被无声跳过
- 文件: `app/core/Session.php` 行号: 126-134
- 代码:
```php
public static function regenerate(bool $destroyOld = true): void
{
    self::start();
    if (headers_sent()) { return; }        // ← 静默返回，无日志无异常
    @session_regenerate_id($destroyOld);
}
```
- 问题: 登录后调用 `regenerate()` 是 session fixation 防护的关键步骤。这里在 headers 已发送时**静默跳过**且不记录任何日志，开发者会误以为防护已生效；`@` 又吞掉了 `session_regenerate_id()` 的失败。
- 触发场景: 控制器中有任何 `echo`/`print_r`/UTF-8 BOM（`app/view/` 模板文件首行 BOM 即可触发）→ `headers_sent()` 为真 → ID 不轮换 → 攻击者预置的 session id 继续有效。
- 建议修复:
```php
public static function regenerate(bool $destroyOld = true): bool
{
    self::start();
    if (headers_sent()) {
        error_log('Session::regenerate() skipped: headers already sent — fixation protection NOT applied');
        return false;
    }
    return @session_regenerate_id($destroyOld);
}
```
  根本做法是在入口最早期（任何输出之前）完成 session 启动与 ID 轮换。

### [MEDIUM] S-02 会话无法启动时伪造 `$_SESSION = []` 并置 `$started = true`，写入被静默丢弃
- 文件: `app/core/Session.php` 行号: 38-45、75-79
- 代码:
```php
} elseif (session_status() === PHP_SESSION_NONE && headers_sent()) {
    self::$started = true;                 // ← 标记为已启动，永不重试
    if (!isset($_SESSION)) { $_SESSION = []; }
    error_log('LightPHP Session: Cannot start session - headers already sent');
}
public static function set(string $key, mixed $value): void
{ self::start(); $_SESSION[$key] = $value; }   // 写入不存在的会话 → 丢弃
```
- 问题: headers 已发送时框架把 `$started` 置 `true` 并伪造 `$_SESSION` 超全局。此后所有 `Session::set()` / `token()` 看起来「成功」，但请求结束时数据随进程消失。表现为：登录成功但下一请求未登录、CSRF token 每次都变、`flash()` 消息丢失，且**业务层没有任何错误**。
- 建议修复: 用显式状态枚举取代布尔量，写操作在会话不可用时抛异常：
```php
private static bool $available = false;
public static function set(string $key, mixed $value): void
{
    self::start();
    if (!self::$available) { throw new \RuntimeException('Session unavailable (headers already sent).'); }
    $_SESSION[$key] = $value;
}
```

### [MEDIUM] S-03 CSRF token 与 session 同生共死（与 CS-01 互为因果），且无登录/登出钩子
- 文件: `app/core/Session.php` 行号: 172-189
- 问题: 见 CS-01。补充一点：`regenerateToken()` 存在但无调用点，说明作者已意识到需要轮换，只是没有接入任何生命周期钩子（`Application` / `Auth` 层都不存在）。
- 建议修复: 提供 `Session::regenerateIdAndToken()` 并在框架层预留 `login()` / `logout()` 钩子统一调用。

### [MEDIUM] E-01 `Env::load()` 类型化值只写入 `self::$vars`，`$_ENV` / `putenv` 写入字符串 → 类型不一致
- 文件: `app/core/Env.php` 行号: 77-89、96-121、123-134
- 代码:
```php
$originalValue = $value;                 // 字符串
if ($lower === 'true') $value = true;    // 类型化
if (!isset($_ENV[$key]) && getenv($key) === false) {
    self::$vars[$key] = $value;          // bool
    $_ENV[$key] = $value;                 // 注释称「类型化值」
    putenv("{$key}={$originalValue}");    // 字符串
}
```
- 问题: `env()` 返回 `bool`，但业务代码直接读 `$_ENV['APP_DEBUG']` 或 `getenv('APP_DEBUG')` 拿到字符串 `'true'`。于是 `if ($_ENV['APP_DEBUG'] === true)` 永远 false、`if (getenv('APP_DEBUG'))` 永远 true（`'false'` 也是非空字符串）。这类不一致在 `config/*.php` 之外（自定义脚本、ServiceProvider）极易踩坑。
- 建议修复: 明确「`env()` 是唯一取值入口」，`$_ENV` 与 `self::$vars` 保持同一份类型化值；`putenv` 因签名限制必须用字符串，应降级为显式开关 `Env::usePutenv(true)` 并在注释中说明它是「外部可见的字符串视图」。

### [MEDIUM] E-02 `putenv()` 把 `.env` 全部键值注入进程环境 → 子进程与同机进程全量继承敏感信息
- 文件: `app/core/Env.php` 行号: 88、123-134
- 代码:
```php
putenv("{$key}={$originalValue}");          // load()
putenv("{$key}={$stringValue}");            // set()
```
- 问题: `.env` 通常含 `APP_KEY`、`DB_PASSWORD`、`AWS_SECRET_ACCESS_KEY`。`putenv` 让这些值出现在 `getenv()` 视图、`/proc/<pid>/environ`（同 UID 可读）、以及**所有子进程**中。`bin/console` 的 `serve` / `test` 都通过 `passthru` 启动子进程，敏感信息随之外泄。`Env::set()` 也未过滤 `\0` 与 `=`，恶意 key/value 可截断后续环境变量。
- 建议修复: ①默认**不调用 `putenv`**，仅维护 `self::$vars` 与 `$_ENV`；②若必须，过滤 key（`/^[A-Za-z_][A-Za-z0-9_]*$/`）与 value（剔除 `\0`、`\n`、`=`）；③在部署文档中说明 `/proc` 暴露面。

### [MEDIUM] CF-02 `Config::get()` / `has()` 在点号路径穿过标量时抛 TypeError 而非返回默认值
- 文件: `app/config/Config.php` 行号: 10-23、43-56
- 代码:
```php
foreach ($keys as $k) {
    if (!array_key_exists($k, $value)) { return $default; }   // ← $value 可能已是 string
    $value = $value[$k];
}
```
- 问题: `array_key_exists(string, string)` 在 PHP 8 是 `TypeError`。`Config::get('app.name.foo')` 直接抛异常而非返回 `$default`。任何用「配置节名 + 用户输入」拼 key 的用法（后台配置页、动态列名、`config:show <section>.sub`）都会被输入变成 500 触发器。
- 触发场景（实测）:
```php
Config::set('app.name', 'LightPHP');
Config::get('app.name.foo');   // TypeError: array_key_exists(): Argument #2 ($array) must be of type array, string given
```
- 建议修复:
```php
foreach ($keys as $k) {
    if (!is_array($value) || !array_key_exists($k, $value)) { return $default; }
    $value = $value[$k];
}
```
  并提供 `Config::getString()/getInt()/getBool()` 类型化取值。
### [MEDIUM] CF-03 配置缓存明文落盘 `storage/cache/`，含 DB 密码 / APP_KEY / AWS Secret
- 文件: `app/config/Config.php` 行号: 108-127
- 问题: 缓存文件包含**全部**配置的明文表示，唯一防线是首行的 `exit` 守卫——而该守卫已被 CF-01 证明脆弱。`storage/` 的 Web 访问控制完全依赖部署配置（`docs/deployment.md:232` 只提醒「`.env` 不可通过 Web 访问」，未提 `storage/`）；Nginx 若未 deny 且 PHP location 规则不匹配，缓存文件会以源码形式泄露全部密钥。`cache()` 也没有设置文件权限（继承 umask，通常 0644）。
- 建议修复: ①`chmod($cacheFile, 0600)`；②把缓存放到不可被 Web 命中的目录并显式 deny；③更彻底：**不缓存含密钥的配置节**，或对 `password`/`secret`/`key` 占位、运行时从环境变量回填；④`var_export` 对闭包 / 对象会生成不可执行代码，写入前应断言叶子节点均为标量 / 数组。

### [MEDIUM] CF-04 配置缓存无失效机制，`config:cache` 后改 `.env` 或配置文件不生效
- 文件: `app/config/Config.php` 行号: 87-103；`app/core/Application.php` 行号: 70-97
- 问题: ①缓存文件不带「来源指纹」（配置文件 mtime + `.env` 哈希），无法判断是否过期，也没有 `--no-cache` 应急开关；②`Application::loadConfig()` 不调用 `loadCached()`，与 `Config` 是两套加载逻辑（CF-01 的成因）；③`array_replace_recursive($cached, self::$items)` 合并方向是「已加载项优先」，在「先 loadCached 再 load 目录」场景下会造成隐蔽的部分覆盖。
- 建议修复: ①统一到单一加载路径；②缓存头部写入 `['fingerprint' => …, 'items' => …]`，不匹配时自动回退并自我修复；③提供 `config:cache --force` 与 `APP_CONFIG_CACHE=false` 逃生开关。

### [MEDIUM] H-01 密文格式无版本号 / 密钥 ID，无法轮换 APP_KEY
- 文件: `app/core/Hash.php` 行号: 23-63
- 代码:
```php
return \base64_encode($iv . $tag . $encrypted);   // iv(12) + tag(16) + ciphertext，无版本/密钥标识
```
- 问题: 密文不含版本与密钥 ID，也不使用 AAD 绑定上下文。更换 `APP_KEY` 后历史密文全部无法解密（无迁移路径），也无法「多密钥共存 + 渐进轮换」。此外 `decrypt()` 失败返回 `null`，与「明文本身是空串」在调用方难以区分，容易把 `null` 写进数据库或继续当有效值使用。
- 建议修复: 格式改为 `base64url(version || keyId || iv || tag || ciphertext)`，并用 HKDF 从主密钥派生带用途标签的子密钥（`enc` / `cookie` / `csrf`）；解密失败抛 `DecryptException` 或返回显式 `DecryptResult{ok:bool}`，不要用 `null` 兼表两义。

### [MEDIUM] CA-01 `Captcha::verify()` 无失败次数限制与锁定，纯靠外部 Throttle
- 文件: `app/core/Captcha.php` 行号: 48-73
- 问题: ①`hash_equals` 时序安全（正确），但没有任何失败计数：默认 4 位、字符集 32 个符号，码空间约 100 万。若目标接口（登录、发短信）未挂 `Throttle`，单连接即可在数分钟内穷举完；②`clear()` 只在**成功**时调用，失败不累计也不锁定；③每次 `generate()` 覆盖旧码但不轮换 session id。
- 触发场景: 攻击者循环 `GET /captcha`（刷新码）+ `POST /login`，在无限流时命中概率随尝试线性上升。
- 建议修复: 在 session 内记录 `captcha_attempts` 与 `captcha_locked_until`，超过 N 次失败即锁定至生成时间到期；并把 `Throttle` 作为验证码端点的强制中间件。

### [MEDIUM] CA-02 `generate()` 在 `createImage()` 抛异常时已写入新码，会话中残留用户从未看到的验证码
- 文件: `app/core/Captcha.php` 行号: 26-41
- 代码:
```php
Session::set(self::$key, strtolower($code));
Session::set(self::$key . '_time', time());      // ← 先写会话

try { $image = self::createImage($code); }        // ← 可能抛 RuntimeException（缺 GD / ob_start 失败）
finally { /* 重置 width/height/length/chars */ }
```
- 问题: GD 缺失、输出缓冲启动失败等异常发生时，会话中已存在新码但图片从未返回给用户。用户后续提交任意旧验证码都会失败，而现象是「偶尔验证码校验失败」的玄学问题。
- 建议修复: 先成功生成图片，再写入会话：
```php
$image = self::createImage($code);          // 失败则不落会话
Session::set(self::$key, strtolower($code));
Session::set(self::$key . '_time', time());
return ['image' => $image];
```

### [MEDIUM] CA-03 `verify()` 显式传 `$sessionCode` 的旁路不清理会话、不参与过期清理
- 文件: `app/core/Captcha.php` 行号: 48-73
- 问题: `verify($input, $sessionCode, $generatedAt)` 在显式传入 `$sessionCode` 时：①不调用 `self::clear()`，验证码在会话中可被无限次重放；②过期分支的 `if ($sessionCode === null)` 判断使显式路径**完全跳过清理**。外部调用者（无状态校验服务、单元测试、跨进程验证）很容易误用这条旁路。
- 建议修复: 把「验证」与「消费」拆成两个方法：`verify()` 只判断，`verifyAndConsume()` 才做一次性消费；显式路径应返回验证结果而不触碰 session（并去掉 `$generatedAt` 这种由调用方传时间戳的可伪造参数）。

### [MEDIUM] EV-01 通配符与精确监听器之间不做全局 priority 排序
- 文件: `app/core/EventDispatcher.php` 行号: 24-35、140-163
- 代码:
```php
$this->listeners[$event][] = ['listener' => $listener, 'priority' => $priority];
usort($this->listeners[$event], fn($a, $b) => $b['priority'] <=> $a['priority']);  // ← 只在单个 pattern 内排序
…
foreach ($this->listeners as $pattern => $registered) {   // ← 按 pattern 插入顺序拼接
    if ($this->matchWildcard($pattern, $event)) {
        foreach ($registered as $entry) { $listeners[] = $entry['listener']; }
    }
}
```
- 问题: `usort` 只作用于同一个 pattern 内部；跨 pattern 的拼接顺序取决于 `$this->listeners` 的插入顺序，**与 priority 无关**。先注册的 `user.*`（priority = -100）永远先于后注册的 `user.created`（priority = 10）执行。与 Laravel `EventDispatcher` 的全局 priority 语义不一致，正是审计重点「通配符优先级」的实质缺陷。
- 触发场景（实测）:
```php
$e->listen('user.*', fn() => 'wildcard-low', -100);
$e->listen('user.created', fn() => 'specific-high', 10);
$e->listen('user.*.done', fn() => 'wild2', 0);
$e->dispatch('user.created');   // → ["wildcard-low", "specific-high"]  ← priority 高的反而后执行
```
  实际影响：注册顺序靠前的通配监听器（全局审计 / 日志）无法通过 priority 给特定业务监听器让位。
- 建议修复: 在 `getListenersForEvent()` 收集 `['listener','priority']` 后统一 `usort` 再取 listener；并补充「同 priority 保持注册顺序」的稳定性测试（PHP 8 的 `usort` 已稳定）。

### [MEDIUM] EV-02 `until()` 静默吞掉监听器异常（连 `error_log` 都没有），与 `dispatch()` 不一致
- 文件: `app/core/EventDispatcher.php` 行号: 120-132（对比 84-96）
- 代码:
```php
// until()
try { $result = $listener($event, ...$payload); }
catch (\Throwable $e) { continue; }        // ← 静默丢弃
if ($result !== null) { return $result; }

// dispatch()
catch (\Throwable $e) { error_log("EventDispatcher: listener for [{$event}] threw " . $e->getMessage()); continue; }
```
- 问题: `until()` 语义是「取第一个非 null 结果」，一旦前面的监听器抛异常，框架会**静默退到下一个监听器**，调用方拿到「看起来正常但其实跳过了逻辑」的结果，线上几乎无法定位。
- 建议修复: 与 `dispatch()` 保持一致（至少 `error_log`），或提供 `dispatchStrict()` 让异常上抛由调用方决定。

### [MEDIUM] EV-03 `dispatch()` 以 `$result === false` 作为「停止传播」信号，与合法返回值冲突
- 文件: `app/core/EventDispatcher.php` 行号: 84-90
- 代码:
```php
$result = $listener($event, ...$payload);
$results[] = $result;
if ($result === false) { break; }      // ← 返回 false 即中断后续所有监听器
```
- 问题: `false` 是完全合法的业务返回值（如「校验未通过」）。任何监听器恰好返回 `false` 都会**静默阻止后续所有监听器执行**，包括框架自带的审计、缓存失效、通知监听器。表现为「业务代码有时正常有时不写日志」。
- 建议修复: 用显式传播控制对象替代：
```php
if ($result instanceof \core\StopPropagation) { break; }
// 或约定返回 ['halt' => true, 'result' => …]
```

### [MEDIUM] EV-04 `subscribeClass()` 每次派发都 `new` 一个订阅者实例，实例状态全部丢失
- 文件: `app/core/EventDispatcher.php` 行号: 251-254、305-311
- 代码:
```php
$this->listen($event, function (string $e, mixed ...$payload) use ($subscriberClass, $name) {
    $instance = new $subscriberClass();          // ← 每次派发都新建
    return $instance->$name($e, ...$payload);
});
```
- 问题: 订阅者若有内部状态（计数器、缓存、去重集合、容器注入依赖），在事件之间完全丢失；`HasModelEvents` 场景下每次事件都做一次实例化开销。同时 `new $class()` 要求构造函数**无必填参数**，与 DI 体系脱节。
- 建议修复: 允许传入已解析实例，或从容器解析并缓存：
```php
public function subscribeClass(string $class, ?object $instance = null): void
{
    $c = \core\Container::getInstance();
    $instance ??= ($c !== null && $c->has($class)) ? $c->get($class) : new $class();
    $this->listen($event, fn(string $e, mixed ...$p) => $instance->{$name}($e, ...$p));
}
```
### [MEDIUM] CO-02 Cors 配置数组无默认值合并，传入部分配置即 500
- 文件: `app/middleware/Cors.php` 行号: 13-33、47、68-77
- 代码:
```php
$this->config = $config ?? [ /* 完整默认值 */ ];   // 只在 null 时使用默认值
header('Access-Control-Allow-Methods: ' . implode(', ', $this->config['allowed_methods']));   // ← 直接下标
```
- 问题: 只要调用方传了**部分**配置（常见：`new Cors(['allowed_origins' => ['https://a.com']])`），`$this->config['allowed_methods']` 就是 undefined key → warning，`implode(', ', null)` → `TypeError` → 500。
- 触发场景: `new Cors(['allowed_origins' => ['*']])` → 第 68 行 `implode(): Argument #2 must be of type ?array` TypeError。
- 建议修复:
```php
$this->config = array_replace([
    'allowed_origins' => [], 'allowed_methods' => ['GET','POST','PUT','DELETE','PATCH','OPTIONS'],
    'allowed_headers' => ['Content-Type','Authorization','X-Requested-With','X-CSRF-TOKEN'],
    'exposed_headers' => [], 'max_age' => 86400, 'supports_credentials' => false,
], $config ?? []);
```

### [MEDIUM] CO-03 凭据头无条件下发，OPTIONS 预检对任意 Origin 返回 204
- 文件: `app/middleware/Cors.php` 行号: 62-74、83-87
- 问题: ①`Access-Control-Allow-Credentials: true` 在 Origin 未命中白名单时也下发（无害但误导排查）；②OPTIONS 分支在 Origin 检查**之前** return，任何来源的预检都拿到 204 + 完整 `Allow-Methods` / `Allow-Headers`（等于向任意站点泄露 API 能力清单），只是没有 ACAO 所以浏览器会拦；③`$wildcardWithCredentials` 因构造函数已禁止该组合而恒为 false（见 CO-04）。
- 建议修复: 把 Origin 校验前置到 OPTIONS 之前；`Allow-Credentials` 只在「确实下发了 ACAO 且命中白名单」时发送；`Allow-Methods/Allow-Headers` 只对命中白名单的预检返回。

### [MEDIUM] CS-02 `Session::token()` 在校验之前调用，无 session 的写请求都会创建会话文件
- 文件: `app/middleware/CsrfMiddleware.php` 行号: 25-28
- 代码:
```php
$token = $request->post('_token') ?? $request->header('X-CSRF-TOKEN');
$sessionToken = Session::token();      // ← 取 token 会 set('_token') 从而创建会话
if ($token === null || … ) { return 419; }
```
- 问题: 校验失败的请求（绝大多数是攻击流量或过期标签页）也会先创建 session 并写入 `_token`，产生磁盘 session 文件放大 DoS，并给攻击者一个「批量制造会话」的廉价原语。
- 建议修复: 先做廉价的「有没有提供 token」判断并早退，再碰 session：
```php
if ($token === null || $token === '') { return Response::json(['code'=>419,'message'=>'CSRF token mismatch'], 419); }
$sessionToken = Session::token();
if (!hash_equals((string) $sessionToken, (string) $token)) { … }
```

### [MEDIUM] CS-03 `CsrfMiddleware::$except` 无任何配置入口，实际无法声明排除路径
- 文件: `app/middleware/CsrfMiddleware.php` 行号: 11、15-17；对比 `app/middleware/OutputCache.php` 行号: 30-33
- 问题: `shouldSkip()` 与 `$except` 都是 `protected`，`CsrfMiddleware` 既没有 `setExcept()` 也没有构造参数，Router 的 `resolveMiddleware` 也只做别名 / 分组解析。因此**任何路径都无法为 CSRF 声明排除项**——Webhooks、OAuth 回调、支付网关回调只能整条路由不挂该中间件或复制子类。文档 / 注释暗示的能力并不存在。
- 建议修复: 补齐与其他中间件一致的配置入口（构造参数 + `setExcept()/setOnly()` fluent API），并让 Router 支持 `'middleware' => ['csrf:except=/webhooks/*']` 形式。

### [MEDIUM] OC-03 回放时 `getContent()` 覆盖输出缓冲内容并丢弃 → 控制器 `echo` 被静默吞掉
- 文件: `app/middleware/OutputCache.php` 行号: 52-71
- 代码:
```php
finally { …; $output = ob_get_clean(); }                       // 缓冲内容在此被丢弃
$content = $output !== false ? $output : '';
if (is_object($response) && method_exists($response, 'getContent')) {
    $content = $response->getContent();                       // Response 内容覆盖缓冲内容
}
```
- 问题: 控制器 / 视图既 `echo` 又返回 `Response` 时，`echo` 部分进入缓冲后被 `ob_get_clean()` 丢弃，`$content` 改用 `Response::getContent()`，最终响应**丢失 echo 部分**且完全静默。`OutputCache` 与 echo 式渲染不可共存。
- 建议修复: 合并而非覆盖，并在开发期对「两者都有内容」给出 warning：
```php
$buffered = $output !== false ? $output : '';
$content  = '';
if (is_object($response) && method_exists($response, 'getContent')) { $content = $response->getContent(); }
elseif (is_string($response)) { $content = $response; }
else { $content = $buffered; }
```

### [MEDIUM] OC-04 `cache.output_cache.{enabled,ttl,except}` 三项配置全部未被读取
- 文件: `app/middleware/OutputCache.php` 行号: 10-23、41-43；`app/config/cache.php` 行号: 72-76
- 问题: `enabled`（默认 false）、`ttl`、`except` 三项**没有任何代码读取**。中间件只在路由显式挂载才生效，`enabled=false` 完全不阻止挂载；`except` 用类内硬编码默认值。配置形同虚设，且产生「配置了 except 就生效」的错觉。
- 建议修复: 构造函数默认从配置读取，并让 `enabled=false` 时直接透传：
```php
public function __construct(CacheManager $cache, ?array $config = null)
{
    $this->cache = $cache;
    $config ??= \config\Config::get('cache.output_cache', []);
    $this->ttl     = (int) ($config['ttl'] ?? 3600);
    $this->except  = (array) ($config['except'] ?? ['/admin/*', '/api/*']);
    $this->enabled = (bool) ($config['enabled'] ?? false);
}
// handle() 首行： if (!$this->enabled) { return $next($request); }
```

### [MEDIUM] OC-05 缓存内容类型不受信（TypeError 500），且无 stampede 锁
- 文件: `app/middleware/OutputCache.php` 行号: 47-50、139-141
- 问题: ①`$cached` 直接按 `array` 使用，后端里若有同键旧格式数据、被其他代码写入或序列化损坏，都会变成 `TypeError` 500；②命中判定用 `!== null`，若某驱动用 `false` 表示缺失则永不命中；③无 stampede 保护：热点 URL 失效瞬间 N 个请求同时回源，攻击者可反复触发失效维持放大。
- 建议修复:
```php
$cached = $this->cache->get($cacheKey);
if (is_array($cached) && isset($cached['content'], $cached['status'])) {
    return $this->buildCachedResponse($cached);
}
// 回源时用 add()/lock() 做 single-flight
```

### [MEDIUM] OC-06 `except` 默认值只有 `/admin/*`、`/api/*`，登录页、结算页等个性化页面默认被缓存
- 文件: `app/middleware/OutputCache.php` 行号: 13、37-43；`app/config/cache.php` 行号: 75
- 问题: 只要中间件被挂载，**所有**未匹配的 GET 路由都会进缓存。默认排除项只覆盖 `/admin/*` 与 `/api/*`，而更常见的个性化页面（`/user/profile`、`/cart`、`/checkout`、`/order/{id}`、`/search?q=`）都不在默认排除里。配合 OC-01 的「匿名共享缓存键」，一个游客访问 `/user/profile`（模板可能渲染「未登录」骨架）会把该骨架缓存给所有人。
- 建议修复: 默认排除改为「deny 优先」——只缓存显式 `$cacheable` 白名单中的路由；至少把 `/user`、`/cart`、`/checkout`、`/order`、`/account` 加入默认 `except`。

### [MEDIUM] TH-01 Throttle 在 `fopen()` / `flock()` 失败时 fail-open（放行）
- 文件: `app/middleware/Throttle.php` 行号: 81-90
- 代码:
```php
$fp = @fopen($file, 'c+');
if ($fp === false) { error_log(…); return true; }      // ← 磁盘故障 → 放行
try {
    if (!flock($fp, LOCK_EX)) { return true; }          // ← 锁失败 → 放行
```
- 问题: 限流器在**最需要生效的时刻**（高并发、磁盘满、NFS 锁不可用）静默失效并放行全部请求。`@fopen` + `error_log` 后 `return true` 使得「限流已关闭」这一事实只存在于日志里。
- 触发场景: 存储卷写满 / `storage/cache` 被删 / NFS 挂载抖动 → 所有受 `Throttle` 保护的接口（登录、短信、注册）瞬间无限制。
- 建议修复: 增加可配置的失败策略，默认「fail-closed + 告警」：
```php
if ($fp === false) {
    error_log("Throttle: storage unavailable, failMode={$this->failMode}");
    return $this->failMode === 'closed' ? false : true;   // 默认 closed
}
```
  并把该状态写入健康检查指标，而不是只写 error_log。

### [MEDIUM] TH-02 每个 (IP, 路由) 一个文件且从不过期清理 → 文件数无上界
- 文件: `app/middleware/Throttle.php` 行号: 66-69、96-124
- 代码:
```php
private function getCacheFile(string $key): string
{ return $this->storagePath . $key . '.data'; }
fwrite($fp, json_encode(['attempts' => $attempts, 'expire' => $expire]));   // 从不 unlink
```
- 问题: 过期只体现在文件内容的 `expire` 字段里，**文件本身永不删除**。攻击者可通过轮换来源 IP（配置了 `setTrustedProxies` 的部署中 XFF 最左侧可被上游应用伪造）或 IPv6 前缀（`2001:db8::/32` 的海量地址）无限制造新文件打爆 inode；即便正常流量，高基数路径参数（`/post/{uuid}`）同样致命。目录与 FileCache 共用（`storage/cache`），会显著拖慢缓存目录读写。
- 建议修复: ①按小时分桶（`throttle/YYYYMMDD/HH/`）使过期目录可整目录删除；②增加 `gc()` 删除 `expire < now` 的文件；③把节流存储移到独立目录；④或改用固定大小的 LRU 文件替代无限增长。

### [MEDIUM] TH-03 `retryAfter()` 无锁读取可能读到截断 JSON；`clear()` 粒度过粗
- 文件: `app/middleware/Throttle.php` 行号: 127-157
- 问题: ①`attempt()` 在锁内 `ftruncate($fp, 0)` 后才 `fwrite`，`retryAfter()` 不加锁直接 `file_get_contents`——可能读到空或半截 JSON，`json_decode` 失败后静默回退为完整 `decaySeconds`，`Retry-After` 响应头偏大（客户端多等）；②`clear(string $ip)` 只能按 IP 全量清除，无法按路由或时间窗；③`glob()` 在高基数目录下是 O(n) 全表扫描。
- 建议修复: ①`retryAfter()` 同样加 `LOCK_SH`；②对剩余秒数做上限 clamp，避免误导客户端；③`clear()` 改用文件索引（Redis/DB）而非 glob。

### [MEDIUM] RL-01 记录完整 query string 与原始 User-Agent → 日志泄密 + 日志投毒
- 文件: `app/middleware/RequestLogMiddleware.php` 行号: 20、35-55
- 代码:
```php
$uri = $request->uri();                    // ← 含完整 query string
…
$logger->info($message, [
    'method' => $method, 'uri' => $uri, 'status' => $statusCode,
    'duration_ms' => $duration, 'ip' => $ip,
    'user_agent' => $request->userAgent(),  // ← 攻击者可控，且可含 ANSI 转义
]);
```
- 问题: ①URL 中的 `?token=…`、`?password=…`、OAuth `code` 会明文进日志（日志通常保留数月且访问面比数据库更广）；②`user_agent` 原样进 context，`Logger` 只过滤 `\r\n`，不过滤其它控制字符与 ANSI 转义序列（`\e[2K`、`\e[31m`），攻击者可通过 UA 覆盖日志行内容 / 在 `tail -f` 的终端中注入转义序列（终端注入）；③`user_agent` 也是 LG-01 的 TypeError 触发器。
- 建议修复: ①只记录 path 与「脱敏后的 query」（剔除 `token`/`password`/`code`/`secret`/`key` 等键）；②对 `user_agent` 做控制字符清洗（保留可打印 ASCII，其余替换为 `\xNN`）；③接入 LG-01 的修复。

### [MEDIUM] RL-02 异常路径下状态码被记成 200，且未记录异常信息
- 文件: `app/middleware/RequestLogMiddleware.php` 行号: 25-34
- 问题: 异常由 `finally` 块记录，此时 `Response::send()` 尚未执行，`http_response_code()` 恒为 200，于是**所有 500 请求在日志里都是 200**；同时没有记录异常类名、文件、行号或请求 ID，故障排查时无法区分「真成功」与「异常后被降级」。
- 建议修复: 在 `catch (\Throwable $e)` 中记录 `$e`，并按异常类型映射状态码（`HttpException` → `getStatusCode()`，其余 → 500），同时生成 `X-Request-Id` 贯穿日志与响应头。
### [MEDIUM] LG-02 无敏感信息脱敏，日志目录 0755、文件随 umask，且无轮转
- 文件: `app/log/Logger.php` 行号: 39-45、64-82
- 代码:
```php
public function __construct(string $logPath = STORAGE_PATH . 'log/')
{
    $this->logPath = rtrim($logPath, '/') . '/';
    if (!is_dir($this->logPath)) { mkdir($this->logPath, 0755, true); }
}
…
file_put_contents($filename, $logLine, FILE_APPEND | LOCK_EX);   // 无权限控制、无轮转
```
- 问题: ①context 中的 `password` / `token` / `authorization` / `cookie` 原样落盘，框架没有任何脱敏机制；②目录 0755、文件权限继承 umask（常见 0644）→ 同机其他账号可读；③按日期分文件但无大小上限与轮转，单日大流量可写满磁盘（DoS）；④日志文件若落在 Web 可访问路径下（`storage/` 未 deny），可能直接被下载。
- 建议修复: ①增加 `LogSanitizer`：对键名匹配 `/(pass(word)?|token|secret|auth|cookie|api[_-]?key)/i` 的 context 值统一替换为 `***`；②`mkdir(…, 0700)` 并对文件 `chmod 0640`；③增加 `maxBytes` 轮转（`app.log` + `app.log.1`）或接入 `rotolog`；④部署文档明确 deny `storage/`。

### [MEDIUM] LG-03 `date('Y-m-d')` 使用 `date.timezone`，而 `app.timezone` 从未被应用
- 文件: `app/log/Logger.php` 行号: 71、77；`app/config/app.php` 行号: 79；`app/core/Application.php` 行号: 128-133
- 代码:
```php
$logLine = sprintf("[%s] %s: %s%s\n", date('Y-m-d H:i:s'), …);
$filename = $this->logPath . date('Y-m-d') . '.log';
```
- 问题: `app/config/app.php` 定义了 `'timezone' => env('APP_TIMEZONE', 'Asia/Shanghai')`，但**全仓库没有任何 `date_default_timezone_set()` 调用**（`grep -rn date_default_timezone_set app/` 无结果），`Application::registerServices()` 也只设置了 `Hash::setApplicationKey()`。因此该配置项完全是摆设，所有依赖 `date()` / `time()` 的支撑层组件都使用 PHP 默认时区（`date.timezone` 未设置时为 UTC）：
  - `Logger` 的日志分文件与时间戳偏移 8 小时 →「每天的日志」边界失真，跨时区排障困难；
  - `Captcha` 的 `_time` 与 `time()` 的一致性虽不受影响，但「验证码有效期 300 秒」的业务语义与运营看到的日志时间不匹配；
  - `Throttle` 的 `expire` 同理。
- 触发场景: 服务器 `date.timezone` 未设置（容器镜像的常见默认值）而 `.env` 写 `APP_TIMEZONE=Asia/Shanghai` → 日志文件名与内容时间比实际早 8 小时。
- 建议修复: 在 `Application::__construct()` 中、任何组件初始化之前调用：
```php
date_default_timezone_set($this->getConfig('app.timezone', 'UTC'));
```
  并补一条单元测试断言 `date('Y-m-d')` 与 `config('app.timezone')` 一致。

### [MEDIUM] LG-04 `log()` 对非法 level 直接抛异常，且 `interpolate()` 可能被恶意 `__toString` 打断
- 文件: `app/log/Logger.php` 行号: 54-59、194-203
- 代码:
```php
if (!isset($this->levels[$level])) { throw new \InvalidArgumentException("Invalid log level: {$level}"); }
…
$replace['{' . $key . '}'] = (string) $value;      // 对象 __toString 可抛异常
```
- 问题: ①level 若来自数据（`$logger->log($userInput, …)`）会抛 `InvalidArgumentException`，中断业务；②`interpolate()` 对带 `__toString` 的对象强转，若其 `__toString` 抛异常（业务对象常见），同样炸掉日志——与 LG-01 属于同一类「日志放大故障」问题。
- 建议修复: 非法 level 降级为 `info` 并 `error_log` 一次；`interpolate()` 用 try/catch 包裹每个 `(string)` 转换，失败时替换为 `[unstringable:ClassName]`。

### [MEDIUM] CMG-01 `CacheManager` 无存在性校验，`__call` 把所有调用代理到「当前默认」驱动
- 文件: `app/cache/CacheManager.php` 行号: 45-54、105-142
- 问题: ①`default` 指向不存在的 store 时，首次访问才抛 `Unsupported cache driver: nope. Supported drivers: file, redis, memcached`——错误信息里只有 driver 名，没有 store 名（实测输出确认），排障困难；②`__call()` 无条件代理到 `driver()`（默认驱动），于是「想用 store A 却调用了默认 B」在生产中**静默读写错误的缓存**——例如全局默认是 file、某处误用 `Cache::remember($k, …)`（实际打到 file）而另处用 `Cache::store('redis')`，行为不一致且无任何提示；③没有 `store()` / `forget()` 之类的显式驱动选择 API，`__call` 掩盖了「这个方法属于哪个驱动」。
- 建议修复: ①`resolve()` 的异常信息带上 store 名与已配置 store 列表；②`__call` 增加 debug 断言：当代码显式使用过 `driver('redis')` 后再调用无参 `__call` 时给出 `E_USER_DEPRECATED`；③提供 `CacheManager::store(string $name): CacheInterface` 作为显式入口，并在文档中要求业务代码使用它。

### [MEDIUM] HC-03 `json_encode` 失败时静默发送空 body，但仍带 `Content-Type: application/json`
- 文件: `app/core/HttpClient.php` 行号: 104-115、179-190
- 代码:
```php
$useJson = $opts['json'] ?? is_array($body);
if ($useJson) {
    $bodyStr = json_encode($body, JSON_UNESCAPED_UNICODE) ?: '';   // 失败 → ''
    if (!$this->hasHeader($headers, 'Content-Type')) { $headers[] = 'Content-Type: application/json; charset=utf-8'; }
}
…
$result[] = $name . ': ' . $value;      // normalizeHeaders：值未清洗
```
- 问题: ①`json_encode` 在非法 UTF-8、NAN/INF、递归引用、资源时返回 `false`，`?: ''` 把它变成**空 body**，但仍声明 JSON 类型——上游会收到一个「合法但内容为空的 JSON」，业务数据**静默丢失**且没有任何错误；②`normalizeHeaders()` 不校验值中的 `\r\n`，也不校验头名，允许调用方注入 `Host:` 覆盖虚拟主机头或构造畸形头。
- 建议修复:
```php
$encoded = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
$bodyStr = $encoded;
…
private function normalizeHeaders(array $headers): array
{
    foreach ($headers as $name => $value) {
        $name = preg_replace('/[^\x21-\x39\x3B-\x7E]/', '', (string) $name);
        $value = str_replace(["\r", "\n"], '', (string) $value);
        …
    }
}
```
  用 `JSON_THROW_ON_ERROR` 把编码失败变成显式异常，绝不静默发空包。

### [MEDIUM] AD-01 `ApiDoc::parseController()` 正则会误命中，且对每个控制器文件无条件 `require_once`
- 文件: `app/core/ApiDoc.php` 行号: 64-95、160-175
- 代码:
```php
private function extractClassName(string $content): ?string
{
    if (preg_match('/class\s+(\w+)/', $content, $matches)) { return $matches[1]; }
    return null;
}
…
if (!class_exists($fullClass)) { require_once $file; }
…
$reflection = new \ReflectionClass($fullClass);
```
- 问题: ①`/class\s+(\w+)/` 会被注释里的 `class`、字符串字面量、以及 `new class` / `abstract class` / `final class` 误命中（`new class extends X` 会匹配到 `extends`），拿到错误类名；`class_exists()` 对匿名类返回 false → 继续 `require_once` → 再次 `class_exists()` 仍 false → `return`，尚可容忍；但若误命中的是一个**已存在的类名**，则会对错误的类做反射并把它的方法写进文档。②`new \ReflectionClass($fullClass)` 在类名解析失败但 `class_exists` 为 true 的边界下会抛未捕获的 `ReflectionException`。③对 `app/controller/*.php` 中每个文件无条件 `require_once`：若该目录可写（例如上传可落盘到 `app/` 下，见 LD-01），则任意 PHP 文件会被执行。
- 触发场景: 控制器文件中出现 `// 这是一个 class Foo 的例子` 注释 → `extractClassName` 返回 `Foo` → 文档里出现错误的控制器条目。
- 建议修复: 用 `token_get_all()` 解析（框架在 Blade 中已有类似做法），并对 `ReflectionClass` 失败做 try/catch；`require_once` 前先断言文件确实位于 `APP_PATH.'controller/'` 且不含可执行副作用（或改用 `PhpParser` 式静态扫描，不加载代码）。

### [MEDIUM] AD-02 `toMarkdown()` 把 uri / description 原样插入 Markdown 表格，文档站渲染成 HTML 时构成存储型 XSS
- 文件: `app/core/ApiDoc.php` 行号: 18-39、225-239
- 问题: `toMarkdown()` 把 `{$method}`、`{$uri}`、`{$description}` 直接插入 Markdown 表格行，未转义 `` ` ``、`|`、`<`/`>`。路由 URI 中若出现 `` | `` 会破坏表格结构；若路由描述中含 HTML（例如从 `@Route` 属性注释里写入），渲染成 HTML 的文档站会执行脚本。`guessUri()` 还完全靠命名猜测 URI，生成的文档本身就是错的。
- 建议修复: 对插入内容做 `htmlspecialchars()` + Markdown 特殊字符转义；更根本的做法是让 `ApiDoc` 读取真实的 `#[Route]` Attribute 与路由表，而不是正则扫描 + 猜测。
### [MEDIUM] JR-02 集合模式硬编码 `'data'`，忽略 `static::$wrap` → 单资源与集合结构不一致
- 文件: `app/core/JsonResource.php` 行号: 90-97（对比 101-104）
- 代码:
```php
if ($this->collectionItems !== null) {
    $items = [];
    foreach ($this->collectionItems as $item) { $items[] = $child->toArray($request); }
    return array_merge(['data' => $items], $meta);      // ← 硬编码 'data'
}
…
return array_merge([static::$wrap => $data], $meta);     // ← 这里用 static::$wrap
```
- 问题: 集合分支完全忽略 `static::$wrap`。子类把 `$wrap` 设为 `'items'` 后，单资源响应是 `{"items": …}` 而集合响应仍是 `{"data": […]}`，客户端解析逻辑必然在其中一个分支上出错。
- 建议修复:
```php
$key = static::$wrap ?? 'data';
return array_merge([$key => $items], $meta);
```

### [MEDIUM] JR-03 `resourceToArray()` 回退到 `(array) $resource`，会暴露对象的私有属性
- 文件: `app/core/JsonResource.php` 行号: 113-118、143-159
- 代码:
```php
if (is_object($resource) && method_exists($resource, 'toArray')) { return $resource->toArray(); }
…
return (array) $resource;        // ← 对任意对象回退
```
- 问题: ①`(array)` 强转会把私有属性以 `"\0ClassName\0prop"` 形式暴露进 API 输出（JSON 中呈现为奇怪键名），若属性是密码哈希、内部标记等即构成信息泄露；②`collection()` 创建的实例 `$resource` 为 `null`，若误调 `toArray()` 静默返回 `[]`，调用方难以察觉自己拿到的是集合模式而非单资源模式。
- 建议修复: ①默认不做 `(array)` 回退，改为抛 `InvalidArgumentException`，强制子类显式实现 `toArray()`；②`collection()` 模式下重写 `toArray()` 抛出提示性异常；③对 Model 类走白名单字段（`$visible`），默认剔除 `password`/`remember_token` 等。

### [MEDIUM] FA-01 `Facade::setContainer()` 不清空已解析实例缓存，换容器后仍返回旧对象
- 文件: `app/core/Facade.php` 行号: 19-45、53-57
- 代码:
```php
public static function setContainer(Container $container): void
{ static::$container = $container; }        // ← 没有 static::$resolved = []
protected static function resolve(): object
{
    if (isset(static::$resolved[static::class])) { return static::$resolved[static::class]; }
}
```
- 问题: 换容器后 `$resolved` 里的旧实例仍被直接返回。测试重建容器、CLI 复用进程、Swoole / RoadRunner 长驻、多租户切换容器时，都会拿到**上一个容器的服务实例**（旧 DB 连接、旧配置、旧事件调度器）。`clearResolved()` 是 public static 却没有任何地方在 `setContainer()` 内部调用。
- 建议修复:
```php
public static function setContainer(Container $container): void
{
    static::$container = $container;
    static::$resolved = [];          // 换容器必须失效缓存
}
```
  并增加 `Facade::swap($instance)` 便于测试注入。

### [MEDIUM] MA-01 `Macroable::mixin()` 遍历并**调用** mixin 的全部 public 方法，含魔术方法与有副作用的方法
- 文件: `app/core/traits/Macroable.php` 行号: 45-62
- 代码:
```php
$methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);   // ← 含继承来的方法
foreach ($methods as $method) {
    $name = $method->getName();
    if (! $replace && static::hasMacro($name)) { continue; }
    $closure = $mixin->{$name}();        // ← 立即调用每个 public 方法
    static::macro($name, $closure);
}
```
- 问题: ①`getMethods(IS_PUBLIC)` 返回**包括继承来的**全部 public 方法且不区分 `_` 前缀，因此 `__toString`、`__invoke`、`__destruct`（public 魔术方法）都会被真实调用一次；②有必需参数的方法直接抛 `ArgumentCountError`；③mixin 中任何带副作用的 public 方法（`connect()`、`reset()`）都会被执行并把返回值注册成宏。宏注册表是**静态共享**的，一次错误的 mixin 会长期污染该类。
- 建议修复:
```php
$methods = array_filter(
    $reflection->getMethods(\ReflectionMethod::IS_PUBLIC),
    fn(\ReflectionMethod $m) => $m->getDeclaringClass()->getName() === $mixin::class
        && !str_starts_with($m->getName(), '_')
        && $m->getNumberOfRequiredParameters() === 0
);
```
  并在 `macro()` 中拒绝覆盖 `_` 开头与已存在的真实方法名。

### [MEDIUM] CMD-01 未知选项被静默接受，拼写错误不报错 → 破坏性命令照常执行
- 文件: `app/core/console/Command.php` 行号: 45-93、100-108
- 代码:
```php
if (str_starts_with($arg, '--')) {
    $name = substr($arg, 2);  $value = true;
    …
    $this->options[$name] = $value;      // ← 不与 signature 声明比对，任何名字都接受
}
```
- 问题: 解析器只消费 token，从不校验选项名是否在 signature 中声明。`--forc`（拼写错误）被当成「值为 true 的未知选项」，`hasOption('force')` 为 false 而命令照常执行。对 `migrate` / `cache:clear` / `config:cache` 这类破坏性命令，「我以为加了 `--force` 所以会覆盖」实际并没有。
- 触发场景: `php bin/console migrate --dryrun`（拼错）→ 正常执行真实迁移。
- 建议修复: 解析结束后与声明做差集校验：
```php
$declared = array_column(array_filter($definition, fn($d) => $d['type'] === 'option'), 'name');
$unknown  = array_diff(array_keys($this->options), $declared);
if ($unknown) { throw new \InvalidArgumentException('Unknown option(s): ' . implode(', ', $unknown)); }
```

### [MEDIUM] CMD-02 选项值吞掉下一个位置参数
- 文件: `app/core/console/Command.php` 行号: 64-86
- 问题: 布尔开关（signature 中声明为无值的 `{--force}`）也会贪婪吞掉下一个 token。用户忘记写 `--force` 而直接跟位置参数时，该参数被当作开关的值，且**从 `arguments` 中消失**，造成参数错位。
- 触发场景（实测）:
```php
$signature = "demo {name} {--force} {--limit=10} {age?}";
parseInput(["bob","--limit","5","--force","extra-positional"]);
// ARGS={"name":"bob","age":null}  OPTS={"limit":"5","force":"extra-positional"}
```
- 建议修复: 只对「声明了默认值或确为值型」的选项消费下一个 token，布尔开关一律置 `true`：
```php
$isFlag = isset($this->optionDefs[$name]) && !str_contains($this->optionDefs[$name]['raw'], '=');
if (!$isFlag && !str_contains($name, '=') && isset($args[$i+1]) && !str_starts_with($args[$i+1], '-')) {
    $value = $args[++$i];
}
```

### [MEDIUM] CMD-03 signature 中的 `required` 计算后从未校验，缺失必填参数静默为 null
- 文件: `app/core/console/Command.php` 行号: 88-92、130-152
- 代码:
```php
// parseArgument() 算了 $required / $default
return ['type'=>'argument','name'=>$name,'required'=>$required,'default'=>$default];
// parseInput()：
$this->arguments[$def['name']] = $def['default'] ?? null;    // ← $def['required'] 从未被读取
```
- 问题: `{name}`（必填）与 `{name?}`（可选）的唯一区别就是这个从未使用的标志。缺参数时静默给 `null`，错误被推迟到业务代码，表现为 `(int) null = 0`、`preg_match(null)` deprecated、`str_replace(null)` TypeError 等下游怪异错误。
- 触发场景（实测）: `$signature = "demo2 {name}"`，`parseInput([])` → `argument('name') === NULL`，无任何提示。
- 建议修复:
```php
foreach ($positionalDefs as $def) {
    if (!isset($this->arguments[$def['name']])) {
        if ($def['required'] && $def['default'] === null) {
            throw new \InvalidArgumentException("Missing required argument \"{$def['name']}\".");
        }
        $this->arguments[$def['name']] = $def['default'];
    }
}
```

### [MEDIUM] CMD-04 signature 中的选项默认值解析出来后从未写入 `$options`
- 文件: `app/core/console/Command.php` 行号: 154-169、88-92
- 问题: `{--tag=default}` 的 `default` 被解析出来但只用于**位置参数**补齐，选项完全没有默认值。于是 `option('tag')` 返回调用方传入的 `$default`（通常 null），`hasOption('tag')` 为 false——尽管签名里明确写了 `default`。`bin/console:38` 的 `$this->option('host', 'localhost')` 只能靠调用方再写一遍默认值兜底。
- 触发场景（实测）: `$signature = "demo2 {name} {--tag=default}"`，`parseInput([])` → `tag=NULL hasTag=false`。
- 建议修复:
```php
foreach ($definition as $def) {
    if ($def['type'] === 'option' && !array_key_exists($def['name'], $this->options)) {
        $this->options[$def['name']] = $def['default'];
    }
}
```
  同时区分 flag（默认 `false`）与值选项（默认 `null`/字符串），避免 `option('x','y')` 与 `option('x')` 语义打架。

### [MEDIUM] CMD-05 以 `-` 开头的选项值被当成短选项
- 文件: `app/core/console/Command.php` 行号: 64-79
- 代码:
```php
} elseif (str_starts_with($arg, '-')) {          // ← 负数也命中
    $name = substr($arg, 1);  $value = true;
```
- 问题: 负数是极常见的参数值（`--offset -5`、`--limit -1`、日期 `-1 day`）。当前实现把 `--offset` 设为 `true` 后，又把 `-5` 当成新的短选项 `5 => true`，命令拿到 `true` 而不是 `-5`。
- 触发场景（实测）: `$signature = "demo3 {--offset=0}"`，`parseInput(["--offset","-5"])` → `{"offset":true,"5":true}`。
- 建议修复: 用 `is_numeric()` 区分短选项与负数：
```php
$isShort = str_starts_with($arg, '-') && !str_starts_with($arg, '--') && !is_numeric($arg);
```

### [MEDIUM] GEN-01 `generateResourceRoutes()` 未校验控制器名，与其它生成方法防护不一致
- 文件: `app/core/Generator.php` 行号: 244-258（对比 97-104）
- 代码:
```php
$controllerName = $controllerName ?: $this->tableToControllerName($table);   // ← 无 preg_match 校验
return <<<PHP
\$router->get('/{$name}', [\\controller\\{$controllerName}::class, 'index']);
PHP;
```
- 问题: `generateController()` / `generateModel()` 都用 `/^[a-zA-Z_][a-zA-Z0-9_]*$/` 校验类名，只有 `generateResourceRoutes()` 漏了。控制器名被原样插入生成的路由 PHP 代码与 URL 路径 → **代码注入**（`"X'; eval($_GET[0]); //"` 会闭合 `::class` 与数组语法）与**路径穿越**（`$router->get('/../admin', …)`）。
- 触发场景: 从外部输入（API / 表单 / 工单系统）取控制器名传给 `generateResourceRoutes()` → 生成的 `app/route/*.php` 含任意代码。
- 建议修复: 抽出统一的 `assertIdentifier()`，三个生成方法共用。

### [MEDIUM] GEN-02 列名 / 列类型被原样插入生成的 PHP 单引号字符串，缺少转义
- 文件: `app/core/Generator.php` 行号: 75-90、120-198、364-403、412
- 代码:
```php
$lines[] = "            '{$field}' => '{$rule}',";        // 来自 SHOW FULL COLUMNS 的 Field 名
$fieldStr = implode(', ', array_map(fn($f) => "'{$f}'", $fillable));
```
- 问题: 列名来自 `SHOW FULL COLUMNS`（:49），未经任何转义就拼进生成 PHP 的单引号字符串字面量。MySQL 反引号标识符允许 `'`、`\`、`}`、`$` 等字符，因此一个精心命名的列即可逃逸字符串字面量，实现**生成文件中的代码注入**（该文件随后被 autoload / require 执行）。
- 触发场景: 数据库中存在含 `'` 的列名时执行 `make:model`，生成的 `app/model/X.php` 顶部即含可执行 PHP。
- 建议修复: 统一用 `var_export()` 生成 PHP 字面量，绝不手工拼引号；并在生成前对列名做 `preg_match('/^[A-Za-z0-9_]+$/', …)` 断言。

### [MEDIUM] GEN-03 `saveModel()` / `saveController()` 直接覆盖已存在文件且无提示、无备份
- 文件: `app/core/Generator.php` 行号: 203-225
- 代码:
```php
public function saveController(string $table, ?string $controllerName = null, bool $withModel = true): string
{
    $content = $this->generateController($table, $controllerName, $withModel);
    …
    if (file_put_contents($path, $content) === false) { throw new \RuntimeException(…); }
    return $path;      // ← 无论文件是否已存在都直接覆盖
}
```
- 问题: 误跑一次 `make:controller User` 就会**静默清空**既有控制器中手写的业务逻辑（无提示、无备份、无 diff 确认）。这类数据/代码丢失往往在部署后才被发现。
- 建议修复: 文件已存在时默认拒绝（抛异常），提供 `--force` 显式覆盖；覆盖前写入 `.bak`；并在 `bin/console` 的 `make:*` 命令中把 `file_exists` 检查前置到生成之前。

### [MEDIUM] S3-01 `LocalDisk` 仅做词法 `..` 拒绝，不做 realpath 校验 → root 下的符号链接可越界读写
- 文件: `app/core/LocalDisk.php` 行号: 50-61、151-172
- 代码:
```php
private function normalizePath(string $path, bool $isDir = false): string
{
    // 规范化分隔符 + 词法剥离 '.' 与 '..'
    foreach ($parts as $part) { if ($part === '..') { throw new \InvalidArgumentException(…); } }
    return $this->root . '/' . implode('/', $resolved);   // ← 不做 realpath 校验
}
```
- 问题: 词法分析只能拒绝路径**字符串**中的 `..`，无法防止 root 内部存在指向外部的**符号链接**。若 `storage/app/` 下有 `link -> /etc`（攻击者经由上传、备份恢复、共享目录等获得创建符号链接的能力），`get('link/passwd')` 会读到 `/etc/passwd`，`put('link/x', …)` 会写到 root 之外。
- 触发场景:
```php
symlink('/etc', STORAGE_PATH . 'app/link');
$disk->get('link/passwd');      // 返回 /etc/passwd 内容
$disk->put('link/cron.d/evil', $payload);   // 写到 root 之外
```
- 建议修复: 对**已存在**的路径追加 `realpath` 校验（与 `Upload::save()` 的做法一致）：
```php
$full = $this->root . '/' . implode('/', $resolved);
$real = realpath($full);
if ($real !== false) {
    $norm = fn($p) => rtrim(str_replace('\\','/',$p), '/') . '/';
    if (!str_starts_with($norm($real), $norm((string) realpath($this->root)))) {
        throw new \InvalidArgumentException("Path escapes disk root: {$path}");
    }
}
```
  另可在 `put()` 时用 `lstat()` 拒绝目标路径上任何一级是符号链接。

### [MEDIUM] MW-02 `shouldSkip()` 直接读超全局 `$_SERVER`，无法在 CLI / 测试 / PSR-7 场景复用
- 文件: `app/middleware/Middleware.php` 行号: 12-17
- 代码:
```php
protected function shouldSkip(): bool
{
    $uri = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
```
- 问题: `shouldSkip()` 不接收 `Request`，而是直接读超全局。这使中间件的排除逻辑无法注入、无法在单元测试中构造不同 URI、也无法用于 CLI / Swoole 等非标准 SAPI（此时 `$_SERVER['REQUEST_URI']` 永远是 `'/'`，导致 `except: ['*']` 之类的规则被误命中而**跳过全部逻辑**）。
- 建议修复: 把 `Request` 传入（或缓存为中间件属性），并让 `shouldSkip(\core\Request $request)` 使用 `$request->path()`（与 MW-01 的修复合并）。

### [MEDIUM] MW-03 通配符 `*` 被翻译成 `.*`（可跨 `/`），并存在正则回溯放大
- 文件: `app/middleware/Middleware.php` 行号: 23-30
- 代码:
```php
$regex = preg_quote($pattern, '#');
$regex = str_replace('\\*', '.*', $regex);        // ← .* 可匹配任意字符，含 '/'
if (preg_match('#^' . $regex . '$#', $uri)) { return true; }
```
- 问题: ①`*` 匹配 `/`，因此 `'/admin/*'` 同时匹配 `/admin/a/b/c`、`/admin/../secret`（若 URI 被规范化前），语义与开发者预期（单段）不符；②多个 `*` 相邻时会拼出 `.*.*.*…`，在长 URI 上产生**回溯放大**：`/a/*/*/*/*/*/*/*/b` 对 `/a/` + 数千个 `x` 的输入会退化到指数级。模式来自配置故非远程可利用，但配置项往往由业务方拼装，风险不可忽略。
- 建议修复: ①把 `*` 翻译为 `[^/]*`（单段语义）并提供独立的 `**` 表示跨段；②对拼接结果做长度上限与「连续 `.*` 数量」检查，或改用 `fnmatch()` / 逐段比较而非正则。
### [MEDIUM] COL-01 `Collection::zip()` 用数字下标遍历，字符串键集合全部得到 null
- 文件: `app/core/Collection.php` 行号: 379-387
- 代码:
```php
public function zip(array $items): self
{
    $result = [];
    $count = max(count($this->items), count($items));
    for ($i = 0; $i < $count; $i++) {
        $result[] = [$this->items[$i] ?? null, $items[$i] ?? null];   // ← 假设 $this->items 是 list
    }
    return new static($result);
}
```
- 问题: `$this->items[$i]` 假设原集合是数字键的 list。对 `keyBy()` / `groupBy()` / `pluck($v,$k)` 这类产出**字符串键**的集合（正是 `zip()` 最典型的用法——把两个 keyed 集合并排），全部取到 `null`。
- 触发场景（实测）:
```php
(new Collection(["x"=>1,"y"=>2]))->zip([10,20])->all();
// [[null,10],[null,20]]      ← 两个原始值都丢了
```
- 建议修复:
```php
$left = array_values($this->items);
$right = array_values($items);
for ($i = 0, $n = max(count($left), count($right)); $i < $n; $i++) {
    $result[] = [$left[$i] ?? null, $right[$i] ?? null];
}
```

### [MEDIUM] COL-02 `avg($key)` 用元素总数作分母，`array_column` 丢弃缺键行 → 平均值错误
- 文件: `app/core/Collection.php` 行号: 101-106（对比 93-99）
- 代码:
```php
public function avg(?string $key = null): float|int
{
    $count = $this->count();          // ← 全部元素数
    if ($count === 0) return 0;
    return $this->sum($key) / $count; // ← sum() 内部 array_column 会丢弃缺键元素
}
```
- 问题: `sum($key)` 走 `array_column($this->items, $key)`，该函数**静默丢弃**没有该键的行与非数组行，而分母仍是 `count($this->items)`。任何「部分行缺字段」的数据集都会得到被稀释的平均值。
- 触发场景（实测）:
```php
(new Collection([["v"=>1],["w"=>3]]))->avg('v');   // float(0.5)  ← 正确应为 1.0
```
- 建议修复:
```php
public function avg(?string $key = null): float|int
{
    $values = $key === null ? array_filter($this->items, 'is_numeric')
                            : array_filter(array_column($this->items, $key), 'is_numeric');
    if ($values === []) { return 0; }
    return array_sum($values) / count($values);
}
```

### [MEDIUM] COL-03 `where/whereIn/pluck/sortBy/keyBy/groupBy` 假设元素是数组，对对象静默返回空
- 文件: `app/core/Collection.php` 行号: 57-79、139-165、218-239
- 代码:
```php
public function where(string $key, mixed $value): self
{ return $this->filter(fn($item) => ($item[$key] ?? null) === $value); }
```
- 问题: 所有数据访问方法都用 `$item[$key] ?? null`。对 `Eloquent\Model`、`DTO`、`stdClass` 等对象：①对象未实现 `ArrayAccess` 时 `$obj[$key] ?? null` 不会报错而是静默返回 `null`；②`Model` 实现了 `ArrayAccess` 时 `offsetGet('attr')` 通常返回 `null`（真实属性在 `getAttribute()` 中）。结果是 `where('id', 1)` 在模型集合上返回**空集合**，而 `pluck('name')` 返回全 `null` 数组——没有任何错误提示，排查成本极高。
- 触发场景:
```php
collect($users)->where('id', 5)->count();      // 0（用户明明存在）
collect($users)->pluck('name');               // [null, null, …]
```
- 建议修复: 统一走一个取值器，兼容数组 / ArrayAccess / 对象 getter / 魔术属性：
```php
private static function valueAt(mixed $item, string $key): mixed
{
    if (is_array($item)) { return $item[$key] ?? null; }
    if ($item instanceof \ArrayAccess) { return $item->offsetExists($key) ? $item[$key] : null; }
    if (is_object($item)) { return $item->{$key} ?? null; }
    return null;
}
```
  并把 `sum/min/max` 的 `array_column` 同样替换（见 COL-05）。

### [MEDIUM] COL-04 `pluck()` / `keyBy()` 用 `'__pluck_key_' . count()` 兜底键，可与真实键碰撞
- 文件: `app/core/Collection.php` 行号: 67-79、232-239
- 代码:
```php
$results[$item[$key] ?? '__pluck_key_' . count($results)] = $itemValue;
```
- 问题: 当某行缺少 `$key`（或值为 `null`）时使用合成键。若集合中同时存在一个**真实**键 `'__pluck_key_0'`，两行会互相覆盖，数据静默丢失。
- 建议修复: 缺键时抛异常（这是数据问题，不应静默编造键），或使用不会被数据占用的高位前缀 + 后置校验：
```php
if (!array_key_exists($key, $item)) {
    throw new \UnexpectedValueException("Cannot pluck missing key '{$key}'.");
}
```

### [MEDIUM] COL-05 `sum()` / `min()` / `max()` 对含非数值的集合产生 Warning 并返回错误值
- 文件: `app/core/Collection.php` 行号: 93-124
- 触发场景（实测）:
```php
(new Collection([1,2,"abc"]))->sum();
// Warning: array_sum(): Addition is not supported on type string
// int(3)        ← "abc" 被静默当作 0
```
- 问题: ①`array_sum` 对字符串发出 Warning 并把它当 0；②`min()/max()` 用 PHP 的跨类型比较规则（`"abc" > 2` 为 true），结果与预期完全不同；③`array_column` 在元素非数组时返回空数组，`sum($key)` 静默返回 0。这些都把数据问题转化为「看起来合理的错误数字」。
- 建议修复: 在聚合前显式过滤并对被丢弃的元素发出 `E_USER_WARNING`，或在提供 `strict` 开关时抛异常。

### [MEDIUM] COL-06 `chunk($size <= 0)` 不分块；`first()/last()` 把可调用字符串当工厂执行
- 文件: `app/core/Collection.php` 行号: 187-214、324-339
- 问题: ①`chunk(0)` / `chunk(-1)` 时 `count($chunk) === $size` 永不成立，全部元素落进最后一个块，返回「一个巨大的 chunk」而非按预期分块（实测 `chunk(0)->count() === 1`）；②`first()` / `last()` 的默认值判断是 `is_callable($default) ? $default() : $default`，而**任何存在的函数名/类名字符串都是 callable**——`first(null, 'strlen')` 会真的执行 `strlen()`，`first(null, 'app\\Foo')` 会实例化该类。
- 触发场景: `collect($items)->first(null, 'count')` → 返回整数而不是字符串 `'count'`。
- 建议修复: ①`chunk()` 开头 `if ($size < 1) { throw new \InvalidArgumentException('chunk size must be >= 1'); }`；②默认值只接受 `Closure`（或用独立的 `firstOr(default: …)` 方法显式区分）。

### [MEDIUM] COL-07 `flatten()` 对深层嵌套无深度上限，`split()` 在 `$number = 0` 时行为异常
- 文件: `app/core/Collection.php` 行号: 311-322、416-420
- 代码:
```php
public function flatten(): self
{
    foreach ($this->items as $item) {
        if (is_array($item)) { array_push($result, ...(new static($item))->flatten()->all()); }
```
- 问题: ①`flatten()` 递归无深度限制，处理用户可控的深层嵌套 JSON（解码深度默认 512）时会消耗大量栈空间，深层嵌套下可能触发内存耗尽；②`split(0)` 走 `max(1, 0)` → chunk size = 元素总数 → 返回「一个包含全部元素的 chunk」而不是报错；③`flatten()` 只展开 `array`，不展开 `Traversable` 与 `JsonResource`，与 `flatMap()` 的语义不一致。
- 建议修复: 加入深度上限（超过即抛异常或停止展开）；`split()` 对 `$number < 1` 抛异常；统一「可展开类型」判定。

### [MEDIUM] LD-02 每次 autoload 失败都对 13 个前缀各做 2 次 `realpath()` 系统调用
- 文件: `app/core/Loader.php` 行号: 36-57
- 问题: `autoload()` 在循环内对每个前缀调用 `realpath($path)`（前缀目录是静态的，可缓存）与 `realpath($file)`。一次未命中的类查找会产生 **26 次文件系统调用**；在 Composer 缺席、`class_exists()` 被大量调用（如 `is_subclass_of`、`instanceof` 探测）的场景下这是可测量的启动开销。此外 `realpath($file)` 返回 false 时已经 `continue`，随后第 51 行的 `file_exists($file)` 永远是死代码（永远不会被执行到 false 分支）。
- 建议修复: 静态缓存前缀目录的 `realpath`：
```php
private static array $baseReal = [];
private static function baseReal(string $path): string|false
{ return self::$baseReal[$path] ??= realpath($path); }
```
  并删除第 51 行的死代码。

### [MEDIUM] LD-03 前缀表顺序使 `core\console\` / `core\traits\` 永远不可达（靠 `core\` 兜底成功）
- 文件: `app/core/Loader.php` 行号: 8-22
- 代码:
```php
private static array $prefixes = [
    'core\\'          => APP_PATH . 'core/',
    'core\\console\\' => APP_PATH . 'core/console/',
    'core\\traits\\'  => APP_PATH . 'core/traits/',
    …
];
```
- 问题: 循环按插入顺序匹配。类 `core\console\Console` 先命中 `'core\'`，`relativeClass = 'console\Console'` → `app/core/console/Console.php` → 加载成功。也就是说 `core\console\` 与 `core\traits\` 两项**永远不会被使用**，功能上靠 `core\` 兜底恰好正确，但：
  - 维护者会误以为这两个前缀有独立语义（例如可以把 `core/traits/` 移出 `core/`），一旦移动即全部失效；
  - 未来若为 `core\console\` 配置不同目录（如把 console 目录改名/外移），永远不会生效。
- 建议修复: 按**最长前缀优先**排序：
```php
uksort(self::$prefixes, fn($a, $b) => strlen($b) <=> strlen($a));
```
  并在 `addNamespace()` 中同样触发重排。

### [MEDIUM] MA-02 `__call` / `__callStatic` 对 static 闭包 `bindTo()` 返回 null → 调用 null
- 文件: `app/core/traits/Macroable.php` 行号: 97-112、124-139
- 代码:
```php
if ($macro instanceof \Closure) { $macro = $macro->bindTo($this, static::class); }
return $macro(...$args);
```
- 问题: `Closure::bindTo()` 在三种情况下返回 `null`（并发出 warning）：①闭包是 `static function`（不能绑定 `$this`）；②闭包已被绑定到其它对象且作用域受限；③目标类作用域不允许访问闭包内使用的 private 成员。此时 `$macro` 变成 `null`，`$macro(...)` 抛 `Error: Value of type null is not callable`，且 warning 被掩盖了真实原因。
- 建议修复:
```php
if ($macro instanceof \Closure) {
    $bound = $macro->bindTo($this, static::class);
    if ($bound === null) {
        throw new \BadMethodCallException(sprintf('Macro %s::%s is not bindable to an instance.', static::class, $method));
    }
    $macro = $bound;
}
```

### [MEDIUM] S3-02 `LocalDisk::url()` 不走 `normalizePath()`，路径穿越直接拼进 URL
- 文件: `app/core/LocalDisk.php` 行号: 80-86
- 代码:
```php
public function url(string $path): string
{
    if ($this->urlPrefix === null) { throw new \RuntimeException(…); }
    return rtrim($this->urlPrefix, '/') . '/' . ltrim(str_replace('\\', '/', $path), '/');
}
```
- 问题: `url()` 是**唯一**不做路径校验的磁盘方法。`url('../../.env')` 返回 `/uploads/../../.env`，控制器若把它塞进 `<img src>` / `redirect()` / `Location` 头，浏览器与中间件会把路径规范化成 web 根之外的位置——构成路径穿越的信息泄露原语（尤其当 `urlPrefix` 本身是一个可被外部读到的绝对 URL 时）。
- 建议修复: `url()` 内部复用 `normalizePath()` 的词法检查，并对返回值做 `rawurlencode` 分段编码：
```php
public function url(string $path): string
{
    if ($this->urlPrefix === null) { throw new \RuntimeException(…); }
    $this->normalizePath($path);   // 复用词法遍历拒绝，确保无 '..'
    $segments = array_map('rawurlencode', explode('/', str_replace('\\', '/', $path)));
    return rtrim($this->urlPrefix, '/') . '/' . implode('/', array_filter($segments, fn($s) => $s !== ''));
}
```

### [MEDIUM] S3-03 `normalizePath()` 的 `$isDir` 参数从未被使用（死参数）
- 文件: `app/core/LocalDisk.php` 行号: 88-90、113-115、151
- 代码:
```php
public function files(string $directory = ''): array { $dir = $this->normalizePath($directory, true); … }
private function normalizePath(string $path, bool $isDir = false): string
{ … /* $isDir 全程未被读取 */ }
```
- 问题: 死参数说明作者曾计划区分「文件路径」与「目录路径」语义（例如目录路径应允许尾部 `/`）。当前 `files('dir/')` 与 `files('dir')` 行为一致（尾部空段被跳过），无害但属设计意图未落地，容易让后续维护者误以为有特殊处理。
- 建议修复: 要么实现该语义（目录路径要求不以文件形式存在），要么删除参数并在注释中说明「目录与文件路径采用同一套词法归一化」。

### [MEDIUM] S3-04 `config/storage.php` 的 `'throw' => true` 无人读取；`put()` 忽略 `stream_copy_to_stream` 返回值
- 文件: `app/config/storage.php` 行号: 33、40；`app/core/LocalDisk.php` 行号: 24-48
- 代码:
```php
'local'  => ['driver' => 'local', 'root' => STORAGE_PATH . 'app/', 'url' => null, 'throw' => true],
…
if (is_resource($content)) {
    $dest = @fopen($full, 'wb');
    try { stream_copy_to_stream($content, $dest); return true; }   // ← 返回值被忽略
    finally { fclose($dest); }
}
```
- 问题: ①`throw` 配置项在 `LocalDisk` / `Storage` 中从未被读取——写失败一律返回 `false`，开发者配置 `throw => true` 却得不到异常，属配置幻觉；②`stream_copy_to_stream()` 在磁盘满 / 流被中断时返回**实际复制的字节数**（可能小于流长度），当前实现一律 `return true`，产生**静默截断的文件**（对上传大文件是数据完整性问题）。
- 建议修复: ①实现 `throw` 语义（失败时抛 `UnableToWriteFile`）；②比较复制字节数与 `fstat($content)['size']`，不一致时截断临时文件并返回 false / 抛异常（推荐「先写 `.tmp` 再 rename」的原子写法）。

### [MEDIUM] C-02 `Cookie` 的 `$secure` 判定忽略可信代理，与 `Session` 行为不一致
- 文件: `app/core/Cookie.php` 行号: 15-17、41-43；对照 `app/core/Session.php` 行号: 18
- 代码:
```php
if ($secure === null) {
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';   // ← 只看 HTTPS
}
```
- 问题: `Session::start()` 已改用 `Request::isSecureFromServer()`（尊重 `setTrustedProxies` 与 `X-Forwarded-Proto`，CHANGELOG:132 记录的修复），但 `Cookie::set/delete` 仍是老写法。在 Nginx TLS 终止的部署中，PHP 看到的请求是 http → **所有 `Cookie::set()` 写出的 cookie 都不带 `Secure` 标志**，浏览器因此会在 http 降级访问时明文发送会话 / 令牌 cookie。
- 触发场景: Nginx(443) → php-fpm(80) 部署 + `Cookie::set('token', $t, 3600)` → 响应头 `Set-Cookie: token=…`（无 `Secure`）。
- 建议修复: 复用同一判断：
```php
if ($secure === null) { $secure = \core\Request::isSecureFromServer(); }
```
  更进一步：在 HTTPS 环境下应**默认** `secure = true` 而非依赖探测（探测失败时降级为不安全是危险的默认方向）。

### [MEDIUM] C-03 Cookie 写入 / 读取类型不对称（数组与布尔写入后读回为字符串）
- 文件: `app/core/Cookie.php` 行号: 8-11、18-26
- 代码:
```php
if (!is_string($value) && !is_numeric($value)) {
    $encoded = json_encode($value, JSON_UNESCAPED_UNICODE);
    $value = $encoded;                 // 数组/对象/bool 被编码为字符串
}
…
public static function get(string $key, mixed $default = null): mixed
{ return $_COOKIE[$key] ?? $default; }   // 返回类型是 string|null
```
- 问题: `Cookie::set('prefs', ['theme'=>'dark'])` 写入 `{"theme":"dark"}`，`Cookie::get('prefs')` 返回的是**字符串**而非数组；`Cookie::set('flag', true)` 读回 `'true'` 字符串。于是业务代码出现类型混淆：`$prefs['theme']` → `'{"theme":"dark"}'['theme']` → 非法字符串偏移 TypeError（PHP 8）。这是把结构化数据放 Cookie 的必然踩坑点。
- 建议修复: ①`get()` 增加可选 JSON 解码：`Cookie::getArray('prefs')`，或提供 `Cookie::getDecoded($key, $default)`；②或者干脆禁止写入非标量（抛 `InvalidArgumentException`），引导使用者改用服务端 session / cache 存储。

### [MEDIUM] U-04 MIME 仅靠 `finfo`，可被魔数伪造；`finfo` 缺失时统一回落 `application/octet-stream`
- 文件: `app/core/Upload.php` 行号: 116-120、202-218
- 代码:
```php
private function getRealMimeType(): string
{
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        …
        return $mimeType ?: 'application/octet-stream';
    }
    return 'application/octet-stream';        // ← finfo 不可用时的统一回落
}
```
- 问题: ①`finfo` 读的是文件头，攻击者可用 `GIF89a<?php …?>` 这类 polyglot 让一个 PHP 脚本被识别为 `image/gif`，从而通过 `allowedTypes(['image/gif'])`；②当 `finfo` 扩展不可用（精简镜像很常见）时，**所有**文件都被判定为 `application/octet-stream`——若白名单中恰好包含该项（例如为了兼容某种文件而加入），则等于**白名单完全失效**。
- 触发场景: 上传内容为 `GIF89a` + PHP 代码，`getimagesize()` 能识别为合法 GIF，`finfo` 返回 `image/gif`，但实际是脚本。
- 建议修复: ①`finfo` 不可用时应**拒绝所有校验**（fail-closed）而非回落为 octet-stream；②对图片类型增加二次校验 `getimagesize()` + `imagecreatefromstring()` 能否解码；③图片类型一律重新编码（`imagejpeg()` / `imagewebp()`）后再落盘，彻底剥离可能的附加内容。

### [MEDIUM] U-05 `save()` 先 `mkdir` 后 `realpath` 校验（先写后校验）
- 文件: `app/core/Upload.php` 行号: 161-174
- 代码:
```php
if (!is_dir($fullPath)) {
    if (!mkdir($fullPath, 0755, true) && !is_dir($fullPath)) { … }
}
$realBase = realpath(PUBLIC_PATH);
$resolvedPath = realpath($fullPath);
if (… !str_starts_with($resolvedPath, $realBase . DIRECTORY_SEPARATOR)) { … }
```
- 问题: 目录**先被创建**，之后才校验是否越界。虽然 `sanitizePath()` 的 `str_replace('..','')` 已经消除了字面量 `..`（实测 `/....//....//etc/` → `/etc/`，落在 PUBLIC 内），但校验顺序颠倒意味着：一旦 `sanitizePath()` 的过滤被绕过或出现符号链接 TOCTOU，**越界目录已经被创建**（留下持久化的副作用）。此外创建出的目录权限硬编码 `0755`，上传目录在多用户主机上对其他账号可写。
- 建议修复: 先词法归一化并校验「目标路径的父目录前缀」，通过后再 `mkdir`；目录权限用 `0750`（或至少 `0770` 配合 umask），并在上传目录内放置禁用脚本执行的 `.htaccess` / Nginx 配置。

### [MEDIUM] U-06 `sanitizePath()` 的 `preg_replace()` 返回值未做 null 检查，返回类型可能 TypeError
- 文件: `app/core/Upload.php` 行号: 195-200
- 代码:
```php
private function sanitizePath(string $path): string
{
    $path = str_replace(['\\', '..'], ['/', ''], $path);
    $path = '/' . trim($path, '/') . '/';
    return preg_replace('#/+#', '/', $path);      // 可能返回 null
}
```
- 问题: `preg_replace()` 在发生回溯限制 / 内部错误时返回 `null`；本方法声明 `: string`，strict_types 下会抛 `TypeError`（罕见但存在）。另外该过滤是「非递归删除 `..`」：`'....'` 会被删成 `''`（实测），`'.../x'` 变成 `'./x'`（实测 `/.../etc/` 类输入不会被规范化到根外），当前实现配合 realpath 兜底尚属安全，但**正确性依赖于两个独立机制同时生效**，任何一处改动都可能打开缺口。
- 建议修复: 用与 `LocalDisk::normalizePath()` 相同的「分段词法归一化」实现（见 S3-01 建议），而不是字符串替换；并对 `preg_replace` 的返回值做 `?? $path` 保护。
---

## 4. LOW

### [LOW] V-10 `date` 规则未检查 `DateTime::getLastErrors()`，部分错误日期被规范化后接受
- 文件: `app/core/Validate.php` 行号: 426-434
- 问题: `createFromFormat()` 对 `2020-02-31` 这类溢出日期会自动规范化到 `2020-03-02`；当前靠 `$d->format($format) === $value` 恰好拦截住了。但如果格式串中含 `!`/`|`（如 `Y-m-d|`) 或调用方自定义了宽松格式，规范化可能刚好对齐而放行不合法日期。
- 建议修复: 同时检查 `getLastErrors()` 的 warning/error 计数，均为 0 才算通过。

### [LOW] V-11 规则名大小写不统一（`Required` 与 `required` 行为不同）
- 文件: `app/core/Validate.php` 行号: 213-215
- 问题: `$ruleMethod = 'validate' . ucfirst($rule);` 意味着 `Required`（首字母大写）会命中 `validateRequired` 并正常工作，而 `REQUIRED` 会命中不存在的 `validateREQUIRED` 而被判为「未知规则」。同一语义存在多种拼写，排错成本高。
- 建议修复: 在 `applyRule()` 入口统一 `strtolower(trim($rule))`，并在 `messages()` 的键查找中同样做小写归一化。

### [LOW] V-12 `confirmed` / `same` / `different` 使用 `===` 比较，无时序保护
- 文件: `app/core/Validate.php` 行号: 436-440、520-536
- 问题: 这三个规则比较的是「用户自己输入的两个字段」（如 `password` 与 `password_confirmation`），不属于服务端秘密，理论上不需要 `hash_equals`。但若框架未来把它们复用到「用户输入 vs 服务端存储值」的场景（如 `current_password`），`===` 就是可测量的时序侧信道。
- 建议修复: 保持现状但在注释中明确「仅用于同请求内的用户自填字段」；对涉及服务端值的比较新增 `hash_equals` 路径。

### [LOW] F-03 `FormRequest::errors()` 有隐式副作用，且缺少常用扩展点
- 文件: `app/core/FormRequest.php` 行号: 87-106
- 问题: ①`errors()` 在 `$this->validator === null` 时会**触发一次完整校验**（可能写 error_log、触发 V-02 的 warning），一个「只读取错误信息」的调用产生了校验副作用；②缺少 `withValidator()` / `prepareForValidation()` / `stopOnFirstFailure()` / `after()` 等 Laravel 式钩子，无法实现在验证前清洗输入（如 trim、转小写）、验证后填充字段等常见需求，开发者只能绕开 FormRequest 手写。
- 建议修复: 让 `errors()` 只读取（不触发校验），并补充 `prepareForValidation(array $data): array`、`after()` 钩子。

### [LOW] S-04 `flash($key, null)` 无法区分「读取」与「写入 null」
- 文件: `app/core/Session.php` 行号: 136-145
- 代码:
```php
public static function flash(string $key, mixed $value = null): mixed
{
    self::start();
    if ($value === null) { return self::pull('_flash_' . $key); }   // ← null 被当作读取
    $_SESSION['_flash_' . $key] = $value;
    …
}
```
- 问题: 该方法同时承担「写」与「读」两个职责，用 `$value === null` 作为区分标志。于是**无法 flash 一个 null 值**（`flash('k', null)` 变成读取操作），也无法 flash 值为 `false` 之外需要区分的场景。
- 建议修复: 拆成 `flashSet($key, $value)`（已有）与 `flashGet($key, $default)`（已有）两个显式方法，把 `flash()` 标记为 deprecated。

### [LOW] S-05 `session_set_cookie_params` 被 `@` 抑制，PHP 已自动启动会话时静默失效
- 文件: `app/core/Session.php` 行号: 19-25
- 问题: 当 `session.auto_start=1` 或 `public/index.php` 在此之前已调用过 `session_start()` 时，`session_set_cookie_params()` 会返回 `false` 且不生效——`httponly` / `samesite` / `secure` 三个安全参数**完全没有设置**，而 `@` 让调用方毫无感知。
- 建议修复: 检查返回值，失败时 `error_log('Session: failed to set cookie params (session already started?)')`；并在 `start()` 中先断言 `session_status() === PHP_SESSION_NONE`，否则明确走「已由外部启动」分支并补发安全 cookie 头。

### [LOW] E-03 `Env::load()` 未校验键名字符集；文件不存在时 `$loaded` 保持 false
- 文件: `app/core/Env.php` 行号: 25-93
- 问题: ①`=value` 这样的行会写入 `$_ENV['']`；`FOO BAR=x` 会产生带空格的键名，随后 `putenv()` 行为未定义；②`if (!file_exists($path)) { return; }` 在 return 前没有置 `self::$loaded = true`，因此每次 `env()` → `Env::get()` 之外的显式 `load()` 调用都会重复 `file_exists`；③`$value` 中的 `\0` 未过滤，`putenv` 会被截断。
- 建议修复: 键名加 `preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)` 校验并在不匹配时跳过；`$loaded` 无论成功与否都置 true（并把「文件不存在」视为正常情况）；value 过滤 `\0` 与换行。

### [LOW] CF-05 `Config` 未校验空键；`load()` 直接 `require` 未校验可读性
- 文件: `app/config/Config.php` 行号: 25-41、63-81
- 问题: ①`Config::set('')` / `Config::get('')` 会操作 `self::$items['']`，无任何提示；②`load()` 直接 `require $file`，若配置文件不可读会产生 warning 并继续（`$result` 为 `1`/`true`，被 `is_array` 过滤掉，配置静默缺失）；③没有对配置项类型做校验，`app.php` 返回非数组时静默变成空数组。
- 建议修复: 拒绝空键；`load()` 用 `is_readable()` 前置检查，失败时抛 `RuntimeException` 指明文件路径；返回值非数组时告警。

### [LOW] H-02 `Hash::encrypt($value, $key)` 显式传空串 key 时不走 `getKey()` 的非空校验
- 文件: `app/core/Hash.php` 行号: 23-37、75-86
- 问题: `$key = $key ?? self::getKey();` —— 当调用方显式传入 `''` 时，`?? ` 不生效（空串非 null），`openssl_encrypt` 会以**零密钥**继续（PHP 8 仅在部分版本抛出 `ValueError`）。虽然 `setApplicationKey('')` 已改为置 null，但直接传参这条路径没有同等保护。
- 建议修复:
```php
$key = ($key === null || $key === '') ? self::getKey() : $key;
if (strlen($key) === 0) { throw new \RuntimeException('Encryption key must not be empty.'); }
```

### [LOW] H-03 `Hash` 固定使用 BCRYPT，`> 72` 字节密码被静默截断
- 文件: `app/core/Hash.php` 行号: 8-21
- 问题: ①`PASSWORD_BCRYPT` 硬编码，`needsRehash()` 也只对比 BCRYPT，将来迁移 argon2id / bcrypt 之外的算法需要改代码而非改配置；②`password_hash(..., PASSWORD_BCRYPT)` 对超过 72 字节的输入**静默截断**（PHP 8 起会抛 `ValueError`，但 8.0–8.3 行为视版本），长密码（如把 passphrase 直接当密码）的实际强度低于用户预期；③没有对超长输入给出提示。
- 建议修复: 默认改用 `PASSWORD_DEFAULT`（跟随 PHP 版本演进到 argon2id），并对 `strlen($value) > 72` 明确抛错或提示改用预哈希（`base64_encode(hash('sha384', $value, true))`）。

### [LOW] U-07 `getExtension()` 命中危险扩展名时返回空串，调用方可能误判为「无扩展名」
- 文件: `app/core/Upload.php` 行号: 240-254
- 问题: 命中黑名单时返回 `''`（而非抛异常或 `null`）。`validate()` 中用它与 `allowedExtensions` 比对确实会失败（安全），但**任何单独使用该方法的调用方**（如日志、审计、自定义存储键）会把 `''` 当作「该文件没有扩展名」而不是「该文件非法」，从而把 `evil.php` 记录为 `evil`。
- 建议修复: 改为返回 `null` 表示「非法」，并在 `validate()` 中显式区分「无扩展名」与「非法扩展名」两种错误消息。

### [LOW] CO-04 构造函数已禁止 `*` + 凭据，使 `handle()` 中的通配符+凭据分支成为死代码
- 文件: `app/middleware/Cors.php` 行号: 26-32（对比 47-56、83）
- 问题: 构造函数对 `allowed_origins: ['*']` + `supports_credentials: true` 直接抛异常，因此第 48-53 行的「回显 Origin」分支与第 83 行的 `$wildcardWithCredentials` **永远为 false**。这不是漏洞，但属于「防御逻辑重复实现且已不可达」，后续维护者若只改 `handle()` 而忽略构造函数，会误以为该组合是被支持的。
- 建议修复: 删除死代码，只保留构造函数中的不变式检查；`handle()` 简化为「白名单命中 → 回显 + `Vary: Origin`；否则不下发 ACAO」。

### [LOW] CS-04 `_token` 为数组时依赖 `(string)` 强转才不致命
- 文件: `app/middleware/CsrfMiddleware.php` 行号: 25-30
- 代码:
```php
if ($token === null || $sessionToken === null || $sessionToken === '' || $token === ''
    || !hash_equals((string) $sessionToken, (string) $token)) { … }
```
- 问题: `$token === ''` 对数组为 false，于是走到 `(string) $token`，触发 `Array to string conversion` warning 并得到字符串 `"Array"`。虽然 `hash_equals` 随即返回 false（不会误放行），但每个畸形请求都会产生一条 warning；`$token === ''` 这一项检查也说明作者预期 token 是字符串，却没有先做 `is_string()` 断言。
- 建议修复:
```php
if (!is_string($token) || $token === '' || !is_string($sessionToken) || $sessionToken === ''
    || !hash_equals($sessionToken, $token)) { … }
```

### [LOW] OC-07 用 `method_exists` 鸭子类型判断 Response 而非 `instanceof`
- 文件: `app/middleware/OutputCache.php` 行号: 67-88
- 问题: 通过 `method_exists($response, 'getContent' / 'getStatusCode' / 'getHeaders')` 判断响应类型，任何恰好有同名方法的第三方对象都会被当作 `core\Response` 处理；而 `__toString` 的对象会被当作普通字符串丢弃。契约不明确，且每处都需重复三段判断。
- 建议修复: 改为 `instanceof \core\Response` 判断，非 `Response` 时统一 `__toString()` 兜底并在缓存中标记类型。

### [LOW] TH-04 节流键只含 IP + 路径，不区分用户 / 会话；构造函数 `mkdir` 未判返回值
- 文件: `app/middleware/Throttle.php` 行号: 17-25、56-64
- 问题: ①同一 NAT 出口（如公司、校园、移动网络）下的所有用户共享一个计数桶，一个用户的脚本会拖垮其余所有人（也可被用于「让同 IP 的他人被限流」的定向 DoS）；②构造函数 `mkdir(...)` 不检查返回值，`storage/cache` 不可写时构造成功但每次 `attempt()` 都 `fopen` 失败 → 落入 TH-01 的 fail-open；③文件驱动在多机 / 容器部署下不共享，限流形同虚设。
- 建议修复: 键中加入「已登录用户 id 或 session 指纹」（登录后按用户限流，未登录退回 IP）；`mkdir` 失败时抛异常并在构造阶段就暴露配置问题；多机部署应改用 Redis 驱动（`CacheManager` 已有该能力，可复用）。

### [LOW] RL-03 `resolveLogger()` 静默吞掉全部异常，日志丢失无任何提示
- 文件: `app/middleware/RequestLogMiddleware.php` 行号: 62-72
- 代码:
```php
try { … return $container->get('log'); } catch (\Throwable $e) { }
return null;
```
- 问题: 容器未注册 `log`、`log` 解析失败等所有情况都被静默吞掉，请求日志直接消失而无任何痕迹（排障时会误以为「没有请求进来」）。空 catch 块也使静态分析工具无法提示。
- 建议修复: 至少 `error_log('RequestLog: logger unavailable — ' . $e->getMessage())` 一次（可用静态标记避免每请求刷屏）。

### [LOW] HC-04 响应头按大小写原样作键；`delete()` 无 body 参数；异常构造中夹带赋值表达式
- 文件: `app/core/HttpClient.php` 行号: 132-148、59-62、160-167
- 代码:
```php
throw new HttpClientException(
    "HTTP request failed with status {$status}",
    $errNo = 0,          // ← 实参位上的赋值表达式
    null,
    $response
);
```
- 问题: ①`HEADERFUNCTION` 用响应头的**原始大小写**作数组键，`Set-Cookie` 与 `set-cookie` 会成为两个不同条目，`HttpResponse::header()` 虽然做了 `strcasecmp` 但只返回首个（多值头丢失）；②`delete()` 不接受 body，无法发送 `DELETE` + JSON；③`$errNo = 0` 作为实参表达式虽然合法但极易被误读为「沿用上次的错误码」；④无重试机制、无幂等键支持。
- 建议修复: 响应头键统一小写（对外 `headers()` 时再按需恢复）；`delete($url, $body = null, $options = [])`；把 `$errNo` 改为普通赋值语句；为幂等方法提供可选的 `retry` 与 `idempotency_key`。

### [LOW] CMG-02 `CacheManager` 未校验 `default` 是否存在于 `stores`；`extend()` 只清缓存不清已分发实例
- 文件: `app/cache/CacheManager.php` 行号: 33-37、82-86
- 问题: ①构造函数不做 `default ∈ stores` 校验，配置写错时首次访问才抛「Unsupported cache driver」（且错误信息不含 store 名，见 CMG-01）；②`extend()` 只 `unset($this->drivers[$name])`，已经分发出去的旧实例仍被业务代码持有，换驱动后行为不一致。
- 建议修复: 构造函数断言 `default` 存在并给出明确错误；`extend()` 后向已持有实例的调用方发出弃用提示，或改为不可变引用（`$this->drivers` 存类名而非实例，每次调用解析）。

### [LOW] FA-02 `Facade::$resolved` 是跨子类共享的单一数组，`clearResolved()` 清空全部
- 文件: `app/core/Facade.php` 行号: 20、33、53-57
- 问题: `protected static array $resolved` 声明在基类上，因此**所有** Facade 子类共享同一个数组（仅键不同）。`clearResolved()` 虽然按 `static::class` 写入，但 `static::$resolved = []` 实际清空的是整张表。测试中只想重置某一个 Facade 却影响了全部，语义不符直觉。
- 建议修复: 把 `$resolved` 改为「按类分离的存储」——例如改为实例级注册表（`Container::instance()` 本身就是单例，Facade 缓存意义不大），或让 `clearResolved(?string $class = null)` 支持按类清理。

### [LOW] CMD-06 `Console::run()` 不捕获异常；命令名原样 echo；ANSI 颜色不剥离
- 文件: `app/core/console/Console.php` 行号: 28-54、65-99
- 问题: ①`return $command->handle();` 没有 try/catch，命令抛异常时直接把 PHP 栈打到 stdout（对 `serve` 之外的命令而言是信息泄露，也让 CI 日志被栈污染）；②`echo "Command '{$commandName}' not found.";` 直接插入 `$argv[1]`，用户可在命令名中注入 ANSI 转义序列操纵终端输出；③`info()/error()/warn()` 无条件输出 ANSI 颜色，在非 TTY（CI 日志、cron 输出、`nohup` 文件）中会留下 `^[[32m` 之类的乱码。
- 建议修复:
```php
if (isset($this->commands[$commandName])) {
    try { return $this->commands[$commandName]->handle(); }
    catch (\Throwable $e) { fwrite(STDERR, $e->getMessage() . PHP_EOL); return 1; }
}
$safe = preg_replace('/[\x00-\x1F\x7F]/', '', $commandName);
// …
protected function colorize(string $s, string $code): string
{ return $this->supportsColor() ? "\033[{$code}m{$s}\033[0m" : $s; }
```
  并用 `stream_isatty(STDOUT)`（或 `posix_isatty`）判定。

### [LOW] CMD-07 `Command::table()` 列宽按字节计算；行数组长于表头时访问未定义下标
- 文件: `app/core/console/Command.php` 行号: 191-220
- 问题: ①`strlen()` / `str_pad()` 按**字节**工作，中文 / emoji 列宽全部错位（一个汉字占 2 字节但终端占 2 列，看起来「凑巧」，emoji 则完全错乱）；②循环中 `$widths[$i]` 在行元素多于表头时未定义 → warning + `str_pad($cell, null)` TypeError；③`$widths` 未处理多字节与 ANSI 转义序列的显示宽度。
- 建议修复: 使用 `mb_strwidth($s, 'UTF-8')` 计算显示宽度并手写补空格（或引入 `Symfony\Console` 风格的 `Helper::width()`）；对 `$i` 做 `isset` 检查。

### [LOW] CMD-08 `parseSignature()` 按空格切分签名，含空格的默认值与描述不可用
- 文件: `app/core/console/Command.php` 行号: 110-128
- 问题: `explode(' ', $this->signature)` 使得 `{name=John Doe}`（默认值含空格）被拆成 `{name=John` 与 `Doe}` 两段，后者被当作一个 argument 定义；同样地签名无法携带描述文本。
- 建议修复: 用 `preg_split('/\s+(?=\{|$)/', …)` 只在 `{` 前切分，或改用带引号支持的 tokenizer。

### [LOW] GEN-04 `getTables()` 依赖默认库名构造结果键；`generateController()` 的 `$withModel` 参数完全未使用
- 文件: `app/core/Generator.php` 行号: 29-40、95-118
- 问题: ①`$key = 'Tables_in_' . $db->getDatabase();` 依赖 MySQL 的默认行为，库名含特殊字符或使用非 MySQL 驱动时键名不匹配 → `array_column` 返回空数组（静默失败）；②`generateController(string $table, ?string $controllerName = null, bool $withModel = true)` 的 `$withModel` 在函数体内从未被引用（`generateAll()` 也没有「只生成控制器」的分支），调用方传 `false` 完全没有效果。
- 建议修复: ①改用驱动无关的查询（`SHOW FULL TABLES` 后按 `array_column($rows, …, 0)`）或对非 MySQL 抛明确异常；②实现 `$withModel` 语义，或删除该参数并在文档中说明控制器生成为何总是包含模型。
---

## 5. 已验证正确（无需修改）

以下项经通读与实测确认实现正确，记录以避免重复审查：

**Validate**
- 未知规则不会**静默放行**，而是记为校验失败并 `trigger_error`（仅告警方式有问题，见 V-02）。
- `splitRules()` 对 `regex:` 规则做了 delimiter-aware 切分，能正确处理正则中的 `|` 与转义（`isRegexClosed()` 的反斜杠计数逻辑正确）。
- `validated()` 只返回「在规则中声明且无错误」的字段，不会把未声明的输入透传出去（这是正确且关键的安全属性）。
- `validated()` / `errors()` / `passes()` / `fails()` / `firstError()` 的边界处理正确（`firstError(null)` 返回任意首个错误）。

**Session**
- `session_set_cookie_params` 使用 `httponly => true`、`samesite => 'Lax'`，并通过 `Request::isSecureFromServer()` 正确尊重可信代理的 `X-Forwarded-Proto`（CHANGELOG:132 的修复确已落地）。
- `session.use_strict_mode = 1` 在 `session_start()` 之前设置，防 session fixation 的服务端校验生效。
- `ageFlash()` 的两轮 `_flash_old` / `_flash_new` 老化逻辑正确，且正确保留了「本轮重新 flash 的同一 key」，不会误删。
- `flush()` 后 `$started = false` 允许重新启动；PHP 8 下 `session_destroy()` 会把状态置回 `PHP_SESSION_NONE`（实测 `status_after_destroy=1`，随后 `session_start()` 成功并分配新 ID），无残留状态问题。
- `flush()` 清除 cookie 时正确回传了 `path` / `domain` / `secure` / `httponly` / `samesite`。

**Cookie**
- 默认 `httponly = true`、`samesite = 'Lax'`，且 `json_encode` 失败时返回 `false` 而非写入空 cookie（错误处理态度正确）。
- `setcookie()` 使用**数组形式 options**（PHP 7.3+），`samesite` 可用；`$expires` 用 `time() + $expire` 计算正确。

**Hash**
- `encrypt()` 正确使用 AES-256-GCM，每次生成随机 12 字节 IV，并把 16 字节 tag 一并存储；`decrypt()` 长度检查、IV/tag/密文切分与 `openssl_decrypt` 的 tag 传参**均正确**。
- `getKey()` 统一用 `substr(hash('sha256', $key, true), 0, 32)` 派生 32 字节密钥，正确处理了任意长度的 `APP_KEY`（含 `base64:` 前缀场景也不会退化）。
- `setApplicationKey('')` 置为 null 并把校验延后到 `getKey()`，避免了空密钥被静默使用（CHANGELOG:383 的加固在 :276 被有意回退，当前实现是正确取舍）。
- `password_verify` / `password_needs_rehash` 使用正确。

**Captcha**
- `verify()` 使用 `hash_equals` 比较，**时序安全**。
- `chars()` 默认字符集已排除易混淆的 `0/O/1/I/l`，且生成用 `random_int()`（CSPRNG）而非 `rand()`。
- 验证码过期检查（`ttl`，默认 300s）与成功后的清理逻辑正确（CHANGELOG:274 的修复已落地）。
- `generate()` 用 `try/finally` 重置视觉配置，避免长驻进程中的状态污染，思路正确。

**CsrfMiddleware**
- 使用 `hash_equals` 比较 token，**时序安全**（部分缓解 CS-01 的问题）。
- 安全方法豁免列表为 `GET / HEAD / OPTIONS`，正确（PUT/PATCH/DELETE 均受保护）。
- 同时接受表单字段 `_token` 与 `X-CSRF-TOKEN` 头，兼容传统表单与 AJAX。

**LocalDisk**
- `normalizePath()` 的词法遍历正确拒绝**任何** `..` 段（含 URL 编码之外的纯词法形式），且能正确处理前导 `/`、重复 `/`、`.` 段（实测 `/a//b`、`.`、`''` 段均被规范化）。
- 不依赖 `realpath()`，因此对**尚未创建**的文件/目录也能正确工作（这是刻意且正确的设计选择）。
- `put()` 的字符串分支使用了 `LOCK_EX`，`resource` 分支用 `try/finally` 保证 `fclose`，无句柄泄漏。

**Middleware**
- `shouldSkip()` 对 `$except` 做了 `preg_quote` 后再把 `\*` 换成 `.*`，避免配置中的正则元字符被当作模式（方向正确，语义问题见 MW-03）。
- `parse_url` 失败时对 `null` 做了 `(string)` 转换，不会因返回类型产生 TypeError。

**Throttle**
- `attempt()` 用 `flock(LOCK_EX)` + `ftruncate` + `rewind` + `fwrite` 消除了经典的 TOCTOU 竞态（CHANGELOG:275 之后的加固确已落地），并用 `finally` 释放锁。
- 缓存键使用 `hash('sha256', $ip)` 而非裸 IP，避免了文件名注入与目录穿越。
- 读取时校验 `expire > 0 && expire > time()` 才复用计数，避免脏数据导致永久限流。

**Logger**
- 写文件使用 `FILE_APPEND | LOCK_EX`，并发写入不会交错（这是文件日志的关键正确点）。
- `interpolated` 与 `remainingJson` 都做了 `\r\n` / `\r` / `\n` → 空格替换，**有效阻止了日志换行注入**（伪造日志行）。
- `clear()` 用 `preg_match('/^\d{4}-\d{2}-\d{2}$/')` 校验日期，路径穿越被正确阻断。
- `setLevel()` / `log()` 都对未知 level 做了校验，不会写入无法识别的级别。
- `remainingContext()` 正确排除了「已被消息模板插值」与「数组 / 无 `__toString` 的对象」，避免重复输出与递归序列化。

**Upload**
- `getExtension()` 检查文件名中**所有**扩展名部分（防双扩展名绕过），而不是只看最后一个。
- `save()` 的存储文件名使用 `bin2hex(random_bytes(16))`，彻底消除了文件名注入与同名覆盖。
- `validate()` 中「危险扩展名」检查在 `allowedExtensions` 之后**再次执行**，不依赖调用方是否配置了白名单。
- `move_uploaded_file()`（而非 `rename()`）确保了只有真实上传的文件能被移动，且通过了 `is_uploaded_file()` 校验。
- `save()` 有 `realpath` + `DIRECTORY_SEPARATOR` 的目录白名单校验（**注意：这一点与 LD-01 中 Loader 的同类缺陷形成鲜明对比，Upload 写对了，Loader 写错了**）。

**EventDispatcher**
- `listen()` / `forget()` 都正确清空了 `wildcardCache` 与 `wildcardRegexCache`，不存在「注册后缓存不失效」的 bug。
- `matchWildcard()` 中 `preg_quote($pattern, '#')` 后再把 `\*` 替换为 `[^.]+`，正确实现了「单段通配、不跨 `.`」的语义，且对 `$`、`.`、`(` 等元字符做了转义。
- `dispatch()` / `until()` 都用 `finally` 弹出 `dispatchingStack`，异常路径下递归检测状态不会泄漏。
- `wildcardCache` 有 1024 条上限并整表清空，避免了长驻进程派发大量唯一事件名导致的内存泄漏。

**Collection**
- `map()` 用 `array_keys()` + `array_combine` 保留键名，并正确处理了空集合（`empty($keys) ? [] : …`），无 `array_combine()` 返回 false 的坑。
- `filter()` 使用 `ARRAY_FILTER_USE_BOTH`，回调收到 `(value, key)`，与文档一致。
- `each()` 支持回调返回 `false` 提前中断，且不修改集合（不可变链式语义正确）。
- `pull()` / `forget()` 是唯一会原地修改的 mutator，其它方法均返回新实例，语义一致且明确。
- `toJson()` 在 `json_encode` 失败时回退 `'[]'` 而非返回 `false`，避免了向下游传播错误类型。
- 接口实现（`IteratorAggregate` / `ArrayAccess` / `Countable` / `JsonSerializable`）完整，`__toString()` 提供了一致的 JSON 表示。

**Facade / Loader / Macroable / console / Storage / CacheManager / HttpClient / ApiDoc / JsonResource**
- `Facade::__callStatic` 只做静态代理，`$resolved` 按 `static::class` 分键，跨 Facade 不串味（仅「换容器不清缓存」问题，见 FA-01）。
- `Loader::autoload()` 确实做了 realpath 存在性检查（`realpath($file) === false` 时 `continue`），且 `addNamespace()` 会对前缀补 `\\` 与路径补 `/`，无低级错误。
- `Macroable::__call` / `__callStatic` 在宏不存在时抛 `BadMethodCallException` 而非静默返回，错误可见。
- `Console::run()` 对未知命令返回退出码 `1`，`list` / `--help` / `--version` 三个内建分支正确。
- `LocalDisk::files()` / `directories()` 正确跳过 `.` 与 `..`，并对结果 `sort()` 保证稳定顺序。
- `Storage::disk()` 对未配置盘与不支持的驱动都抛 `InvalidArgumentException` 并带上盘名/驱动名，错误信息可定位（对比 CacheManager 的 CMG-01）。
- `CacheManager::extend()` 会 `unset` 已缓存的驱动实例，扩展后不会继续使用旧驱动。
- `HttpResponse::header()` 用 `strcasecmp` 做大小写无关匹配，`ok()` / `failed()` 边界正确（`status >= 200 && < 300`）。
- `HttpClient::hasHeader()` 大小写无关判断正确；`finally { curl_close($ch); }` 保证句柄释放；`$status` 用 `CURLINFO_HTTP_CODE` 读取正确。
- `JsonResource::resolve()` 单资源与集合两条路径都能正确合并 `with()` 与 `additional()`；`resourceToArray()` 对数组 / `toArray()` / `JsonSerializable` 三种输入的处理顺序正确。