<?php
declare(strict_types=1);

namespace core;

/**
 * 路由管理器
 * 
 * 负责注册路由、匹配请求、执行中间件和处理程序。
 * 支持 RESTful 路由、路由分组、中间件和路由缓存。
 */
class Router
{
    /** @var array<int, array<string, mixed>> 路由列表 */
    private array $routes = [];

    /** @var array<string, mixed> 当前路由分组配置 */
    private array $group = [];

    /** @var array<int, string> 当前分组的中间件列表 */
    private array $middlewares = [];

    /** @var Container|null 依赖注入容器 */
    private ?Container $container = null;

    /** @var array<string, string> 编译后的正则模式缓存 */
    private array $compiledRoutes = [];

    /** @var array<string, array<string, int>> 编译后的正则缓存，含分组名信息 */
    private array $compiledRegexCache = [];

    /** @var array<string, string|callable> 中间件别名映射 */
    private array $middlewareAliases = [];

    /** @var array<string, array<string|callable>> 中间件组 */
    private array $middlewareGroups = [];

    /** @var array<int, string|callable> 全局中间件 */
    private array $globalMiddleware = [];

    /** @var array<string, array{method: string, uri: string}> 已命名路由 */
    private array $namedRoutes = [];

    /** @var string|null 当前路由名称（用于链式调用） */
    private ?string $pendingRouteName = null;

    public function __construct()
    {
        $current = ini_get('pcre.backtrack_limit');
        if ($current === false || (int) $current > 100000) {
            ini_set('pcre.backtrack_limit', '100000');
        }
    }

    /**
     * 注册中间件别名
     * 
     * @param string $name 别名
     * @param string|callable $middleware 中间件类名或闭包
     * @return self
     */
    public function aliasMiddleware(string $name, string|callable $middleware): self
    {
        $this->middlewareAliases[$name] = $middleware;
        return $this;
    }

    /**
     * 注册中间件组
     * 
     * @param string $name 组名
     * @param array $middlewares 中间件列表
     * @return self
     */
    public function middlewareGroup(string $name, array $middlewares): self
    {
        $this->middlewareGroups[$name] = $middlewares;
        return $this;
    }

    /**
     * 设置全局中间件
     * 
     * @param array $middlewares 中间件列表
     * @return self
     */
    public function setGlobalMiddleware(array $middlewares): self
    {
        $this->globalMiddleware = $middlewares;
        return $this;
    }

    /**
     * 解析中间件（别名 → 类名，组 → 展开列表）
     * 
     * @param array $middlewares 中间件列表
     * @return array 解析后的中间件列表
     */
    private function resolveMiddleware(array $middlewares, array $seen = []): array
    {
        $resolved = [];
        foreach ($middlewares as $mw) {
            if (is_string($mw)) {
                // 别名可带构造参数：'throttle:60,1'
                // 解析别名时必须保留 ':args' 后缀，否则 executeMiddleware
                // 只拿到裸类名，参数被静默丢弃。
                [$aliasName] = explode(':', $mw, 2);
                if (isset($this->middlewareAliases[$aliasName])) {
                    $target = $this->middlewareAliases[$aliasName];
                    $resolved[] = is_string($target)
                        ? $target . substr($mw, strlen($aliasName))
                        : $target;
                } elseif (isset($this->middlewareGroups[$aliasName])) {
                    // 中间件组可能自引用/互相引用，无环检测会无限递归耗尽内存
                    if (isset($seen[$aliasName])) {
                        continue;
                    }
                    $seen[$aliasName] = true;
                    $inner = $this->resolveMiddleware($this->middlewareGroups[$aliasName], $seen);
                    // 组后缀同样传递：'web:x' → 组内每项追加 ':x'
                    $suffix = substr($mw, strlen($aliasName));
                    if ($suffix !== '') {
                        foreach ($inner as $k => $item) {
                            if (is_string($item)) {
                                $inner[$k] = $item . $suffix;
                            }
                        }
                    }
                    $resolved = array_merge($resolved, $inner);
                } else {
                    $resolved[] = $mw;
                }
            } else {
                $resolved[] = $mw;
            }
        }
        return $resolved;
    }

    /**
     * 注册 GET 路由
     * 
     * @param string $uri 路由路径
     * @param callable|array $handler 处理程序（闭包或 [控制器, 方法] 数组）
     * @return self
     */
    public function get(string $uri, callable|array $handler): self
    {
        return $this->addRoute('GET', $uri, $handler);
    }

    /**
     * 注册 POST 路由
     * 
     * @param string $uri 路由路径
     * @param callable|array $handler 处理程序
     * @return self
     */
    public function post(string $uri, callable|array $handler): self
    {
        return $this->addRoute('POST', $uri, $handler);
    }

    /**
     * 注册 PUT 路由
     * 
     * @param string $uri 路由路径
     * @param callable|array $handler 处理程序
     * @return self
     */
    public function put(string $uri, callable|array $handler): self
    {
        return $this->addRoute('PUT', $uri, $handler);
    }

    /**
     * 注册 DELETE 路由
     * 
     * @param string $uri 路由路径
     * @param callable|array $handler 处理程序
     * @return self
     */
    public function delete(string $uri, callable|array $handler): self
    {
        return $this->addRoute('DELETE', $uri, $handler);
    }

    /**
     * 注册 PATCH 路由
     * 
     * @param string $uri 路由路径
     * @param callable|array $handler 处理程序
     * @return self
     */
    public function patch(string $uri, callable|array $handler): self
    {
        return $this->addRoute('PATCH', $uri, $handler);
    }

    /**
     * 注册 OPTIONS 路由
     * 
     * @param string $uri 路由路径
     * @param callable|array $handler 处理程序
     * @return self
     */
    public function options(string $uri, callable|array $handler): self
    {
        return $this->addRoute('OPTIONS', $uri, $handler);
    }

    /**
     * 注册匹配所有 HTTP 方法的路由
     * 
     * @param string $uri 路由路径
     * @param callable|array $handler 处理程序
     * @return self
     */
    public function any(string $uri, callable|array $handler): self
    {
        $methods = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'];
        foreach ($methods as $method) {
            $this->addRoute($method, $uri, $handler);
        }
        return $this;
    }

    /**
     * 注册匹配指定 HTTP 方法的路由
     * 
     * @param array $methods HTTP 方法列表
     * @param string $uri 路由路径
     * @param callable|array $handler 处理程序
     * @return self
     */
    public function match(array $methods, string $uri, callable|array $handler): self
    {
        foreach ($methods as $method) {
            $this->addRoute(strtoupper($method), $uri, $handler);
        }
        return $this;
    }

    /**
     * 添加路由到路由列表
     * 
     * @param string $method HTTP 方法
     * @param string $uri 路由路径
     * @param callable|array $handler 处理程序
     * @return self
     */
    private function addRoute(string $method, string $uri, callable|array $handler): self
    {
        $uri = '/' . trim($uri, '/');
        if ($uri !== '/') {
            $uri = rtrim($uri, '/');
        }

        if (isset($this->group['prefix'])) {
            $prefix = rtrim($this->group['prefix'], '/');
            $uri = $prefix . $uri;
            if ($uri !== '/') {
                $uri = rtrim($uri, '/');
            }
        }

        $this->routes[] = [
            'method' => $method,
            'uri' => $uri,
            'handler' => $handler,
            'middleware' => $this->middlewares,
            'group' => $this->group,
            'name' => $this->pendingRouteName,
        ];

        if ($this->pendingRouteName !== null) {
            $this->namedRoutes[$this->pendingRouteName] = [
                'method' => $method,
                'uri' => $uri,
            ];
            $this->pendingRouteName = null;
        }

        return $this;
    }

    /**
     * 为路由命名
     *
     * 可在路由注册后链式调用：$router->get('/users', ...)->name('users.index')
     *
     * @param string $name 路由名称
     * @return self
     */
    public function name(string $name): self
    {
        if (!empty($this->routes)) {
            $lastIndex = array_key_last($this->routes);
            $this->routes[$lastIndex]['name'] = $name;
            $this->namedRoutes[$name] = [
                'method' => $this->routes[$lastIndex]['method'],
                'uri' => $this->routes[$lastIndex]['uri'],
            ];
        } else {
            $this->pendingRouteName = $name;
        }
        return $this;
    }

    /**
     * 通过路由名称生成 URL
     *
     * @param string $name 路由名称
     * @param array $parameters 路由参数
     * @return string URL
     * @throws \RuntimeException 当路由名称不存在时
     */
    public function route(string $name, array $parameters = []): string
    {
        if (!isset($this->namedRoutes[$name])) {
            throw new \RuntimeException("Route [{$name}] not defined.");
        }

        $uri = $this->namedRoutes[$name]['uri'];

        // 用与 addRoute 一致的 brace-matching 解析参数占位符，支持自定义正则中含 {}（如 {id:[0-9]{3}}）
        $uri = preg_replace_callback(
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

        return $uri;
    }

    /**
     * 添加中间件到当前分组
     * 
     * @param string|array $middleware 中间件类名或中间件数组
     * @return self
     */
    public function middleware(string|array $middleware): self
    {
        $this->middlewares = array_merge($this->middlewares, (array) $middleware);
        return $this;
    }

    /**
     * 创建路由分组
     * 
     * 支持设置前缀和中间件，分组内的路由会继承这些配置。
     * 
     * @param array $attributes 分组属性（prefix, middleware）
     * @param callable $callback 分组回调
     * @return self
     */
    public function group(array $attributes, callable $callback): self
    {
        $previousGroup = $this->group;
        $previousMiddleware = $this->middlewares;

        if (isset($attributes['middleware'])) {
            $this->middlewares = array_merge($this->middlewares, (array) $attributes['middleware']);
        }

        if (isset($attributes['prefix'])) {
            $innerPrefix = '/' . trim($attributes['prefix'], '/');
            $this->group['prefix'] = ($this->group['prefix'] ?? '') . $innerPrefix;
        }

        // 回调内若抛异常，必须恢复分组状态。
        // 否则后续注册的路由会继承本组的 prefix/middleware
        //（实测：group(['prefix'=>'/admin'], fn()=>throw) 后 get('/public')
        //       被注册成 '/admin/public' 并继承错误中间件）。
        try {
            $callback($this);
        } finally {
            $this->group = $previousGroup;
            $this->middlewares = $previousMiddleware;
        }

        return $this;
    }

    /**
     * 从文件加载路由
     * 
     * @param string $file 路由文件路径
     */
    public function load(string $file): void
    {
        if (file_exists($file)) {
            $router = require $file;
            if ($router instanceof Router) {
                $this->routes = array_merge($this->routes, $router->getRoutes());
                $this->namedRoutes = array_merge($this->namedRoutes, $router->namedRoutes);
            }
        }
    }

    /**
     * 设置依赖注入容器
     *
     * @param Container $container 容器实例
     */
    public function setContainer(Container $container): void
    {
        $this->container = $container;
    }

    /**
     * 注册控制器中通过 PHP 8 Attribute 声明的路由
     *
     * 扫描类级 #[Route(prefix:, middleware:)] 与方法级 #[Route]/#[Get]/#[Post] 等
     * 语法糖属性，将其注册为常规路由。与 route 文件定义的路由共存。
     *
     * @param string $controllerClass 控制器类名
     * @return int 注册的路由数量
     */
    public function registerController(string $controllerClass): int
    {
        if (!class_exists($controllerClass)) {
            return 0;
        }

        $reflection = new \ReflectionClass($controllerClass);

        // 类级属性：prefix / middleware
        $prefix = null;
        $middleware = [];
        foreach ($reflection->getAttributes(\core\attributes\Route::class, \ReflectionAttribute::IS_INSTANCEOF) as $attr) {
            $classRoute = $attr->newInstance();
            if ($classRoute->prefix !== null) {
                $prefix = $classRoute->prefix;
            }
            if (!empty($classRoute->middleware)) {
                $middleware = $classRoute->middleware;
            }
        }

        $count = 0;
        $register = function () use ($reflection, $controllerClass, &$count, $middleware): void {
            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->isConstructor() || $method->isStatic()) {
                    continue;
                }
                // 仅注册直接声明于本类的方法，避免继承的公共方法被注册
                if ($method->getDeclaringClass()->getName() !== $reflection->getName()) {
                    continue;
                }
                $attrs = $method->getAttributes(\core\attributes\Route::class, \ReflectionAttribute::IS_INSTANCEOF);
                foreach ($attrs as $attr) {
                    $route = $attr->newInstance();
                    $httpMethod = strtoupper($route->method);

                    // 方法级 middleware 追加到类级 middleware 之后。
                    // 此前 $route->middleware 从未被读取，方法上写的中间件被静默丢弃
                    // （实测：#[Route('/secure', middleware:['auth'])] 实际未受保护）。
                    // 支持 '!name' 前缀从继承链中剔除类级中间件。
                    $routeMiddleware = $middleware;
                    foreach ($route->middleware as $mw) {
                        if (is_string($mw) && str_starts_with($mw, '!')) {
                            $routeMiddleware = array_values(array_filter(
                                $routeMiddleware,
                                fn ($m) => $m !== substr($mw, 1)
                            ));
                        } else {
                            $routeMiddleware[] = $mw;
                        }
                    }

                    $previousMiddleware = $this->middlewares;
                    if ($routeMiddleware) {
                        $this->middlewares = $routeMiddleware;
                    }
                    try {
                        $this->addRoute($httpMethod, $route->path, [$controllerClass, $method->getName()]);
                        if ($route->name !== null) {
                            $this->name($route->name);
                        }
                    } finally {
                        $this->middlewares = $previousMiddleware;
                    }
                    $count++;
                }
            }
        };

        if ($prefix !== null) {
            $this->group(['prefix' => $prefix], $register);
        } else {
            $register();
        }

        return $count;
    }

    /**
     * 扫描控制器目录，注册其中所有类的 Attribute 路由
     *
     * @param string $directory 控制器目录绝对路径（末尾带分隔符）
     * @param string $namespace 对应的根命名空间（末尾带 \\）
     * @return int 注册的路由数量
     */
    public function scanControllerDirectory(string $directory, string $namespace = 'controller\\'): int
    {
        if (!is_dir($directory)) {
            return 0;
        }
        $files = glob($directory . '*.php');
        if ($files === false) {
            return 0;
        }
        $count = 0;
        foreach ($files as $file) {
            $class = $namespace . basename($file, '.php');
            if (class_exists($class)) {
                $count += $this->registerController($class);
            }
        }
        return $count;
    }

    /**
     * 调度请求
     * 
     * 匹配路由并执行对应的处理程序和中间件。
     * 
     * @param Request|null $request 请求对象
     * @return mixed 响应结果
     */
    public function dispatch(?Request $request = null): mixed
    {
        if ($request === null) {
            $request = new Request();
        }

        $method = $request->method();
        $uri = $this->normalizeUri($request->uri());
        if ($uri === false) {
            // 请求 URI 含有控制字符 / 反斜杠等异常内容，直接判定为不匹配
            return $this->handleNotFound();
        }
        if ($uri !== '/') {
            $uri = rtrim($uri, '/');
        }

        // 第一遍记录「URI 命中但方法不匹配」的路由，用于返回 405 + Allow 头
        $allowedMethods = [];

        foreach ($this->routes as $route) {
            // HEAD 请求应匹配 GET 路由（HTTP 规范）
            $methodMatch = $route['method'] === $method
                || ($method === 'HEAD' && $route['method'] === 'GET');

            $params = $this->matchRoute($route['uri'], $uri);
            if ($params === false) {
                continue;
            }

            if (!$methodMatch) {
                // URI 命中但方法不允许：记录下来，供循环结束后返回 405 + Allow
                $allowedMethods[$route['method']] = true;
                continue;
            }

            $handler = fn () => $this->executeHandler($route['handler'], $params, $request);
            $routeMiddleware = $this->resolveMiddleware($route['middleware'] ?? []);
            $allMiddleware = array_merge($this->resolveMiddleware($this->globalMiddleware), $routeMiddleware);
            return $this->executeMiddleware($allMiddleware, $handler, $request);
        }

        if ($allowedMethods !== []) {
            if ($method === 'HEAD') {
                // HEAD 允许由 GET 兜底
                $allowedMethods['GET'] = true;
            }
            if ($method === 'OPTIONS') {
                // 自动应答预检。必须经中间件链：否则 Cors 的预检分支不会执行，
                // 响应不含任何 Access-Control-* 头，浏览器会拦截预检请求
                // （实测 OPTIONS /api/user 返回 204 但零 CORS 头）。
                $allowHeader = implode(', ', array_keys($allowedMethods));
                // 此处不能复用下方循环里的 $allMiddleware（尚未计算），
                // 需按同样的规则重新合并全局 + 路由中间件。
                $preflightMiddleware = array_merge(
                    $this->resolveMiddleware($this->globalMiddleware),
                    $this->resolveMiddleware($route['middleware'] ?? [])
                );
                $preflight = fn() => Response::make('', 204)->header('Allow', $allowHeader);
                return $this->executeMiddleware($preflightMiddleware, $preflight, $request);
            }
            return Response::make('<h1>405 Method Not Allowed</h1>', 405)
                ->header('Allow', implode(', ', array_keys($allowedMethods)));
        }

        return $this->handleNotFound();
    }

    /**
     * 规范化请求 URI
     *
     * 处理要点：
     * - 折叠重复斜杠（//a//b → /a/b）
     * - 以 `//` 开头时先折叠，否则 parse_url() 会按 authority-form 解析，
     *   把首段当成主机名，导致 `//evil.com/admin` 被分发到 `/admin` 路由
     *   （WAF/日志/限流看到的路径与实际分发的路由不一致）
     * - 拒绝含反斜杠、NUL 与控制字符的 URI
     *
     * @param string $raw 原始 REQUEST_URI
     * @return string|false 规范化后的路径；无法安全解析时返回 false
     */
    private function normalizeUri(string $raw): string|false
    {
        if (str_contains($raw, '\\') || preg_match('/[\x00-\x1F\x7F]/', $raw) === 1) {
            return false;
        }

        // 先折叠重复斜杠，避免 authority-form 解析歧义
        $collapsed = preg_replace('#/+#', '/', $raw);
        if (!is_string($collapsed)) {
            return false;
        }

        // 剥离查询串与 fragment（REQUEST_URI 形如 /path?query）
        $path = parse_url($collapsed, PHP_URL_PATH);
        if (!is_string($path)) {
            return false;
        }

        return '/' . trim($path, '/');
    }

    /**
     * 匹配路由模式
     * 
     * 将路由模式编译为正则表达式并匹配请求URI。
     * 
     * @param string $pattern 路由模式
     * @param string $uri 请求URI
     * @return array|false 匹配的参数数组或false
     */
    private function matchRoute(string $pattern, string $uri): array|false
    {
        // 精确匹配
        if ($pattern === $uri) {
            return [];
        }

        // 使用缓存的正则表达式，避免重复编译
        $regex = $this->compiledRoutes[$pattern] ?? null;
        if ($regex === null) {
            // 将路由模式编译为正则表达式，对字面部分进行 preg_quote 转义
            $compiled = '';
            $offset = 0;
            $len = strlen($pattern);

            while ($offset < $len) {
                $bracePos = strpos($pattern, '{', $offset);
                if ($bracePos === false) {
                    $compiled .= preg_quote(substr($pattern, $offset), '~');
                    break;
                }

                // 转义参数前的字面部分
                $compiled .= preg_quote(substr($pattern, $offset, $bracePos - $offset), '~');

                // 查找匹配的 }，支持自定义正则中包含 {}
                $depth = 1;
                $paramEnd = $bracePos + 1;
                while ($paramEnd < $len && $depth > 0) {
                    if ($pattern[$paramEnd] === '{') {
                        $depth++;
                    } elseif ($pattern[$paramEnd] === '}') {
                        $depth--;
                    }
                    if ($depth > 0) {
                        $paramEnd++;
                    }
                }

                if ($depth !== 0) {
                    // 未匹配的 {，作为字面量处理
                    $compiled .= preg_quote('{', '~');
                    $offset = $bracePos + 1;
                    continue;
                }

                // 提取参数定义
                $paramDef = substr($pattern, $bracePos + 1, $paramEnd - $bracePos - 1);

                if (str_contains($paramDef, ':')) {
                    [$paramName, $customRegex] = explode(':', $paramDef, 2);
                    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $paramName)) {
                        $compiled .= preg_quote('{' . $paramDef . '}', '~');
                        $offset = $paramEnd + 1;
                        continue;
                    }
                    if (strlen($customRegex) > 64) {
                        throw new \InvalidArgumentException("Route regex too long for parameter '{$paramName}'");
                    }
                    $compiled .= "(?<{$paramName}>" . str_replace('~', '\~', $customRegex) . ")";
                } else {
                    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $paramDef)) {
                        $compiled .= preg_quote('{' . $paramDef . '}', '~');
                        $offset = $paramEnd + 1;
                        continue;
                    }
                    $compiled .= "(?<{$paramDef}>[^/]+)";
                }

                $offset = $paramEnd + 1;
            }

            $regex = '~^' . $compiled . '$~';
            // 先验证正则可编译，再缓存；避免无效正则被永久缓存导致后续全部失配
            $probe = @preg_match($regex, '');
            if ($probe === false) {
                $err = preg_last_error();
                error_log(sprintf(
                    'Router matchRoute: regex compile error [%d] for pattern="%s"',
                    $err,
                    $pattern
                ));
                return false;
            }
            $this->compiledRoutes[$pattern] = $regex;
        }

        // 执行正则匹配
        $result = preg_match($regex, $uri, $matches);
        if ($result === 1) {
            $params = [];
            foreach ($matches as $key => $value) {
                // 只收集命名参数（字符串 key），不收集数字 key（整体匹配）
                if (!is_string($key)) {
                    continue;
                }
                // urldecode 值，避免控制器收到 %20 等编码后的值
                // 与 Router::route() 的 urlencode 形成往返一致
                $decoded = is_string($value) ? urldecode($value) : $value;
                if (!is_string($decoded)) {
                    $params[$key] = $decoded;
                    continue;
                }
                // 路由段按定义不得含分隔符。若解码后出现 / \ 或 NUL，
                // 说明调用方编码了分隔符（%2F 等）。直接放行会把
                // /files/a%2F..%2F..%2Fetc%2Fpasswd 还原成 a/../../etc/passwd，
                // 任何把该参数拼进文件路径的控制器都会被穿越。
                if (str_contains($decoded, '/') || str_contains($decoded, '\\') || str_contains($decoded, "\0")) {
                    return false;
                }
                $params[$key] = $decoded;
            }
            return $params;
        }

        if ($result === false) {
            // 运行时正则失败（如回溯超限）— 记录错误便于排查
            $err = preg_last_error();
            error_log(sprintf(
                'Router matchRoute: regex error [%d] for pattern="%s" uri="%s"',
                $err,
                $pattern,
                $uri
            ));
        }

        return false;
    }

    /**
     * 执行中间件链
     * 
     * 使用洋葱模型执行中间件，最后执行处理程序。
     * 
     * @param array $middlewares 中间件列表
     * @param callable $handler 处理程序
     * @param \core\Request $request 请求对象
     * @return mixed 响应结果
     */
    private function executeMiddleware(array $middlewares, callable $handler, \core\Request $request): mixed
    {
        $next = $handler;

        // 逆序遍历中间件，构建洋葱模型
        foreach (array_reverse($middlewares) as $middleware) {
            $next = function () use ($middleware, $next, $request) {
                return $this->invokeMiddleware($middleware, $next, $request);
            };
        }

        return $next();
    }

    /**
     * 执行单个中间件
     *
     * 支持三种形式：
     *  - 已实例化的对象（core\Pipeline 亦支持，两套执行器能力保持一致）
     *  - 'ClassName'
     *  - 'ClassName:arg1,arg2'（逗号分隔的构造参数）
     *
     * @param mixed $middleware 中间件定义
     * @param callable $next 下一层
     * @param \core\Request $request 当前请求
     * @return mixed
     */
    private function invokeMiddleware(mixed $middleware, callable $next, \core\Request $request): mixed
    {
        // 已实例化的中间件对象：core\Pipeline 本就支持对象形式，
        // 此处缺失导致 docs 中推荐的 new Cors([...]) 直接抛
        // "Invalid middleware: object"。同框架两套执行器能力必须一致。
        if (is_object($middleware)) {
            if (method_exists($middleware, 'handle')) {
                return $middleware->handle($request, $next);
            }
            if (is_callable($middleware)) {
                return $middleware($request, $next);
            }
            throw new \RuntimeException(
                'Middleware ' . get_class($middleware) . ' does not implement handle() method'
            );
        }

        if (is_string($middleware)) {
            // 可带构造参数：'throttle:60,1'
            $className = $middleware;
            $ctorArgs = [];
            if (str_contains($middleware, ':')) {
                [$className, $argStr] = explode(':', $middleware, 2);
                $ctorArgs = array_values(array_filter(
                    array_map('trim', explode(',', $argStr)),
                    static fn(string $a): bool => $a !== ''
                ));
            }
            if (class_exists($className)) {
                // 按构造函数签名把 ':a,b' 的字符串参数转成声明的类型。
                // 否则 'throttle:60,1' 会把字符串 "60" 传给 int $maxAttempts，
                // strict_types 下抛 TypeError。
                $ctorArgs = $this->castConstructorArgs($className, $ctorArgs);
                // 容器有实例且无构造参数时复用（保持单例语义）
                $instance = ($ctorArgs === [] && $this->container)
                    ? $this->container->get($className)
                    : new $className(...$ctorArgs);
                if (method_exists($instance, 'handle')) {
                    return $instance->handle($request, $next);
                }
                throw new \RuntimeException("Middleware {$className} does not implement handle() method");
            }
        }

        // 数组形式 [类名, 方法名]
        if (is_array($middleware) && count($middleware) === 2) {
            [$class, $method] = $middleware;
            if (class_exists($class)) {
                $instance = $this->container ? $this->container->get($class) : new $class();
                if (method_exists($instance, $method)) {
                    return $instance->$method($request, $next);
                }
                throw new \RuntimeException("Middleware method {$method} does not exist on {$class}");
            }
        }

        // 可调用对象 / 闭包
        if (is_callable($middleware)) {
            return $middleware($request, $next);
        }

        $identifier = is_string($middleware) ? $middleware : gettype($middleware);
        throw new \RuntimeException("Invalid middleware: {$identifier}");
    }

    /**
     * 按构造函数签名把字符串参数转换为声明的类型
     *
     * 支持 'throttle:60,1' 这类写法：解析出的参数都是字符串，
     * 直接传给 int/float/bool 形参会在 strict_types 下抛 TypeError。
     *
     * @param string $className 中间件类名
     * @param array $args 原始字符串参数
     * @return array 转换后的参数
     */
    private function castConstructorArgs(string $className, array $args): array
    {
        if ($args === []) {
            return $args;
        }
        $ref = new \ReflectionClass($className);
        $ctor = $ref->getConstructor();
        if ($ctor === null) {
            return $args;
        }
        $params = $ctor->getParameters();
        foreach ($args as $i => $arg) {
            if (!isset($params[$i]) || !is_string($arg)) {
                continue;
            }
            $type = $params[$i]->getType();
            if (!$type instanceof \ReflectionNamedType || $type->isBuiltin() === false) {
                continue;
            }
            $args[$i] = match ($type->getName()) {
                'int' => (int) $arg,
                'float' => (float) $arg,
                'bool' => filter_var($arg, FILTER_VALIDATE_BOOLEAN),
                default => $arg,
            };
        }
        return $args;
    }

    /**
     * 执行路由处理程序
     * 
     * 支持闭包和控制器方法两种形式的处理程序。
     * 
     * @param callable|array $handler 处理程序（闭包或 [控制器, 方法] 数组）
     * @param array $params 路由参数
     * @return mixed 响应结果
     * @throws \RuntimeException 当处理程序不可调用时
     */
    private function executeHandler(callable|array $handler, array $params, Request $request): mixed
    {
        // 闭包直接执行
        if ($handler instanceof \Closure) {
            // 路由参数数组的键是占位符名字符串（如 ['path' => 'a']）。
            // PHP 8.1+ 中 `...$array` 对含字符串键的数组按「命名参数」展开，
            // 会要求闭包形参名与占位符名完全一致，否则抛 Error: Unknown named parameter。
            // 框架语义应为「按声明顺序位置传参」，因此必须 array_values() 丢弃键。
            $args = array_values($params);
            $reflection = new \ReflectionFunction($handler);
            if ($reflection->isVariadic()) {
                return $handler(...$args);
            }
            // 形参数量不足时截断，多余形参交由默认值处理（PHP 会忽略多余实参）
            $count = $reflection->getNumberOfParameters();
            if ($count < count($args)) {
                $args = array_slice($args, 0, $count);
            }
            return $handler(...$args);
        }

        // 数组形式的控制器方法
        if (is_array($handler)) {
            [$controller, $action] = $handler;

            // 如果控制器是字符串类名，实例化它
            if (is_string($controller) && class_exists($controller)) {
                $controller = $this->container
                    ? $this->container->get($controller)
                    : new $controller();
            }

            // 检查方法是否存在
            if (method_exists($controller, $action)) {
                $method = new \ReflectionMethod($controller, $action);

                // 检查方法是否为公共方法
                if (!$method->isPublic()) {
                    throw new \RuntimeException(
                        sprintf('Action [%s] on controller [%s] must be public', $action, get_class($controller))
                    );
                }

                $methodParams = $method->getParameters();

                // 解析方法参数
                $args = [];
                foreach ($methodParams as $param) {
                    $paramName = $param->getName();
                    $paramType = $param->getType();

                    // 优先使用路由参数（用 array_key_exists 允许 false/null 值的参数）
                    if (array_key_exists($paramName, $params)) {
                        $args[] = $params[$paramName];
                    } elseif ($paramType instanceof \ReflectionNamedType && !$paramType->isBuiltin()) {
                        // 类型提示注入
                        $typeName = $paramType->getName();
                        if ($typeName === 'core\Request' || $typeName === 'Request') {
                            $args[] = $request;
                        } elseif (class_exists($typeName) && is_subclass_of($typeName, \core\FormRequest::class, true)) {
                            // FormRequest 子类：实例化并自动触发授权与验证，失败抛 HttpException/ValidationException
                            $formRequest = new $typeName();
                            $formRequest->validateResolved();
                            $args[] = $formRequest;
                        } elseif ($this->container && $this->container->has($typeName)) {
                            $args[] = $this->container->get($typeName);
                        } elseif (class_exists($typeName)) {
                            $args[] = new $typeName();
                        } elseif ($param->isDefaultValueAvailable()) {
                            $args[] = $param->getDefaultValue();
                        } elseif ($paramType->allowsNull()) {
                            $args[] = null;
                        } else {
                            throw new \RuntimeException(
                                "Unable to resolve parameter [\${$paramName}] for [" . get_class($controller) . "::{$action}]"
                            );
                        }
                    } elseif ($param->isDefaultValueAvailable()) {
                        // 使用默认值
                        $args[] = $param->getDefaultValue();
                    } elseif ($paramType !== null && $paramType->allowsNull()) {
                        $args[] = null;
                    } else {
                        throw new \RuntimeException(
                            "Unable to resolve parameter [\${$paramName}] for [" . get_class($controller) . "::{$action}]"
                        );
                    }
                }

                return $method->invokeArgs($controller, $args);
            }
        }

        throw new \RuntimeException('Handler not callable');
    }

    /**
     * 处理 404 未找到
     * 
     * 尝试加载自定义 404 错误视图，不存在则返回默认响应。
     * 
     * @return Response 404 响应
     */
    private function handleNotFound(): Response
    {
        // 尝试加载自定义错误视图
        $config = $this->container?->get('config');
        $errorView = is_array($config) && isset($config['app']['error_views']['404'])
            ? $config['app']['error_views']['404']
            : null;
        if ($errorView !== null && defined('VIEW_PATH')) {
            $viewPath = VIEW_PATH . ltrim($errorView, '/') . '.php';
            if (file_exists($viewPath)) {
                ob_start();
                require $viewPath;
                return Response::make(ob_get_clean() ?: '', 404);
            }
        }

        // 返回默认 404 响应
        return Response::make('<h1>404 Not Found</h1>', 404);
    }

    /**
     * 获取所有注册的路由
     * 
     * @return array<int, array<string, mixed>> 路由列表
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }

    /**
     * 加载缓存路由（跳过路由文件解析）
     * 
     * @param string $cacheFile 缓存文件路径
     * @return bool 是否加载成功
     */
    public function loadCachedRoutes(string $cacheFile): bool
    {
        if (!file_exists($cacheFile)) {
            return false;
        }

        $data = require $cacheFile;
        if (is_array($data) && isset($data['routes'])) {
            $this->routes = $data['routes'];
            $this->namedRoutes = $data['namedRoutes'] ?? [];
            return true;
        }

        return false;
    }

    /**
     * 将当前路由缓存到文件
     * 
     * @param string $cacheFile 缓存文件路径
     * @return bool 是否缓存成功
     */
    public function cacheRoutes(string $cacheFile): bool
    {
        // var_export 无法还原闭包，也无法还原任意对象实例
        // （对象会变成 ClassName::__set_state(array(...))，类未实现该方法时致命错误）。
        // 因此 handler 与 middleware 中的任何不可序列化值都必须让缓存整体放弃。
        foreach ($this->routes as $route) {
            if (!$this->isCacheableValue($route['handler'] ?? null)) {
                return false;
            }
            if (!$this->isCacheableValue($route['middleware'] ?? null)) {
                return false;
            }
        }

        $dir = dirname($cacheFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $data = [
            'routes' => $this->routes,
            'namedRoutes' => $this->namedRoutes,
        ];
        $export = var_export($data, true);
        $content = '<?php return ' . $export . ';';
        // 原子写：先写临时文件再 rename，避免并发请求 require 到半截文件
        $tmpFile = $cacheFile . '.' . getmypid() . '.tmp';
        if (file_put_contents($tmpFile, $content, LOCK_EX) === false) {
            return false;
        }
        if (!@rename($tmpFile, $cacheFile)) {
            @unlink($tmpFile);
            return false;
        }
        return true;
    }

    /**
     * 判断某个值能否被 var_export 正确还原为等价的可执行代码
     *
     * 仅允许：标量、数组、类名字符串（对应 [Class::class, 'method'] 形式）。
     * 闭包、对象实例、资源一律视为不可缓存。
     *
     * @param mixed $value 待检查的值
     * @return bool 是否可缓存
     */
    private function isCacheableValue(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if (!$this->isCacheableValue($item)) {
                    return false;
                }
            }
            return true;
        }
        if ($value === null || is_scalar($value)) {
            return true;
        }
        // 字符串类名可被 var_export 原样还原（[SomeClass::class, 'method']）
        return is_string($value);
    }
}