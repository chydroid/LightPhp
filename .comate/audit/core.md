# LightPHP 核心 HTTP 层安全与健壮性审计报告

- 审计范围：`app/core/Application.php`、`Router.php`、`Request.php`、`Response.php`、`Pipeline.php`、`Controller.php`、`attributes/Route.php`、`exception/*`、`FormRequest.php`、`ExceptionHandler.php`、`public/index.php`、`app/route/*.php`、`app/middleware/*`
- 审计日期：2026-10-03
- 分支：`cline/55bfe`（commit `f2dd2a2`）
- 方法：全文件通读（Router.php 869 行、Request.php 533 行均分页读完）+ PHP 8.4.7 CLI 运行时复现
- 结论：**发现 5 个严重、8 个高危、14 个中危、16 个低危问题**。其中 CORE-01、CORE-02、CORE-05 为"默认配置下即触发"的缺陷。

## 缺陷统计

| 级别 | 数量 | 编号 |
|---|---|---|
| 严重 Critical | 5 | CORE-01 ~ CORE-05 |
| 高 High | 8 | CORE-06 ~ CORE-13 |
| 中 Medium | 14 | CORE-14 ~ CORE-27 |
| 低 Low | 17 | CORE-28 ~ CORE-44 |

---

# 一、严重（Critical）

### [严重] CORE-01 闭包路由参数按"命名参数"展开，参数名不匹配即致命错误
- 文件: app/core/Router.php  行号: 700-705
- 代码:
```php
private function executeHandler(callable|array $handler, array $params, Request $request): mixed
{
    // 闭包直接执行
    if ($handler instanceof \Closure) {
        return $handler(...$params);   // ← $params 的键是字符串（路由占位符名）
    }
```
- 问题: `matchRoute()` 返回的参数数组键全部是**字符串**（`['path' => '...']`，见 Router.php:616-623）。PHP 8.1+ 中 `...$array` 对含字符串键的数组按**命名参数**展开。闭包形参名必须与路由占位符名**完全一致**，否则抛 `Error: Unknown named parameter $xxx`——这是 `Error` 而非 `Exception`，无法被 `catch (\Throwable)` 之外的业务逻辑处理，且会被 `Application::handleException` 渲染为 500。实际上闭包路由仅在"占位符名 == 形参名"这一巧合下可用，属于设计级缺陷（Laravel/Symfony 均使用 `array_values()` 位置展开）。
- 触发场景: 已实测复现：
```php
$router->get('/files/{path}', fn($p) => new Response('x'));
// GET /files/a
// Fatal error: Uncaught Error: Unknown named parameter $path
//   at app/core/Router.php:704
```
- 建议修复: 位置展开，或按形参名智能映射（二者取其一，推荐前者以匹配主流框架语义）：
```php
if ($handler instanceof \Closure) {
    $ref = new \ReflectionFunction($handler);
    if ($ref->isVariadic() || $ref->getNumberOfParameters() > 0) {
        return $handler(...array_values($params));
    }
    return $handler();
}
```
  同时对 `$params` 为空且闭包无参的情况保持兼容（现有 `$handler(...[])` 亦可，但显式分支更清晰）。

### [严重] CORE-02 Attribute 方法级 middleware 被静默丢弃（授权绕过）
- 文件: app/core/Router.php  行号: 425-434（配合 app/core/attributes/Route.php 21-28）
- 代码:
```php
$attrs = $method->getAttributes(\core\attributes\Route::class, \ReflectionAttribute::IS_INSTANCEOF);
foreach ($attrs as $attr) {
    $route = $attr->newInstance();
    $httpMethod = strtoupper($route->method);
    $this->addRoute($httpMethod, $route->path, [$controllerClass, $method->getName()]);
    if ($route->name !== null) {
        $this->name($route->name);
    }
    $count++;
}
// ← $route->middleware 从未被读取；类级 $middleware 被套用到全部方法
```
- 问题: `core\attributes\Route` 定义了 `middleware` 属性且注释明确"方法级 #[Route(...)]"，但 `registerController()` 只消费 `path/method/name` 三项。方法上写的 `middleware: ['auth']` 被**无声丢弃**，开发者以为受保护实则裸奔。类级 middleware 反而会被强制应用到该控制器**所有**方法（including 公开的 `index()`），语义相反且更危险。
- 触发场景: 实测复现：
```php
#[\core\attributes\Route(prefix: '/p', middleware: ['cors'])]
class C {
    #[\core\attributes\Route('/secure', method: 'GET', middleware: ['auth'])]
    public function secure() {}
}
// 注册结果：GET /p/secure  mw=["cors"]   ← 'auth' 完全消失
```
- 建议修复: 合并类级与方法级中间件（方法级追加，且支持剔除 `!` 前缀）：
```php
$methodMiddleware = array_merge($middleware, $route->middleware ?? []);
$previous = $this->middlewares;
if ($methodMiddleware) { $this->middlewares = array_merge($previous, $methodMiddleware); }
try { $this->addRoute($httpMethod, $route->path, [$controllerClass, $method->getName()]); }
finally { $this->middlewares = $previous; }
```
  并在 `registerController()` 入口对"方法级 middleware 非空但未被消费"的历史行为加 `trigger_error()` 级别的告警，防止回归。

### [严重] CORE-03 路由参数 urldecode 导致 `%2F`→`/`，可造成路径穿越
- 文件: app/core/Router.php  行号: 613-624
- 代码:
```php
$result = preg_match($regex, $uri, $matches);
if ($result === 1) {
    $params = [];
    foreach ($matches as $key => $value) {
        if (is_string($key)) {
            // urldecode 值，避免控制器收到 %20 等编码后的值
            $params[$key] = is_string($value) ? urldecode($value) : $value;
        }
    }
```
- 问题: 路由编译使用 `(?<name>[^/]+)`（Router.php:591），匹配阶段发生在**未解码**的 URI 上，因此 `%2F` 会被当作普通字符匹配进参数，随后 `urldecode()` 把它还原成真正的 `/`。控制器拿到的"路径参数"因此可以包含任意数量的 `/` 与 `..`。任何 `include`/`file_get_contents`/`fopen`/`unlink` 使用该参数的代码将被穿越。Symfony 默认返回原始值并提供 `get()` 显式解码，正是为了避免这一点。
- 触发场景: 实测复现：
```php
$router->get('/files/{path}', fn($path) => new Response("got:[$path]"));
// GET /files/a%2F..%2F..%2Fetc%2Fpasswd
// => got:[a/../../etc/passwd]
```
- 建议修复: 保留原始值，另提供显式解码接口：
```php
$params[$key] = $value;                       // 原始值，安全
// 需要解码时由控制器显式调用：
// $request->route('path')  → 内部 rawurldecode + '/' 合法性校验
```
  若必须保留自动解码，则应拒绝解码后含 `/`、`\0` 或 `..` 的值并记日志。

### [严重] CORE-04 路由缓存 `var_export` 序列化对象中间件 → 缓存文件不可加载（致命错误）
- 文件: app/core/Router.php  行号: 847-868 与 825-839
- 代码:
```php
public function cacheRoutes(string $cacheFile): bool
{
    // 检查是否包含闭包路由，闭包无法被 var_export 序列化
    foreach ($this->routes as $route) {
        if ($route['handler'] instanceof \Closure) { return false; }   // ← 只查 handler
    }
    ...
    $export = var_export($data, true);
    $content = '<?php return ' . $export . ';';
    return file_put_contents($cacheFile, $content, LOCK_EX) !== false;
}
```
- 问题: 闭包检查只覆盖 `handler`，**完全跳过 `middleware`**。而官方文档（docs/ecommerce-full-tutorial.md:265）推荐 `middleware: [new \middleware\Cors([...])]`。`var_export()` 遇到对象会输出 `\middleware\Cors::__set_state(array(...))`，而该类没有实现 `__set_state()` → 缓存文件 `require` 时抛 `Error: Call to undefined method middleware\Cors::__set_state()`，**每个请求都崩，全站 500**。同类问题还包括 handler 为可调用对象、middleware 数组内含闭包（输出成 `Closure::__set_state`）。此外缓存文件是可执行 PHP，`require` 时不做任何结构/来源校验。
- 触发场景: 实测复现：
```php
$router->group(['middleware' => [new \middleware\Cors(['allowed_origins'=>['*']])]]],
    fn($r) => $r->get('/o', ['stdClass','x']));
$router->cacheRoutes($f);          // true（误报成功）
// 缓存内容含：\middleware\Cors::__set_state(array('config' => array(...)))
require $f;                        // Error: Call to undefined method
```
- 建议修复:
  1. 递归扫描 `handler` 与 `middleware`（含嵌套数组/对象/闭包），任一不可序列化则拒绝缓存；
  2. 改用 `serialize()` + `unserialize($data, ['allowed_classes' => false])`，或仅缓存纯标量路由表并在运行时反射还原；
  3. 缓存文件写入 `<?php /* die */ ?>` 前缀 + 写入后 `opcache_invalidate()`；
  4. `loadCachedRoutes()` 对每条路由做 `method/uri/handler` 必需键校验，失败则回退到解析路由文件并记日志。

### [严重] CORE-05 随包应用的全部 `/api/*` 路由 500（中间件别名 `cors` 从未注册）
- 文件: app/route/route.php 行号: 14；app/route/web.php 行号: 14；app/core/Application.php 行号: 104-133
- 代码:
```php
// app/route/route.php:14
$router->group(['prefix' => '/api', 'middleware' => ['cors']], function($router) { ... });

// app/core/Application.php:104-133  registerServices()
$this->container->instance('config', $this->config);
$this->container->singleton('router', fn() => $this->router);
$this->container->singleton('events', fn() => $this->events);
// …无任何 aliasMiddleware('cors', ...) 注册；app.php 的 'providers' 也从未被读取
```
- 问题: 框架内没有任何代码注册 `cors` 别名（仅 docs/ 与 tests/ 中出现）。`resolveMiddleware()` 把未知的字符串原样透传，`executeMiddleware()` 在 `class_exists('cors')` 为 false、`is_callable('cors')` 为 false 时抛 `RuntimeException: Invalid middleware: cors`。结果：开箱即用的骨架项目中**每一个 `/api` 端点都返回 500**。根因链还有两环：(a) `config/app.php` 的 `providers` 键从未被 `Application` 读取；(b) `Router::load()`（Router.php:364-373）只合并 routes，丢弃了文件内 Router 的 `middlewareAliases/middlewareGroups/globalMiddleware`，因此即使在路由文件里写 `$router->aliasMiddleware(...)` 也不会生效。
- 触发场景: 实测复现：
```
routes=8
GET /api/users  →  EX: RuntimeException: Invalid middleware: cors
```
- 建议修复:
  1. `Application::registerServices()` 之后、`run()` 之前，从 `config('app.providers')` 实例化并注册服务提供者，并在内置提供者中注册 `cors/throttle/csrf/auth` 别名；
  2. `Router::load()` 合并 aliases/groups/globalMiddleware/namedRoutes；
  3. 启动时做一次完整性自检：遍历所有路由的 middleware 列表，遇到既非类、又非可调用、又非已知别名的项即抛出**明确的**启动期异常（而非请求期 500）；
  4. 从 `app/route/` 中删除与 web.php 重复的 route.php（见 CORE-13）。

---

# 二、高危（High）

### [高] CORE-06 `Router::group()` 回调抛异常时不恢复分组状态 → 路由表永久污染
- 文件: app/core/Router.php  行号: 337-357
- 代码:
```php
public function group(array $attributes, callable $callback): self
{
    $previousGroup = $this->group;
    $previousMiddleware = $this->middlewares;
    ...
    $callback($this);              // ← 抛异常则下面两行永不执行
    $this->group = $previousGroup;
    $this->middlewares = $previousMiddleware;
    return $this;
}
```
- 问题: 缺少 `try/finally`。任何一个分组回调内的语法/运行时异常都会让 `prefix` 与 `middlewares` 永久停留在异常发生时的状态，**之后注册的所有路由**都会继承错误的路径前缀与中间件。异常通常被上层吞掉（`Application::run()` catch 后渲染错误页），因此这个"脏状态"很难被发现，且随进程内后续注册持续放大。
- 触发场景: 实测复现：
```php
$router->group(['prefix'=>'/admin','middleware'=>['X']], function($r){ throw new RuntimeException('boom'); });
// catch 之后：
$router->get('/public', fn() => 'x');
// 实际注册为：  /admin/public   middleware=["X"]   ← 完全错误
```
- 建议修复:
```php
try { $callback($this); }
finally { $this->group = $previousGroup; $this->middlewares = $previousMiddleware; }
return $this;
```

### [高] CORE-07 `parse_url()` 处理 authority-form REQUEST_URI → 路由混淆
- 文件: app/core/Router.php  行号: 490-494
- 代码:
```php
$method = $request->method();
$uri = '/' . trim((string) parse_url($request->uri(), PHP_URL_PATH), '/');
```
- 问题: `Request::uri()` 返回原始 `REQUEST_URI`（Request.php:201-204）。当 `REQUEST_URI` 以 `//` 开头时，`parse_url()` 会按 **authority 形式**解析，把首段当作 host。例如 `GET //admin/delete-all HTTP/1.1` 被解析为 host=admin、path=/delete-all，最终匹配到 `/delete-all` 路由。这类"路径归一化差异"是经典的路由绕过向量：WAF/审计日志/限流看到的路径与实际分发的路由不一致；`Response::redirect()` 已专门拦截 `//` 协议相对 URL，但路由层没有做同样处理。
- 触发场景: 实测复现：
```
uri[//evil.com/admin]  =>  /admin      ← 被当作 host:evil.com + path:/admin
uri[///]               =>  /
```
- 建议修复: 先做规范化再交给 `parse_url`，并拒绝含反斜杠、NUL、控制字符的 URI：
```php
$raw = $request->uri();
if (str_contains($raw, '\\') || preg_match('/[\x00-\x1F\x7F]/', $raw)) {
    return $this->handleNotFound();
}
$path = parse_url($raw, PHP_URL_PATH);
$uri = '/' . trim(is_string($path) ? $path : '/', '/');
$uri = preg_replace('#/+#', '/', $uri);          // 折叠重复斜杠
```

### [高] CORE-08 HEAD 请求复用 GET 路由但仍输出完整响应体
- 文件: app/core/Router.php  行号: 497-499；app/core/Response.php  行号: 197-219
- 代码:
```php
// Router.php
// HEAD 请求应匹配 GET 路由（HTTP 规范）
$methodMatch = $route['method'] === $method
    || ($method === 'HEAD' && $route['method'] === 'GET');
...
// Response::send()
if ($this->filePath !== null) { readfile($this->filePath); } else { echo $this->content; }
```
- 问题: 匹配逻辑正确，但输出阶段没有区分 HEAD。RFC 9110 规定 HEAD 响应必须与 GET 相同的状态码与响应头，但**不得**包含消息体。当前实现会把整个页面/文件（含 `readfile()` 的完整文件）发给 HEAD 客户端：对健康检查、代理预热、`curl -I` 造成带宽浪费与协议违规；`Response::download()` 场景下会真正把文件内容吐出去。
- 触发场景: 实测复现：
```
HEAD /h  →  status=200  body='BODY'      ← 应为空
```
- 建议修复:
```php
// Response::send()
$isHead = (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD');
if (!$isHead) {
    if ($this->filePath !== null) { readfile($this->filePath); } else { echo $this->content; }
}
```

### [高] CORE-09 无 405 语义、无 `Allow` 头、无自动 OPTIONS 处理
- 文件: app/core/Router.php  行号: 496-513
- 代码:
```php
foreach ($this->routes as $route) {
    if (!$methodMatch) { continue; }
    $params = $this->matchRoute($route['uri'], $uri);
    if ($params !== false) { ... return $this->executeMiddleware(...); }
}
return $this->handleNotFound();     // 方法不匹配 → 一律 404
```
- 问题: 当 URI 存在但方法不允许时返回 404 而非 405，且不返回 `Allow` 头。后果：(a) 客户端/SDK 无法区分"资源不存在"与"方法错误"；(b) 缺少 `Allow` 不符合 HTTP 规范；(c) 未注册 `OPTIONS` 路由时预检请求直接落到 404/500（本项目的预检依赖 `Cors` 中间件兜底，但该中间件只在路由成功匹配后才执行——见 CORE-05，预检现状是 500）。
- 触发场景: 实测复现：`POST /u`（仅注册了 `GET /u`）→ `404 <h1>404 Not Found</h1>`。
- 建议修复: 两遍扫描——第一遍记录"URI 命中但方法不匹配"，循环结束后若存在候选则返回 405 并带 `Allow`：
```php
$allowed = [];
foreach ($this->routes as $route) {
    if ($this->matchRoute($route['uri'], $uri) === false) continue;
    if ($methodMatch) { /* 执行并 return */ }
    $allowed[$route['method']] = true;
}
if ($allowed) {
    return Response::make('<h1>405 Method Not Allowed</h1>', 405)
        ->header('Allow', implode(', ', array_keys($allowed)));
}
return $this->handleNotFound();
```
  另可在 `dispatch()` 入口对无显式 OPTIONS 路由的请求自动回 `Allow` + `204`。

### [高] CORE-10 `Application::run()` 无输出缓冲 → 异常时状态码不可改、内容拼接
- 文件: app/core/Application.php  行号: 173-211 与 293-345
- 代码:
```php
public function run(): void
{
    try {
        $result = $this->router->dispatch();   // 期间控制器/视图可能已 echo
        ...
    } catch (\Throwable $e) { $this->handleException($e); }
}

private function handleException(\Throwable $e): void
{
    if ($e instanceof \core\exception\HttpException) {
        $statusCode = $e->getHttpStatusCode();
        if (!headers_sent()) {          // ← 已输出则跳过，静默降级为 200
            http_response_code($statusCode);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([...]);        // ← 追加到已输出的半截页面之后
```
- 问题: 全流程没有 `ob_start()`。控制器 `echo`、视图渲染中途抛异常、`readfile()` 部分输出后抛异常等场景下：
  1. `headers_sent()` 为 true → `http_response_code()` 被跳过 → 客户端收到 **200 OK** + 半截页面 + JSON 错误体；
  2. 即使状态码改成功，响应体也是"残缺 HTML + JSON"拼接的非法 JSON，前端解析必然失败；
  3. 已按半截内容发出 `Content-Length` 时还会造成协议层截断。
  HttpException 与 ValidationException 两个分支（307-337）**完全没有 ob 清理**，只有 500 分支在渲染自定义视图时做了层数回滚（374-379）。
- 触发场景: 控制器 `echo '<div>'; throw new HttpException(500, 'boom');` → 响应为 `200`，body = `<div>{"error":{"code":500,...}}`。
- 建议修复:
```php
$level = ob_get_level(); ob_start();
try {
    $result = $this->router->dispatch();
    // ... 原发送逻辑
} catch (\Throwable $e) {
    while (ob_get_level() > $level) { ob_end_clean(); }   // 丢弃半截输出
    $this->handleException($e);
} finally {
    while (ob_get_level() > $level) { ob_end_flush(); }
}
```

### [高] CORE-11 `handleException` 泄露 HttpException 原始消息，且 `core\ExceptionHandler` 从未接入
- 文件: app/core/Application.php  行号: 307-337；app/core/ExceptionHandler.php  行号: 143-203
- 代码:
```php
// Application::handleException —— 唯一真正生效的异常渲染路径
if ($e instanceof \core\exception\HttpException) {
    $statusCode = $e->getHttpStatusCode();
    if (!headers_sent()) { http_response_code($statusCode); header('Content-Type: application/json; charset=utf-8'); }
    echo json_encode(['error' => ['code' => $statusCode, 'message' => $e->getMessage()]], JSON_UNESCAPED_UNICODE);
    return;
}
// 对比 ExceptionHandler::renderHttpException()（死代码）：
$message = $this->debug ? $e->getMessage() : (self::SAFE_MESSAGES[$statusCode] ?? '请求处理失败');
```
- 问题: 双套异常渲染并存，而 `core\ExceptionHandler` 在整个 HTTP 链路上**从未被实例化**（全仓仅 tests/docs 引用），因此它实现的 `SAFE_MESSAGES` 白名单、可信 IP 限制、JSON/HTML 内容协商全部是死代码。实际生效的 `Application::handleException`：
  1. 无条件把 `HttpException::getMessage()` 输出给客户端（生产环境亦然）；
  2. 不做内容协商——浏览器导航 403 得到 JSON；
  3. `dontReport` / `dontFlash` 机制形同虚设；
  4. `Application` 分支也没有 `$this->debug` 判断，安全消息表形同虚设。
- 触发场景: 控制器 `throw new \core\exception\HttpException(403, 'DB user=app lacks grant on orders');` → 生产环境客户端直接收到该字符串。
- 建议修复: 让 `handleException()` 委托给 `ExceptionHandler`，并把 Request 注册进容器：
```php
private function handleException(\Throwable $e): void
{
    $handler = new ExceptionHandler($this->container->get('log'), (bool) $this->getConfig('app.debug', false));
    $handler->report($e);
    $request = $this->container->has('request') ? $this->container->get('request') : new Request();
    $handler->render($request, $e)->send();
}
```

### [高] CORE-12 FormRequest 用 `all()`（含查询串）作为验证数据源 → 批量赋值/提权
- 文件: app/core/FormRequest.php  行号: 71-93；app/core/Request.php  行号: 294-300
- 代码:
```php
// FormRequest::validate()
$validator = new Validate();
$validator->rules($this->rules())->messages($this->messages());
$ok = $validator->validate($this->all());     // ← all() = GET + JSON + POST
$this->validatedData = $ok ? $validator->validated() : [];

// Request::all()
if ($this->json !== null) { return array_merge($this->get, $this->json, $this->post); }
return array_merge($this->get, $this->post);
```
- 问题: GET 查询串优先级最低但**依然参与验证并进入 `validated()`**。攻击者可以仅用 URL query 满足 `required` 规则并把值送进批量赋值逻辑：
  1. **提权**：`rules()` 只声明 `['email'=>'required']`，控制器 `$user->fill($req->validated())` —— `?email=victim@x.com` 即可满足；
  2. **绕过 required**：POST 缺 `name` 但 `?name=x` 存在时校验通过，随后控制器从 POST 取空值，产生不一致状态；
  3. **幂等性破坏**：同一 POST 配不同 query 得到不同 `validated()`。
- 触发场景: 实测复现：
```php
class FR extends \core\FormRequest { public function rules(): array { return ['email'=>'required|email']; } }
$_GET['email'] = 'attacker@evil.com'; $_POST = [];
(new FR())->validated();
// => {"email":"attacker@evil.com"}     ← 纯查询串即可通过并进入 validated()
```
- 建议修复:
```php
public function body(): array   // 新增：仅 POST + JSON
{
    return $this->json !== null ? array_merge($this->json, $this->post) : $this->post;
}
public function validate(): bool
{
    $validator = new Validate();
    $validator->rules($this->rules())->messages($this->messages());
    $ok = $validator->validate($this->body());   // ← 不再混入 $_GET
    ...
}
```

### [高] CORE-13 `app/route/*.php` 双份路由定义 + glob 未排序 → 6 条路由永久不可达
- 文件: app/core/Application.php  行号: 178-188；app/route/route.php 行号: 6-24；app/route/web.php  行号: 8-22
- 代码:
```php
$routeFiles = glob(APP_PATH . 'route/*.php');     // 未 sort()，顺序依赖文件系统
foreach ($routeFiles as $file) { $this->router->load($file); }
$this->router->scanControllerDirectory(APP_PATH . 'controller/', 'controller\\');
```
- 问题: `route.php` 与 `web.php` **各自 `new Router()` 并重复定义同一批路由**（`/` 与全部 5 条 `/api/*`）。`load()` 只做 `array_merge`（Router.php:369），没有去重/冲突检测，`dispatch()` 取第一条命中，因此 `web.php` 中对 `/` 与 `/api/*` 的定义**永远不会被执行**。`glob()` 未 `sort()`，在不同文件系统/平台上顺序可能不同，会导致"本地正常、线上相反"的路由错配。
- 触发场景: 实测复现：
```
total routes=16  duplicates=6
重复项：GET / 、GET /api/users 、GET /api/users/{id} 、POST /api/users 、
      PUT /api/users/{id} 、DELETE /api/users/{id}
```
- 建议修复: ① 删除 `app/route/route.php`（或 web.php，二选一）；② `sort($routeFiles)` 保证确定性；③ `Router::load()` 对 `method+uri` 相同的路由抛显式异常而非静默合并；④ 增加启动期断言：路由表不得有重复 `method+uri`。

---

# 三、中危（Medium）

### [中] CORE-14 `resolveMiddleware()` 无环检测 + 别名不递归解析
- 文件: app/core/Router.php  行号: 99-112
- 代码:
```php
private function resolveMiddleware(array $middlewares): array
{
    $resolved = [];
    foreach ($middlewares as $mw) {
        if (is_string($mw) && isset($this->middlewareAliases[$mw])) {
            $resolved[] = $this->middlewareAliases[$mw];              // ← 不再解析
        } elseif (is_string($mw) && isset($this->middlewareGroups[$mw])) {
            $resolved = array_merge($resolved, $this->resolveMiddleware($this->middlewareGroups[$mw]));
        } else { $resolved[] = $mw; }
    }
    return $resolved;
}
```
- 问题: 两个独立缺陷：(a) 递归进入组后**没有环检测**，自引用/间接引用的组会无限递归直到内存耗尽（实测 `Allowed memory size of 134217728 bytes exhausted`，PHP 进程崩溃 = 拒绝服务）；(b) 别名解析只做一层且**不检查别名指向的是组名**，常见写法 `aliasMiddleware('w','web')`（别名指向组）会退化为 "Invalid middleware: web" 运行时错误。
- 触发场景: 实测复现：
```php
$router->middlewareGroup('a', ['a', Cors::class]);   // 自引用
$router->middleware(['a']); $router->get('/z', fn() => ...);
// GET /z → Fatal error: Allowed memory size exhausted at Router.php:103

$router->middlewareGroup('web', [Cors::class]);
$router->aliasMiddleware('w', 'web');               // 别名 → 组
// → RuntimeException: Invalid middleware: web
```
- 建议修复: 引入 `$resolving` 路径追踪（Container 已有同款实现可参考），并让别名解析递归：
```php
private function resolveMiddleware(array $middlewares, array $resolving = []): array
{
    $resolved = [];
    foreach ($middlewares as $mw) {
        if (!is_string($mw)) { $resolved[] = $mw; continue; }
        $key = $mw;
        if (isset($resolving[$key])) {
            throw new \RuntimeException("Circular middleware reference: {$key}");
        }
        if (isset($this->middlewareAliases[$mw])) {
            $resolved = array_merge($resolved, $this->resolveMiddleware([$this->middlewareAliases[$mw]], $resolving + [$key => true]));
        } elseif (isset($this->middlewareGroups[$mw])) {
            $resolved = array_merge($resolved, $this->resolveMiddleware($this->middlewareGroups[$mw], $resolving + [$key => true]));
        } else { $resolved[] = $mw; }
    }
    return $resolved;
}
```
  同时对解析结果按类名 `array_unique` 去重，避免组 + 显式声明导致同一中间件执行两次。

### [中] CORE-15 重复命名捕获组导致路由静默失效
- 文件: app/core/Router.php  行号: 574-609 与 627-636
- 代码:
```php
if (strlen($customRegex) > 64) { throw new \InvalidArgumentException(...); }
$compiled .= "(?<{$paramName}>" . str_replace('~', '\~', $customRegex) . ")";
...
$probe = @preg_match($regex, '');      // 编译失败 → return false（仅 error_log）
if ($probe === false) { error_log(...); return false; }
```
- 问题: 两个占位符同名（如 `/x/{id}/{id}`）会让 PCRE 报 "two named subpatterns have the same name"，`matchRoute()` 直接返回 false，**路由永久匹配不到且仅写 error_log**，最终表现为莫名其妙的 404。此外 `$customRegex` 除长度外无任何约束，允许 `(?i)`、`(?=…)`、嵌套量词，既可能引入 ReDoS 又可能写出改变分组语义的模式。
- 触发场景: 实测复现：`$router->get('/x/{id}/{id}', ...)` → 请求 `/x/1/2` 返回 `404 Not Found`。
- 建议修复: 编译时维护已用参数名集合，重复即在注册/编译阶段抛显式异常；对自定义正则加白名单校验（禁止 `(?` 内联修饰与环视、禁止嵌套无界量词）。

### [中] CORE-16 全局下调 `pcre.backtrack_limit`，且 JIT 下该限制对回溯不生效
- 文件: app/core/Router.php  行号: 47-53
- 代码:
```php
public function __construct()
{
    $current = ini_get('pcre.backtrack_limit');
    if ($current === false || (int) $current > 100000) {
        ini_set('pcre.backtrack_limit', '100000');
    }
}
```
- 问题: (a) `Router` 是每次请求都实例化的全局对象，这里的 `ini_set` 是**进程级副作用**，会把全应用（含 ORM / Validate / 模板层）PCRE 回溯上限从默认 1000000 压到 100000，可能让其他模块的合法正则莫名失败；(b) PHP 7.3+ 默认 `pcre.jit=1`（本机实测 `ini_get('pcre.jit') === '1'`），**JIT 模式下 `backtrack_limit` 对回溯不生效**，该防护目的基本落空；(c) 无注释说明这是有意策略。
- 触发场景: 任何含自定义正则的路由在 JIT 开启时仍可被构造出灾难性回溯的 pattern；而其他模块的复杂正则被无故收紧。
- 建议修复: 移除全局 `ini_set`，改为在 `matchRoute()` 内检测 `preg_last_error() === PREG_JIT_STACKLIMIT_ERROR` 并对失败路由降级为"不匹配 + 告警"；对自定义正则补静态检查（拒绝嵌套量词与内联修饰，长度上限 64 保留）。

### [中] CORE-17 `loadCachedRoutes()` 无结构校验、无失效机制、无生成命令
- 文件: app/core/Router.php  行号: 825-839；app/core/Application.php  行号: 178-179
- 代码:
```php
$data = require $cacheFile;
if (is_array($data) && isset($data['routes'])) {
    $this->routes = $data['routes'];
    $this->namedRoutes = $data['namedRoutes'] ?? [];
    return true;
}
```
- 问题: (a) 只判断顶层 `isset($data['routes'])`，不校验每条路由是否有 `method/uri/handler` 键；残缺缓存会在 `dispatch()` 中触发 "Undefined array key" warning 甚至 TypeError；(b) 缓存一旦生成就**永久生效**，新增路由文件/控制器全部不生效，且全仓没有任何 `route:cache` / `route:clear` 命令——`cacheRoutes()` 在生产链路上是死代码；(c) `require` 可执行 PHP 且来源不可信（`storage/` 若被 web 暴露或存在任意文件写即可 RCE）；(d) 写入后未 `opcache_invalidate()`，长驻进程下旧缓存不刷新。
- 触发场景: 手工执行 `$router->cacheRoutes(STORAGE_PATH.'cache/route_cache.php')` 后再修改路由文件 → 新路由 404；删除被缓存引用的控制器 → `Handler not callable`。
- 建议修复: 增加 `bin/console route:cache` / `route:clear`；缓存内写入 `built_at` + 应用版本 + 路由文件 mtime 指纹，加载时不匹配则自动重建；加载时逐条校验结构，失败则 `error_log` + 回退解析路由文件；缓存文件加 `<?php /* die */ ?>` 前缀。

### [中] CORE-18 中间件链捕获 `$request` 快照，后续层拿不到替换后的 Request
- 文件: app/core/Router.php  行号: 651-688（对比 app/core/Pipeline.php 98-107）
- 代码:
```php
$next = function () use ($middleware, $next, $request) {      // ← $request 被按值捕获
    if (is_string($middleware) && class_exists($middleware)) {
        $instance = $this->container ? $this->container->get($middleware) : new $middleware();
        if (method_exists($instance, 'handle')) { return $instance->handle($request, $next); }
```
- 问题: `$request` 是 `dispatch()` 那一刻的实例，`use` 捕获后所有层级共享同一变量快照。中间件即使调用 `$next($newRequest)`，内层闭包**完全忽略参数**，仍使用旧 `$request`；且 `executeHandler()` 注入给控制器的也是 `dispatch()` 的 `$request`。这使"认证中间件替换/增强请求"这一常见模式彻底失效，也与 `Pipeline::then()` 的 `$passable` 洋葱传值语义不一致（两套实现长期分叉）。
- 触发场景: 中间件 `return $next($request->withUser($user))` 之后，内层控制器 `Request $request` 类型注入拿到的仍是旧实例。
- 建议修复: 统一走 `Pipeline`：`dispatch()` 改为 `(new Pipeline())->send($request)->through($allMiddleware)->viaContainer($this->container)->then($handlerClosure)`；或让 `$next` 接收并透传请求（注意递归闭包需 `function ($req = null) use (&$next, ...)`）。

### [中] CORE-19 `handleNotFound()` require 视图时作用域污染 + 输出缓冲泄漏
- 文件: app/core/Router.php  行号: 789-807
- 代码:
```php
$obLevel = 0;   // 实际未记录基准层
ob_start();
require $viewPath;                       // ← $config/$errorView/$router 全部进入视图作用域
return Response::make(ob_get_clean() ?: '', 404);
```
- 问题: (a) `require` 在方法作用域执行，视图模板可直接访问 `$config`（含数据库凭据、APP_KEY）、`$errorView`、`$this`（即 Router 实例），模板被污染即造成配置泄露；(b) 若视图内部抛异常，`ob_start()` 开启的缓冲**不会关闭**，后续输出被吞进这个孤儿缓冲；(c) `ob_get_clean() ?: ''` 使空视图等价空串；(d) 视图路径由配置拼装 `VIEW_PATH . ltrim($errorView,'/') . '.php'`，若配置项被污染即可 `require` 任意 `.php`。
- 触发场景: `config('app.error_views.404') = '../../../../tmp/evil'`，或自定义 404 模板中直接 `<?= $config['database']['password'] ?>` 即可打印数据库密码。
- 建议修复: 隔离作用域 + 校验路径 + `finally` 关闭缓冲：
```php
$viewPath = realpath(VIEW_PATH . ltrim($errorView, '/') . '.php');
if ($viewPath !== false && str_starts_with($viewPath, realpath(VIEW_PATH))) {
    $level = ob_get_level();
    try {
        ob_start();
        (static function (string $__file) { extract(['__file' => $__file], EXTR_SKIP); require $__file; })($viewPath);
        $html = ob_get_clean();
    } catch (\Throwable $ex) {
        while (ob_get_level() > $level) { ob_end_clean(); }
        throw $ex;
    }
    return Response::make($html ?? '', 404);
}
```

### [中] CORE-20 `Request::host()`/`url()` 直接信任 Host 头 → Host Header Injection
- 文件: app/core/Request.php  行号: 475-506
- 代码:
```php
public function host(): string
{
    return $this->server['HTTP_HOST'] ?? 'localhost';   // ← 完全未校验
}
public function url(): string
{
    $host = $this->host();
    $url = $this->scheme() . '://' . $host;
    ...
    return $url . $this->uri();
}
```
- 问题: `Host` 头完全由客户端控制。`url()` 产出的绝对 URL 会出现在密码重置邮件、API 文档示例、分页链接中，构成经典的 Host Header Injection（密码重置投毒）。同时 `url()` 拼的是**原始** `uri()`（含 query string），当 `REQUEST_URI` 为 absolute-form（`GET http://evil.com/x HTTP/1.1`）时会输出 `http://victim.com/http://evil.com/x`。框架已有 `config('app.url')` 却从未在 `Request::url()` 中作为可信基准。
- 触发场景: `curl -H 'Host: evil.com' https://victim.com/reset` → 邮件中的重置链接指向 `https://evil.com/reset?token=...`。
- 建议修复: 增加 `app.trusted_hosts` 白名单，`host()` 校验通过才返回，否则回退到 `config('app.url')` 解析出的 host；`url()` 对 `uri()` 先 `parse_url` 取 path+query，并拒绝以 scheme 开头或含 `://` 的 `REQUEST_URI`。

### [中] CORE-21 `setTrustedProxies()` 从未由配置接线；调试页可信 IP 判定与之脱节
- 文件: app/core/Request.php  行号: 346-383、454-468；app/core/Application.php  行号: 104-133、346-360
- 代码:
```php
private static array $trustedProxies = [];      // 永远是空：Application 从不调用 setter
public static function setTrustedProxies(array $proxies): void { self::$trustedProxies = $proxies; }
```
```php
// Application::handleException
$clientIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
try {
    if ($this->container->has('request')) {      // ← 容器从未绑定 'request'，恒为 false
        $req = $this->container->get('request');
        if ($req instanceof Request) { $clientIp = $req->ip(); }
    }
} catch (\Throwable $ipException) {}
$showTrace = $isDebug && in_array($clientIp, $allowedIps, true);
```
- 问题: 三个缺陷叠加：
  1. `Application` 从不调用 `setTrustedProxies()`，配置中也无该项 → 反向代理部署时 `scheme()` 恒为 `http`，`Session::start()` 的 `secure` cookie 恒 false（明文会话 cookie）、`url()` 生成 http 链接；
  2. 容器中**从未注册 `request`**（`registerServices()` 只注册 config/router/events/db/cache/log），`has('request')` 恒为 false，异常处理里那段 IP 获取是死代码；
  3. 反过来若开发者自行 `setTrustedProxies([...])`，则 `$req->ip()` 返回 X-Forwarded-For 的值，攻击者只要在 XFF 写入 `127.0.0.1`，就能在 `debug=true` 时**拿到完整堆栈**，第 360 行的 IP 白名单随之失效。`ExceptionHandler::isTrustedIp()`（ExceptionHandler.php:209-216）有同样问题。
- 触发场景: 反代后 `X-Forwarded-For: 127.0.0.1` + `APP_DEBUG=true` → 500 页面输出 `getTraceAsString()`，泄露绝对路径、SQL、密钥上下文。
- 建议修复: `registerServices()` 中 `$this->container->instance('request', new Request())`；新增 `config('app.trusted_proxies', [])` 并在 boot 阶段调用 `setTrustedProxies()`；调试页可信判定**只**用 `$_SERVER['REMOTE_ADDR']`，或基于可信代理链逐跳剥离后再比对。

### [中] CORE-22 `Request` 类型访问器对数组输入静默降级
- 文件: app/core/Request.php  行号: 515-558
- 代码:
```php
public function string(string $key, string $default = ''): string
{
    return (string) $this->input($key, $default);
}
public function boolean(string $key, bool $default = false): bool
{
    $value = $this->input($key, $default);
    if (is_bool($value)) { return $value; }
    return filter_var($value, FILTER_VALIDATE_BOOLEAN);   // 返回 ?bool
}
```
- 问题: (a) `string()` 对数组输入触发 `Warning: Array to string conversion` 并返回 `"Array"`，错误被掩盖；(b) `boolean()` 的 `filter_var` 返回 `?bool`，`null` 在 `strict_types=1` 下触发 `TypeError`；(c) 这些方法常被当作"清洗函数"使用，实际未做任何校验。
- 触发场景: 实测复现：
```
POST /x  tags[]=a&tags[]=b ; $request->string('tags')
Warning: Array to string conversion at Request.php:517
string(5) "Array"
```
- 建议修复: 统一走标量归一：
```php
public function string(string $key, string $default = ''): string
{
    $v = $this->input($key, $default);
    if (is_array($v) || is_object($v)) { throw new \InvalidArgumentException("[{$key}] must be scalar"); }
    return (string) $v;
}
public function boolean(string $key, bool $default = false): bool
{
    $v = $this->input($key, $default);
    return is_scalar($v) ? ((bool) filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)) : $default;
}
```

### [中] CORE-23 `Router::route()` 语义不完整（丢参数、无法生成绝对 URL、数组入参 TypeError）
- 文件: app/core/Router.php  行号: 292-314
- 代码:
```php
return preg_replace_callback(
    '/\{([a-zA-Z_][a-zA-Z0-9_]*)(?::((?:[^{}]|\{[^{}]*\})*))?\}/',
    function ($m) use ($parameters) {
        $key = $m[1];
        if (!array_key_exists($key, $parameters)) {
            throw new \RuntimeException("Missing required parameter [{$key}] for route [{$m[0]}].");
        }
        return \urlencode((string) $parameters[$key]);
    },
    $uri
);
```
- 问题: (a) **多余参数被静默丢弃**——`route('users.index', ['page'=>2])` 若路由无 `page` 占位符，`page` 直接消失，无法生成 `?page=2`，而这是最常见用法；(b) 只返回路径，不能生成绝对 URL（叠加 CORE-20 后更危险）；(c) 无 query 字符串/片段处理；(d) `$parameters[$key]` 为数组时 `(string)` 触发 `Array to string conversion`；(e) 替换后若正则未匹配，占位符 `{id}` 会残留在结果中。
- 触发场景: 实测复现：`$router->get('/u/{id}',...)->name('u.show'); $router->route('u.show', ['id'=>7,'page'=>2])` → `/u/7`（`page=2` 丢失）。
- 建议修复: 收集未消费的参数作为 query string，并新增 `url()`：
```php
$used = [];
$uri = preg_replace_callback($pattern, function ($m) use ($parameters, &$used) {
    $used[$m[1]] = true;
    if (!array_key_exists($m[1], $parameters)) { throw new \RuntimeException(...); }
    if (is_array($parameters[$m[1]])) { throw new \InvalidArgumentException('array param'); }
    return rawurlencode((string) $parameters[$m[1]]);
}, $uri);
$extra = array_diff_key($parameters, $used);
if ($extra) { $uri .= (str_contains($uri,'?') ? '&' : '?') . http_build_query($extra); }
// 新增：public function url(string $name, array $p = []): string { return (new \core\Request())->url() . $this->route($name,$p); }
```

### [中] CORE-24 `Response::redirect()` 允许任意状态码，且未显式拒绝 CR/LF
- 文件: app/core/Response.php  行号: 87-97
- 代码:
```php
public static function redirect(string $url, int $statusCode = 302): self
{
    // 防止开放重定向：只允许相对路径（排除 //evil.com 协议相对URL）
    if (!str_starts_with($url, '/') || str_starts_with($url, '//') || str_contains($url, '\\')) {
        throw new \InvalidArgumentException('Redirect URL must be a relative path');
    }
    $response = new self('', $statusCode);
    $response->header('Location', $url);
    return $response;
}
```
- 问题: (a) 状态码无白名单——`redirect('/x', 200)` 产生"带 Location 的 200"，客户端不会跳转、缓存与监控语义全乱，`redirect('/x', 500)` 同样荒谬；(b) `\r\n` 未在此处过滤（依赖 `header()` 内部剥离 + PHP 8 拒绝多行头），与 `header()` 方法（第 146-147 行）不一致，属纵深防御缺口；(c) 允许 `/%2f%2fevil.com`，部分客户端解码后等价协议相对 URL；(d) 拒绝全部绝对 URL 导致无法跳转到同域绝对地址，属可用性缺陷。
- 触发场景: 实测复现：`Response::redirect('/ok', 200)` → `status=200, Location: /ok`。
- 建议修复:
```php
if (!in_array($statusCode, [301,302,303,307,308], true)) { throw new \InvalidArgumentException('Invalid redirect status'); }
$url = str_replace(["\r","\n","\0"], '', $url);
$decoded = rawurldecode($url);
if (!str_starts_with($url,'/') || str_starts_with($decoded,'//') || str_contains($url,'\\')) { throw ... }
```

### [中] CORE-25 路由缓存模式下全局中间件/别名/组不被持久化
- 文件: app/core/Router.php  行号: 507-508、825-839
- 代码:
```php
$routeMiddleware = $this->resolveMiddleware($route['middleware'] ?? []);
$allMiddleware = array_merge($this->resolveMiddleware($this->globalMiddleware), $routeMiddleware);
```
```php
$data = require $cacheFile;
$this->routes = $data['routes'];            // 只还原 routes / namedRoutes
```
- 问题: `cacheRoutes()` 只持久化 `routes` 与 `namedRoutes`。这些路由的 `middleware` 字段里存的仍是**未解析的别名/组名**（如 `['cors']`、`['web']`）。加载缓存时若别名注册在路由文件中（会被 `load()` 丢弃，见 CORE-05）或本次未执行的提供者中，`resolveMiddleware()` 无法还原 → "Invalid middleware: cors"（与 CORE-05 同款 500），但此时**看起来像缓存坏了**，排查方向被完全误导。
- 触发场景: 路由缓存生成后 `GET /api/users` 返回 500 "Invalid middleware: cors"，与 CORE-05 现象相同但根因不同，混淆排查。
- 建议修复: 缓存时把 `middlewareAliases`/`middlewareGroups`/`globalMiddleware` 一并序列化（仅限字符串/类名，闭包与对象拒绝缓存），`loadCachedRoutes()` 还原；或缓存中直接存**已解析**的中间件标识列表。

### [中] CORE-26 `config('app.providers')` 从未被读取，服务提供者静默失效
- 文件: app/core/Application.php  行号: 104-166；app/config/app.php  行号: 102-104
- 代码:
```php
// app/config/app.php
'providers' => [ /* \app\providers\AppServiceProvider::class, */ ],
```
```php
// Application::registerServices()
$this->container->singleton('log', ...);
$this->router->setContainer($this->container);
\core\Hash::setApplicationKey($this->getConfig('app.key', ''));
// —— 没有任何一行读取 config('app.providers')
$this->bootProviders();   // $this->providers 恒为空数组
```
- 问题: 配置文件提供了标准入口，框架却不消费。开发者按文档把提供者写进 `app.php` 后，`register()`/`boot()` **永不执行**，中间件别名、全局中间件、事件监听全部静默失效且无任何告警。这正是 CORE-05 能够长期潜伏的直接原因。
- 触发场景: 取消注释 `\app\providers\AppServiceProvider::class` 后访问 `/api/users` → 依然 `Invalid middleware: cors`，且无任何提示说明提供者未被加载。
- 建议修复: 在 `registerServices()` 末尾实例化并注册：
```php
foreach ((array) $this->getConfig('app.providers', []) as $providerClass) {
    if (is_string($providerClass) && class_exists($providerClass)) {
        $this->registerProvider(new $providerClass($this->container));
    } else {
        error_log('LightPHP: provider not found: ' . (is_string($providerClass) ? $providerClass : gettype($providerClass)));
    }
}
```

### [中] CORE-27 500 调试页与自定义错误视图的 ob 层处理不一致、异常对象直接注入视图作用域
- 文件: app/core/Application.php  行号: 339-400
- 代码:
```php
if (!headers_sent()) { http_response_code(500); header('Content-Type: text/html; charset=utf-8'); ... }
...
if ($errorView !== null && defined('VIEW_PATH')) {
    $viewPath = VIEW_PATH . ltrim($errorView, '/') . '.php';   // ← 未 realpath 校验
    if (file_exists($viewPath)) {
        try { $obLevel = ob_get_level(); ob_start(); $exception = $e; $debug = $showTrace; require $viewPath; echo ob_get_clean(); return; }
        catch (\Throwable $viewException) { while (ob_get_level() > $obLevel) { ob_end_clean(); } }
    }
}
```
- 问题: (a) 视图路径**未做 realpath 目录校验**（与 CORE-19 同源），配置被污染即可 `require` 任意 `.php`；(b) `require` 把局部变量 `$exception`、`$debug` 注入视图作用域，视图可无视 `$debug` 直接输出 `$exception->getTraceAsString()`，把 CORE-11 的泄露通道重新打开，且**没有可信 IP 判定**兜底；(c) 仅 500 分支做了 ob 回滚，HttpException(307-321) 与 ValidationException(323-337) 分支没有；(d) `echo ob_get_clean()` 是"再包一层缓冲再输出"，外层已有缓冲时内容嵌套。
- 触发场景: `config('app.error_views.500') = '../../storage/log/app.log.php'`；或自定义 500 模板中 `<?= $exception->getTraceAsString() ?>` → 生产环境任意异常即泄露堆栈。
- 建议修复: `realpath()` + 前缀校验；用静态闭包隔离作用域且只传入 `$safeMessage`（不含 trace）而非整个异常对象；把三个异常分支的 ob 清理提取为统一的私有方法。

---

# 四、低危（Low）

### [低] CORE-28 `Request` 未注册为容器单例 → 请求体被重复读入内存
- 文件: app/core/Request.php  行号: 42-53；app/core/Application.php  行号: 104-133
- 代码:
```php
public function __construct()
{
    $this->get = $_GET; $this->post = $_POST; $this->server = $_SERVER;
    $raw = file_get_contents('php://input');
    $this->rawContent = $raw === false ? '' : $raw;
    ...
}
```
- 问题: 容器未绑定 `request`，`Router::dispatch()` 在无参时 `new Request()`，每个 `FormRequest` 子类在注入时又 `new $typeName()`（Router.php:747），于是同一请求的 `php://input` 被多次全量读入并保留（`rawContent` + 解析后的 `json` 数组同时驻留）。大 JSON body（上传/批量接口）下内存占用成倍增长。此外 `new Request()` 读的是**全局** `$_GET/$_POST`，中间件对请求对象的修改（`merge()` 除外）在 FormRequest 中不可见。
- 触发场景: 20MB JSON body + 3 个 FormRequest 参数 → 至少 3 次全量读入 + 3 份解析结果。
- 建议修复: `registerServices()` 中 `$this->container->instance('request', new Request())`；`Router::dispatch()` 优先取容器实例；`FormRequest` 改为注入 Request 并共享其数据，避免重复读流。

### [低] CORE-29 `scanControllerDirectory()` 扫描能力弱且每请求触发全量自动加载
- 文件: app/core/Router.php  行号: 457-474；app/core/Application.php  行号: 187
- 代码:
```php
$files = glob($directory . '*.php');                     // 仅顶层，不递归
foreach ($files as $file) {
    $class = $namespace . basename($file, '.php');       // 以文件名推导类名
    if (class_exists($class)) { $count += $this->registerController($class); }
}
```
- 问题: (a) 不递归子目录，分层控制器（`app/controller/Admin/...`）的 Attribute 路由全部不生效；(b) 类名与文件名不一致时 `class_exists()` 失败，**静默跳过**无任何日志；(c) `class_exists()` 触发 `Loader::autoload()` 逐个 require 全部控制器（可能触发静态初始化副作用），在无路由缓存的生产环境每请求都付此成本；(d) 顺序依赖 `glob()`，未 `sort()`；(e) `namespace` 默认写死 `'controller\\'`。
- 触发场景: 控制器移到 `app/controller/Admin/UserController.php`（类名 `controller\Admin\UserController`）→ 其 Attribute 路由全部消失，404。
- 建议修复: 用 `RecursiveDirectoryIterator` 递归；类名不匹配时 `error_log`；把扫描结果纳入路由缓存（CORE-17）；`sort($files)`。

### [低] CORE-30 `Route` Attribute 类级/方法级语义歧义与误用空间
- 文件: app/core/attributes/Route.php  行号: 18-89；app/core/Router.php  行号: 402-436
- 代码:
```php
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
class Route
{
    public function __construct(
        public string $path = '', public string $method = 'GET',
        public ?string $name = null, public ?string $prefix = null,
        public array $middleware = [],
    ) {}
}
```
- 问题: (a) 同一属性类表达"方法级路由"与"类级分组"两种语义，字段互斥性无约束——`#[Route(prefix:'/api', path:'/x', method:'POST')]` 可通过构造，行为完全取决于挂载位置；(b) `prefix`/`middleware` 在方法级被**静默忽略**（见 CORE-02）；(c) `Get/Post/...` 均为 `TARGET_METHOD`，误挂类上会在 `attr->newInstance()` 处抛 `Error`，信息晦涩；(d) 无方法级附加中间件的属性；(e) 无空 `path` 校验。
- 触发场景: `#[Get]`（漏写 path）→ 注册出 `GET /` 悄悄覆盖首页；`#[Route(prefix:'/api')]` 挂方法上 → prefix 被忽略，路由落到 `/path` 而非 `/api/path`。
- 建议修复: 拆分为 `#[Route(path, method, name)]`（方法级）、`#[RouteGroup(prefix, middleware)]`（类级）、`#[Middleware([...])]`（方法级）；对 `path === ''` 抛 `InvalidArgumentException`。

### [低] CORE-31 `public/index.php` 的 `APP_DEBUG` 与配置缓存不同源，且缺少生产加固
- 文件: public/index.php  行号: 18-34
- 代码:
```php
$appConfig = file_exists(APP_PATH.'config/app.php') ? require APP_PATH.'config/app.php' : [];
define('APP_DEBUG', $appConfig['debug'] ?? false);   // 与 config_cache.php 不同源

try { $app = new \core\Application(); $app->run(); }
catch (\Throwable $e) {
    if (defined('APP_DEBUG') && APP_DEBUG) { throw $e; }   // ← 向所有客户端暴露完整堆栈
    http_response_code(500); echo 'Internal Server Error';
}
```
- 问题: (a) `APP_DEBUG` 直读 `config/app.php`，而 `Application::loadConfig()` 优先读 `storage/cache/config_cache.php`；两者可不一致，若 `.env`/环境变量使 `app.php` 的 debug 为 true 而缓存为 false，第 29 行 `throw $e` 会在**非调试模式**下把含绝对路径的完整堆栈吐给公网；(b) `throw $e` 没有可信 IP 限制，与 `Application::handleException()` 精心实现的 `127.0.0.1/::1` 白名单自相矛盾；(c) 未设置 `error_reporting`/`display_errors`/`expose_php`/`date.timezone`，生产 php.ini 未收紧时 warning 会泄露路径与 SQL 片段；(d) 未校验 PHP 版本（项目要求 8.0+）与关键扩展。
- 触发场景: 生产 `app.php` 中 `'debug' => env('APP_DEBUG', false)`，vhost 设 `APP_DEBUG=1` 但配置缓存为 false → 启动期异常向公网返回含绝对路径的 PHP 致命错误页。
- 建议修复: 统一由 `Application` 单一来源提供 debug 状态；删除入口的 `throw $e` 交给 `handleException`；入口补：
```php
if (PHP_SAPI !== 'cli') { ini_set('log_errors','1'); ini_set('expose_php','0'); }
if (PHP_SAPI !== 'cli' && ($appConfig['env'] ?? '') === 'production') { ini_set('display_errors','0'); }
date_default_timezone_set($appConfig['timezone'] ?? 'UTC');
```

### [低] CORE-32 `.htaccess` 保护范围不足且 `DirectoryMatch` 被错误包裹
- 文件: public/.htaccess  行号: 1-17
- 代码:
```apache
Options -Indexes
<IfModule mod_rewrite.c>
    <DirectoryMatch "^.*/(uploads|upload)/.*">
        <FilesMatch "\.ph(p[2-7]?|t|tml)$">Require all denied</FilesMatch>
    </DirectoryMatch>
</IfModule>
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ index.php [QSA,L]
```
- 问题: (a) `DirectoryMatch` 由 `mod_authz_core` 提供，被错误包在 `<IfModule mod_rewrite.c>` 中——**未启用 mod_rewrite 的服务器上整段上传目录保护失效**（恰是最需要它的场景）；(b) `.htaccess` 内 `DirectoryMatch` 的作用域/继承语义在不同 Apache 版本上不一致，可靠做法是在上传目录放独立 `.htaccess`；(c) 扩展名黑名单缺 `.phar`、`.pht`、`.inc`，上传的 `.htaccess`/`.user.ini` 也可能生效；(d) 未禁止访问点文件（`.env`/`.git`/`.sql`/`.log`），若 `storage/` 被误配到 `public/` 下则日志与缓存可直接下载；(e) `Options -Indexes` 需 `AllowOverride Options`，不匹配时直接 500；(f) 缺 `DirectoryIndex index.php` 与 `FallbackResource`。
- 触发场景: 未启用 mod_rewrite 的服务器上 `/uploads/shell.phtml` 可执行。
- 建议修复: 上传目录单独放 `.htaccess`（`php_flag engine off` 或 `Require all denied` + `RemoveHandler .php`）；顶层加 `<FilesMatch "^\.(env|git|sql|ini|log)$">Require all denied</FilesMatch>`；把 `DirectoryMatch` 移出 `IfModule`；补 `DirectoryIndex index.php`。

### [低] CORE-33 内置开发服务器无路由脚本 → 与 Apache 行为不一致
- 文件: bin/console  行号: 52-55
- 代码:
```php
$cmd = sprintf('php -S %s:%d -t %s', escapeshellarg($host), $port, $publicPath);
passthru($cmd);
```
- 问题: `php -S` 未指定 router script，`public/.htaccess` 被完全忽略（内置服务器不解析 .htaccess）。内置服务器只把**已存在的文件**直接返回，其余路径 404，**永远不会进入 `index.php`**。于是 `php bin/console serve` 下所有前端控制器路由（含 `/`、`/about`）全部 404，而 `AGENTS.md` 将 serve 作为主要开发命令，文档与实现严重不符。另未指定 `PHP_CLI_SERVER_WORKERS`（单进程并发）。
- 触发场景: `php bin/console serve` 后访问 `http://localhost:8080/` → `404 File not found`。
- 建议修复: 增加 `server.php` 并传入：
```php
$cmd = sprintf('php -S %s:%d -t %s %s', escapeshellarg($host), $port, $publicPath, escapeshellarg(ROOT_PATH.'server.php'));
// server.php: if (is_file(__DIR__.'/public'.$uri)) return false; require __DIR__.'/public/index.php';
```

### [低] CORE-34 404 响应缺少 `Cache-Control: no-store`，错误响应工厂不统一
- 文件: app/core/Router.php  行号: 789-807；app/core/Application.php  行号: 192-207
- 代码:
```php
return Response::make('<h1>404 Not Found</h1>', 404);
```
- 问题: (a) 无 `Cache-Control: no-store`，中间代理/浏览器可能缓存并持续返回 404；(b) 404 存在两套实现（`Router::handleNotFound()` 与应用层显式 `Response::make(...,404)`），格式取决于调用方；(c) 不区分 AJAX/API 请求；(d) 无埋点，404 数量无法监控。
- 触发场景: CDN 缓存了某次 404，该路径上线后仍无法访问直至缓存过期。
- 建议修复: 新增统一入口 `Router::notFound(Request $request): Response`，按 `Accept` 返回 JSON/HTML 并附 `->header('Cache-Control','no-store')`；`handleNotFound()` 作为兼容包装。

### [低] CORE-35 `Controller` 响应对象复用与 `error()` 状态码恒 200
- 文件: app/core/Controller.php  行号: 19-36、99-106
- 代码:
```php
public function __construct() { $this->response = new Response(); }
protected function view(string $template, array $data = []): Response
{
    return $this->response->content($view->render($template, $data));   // ← 复用同一实例
}
protected function error(string $message = 'error', int $code = -1, array $data = []): Response
{
    return Response::json(['code'=>$code,'message'=>$message,'data'=>$data]);  // ← 恒 200
}
```
- 问题: (a) 每个控制器实例化时都预分配一个 `Response`，即使用不到；(b) `view()` 复用同一实例，控制器中调用两次 `view()` 会**互相覆盖**内容；若控制器将来改为容器单例还会跨请求残留；(c) `error()` 不透传 HTTP 状态码，业务错误与系统错误在 HTTP 层无法区分（监控、网关重试、告警全部失真）；`success()` 同样无状态码参数。
- 触发场景: `$this->error('库存不足')` 返回 HTTP 200，前端与 APM 都判定为成功。
- 建议修复: `view()` 改为 `Response::make($content, 200)` 新建实例（去掉构造函数预分配）；`error()`/`success()` 增加 `int $status = 200` 参数，`error()` 默认映射 4xx/5xx。

### [低] CORE-36 `Request::parseJson()` 的 Content-Type 识别过窄，标量根 JSON 被丢弃
- 文件: app/core/Request.php  行号: 102-111、284-287
- 代码:
```php
if (stripos($contentType, 'application/json') !== false && !empty($this->rawContent)) {
    $decoded = json_decode($this->rawContent, true);
    if (is_array($decoded)) { $this->json = $decoded; }
}
```
- 问题: (a) 不识别 `application/vnd.api+json`、`application/ld+json`、`text/json`（OData/JSON:API 生态标准）；(b) JSON 标量根（`"abc"`、`42`、`true`）因 `is_array()` 被丢弃，`isJson()` 随之 false，而 `raw()` 仍能取到内容——语义不一致；(c) 无 `JSON_THROW_ON_ERROR`，语法错误静默表现为"空请求"，难以排查；(d) 无 body 大小上限校验；`post_max_size` 被绕过时（chunked）可打满内存；(e) `all()` 的合并顺序 `get, json, post` 意味着 POST 覆盖 JSON，两套 body 表示法混用时语义隐晦。
- 触发场景: `Content-Type: application/vnd.api+json` + `{"a":1}` → `$request->all()` 为空数组，控制器误判为"未提交数据"。
- 建议修复:
```php
$isJson = (bool) preg_match('#^(application/(json|[\w.+-]+\+json)|text/json)\b#i', trim(explode(';', $contentType)[0]));
if ($isJson && $this->rawContent !== '') {
    try { $this->json = json_decode($this->rawContent, true, 512, JSON_THROW_ON_ERROR); }
    catch (\JsonException $e) { $this->jsonError = $e->getMessage(); }
}
```

### [低] CORE-37 `hasFile()`/`file()` 假设单文件结构，多文件字段判断错误
- 文件: app/core/Request.php  行号: 401-425
- 代码:
```php
public function hasFile(string $key): bool
{
    return isset($this->files[$key]) && $this->files[$key]['error'] !== UPLOAD_ERR_NO_FILE;
}
```
- 问题: (a) 多文件字段 `docs[]` 时 `$_FILES['docs']['error']` 是**数组**，与常量比较恒 false → `hasFile('docs')` 永远返回 false；(b) 未区分 `UPLOAD_ERR_INI_SIZE`/`UPLOAD_ERR_FORM_SIZE`，超限文件被当正常文件交给 `Upload`，错误延后到 `move_uploaded_file` 才暴露；(c) `file(null)` 返回"第一个字段"，字段顺序依赖 PHP 内部数组序，语义不确定；(d) 未做 `is_uploaded_file()` 复核（`Upload` 内部有做，但 `Request` 层无防线）。
- 触发场景: `<input type="file" name="docs[]" multiple>` → `hasFile('docs')` 返回 false，上传逻辑被跳过。
- 建议修复: 兼容数组结构并区分错误码：
```php
public function hasFile(string $key): bool
{
    if (!isset($this->files[$key]['error'])) { return false; }
    foreach ((array) $this->files[$key]['error'] as $err) {
        if ($err !== UPLOAD_ERR_OK && $err !== UPLOAD_ERR_NO_FILE) { throw new UploadException($key, (int) $err); }
    }
    return (array) $this->files[$key]['error'] !== [];
}
```

### [低] CORE-38 `Middleware::shouldSkip()` 绕开 Request 对象直读 `$_SERVER`
- 文件: app/middleware/Middleware.php  行号: 12-34
- 代码:
```php
protected function shouldSkip(): bool
{
    $uri = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $uri = rtrim($uri, '/');
    $uri = $uri !== '' ? $uri : '/';
```
- 问题: (a) 直读 `$_SERVER` 而非 `handle($request, $next)` 传入的 Request，与 Router 走的是**另一条解析路径**，两处归一化差异可被利用来跳过 CSRF/限流等"跳过型"逻辑；(b) `parse_url` 的 authority-form 问题（CORE-07）在此同样存在，`//x/api/pay` 解析结果与实际分发路由可能不一致；(c) 每次调用重复 `parse_url`，未折叠重复斜杠（`/a//b` 与 `/a/b` 视为不同）；(d) `*` 编译为 `.*` 无长度上限，配合超长 URI 有轻微 ReDoS 风险；(e) CLI/测试环境无 `$_SERVER['REQUEST_URI']`。
- 触发场景: `except = ['/api/*']`，请求 `//evil.com/api/pay` → 实际分发到 `/api/pay`，但 `shouldSkip()` 解析出的路径与预期不符，边界组合下可绕过 `except` 判断。
- 建议修复: 改为 `shouldSkip(\core\Request $request)`，并抽取共享的路径归一化函数（`Request::decodedPath()`）供 Router 与 Middleware 共用（CORE-07），拼接正则后加长度上限。

### [低] CORE-39 `CsrfMiddleware` token 不轮换（可重放），返回非标准 419
- 文件: app/middleware/CsrfMiddleware.php  行号: 13-34
- 代码:
```php
if ($token === null || $sessionToken === null || $sessionToken === '' || $token === '' || !hash_equals((string)$sessionToken, (string)$token)) {
    return Response::json(['code' => 419, 'message' => 'CSRF token mismatch'], 419);
}
// 验证通过，保持当前会话 token 不变，避免多标签页或连续 AJAX 请求失效
return $next($request);
```
- 问题: (a) token 永不轮换，同一 token 在会话有效期内可**无限重放**（一旦经 Referer/日志/XSS 泄露即可长期利用）；(b) `419` 非 IANA 注册状态码，部分网关/客户端不识别，应为 403（或在文档中明确约定）；(c) 只校验 token，不校验 `Origin`/`Referer` 白名单，缺纵深防御；(d) `Session` 未提供 `regenerateToken()`，即使想轮换也无 API；(e) 默认**没有任何路由启用该中间件**（见 CORE-05/CORE-26），骨架项目实际无 CSRF 防护。
- 触发场景: token 经 Referer 泄露后，可在会话有效期内反复发起 CSRF 请求。
- 建议修复: 校验通过后轮换（提供"每 N 次轮换"以兼容多标签页）；增加 `Origin` 白名单校验；状态码改 403 或文档化 419；默认在 `web` 分组启用。

### [低] CORE-40 `Response::download()` 对目录/特殊文件缺少校验
- 文件: app/core/Response.php  行号: 107-135、213-218
- 代码:
```php
if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.\-]*://#', $filePath)) { throw new \InvalidArgumentException(...); }
if (!file_exists($filePath)) { throw new \InvalidArgumentException(...); }
$fileSize = filesize($filePath);
```
- 问题: (a) `file_exists()` 对目录返回 true，`filesize()` 对目录返回 4096 而不报错，`readfile()` 读目录产生 warning 并输出空内容——应显式 `is_file()`；(b) 无 `realpath()` 前缀校验，路径穿越（`../../etc/passwd`）完全依赖调用方自律；(c) 未校验 `is_readable` 与符号链接目标；(d) `Cache-Control: no-cache` 缺 `Pragma: no-cache`；(e) 无 `Accept-Ranges`/断点续传；(f) `$name` 只过滤 `\r\n\0"\\`，中文文件名未做 RFC 5987 `filename*=UTF-8''` 编码，下载会乱码。
- 触发场景: `Response::download('/some/dir')` → warning + 空响应；`Response::download('../../../../etc/passwd')` → 任意文件下载。
- 建议修复:
```php
$real = realpath($filePath);
if ($real === false || !is_file($real) || !is_readable($real)) { throw new \InvalidArgumentException(...); }
$name = preg_replace('/[\x00-\x1F"\\\\]/u', '', basename($name));
$response->header("Content-Disposition", "attachment; filename=\"{$ascii}\"; filename*=UTF-8''".rawurlencode($name));
$response->header('Pragma', 'no-cache');
```

### [低] CORE-41 `Router::load()` 对非 Router 返回值静默忽略
- 文件: app/core/Router.php  行号: 364-373
- 代码:
```php
public function load(string $file): void
{
    if (file_exists($file)) {
        $router = require $file;
        if ($router instanceof Router) {
            $this->routes = array_merge($this->routes, $router->getRoutes());
            $this->namedRoutes = array_merge($this->namedRoutes, $router->namedRoutes);
        }
        // ← 非 Router / 文件不存在：静默返回，无日志
    }
}
```
- 问题: 路由文件写错（忘记 `return $router;`、返回了数组、文件名拼错、被误放入 `app/route/` 的辅助文件）时**没有任何提示**，表现为"路由莫名其妙 404"。这是排查成本最高的一类静默失败；`glob()` 会把 `app/route/` 下的任何 `.php`（包括未来的 `helpers.php`、`README.php`）当作路由文件加载。
- 触发场景: 实测复现：`$router->load('/tmp/bad.php')`（内容 `return ['not a router'];`）→ `routes=0`，无任何输出。
- 建议修复:
```php
public function load(string $file): void
{
    if (!is_file($file)) { error_log("Router::load: not found {$file}"); return; }
    $router = require $file;
    if (!$router instanceof Router) {
        error_log("Router::load: {$file} must return a " . self::class . " instance");
        return;
    }
    ...
}
```

### [低] CORE-42 `Pipeline` 与 `Router::executeMiddleware` 两套中间件实现语义分叉
- 文件: app/core/Pipeline.php  行号: 98-155；app/core/Router.php  行号: 651-688
- 代码:
```php
// Pipeline::carry() —— 完整实现
if (is_string($pipe) && class_exists($pipe)) { $instance = $this->container ? $this->container->get($pipe) : new $pipe(); ... }
if (is_callable($pipe)) { return $pipe($passable, $stack); }
if (is_object($pipe))  { ... }
throw new \RuntimeException('Invalid pipe type: ' . gettype($pipe));
```
- 问题: 两处实现的能力不对等：Router 版本额外支持 `[$class, $method]` 数组形式，却**缺少** Pipeline 的 `is_object($pipe)` 分支与 `via()` 自定义方法名；同时 `Pipeline` 有 `thenReturn()`、`viaContainer()` 等 Router 完全未使用的 API。结果是：写成对象实例的中间件在 Pipeline 中可用、在 Router 的路由上却抛 `Invalid pipe type: object`（Router 版本会在 `is_callable` 失败后落到 `throw new RuntimeException("Invalid middleware: object")`）。两套实现还各自维护了一份 `$request` 传递约定（见 CORE-18），长期必然继续分叉。
- 触发场景: `$router->get('/x', ...)` 配 `middleware => [new \middleware\Throttle()]` → `RuntimeException: Invalid middleware: object`（实测同类逻辑在 Pipeline 下可正常工作）。
- 建议修复: 让 `Router::dispatch()` 直接复用 `Pipeline`，删除 `executeMiddleware()`；对象/数组/字符串三种形式的支持能力在 `Pipeline::carry()` 中统一补齐。

### [低] CORE-43 `Router::name()` 只作用于最后一条路由，`any()` + `name()` 语义错误
- 文件: app/core/Router.php  行号: 193-200、250-282
- 代码:
```php
public function any(string $uri, callable|array $handler): self
{
    $methods = ['GET','POST','PUT','DELETE','PATCH','OPTIONS'];
    foreach ($methods as $method) { $this->addRoute($method, $uri, $handler); }
    return $this;
}
public function name(string $name): self
{
    if (!empty($this->routes)) {
        $lastIndex = array_key_last($this->routes);
        $this->routes[$lastIndex]['name'] = $name;      // ← 只有最后一条被命名
        $this->namedRoutes[$name] = ['method' => $this->routes[$lastIndex]['method'], 'uri' => ...];
    } else { $this->pendingRouteName = $name; }
```
- 问题: (a) `any('/x', ...)->name('x')` 只有 **OPTIONS 那条**被命名，`namedRoutes['x']['method'] === 'OPTIONS'`，语义误导；(b) `$router->get('/a',...); $router->get('/b',...)->name('b');` 若中间某处调用过 `->middleware()`（不注册路由）不会错位，但一旦 `name()` 在**路由注册之前**被调用，`pendingRouteName` 会被**下一个**注册的路由消费，链式写法 `$router->name('x')->get('/a')` 与 `$router->get('/a')->name('x')` 混用时极易错位；(c) 无重复命名检查，两条同名路由后者覆盖 `namedRoutes` 但两条路由的 `name` 字段都保留，生成 URL 时指向哪条不确定；(d) `any()` 不支持排除某些方法。
- 触发场景: `$router->any('/webhook', $h)->name('hook')` → `route('hook')` 生成的元信息 method 为 OPTIONS。
- 建议修复: `any()` 返回后记录 `$lastAddedIndexRange`，`name()` 对该区间全部打名；`addRoute()` 中检测 `isset($this->namedRoutes[$name])` 并抛出重复命名异常。

### [低] CORE-44 `APP_KEY` 缺失时静默降级
- 文件: app/core/Application.php  行号: 131-132；app/config/app.php  行号: 62
- 代码:
```php
// 设置应用密钥用于加密
\core\Hash::setApplicationKey($this->getConfig('app.key', ''));
```
- 问题: `APP_KEY` 缺失时传入空字符串，无告警、无启动失败。随后 `Hash` 的加解密/派生行为取决于其内部实现（大概率对空 key 直接使用或抛运行时错误），导致"生产环境忘记配 key → 加密功能在运行时报错/或以可预测 key 加密"这类严重问题被推迟到线上首次调用时才暴露。`config/app.php` 的注释写明"生产环境必须设置"，但代码层面没有任何强制。
- 触发场景: 部署时漏配 `APP_KEY` → 首页正常，直到用户密码加密接口首次被调用才报错，且错误信息不指向根因。
- 建议修复:
```php
$key = (string) $this->getConfig('app.key', '');
if ($key === '' && !in_array($this->getConfig('app.env'), ['local','testing'], true)) {
    error_log('LightPHP: APP_KEY 未配置 — 加密/哈希功能将不可用或降级');
}
\core\Hash::setApplicationKey($key);
```

---

# 五、已验证正确清单

以下点经代码审查确认无误，无需修改：

**响应与安全头**
- `Response::header()`（Response.php:144-150）对头名与头值均剥离 `\r`/`\n` → **无 HTTP 响应头注入**
- `Response::redirect()`（Response.php:87-97）拒绝非 `/` 开头、协议相对 `//`、含反斜杠的 URL → **无开放重定向**
- `Response::download()`（Response.php:111-113）用 `^[a-zA-Z][a-zA-Z0-9+.\-]*://` 白名单式拒绝**所有**流包装器（`php://`、`phar://`、`file://`、`data://`）→ **无误用包装器导致 RCE**
- `Response::json()`（Response.php:69-78）启用 `JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP` 防 XSS，且编码失败有兜底
- `Response` 状态码范围校验 `100..599`（Response.php:43-45、185-187）
- `Response::addSecurityHeaders()` 输出 `nosniff`/`SAMEORIGIN`/`Referrer-Policy`，CSP 仅在 `text/html` 时下发（Response.php:226-245）
- `Response::send()` 在 `headers_sent()` 时不重复发头（Response.php:199）
- `Controller::notFound()`（Controller.php:114-117）对消息做 `htmlspecialchars`

**请求层**
- `Request::parseHeaders()` + `normalizeHeaderKey()`（Request.php:62-95）统一归一化为 Title-Case，`header('content-type')`/`X-Requested-With`/`X-CSRF-TOKEN` 均可大小写无关命中；`Content-Type`/`Content-Length` 从 `CONTENT_TYPE`/`CONTENT_LENGTH` 正确补齐
- `Request::ip()`（Request.php:362-383）**仅当 `REMOTE_ADDR` 命中可信代理列表时**才读 `X-Forwarded-For`/`X-Real-IP`，且用 `filter_var(FILTER_VALIDATE_IP)` 校验；默认不信任任何代理头 → **无伪造 IP 绕过限流**
- `Request::isSecureFromServer()`（Request.php:454-468）`X-Forwarded-Proto` 逗号列表取最左侧，且同样要求可信代理
- `Request` 构造函数（Request.php:49-50）对 `file_get_contents('php://input')` 的 `false` 做显式判断，保留合法请求体 `'0'`（未误用 `?:`）
- `Request::post()`/`input()`（Request.php:135-171）用 `array_key_exists` 而非 `isset`，`null`/`false` 值不会丢失

**路由编译与匹配**
- `matchRoute()` 对**字面段**全部 `preg_quote(..., '~')`（Router.php:543-548）→ 无正则元字符注入
- 参数名经 `^[a-zA-Z_][a-zA-Z0-9_]*$` 白名单校验，不合法时按字面量处理（Router.php:576、586）
- 自定义正则长度上限 64 字符（Router.php:581-583）
- 编译后先用 `@preg_match($regex,'')` **探针验证**再缓存，坏正则不会被永久缓存（Router.php:598-609）
- 运行时正则失败（回溯超限）会 `error_log` 记录 pattern 与 uri（Router.php:627-636）
- `compiledRoutes` 以 pattern 为键做请求内缓存，避免重复编译
- 未匹配的 `{` 按字面量处理，不会吞掉后续路由段（Router.php:564-569）
- `Router::route()` 使用与 `addRoute` 一致的 brace-matching 正则，支持 `{id:[0-9]{3}}` 这类含 `{}` 的自定义约束
- `executeHandler()` 校验 action 必须为 public（Router.php:722-727），防止调用控制器内部方法
- FormRequest 子类识别使用 `is_subclass_of(..., true)`，抽象基类不会被实例化（Router.php:745）
- `Loader::autoload()`（Loader.php:44-49）用 `realpath` 前缀校验防止 `..` 路径穿越
- `matchRoute` 的 `urldecode` 与 `route()` 的 `urlencode` 构成一致往返（编码正确性没问题，问题在于 CORE-03 的语义选择）

**中间件与限流**
- `CsrfMiddleware` 使用 `hash_equals()` 做**定时安全**比较，GET/HEAD/OPTIONS 跳过，token 为空时 fail-closed（CsrfMiddleware.php:21-30）
- `Cors` 构造器拒绝 `allowed_origins:['*']` + `supports_credentials:true` 的非法组合（Cors.php:26-32）
- `Cors::sanitizeOrigin()` 用 `^https?://[^\s/]+(:\d+)?$` 过滤 Origin 并剥离 CR/LF/空字节（Cors.php:112-119）；回显 Origin 时带 `Vary: Origin`
- `Throttle::attempt()` 用 `flock(LOCK_EX)` + `ftruncate/rewind` 原子递增，消除 TOCTOU；`expire<=0` 或过期时重置计数（Throttle.php:87-124）
- `Throttle::clear()` 用 sha256 前缀 glob，未直接拼接用户可控路径
- `Middleware::shouldSkip()` 通配符用 `preg_quote` + 精确 `*`→`.*` 替换，不存在正则注入

**异常与启动**
- `ExceptionHandler` 的 `SAFE_MESSAGES` 白名单（ExceptionHandler.php:133-141）与 `isTrustedIp()` 限制（209-216）设计正确——**只是从未被接入**（见 CORE-11）
- `ExceptionHandler::buildDebugHtml()/buildProductionHtml()` 对 message/file/trace 均做 `htmlspecialchars(ENT_QUOTES, 'UTF-8')`
- `Application::handleException()` 的日志写入包在 try/catch 中，日志器故障不会掩盖原始异常（Application.php:295-305）；IP 解析同样包 try/catch
- 500 自定义视图渲染失败时按 `ob_get_level()` 基准回滚缓冲，不会产生 `ob_end_clean()` 警告（Application.php:367-379）
- `Application::loadConfig()` 跳过 `Config.php` 本体，且配置缓存为非法值时回退到目录扫描
- `public/index.php:4` 的 `if (defined('APP_PATH')) return;` 正确防止重复包含
- `Response::download()` 对下载文件名做 `basename()` + 剥离 `\r\n\0"\` → 下载文件名注入已阻断
- `HttpException`/`ValidationException` 继承 `FrameworkException` 层级清晰，`getHttpStatusCode()`/`getErrors()` 语义明确

---

# 六、修复优先级建议

**P0（立即，默认配置即受影响）**
1. CORE-01 闭包参数展开 → `array_values($params)`
2. CORE-05 注册 `cors` 等中间件别名（并消费 `config('app.providers')`）
3. CORE-03 路由参数保留原始值，不自动 `urldecode`
4. CORE-02 合并 Attribute 方法级 middleware

**P1（本迭代内）**
5. CORE-04 路由缓存序列化校验 + `route:cache/clear` 命令
6. CORE-06 `group()` 加 `try/finally`
7. CORE-10 `run()` 全流程输出缓冲
8. CORE-12 `FormRequest` 只用 body 数据验证
9. CORE-11 接入 `ExceptionHandler`，删除入口的 `throw $e`
10. CORE-08 HEAD 不输出响应体
11. CORE-13 清理重复路由文件 + 启动期重复检测

**P2（下迭代）**
12. CORE-07/38 统一路径归一化（`Request::decodedPath()`），供 Router 与 Middleware 共用
13. CORE-09 405 + `Allow` + 自动 OPTIONS
14. CORE-14 中间件解析环检测与递归
15. CORE-20/21 `trusted_hosts` / `trusted_proxies` 配置接线
16. CORE-16 移除全局 `pcre.backtrack_limit` 副作用
17. CORE-19/27 错误视图 realpath 校验与作用域隔离

**P3（技术债）**
18. CORE-23 `route()` 补 query string 与 `url()`
19. CORE-42 `Router` 复用 `Pipeline`，删除重复实现
20. CORE-15/41/43 编译期与注册期校验前置，把"静默失败"改为"启动即报错"
21. CORE-33 `server.php` 路由脚本（同时修复文档与实现不符）
22. 其余低危项按模块迭代消化

---

# 七、复现验证记录（PHP 8.4.7 CLI）

| 编号 | 复现命令要点 | 实测输出 |
|---|---|---|
| CORE-01 | `get('/files/{path}', fn($p)=>...)` + `GET /files/a` | `Fatal error: Unknown named parameter $path at Router.php:704` |
| CORE-05 | 加载 `app/route/route.php` 后 `GET /api/users` | `RuntimeException: Invalid middleware: cors` |
| CORE-03 | `GET /files/a%2F..%2F..%2Fetc%2Fpasswd` | `got:[a/../../etc/passwd]` |
| CORE-06 | group 回调抛异常后再注册路由 | 路由被注册为 `/admin/public`，`mw=["X"]` |
| CORE-07 | `parse_url('//evil.com/admin', PATH)` | `/admin` |
| CORE-08 | `GET /h` 命中返回 `Response::make('BODY')` | `status=200 body='BODY'` |
| CORE-09 | 仅注册 GET，`POST /u` | `404 Not Found` |
| CORE-12 | `$_GET['email']` + 空 `$_POST`，FormRequest 校验 | `{"email":"attacker@evil.com"}` |
| CORE-13 | 加载 `app/route/*.php` 全部文件 | `total routes=16 duplicates=6` |
| CORE-14 | `middlewareGroup('a', ['a', ...])` | `Allowed memory size of 134217728 bytes exhausted at Router.php:103` |
| CORE-14 | `aliasMiddleware('w','web')` 指向组 | `RuntimeException: Invalid middleware: web` |
| CORE-15 | `get('/x/{id}/{id}', ...)` + `GET /x/1/2` | `404 Not Found` |
| CORE-22 | `POST tags[]=a&tags[]=b` → `string('tags')` | `Warning: Array to string conversion` → `"Array"` |
| CORE-23 | `route('u.show', ['id'=>7,'page'=>2])` | `/u/7`（`page=2` 丢失） |
| CORE-24 | `Response::redirect('/ok', 200)` | `status=200 Location=/ok` |
| CORE-41 | `load()` 一个返回数组的文件 | `routes=0`，无任何输出 |
| CORE-02 | Attribute 方法级 `middleware: ['auth']` | 注册结果 `mw=["cors"]`，`auth` 消失 |

---

*报告结束。全部 44 条缺陷中，标注"实测复现"的 17 条均已在 PHP 8.4.7 上通过最小脚本验证；其余为静态代码审查结论，修复建议均给出了可直接落地的代码片段。*