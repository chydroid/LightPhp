<div align="center">

<img src="docs/assets/banner.png" alt="LightPHP" width="280">

# LightPHP

**零依赖 · 高性能 · 可商用的现代化 PHP 框架**

[![PHP Version](https://img.shields.io/badge/PHP-8.0%2B-777BB4?style=for-the-badge&logo=php&logoColor=white)](https://www.php.net)
[![License](https://img.shields.io/badge/license-MIT-22c55e?style=for-the-badge)](LICENSE)
[![Version](https://img.shields.io/badge/version-2.8.1-8b5cf6?style=for-the-badge)](CHANGELOG.md)
[![Tests](https://img.shields.io/badge/tests-513%2F513%20passing-06b6d4?style=for-the-badge&logo=checkmarx)](tests/run_tests.php)
[![Zero Dependencies](https://img.shields.io/badge/zero%20dependencies-no%20composer%20required-f97316?style=for-the-badge)](https://github.com/chydroid/lightphp)

</div>

---

## 一句话定位

LightPHP 是一个面向 PHP 8.0+ 的轻量级全栈框架，**无需 Composer 即可运行**，自带 IoC 容器、ORM、中间件、事件系统、多驱动缓存与模板引擎，适合构建 API、中小型 Web 应用、微服务以及教学项目。

---

## 为什么选择 LightPHP

| 能力 | 说明 |
|------|------|
| **开箱即用** | 单个 PHP 运行时即可启动，没有第三方依赖，部署只需 `git clone` |
| **生产可用** | PSR-11 容器、参数化查询、中间件管道、配置缓存、日志、缓存驱动齐全 |
| **学习曲线低** | API 参考 Laravel / ThinkPHP，上手成本低，文档完整 |
| **性能优先** | 自定义自动加载、零反射路由缓存、轻量级 Facade 与事件调度 |
| **可扩展** | 服务提供者、Macroable 运行时扩展、自定义控制台命令 |

---

## 核心特性一览

<div align="center">

| 架构 | 数据 | 视图 | 安全 | 工程化 |
|:--|:--|:--|:--|:--|
| MVC 分层 | ORM + QueryBuilder | 原生 PHP / Blade / Smarty | CSRF 中间件 | 零依赖运行 |
| IoC 容器（PSR-11） | Schema / Migration | Blade 自动转义 | XSS 防护 | `make:*` 代码生成 |
| 中间件管道 | 一对一 / 一对多关联 | 布局继承 / 区块 | SQL 注入防护 | 配置缓存 |
| 事件系统（含通配符） | 软删除 / 模型事件 | 模板缓存 | 路径遍历防护 | API 文档自动生成 |
| 服务提供者 | 数据库事务 / Savepoint | 组件化视图 | 会话安全 | 500+ 测试断言 |

</div>

---

## 快速开始

### 1. 启动项目

```bash
git clone https://github.com/yourname/lightphp.git
cd lightphp
php bin/console serve           # http://localhost:8080
php bin/console serve 9000      # 自定义端口
```

### 2. 定义路由

```php
// app/route/web.php
use core\Router;
use controller\IndexController;

$router = new Router();

$router->get('/', [IndexController::class, 'index']);
$router->get('/hello/{name}', fn($name) => "Hello, {$name}!");
$router->post('/users', [UserController::class, 'store']);

return $router;
```

### 3. 编写控制器

```php
namespace controller;

use core\Controller;
use core\Request;
use core\Response;

class IndexController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->json([
            'framework' => 'LightPHP',
            'version'   => '2.8.1',
            'php'       => PHP_VERSION,
        ]);
    }
}
```

### 4. 使用模型

```php
namespace model;

use model\Model;

class User extends Model
{
    protected string $table    = 'users';
    protected array  $fillable = ['name', 'email', 'password'];
    protected array  $hidden   = ['password'];
    protected array  $casts    = ['created_at' => 'datetime'];
}
```

```php
// 创建
$user = User::create([
    'name'     => 'Tom',
    'email'    => 'tom@example.com',
    'password' => Hash::make('secret'),
]);

// 查询
$users = User::where('status', 1)
    ->orderBy('id', 'desc')
    ->paginate(15, 1);

// 关联预加载
$posts = Post::with('author')->where('published', 1)->get();
```

### 5. 中间件与限流

```php
// 注册全局中间件
$router->setGlobalMiddleware([
    \middleware\Cors::class,
    \middleware\RequestLogMiddleware::class,
]);

// 路由级限流
$router->middleware('throttle:60,60')->group([
    'prefix' => '/api',
], function ($router) {
    $router->get('/users', [UserController::class, 'index']);
});
```

### 6. 事件与缓存

```php
// 事件监听
$events = new \core\EventDispatcher();
$events->listen('user.registered', function ($event, $user) {
    // 发送欢迎邮件或写入日志
    error_log("User registered: {$user['email']}");
});
$events->dispatch('user.registered', ['id' => 1, 'email' => 'tom@example.com']);

// 标签缓存
$cache = \cache\Cache::tags(['users']);
$cache->set('online', 100, 3600);
$cache->increment('online');
$cache->flush(); // 清空 users 标签下所有 key
```

---

## 性能参考

在 PHP 8.3 + OPcache 环境下，典型场景基准如下：

| 指标 | 数值 |
|------|------|
| 简单路由请求 | ~2–4 ms |
| 单次数据库查询 + 渲染 | ~8–15 ms |
| 内存占用（单次请求） | ~1.5–3 MB |
| 自动加载文件数 | 核心 50+ 个类 |

>
> 实际表现取决于服务器配置、数据库延迟与业务复杂度。

### 7. 使用 Blade 模板

```php
// resources/views/home.blade.php
@extends('layouts.app')

@section('content')
    <h1>Hello, {{ $name }}</h1>
@endsection
```

```php
return view('home', ['name' => 'LightPHP']);
```

---

## 项目结构

```
lightphp/
├── app/
│   ├── cache/           # 缓存驱动：File / Redis / Memcached / Tagged
│   ├── config/          # 应用配置
│   ├── controller/      # 控制器
│   ├── core/            # 框架核心（不可修改）
│   │   ├── console/     #   CLI 命令
│   │   ├── contract/    #   接口契约
│   │   ├── exception/   #   异常类
│   │   └── traits/      #   复用 trait
│   ├── db/              # Connection / QueryBuilder / Schema / Migration
│   ├── log/             # 日志
│   ├── middleware/      # 中间件
│   ├── model/           # 模型
│   ├── route/           # 路由定义
│   ├── traits/          # 业务 trait（SoftDelete / HasModelEvents）
│   └── view/            # 视图引擎
├── bin/
│   └── console          # CLI 入口
├── database/
│   └── migrations/      # 迁移文件
├── docs/                # 文档与资源
├── public/              # Web 入口
├── storage/             # 缓存 / 日志 / Session（需可写）
└── tests/               # 单元测试
```

### 使用约定

- 业务代码放在 `app/controller/`、`app/model/`、`app/route/`、`app/middleware/` 中
- `app/core/` 是框架核心，升级时直接替换即可
- Web 服务器根目录指向 `public/`
- `storage/` 目录必须对 Web 进程可写

---

## 生产部署建议

1. **配置缓存**
   ```bash
   php bin/console config:cache
   ```

2. **OPcache 推荐配置**
   ```ini
   opcache.enable=1
   opcache.memory_consumption=256
   opcache.max_accelerated_files=10000
   opcache.revalidate_freq=60
   ```

3. **目录权限**
   ```bash
   chmod -R 755 storage/
   chown -R www-data:www-data storage/
   ```

4. **Nginx 站点配置示例**
   ```nginx
   server {
       listen 80;
       root /var/www/lightphp/public;
       index index.php;

       location / {
           try_files $uri $uri/ /index.php?$query_string;
       }

       location ~ \.php$ {
           fastcgi_pass unix:/run/php/php8.3-fpm.sock;
           fastcgi_index index.php;
           fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
           include fastcgi_params;
       }
   }
   ```

---

## CLI 命令一览

```bash
php bin/console serve [port]            # 启动开发服务器
php bin/console test                    # 运行单元测试
php bin/console config                  # 查看配置概览
php bin/console config:show <section>   # 查看指定节配置
php bin/console config:cache            # 生成配置缓存
php bin/console config:clear            # 清除配置缓存
php bin/console cache:clear             # 清空应用缓存
php bin/console migrate                 # 执行数据库迁移
php bin/console migrate:rollback [n]    # 回滚最近 n 次迁移
php bin/console make:model <Name>       # 生成模型
php bin/console make:controller <Name>  # 生成控制器
php bin/console make:middleware <Name>  # 生成中间件
php bin/console make:migration <Name>   # 生成迁移文件
```

---

## 文档

| 文档 | 说明 |
|------|------|
| [开发指南](docs/guide.md) | 从零开始构建应用 |
| [快速开始](docs/quick-start.md) | 5 分钟核心概念 |
| [API 参考](docs/api.md) | 类与方法完整参考 |
| [电商教程](docs/ecommerce-full-tutorial.md) | 完整电商系统开发 |
| [后台管理教程](docs/admin-panel-tutorial.md) | 后台管理系统开发 |
| [更新日志](CHANGELOG.md) | 版本变更记录 |

---

## 安全与质量

LightPHP 内置了常见的安全机制：参数化查询、CSRF 防护、Blade 自动转义、路径遍历校验、会话 Cookie 安全标志等。框架通过持续代码审查与 500+ 测试断言保障核心组件稳定性。

```bash
php bin/console test   # 513/513 测试通过
```

---

## 参与贡献

欢迎提交 Issue 和 PR。请遵循：

1. 所有 PHP 文件保持 `declare(strict_types=1);`
2. 新增功能请附单元测试
3. 公共方法需有 PHPDoc 与类型声明
4. 遵循 PSR-12 编码规范

---

## License

[MIT License](LICENSE) © LightPHP Contributors
