
# LightPHP 框架开发指南

欢迎阅读 LightPHP 框架的完整开发指南！本指南将从零开始，带你系统地学习框架的每一个功能模块。无论你是 PHP 初学者还是经验丰富的开发者，都能在本指南中找到你需要的内容。

> 💡 **阅读建议**：如果你是第一次使用 LightPHP，建议按顺序从第 1 章读到第 6 章，掌握框架的基本用法。之后可以根据实际需求跳转到感兴趣的部分。

---

## 目录

1. [概述](#1-概述)
2. [配置管理](#2-配置管理)
3. [目录结构](#3-目录结构)
4. [路由系统](#4-路由系统)
5. [控制器](#5-控制器)
6. [模型与数据库](#6-模型与数据库)
7. [视图与模板](#7-视图与模板)
8. [中间件](#8-中间件)
9. [请求与响应](#9-请求与响应)
10. [验证器](#10-验证器)
11. [缓存](#11-缓存)
12. [日志](#12-日志)
13. [依赖注入容器](#13-依赖注入容器)
14. [配置管理深入](#14-配置管理深入)
15. [Cookie](#15-cookie)
16. [加密哈希](#16-加密哈希)
17. [CSRF 防护](#17-csrf-防护)
18. [异常处理](#18-异常处理)
19. [接口契约](#19-接口契约)
20. [配置](#20-配置)
21. [最佳实践](#21-最佳实践)
22. [ORM 关联关系](#22-orm-关联关系)
23. [事件系统](#23-事件系统)
24. [集合类](#24-集合类)
25. [Blade 模板引擎](#25-blade-模板引擎)
26. [Schema Builder 与数据库迁移](#26-schema-builder-与数据库迁移)
27. [CLI 命令系统](#27-cli-命令系统)
28. [Facade 与 ServiceProvider](#28-facade-与-serviceprovider)
29. [Smarty 模板引擎（可选扩展）](#29-smarty-模板引擎可选扩展)
30. [部署到生产环境](#30-部署到生产环境)
31. [Pipeline 管道（v2.0 新增）](#31-pipeline-管道v20-新增)
32. [Seeder 数据填充（v2.0 新增）](#32-seeder-数据填充v20-新增)

---

## 1. 概述

### 1.1 框架简介

LightPHP 是一个轻量级的 PHP 框架，灵感来自 ThinkPHP 和 Laravel。它的设计目标是：**在保持核心代码精简的同时，提供生产环境所需的完整功能**。

> **核心理念**：对于中小型项目，你不需要引入庞大的 Laravel；对于初学者，你不需要学习复杂的 ThinkPHP。LightPHP 提供了两者中最精华的部分，用最少的代码实现最多的功能。

### 1.2 框架的设计哲学

LightPHP 遵循以下设计原则：

- **轻量优先**：核心代码不到 30 个类，但不牺牲功能完整性
- **渐进增强**：按需使用功能，不需要的模块完全不会成为负担
- **显式优于隐式**：代码逻辑清晰可读，减少"魔法"操作
- **安全默认**：框架内置了 SQL 注入防护、XSS 防护、CSRF 防护、路径遍历防护等安全措施
- **零依赖运行**：核心功能（路由、数据库、模板、缓存、验证等）全部自实现，无需 Composer 即可运行

### 1.3 主要特性一览

| 类别 | 特性 |
|------|------|
| **架构** | MVC 分层、依赖注入容器（PSR-11）、洋葱模型中间件、服务提供者 |
| **路由** | RESTful 风格、路由分组、参数绑定、正则约束、中间件链 |
| **数据库** | QueryBuilder（参数绑定防注入）、ActiveRecord 模型、Schema Builder、数据库迁移 |
| **ORM** | hasOne/hasMany/belongsTo/belongsToMany 关联、预加载、类型转换 |
| **模板** | 原生 PHP + Blade 风格编译器（缓存）+ Smarty（可选） |
| **安全** | CSRF、CORS、Throttle 限流、AES-256-GCM 加密、bcrypt 哈希 |
| **v2.0 新增** | Pipeline 洋葱管道、Macroable 宏扩展、模型事件/观察者、软删除、访问器/修改器、查询作用域、Seeder 数据填充、中间件别名/组/全局注册、Request 类型过滤、ExceptionHandler report/render 分离 |
| **辅助** | 事件系统、集合类（40+ 方法）、门面模式、CLI 命令系统 |
| **日志** | PSR-3 兼容（8 个日志级别）、日期分割 |

### 1.4 系统要求

| 项目 | 最低版本 | 推荐版本 |
|------|---------|---------|
| PHP | 8.0 | 8.2+ |
| MySQL（如需数据库） | 5.7 | 8.0+ |
| 必需扩展 | PDO、PDO_MySQL、JSON、mbstring | — |
| 可选扩展 | GD（验证码）、OpenSSL（加密） | — |

> 💡 **不需要 Composer 也能运行**：框架自带自动加载器（`app/core/Loader.php`），核心功能全部自实现，不依赖任何第三方包。只有使用 Smarty 模板时才需要通过 Composer 额外安装。

---

## 2. 配置管理

LightPHP 使用 `app/config/` 下的 PHP 配置文件来管理所有配置。这种方式的优势是：

- **分类清晰**：一个文件处理一类配置（数据库、应用、缓存等），不会混在一起
- **支持语法高亮**：PHP 文件本身有 IDE 支持，不易写错
- **支持注释**：可以在配置中添加说明注释
- **类型安全**：PHP 数组原生支持各种类型，不会出现"字符串 `true`"还是"布尔 `true`"的困惑

### 2.1 应用配置 `app/config/app.php`

```php
<?php
return [
    'name'     => 'LightPHP',             // 应用名称，用于页面标题等
    'env'      => 'production',            // 环境标识：production / development
    'debug'    => false,                   // ⚠️ 开发环境务必设为 true，生产环境设为 false
    'key'      => 'base64:your-random-32-char-string',  // ⚠️ 必须修改！用于加密和 CSRF
    'timezone' => 'Asia/Shanghai',         // 时区设置
    'charset'  => 'utf-8',                 // 字符编码
];
```

> ⚠️ **重要提醒**：
> - `debug = true` 时会显示详细的错误信息（开发用）；生产环境务必设为 `false`
> - `key` 必须修改为你的自定义值，这是加密功能的基础密钥。生成方式：`php -r "echo base64_encode(random_bytes(32));"`

### 2.2 数据库配置 `app/config/database.php`

```php
<?php
return [
    'default' => 'mysql',                  // 默认数据库连接（对应下面的连接名）
    'connections' => [
        'mysql' => [
            'host'     => '127.0.0.1',     // 数据库主机地址
            'port'     => 3306,            // 端口号
            'database' => 'lightphp',      // ⚠️ 数据库名称
            'username' => 'root',          // ⚠️ 数据库用户名
            'password' => '',              // ⚠️ 数据库密码
            'charset'  => 'utf8mb4',       // 字符集（utf8mb4 支持 emoji）
        ],
    ],
    'prefix' => '',                        // 表前缀（如 'wp_' 则 users → wp_users）
];
```

> 💡 **多数据库连接**：你可以在 `connections` 中添加更多连接（如 `'sqlite' => [...]`），然后通过 `default` 切换或者运行时指定。

### 2.3 目录权限

框架运行需要在 `storage/` 目录下创建缓存和日志文件，请确保该目录有写入权限：

```bash
# Linux / macOS
chmod -R 755 storage/

# Windows — 通常 IIS/Apache 已有写入权限，无需额外操作
```

### 2.4 启动开发服务器

```bash
# 方式一：使用框架内置的 CLI 命令（推荐）
php bin/console serve

# 方式二：指定端口号
php bin/console serve 3000

# 方式三：原生 PHP 内置服务器
cd public
php -S localhost:8080
```

> 💡 `php bin/console serve` 命令相比原生 `php -S` 的优势：
> - 自动从 `public/` 目录启动，无需手动 `cd`
> - 支持 `--host` 参数绑定不同 IP（如 `--host 0.0.0.0` 允许局域网访问）
> - 端口号范围校验

### 2.5 Nginx 生产环境配置

```nginx
server {
    listen 80;
    server_name yourdomain.com;
    root /path/to/your/project/public;  # ⚠️ 根目录指向 public/
    index index.php;

    # 如果请求的不是真实存在的文件，转发给 index.php
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # PHP 请求交给 PHP-FPM 处理
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php-fpm.sock;  # 或 127.0.0.1:9000
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # 禁止访问隐藏文件
    location ~ /\.ht {
        deny all;
    }
}
```

> ⚠️ **部署关键点**：
> 1. `root` 必须指向 `public/` 目录，而不是项目根目录
> 2. 隐藏 `app/`、`storage/` 等敏感目录不会暴露
> 3. `storage/` 目录需要 PHP-FPM 进程有写入权限

---

## 3. 目录结构

```
project/
├── app/                       # 应用核心（你写代码的主要目录）
│   ├── cache/                 # 缓存驱动类
│   ├── config/                # 配置文件（app.php、database.php 等）
│   ├── controller/            # 控制器 — 处理请求逻辑
│   ├── core/                  # 框架核心类（‼️ 一般不要修改）
│   │   ├── console/           # CLI 命令系统
│   │   ├── contract/          # 接口契约（PSR-3 / PSR-11 等）
│   │   ├── exception/         # 异常层次
│   │   ├── traits/            # 可复用 Trait（Macroable 宏扩展等）
│   │   └── helpers.php        # 全局辅助函数
│   ├── db/                    # 数据库层（Connection + QueryBuilder + Schema）
│   ├── log/                   # 日志驱动
│   ├── middleware/             # 中间件（CORS、Throttle、CSRF 等）
│   ├── model/                 # 模型（ActiveRecord + ORM 关联）
│   ├── route/                 # 路由定义文件
│   ├── traits/                # Trait（代码复用）
│   └── view/                  # 视图模板文件（.php / .blade.php）
│
├── bin/                       # CLI 入口
│   └── console                # 命令行调度器
│
├── database/
│   └── migrations/            # 数据库迁移文件
│
├── public/                    # Web 服务器入口（index.php）
│
├── storage/                   # 运行时存储
│   ├── cache/                 # 文件缓存
│   ├── log/                   # 日志（按日期分割）
│   ├── upload/                # 上传文件
│   ├── views/                 # Blade 模板编译缓存
│   └── temp/                  # 临时文件
│
├── tests/                     # 单元测试
├── docs/                      # 开发文档
└── README.md
```

### 3.1 各目录用途速查

| 目录 | 用途 | 需要修改? |
|------|------|----------|
| `app/controller/` | 放置控制器类，处理请求 | ✅ 你的代码 |
| `app/model/` | 放置模型类，映射数据表 | ✅ 你的代码 |
| `app/view/` | 放置视图模板文件 | ✅ 你的代码 |
| `app/route/` | 放置路由定义 | ✅ 你的代码 |
| `app/middleware/` | 放置自定义中间件 | ✅ 你的代码 |
| `app/config/` | 配置文件（数据库等） | ✅ 需要配置 |
| `app/core/` | 框架核心类 | ❌ 一般不改 |
| `public/` | Web 服务器入口 | ❌ 不要改 |
| `storage/` | 缓存/日志/上传 | ❌ 需要写权限 |
| `tests/` | 单元测试 | ✅ 可选 |

---

## 4. 路由系统

路由是 Web 应用的门面——它决定了"访问某个 URL 时，执行哪段代码"。

> 💡 **路由的核心**：每个路由定义了 HTTP 方法（GET/POST 等）+ URL 路径 + 处理函数的三者对应关系。

### 4.1 创建路由文件

在 `app/route/` 目录下创建路由文件，框架启动时会自动加载：

```php
<?php
use core\Router;

$router = new Router();

// 定义首页路由：访问 / 时返回欢迎页面
$router->get('/', function() {
    return new \core\Response('<h1>Welcome to LightPHP!</h1>');
});

return $router;
```

### 4.2 HTTP 方法路由

LightPHP 支持标准的 RESTful HTTP 方法：

```php
// GET — 获取数据（列表/详情）
$router->get('/users', [UserController::class, 'index']);

// POST — 创建数据（新增）
$router->post('/users', [UserController::class, 'store']);

// PUT — 完整更新数据（替换）
$router->put('/users/{id}', [UserController::class, 'update']);

// DELETE — 删除数据
$router->delete('/users/{id}', [UserController::class, 'destroy']);

// PATCH — 部分更新数据（修改部分字段）
$router->patch('/users/{id}', [UserController::class, 'patch']);

// OPTIONS — 获取接口选项
$router->options('/api', [ApiController::class, 'options']);

// ANY — 匹配任意 HTTP 方法
$router->any('/callback', function() {
    // 处理 webhook 回调，不关心请求方法
});
```

> 💡 **方法的语义**：在实际开发中，最常用的是 GET（查）、POST（增）、PUT（改）、DELETE（删），正好对应 CRUD 四个操作。

### 4.3 路由参数

路由参数让你可以在 URL 中捕获动态值：

#### 必需参数

```php
// 访问 /user/123 时，$id 自动填充为 123
$router->get('/user/{id}', function($id) {
    return "User ID: $id";
});

// 多个参数
$router->get('/post/{year}/{month}', function($year, $month) {
    return "Archive: $year-$month";
});
```

#### 可选参数（`?` 后缀）

```php
// /hello → "Hello, Guest!"
// /hello/John → "Hello, John!"
$router->get('/hello/{name?}', function($name = 'Guest') {
    return "Hello, $name!";
});
```

#### 正则约束参数

```php
// id 必须是纯数字
$router->get('/user/{id:\d+}', function($id) {
    return "User ID: $id";
});

// name 必须全是字母
$router->get('/user/{name:[a-zA-Z]+}', function($name) {
    return "User Name: $name";
});

// slug 符合 URL 友好格式
$router->get('/article/{slug:[a-z0-9\-]+}', function($slug) {
    return "Article: $slug";
});
```

> 💡 **正则约束的作用**：避免 `/user/123` 和 `/user/create` 产生路由冲突。通过 `\d+` 约束 id 必须是数字，`create` 就不会被误匹配。

### 4.4 路由分组

当一组路由共享相同的前缀或中间件时，使用分组减少重复代码：

```php
// 【URL 前缀分组】— 所有子路由自动加上 /api/v1 前缀
$router->group(['prefix' => '/api/v1'], function($router) {
    $router->get('/users', [UserController::class, 'index']);   // → /api/v1/users
    $router->get('/posts', [PostController::class, 'index']);   // → /api/v1/posts
});

// 【中间件分组】— 所有子路由自动经过指定的中间件
$router->group(['middleware' => [\middleware\Auth::class]], function($router) {
    $router->get('/profile', [UserController::class, 'profile']);   // 需要登录
    $router->post('/settings', [UserController::class, 'settings']); // 需要登录
});

// 【组合分组】— 同时设置前缀和中间件
$router->group([
    'prefix' => '/admin',
    'middleware' => [\middleware\Auth::class, \middleware\Admin::class]
], function($router) {
    $router->get('/dashboard', [AdminController::class, 'dashboard']);
    $router->get('/users', [AdminController::class, 'users']);
});
```

### 4.5 完整路由示例

下面是一个实际项目中的路由文件范例：

```php
<?php

use core\Router;
use controller\IndexController;
use controller\UserController;
use controller\PostController;
use controller\AdminController;

$router = new Router();

// ===== 公开页面（不需要登录） =====
$router->get('/', [IndexController::class, 'index']);
$router->get('/about', [IndexController::class, 'about']);

// ===== API 路由（RESTful 风格） =====
$router->group(['prefix' => '/api'], function($router) {
    // 用户 API
    $router->group(['prefix' => '/users'], function($router) {
        $router->get('/', [UserController::class, 'index']);          // GET    /api/users
        $router->post('/', [UserController::class, 'store']);        // POST   /api/users
        $router->get('/{id:\d+}', [UserController::class, 'show']);  // GET    /api/users/1
        $router->put('/{id:\d+}', [UserController::class, 'update']);// PUT    /api/users/1
        $router->delete('/{id:\d+}', [UserController::class, 'destroy']); // DELETE /api/users/1
    });

    // 文章 API
    $router->group(['prefix' => '/posts'], function($router) {
        $router->get('/', [PostController::class, 'index']);
        $router->post('/', [PostController::class, 'store']);
    });
});

// ===== 管理后台（需要登录 + 管理员身份） =====
$router->group([
    'prefix' => '/admin',
    'middleware' => [\middleware\Auth::class]
], function($router) {
    $router->get('/dashboard', [AdminController::class, 'dashboard']);
});

return $router;
```

---

## 5. 控制器

控制器是处理请求的核心组件。LightPHP 的控制器继承 `core\Controller` 基类，获得了一系列便捷方法。

> 💡 **控制器的职责**：接收请求参数 → 调用业务逻辑（模型/服务）→ 返回响应（JSON / HTML / 重定向）。控制器应该保持"薄"——只做参数传递和响应返回，真正的业务逻辑交给模型或服务层。

### 5.1 创建一个控制器

```php
<?php
declare(strict_types=1);

namespace controller;

use core\Controller;
use core\Request;
use model\Article;

class ArticleController extends Controller
{
    // 【列表】获取所有文章
    public function index(): \core\Response
    {
        $articles = Article::orderBy('id', 'DESC')->limit(20)->fetchAll();
        return $this->json(['articles' => $articles]);
    }

    // 【详情】根据 ID 获取单篇文章
    public function show(int $id): \core\Response
    {
        $article = Article::find($id);
        if (!$article) {
            return $this->error('文章不存在', 404);
        }
        return $this->json(['article' => $article->toArray()]);
    }

    // 【创建】新增文章
    public function store(Request $request): \core\Response
    {
        // 白名单取参 — 只允许 title 和 content 字段
        $data = $request->only(['title', 'content']);

        if (empty($data['title'])) {
            return $this->error('标题不能为空', 422);
        }

        $article = Article::create($data);
        return $this->success($article, '文章创建成功');
    }

    // 【更新】修改文章
    public function update(int $id, Request $request): \core\Response
    {
        $article = Article::find($id);
        if (!$article) {
            return $this->error('文章不存在', 404);
        }

        $article->title = $request->input('title');
        $article->content = $request->input('content');
        $article->save();

        return $this->success($article, '文章更新成功');
    }

    // 【删除】移除文章
    public function destroy(int $id): \core\Response
    {
        Article::deleteById($id);
        return $this->success(['id' => $id], '文章删除成功');
    }
}
```

### 5.2 `notFound()` 方法

控制器基类提供了 `notFound()` 快捷方法，返回一个 404 响应：

```php
return $this->notFound('文章不存在');
// 等价于：return Response::make('<h1>文章不存在</h1>', 404);
```

### 5.3 控制器基类提供的响应方法

| 方法 | 用途 | 返回值示例 |
|------|------|-----------|
| `$this->view($tpl, $data)` | 返回 HTML 视图 | `<html>...</html>` |
| `$this->json($data, $code)` | 返回 JSON | `{"key":"value"}` |
| `$this->success($data, $msg)` | 返回成功 JSON | `{"code":0,"data":...,"msg":"ok"}` |
| `$this->error($msg, $code, $errors)` | 返回错误 JSON | `{"code":422,"msg":"验证失败",...}` |
| `$this->redirect($url, $code)` | 重定向 | 302 跳转 |

### 5.4 依赖注入

LightPHP 支持构造方法注入，让控制器可以方便地获取所需的服务：

```php
<?php
declare(strict_types=1);

namespace controller;

use core\Controller;
use model\User;

class UserController extends Controller
{
    private User $userModel;

    // 通过构造方法注入 User 模型
    public function __construct(User $userModel)
    {
        $this->userModel = $userModel;
    }

    public function index(): \core\Response
    {
        $users = $this->userModel->all();
        return $this->json(['users' => $users]);
    }
}
```

---

## 6. 模型与数据库

LightPHP 提供了两种数据库交互方式：

1. **QueryBuilder（查询构建器）** — 链式调用构建 SQL，全参数绑定防注入
2. **ActiveRecord 模型（ORM）** — 对象关系映射，把数据表行映射为 PHP 对象

> 💡 **用哪个？** 如果你习惯面向对象操作数据库，用模型；如果需要复杂查询（JOIN、子查询等），用 QueryBuilder。两者可以无缝配合使用。

### 6.1 创建模型

```php
<?php
declare(strict_types=1);

namespace model;

class Post extends Model
{
    // 对应的数据表名
    protected string $table = 'posts';

    // 主键字段名（默认是 id）
    protected string $primaryKey = 'id';

    // 【白名单】— 只有这里列出的字段能通过 create() 批量写入
    // 这是防止"批量赋值漏洞"的安全措施
    protected array $fillable = [
        'title',
        'content',
        'category_id',
        'user_id',
        'status',
    ];

    // 【类型转换】— 取值时自动转为对应类型
    protected array $casts = [
        'category_id' => 'int',       // 整数
        'user_id'     => 'int',
        'status'      => 'int',
        'created_at'  => 'datetime',  // DateTime 对象
        'updated_at'  => 'datetime',
    ];

    // 【隐藏字段】— toArray()/toJson() 输出时自动去掉
    // 用于保护密码等敏感信息不泄露到 API 响应中
    protected array $hidden = [
        'password',
    ];
}
```

### 6.2 模型属性详解

| 属性 | 作用 | 默认值 |
|------|------|--------|
| `$table` | 指定数据表名 | 类名的蛇形复数 |
| `$primaryKey` | 主键字段名 | `'id'` |
| `$fillable` | 允许批量赋值的字段（白名单） | `[]`（不允许） |
| `$hidden` | JSON 序列化时隐藏的字段 | `[]` |
| `$casts` | 字段类型转换映射 | `[]` |
| `$dateFormat` | 日期格式化字符串 | `'Y-m-d H:i:s'` |

### 6.3 支持的字段类型转换

| 类型别名 | 转换效果 | 示例 |
|---------|---------|------|
| `int` / `integer` | 转为整数 | `"123"` → `123` |
| `float` / `double` | 转为浮点数 | `"99.9"` → `99.9` |
| `bool` / `boolean` | 转为布尔值 | `1` → `true`, `0` → `false` |
| `array` | JSON 字符串转 PHP 数组 | `'["a","b"]'` → `['a','b']` |
| `json` | PHP 数组转 JSON 字符串 | `['a','b']` → `'["a","b"]'` |
| `date` | 转为日期字符串（Y-m-d） | — |
| `datetime` | 转为日期时间字符串（Y-m-d H:i:s） | — |

### 6.4 模型基础 CRUD 操作

```php
// ===== 查询 =====

// 获取全部
$posts = Post::all();

// 根据主键查找
$post = Post::find(1);

// 条件查询
$posts = Post::where('status', '=', 1)
    ->orderBy('created_at', 'DESC')
    ->limit(10)
    ->fetchAll();

// 获取第一条符合条件的记录
$post = Post::where('status', '=', 1)->first();

// ===== 创建 =====
$id = Post::create([
    'title'    => '新文章',
    'content'  => '文章内容...',
    'user_id'  => 1,
    'status'   => 1,
]);
// 返回新插入记录的自增 ID

// ===== 更新 =====
// 方式一：获取模型，修改属性，调用 save()
$post = Post::find(1);
$post->title = '修改后的标题';
$post->save();

// 方式二：通过条件批量更新
Post::where('status', '=', 0)->update(['status' => 1]);

// ===== 删除 =====
// 方式一：根据主键删除
Post::deleteById(1);

// 方式二：通过条件批量删除
Post::where('status', '=', -1)->delete();

// ===== 属性访问 =====
$post = Post::find(1);
echo $post->title;           // 魔术方法访问
echo $post->status;          // 自动类型转换（int）
$post->setAttribute('title', '新标题');  // 手动设置

// ===== 模型输出 =====
$array = $post->toArray();   // 数组，hidden 字段已过滤，casts 已应用
$json  = $post->toJson();    // JSON 字符串
```

> ⚠️ **关于 `$fillable`**：如果 `$fillable` 为空数组，`create()` 无法写入任何字段。这是框架的安全机制——你必须显式声明哪些字段可以批量写入。

### 6.5 访问器与修改器（v2.0 新增）

访问器和修改器让你在读取或写入模型属性时自动执行转换逻辑，无需在控制器中手动处理。

#### 访问器（Getter）

在模型中定义 `getFooAttribute()` 方法，读取 `$model->foo` 时自动调用：

```php
class Product extends Model
{
    // 读取 $product->images 时自动将 JSON 字符串转为数组
    public function getImagesAttribute($value): array
    {
        return json_decode($value ?? '[]', true) ?: [];
    }

    // 读取 $product->price 时自动格式化为两位小数
    public function getPriceAttribute($value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}

$product = Product::find(1);
$images = $product->images;  // 返回数组，而非 JSON 字符串
$price  = $product->price;   // 返回 "99.00" 格式化字符串
```

#### 修改器（Setter）

在模型中定义 `setFooAttribute()` 方法，设置 `$model->foo = value` 时自动调用：

```php
class User extends Model
{
    // 设置密码时自动哈希加密
    public function setPasswordAttribute(string $value): void
    {
        $this->attributes['password'] = \core\Hash::make($value);
    }
}

$user = new User();
$user->password = 'plain_text';  // 自动哈希，$this->attributes['password'] 已是加密值
```

> 💡 **命名规则**：属性名 `foo_bar` 对应方法名 `getFooBarAttribute()` / `setFooBarAttribute()`（驼峰转换）。

### 6.6 查询作用域（v2.0 新增）

查询作用域让你将常用的查询条件封装为模型方法，避免在控制器中重复编写相同的 WHERE 条件。

```php
class Post extends Model
{
    // 定义作用域：方法名以 scope 开头，参数自动注入 QueryBuilder
    public function scopePublished($query)
    {
        return $query->where('status', '=', 1);
    }

    public function scopeRecent($query)
    {
        return $query->orderBy('created_at', 'DESC');
    }

    // 带参数的作用域
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', '=', $type);
    }
}

// 使用：调用时省略 scope 前缀
$posts = Post::published()->recent()->fetchAll();
$articles = Post::ofType('article')->fetchAll();
```

> 💡 **原理**：模型的 `__call()` 和 `__callStatic()` 魔术方法在找不到对应方法时，会自动查找 `scopeXxx()` 方法，并将 QueryBuilder 实例作为第一个参数注入。

### 6.7 模型事件与观察者（v2.0 新增）

模型事件允许你在模型的增删改操作前后执行自定义逻辑，实现解耦。

#### 支持的事件

| 事件 | 触发时机 | 典型用途 |
|------|---------|---------|
| `creating` | 创建前 | 自动填充默认值 |
| `created` | 创建后 | 发送通知、记录日志 |
| `updating` | 更新前 | 数据变更审计 |
| `updated` | 更新后 | 清除缓存 |
| `saving` | 保存前（创建或更新） | 统一数据校验 |
| `saved` | 保存后 | 统一后置处理 |
| `deleting` | 删除前 | 检查是否可删除 |
| `deleted` | 删除后 | 清理关联数据 |

#### 注册事件监听

```php
use model\User;

// 方式一：闭包监听
User::onEvent('creating', function($user) {
    // 监听器内可通过魔术属性或 setAttribute 修改即将写入的字段
    $user->created_at = date('Y-m-d H:i:s');
});

User::onEvent('deleted', function($user) {
    // 用户删除后清理关联数据
    \model\UserProfile::where('user_id', '=', $user->id)->delete();
});

// 方式二：观察者类（推荐，逻辑更集中）
class UserObserver
{
    public function creating($user) { /* 创建前 */ }
    public function created($user)  { /* 创建后：发送欢迎邮件 */ }
    public function updating($user) { /* 更新前 */ }
    public function deleting($user) { /* 删除前：检查关联 */ }
    public function deleted($user)  { /* 删除后：清理数据 */ }
}

User::observe(new UserObserver());
```

> 💡 **事件返回 `false` 可阻止操作**：如果在 `creating`/`updating`/`saving`/`deleting` 事件中返回 `false`，对应的操作将被取消。

### 6.8 软删除（v2.0 新增）

软删除不会真正从数据库删除记录，而是将 `deleted_at` 字段设为当前时间。查询时自动排除已软删除的记录。

> ⚠️ **重要**：软删除方法是**实例方法**而非静态方法。需要先 `new Post()` 或 `Post::find(1)` 获取实例再调用。

```php
use traits\SoftDelete;

class Post extends Model
{
    use SoftDelete;

    // 默认使用 deleted_at 字段，可在模型中覆盖：
    // protected string $deletedAtColumn = 'deleted_at';
}

// 软删除：设置 deleted_at 而非真正删除
$post = new Post();
$post->delete(1);     // deleted_at 被设为当前时间

// 查询时自动排除软删除记录
$posts = Post::all(); // 不含 deleted_at IS NOT NULL

// 包含软删除记录
$allPosts = Post::withTrashed()->fetchAll();

// 仅查询软删除记录
$trashed = Post::onlyTrashed()->fetchAll();

// 恢复软删除
$post = Post::find(1);
if ($post && $post->trashed()) {
    $post->restore(); // deleted_at 设为 null
}

// 强制物理删除（绕过 deleted_at 检查）
(new Post())->force()->delete(1);  // 真正从数据库删除

// 检查是否已被软删除
if ($post->trashed()) {
    echo '该记录已被软删除';
}
```

> ⚠️ **数据库要求**：使用软删除的表必须有 `deleted_at` 字段（DATETIME 类型，默认 NULL）。

### 6.9 查询构建器（QueryBuilder）

当你需要更灵活的数据库操作时，可以直接使用 QueryBuilder：

```php
use db\Connection;

$db = new Connection(['host' => '127.0.0.1', ...]);  // 或通过容器获取
$qb = $db->table('users');

// ===== 基础查询 =====
$all   = $qb->fetchAll();                           // 获取全部
$first = $qb->where('id', '=', 1)->first();        // 第一条
$name  = $qb->where('id', '=', 1)->value('name');  // 单个字段值
$count = $qb->where('status', '=', 1)->count();    // 统计

// ===== WHERE 条件（多种变体） =====
$qb->where('status', '=', 1)                       // 等值
   ->where('age', '>', 18)                         // 大于
   ->where('name', 'LIKE', '%John%')               // 模糊匹配
   ->whereIn('id', [1, 2, 3])                       // IN
   ->whereNull('deleted_at')                        // IS NULL
   ->whereNotNull('email')                          // IS NOT NULL
   ->whereBetween('age', 18, 65);                   // BETWEEN

// OR 条件（每个 key 是列名，值为直接值或 [操作符, 值] 数组）
$qb->whereOr(['name' => 'John', 'role' => ['=', 'admin']]); // (name='John' OR role='admin')

// ===== JOIN 关联查询 =====
$qb->select(['users.*', 'profiles.avatar'])
   ->join('profiles', 'users.id', '=', 'profiles.user_id')
   ->leftJoin('orders', 'users.id', '=', 'orders.user_id')
   ->fetchAll();

// ===== 排序 + 分页 =====
$result = $db->table('users')
    ->orderBy('created_at', 'DESC')  // 按创建时间降序
    ->paginate(15, 1);               // 每页15条，第1页

// paginate 返回结构：
// [
//     'items'        => [...],      // 当前页数据
//     'total'        => 100,        // 总记录数
//     'per_page'     => 15,         // 每页条数
//     'current_page' => 1,          // 当前页码
//     'last_page'    => 7,          // 最后一页页码
//     'has_more'     => true        // 是否有下一页
// ]

// ===== 聚合函数 =====
$count    = $qb->count();             // COUNT(*)
$count    = $qb->count('id');        // COUNT(id)
$sum      = $qb->sum('amount');      // SUM(amount)
$avg      = $qb->avg('score');       // AVG(score)
$max      = $qb->max('id');          // MAX(id)
$min      = $qb->min('id');          // MIN(id)

// ===== 分组 + 聚合筛选 =====
$db->table('orders')
   ->groupBy('user_id')
   ->having('total', '>', 100)
   ->fetchAll();

// ===== INSERT / UPDATE / DELETE =====
$id = $db->table('users')->insert(['name' => 'John']);   // INSERT

// ⚠️ UPDATE 必须带 WHERE（框架强制要求，防止全表误更新）
$db->table('users')->where('id', '=', 1)->update(['name' => 'Jane']);

// ⚠️ DELETE 必须带 WHERE（同理）
$db->table('users')->where('id', '=', 1)->delete();
```

> 🛡️ **安全机制**：
> - 所有值通过 PDO 参数绑定，杜绝 SQL 注入
> - WHERE 操作符限定为白名单（`=`, `!=`, `<`, `>`, `<=`, `>=`, `LIKE`, `NOT LIKE`, `IN`, `NOT IN`），拒绝奇异的 SQL 操作符
> - UPDATE/DELETE 不带 WHERE 会直接抛出异常，保护全表数据

### 6.10 数据库事务

```php
$db = new Connection([...]);

try {
    $db->beginTransaction();  // 开始事务

    // 操作一：创建订单
    $db->table('orders')->insert([
        'user_id' => 1,
        'amount'  => 100,
    ]);

    // 操作二：扣减余额
    $db->table('users')->where('id', '=', 1)->update([
        'balance' => $newBalance,
    ]);

    $db->commit();  // 提交：两个操作都成功，数据写入
} catch (\Exception $e) {
    $db->rollback();  // 回滚：任一操作失败，之前的修改全部撤销
    throw $e;
}
```

> 💡 **事务的作用**：确保一组数据库操作要么全部成功，要么全部撤销。典型场景：转账（A 扣钱 + B 加钱，两步必须同时成功）。

---

## 7. 视图与模板

LightPHP 支持三种模板方式：原生 PHP（性能最高）、Blade 风格（功能最全）、Smarty（可选扩展）。

### 7.1 原生 PHP 模板

视图文件放在 `app/view/` 目录下：

```php
<!-- app/view/article/list.php -->
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <title><?= htmlspecialchars($title ?? '文章列表') ?></title>
</head>
<body>
    <h1><?= htmlspecialchars($title ?? '文章列表') ?></h1>

    <?php if (!empty($articles)): ?>
        <ul>
            <?php foreach ($articles as $article): ?>
                <li>
                    <a href="/article/<?= $article['id'] ?>">
                        <?= htmlspecialchars($article['title']) ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php else: ?>
        <p>暂无文章</p>
    <?php endif; ?>
</body>
</html>
```

控制器中渲染：

```php
public function index(): \core\Response
{
    $articles = Article::all();
    return $this->view('article/list', [
        'title' => '文章列表',
        'articles' => $articles,
    ]);
}
```

> ⚠️ **XSS 防护**：在原生 PHP 模板中手动使用 `htmlspecialchars()` 转义所有用户输入的内容。

### 7.2 视图辅助函数

LightPHP 提供了一系列视图辅助函数（`view\Helper`）：

```php
// HTML 安全转义
\view\Helper::e($value);                        // htmlspecialchars 封装

// 获取上一次表单提交的旧值（配合 Session Flash 使用）
\view\Helper::old('field_name', 'default_value');

// 调试输出
\view\Helper::dump($variable);                  // var_dump 美化

// 资源文件路径
\view\Helper::asset('/css/style.css');          // → /css/style.css

// URL 生成
\view\Helper::url('/user/profile');             // → /user/profile

// 当前时间
\view\Helper::now('Y-m-d H:i:s');               // 格式化当前时间

// 字符串截断
\view\Helper::truncate($longText, 100, '...');  // 超过 100 字符时截断

// 是/否标签
\view\Helper::yesno($value, ['否', '是']);      // 根据布尔值输出

// 根据当前 URL 判断菜单激活状态
\view\Helper::isActive('/users', 'active');     // 当前在 /users 时输出 'active'
```

关于 Blade 模板引擎和 Smarty 模板，请分别参考 [第 25 章 Blade 模板引擎](#25-blade-模板引擎) 和 [第 29 章 Smarty 模板](#29-smarty-模板引擎可选扩展)。

---

## 8. 中间件

中间件采用**洋葱模型（Onion Middleware）**：请求从外向内穿过层层中间件，到达核心控制器后，响应再从内向外传回。每一层中间件都可以在请求前后做处理。

```
请求 → [认证] → [日志] → [控制器] → [日志] → [认证] → 响应
       |_______前置处理_______|  |____后置处理____|
```

> 💡 **中间件的典型用途**：认证检查、请求日志、CORS 处理、速率限制、输入过滤等。

### 8.1 创建自定义中间件

```php
<?php
declare(strict_types=1);

namespace middleware;

class Auth
{
    /**
     * @param mixed    $request  请求对象
     * @param callable $next     下一个处理者（下一个中间件或控制器）
     * @return mixed             响应对象
     */
    public function handle($request, callable $next)
    {
        // ===== 前置处理：控制器执行之前 =====
        if (!isset($_SESSION['user_id'])) {
            // 未登录：记录当前 URL 后返回 401
            $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
            return new \core\Response('请先登录', 401);
        }

        // ===== 调用下一层 =====
        $response = $next($request);

        // ===== 后置处理：控制器执行之后 =====
        // 例如添加响应头
        // $response->header('X-User-Id', $_SESSION['user_id']);

        return $response;
    }
}
```

### 8.2 内置中间件速查

#### CORS 中间件（`middleware\Cors`）

处理浏览器跨域请求：

```php
use middleware\Cors;

$cors = new Cors([
    'allowed_origins'      => ['https://example.com'],  // 白名单域名
    'allowed_methods'      => ['GET', 'POST', 'PUT', 'DELETE'],
    'allowed_headers'      => ['Content-Type', 'Authorization'],
    'supports_credentials'  => true,                     // 允许携带 Cookie
    'max_age'              => 86400,                     // 预检请求缓存秒数
]);
```

> ⚠️ **安全提醒**：生产环境中 `allowed_origins` 务必填写具体域名，不要使用 `*`。

#### Throttle 限流中间件（`middleware\Throttle`）

限制单个 IP 的请求频率：

```php
use middleware\Throttle;

// 60 次 / 60 秒 = 每分钟最多 60 次请求
$throttle = new Throttle(60, 60);

// 超限自动返回 429 Too Many Requests，响应头带 Retry-After
```

#### CSRF 防护中间件（`middleware\CsrfMiddleware`）

防止跨站请求伪造攻击。详见 [第 17 章 CSRF 防护](#17-csrf-防护)。

### 8.3 使用中间件

```php
// 方式一：单个路由绑定
$router->get('/profile', [UserController::class, 'profile'])
    ->middleware([\middleware\Auth::class]);

// 方式二：路由分组批量绑定
$router->group([
    'prefix' => '/admin',
    'middleware' => [\middleware\Auth::class, \middleware\Admin::class]
], function($router) {
    $router->get('/dashboard', [AdminController::class, 'dashboard']);
});
```

### 8.4 中间件别名、组与全局注册（v2.0 新增）

v2.0 新增了中间件别名、中间件组和全局中间件的注册机制，让中间件管理更灵活。

#### 中间件别名

给中间件类注册一个短名称，在路由中使用时更简洁：

```php
$router = new Router();

// 注册别名
$router->aliasMiddleware('auth', \middleware\Auth::class);
$router->aliasMiddleware('admin', \middleware\AdminAuth::class);
$router->aliasMiddleware('throttle', \middleware\Throttle::class);

// 在路由中使用别名（而非完整类名）
$router->get('/profile', [UserController::class, 'profile'])
    ->middleware(['auth']);

$router->group(['middleware' => ['auth', 'admin']], function($router) {
    $router->get('/dashboard', [AdminController::class, 'dashboard']);
});
```

#### 中间件组

将多个中间件打包成一组，按名称引用：

```php
// 注册中间件组
$router->middlewareGroup('api', [
    \middleware\Cors::class,
    \middleware\Throttle::class,
]);

$router->middlewareGroup('web', [
    \middleware\CsrfMiddleware::class,
    \middleware\Auth::class,
]);

// 在路由中使用组名
$router->group(['middleware' => ['api']], function($router) {
    // 所有路由自动经过 Cors + Throttle 中间件
    $router->get('/users', [UserController::class, 'index']);
});
```

#### 全局中间件

全局中间件在每个请求中都会执行，无需在路由中显式指定：

```php
$router->setGlobalMiddleware([
    \middleware\Cors::class,
]);
```

> 💡 **解析优先级**：全局中间件 → 路由中间件（先展开别名和组，再按顺序执行）。

---

## 9. 请求与响应

### 9.1 Request 类

Request 对象封装了 `$_GET`、`$_POST`、`$_FILES`、`$_SERVER` 等超全局变量，提供了简洁安全的接口。

```php
public function store(Request $request): \core\Response
{
    // ===== 获取输入数据 =====
    $id    = $request->get('id');                     // GET 参数
    $name  = $request->post('name');                  // POST 参数
    $name  = $request->input('name', '默认值');       // 统一入口（GET/POST）
    $all   = $request->all();                         // 所有输入
    $data  = $request->only(['name', 'email']);       // 白名单取参（推荐！）
    $data  = $request->except(['password', 'token']); // 排除指定字段

    // ===== 判断字段存在 =====
    if ($request->has('name')) { /* ... */ }

    // ===== 文件上传 =====
    $file = $request->file('avatar');
    if ($request->hasFile('avatar')) { /* ... */ }

    // ===== 请求信息 =====
    $method    = $request->method();              // GET / POST / PUT ...
    $uri       = $request->uri();                 // /users/1
    $isAjax    = $request->isAjax();              // 是否 AJAX
    $isGet     = $request->isGet();               // 是否 GET
    $isPost    = $request->isPost();              // 是否 POST
    $ip        = $request->ip();                  // 客户端 IP
    $userAgent = $request->userAgent();           // User-Agent
    $token     = $request->header('Authorization'); // 请求头
}
```

#### 类型过滤方法（v2.0 新增）

Request 新增了类型安全的输入获取方法，自动将输入值转为指定类型：

```php
// 获取字符串（去除首尾空格）
$name = $request->string('name');           // 默认 ''
$name = $request->string('name', '匿名');   // 指定默认值

// 获取整数
$page = $request->integer('page');          // 默认 0
$page = $request->integer('page', 1);       // 指定默认值

// 获取浮点数
$price = $request->float('price');          // 默认 0.0
$price = $request->float('price', 0.01);    // 指定默认值

// 获取布尔值
$remember = $request->boolean('remember');  // 默认 false
// '1', 'true', 'on' → true；其他 → false

// 获取数组
$ids = $request->arrayInput('ids');         // 默认 []
$ids = $request->arrayInput('ids', [1]);    // 指定默认值

// 合并额外数据到请求
$request->merge(['user_id' => $userId]);
// 之后可以通过 $request->input('user_id') 获取
```

> 💡 **为什么用类型过滤？** HTTP 请求的所有参数都是字符串类型。使用 `$request->integer('page')` 可以确保你拿到的是整数，避免 `"2"` 这样的字符串导致意外行为。

#### 宏扩展（Macroable，v2.0 新增）

Request 和 Response 类在 v2.0 中引入了 `Macroable` trait，允许你在运行时动态添加方法：

```php
use core\Request;

// 动态添加方法
Request::macro('userAgentIsMobile', function() {
    $ua = $this->userAgent();
    return (bool) preg_match('/Mobile|Android|iPhone/i', $ua);
});

// 使用
if ($request->userAgentIsMobile()) {
    return $this->view('mobile/home');
}
```

> 💡 **`only()` vs `except()`**：推荐使用 `only()`（白名单），明确列出需要的字段。这样即使请求中带了多余的字段也不会产生意外。

### 9.2 Response 类

```php
use core\Response;

// HTML 响应
return Response::make('<h1>Hello</h1>');

// 带状态码
return Response::make('Not Found', 404);

// JSON 响应
return Response::json([
    'code'    => 0,
    'message' => 'success',
    'data'    => ['id' => 1, 'name' => 'John'],
]);

// 设置响应头
return Response::make($content)
    ->header('X-Custom-Header', 'value')
    ->header('Cache-Control', 'no-cache');

// 重定向
return Response::redirect('/login');      // 302 临时重定向
return Response::redirect('/new-page', 301); // 301 永久重定向
```

---

## 10. 验证器

LightPHP 内置了数据验证器，支持链式调用和自定义错误消息。

### 10.1 基本用法

```php
use core\Validate;

$validator = (new Validate())->rules([
    'name'  => 'required|min:2|max:50',          // 必填，2-50 个字符
    'email' => 'required|email',                  // 必填，邮箱格式
    'age'   => 'required|integer|min:18|max:120', // 必填，18-120 的整数
]);

// 方式一：validate 方法返回 bool + validated 获取数据
if ($validator->validate($request->all())) {
    // 验证通过，拿到已验证的数据
    $validData = $validator->validated();
}

if (!$validator->validate($request->all())) {
    // 验证失败，获取所有错误
    $errors     = $validator->errors();      // ['name' => ['名称不能为空']]
    $firstError = $validator->firstError();  // '名称不能为空'
}
```

### 10.2 可用的验证规则

| 规则 | 说明 | 示例 |
|------|------|------|
| `required` | 必填 | `'name' => 'required'` |
| `email` | 邮箱格式 | `'email' => 'email'` |
| `min:n` | 最小长度/最小值 | `'name' => 'min:2'` |
| `max:n` | 最大长度/最大值 | `'name' => 'max:50'` |
| `numeric` | 必须是数字 | `'score' => 'numeric'` |
| `integer` | 必须是整数 | `'age' => 'integer'` |
| `url` | 必须是 URL | `'website' => 'url'` |
| `ip` | 必须是 IP 地址 | `'visitor_ip' => 'ip'` |
| `alpha` | 只能包含字母 | `'code' => 'alpha'` |
| `alphaNum` | 只能包含字母和数字 | `'code' => 'alphaNum'` |
| `in:a,b,c` | 值必须在列表中 | `'role' => 'in:admin,user'` |
| `notIn:a,b,c` | 值不能在列表中 | `'name' => 'notIn:admin,root'` |
| `regex:pattern` | 正则匹配 | `'phone' => 'regex:/^1\d{10}$/'` |
| `date:Y-m-d` | 必须是日期格式 | `'birth' => 'date:Y-m-d'` |
| `confirmed` | 确认字段（必须有 `_confirmation` 后缀的同名字段） | `'password' => 'confirmed'` |

> 💡 **`confirmed` 规则**：常用于密码确认。如果规则是 `'password' => 'confirmed'`，它会检查请求中是否存在 `password_confirmation` 字段且值相同。

### 10.3 自定义错误消息

```php
$validator = (new Validate())
    ->rules([
        'name'  => 'required|min:2|max:50',
        'email' => 'required|email',
    ])
    ->messages([
        'name.required' => '请填写姓名',
        'name.min'      => '姓名至少需要 :min 个字符',  // :min 会被替换为 2
        'name.max'      => '姓名最多 :max 个字符',      // :max 会被替换为 50
        'email.required' => '请填写邮箱地址',
        'email.email'   => '邮箱格式不正确',
    ]);

// 执行验证
if (!$validator->validate($_POST)) {
    $errors = $validator->errors();
    // 返回：
    // ['name' => ['请填写姓名'], 'email' => ['邮箱格式不正确']]
}
```

---

## 11. 缓存

LightPHP 内置了文件缓存驱动（`cache\FileCache`），支持 JSON 序列化 + HMAC 签名，防止缓存投毒攻击。

### 11.1 基本操作

```php
use cache\FileCache;

$cache = new FileCache();  // 默认存储路径 storage/cache/

// 写入缓存（有效期 3600 秒 = 1 小时）
$cache->set('user:1', $userData, 3600);

// 读取缓存（不存在时返回默认值）
$user = $cache->get('user:1', null);

// 检查是否存在
if ($cache->has('user:1')) { /* ... */ }

// 删除单个
$cache->delete('user:1');

// 清空全部
$cache->clear();
```

> 🛡️ **缓存安全**：FileCache 使用 JSON（不是 `serialize`）+ HMAC 签名验证数据完整性。即使有人篡改缓存文件，验证也会失败并返回默认值。缓存文件头部包含 `<?php die; ?>` 防止被直接访问执行。

### 11.2 remember 方法（缓存穿透保护）

当缓存不存在时自动执行回调获取数据并写入缓存：

```php
// 缓存 'hot_articles' 1800 秒（30 分钟）
// 如果缓存不存在，自动查询数据库并设置缓存
$articles = $cache->remember('hot_articles', 1800, function() use ($db) {
    return $db->table('articles')
        ->where('is_hot', '=', 1)
        ->orderBy('id', 'DESC')
        ->limit(10)
        ->fetchAll();
});
```

### 11.3 计数器

```php
$cache->set('page_views', 0);        // 初始化
$cache->increment('page_views');     // +1（默认）
$cache->increment('page_views', 5);  // +5
$cache->decrement('page_views');     // -1
```

---

## 12. 日志

LightPHP 的 `log\Logger` 实现了 PSR-3 Logger Interface 标准。日志按日期自动分割，存储在 `storage/log/` 目录下。

### 12.1 基本用法

```php
use log\Logger;

$logger = new Logger();

// 8 个日志级别（按严重程度从低到高）
$logger->debug('调试信息');        // 最低级别，开发调试用
$logger->info('用户登录成功');      // 常规操作信息
$logger->notice('磁盘空间不足');     // 值得注意但非异常
$logger->warning('API 响应变慢');   // 警告信息
$logger->error('数据库连接失败');    // 错误（不阻断运行）
$logger->critical('支付服务不可用'); // 严重错误（影响核心功能）
$logger->alert('监控告警触发');      // 需要立即处理的告警
$logger->emergency('系统完全崩溃');  // 系统不可用
```

> 💡 **日志级别的选择原则**：
> - 开发环境：设置 `debug` 级别，记录所有信息
> - 生产环境：设置 `warning` 级别，只记录警告和错误，减少日志量

### 12.2 带上下文的日志

```php
$logger->info('用户执行了操作', [
    'user_id' => 123,
    'action'  => 'create_post',
    'post_id' => 456,
    'ip'      => $_SERVER['REMOTE_ADDR'],
]);
```

日志文件路径：`storage/log/2025-01-15.log`

> 📝 **PSR-3 兼容**：Logger 实现了 `core\contract\LoggerInterface` 接口，支持标准的 PSR-3 方法签名。上下文中的 `{key}` 占位符会自动替换为对应的值。

---

## 13. 依赖注入容器

LightPHP 的容器（`core\Container`）实现了 PSR-11 Container Interface 标准接口，支持自动依赖解析。

> 💡 **容器是什么？** 简单说，容器就是"对象工厂"。你把"怎么创建某个对象"（绑定）告诉容器，以后直接用 `get()` 就能拿到配置好的对象，不需要每次手动 `new`。

### 13.1 基本操作

```php
use core\Container;
use cache\FileCache;
use db\Connection;

$container = new Container();

// 【绑定】— 把接口映射到具体实现类
$container->bind('cache', FileCache::class);
// 每次 get('cache') 都会创建一个新实例

// 【单例】— 全局只有一个实例，多次调用返回同一个
$container->singleton('db', Connection::class);
// 第一次 get('db') 创建实例，之后每次都返回同一个

// 【别名】— 给服务起个别名
$container->alias('database', 'db');
// get('database') === get('db')

// 【获取实例】
$cache = $container->get('cache');
$db    = $container->get('db');

// 【自动解析（反射）】
// 不需要显式绑定，容器会通过反射自动解析构造函数的参数
$validator = $container->get(Validate::class);

// 【检查】
$hasCache = $container->has('cache');  // true

// 【移除】
$container->forget('cache');

// 【清空全部】
$container->flush();
```

### 13.2 在应用中使用容器

框架在 Application 中自动初始化了容器，通过 `Container::getInstance()` 可以获取服务：

```php
$container = \core\Container::getInstance();
$db    = $container->get(\db\Connection::class);
$cache = $container->get(\cache\FileCache::class);
$log   = $container->get(\log\Logger::class);
```

### 13.3 PSR-11 兼容说明

LightPHP 的容器 100% 符合 PSR-11 标准：

```php
// PSR-11 标准方式
$service = $container->get('service-id');
$exists  = $container->has('service-id');

// 不存在时抛出异常
// NotFoundException implements PsrNotFoundExceptionInterface
```

---

## 14. 配置管理深入

### 14.1 配置文件

所有配置位于 `app/config/` 下，按功能分类：

| 文件 | 说明 |
|------|------|
| `app.php` | 应用名称、环境、调试模式、密钥 |
| `database.php` | 数据库连接（支持多连接配置） |
| `cache.php` | 缓存驱动配置 |

### 14.2 获取和设置配置

```php
// 通过 Application 实例获取
$app    = new Application();
$debug  = $app->getConfig('app.debug', false);     // 嵌套键用点号分隔
$dbHost = $app->getConfig('database.connections.mysql.host');

// 动态设置配置
$app->setConfig('app.debug', true);  // 程序内动态修改

// 通过 Config 静态工具类
$allDbConfig = \config\Config::get('database');
```

### 14.3 关于 `.env` 文件

传统的 `.env` 配置文件在本框架中为**可选**功能。默认情况下，直接在 `app/config/` 下的 PHP 文件中配置即可，这种方式更清晰直观。可以通过 CLI 命令查看当前配置：`php bin/console config` 和 `php bin/console config:show database`。`core\Env` 类提供了对 `.env` 格式的兼容读取（如果项目中有 `.env` 文件且你选择加载的话）。

> 💡 **为什么推荐 PHP 配置文件而不是 .env？**
> 1. PHP 文件有 IDE 语法高亮和自动补全，不易写错
> 2. 支持注释（`.env` 虽然也支持单行注释，但 PHP 文件更灵活）
> 3. 支持复杂数据结构（嵌套数组等）
> 4. 一个功能一个文件，清晰分类

---

## 15. Cookie

### 15.1 基本用法

```php
use core\Cookie;

// 设置 Cookie
Cookie::set('theme', 'dark', 86400);  // 名称、值、过期秒数（86400 = 1天）

// 完整参数设置
Cookie::set('token', $value, 3600, '/', '', true, 'Strict');
// 参数依次：名称、值、过期秒、路径、域名、仅 HTTPS、SameSite

// 获取 Cookie
$theme = Cookie::get('theme', 'light');  // 不存在时返回 'light'

// 检查存在
if (Cookie::has('theme')) { /* ... */ }

// 删除 Cookie
Cookie::delete('theme');
```

### 15.2 SameSite 参数说明

| 值 | 行为 | 适用场景 |
|------|------|---------|
| `Strict` | 最严格，任何跨站请求都不发送 Cookie | 高安全性需求 |
| `Lax`（默认） | 顶级导航（如点击链接）时发送，iframe/img/ajax 不发送 | 大多数 Web 应用 |
| `None` | 允许跨站发送（必须搭配 `Secure=true`） | 跨域 API、第三方嵌入 |

---

## 16. 加密哈希

### 16.1 bcrypt 密码哈希

不可逆的密码哈希，用于安全存储用户密码：

```php
use core\Hash;

// 生成哈希
$hashed = Hash::make('password123');

// 验证密码
if (Hash::verify('password123', $hashed)) {
    echo '密码正确';
}
// Hash::verify('wrong_password', $hashed) → false
```

> 💡 **bcrypt 特点**：每次生成的哈希值都不同（因为包含随机盐），但都能正确验证。即使数据库泄露，攻击者也无法反推出原始密码。

### 16.2 AES-256-GCM 认证加密

可逆加密，同时验证数据完整性（防止密文被篡改）：

```php
// 加密（需要 app/config/app.php 中的 'key' 已设置）
$encrypted = Hash::encrypt('敏感数据');   // 返回 IV + 密文 + 认证标签

// 解密
$decrypted = Hash::decrypt($encrypted);   // 返回原始明文

// 如果密文被篡改，decrypt() 会抛出异常
```

> ⚠️ **使用前提**：`app/config/app.php` 中的 `key` 必须已修改为你的自定义值。

---

## 17. CSRF 防护

CSRF（Cross-Site Request Forgery，跨站请求伪造）是一种攻击手段，攻击者诱导用户点击恶意链接，利用用户已登录的身份在目标网站执行未授权的操作。

### 17.1 使用 CSRF 中间件

```php
use middleware\CsrfMiddleware;

// 方式一：在需要保护的路由分组上使用
$router->group(['middleware' => [CsrfMiddleware::class]], function($router) {
    $router->post('/form', [FormController::class, 'submit']);
    $router->put('/profile', [UserController::class, 'update']);
    $router->delete('/post/{id}', [PostController::class, 'destroy']);
});
```

### 17.2 表单中使用 CSRF Token

传统 HTML 表单：

```php
<form method="POST" action="/form">
    <input type="hidden" name="_token" value="<?= \core\Session::token() ?>">
    <input type="text" name="name">
    <button type="submit">提交</button>
</form>
```

Blade 模板中使用 `@csrf` 指令：

```blade
<form method="POST" action="/submit">
    @csrf
    <input type="text" name="data">
    <button type="submit">提交</button>
</form>
```

---

## 18. 异常处理

### 18.1 异常层次结构

LightPHP 提供了清晰的异常层次：

```
FrameworkException（框架基础异常）
├── RouteNotFoundException     → 404 路由未找到
├── HttpException              → HTTP 状态异常（可自定义状态码）
├── DatabaseException          → 数据库操作异常
└── ValidationException        → 数据验证异常（携带错误详情数组）
```

### 18.2 使用示例

```php
use core\exception\RouteNotFoundException;
use core\exception\HttpException;
use core\exception\DatabaseException;
use core\exception\ValidationException;

// 抛 404
throw new RouteNotFoundException('页面不存在');

// 抛自定义 HTTP 状态码
throw new HttpException(403, '你没有访问此资源的权限');

// 抛数据库异常
throw new DatabaseException('数据库连接失败：' . $e->getMessage());

// 抛验证异常（带详细的字段错误）
throw new ValidationException([
    'email' => '邮箱格式不正确',
    'name'  => '姓名不能为空',
]);
```

> 💡 **什么时候抛异常？** 当程序遇到了无法继续正确执行的情况（如路由未匹配到、数据库连不上、用户输入不合法等），应该抛出对应类型的异常，而不是返回一个默认的错误值。这样上层可以统一捕获和处理。

### 18.3 ExceptionHandler 异常处理器（v2.0 新增）

v2.0 新增了 `core\ExceptionHandler` 类，提供 report/render 分离的异常处理机制：

```php
use core\ExceptionHandler;

// 创建异常处理器
$handler = new ExceptionHandler($logger, $debug);

// report — 记录异常到日志
$handler->report($exception);

// render — 将异常转为 HTTP 响应
$response = $handler->render($request, $exception);
```

#### 不记录日志的异常

通过 `$dontReport` 属性配置不需要记录日志的异常类型：

```php
class MyExceptionHandler extends ExceptionHandler
{
    protected array $dontReport = [
        \core\exception\RouteNotFoundException::class,
        \core\exception\ValidationException::class,
    ];
}
```

#### 不在响应中暴露的字段

通过 `$dontFlash` 属性配置不应在调试响应中暴露的敏感字段：

```php
class MyExceptionHandler extends ExceptionHandler
{
    protected array $dontFlash = [
        'password',
        'password_confirmation',
        'credit_card_number',
    ];
}
```

> 💡 **调试模式 vs 生产模式**：`$debug = true` 时，异常响应包含详细的堆栈跟踪和代码片段；`$debug = false` 时，只返回通用的 500 错误页面，不暴露内部信息。

---

## 19. 接口契约

接口契约定义了框架组件的标准行为，使得组件可以互相替换（例如：用 Redis 缓存替换文件缓存，只需实现相同的接口）。

### 19.1 CacheInterface

```php
interface CacheInterface
{
    public function get(string $key, mixed $default = null): mixed;
    public function set(string $key, mixed $value, int $ttl = 0): bool;
    public function delete(string $key): bool;
    public function has(string $key): bool;
    public function clear(): bool;
    public function remember(string $key, int $ttl, callable $callback): mixed;
    public function increment(string $key, int $step = 1): int;
    public function decrement(string $key, int $step = 1): int;
}
```

### 19.2 LoggerInterface（PSR-3 兼容）

```php
interface LoggerInterface
{
    // 9 个日志级别方法（emergency → debug）
    public function emergency(string|\Stringable $message, array $context = []): void;
    public function alert(string|\Stringable $message, array $context = []): void;
    public function critical(string|\Stringable $message, array $context = []): void;
    public function error(string|\Stringable $message, array $context = []): void;
    public function warning(string|\Stringable $message, array $context = []): void;
    public function notice(string|\Stringable $message, array $context = []): void;
    public function info(string|\Stringable $message, array $context = []): void;
    public function debug(string|\Stringable $message, array $context = []): void;

    // 通用日志方法
    public function log(string $level, string|\Stringable $message, array $context = []): void;
}
```

### 19.3 PsrContainerInterface（PSR-11 兼容）

```php
interface PsrContainerInterface
{
    public function get(string $id): mixed;
    public function has(string $id): bool;
}
```

LightPHP 的 `core\Container` 完整实现了此接口，使用 `PsrNotFoundExceptionInterface` 处理不存在的 ID。

---

## 20. 配置

> 本章与 [第 2 章配置管理](#2-配置管理) 互为补充，这里重点介绍配置的结构和获取方式。

### 20.1 配置扁平化结构

虽然配置文件是嵌套的多维数组，但获取时使用**点号分隔**的路径语法进行扁平化访问：

```
配置文件结构                          获取方式
app.php → ['debug' => true]     →    $app->getConfig('app.debug')
database.php → {                 →    $app->getConfig('database.connections.mysql.host')
    connections: {
        mysql: { host: '...' }
    }
}
```

### 20.2 配置动态修改

某些场景需要在运行时修改配置（如：根据请求头切换语言、根据环境切换调试模式）：

```php
$app->setConfig('app.debug', false);        // 关闭调试
$app->setConfig('app.lang', 'zh-cn');      // 切换语言
```

---

## 21. 最佳实践

### 21.1 项目目录组织

推荐按以下方式组织代码：

```
app/
├── controller/           # 控制器（可按模块分子目录）
│   ├── Api/              # API 控制器
│   └── Web/              # 网页控制器
├── model/               # 模型
├── view/                # 视图
│   ├── layouts/         # 公共布局
│   └── partials/        # 复用组件
├── service/             # 业务逻辑层（Service），保持控制器"薄"
└── middleware/           # 自定义中间件
```

### 21.2 控制器最佳实践

```php
class UserController extends Controller
{
    private UserService $userService;

    // 通过构造方法注入业务服务
    public function __construct(UserService $userService)
    {
        $this->userService = $userService;
    }

    public function index(): \core\Response
    {
        // 控制器只负责参数传递和响应返回
        // 真正的业务逻辑放在 Service 中
        $users = $this->userService->getActiveUsers();
        return $this->json(['users' => $users]);
    }
}
```

### 21.3 模型最佳实践

```php
class Post extends Model
{
    protected string $table = 'posts';

    // ✅ 明确声明可批量赋值的字段
    protected array $fillable = ['title', 'content', 'user_id', 'status'];

    // ✅ 对敏感字段显式声明隐藏
    protected array $hidden = ['password'];

    // ✅ 对所有非字符串字段设置类型转换
    protected array $casts = [
        'user_id'    => 'int',
        'status'     => 'int',
        'created_at' => 'datetime',
    ];
}
```

### 21.4 安全最佳实践清单

| 措施 | 做法 | 重要性 |
|------|------|--------|
| **输出转义** | 原生 PHP 模板中使用 `htmlspecialchars()`；Blade 用 `{{ }}` 自动转义 | 🔴 必须 |
| **批量赋值保护** | 模型声明 `$fillable` 白名单 | 🔴 必须 |
| **输入验证** | 所有用户输入通过 Validate 类校验 | 🔴 必须 |
| **CSRF 防护** | 表单提交使用 `@csrf` 指令 + CsrfMiddleware | 🟡 推荐 |
| **密码哈希** | 使用 `Hash::make()` 存储密码 | 🔴 必须 |
| **加密密钥** | `app/config/app.php` 的 `key` 务必修改 | 🔴 必须 |

### 21.5 性能优化建议

```php
// ✅ 使用缓存减少数据库查询
$cache = new FileCache();
$users = $cache->remember('users:list', 600, function() {
    return User::all();
});

// ✅ 只查询需要的字段
$users = User::select(['id', 'name', 'email'])
    ->where('status', '=', 1)
    ->fetchAll();

// ✅ 使用分页处理大数据集
$result = User::paginate(20, $page);

// ✅ 使用预加载解决 N+1 问题
$users = User::with('posts')->fetchAll();
```

---

## 22. ORM 关联关系

ORM 关联让你在 PHP 代码中自然地表达数据表之间的关系。

### 22.1 四种关联关系一览

| 关联类型 | 方法 | 场景示例 |
|---------|------|---------|
| 一对一 | `hasOne()` | User ↔ Profile（每个用户有一份资料） |
| 一对多 | `hasMany()` | User ↔ Posts（每个用户有多篇文章） |
| 反向一对多 | `belongsTo()` | Post ↔ User（每篇文章属于一个用户） |
| 多对多 | `belongsToMany()` | Post ↔ Tag（文章和标签，通过中间表关联） |

### 22.2 定义和使用关联

```php
class User extends Model
{
    // 一对一：User 有一个 Profile
    public function profile()
    {
        return $this->hasOne(Profile::class, 'user_id');
    }

    // 一对多：User 有多个 Post
    public function posts()
    {
        return $this->hasMany(Post::class, 'user_id');
    }
}

class Post extends Model
{
    // 反向关联：Post 属于哪个 User
    public function author()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // 多对多：Post 有哪些 Tag（通过 post_tag 中间表）
    public function tags()
    {
        return $this->belongsToMany(Tag::class, 'post_tag');
    }
}

// ===== 使用关联 =====

$user = User::find(1);

// 方法调用获取关联数据
$profile = $user->profile();    // → Profile 对象或 null
$posts   = $user->posts();      // → Post 对象数组
$author  = Post::find(1)->author();  // → User 对象或 null
$tags    = Post::find(1)->tags();    // → Tag 对象数组
```

### 22.3 预加载（解决 N+1 查询问题）

> 📖 **N+1 问题**：假如要列出 100 个用户及其文章。不使用预加载时，循环中每次 `$user->posts()` 都会查一次数据库，总共需要 1（查用户）+ 100（查文章）= 101 次 SQL 查询。使用预加载后，只需 2 次 SQL。

```php
// 【方式一】with() 预加载
$users = User::with('posts')->fetchAll();
foreach ($users as $user) {
    $posts = $user->posts;  // ⚡ 从预加载中取，不会重复查询
}

// 【方式二】手动批量预加载
$users = User::where('status', '=', 1)->fetchAll();
$userModels = array_map(fn($r) => new User($r), $users);
Model::eagerLoad($userModels, 'posts', Post::class, 'hasMany');
```

---

## 23. 事件系统

事件系统让模块之间解耦。一个模块触发事件，其他模块监听并响应，彼此不需要知道对方的存在。

> 💡 **典型场景**：用户注册后 → 发送欢迎邮件 + 记录日志 + 初始化用户设置。通过事件系统，注册控制器只需触发 `user.created` 事件，邮件、日志、设置这些功能各自监听并独立处理。

### 23.1 基础用法

```php
use core\EventDispatcher;

$events = new EventDispatcher();

// 注册监听器
$events->listen('user.created', function($event, array $data) {
    // $event → 事件名 'user.created'
    // $data  → 触发时传入的数据
    Logger::info("新用户注册：{$data['name']}");
});

// 触发事件
$events->dispatch('user.created', ['name' => 'John', 'email' => 'john@example.com']);
```

### 23.2 通配符监听

使用 `*` 匹配多个事件：

```php
// 匹配所有以 'order.' 开头的事件
$events->listen('order.*', function($event, $data) {
    Logger::info("订单事件触发：{$event}");
});

// 以下事件都会被上面的监听器捕获：
$events->dispatch('order.created', $data);
$events->dispatch('order.paid', $data);
$events->dispatch('order.shipped', $data);
```

### 23.3 优先级排序

数字越大越先执行：

```php
$events->listen('order.paid', [GenerateInvoice::class, 'handle'], 100);  // 先开发票
$events->listen('order.paid', [SendEmail::class, 'handle'], 50);        // 再发邮件
```

### 23.4 获取返回值

```php
// until() — 获得第一个非 null 的返回值后就停止
$handler = $events->until('find.handler', ['param']);

// dispatch() — 返回所有监听器的返回值数组
$results = $events->dispatch('event.name', $data);
```

### 23.5 订阅者模式

一个类中集中管理多个事件监听：

```php
class EventSubscriber
{
    public function subscribe(EventDispatcher $events): void
    {
        $events->listen('user.created', [$this, 'onUserCreated']);
        $events->listen('user.deleted', [$this, 'onUserDeleted']);
        $events->listen('order.placed', [$this, 'onOrderPlaced']);
    }

    public function onUserCreated($event, $data) { /* ... */ }
    public function onUserDeleted($event, $data) { /* ... */ }
    public function onOrderPlaced($event, $data) { /* ... */ }
}

$events->subscribe(new EventSubscriber());
```

---

## 24. 集合类（Collection）

Collection 是对数组的面向对象封装，提供了 40+ 个链式调用的数据处理方法。它让数组操作变得像写英文句子一样流畅。

### 24.1 创建集合

```php
// 用 collect() 辅助函数包装任意数组
$c = collect([1, 2, 3]);

// 或用静态方法
$c = \core\Collection::make([1, 2, 3]);
```

### 24.2 常用操作速查

```php
// ===== 过滤与映射 =====
$c->map(fn($n) => $n * 2);           // 每个元素 * 2 → [2, 4, 6]
$c->filter(fn($n) => $n > 1);        // 保留 > 1 的 → [2, 3]
$c->where('status', 1);              // 保留 status=1 的元素
$c->pluck('name');                   // 提取所有元素的 'name' 字段
$c->only(['a', 'c']);                // 只保留指定的键
$c->except(['b']);                   // 排除指定的键

// ===== 聚合 =====
$c->sum('price');                    // 求和
$c->avg('score');                    // 平均值
$c->min('age');                      // 最小值
$c->max('age');                      // 最大值

// ===== 排序与截取 =====
$c->sortBy('name');                  // 升序
$c->sortByDesc('created_at');       // 降序
$c->take(10);                        // 取前 10 条
$c->skip(5);                         // 跳过前 5 条

// ===== 分组 =====
$c->groupBy('type');                 // 按 type 分组
$c->keyBy('id');                     // 按 id 作为键

// ===== 查找 =====
$first = $c->first(fn($n) => $n > 5);    // 找到第一个匹配的
$has   = $c->contains(2);                // 是否包含 2
$empty = $c->isEmpty();                   // 是否为空

// ===== 输出 =====
$array = $c->toArray();              // 转回 PHP 数组
$json  = $c->toJson();              // 转回 JSON

// ===== 链式调用范例 =====
$result = collect($users)
    ->where('status', 1)             // 只取已激活用户
    ->sortByDesc('created_at')       // 按创建时间降序
    ->take(10)                        // 取前 10 个
    ->pluck('name')                   // 只取名字
    ->toJson();                       // 输出 JSON
```

---

## 25. Blade 模板引擎

Blade 是受 Laravel Blade 启发的模板编译器。`.blade.php` 文件会被自动编译为纯 PHP 并缓存，确保高性能。

### 25.1 创建 Blade 实例

```php
use view\Blade;

$blade = new Blade(
    VIEW_PATH,                    // 视图文件目录（ app/view/ ）
    STORAGE_PATH . 'views/'      // 编译缓存目录
);

// 渲染模板
echo $blade->render('page', [
    'title' => 'Hello',
    'users' => $users,
]);

// 直接编译字符串（用于测试/邮件模板）
$compiled = $blade->compileString('Hello {{ $name }}');
```

### 25.2 语法速查表

| 语法 | 功能 | 注意事项 |
|------|------|---------|
| `{{ $var }}` | HTML 转义输出 | ✅ 推荐（防 XSS） |
| `{!! $html !!}` | 原始 HTML 输出 | ⚠️ 注意 XSS 风险 |
| `@if(...) @elseif(...) @else @endif` | 条件判断 | — |
| `@foreach($items as $item) @endforeach` | 遍历循环 | $loop 对象可用 |
| `@for($i=0;...) @endfor` | for 循环 | — |
| `@while(...) @endwhile` | while 循环 | — |
| `@extends('layout')` | 继承布局 | — |
| `@section('name') ... @endsection` | 定义内容块 | — |
| `@yield('name')` | 渲染内容块 | — |
| `@include('view', [...])` | 包含子视图 | — |
| `@csrf` | CSRF 令牌隐藏字段 | — |
| `@method('PUT')` | 表单方法伪装（PUT/DELETE） | — |
| `@json($data)` | JSON 编码输出 | — |
| `@php ... @endphp` | 原生 PHP 代码 | — |
| `@isset($var) @endisset` | 变量已设置 | — |
| `@empty($var) @endempty` | 变量为空 | — |

### 25.3 自定义指令

```php
use view\Blade;

// @datetime — 格式化时间为日期
Blade::directive('datetime', function($expr) {
    return "<?= date('Y-m-d H:i', strtotime({$expr})) ?>";
});

// 模板中：<span>@datetime($post->created_at)</span>
// 输出：  <span>2025-01-15 10:30</span>
```

### 25.4 模板继承完整示例

```blade
{{-- layouts/app.blade.php — 布局模板 --}}
<!DOCTYPE html>
<html>
<head>
    <title>@yield('title', 'LightPHP')</title>
</head>
<body>
    <nav>@yield('nav')</nav>

    <main>
        @yield('content')
    </main>

    <footer>@yield('footer', '© 2025 LightPHP')</footer>
</body>
</html>

{{-- pages/home.blade.php — 继承布局的页面 --}}
@extends('layouts.app')

@section('title', '首页')

@section('content')
    <h1>欢迎来到 LightPHP</h1>

    @if(count($articles) > 0)
        @foreach($articles as $article)
            <article>
                <h2>{{ $article->title }}</h2>
            </article>
        @endforeach
    @else
        <p>暂无文章</p>
    @endif
@endsection
```

---

## 26. Schema Builder 与数据库迁移

### 26.1 Schema Builder 建表

```php
use db\Schema;
use db\Blueprint;

$pdo = \core\Container::getInstance()->get(\db\Connection::class)->getPdo();
$schema = Schema::setConnection($pdo);

// 创建表
$schema->create('articles', function(Blueprint $t) {
    $t->id();                              // bigint PK AUTO_INCREMENT
    $t->string('title', 200);             // VARCHAR(200) NOT NULL
    $t->text('content');                  // TEXT
    $t->string('slug')->unique();         // VARCHAR(255) UNIQUE
    $t->integer('view_count')->default(0);// INT DEFAULT 0
    $t->decimal('price', 8, 2)->nullable(); // DECIMAL(8,2) NULL
    $t->boolean('is_published')->default(0); // TINYINT(1) DEFAULT 0
    $t->timestamps();                      // created_at + updated_at
    $t->softDeletes();                    // deleted_at
});

// 修改表
$schema->table('articles', function(Blueprint $t) {
    $t->string('excerpt', 500)->nullable()->after('title');  // 在 title 后添加
    $t->dropColumn('old_column');                             // 删除列
    $t->renameColumn('old_name', 'new_name');                 // 重命名
});

// 表操作
$schema->hasTable('articles');               // 表是否存在
$schema->hasColumn('articles', 'slug');       // 列是否存在
$schema->rename('old_name', 'new_name');     // 重命名表
$schema->truncate('articles');               // 清空表
$schema->drop('articles');                   // 删除表
$schema->dropIfExists('articles');           // 存在则删除
```

### 26.2 可用列类型

| 方法 | SQL 类型 | 说明 |
|------|---------|------|
| `$t->id()` | BIGINT PK AUTO_INCREMENT | 自增主键 |
| `$t->string('name', 255)` | VARCHAR(255) | 变长字符串 |
| `$t->integer('age')` | INT | 整数 |
| `$t->bigInteger('id')` | BIGINT | 大整数 |
| `$t->tinyInteger('status')` | TINYINT | 小整数 |
| `$t->boolean('active')` | TINYINT(1) | 布尔值 |
| `$t->decimal('price', 8, 2)` | DECIMAL(8,2) | 精确小数 |
| `$t->float('score')` | FLOAT | 浮点数 |
| `$t->double('value')` | DOUBLE | 双精度浮点 |
| `$t->text('content')` | TEXT | 文本 |
| `$t->longText('body')` | LONGTEXT | 长文本 |
| `$t->date('birth')` | DATE | 日期 |
| `$t->dateTime('created')` | DATETIME | 日期时间 |
| `$t->timestamp('logged_at')` | TIMESTAMP | 时间戳 |
| `$t->json('settings')` | JSON | JSON 数据 |
| `$t->enum('status', ['a','b'])` | ENUM | 枚举值 |
| `$t->timestamps()` | created_at + updated_at | 自动时间戳 |
| `$t->softDeletes()` | deleted_at | 软删除 |
| `$t->morphs('name')` | name_id + name_type | 多态关联 |
| `$t->foreign('user_id')->references('id')->on('users')` | FOREIGN KEY | 外键约束 |

### 26.3 数据库迁移命令

```bash
# 创建迁移文件
php bin/console make:migration create_users users

# 执行所有待迁移文件
php bin/console migrate

# 回滚：最近一批
php bin/console migrate:rollback

# 回滚：最近 3 批
php bin/console migrate:rollback 3
```

---

## 27. CLI 命令系统

### 27.1 内置命令

```bash
php bin/console list                           # 列出所有命令
php bin/console serve                          # 启动开发服务器（8080）
php bin/console serve 3000                     # 指定端口号
php bin/console serve 8080 --host 0.0.0.0     # 允许局域网访问
php bin/console config                         # 显示配置概览
php bin/console config:show database            # 查看数据库详细配置（密码隐藏）
php bin/console cache:clear                    # 清空缓存
php bin/console make:model User                # 生成模型类
php bin/console make:controller User           # 生成控制器
php bin/console make:middleware Auth           # 生成中间件
php bin/console make:migration create_users users # 生成迁移
php bin/console migrate                        # 执行数据库迁移
php bin/console migrate:rollback               # 回滚迁移
php bin/console test                           # 运行单元测试
```

### 27.2 自定义命令

```php
use core\console\Command;

class ReportCommand extends Command
{
    // 签名：命令名 {参数?} {参数=默认} {--选项} {--选项=值}
    protected string $signature = 'report:generate {type=daily} {date?} {--email} {--format=json}';
    protected string $description = '生成指定类型的报告';

    public function handle(): int
    {
        $type = $this->argument('type');              // 获取参数
        $date = $this->argument('date', date('Y-m-d'));
        $format = $this->option('format', 'json');    // 获取选项

        if ($this->hasOption('email')) {
            $this->info("Sending {$type} report via email...");
        }

        $this->info("Generating {$type} report for {$date} as {$format}");

        return 0;  // 0 = 成功，非 0 = 失败
    }
}

// 注册到 Console
$console = new \core\console\Console('MyApp', '1.0');
$console->register(new ReportCommand());
$console->run($argv);
```

### 27.3 CLI 输出辅助方法

```php
$this->info('正常信息');     // 绿色文字
$this->error('错误信息');    // 红色文字
$this->warn('警告信息');     // 黄色文字
$this->line('普通文字');     // 普通白色文字
$this->table($headers, $rows); // ASCII 表格输出
```

---

## 28. Facade 与 ServiceProvider

### 28.1 Facade 门面

通过门面可以用静态方法优雅地访问容器中的服务：

```php
use core\Facade;

class Cache extends Facade
{
    // 告诉门面：我的后台服务在容器中的 ID 是 'cache'
    protected static function getFacadeAccessor(): string
    {
        return 'cache';
    }
}

// 现在可以像这样静态调用：
Cache::set('key', 'value');
$value = Cache::get('key', 'default');
Cache::delete('key');
Cache::clear();

// 等价于：
// $app->getContainer()->get('cache')->set('key', 'value');
```

### 28.2 ServiceProvider 服务提供者

服务提供者在框架启动时注册服务，分离关注点：

```php
use core\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    // 注册阶段 — 把服务绑定到容器
    public function register(): void
    {
        // 单例绑定支付服务
        $this->app->singleton('payment', function($container) {
            return new PaymentService(
                $container->get('config')['payment'] ?? []
            );
        });
    }

    // 引导阶段 — 所有服务注册完后，做初始化
    public function boot(): void
    {
        // 注册事件监听
        $events = $this->application->getEvents();
        $events->listen('order.placed', [OrderHandler::class, 'handle']);
    }
}

// 在 Application 中注册：
$app->registerProvider(new AppServiceProvider($app->getContainer()));
$app->bootProviders();
```

> 💡 **register() vs boot()**：
> - `register()` 中只做服务绑定（把类/闭包放进容器），不要使用可能还没注册的服务
> - `boot()` 在所有提供者都注册完毕后执行，可以安全地使用任何服务和注册事件监听

---

## 29. Smarty 模板引擎（可选扩展）

为保持框架轻量，Smarty 模板引擎作为可选项，需通过 Composer 安装：

```bash
composer require smarty/smarty
```

安装后即可使用：

```php
use view\Smarty;

$smarty = new Smarty(
    VIEW_PATH . 'templates/',                // 模板目录
    STORAGE_PATH . 'cache/smarty/compile/',  // 编译缓存目录
    STORAGE_PATH . 'cache/smarty/cache/'     // 页面缓存目录
);

$smarty->assign('title', '用户列表');
$smarty->assign('users', $users);
$smarty->assign('count', count($users));

echo $smarty->fetch('user/list.tpl');
```

支持 SmartyView 布局：

```php
use view\SmartyView;

$view = new SmartyView();
$view->layout('layouts/app.tpl');  // 设置布局
$view->assign('title', '用户列表');
return $view->display('user/list.tpl', ['users' => User::all()]);
```

> 📌 模板文件使用 `.tpl` 扩展名，建议放在 `app/view/templates/` 目录。

---

## 30. 部署到生产环境

### 30.1 部署检查清单

| 检查项 | 操作 |
|--------|------|
| `app/config/app.php` → `debug` | 设为 `false` ✅ |
| `app/config/app.php` → `key` | 已修改为自定义值 ✅ |
| `app/config/database.php` | 数据库信息正确 ✅ |
| `storage/` 权限 | Web 服务器有写入权限 ✅ |
| Web 根目录 | 指向 `public/` ✅ |
| PHP 版本 | ≥ 8.0 ✅ |
| PHP 扩展 | PDO、PDO_MySQL、JSON、mbstring ✅ |
| 错误显示 | `display_errors = Off` ✅ |
| 日志 | `error_log` 已配置 ✅ |

### 30.2 Apache 配置示例

```apache
<VirtualHost *:80>
    ServerName yourdomain.com
    DocumentRoot /path/to/project/public

    <Directory /path/to/project/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

### 30.3 Nginx 配置

详见 [第 2.5 节 Nginx 配置](#25-nginx-生产环境配置)。

---

## 31. Pipeline 管道（v2.0 新增）

Pipeline 实现了洋葱模型的管道模式，请求依次穿过每一层中间件到达核心，响应再反向传回。框架的中间件系统就是基于 Pipeline 实现的。

> 💡 **Pipeline 的用途**：除了中间件，Pipeline 还可用于数据加工流水线（如文本过滤→格式化→缓存）、请求处理链等场景。

### 31.1 基本用法

```php
use core\Pipeline;

$result = (new Pipeline())
    ->send($request)                          // 传入初始数据
    ->through([                               // 定义管道层（中间件数组）
        new AuthMiddleware(),
        new ThrottleMiddleware(),
        new CorsMiddleware(),
    ])
    ->then(function($request) {               // 核心处理逻辑
        return new Response('OK');
    });
```

### 31.2 自定义管道方法

默认每层管道调用 `handle()` 方法，可通过 `via()` 修改：

```php
$result = (new Pipeline())
    ->send($data)
    ->through([$filter1, $filter2])
    ->via('process')                          // 调用每层的 process() 方法
    ->then(function($data) {
        return $data;
    });
```

### 31.3 thenReturn 方法

不需要指定最终闭包时使用，最内层直接返回传入的数据：

```php
$result = (new Pipeline())
    ->send($data)
    ->through($pipes)
    ->thenReturn();                           // 返回管道处理后的结果
```

---

## 32. Seeder 数据填充（v2.0 新增）

Seeder 用于向数据库填充初始数据或测试数据，常用于开发环境和自动化测试。

### 32.1 创建 Seeder

```php
use db\Seeder;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $this->db->table('users')->insert([
            'username' => 'admin',
            'email'    => 'admin@example.com',
            'password' => \core\Hash::make('password'),
        ]);
    }
}
```

### 32.2 注册和运行

```php
use db\Seeder;
use db\Connection;

$db = new Connection([...]);

// 注册 Seeder 类
Seeder::register(UserSeeder::class);
Seeder::register(PostSeeder::class);

// 运行所有已注册的 Seeder
Seeder::runAll($db);
```

### 32.3 在 Seeder 中调用其他 Seeder

```php
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(UserSeeder::class);    // 先填充用户
        $this->call(PostSeeder::class);    // 再填充文章
    }
}
```

---

## 附录

### A. 快速命令参考

```bash
# 开发
php bin/console serve [port] [--host host]

# 测试
php bin/console test

# 代码生成
php bin/console make:model <Name>
php bin/console make:controller <Name>
php bin/console make:middleware <Name>
php bin/console make:migration <Name> <table>

# 数据库迁移
php bin/console migrate
php bin/console migrate:rollback [steps]

# 缓存清理
php bin/console cache:clear

# 查看配置
php bin/console config
php bin/console config:show app
```

### B. 推荐学习路径

1. **[第 1 章](#1-概述)** — 了解框架的设计理念和系统要求
2. **[第 2 章](#2-配置管理)** — 配置数据库和启动开发服务器
3. **[第 4 章](#4-路由系统)** + **[第 5 章](#5-控制器)** — 掌握请求处理流程
4. **[第 6 章](#6-模型与数据库)** — 学习数据库操作和 ORM
5. **[第 7 章](#7-视图与模板)** + **[第 25 章](#25-blade-模板引擎)** — 掌握模板渲染
6. **[第 8 章](#8-中间件)** — 学习请求拦截和处理
7. 其余章节按需查阅

---

文档完成！欢迎提交 issue 反馈文档问题或功能建议。