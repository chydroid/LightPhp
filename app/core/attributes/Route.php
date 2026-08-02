<?php
declare(strict_types=1);

namespace core\attributes;

/**
 * PHP 8 Attribute 路由注解
 *
 * 方法级：声明控制器方法对应的路由
 *   #[Route('/users', method: 'GET', name: 'users.index')]
 *
 * 类级：声明该控制器所有路由的公共前缀与中间件
 *   #[Route(prefix: '/api', middleware: ['cors'])]
 *
 * 与 route 文件（app/route/*.php）定义的路由共存，由
 * Router::registerController() 扫描注册。
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
class Route
{
    public function __construct(
        public string $path = '',
        public string $method = 'GET',
        public ?string $name = null,
        public ?string $prefix = null,
        public array $middleware = [],
    ) {
    }
}

/** GET 路由语法糖 */
#[\Attribute(\Attribute::TARGET_METHOD)]
class Get extends Route
{
    public function __construct(string $path = '', ?string $name = null)
    {
        parent::__construct($path, 'GET', $name);
    }
}

/** POST 路由语法糖 */
#[\Attribute(\Attribute::TARGET_METHOD)]
class Post extends Route
{
    public function __construct(string $path = '', ?string $name = null)
    {
        parent::__construct($path, 'POST', $name);
    }
}

/** PUT 路由语法糖 */
#[\Attribute(\Attribute::TARGET_METHOD)]
class Put extends Route
{
    public function __construct(string $path = '', ?string $name = null)
    {
        parent::__construct($path, 'PUT', $name);
    }
}

/** PATCH 路由语法糖 */
#[\Attribute(\Attribute::TARGET_METHOD)]
class Patch extends Route
{
    public function __construct(string $path = '', ?string $name = null)
    {
        parent::__construct($path, 'PATCH', $name);
    }
}

/** DELETE 路由语法糖 */
#[\Attribute(\Attribute::TARGET_METHOD)]
class Delete extends Route
{
    public function __construct(string $path = '', ?string $name = null)
    {
        parent::__construct($path, 'DELETE', $name);
    }
}

/** OPTIONS 路由语法糖 */
#[\Attribute(\Attribute::TARGET_METHOD)]
class Options extends Route
{
    public function __construct(string $path = '', ?string $name = null)
    {
        parent::__construct($path, 'OPTIONS', $name);
    }
}
