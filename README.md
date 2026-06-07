# LightPHP Framework

轻量级 PHP 框架，灵感来自 ThinkPHP 和 Laravel。支持 PHP 8.0+，完全兼容8.4和8.5，适合生产环境使用。

**核心理念**：保持轻量级的同时，提供生产级功能和易用的开发体验。如果你熟悉 ThinkPHP 或 Laravel，上手本框架将非常顺利。

**版本**：v2.0.2

> 🛡️ **质量保证**：本项目已通过 5 轮系统性商用级代码审查，累计修复 **100+ 处** 安全漏洞、运行时 BUG 与接口违规问题。详见 [CHANGELOG.md](CHANGELOG.md)。

---

## 目录

- [特性总览](#特性总览)
- [环境要求](#环境要求)
- [新手入门（5 分钟上手）](#新手入门5-分钟上手)
- [项目目录结构](#项目目录结构)
- [路由系统](#路由系统)
- [控制器](#控制器)
- [ORM 模型与数据库](#orm-模型与数据库)
  - [模型定义与基础 CRUD](#模型定义与基础-crud)
  - [查询构建器（QueryBuilder）](#查询构建器querybuilder)
  - [关联关系（Relations）](#关联关系relations)
  - [Schema Builder 与数据库迁移](#schema-builder-与数据库迁移)
- [集合类（Collection）](#集合类collection)
- [模板引擎](#模板引擎)
  - [原生 PHP 模板](#原生-php-模板)
  - [Blade 风格模板](#blade-风格模板)
  - [Smarty 模板（可选）](#smarty-模板可选)
- [中间件](#中间件)
- [事件系统](#事件系统)
- [服务提供者与门面](#服务提供者与门面)
- [CLI 命令行](#cli-命令行)
- [容器与依赖注入](#容器与依赖注入)
- [安全相关](#安全相关)
- [测试](#测试)
- [PSR 兼容性](#psr-兼容性)
- [开发文档](#开发文档)
- [常见问题排查](#常见问题排查)
- [License](#license)

---

## 特性总览

### 核心架构
- **MVC 架构** — 清晰的 Controller（控制器）/ Model（模型）/ View（视图）分层设计
- **依赖注入容器** — 自动解析类依赖，支持别名绑定和单例模式，**PSR-11 兼容**
- **中间件管道** — 洋葱模型（Onion Middleware），请求层层穿过中间件，前置/后置处理灵活
- **Pipeline 管道** — 独立的管道类，可用于中间件或其他洋葱模型场景
- **服务提供者** — 模块化的服务注册与引导机制，延迟加载，按需初始化
- **异常处理器** — 报告/渲染分离，`$dontReport` 忽略列表，调试/生产双模式

### 路由与请求
- **路由系统** — RESTful 风格路由、路由分组（group）、参数绑定、中间件链
- **中间件别名/组** — 支持中间件别名映射、分组注册、全局中间件
- **请求对象** — 封装 `$_GET`/`$_POST`/`$_FILES`/`$_SERVER` 等，提供安全便捷的输入获取方法
- **请求类型过滤** — `string()`/`integer()`/`float()`/`boolean()`/`arrayInput()` 类型安全获取
- **Macroable 宏** — Request/Response 支持运行时动态扩展方法

### 数据库与模型
- **查询构建器（QueryBuilder）** — 链式调用构建 SQL，安全参数绑定，防止 SQL 注入
- **ActiveRecord 模型** — 类似 Laravel Eloquent 的模型系统，属性访问、类型转换、JSON 序列化
- **访问器/修改器** — `getFooAttribute()`/`setFooAttribute()` 自定义属性读写逻辑
- **查询作用域** — `scopePopular()` 等方法封装常用查询条件
- **模型事件** — creating/created/updating/updated/saving/saved/deleting/deleted 8种事件
- **模型观察者** — Observer 类自动检测事件方法，统一管理模型生命周期钩子
- **软删除** — SoftDelete trait，`withTrashed()`/`onlyTrashed()`/`restore()` 完整软删除支持
- **关联关系** — hasOne / hasMany / belongsTo / belongsToMany 四种完整关联，支持预加载
- **Schema Builder** — 流畅的表结构定义 API（Laravel Blueprint 风格），支持建表、改表、外键
- **数据库迁移** — 团队协作的数据库版本管理（up/down/rollback）
- **数据填充（Seeder）** — 种子数据注册与批量执行

### 模板引擎
- **原生 PHP 视图** — 高性能的原生 PHP 模板，支持布局继承
- **Blade 风格编译器** — `{{ }}` 输出 + `@if`/`@foreach`/`@extends` 等指令，带缓存机制
- **Smarty（可选）** — 通过 Composer 按需安装，保持框架轻量

### 安全与校验
- **CSRF 防护中间件** — 自动生成令牌、验证 POST 请求
- **CORS 中间件** — 跨域请求控制，Origin 安全校验
- **速率限制（Throttle）** — 基于 IP 的请求频率限制，防止滥用
- **输入验证器** — 链式调用验证规则（required/email/min/max 等），支持自定义错误消息
- **AES-256-GCM 加密** — 认证加密，防篡改
- **bcrypt 密码哈希**
- **路径遍历防护** — 文件上传和视图加载的安全过滤

### 辅助功能
- **事件系统** — 通配符事件、优先级排序、传播控制、订阅者模式
- **集合类（Collection）** — 40+ 数组方法链式调用（map/filter/groupBy/sortBy/pluck 等）
- **门面模式（Facade）** — 通过静态代理方便地访问容器中的服务
- **文件缓存** — JSON + HMAC 签名，防缓存投毒
- **PSR-3 兼容日志** — 8 个日志级别，日期分割，上下文插值
- **Session / Cookie** — 内置 Flash 消息、SameSite 支持
- **图片验证码** — GD 库生成的图形验证码
- **CLI 命令行** — 完整的命令注册/调度系统，内置 10+ 个实用命令

---

## 环境要求

| 项目 | 最低版本 | 推荐版本 |
|------|---------|---------|
| **PHP** | 8.0 | 8.2+ |
| **MySQL** | 5.7 | 8.0+ |
| **PHP 扩展** | PDO、PDO_MySQL、JSON、mbstring | — |
| **PHP 扩展（可选）** | GD（验证码）、OpenSSL（加密） | — |

> 💡 **提示**：框架的核心功能不需要任何 Composer 依赖即可运行。Blade 模板编译器、验证码、加密等功能已内置实现。只有使用 Smarty 模板时才需要通过 Composer 安装额外的包。

---

## 新手入门（5 分钟上手）

> 如果你是第一次使用本框架，按照以下步骤操作即可在 5 分钟内跑起来。

### 第一步：获取项目

```bash
git clone https://github.com/chydroid/lightphp.git
cd lightphp
```

> 框架自带自动加载器（`app/core/Loader.php`），无需运行 `composer install` 即可使用核心功能。

### 第二步：配置数据库

打开 `app/config/database.php`，修改为你的数据库连接信息：

```php
<?php
// app/config/database.php
return [
    'default' => 'mysql',
    'connections' => [
        'mysql' => [
            'host'     => '127.0.0.1',   // 数据库地址，本地开发通常用 127.0.0.1
            'port'     => 3306,           // 端口，MySQL 默认 3306
            'database' => 'lightphp',     // ⚠️ 改成你的数据库名称
            'username' => 'root',         // ⚠️ 改成你的数据库用户名
            'password' => '',             // ⚠️ 改成你的数据库密码
            'charset'  => 'utf8mb4',      // 推荐 utf8mb4，支持 emoji
        ],
    ],
];
```

### 第三步：配置应用密钥

打开 `app/config/app.php`，修改 `key` 字段。这个密钥用于加密和 CSRF 等安全功能：

```php
<?php
// app/config/app.php
return [
    // ⚠️ 必须修改！使用32位随机字符串，保持唯一和保密
    'key' => 'base64:your-random-32-character-string',

    // 开发环境设为 true（会显示详细错误），生产环境设为 false
    'debug' => true,

    // 其他配置...
];
```

> 💡 **生成安全密钥的技巧**：在命令行运行 `php -r "echo base64_encode(random_bytes(32));"` 即可获得一个随机密钥。

### 第四步：启动开发服务器

```bash
php bin/console serve
```

你会看到类似输出：
```
LightPHP development server started at http://localhost:8080
Press Ctrl+C to stop.
```

现在打开浏览器访问 `http://localhost:8080`，即可看到框架欢迎页面。

> 💡 **提示**：
> - 修改端口号：`php bin/console serve 3000`
> - 修改绑定主机：`php bin/console serve 8080 --host 0.0.0.0`（允许局域网访问）
> - 按 `Ctrl+C` 停止服务器

### 第五步：创建你的第一个页面

**1. 创建控制器** `app/controller/HelloController.php`：

```php
<?php
namespace controller;

use core\Controller;

class HelloController extends Controller
{
    public function index()
    {
        // 返回 JSON 响应
        return $this->json(['message' => 'Hello, LightPHP!']);
    }
}
```

**2. 添加路由** `app/route/web.php`：

```php
$router->get('/hello', [\controller\HelloController::class, 'index']);
```

**3. 访问** `http://localhost:8080/hello`

你应该看到 JSON 响应：
```json
{"message": "Hello, LightPHP!", "code": 0}
```

> 🎉 **恭喜！** 你已经成功运行了你的第一个 LightPHP 应用。接下来可以继续阅读下面的内容，深入学习路由、模型、模板等更多功能。

---

## 项目目录结构

```
lightphp/
├── app/                      # 应用核心目录（你的代码主要写在这里）
│   ├── controller/           # 控制器 — 处理请求，返回响应
│   ├── model/                # 模型 — 数据库表对应的 Active Record 类
│   ├── view/                 # 视图 — 模板文件（.php / .blade.php / .tpl）
│   ├── route/                # 路由定义文件
│   ├── middleware/            # 中间件 — 请求过滤/处理层
│   ├── core/                 # 框架核心类（一般不需要修改）
│   │   ├── console/          # CLI 命令行系统
│   │   ├── contract/         # 接口契约（PSR-3 / PSR-11 等）
│   │   └── helpers.php       # 全局辅助函数
│   ├── db/                   # 数据库层（QueryBuilder + Connection + Schema + Migration）
│   ├── cache/                # 缓存驱动
│   ├── log/                  # 日志驱动
│   ├── traits/               # Trait 复用代码
│   └── config/               # 配置文件（数据库、应用、缓存、日志等）
│
├── database/
│   └── migrations/           # 数据库迁移文件
│
├── public/                   # Web 入口目录（index.php）— 需配置 Web 服务器指向此目录
│
├── storage/                  # 存储目录（缓存、日志、编译视图等）— 需确保有写入权限
│   ├── cache/                # 文件缓存
│   ├── log/                  # 日志文件（按日期分割）
│   └── views/                # Blade 模板编译缓存
│
├── bin/                      # CLI 命令行入口
│   └── console               # 命令行调度脚本
│
├── docs/                     # 开发文档
├── tests/                    # 单元测试
├── vendor/                   # Composer 依赖（可选）
└── README.md                 # 本文件
```

> 💡 **重要提示**：
> - 你的代码主要写在 `app/controller/`、`app/model/`、`app/view/`、`app/route/` 和 `app/middleware/` 中。
> - `app/core/` 是框架核心，一般情况下不要修改。
> - 生产环境部署时，请将 Web 服务器根目录指向 `public/`。
> - `storage/` 目录需要 Web 服务器进程有写入权限。

---

## 路由系统

路由是 Web 应用的入口，定义了 URL 与处理函数的对应关系。路由文件位于 `app/route/` 目录。

### 基本路由

```php
use core\Router;
use core\Response;

$router = new Router();

// GET 请求 — 适用于获取/展示数据
$router->get('/', fn() => new Response('<h1>Welcome!</h1>'));

// POST 请求 — 适用于提交/创建数据
$router->post('/user', fn() => new Response('User created'));

// 支持多个 HTTP 方法
$router->map(['GET', 'POST'], '/form', [FormController::class, 'handle']);

// 参数路由 — {id} 会被捕获为路由参数
$router->get('/user/{id}', function($id) {
    return new Response("User ID: $id");
});

// 多个参数
$router->get('/post/{year}/{month}', function($year, $month) {
    return new Response("Archive: $year-$month");
});
```

### 路由分组

当一组路由有相同的 URL 前缀或中间件时，使用路由分组可以避免重复书写：

```php
// 分组：统一设置路径前缀和中间件
$router->group([
    'prefix' => '/api',           // URL 前缀：所有子路由都以 /api 开头
    'middleware' => ['auth']      // 中间件：组内所有路由都会经过这些中间件
], function($router) {
    // 实际 URL: GET /api/users
    $router->get('/users', [UserController::class, 'index']);

    // 实际 URL: POST /api/users
    $router->post('/users', [UserController::class, 'store']);

    // 嵌套分组：实际 URL = /api/admin/settings
    $router->group(['prefix' => '/admin'], function($r) {
        $r->get('/settings', [AdminController::class, 'settings']);
    });
});
```

### 中间件路由

可以为单条路由绑定中间件：

```php
// 先绑定中间件，再定义路由方法和路径
$router->middleware('auth')->get('/profile', [ProfileController::class, 'show']);
$router->middleware('log')->get('/debug', fn() => 'ok');

// 绑定多个中间件
$router->middleware(['auth', 'admin'])->get('/admin/dashboard', fn() => 'Admin');
```

> 💡 **路由工作原理**：
> 1. 框架接收请求 URL，与已注册的路由逐一匹配。
> 2. 找到匹配的路由后，提取 URL 参数（如 `{id}` 部分）。
> 3. 依次执行路由绑定的中间件（洋葱模型，先入后出）。
> 4. 最后执行路由处理函数（控制器方法或闭包），返回响应。

---

## 控制器

控制器负责处理具体的业务逻辑。建议将控制器放在 `app/controller/` 目录下，并继承 `core\Controller` 基类。

### 基础控制器

```php
<?php
namespace controller;

use core\Controller;
use core\Request;
use model\User;

class UserController extends Controller
{
    // 列表页
    public function index()
    {
        $users = User::all();
        return $this->json(collect($users)->pluck('name'));
    }

    // 详情
    public function show($id)
    {
        $user = User::find($id);
        if (!$user) {
            return $this->error('User not found', 404);
        }
        return $this->json($user->toArray());
    }

    // 创建
    public function store(Request $request)
    {
        $data = $request->only(['name', 'email']);
        User::create($data);
        return $this->success($data, 'Created');
    }

    // 更新
    public function update(Request $request, $id)
    {
        $user = User::find($id);
        $user->name = $request->input('name');
        $user->save();
        return $this->success(null, 'Updated');
    }

    // 删除
    public function destroy($id)
    {
        User::deleteById($id);
        return $this->success(null, 'Deleted');
    }
}
```

### 控制器基类提供的响应方法

| 方法 | 说明 | 示例 |
|------|------|------|
| `$this->json($data, $code)` | 返回 JSON 响应 | `$this->json(['id' => 1])` |
| `$this->success($data, $msg)` | 返回成功 JSON | `$this->success($user, 'Created')` |
| `$this->error($msg, $code)` | 返回错误 JSON | `$this->error('Not found', 404)` |
| `$this->view($template, $data)` | 返回 HTML 视图 | `$this->view('user/list', $data)` |
| `$this->redirect($url)` | 重定向 | `$this->redirect('/login')` |

### Request 对象的常用方法

```php
// 获取输入数据
$name = $request->input('name');                // 获取单个字段
$name = $request->input('name', 'default');     // 带默认值
$all = $request->all();                          // 获取所有输入
$fields = $request->only(['name', 'email']);    // 获取指定字段
$fields = $request->except(['password']);       // 排除指定字段

// 判断
$isPost = $request->isPost();                    // 是否 POST 请求
$isGet = $request->isGet();                      // 是否 GET 请求
$has = $request->has('name');                    // 是否有某字段
$isAjax = $request->isAjax();                    // 是否 AJAX 请求

// 获取上传的文件
$file = $request->file('avatar');                // 获取上传文件信息
```

---

## ORM 模型与数据库

### 模型定义与基础 CRUD

模型（Model）对应数据库中的一张表。每个模型类继承 `model\Model`：

```php
<?php
namespace model;

class User extends Model
{
    // 表名 — 如果不指定，默认使用类名的蛇形复数形式
    protected string $table = 'users';

    // 主键 — 默认是 'id'
    protected string $primaryKey = 'id';

    // 可填充字段（白名单）— 只有这些字段可以通过 create() 和 fill() 批量赋值
    protected array $fillable = ['name', 'email', 'password'];

    // 隐藏字段 — 在 toArray() 和 toJson() 输出时自动过滤掉
    protected array $hidden = ['password'];

    // 类型转换 — 取值时自动转为指定类型
    protected array $casts = [
        'status' => 'int',           // 转为整数
        'created_at' => 'datetime',  // 转为 DateTime 对象
    ];
}
```

#### 基础 CRUD 操作

```php
// 【查】按主键查找
$user = User::find(1);
echo $user->name;               // 属性方式访问字段
echo $user->email;

// 【查】条件查询
$users = User::where('status', '=', 1)
    ->orderBy('id', 'DESC')
    ->limit(10)
    ->fetchAll();

// 【查】分页查询
$result = User::paginate(15, 1);
// 返回: ['items' => [...], 'total' => 100, 'per_page' => 15, 'current_page' => 1, 'last_page' => 7, 'has_more' => true]

// 【增】创建记录
$user = User::create([
    'name'  => 'John',
    'email' => 'john@example.com',
]);

// 【改】修改并保存
$user = User::find(1);
$user->name = 'Jane';
$user->save();

// 【删】通过主键删除
User::deleteById(1);

// 模型输出
$array = $user->toArray();     // 转为数组（hidden 字段自动过滤）
$json  = $user->toJson();      // 转为 JSON 字符串
// $user->status 已被转为 int 类型
```

> 💡 **`$fillable` 的作用**：防止"批量赋值漏洞"。只有列在 `$fillable` 中的字段才能通过 `create()` 批量写入。不在列表中的字段（如 `is_admin`）即使出现在输入数据中也会被忽略。

> 💡 **`$hidden` 的作用**：保护敏感字段。`password` 等字段在 `toArray()` 和 `toJson()` 输出时会自动隐藏，防止 API 响应中泄露。

### 查询构建器（QueryBuilder）

除了通过模型查询，也可以直接使用查询构建器进行更灵活的数据库操作：

```php
use db\Connection;

$db = new Connection(config: [...]);   // 或通过容器获取: $app->get('db')
$qb = $db->table('users');

// 基础查询
$all = $qb->fetchAll();                            // 获取全部
$first = $qb->where('id', '=', 1)->first();       // 获取第一条

// 条件查询
$qb->where('status', '=', 1)
   ->where('role', 'LIKE', '%admin%')
   ->orderBy('id', 'DESC')
   ->limit(10)
   ->fetchAll();

// WHERE 子句的多种变体
$qb->whereIn('id', [1, 2, 3])                    // WHERE id IN (1,2,3)
   ->whereNull('deleted_at')                      // WHERE deleted_at IS NULL
   ->whereNotNull('email')                        // WHERE email IS NOT NULL
   ->whereBetween('age', 18, 65)                  // WHERE age BETWEEN 18 AND 65
   ->whereOr(['name' => 'John', ['role', 'admin']]) // (name='John' OR role='admin')

// JOIN 查询
$qb->join('profiles', 'users.id', '=', 'profiles.user_id')
   ->leftJoin('orders', 'users.id', '=', 'orders.user_id')
   ->select('users.*, profiles.avatar')
   ->fetchAll();

// 聚合函数
$count = $qb->count();                            // COUNT(*)
$count = $qb->count('id');                        // COUNT(id)
$sum   = $qb->sum('amount');                      // SUM(amount)
$avg   = $qb->avg('score');                       // AVG(score)
$max   = $qb->max('id');                          // MAX(id)
$min   = $qb->min('id');                          // MIN(id)

// 分组与聚合筛选
$db->table('orders')
   ->groupBy('user_id')
   ->having('total', '>', 100)
   ->fetchAll();

// INSERT
$id = $db->table('users')->insert([
    'name' => 'John',
    'email' => 'john@example.com',
]);

// UPDATE（⚠️ 必须带 WHERE 条件，防止误更新全表）
$db->table('users')->where('id', '=', 1)->update(['name' => 'Jane']);

// DELETE（⚠️ 必须带 WHERE 条件，防止误删除全表）
$db->table('users')->where('id', '=', 1)->delete();

// 原生 SQL
$results = $db->query('SELECT * FROM users WHERE status = ?', [1]);
$count   = $db->execute('UPDATE users SET status = 0 WHERE id = ?', [1]);

// 事务（Transaction）
$db->beginTransaction();
try {
    $db->table('users')->where('id', '=', 1)->update(['balance' => 100]);
    $db->table('logs')->insert(['message' => 'Updated', 'user_id' => 1]);
    $db->commit();
} catch (\Exception $e) {
    $db->rollback();
    throw $e;
}
```

> ⚠️ **安全提醒**：UPDATE 和 DELETE 操作如果没有指定 WHERE 条件，框架会抛出 `RuntimeException`，这是为了保护数据不被误操作。

### 关联关系（Relations）

使用关联可以方便地获取模型之间的关系数据：

```php
<?php
namespace model;

class User extends Model
{
    // 【一对一】User 有一个 Profile
    public function profile()
    {
        return $this->hasOne(Profile::class, 'user_id');
    }

    // 【一对多】User 有多个 Post
    public function posts()
    {
        return $this->hasMany(Post::class, 'user_id');
    }
}

class Post extends Model
{
    // 【反向一对多】Post 属于 User
    public function author()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // 【多对多】Post 有多个 Tag（通过 post_tag 中间表关联）
    public function tags()
    {
        return $this->belongsToMany(Tag::class, 'post_tag');
    }
}

// ===== 使用关联 =====

// 方法调用 — 获取关联数据
$user    = User::find(1);
$profile = $user->profile();   // 返回单个 Profile 对象
$posts   = $user->posts();     // 返回 Post 数组
$author  = Post::find(1)->author(); // 反向获取作者
$tags    = Post::find(1)->tags();   // 获取所有标签

// 预加载（Eager Loading）— 解决 N+1 查询问题
// 不使用预加载时，循环中每个 $user->posts 会执行一次 SQL（N+1 问题）
// 使用 with() 预加载后，只需 2 次 SQL 即可获取所有关联数据
$users = User::with('posts')->fetchAll();
foreach ($users as $user) {
    $posts = $user->posts; // ⚡ 直接从预加载的数据中获取，不会重复查询
}
```

> 💡 **什么是 N+1 问题？** 假设有 100 个用户，如果不预加载，循环中调用 `$user->posts()` 会产生 1 次查用户 + 100 次查帖子 = 101 次 SQL。使用 `with('posts')` 预加载后只需 2 次 SQL（1 次查用户 + 1 次查所有用户的帖子）。

### Schema Builder 与数据库迁移

Schema Builder 用于以代码的形式定义数据表结构，迁移（Migration）用于团队协作时的数据库版本管理。

#### Schema Builder API

```php
use db\Schema;

// 建表
Schema::create('users', function($t) {
    $t->id();                                        // bigint PRIMARY KEY AUTO_INCREMENT
    $t->string('name', 100);                         // VARCHAR(100) NOT NULL
    $t->string('email')->unique();                   // VARCHAR(255) UNIQUE
    $t->string('password');
    $t->integer('age')->nullable();                  // INT NULL
    $t->decimal('balance', 10, 2)->default(0.00);    // DECIMAL(10,2) DEFAULT 0.00
    $t->boolean('is_active')->default(true);         // TINYINT(1) DEFAULT 1
    $t->text('bio')->nullable();                     // TEXT NULL
    $t->timestamps();                                // created_at + updated_at
    $t->softDeletes();                               // deleted_at（软删除）
});

// 修改表
Schema::table('users', function($t) {
    $t->string('phone', 20)->nullable()->after('email');  // 在 email 之后添加
    $t->dropColumn('old_field');                           // 删除字段
    $t->renameColumn('old_name', 'new_name');              // 重命名字段
});

// 常用列类型方法
// $t->id()               自增主键
// $t->string('col', 255) VARCHAR
// $t->integer('col')     INT
// $t->bigInteger('col')  BIGINT
// $t->decimal('col', 10, 2) DECIMAL
// $t->boolean('col')     TINYINT(1)
// $t->text('col')        TEXT
// $t->date('col')        DATE
// $t->datetime('col')    DATETIME
// $t->timestamp('col')   TIMESTAMP
// $t->enum('col', [])    ENUM
// $t->json('col')        JSON

// 修饰方法
// $t->nullable()         允许 NULL
// $t->default($value)    设置默认值
// $t->unique()           添加唯一索引
// $t->after('col')       在指定列之后添加
// $t->comment('desc')    添加注释

// 外键
$t->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

// 多态关联（用于 comments 等场景）
$t->morphs('commentable');  // 自动创建 commentable_id + commentable_type

// 表操作
Schema::hasTable('users');                       // 检查表是否存在
Schema::hasColumn('users', 'email');             // 检查列是否存在
Schema::rename('old_table', 'new_table');        // 重命名表
Schema::truncate('users');                       // 清空表
Schema::drop('users');                           // 删除表
Schema::dropIfExists('users');                   // 如果存在则删除
```

#### 创建并运行迁移

```bash
# 创建迁移文件
php bin/console make:migration create_users users

# 执行所有待迁移文件
php bin/console migrate

# 回滚迁移（最近一批）
php bin/console migrate:rollback

# 回滚最近 3 批
php bin/console migrate:rollback 3
```

迁移文件示例（`database/migrations/20250101000000_create_users.php`）：

```php
<?php
class CreateUsers_20250101000000
{
    private \PDO $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function up(): void
    {
        // 迁移执行：创建表
        \db\Schema::setConnection($this->pdo)->create('users', function($t) {
            $t->id();
            $t->string('name', 100);
            $t->string('email')->unique();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        // 迁移回滚：删除表
        \db\Schema::setConnection($this->pdo)->dropIfExists('users');
    }
}
```

---

## 集合类（Collection）

Collection 对数组进行了面向对象的封装，提供链式调用的数据处理方法：

```php
// collect() 辅助函数将数组包装为 Collection 对象
$users = collect(User::all());

// 提取单列
$names = $users->pluck('name');       // ['John', 'Jane', ...]

// 条件过滤
$active  = $users->where('status', 1);  // 保留 status=1 的
$filter  = $users->filter(fn($u) => $u['age'] > 18);

// 分组
$grouped = $users->groupBy('role');   // 按 role 分组

// 排序
$sorted = $users->sortBy('created_at');     // 升序
$latest = $users->sortByDesc('id');         // 降序

// 统计
$sum = $users->sum('points');         // 求和
$avg = $users->avg('age');            // 平均值
$max = $users->max('score');          // 最大值
$min = $users->min('score');          // 最小值

// 取子集
$first5 = $users->take(5);            // 取前 5 个
$rest   = $users->skip(10)->take(5);  // 跳过前 10 个，取 5 个
$first  = $users->first();            // 取第一个
$last   = $users->last();             // 取最后一个

// 判断
$isEmpty = $users->isEmpty();         // 是否为空
$has = $users->contains('John');      // 是否包含某值

// 值转换
$upper = $users->map(fn($u) => strtoupper($u['name']));
$indexed = $users->keyBy('id');       // 以 id 为键重新索引
$unique  = $users->unique();           // 去重

// 输出
$array = $users->values()->toArray(); // 重置索引并转数组
$json  = $users->toJson();            // 转为 JSON

// 中间操作
$users->tap(function($collection) {
    // 不修改集合，执行副作用（如打日志）
    \log\Logger::info('Processing ' . $collection->count() . ' users');
})->map(fn($u) => $u['name']);

// 链式组合
$result = collect(User::all())
    ->filter(fn($u) => $u['status'] === 1)
    ->sortByDesc('score')
    ->take(10)
    ->pluck('name')
    ->toJson();
```

> 💡 **Collection 是惰性的吗？** 不是。每次调用方法都会返回一个新的 Collection 对象。如果你只取前 10 条，建议先用 SQL LIMIT 限制数据量，再包装为 Collection 进行二次处理。

---

## 模板引擎

LightPHP 支持三种模板方式：原生 PHP（性能最高）、Blade 风格（功能最全）、Smarty（可选扩展）。

### 原生 PHP 模板

最直接、性能最好的方式。模板文件位于 `app/view/` 目录：

```php
// 控制器中
public function list()
{
    $users = User::all();
    return $this->view('user/list', ['title' => 'Users', 'users' => $users]);
}

// 视图中访问传入的变量
// <?php echo $title; ?>  →  "Users"
// <?php foreach ($users as $user): ?>  ...  <?php endforeach; ?>
```

### Blade 风格模板

使用 `.blade.php` 后缀的文件会自动被 Blade 编译器处理，编译结果缓存到 `storage/views/` 以提高性能：

#### 输出

```blade
{{-- 自动 HTML 转义，防止 XSS 攻击 --}}
<p>{{ $user->name }}</p>

{{-- 原始 HTML 输出（⚠️ 注意 XSS 风险） --}}
{!! $htmlContent !!}

{{-- 输出 JSON --}}
@json($data)

{{-- CSRF 令牌字段 --}}
@csrf
```

#### 条件判断

```blade
@if(count($users) > 0)
    <p>共有 {{ count($users) }} 个用户</p>
@elseif(count($users) === 0)
    <p>暂无用户</p>
@else
    <p>数据异常</p>
@endif

{{-- 快捷判空 --}}
@empty($users)
    <p>暂无数据</p>
@endempty
```

#### 循环

```blade
@foreach($users as $user)
    <div class="user-card">
        <h3>{{ $user->name }}</h3>
        <p>{{ $user->email }}</p>
    </div>
@endforeach

@for($i = 0; $i < 10; $i++)
    <span>{{ $i }}</span>
@endfor

@while($condition)
    {{-- ... --}}
@endwhile

{{-- 循环中获取 $loop 变量（索引、奇偶等） --}}
{{--
$loop->index      # 从 0 开始的索引
$loop->iteration  # 从 1 开始的迭代次数
$loop->first      # 是否第一个
$loop->last       # 是否最后一个
$loop->even       # 是否偶数次
$loop->odd        # 是否奇数次
--}}
```

#### 布局继承

这是 Blade 最强大的特性之一。定义布局模板，然后在子页面中填充内容块：

```blade
{{-- layout.blade.php — 布局模板 --}}
<!DOCTYPE html>
<html>
<head>
    <title>@yield('title', 'LightPHP')</title>
</head>
<body>
    <header>@yield('header')</header>

    <main>
        @yield('content')
    </main>

    <footer>@yield('footer')</footer>
</body>
</html>

{{-- user/list.blade.php — 继承布局 --}}
@extends('layout')

@section('title', '用户列表')

@section('content')
    @foreach($users as $user)
        <div class="user">
            <h3>{{ $user->name }}</h3>
        </div>
    @endforeach
@endsection
```

#### 包含子视图

```blade
{{-- 包含头部组件 — 子视图中可以访问当前所有变量 --}}
@include('partials.header')

{{-- 传入额外变量 --}}
@include('partials.sidebar', ['menu' => 'admin'])

{{-- 包含视图，变量不存在时不报错 --}}
@includeIf('optional.widget', ['data' => []])

{{-- each 指令：对每个元素渲染一个子视图 --}}
@each('partials.user-card', $users, 'user')
```

#### 自定义指令

```php
use view\Blade;

// 注册 @datetime 指令
Blade::directive('datetime', fn($expr) => "<?= date('Y-m-d H:i', strtotime({$expr})) ?>");

// 注册 @uppercase 指令
Blade::directive('uppercase', fn($expr) => "<?= strtoupper({$expr}) ?>");
```

模板中使用：
```blade
<p>注册时间：@datetime($user->created_at)</p>
<p>姓名大写：@uppercase($user->name)</p>
```

#### Blade 可用指令速查

| 指令 | 说明 | 示例 |
|------|------|------|
| `{{ $var }}` | HTML 转义输出 | `{{ $name }}` |
| `{!! $html !!}` | 原始输出（注意 XSS） | `{!! $trusted !!}` |
| `@if / @elseif / @else / @endif` | 条件判断 | — |
| `@foreach / @endforeach` | 循环遍历 | — |
| `@for / @endfor` | for 循环 | — |
| `@while / @endwhile` | while 循环 | — |
| `@extends('layout')` | 继承布局 | — |
| `@section / @endsection` | 定义内容块 | — |
| `@yield('name')` | 渲染内容块 | — |
| `@include('view')` | 包含子视图 | — |
| `@csrf` | CSRF 令牌字段 | — |
| `@json($data)` | JSON 编码输出 | — |
| `@empty / @endempty` | 判断变量为空 | — |
| `@isset / @endisset` | 变量是否定义 | — |
| `@php ... @endphp` | 原生 PHP 代码 | — |

---

### Smarty 模板（可选）

Smarty 是一个成熟的 PHP 模板引擎。为了保持框架轻量，Smarty 不在默认依赖中，需要时通过 Composer 单独安装：

```bash
composer require smarty/smarty
```

安装后即可使用：

```php
use view\Smarty;

$smarty = new Smarty(
    VIEW_PATH . 'templates/',                // 模板目录
    STORAGE_PATH . 'cache/smarty/compile/',  // 编译缓存
    STORAGE_PATH . 'cache/smarty/cache/'     // 页面缓存
);

$smarty->assign('title', 'Hello');
$smarty->assign('users', $users);
echo $smarty->fetch('user/list.tpl');
```

SmartyView 支持布局和区块功能：

```php
use view\SmartyView;

$view = new SmartyView();
$view->layout('layouts/app.tpl');
return $view->display('user/list.tpl', ['users' => $users]);
```

> 💡 模板文件使用 `.tpl` 扩展名，建议放在 `app/view/templates/` 目录下。

---

## 中间件

中间件在请求到达控制器之前/之后执行，用于实现认证、日志、CORS、限流等功能。使用洋葱模型（Onion Middleware），中间件层层包裹：

```
请求 → [中间件A → [中间件B → [控制器] → 中间件B] → 中间件A] → 响应
```

### 编写自定义中间件

```php
<?php
namespace middleware;

use core\Response;

class Auth
{
    /**
     * @param mixed $request     请求对象
     * @param callable $next     下一个处理者（下一个中间件或控制器）
     * @return mixed             响应
     */
    public function handle($request, callable $next)
    {
        // ===== 前置处理（控制器执行之前） =====
        if (!isset($_SESSION['user_id'])) {
            return new Response('Unauthorized', 401);
        }

        // ===== 调用下一个处理者 =====
        $response = $next($request);

        // ===== 后置处理（控制器执行之后） =====
        // 可以在此修改响应，如添加额外的 Header
        // $response->header('X-Auth-User', $userId);

        return $response;
    }
}
```

### 内置中间件

#### CORS 跨域中间件

处理跨域资源共享（Cross-Origin Resource Sharing）：

```php
use middleware\Cors;

$cors = new Cors([
    'allowed_origins'     => ['https://example.com'], // 允许的来源域名
    'allowed_methods'     => ['GET', 'POST', 'PUT', 'DELETE'], // 允许的 HTTP 方法
    'allowed_headers'     => ['Content-Type', 'Authorization'], // 允许的请求头
    'exposed_headers'     => [],                      // 暴露给客户端的响应头
    'max_age'             => 3600,                    // 预检请求缓存时间（秒）
    'supports_credentials' => true,                   // 是否允许携带 Cookie
]);
```

> ⚠️ **安全提醒**：生产环境中请务必将 `allowed_origins` 限制为具体的前端域名，不要使用 `*`。

#### Throttle 限流中间件

限制单个 IP 的请求频率，防止恶意请求：

```php
use middleware\Throttle;

// 60 次请求 / 60 秒（即每分钟最多 60 次请求）
$throttle = new Throttle(60, 60);

// 超过限制时自动返回 429 Too Many Requests，响应头中包含 Retry-After
```

#### CSRF 中间件

保护 POST/PUT/DELETE 请求免受跨站请求伪造攻击：

```php
use middleware\CsrfMiddleware;

// 自动为每个会话生成 CSRF 令牌
// 在前端表单中使用 @csrf 指令输出隐藏字段
// 中间件自动验证提交的令牌
$csrf = new CsrfMiddleware();

// 排除某些不需要验证的路由（如 webhook 回调）
$csrf->except(['/webhook/payment']);
```

---

## 事件系统

事件系统允许你在应用的某些节点触发事件，由其他代码监听并响应，实现模块间的解耦：

```php
use core\EventDispatcher;

$events = new EventDispatcher();

// 监听事件 — 当 user.created 被触发时执行回调
$events->listen('user.created', function($event, $data) {
    // $event = 'user.created'（事件名）
    // $data  = ['name' => 'John', 'email' => '...']（触发时传入的数据）
    \log\Logger::info("User {$data['name']} created");
});

// 通配符监听 — 匹配所有 user.* 的事件
$events->listen('user.*', function($event, $data) {
    \log\Logger::debug("User event: {$event}");
});

// 带优先级监听 — 数字越小越先执行
$events->listen('order.paid', [SendEmailHandler::class, 'handle'], 10);
$events->listen('order.paid', [UpdateInventory::class, 'handle'], 20);

// 触发事件
$events->dispatch('user.created', ['name' => 'John', 'email' => 'john@example.com']);

// until — 获得第一个非 null 的返回值后就停止
$result = $events->until('find.handler');

// 订阅者模式 — 一个类中注册多个事件
$events->subscribe($eventSubscriber);
```

> 💡 **典型应用场景**：用户注册后发送欢迎邮件、写入日志、更新统计数据等。这些操作通过事件系统实现，控制器只需关注注册逻辑本身。

---

## 服务提供者与门面

### 服务提供者（ServiceProvider）

服务提供者是模块化组织代码的方式，分为两个阶段：
1. **注册（register）**：向容器绑定服务，此时其他服务可能还没准备好
2. **引导（boot）**：所有服务注册完毕后，进行初始化操作（如注册事件监听）

```php
<?php
namespace provider;

use core\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    // 注册阶段：绑定服务到容器
    public function register(): void
    {
        // 绑定支付服务为单例
        $this->app->singleton('payment', function($container) {
            $config = $container->get('config');
            return new PaymentService($config['payment'] ?? []);
        });
    }

    // 引导阶段：所有服务已注册，可以进行初始化
    public function boot(): void
    {
        $events = $this->application->getEvents();
        $events->listen('order.placed', [OrderHandler::class, 'handle']);
    }
}
```

在 Application 中注册：

```php
$app->registerProvider(new AppServiceProvider($app->getContainer()));
$app->bootProviders();  // 所有提供者注册完成后，统一执行 boot()
```

### 门面（Facade）

门面提供了一个静态代理来访问容器中的服务，让调用代码更简洁：

```php
<?php
namespace facade;

use core\Facade;

class Cache extends Facade
{
    // 指定在容器中对应的服务名称
    protected static function getFacadeAccessor(): string
    {
        return 'cache';
    }
}
```

使用门面：

```php
// 静态调用实际上是转发到容器中 'cache' 服务的对应方法
Cache::set('key', $value);
echo Cache::get('key');
Cache::delete('key');
Cache::has('key');
Cache::clear();
Cache::remember('stats', 3600, fn() => computeStats());
```

> 💡 **门面 vs 直接调用**：`Cache::get('key')` 等价于 `$app->get('cache')->get('key')`。门面让代码更简洁，但你需要在应用初始化时正确设置 Facade 的容器实例。

---

## CLI 命令行

```bash
# 列出所有可用命令
php bin/console list

# 启动开发服务器
php bin/console serve                 # 默认 8080 端口
php bin/console serve 3000            # 指定端口
php bin/console serve 8080 --host 0.0.0.0  # 允许局域网访问

# 环境变量
php bin/console config                # 显示配置概览
php bin/console config:show database  # 查看数据库详细配置（密码自动隐藏）

# 缓存
php bin/console cache:clear           # 清空所有缓存文件

# 代码生成
php bin/console make:model User       # 生成模型类
php bin/console make:controller Admin\User  # 生成控制器类
php bin/console make:middleware Auth  # 生成中间件类

# 迁移
php bin/console make:migration create_products products  # 生成迁移文件
php bin/console migrate               # 执行所有待迁移的文件
php bin/console migrate:rollback      # 回滚最近一批
php bin/console migrate:rollback 3    # 回滚最近 3 批

# 测试
php bin/console test                  # 运行单元测试
```

### 编写自定义命令

```php
<?php
namespace command;

use core\console\Command;

class ReportCommand extends Command
{
    // 签名格式：命令名 {参数?} {参数=默认值} {--选项}
    // {date?} — 可选参数
    // {type=daily} — 带默认值的参数
    // {--email} — 布尔选项（有即为 true）
    // {--output=json} — 带值的选项
    protected string $signature = 'report:generate {type=daily} {date?} {--email} {--output=json}';
    protected string $description = '生成指定类型的报告';

    public function handle(): int
    {
        $type = $this->argument('type');         // 'daily'
        $date = $this->argument('date') ?? date('Y-m-d');  // 可选参数
        $output = $this->option('output', 'json'); // --output=json

        if ($this->hasOption('email')) {
            $this->info("Sending {$type} report for {$date} via email...");
        } else {
            $this->info("Generating {$type} report for {$date} as {$output}...");
        }

        // 返回 0 表示成功，非 0 表示失败
        return 0;
    }
}
```

> 💡 **CLI 输出辅助方法**：
> - `$this->info($msg)` — 绿色文字，表示正常信息
> - `$this->error($msg)` — 红色文字，表示错误信息
> - `$this->warn($msg)` — 黄色文字，表示警告信息
> - `$this->line($msg)` — 普通文字
> - `$this->table($headers, $rows)` — 绘制 ASCII 表格

---

## 容器与依赖注入

容器负责管理类的创建和依赖解析：

```php
use core\Container;

$container = new Container();

// 绑定：接口 → 实现
$container->bind(CacheInterface::class, FileCache::class);

// 单例：只创建一次，之后复用
$container->singleton('db', Connection::class);

// 别名
$container->alias('database', 'db');

// 自动解析：通过反射自动注入构造函数参数
$validator = $container->get(Validate::class);

// 手动解析带参数的类
$upload = $container->make(Upload::class, ['uploadDir' => '/path/to/uploads']);

// 检查
$container->has('db');        // true

// 移除
$container->forget('cache');  // 移除绑定

// 清空
$container->flush();          // 清空所有绑定和实例
```

### PSR-11 兼容

LightPHP 的容器实现了 PHP-FIG 的 PSR-11（Container Interface）标准接口：

```php
// PSR-11 标准方式
$container->get('service-id');    // 获取服务
$container->has('service-id');    // 检查是否存在

// 如果 id 不存在，抛出 PsrNotFoundExceptionInterface
// 如果获取过程出错，抛出 PsrContainerExceptionInterface
```

> 💡 **接口在** `app/core/contract/PsrContainerInterface.php`，`get()` 和 `has()` 方法 100% 符合 PSR-11 标准。

---

## 安全相关

LightPHP 内置了多项安全防护功能：

### AES-256-GCM 加密

GCM 模式提供认证加密（加密同时验证数据完整性，防止篡改）：

```php
use core\Hash;

// 加密
$encrypted = Hash::encrypt('sensitive-data');
// $encrypted 是一个包含 IV、密文和认证标签的字符串

// 解密
$decrypted = Hash::decrypt($encrypted);
// 如果密文被篡改，decrypt() 会抛出异常

// ⚠️ 使用前请确保 app/config/app.php 中的 'key' 已修改为自定义值
```

### bcrypt 密码哈希

用于用户密码的安全存储（不可逆单向哈希）：

```php
$hash = Hash::make('password123');         // 生成哈希
$valid = Hash::verify('password123', $hash); // 验证密码 → true
$invalid = Hash::verify('wrong', $hash);    // → false
```

### 输入验证

```php
use core\Validate;

$validator = (new Validate())->rules([
    'name'     => 'required|min:2|max:50',
    'email'    => 'required|email',
    'age'      => 'integer|min:0|max:150',
    'password' => 'required|min:6',
])->messages([
    'name.required'  => '请填写姓名',
    'email.required' => '请填写邮箱',
    'email.email'    => '邮箱格式不正确',
]);

if ($validator->validate($_POST)) {
    // 验证通过
    $validData = $validator->validated();
}

if (!$validator->validate($_POST)) {
    // 验证失败，获取错误信息
    $errors = $validator->errors();
    // $errors = ['name' => ['请填写姓名'], 'email' => ['邮箱格式不正确']]
}
```

可用的验证规则：`required` / `email` / `min:N` / `max:N` / `integer` / `string` / `array` / `in:val1,val2` / `not_in:val1,val2` / `confirmed`（确认字段，如 password_confirmation）

### CSRF 防护

前端模板中使用 `@csrf` 指令自动生成隐藏令牌字段：

```blade
<form method="POST" action="/submit">
    @csrf
    <input type="text" name="data">
    <button type="submit">提交</button>
</form>
```

后端使用 `CsrfMiddleware` 自动验证 POST/PUT/DELETE 请求中的令牌。

### 其他安全防护

- **SQL 注入防护**：查询构建器全部使用 PDO 参数绑定，不拼接用户输入
- **路径遍历防护**：文件上传和视图加载均有路径过滤，防止 `../` 目录穿越
- **Origin 校验**：CORS 中间件对 Origin 头进行格式验证，防止 CRLF 注入
- **缓存签名**：文件缓存使用 HMAC 签名，防止缓存投毒
- **安全响应头**：框架自动附加以下 HTTP 安全头：
  - `Content-Security-Policy` — 限制脚本/样式来源，防止 XSS
  - `X-Content-Type-Options: nosniff` — 禁止 MIME 嗅探
  - `X-Frame-Options: SAMEORIGIN` — 防止点击劫持
  - `X-XSS-Protection: 1; mode=block` — 启用浏览器 XSS 过滤
  - `Referrer-Policy: strict-origin-when-cross-origin` — 控制来源泄露
  > JSON/API 响应不附加 CSP 头，HTML 响应自动附加完整安全头。可通过 `$response->withoutSecurityHeaders()` 关闭。

---

## 测试

```bash
php bin/console test
```

207 个单元测试覆盖所有核心组件：

| 测试模块 | 测试数量 | 覆盖内容 |
|---------|---------|---------|
| Router | ✓ | 路由注册、参数提取、分组、中间件链 |
| Container | ✓ | 绑定、解析、单例、别名、PSR-11 接口 |
| Request / Response | ✓ | 输入获取、JSON 响应、状态码 |
| Model（含 ORM） | ✓ | CRUD、关联关系、类型转换、toArray/toJson |
| QueryBuilder | ✓ | 查询构建、参数绑定、聚合函数、安全校验 |
| Schema / Migration | ✓ | 建表建列、修饰符、外键 |
| EventDispatcher | ✓ | 监听、触发、优先级、传播控制、通配符 |
| Collection | ✓ | map/filter/pluck/sortBy/sum/avg 等 30+ 方法 |
| Blade 模板 | ✓ | 指令编译、布局继承、缓存机制 |
| Middleware | ✓ | CORS、Throttle 限流、CSRF 令牌 |
| Console / Command | ✓ | 签名解析、参数绑定、表格输出 |
| Session / Cookie | ✓ | 读写、Flash 消息、SameSite |
| Validate | ✓ | 规则校验、自定义消息、passes/fails |
| Hash / Encrypt | ✓ | bcrypt 验证、AES-256-GCM 加密解密 |
| FileCache | ✓ | 读写、过期、签名验证、增量 |
| Logger | ✓ | PSR-3 级别、上下文插值、日期分割 |
| Upload | ✓ | MIME 验证、路径安全 |
| Facade | ✓ | 代理访问、getFacadeAccessor |
| Generator | ✓ | 代码生成、文件输出 |

---

## PSR 兼容性

LightPHP 完整实现了以下 PHP-FIG（PHP Framework Interop Group）标准接口：

| 标准编号 | 标准名称 | 兼容状态 | 说明 |
|----------|---------|---------|------|
| **PSR-3** | Logger Interface | ✅ 完整兼容 | 9 个日志级别方法（emergency / alert / critical / error / warning / notice / info / debug） + `log()` 通用方法 + `Stringable` 支持 |
| **PSR-11** | Container Interface | ✅ 完整兼容 | `get($id)` 和 `has($id)` 方法 + 对应异常接口（PsrNotFoundExceptionInterface / PsrContainerExceptionInterface） |

> 💡 PSR 接口通过 `app/core/contract/` 目录下的本地实现提供，**无需引入第三方 Composer 包**，保持框架零外部依赖。

---

## 开发文档

| 文档 | 说明 | 适合人群 |
|------|------|---------|
| [**开发指南**](docs/guide.md) | 框架完整使用教程，从零开始构建应用 | 所有开发者 |
| [**API 文档**](docs/api.md) | 所有类和方法的详细 API 参考 | 日常查阅 |
| [**电商教程**](docs/ecommerce-full-tutorial.md) | 电商系统完整开发教程 | 进阶学习 |
| [**后台管理教程**](docs/admin-panel-tutorial.md) | 后台管理系统开发教程 | 进阶学习 |
| [**测试指南**](docs/testing-guide.md) | 测试编写和运行指南 | 质量保证 |

---

## 常见问题排查

### Q1：启动服务后访问 `http://localhost:8080` 显示空白页？

**原因**：PHP 错误报告未开启。

**解决**：打开 `app/config/app.php`，将 `debug` 设为 `true`：

```php
return [
    'debug' => true,   // 开启调试模式
    // ...
];
```

### Q2：数据库连接失败？

**原因**：数据库配置不正确或 MySQL 未启动。

**解决**：
1. 确认 MySQL 服务已启动
2. 检查 `app/config/database.php` 中的 host/port/database/username/password 是否正确
3. 确认数据库已创建（如 `CREATE DATABASE lightphp;`）

### Q3：模型调用 `find()` 方法报错？

**原因**：数据库表不存在或表名不匹配。

**解决**：
1. 确认数据库中存在对应的数据表
2. 检查模型中的 `$table` 属性是否与数据表名一致
3. 使用 Schema Builder 或 SQL 创建表

### Q4：路由返回 404？

**原因**：路由未正确注册或 URL 路径拼写错误。

**解决**：
1. 确认路由文件路径和命名正确（`app/route/web.php`）
2. 检查路由定义是否与请求的 URL 匹配
3. 使用 `php bin/console serve` 启动的服务器，URL 为 `http://localhost:8080/your-route`（注意端口号）

### Q5：Blade 模板修改后不生效？

**原因**：Blade 视图被缓存了。

**解决**：执行 `php bin/console cache:clear` 清除视图缓存，或直接删除 `storage/views/` 目录下的缓存文件。生产环境建议开启调试模式或设置较短的缓存时间。

### Q6：上传文件时报"不安全"错误？

**原因**：文件名包含路径遍历字符（如 `../`）。

**解决**：框架会自动过滤这些字符，确保上传的文件名安全。如果你需要保留更多字符，请修改 `Upload` 类的过滤规则。

---

## License

MIT License — 自由使用、修改和分发。

---

> 📖 **推荐学习路径**：
> 1. 先看 [新手入门（5 分钟上手）](docs/guide.md)，快速跑起来
> 2. 阅读"路由系统"和"控制器"，理解请求处理流程
> 3. 学习"ORM 模型与数据库"，掌握数据操作
> 4. 了解"模板引擎"，学会页面渲染
> 5. 深入"中间件"和"事件系统"，构建更复杂的应用
> 6. 查阅 [API 文档](docs/api.md) 了解完整功能
> 7. 参考 [电商教程](docs/ecommerce-full-tutorial.md) 和 [后台管理教程](docs/admin-panel-tutorial.md)，构建实际应用
> 8. 参与 [测试指南](docs/testing-guide.md)，编写测试用例确保代码质量
> 9. 最后是欢迎使用 LightPHP，祝你开发愉快！
> 10. 如果感觉不错的话，欢迎给本项目加星标，支持我们的开发！
