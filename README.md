<div align="center">

<img src="docs/assets/banner.png" alt="LightPHP" width="280">

# LightPHP

**零依赖 · 高性能 · 生产就绪的现代化 PHP 框架**

[![PHP Version](https://img.shields.io/badge/PHP-8.0%2B-777BB4?style=for-the-badge&logo=php&logoColor=white)](https://www.php.net)
[![License](https://img.shields.io/badge/license-MIT-22c55e?style=for-the-badge)](LICENSE)
[![Tests](https://img.shields.io/badge/tests-1218%2F1218%20passing-06b6d4?style=for-the-badge&logo=checkmarx)](tests/run_tests.php)
[![Zero Dependencies](https://img.shields.io/badge/zero%20dependencies-no%20composer%20required-f97316?style=for-the-badge)](https://github.com/chydroid/lightphp)

> **1218 项测试全部通过**，经过 7 轮系统性审计。
> 本文档如实描述框架的**能力边界**——包括它不擅长什么、以及几处需要你主动留意的陷阱。
> 请先阅读 [使用前必读](#使用前必读) 再动手。

</div>

---

## 定位

LightPHP 是一个面向 PHP 8.0+ 的全栈 MVC 框架，**无需 Composer 即可运行**，自带 IoC 容器、ORM、中间件、事件系统、多驱动缓存与模板引擎。

**适合**：REST API、内部工具、微服务、教学项目、偏好轻量且能读懂全部源码的团队。

**不适合**：需要开箱即用完整后台脚手架、大型团队分工、依赖庞大生态（支付 / IM / 云厂商 SDK）的项目——这类需求请考虑 Laravel 或 Hyperf。

---

## 优势与不足

我们把话说清楚。以下评价基于实测，而非宣传。

### ✅ 优势

| 优势 | 说明 |
|------|------|
| **零依赖** | 只有一个 PHP 运行时 + 扩展即可跑，部署是 `git clone`。没有 `vendor/` 膨胀，没有供应链风险 |
| **源码可读** | 核心约 50 个类，全部可通读。遇到问题能自己定位，不必等上游发版 |
| **PSR-11 容器** | 依赖注入规范，`Container` 可直接替换为其他实现 |
| **安全默认值合理** | 参数化查询、Blade 自动转义、CSRF、AES-256-GCM、bcrypt，且多数缺陷已在 7 轮审计中修复 |
| **扩展点克制** | 服务提供者 + `Macroable` 运行时扩展，不需要为小需求引入整套插件体系 |
| **CLI 完整** | `serve` / `migrate` / `make:*` / `config:cache` / `test` 覆盖日常开发 |

### ⚠️ 不足（请务必了解）

| 不足 | 影响 | 应对 |
|------|------|------|
| **无 Composer 生态** | 无法直接 `composer require` 引入成熟库，支付 / 短信 / 云 SDK 需手写 | 自行封装，或用 REST 调用第三方服务 |
| **查询链返回裸数组** | `Model::where()->first()` **不套用 `$hidden`**，可能泄漏敏感字段 | 见 [使用前必读](#1-敏感字段查询链不套用-hidden) |
| **无内置用户认证** | 只有 `Hash` / `Session` / `CsrfMiddleware`，**没有** login / register / 权限体系 | 自行实现 |
| **无后台脚手架** | 菜单、权限、CRUD 界面都要自己写 | 用 `JsonResource` + `make:controller` 拼装 |
| **ORM 功能有限** | 无嵌套关联写回、无多态关联、无分库分表；预加载需显式调 `withRelation()` | 复杂查询直接用 `QueryBuilder` / `raw()` |
| **测试框架极简** | 自研 runner，无 Mock / 覆盖率 / 并行 | 简单够用；复杂项目建议另配 PHPUnit |
| **升级成本** | `app/core/` 需整体替换，自定义改动会冲突 | 把扩展写在 `app/middleware`、`model\` 或用 `Macroable` |
| **单进程假设** | 无队列 / worker / 定时任务 | 用 CLI 命令 + 系统 cron 兜底 |

---

## 使用前必读

这几条是实测中真实踩到的坑，不是理论风险。

### 1. 敏感字段：查询链不套用 `$hidden`

`$hidden` **只在 Model 实例的 `toArray()` / `toJson()` 上生效**。而 `Model::where(...)` 返回的是 `QueryBuilder`，其 `first()` / `fetchAll()` 产出**裸数组**，完全绕过 `$hidden`：

```php
class User extends \model\Model {
    protected array $hidden = ['password'];
}

// ❌ 危险：裸数组，password 明文会被 json() 吐出去
$rows = User::where('status', 1)->fetchAll();
return $this->json($rows);

// ✅ 安全：visibleOnly() 显式过滤
return $this->json(User::visibleOnly($rows));

// ✅ 更好：hydrate() 转成 Model，行为与 find() 完全一致
$users = User::hydrate(User::where('status', 1)->fetchAll());
return $this->json(array_map(fn($u) => $u->toArray(), $users));
```

实测对照：

```php
User::where('id',1)->first();   // {"id":1,...,"password":"p"}   ← 泄漏
User::find(1)->toArray();       // {"id":1,...}                   ← 已隐藏
User::find(1)->toJson();        // {"id":1,...}                   ← 已隐藏
```

> **原则**：只要数据要离开 PHP，就先过 `toArray()`、`visibleOnly()` 或 `hydrate()`。
> `find()` / `first()` / `firstOrFail()` / `paginate()` 返回的已经是 Model 实例，本身是安全的。

### 2. 无时间戳的表要显式关闭

框架默认在 `create()` / `update()` 时写入 `created_at` 与 `updated_at`。若你的表没有这两列（如配置表、日志表、纯关联表），会直接报错：

```
PDOException: table settings has no column named created_at
```

解决：

```php
class Setting extends \model\Model {
    protected bool $timestamps = false;   // 关闭自动时间戳
}
```

### 3. 生产环境务必配置 `APP_TRUSTED_HOSTS`

`Request::url()` 由 `Host` 请求头拼装。攻击者发一个伪造 Host 的请求，就能让找回密码链接、OAuth 回调指向自己的域名。

框架默认 `trusted_hosts = '*'`（不校验）以保证兼容，**生产环境必须收紧**：

```bash
# .env
APP_URL=https://your-domain.com
APP_TRUSTED_HOSTS=your-domain.com,*.your-domain.com
```

不在白名单内的 Host 会被忽略，`host()` 回落到 `APP_URL`。注意 `*.` **只匹配一层子域**：`a.b.example.com` 不会匹配 `*.example.com`。

### 4. `config:cache` 会锁定配置

执行 `php bin/console config:cache` 后，配置被冻结在缓存文件里。改了 `app/config/` 或 `.env` **不会生效**，必须先 `config:clear`。

### 5. 路由中间件支持三种写法

```php
// 1. 类名
$router->setGlobalMiddleware([\middleware\Cors::class]);

// 2. 别名（可带构造参数，按构造函数签名自动转类型）
$router->aliasMiddleware('throttle', \middleware\Throttle::class);
$router->setGlobalMiddleware(['throttle:60,1']);   // → new Throttle(60, 1)

// 3. 已实例化对象（需要自定义配置时）
$router->setGlobalMiddleware([new \middleware\Cors(['allowed_origins' => ['*']])]);
```

### 6. 关联预加载要用 `withRelation()`

`with()` **不是**批量预加载入口——它只把关联挂在**当前实例**上。拼进查询链后那个实例会被丢弃，关联数据不会进入结果：

```php
// ❌ 无效：author 不会出现在结果里
$posts = Post::with('author')->where('published', 1)->fetchAll();

// ✅ 有效：批量取出并注入每个模型实例（一次查询，无 N+1）
$rows  = (new Post())->where('published', 1)->fetchAll();
$posts = Post::withRelation($rows, 'author', 'belongsTo', 'author_id', 'id');
echo json_encode($posts[0]->toArray());
// {"id":1,...,"author":{"id":1,"name":"Tom"}}
```

在关联模型里声明 `protected ?string $authorModel = Author::class;` 可避免为推断类名而多查一次。

`$type` 可选 `belongsTo` / `hasOne` / `hasMany`。

---

## 快速开始

### 1. 启动项目

```bash
git clone https://github.com/chydroid/lightphp.git
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

// 路由分组 + 中间件
$router->group(['prefix' => '/api', 'middleware' => ['cors']], function($router) {
    $router->get('/users', [UserController::class, 'index']);
});

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
            'version'   => '2.15.9',
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
    protected bool   $timestamps = true;   // 表无 created_at/updated_at 列时设为 false
}
```

```php
// 创建
$user = User::create([
    'name'     => 'Tom',
    'email'    => 'tom@example.com',
    'password' => Hash::make('secret'),   // 框架不自动哈希，需自行 Hash::make()
]);

// 查询（find/first/paginate 返回 Model 实例，$hidden 自动生效）
$user  = User::find(1);
$users = User::paginate(15, 1);   // ['items' => [Model...], 'total' => N, ...]

// 关联预加载（withRelation 是真正生效的批量预加载，避免 N+1）
class Post extends \model\Model
{
    protected string $table = 'posts';
    protected ?string $authorModel = User::class;   // 显式声明关联类，避免额外查询

    public function author()
    {
        return $this->belongsTo(User::class, 'author_id', 'id');
    }
}

$rows  = (new Post())->where('published', 1)->fetchAll();
$posts = Post::withRelation($rows, 'author', 'belongsTo', 'author_id', 'id');

echo json_encode($posts[0]->toArray());
// {"id":1,"author_id":1,"published":1,"author":{"id":1,"name":"Tom"}}
```

> ⚠️ `User::where(...)` 返回的是 `QueryBuilder`，其 `first()` / `fetchAll()` 产出**裸数组**，**不套用 `$hidden`**。
> 查询结果要输出到响应时，请用 `User::visibleOnly($rows)` 或 `User::hydrate($rows)`——详见 [使用前必读](#1-敏感字段查询链不套用-hidden)。
>
> ⚠️ `with()` **不是** 批量预加载入口：它只把关联挂在当前实例上，
> 拼进查询链（`Model::with('author')->where(...)`）不会生效。请用 `withRelation()`。

### 5. 中间件与限流

```php
// 注册中间件别名
$router->aliasMiddleware('cors', \middleware\Cors::class);
$router->aliasMiddleware('throttle', \middleware\Throttle::class);

// 全局中间件
$router->setGlobalMiddleware([
    \middleware\Cors::class,
    \middleware\RequestLogMiddleware::class,
]);

// 路由级限流
$router->group(['prefix' => '/api', 'middleware' => ['throttle']], function($router) {
    $router->get('/users', [UserController::class, 'index']);
});
```

### 6. 事件与缓存

```php
// 事件监听
$events = new \core\EventDispatcher();
$events->listen('user.registered', function ($event, $user) {
    error_log("User registered: {$user['email']}");
});
$events->dispatch('user.registered', ['id' => 1, 'email' => 'tom@example.com']);

// 标签缓存
$cache = \cache\Cache::tags(['users']);
$cache->set('online', 100, 3600);
$cache->increment('online');
$cache->flush(); // 清空 users 标签下所有 key
```

### 7. 使用 Blade 模板

```php
// 渲染视图
$blade = new \view\Blade(VIEW_PATH, STORAGE_PATH . 'views/');
return $blade->render('home', ['name' => 'LightPHP']);
```

```blade
{{-- resources/views/home.blade.php --}}
@extends('layouts.app')

@section('content')
    <h1>Hello, {{ $name }}</h1>
@endsection
```

### 8. 数据验证

```php
$validator = (new \core\Validate())->rules([
    'name'     => 'required|min:2|max:50',
    'email'    => 'required|email',
    'password' => 'required|min:8',
]);

if (!$validator->validate($request->all())) {
    return $this->error($validator->firstError(), 422);
}

$validData = $validator->validated();
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

> 实际表现取决于服务器配置、数据库延迟与业务复杂度。

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

## 生产部署

### 1. 配置缓存

```bash
php bin/console config:cache
```

### 2. OPcache 推荐配置

```ini
opcache.enable=1
opcache.memory_consumption=256
opcache.max_accelerated_files=10000
opcache.revalidate_freq=60
```

### 3. 目录权限

```bash
chmod -R 755 storage/
chown -R www-data:www-data storage/
```

### 4. Nginx 站点配置示例

```nginx
server {
    listen 80;
    server_name yourdomain.com;
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

    location ~ /\.ht {
        deny all;
    }
}
```

### 5. 部署检查清单

| 检查项 | 操作 |
|--------|------|
| `app/config/app.php` → `debug` | 设为 `false` |
| `app/config/app.php` → `key` | 已修改为自定义值 |
| `app/config/database.php` | 数据库信息正确 |
| **`.env` → `APP_TRUSTED_HOSTS`** | **必须设置**，否则 `url()` 可被 Host 头投毒 |
| `storage/` 权限 | Web 服务器有写入权限 |
| Web 根目录 | 指向 `public/` |
| PHP 版本 | ≥ 8.0 |
| 错误显示 | `display_errors = Off` |

### 6. 最小 `.env` 模板

```bash
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:$(php -r "echo base64_encode(random_bytes(32));")
APP_URL=https://your-domain.com

# 防 Host 头投毒（生产必填）
APP_TRUSTED_HOSTS=your-domain.com,*.your-domain.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=your_db
DB_USERNAME=your_user
DB_PASSWORD=your_password
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
| [开发指南](docs/guide.md) | 从零开始构建应用（32 章完整教程） |
| [快速开始](docs/quick-start.md) | 核心功能代码示例速查 |
| [API 参考](docs/api.md) | 类与方法完整参考 |
| [电商教程](docs/ecommerce-full-tutorial.md) | 完整电商系统开发 |
| [后台管理教程](docs/admin-panel-tutorial.md) | 后台管理系统开发 |
| [测试指南](docs/testing-guide.md) | 单元测试编写指南 |
| [更新日志](CHANGELOG.md) | 版本变更记录 |

---

## 安全与质量

LightPHP 内置了完整的安全机制：

- **SQL 注入防护**：所有数据库操作使用 PDO 参数绑定
- **XSS 防护**：Blade 模板 `{{ }}` 自动转义，原生模板提供 `Helper::e()` 辅助函数
- **CSRF 防护**：`CsrfMiddleware` 中间件 + `@csrf` Blade 指令
- **密码安全**：bcrypt 哈希存储（`Hash::make()`）
- **加密解密**：AES-256-GCM 认证加密（`Hash::encrypt()` / `Hash::decrypt()`）
- **路径遍历防护**：文件操作严格校验路径
- **会话安全**：Cookie 支持 `HttpOnly`、`Secure`、`SameSite` 标志

框架通过 1218 项测试保障核心组件稳定性：

```bash
php bin/console test   # 1218/1218 测试通过
```

> 审计发现的历史缺陷与修复记录见 [CHANGELOG.md](CHANGELOG.md)，审计报告见 `.comate/audit*/`。

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
