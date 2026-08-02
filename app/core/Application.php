<?php
declare(strict_types=1);

namespace core;

/**
 * 应用程序核心类
 * 
 * 负责整个框架的初始化、服务注册和请求调度。
 * 是 LightPHP 框架的核心入口，管理容器、路由、事件等核心组件。
 */
class Application
{
    /** @var Container 依赖注入容器 */
    private Container $container;

    /** @var Router 路由管理器 */
    private Router $router;

    /** @var EventDispatcher 事件调度器 */
    private EventDispatcher $events;

    /** @var array 配置数组 */
    private array $config = [];

    /** @var array 服务提供者列表 */
    private array $providers = [];

    /** @var bool 是否已启动 */
    private bool $booted = false;

    /** @var static|null 单例实例 */
    private static ?self $instance = null;

    /**
     * 获取应用单例实例
     */
    public static function getInstance(): ?self
    {
        return self::$instance;
    }

    /**
     * 获取路由管理器
     */
    public function getRouter(): Router
    {
        return $this->router;
    }

    /**
     * 构造函数 - 初始化应用核心组件
     */
    public function __construct()
    {
        self::$instance = $this;
        $this->container = new Container();
        Container::setInstance($this->container);
        $this->router = new Router();
        $this->events = new EventDispatcher();
        $this->loadConfig();
        $this->registerServices();
    }

    /**
     * 加载配置文件
     * 
     * 优先从缓存加载配置，若缓存不存在则从 config 目录读取所有配置文件。
     */
    private function loadConfig(): void
    {
        // 尝试从缓存加载配置，提高启动速度
        $cachedFile = STORAGE_PATH . 'cache/config_cache.php';
        if (file_exists($cachedFile)) {
            $cached = require $cachedFile;
            if (is_array($cached)) {
                $this->config = $cached;
                return;
            }
        }

        // 从配置目录加载所有 .php 配置文件
        $configPath = APP_PATH . 'config/';
        if (is_dir($configPath)) {
            $files = glob($configPath . '*.php');
            if ($files !== false) {
                foreach ($files as $file) {
                    $name = basename($file, '.php');
                    if ($name === 'Config') {
                        continue;
                    }
                    $result = require $file;
                    $this->config[$name] = is_array($result) ? $result : [];
                }
            }
        }
    }

    /**
     * 注册核心服务到容器
     * 
     * 注册配置、路由、事件、数据库、缓存、日志等核心服务。
     */
    private function registerServices(): void
    {
        // 注册配置服务
        $this->container->instance('config', $this->config);
        $this->container->singleton('router', fn() => $this->router);
        $this->container->singleton('events', fn() => $this->events);

        // 注册数据库连接
        $dbConfig = $this->config['database'] ?? [];
        if (isset($dbConfig['connections'])) {
            $default = $dbConfig['default'] ?? 'mysql';
            $dbConfig = $dbConfig['connections'][$default] ?? [];
        }
        $this->container->singleton('db', fn() => new \db\Connection($dbConfig));

        // 注册缓存管理器
        $this->container->singleton('cache', function () {
            $cacheConfig = $this->config['cache'] ?? [];
            return new \cache\CacheManager($cacheConfig);
        });

        // 注册日志服务
        $this->container->singleton('log', fn() => new \log\Logger(STORAGE_PATH . 'log/'));

        // 为路由设置容器
        $this->router->setContainer($this->container);

        // 设置应用密钥用于加密
        \core\Hash::setApplicationKey($this->getConfig('app.key', ''));
    }

    /**
     * 注册服务提供者
     * 
     * @param ServiceProvider $provider 服务提供者实例
     */
    public function registerProvider(ServiceProvider $provider): void
    {
        $provider->setApplication($this);
        $provider->register();
        $this->providers[] = $provider;
    }

    /**
     * 启动所有服务提供者
     * 
     * 调用所有已注册服务提供者的 boot() 方法，并触发 'app.booted' 事件。
     */
    public function bootProviders(): void
    {
        if ($this->booted) {
            return;
        }

        foreach ($this->providers as $provider) {
            $provider->boot();
        }

        // 触发应用启动完成事件
        $this->events->dispatch('app.booted');

        $this->booted = true;
    }

    /**
     * 运行应用程序
     * 
     * 启动服务提供者，加载路由，调度请求并返回响应。
     */
    public function run(): void
    {
        try {
            $this->bootProviders();

            $routeCacheFile = STORAGE_PATH . 'cache/route_cache.php';
            if (!$this->router->loadCachedRoutes($routeCacheFile)) {
                $routeFiles = glob(APP_PATH . 'route/*.php');
                if ($routeFiles !== false) {
                    foreach ($routeFiles as $file) {
                        $this->router->load($file);
                    }
                }
                // 扫描控制器 PHP 8 Attribute 路由，与 route 文件定义共存
                $this->router->scanControllerDirectory(APP_PATH . 'controller/', 'controller\\');
            }

            $result = $this->router->dispatch();

            if ($result instanceof \core\Response) {
                $result->send();
            } elseif (is_array($result)) {
                \core\Response::json($result)->send();
            } elseif (is_string($result)) {
                echo $result;
            } elseif (is_object($result) && method_exists($result, '__toString')) {
                echo (string) $result;
            } elseif ($result === null || $result === false) {
                // 处理程序返回 null/false：视为无内容响应，输出空 200
                // （控制器可能有意返回 null 表示无返回体，不应被误判为 500 错误）
                \core\Response::make('', 200)->send();
            } else {
                // 其他类型（int/float/bool true/无 __toString 的对象）— 强制 500 避免抛 TypeError
                \core\Response::json(['code' => 500, 'message' => 'Internal Server Error'])->send();
            }
        } catch (\Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * 获取依赖注入容器
     * 
     * @return Container 容器实例
     */
    public function getContainer(): Container
    {
        return $this->container;
    }

    /**
     * 获取事件调度器
     * 
     * @return EventDispatcher 事件调度器实例
     */
    public function getEvents(): EventDispatcher
    {
        return $this->events;
    }

    /**
     * 获取配置项
     * 
     * @param string|null $key 配置键，支持点号分隔（如 'app.debug'）
     * @param mixed $default 默认值
     * @return mixed 配置值
     */
    public function getConfig(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->config;
        }

        $keys = explode('.', $key);
        $value = $this->config;

        foreach ($keys as $k) {
            if (!is_array($value) || !array_key_exists($k, $value)) {
                return $default;
            }
            $value = $value[$k];
        }

        return $value;
    }

    /**
     * 设置配置项
     * 
     * @param string $key 配置键，支持点号分隔
     * @param mixed $value 配置值
     */
    public function setConfig(string $key, mixed $value): void
    {
        $keys = explode('.', $key);
        $config = &$this->config;
        $lastIndex = count($keys) - 1;

        foreach ($keys as $i => $k) {
            if ($i === $lastIndex) {
                $config[$k] = $value;
                break;
            }
            if (!isset($config[$k]) || !is_array($config[$k])) {
                $config[$k] = [];
            }
            $config = &$config[$k];
        }

        $this->container->instance('config', $this->config);
    }

    /**
     * 处理异常
     * 
     * 记录异常日志并输出错误响应。
     * 调试模式下向本地 IP 显示详细错误信息。
     * 
     * @param \Throwable $e 异常对象
     */
    private function handleException(\Throwable $e): void
    {
        try {
            $log = $this->container->get('log');
            $log->error($e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
        } catch (\Throwable $logException) {
            error_log('LightPHP: Failed to log exception: ' . $logException->getMessage());
            error_log('LightPHP original error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        }

        if ($e instanceof \core\exception\HttpException) {
            $statusCode = $e->getHttpStatusCode();
            if (!headers_sent()) {
                http_response_code($statusCode);
                header('Content-Type: application/json; charset=utf-8');
                header('X-Content-Type-Options: nosniff');
            }
            echo json_encode([
                'error' => [
                    'code' => $statusCode,
                    'message' => $e->getMessage(),
                ],
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        if ($e instanceof \core\exception\ValidationException) {
            if (!headers_sent()) {
                http_response_code(422);
                header('Content-Type: application/json; charset=utf-8');
                header('X-Content-Type-Options: nosniff');
            }
            echo json_encode([
                'error' => [
                    'code' => 422,
                    'message' => $e->getMessage(),
                    'errors' => $e->getErrors(),
                ],
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: SAMEORIGIN');
        }

        $isDebug = $this->getConfig('app.debug', false);
        $allowedIps = ['127.0.0.1', '::1'];
        // request 解析可能本身抛异常（如容器状态异常），包裹保护避免异常处理器二次崩溃
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        try {
            if ($this->container->has('request')) {
                $req = $this->container->get('request');
                if ($req instanceof Request) {
                    $clientIp = $req->ip();
                }
            }
        } catch (\Throwable $ipException) {
            // 忽略，使用 $_SERVER 回退
        }
        $showTrace = $isDebug && in_array($clientIp, $allowedIps, true);

        $errorView = $this->getConfig('app.error_views.500');
        if ($errorView !== null && defined('VIEW_PATH')) {
            $viewPath = VIEW_PATH . ltrim($errorView, '/') . '.php';
            if (file_exists($viewPath)) {
                try {
                    $obLevel = ob_get_level();
                    ob_start();
                    $exception = $e;
                    $debug = $showTrace;
                    require $viewPath;
                    echo ob_get_clean();
                    return;
                } catch (\Throwable $viewException) {
                    // 仅在确实有我们启动的缓冲层时清理，避免 ob_end_clean 警告
                    while (ob_get_level() > $obLevel) {
                        ob_end_clean();
                    }
                }
            }
        }

        echo '<!DOCTYPE html>' . "\n";
        echo '<html lang="zh-CN">' . "\n";
        echo '<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>服务异常</title>';
        echo '<style>body{font-family:system-ui,-apple-system,sans-serif;max-width:800px;margin:50px auto;padding:20px;background:#f5f5f5;color:#333}h1{color:#d32f2f;font-size:24px}pre{background:#fff;padding:15px;border-radius:4px;overflow-x:auto;font-size:13px;line-height:1.5;border:1px solid #ddd}p{margin:8px 0}</style>';
        echo '</head><body>' . "\n";

        if ($showTrace) {
            echo '<h1>错误: ' . htmlspecialchars($e->getMessage()) . '</h1>';
            echo '<p>文件: ' . htmlspecialchars($e->getFile()) . ' 行: ' . $e->getLine() . '</p>';
            echo '<pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
            echo '</body></html>';
            return;
        }

        echo '<h1>500 - 服务器内部错误</h1>';
        echo '<p>很抱歉，服务器遇到了一个内部错误。请稍后再试。</p>';
        echo '</body></html>';
    }
}
