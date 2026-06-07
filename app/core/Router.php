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
    private function resolveMiddleware(array $middlewares): array
    {
        $resolved = [];
        foreach ($middlewares as $mw) {
            if (is_string($mw) && isset($this->middlewareAliases[$mw])) {
                $resolved[] = $this->middlewareAliases[$mw];
            } elseif (is_string($mw) && isset($this->middlewareGroups[$mw])) {
                $resolved = array_merge($resolved, $this->resolveMiddleware($this->middlewareGroups[$mw]));
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
        ];

        return $this;
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

        $callback($this);

        $this->group = $previousGroup;
        $this->middlewares = $previousMiddleware;

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
        $uri = '/' . trim((string) parse_url($request->uri(), PHP_URL_PATH), '/');
        if ($uri !== '/') {
            $uri = rtrim($uri, '/');
        }

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            $params = $this->matchRoute($route['uri'], $uri);
            if ($params !== false) {
                $handler = fn () => $this->executeHandler($route['handler'], $params, $request);
                $routeMiddleware = $this->resolveMiddleware($route['middleware'] ?? []);
                $allMiddleware = array_merge($this->resolveMiddleware($this->globalMiddleware), $routeMiddleware);
                return $this->executeMiddleware($allMiddleware, $handler, $request);
            }
        }

        return $this->handleNotFound();
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
            // 将 {param} 转换为命名捕获组
            $compiled = (string) preg_replace('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', '(?<$1>[^/]+)', $pattern);
            // 支持自定义正则表达式 {param:regex}
            $compiled = (string) preg_replace('/\{([a-zA-Z_][a-zA-Z0-9_]*):([^\}]+)\}/', '(?<$1>$2)', $compiled);
            // 使用 ~ 作为定界符避免与自定义正则中的 # 冲突
            $regex = '~^' . $compiled . '$~';
            $this->compiledRoutes[$pattern] = $regex;
        }

        // 执行正则匹配
        if (preg_match($regex, $uri, $matches)) {
            $params = [];
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = $value;
                }
            }
            return $params;
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
                // 字符串形式的中间件类名
                if (is_string($middleware) && class_exists($middleware)) {
                    $instance = $this->container ? $this->container->get($middleware) : new $middleware();
                    if (method_exists($instance, 'handle')) {
                        return $instance->handle($request, $next);
                    }
                }
                // 数组形式 [类名, 方法名]
                if (is_array($middleware) && count($middleware) === 2) {
                    [$class, $method] = $middleware;
                    $instance = $this->container ? $this->container->get($class) : new $class();
                    if (method_exists($instance, $method)) {
                        return $instance->$method($request, $next);
                    }
                }
                // 可调用对象
                if (is_callable($middleware)) {
                    return $middleware($request, $next);
                }
                return $next();
            };
        }

        return $next();
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
            return $handler(...$params);
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

                    // 优先使用路由参数
                    if (isset($params[$paramName])) {
                        $args[] = $params[$paramName];
                    } elseif ($paramType instanceof \ReflectionNamedType && !$paramType->isBuiltin()) {
                        // 类型提示注入
                        $typeName = $paramType->getName();
                        if ($typeName === 'core\Request' || $typeName === 'Request') {
                            $args[] = $request;
                        } elseif ($this->container && $this->container->has($typeName)) {
                            $args[] = $this->container->get($typeName);
                        } elseif (class_exists($typeName)) {
                            $args[] = new $typeName();
                        } elseif ($param->isDefaultValueAvailable()) {
                            $args[] = $param->getDefaultValue();
                        } else {
                            throw new \RuntimeException(
                                "Unable to resolve parameter [\${$paramName}] for [" . get_class($controller) . "::{$action}]"
                            );
                        }
                    } elseif ($param->isDefaultValueAvailable()) {
                        // 使用默认值
                        $args[] = $param->getDefaultValue();
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
        http_response_code(404);

        // 尝试加载自定义错误视图
        $errorView = $this->container?->get('config')['app']['error_views']['404'] ?? null;
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

        $routes = require $cacheFile;
        if (is_array($routes)) {
            $this->routes = $routes;
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
        // 检查是否包含闭包路由，闭包无法被 var_export 序列化
        foreach ($this->routes as $route) {
            if ($route['handler'] instanceof \Closure) {
                return false;
            }
        }

        $dir = dirname($cacheFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $export = var_export($this->routes, true);
        $content = '<?php return ' . $export . ';';
        return file_put_contents($cacheFile, $content, LOCK_EX) !== false;
    }
}