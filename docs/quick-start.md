# LightPHP v2.5.0 快速上手指南

本指南将帮助你快速上手 LightPHP v2.5.0，涵盖所有核心功能的代码示例。

---

## 目录

1. [安装与配置](#1-安装与配置)
2. [路由定义](#2-路由定义)
3. [控制器](#3-控制器)
4. [请求与响应](#4-请求与响应)
5. [数据库查询构建器](#5-数据库查询构建器)
6. [模型 ORM](#6-模型-orm)
7. [模型事件与观察者](#7-模型事件与观察者)
8. [访问器与修改器](#8-访问器与修改器)
9. [查询作用域](#9-查询作用域)
10. [软删除](#10-软删除)
11. [中间件](#11-中间件)
12. [Pipeline 管道](#12-pipeline-管道)
13. [Macroable 宏扩展](#13-macroable-宏扩展)
14. [异常处理](#14-异常处理)
15. [缓存系统](#15-缓存系统)
16. [视图与模板](#16-视图与模板)
17. [数据验证](#17-数据验证)
18. [数据库迁移与数据填充](#18-数据库迁移与数据填充)
19. [集合](#19-集合)

---

## 1. 安装与配置

```bash
git clone https://github.com/chydroid/LightPhp.git
cd LightPhp
```

复制环境配置文件并修改：

```bash
cp .env.example .env
```

编辑 `.env` 文件配置数据库和缓存：

```ini
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=lightphp
DB_USERNAME=root
DB_PASSWORD=

CACHE_DRIVER=file
APP_DEBUG=true
```

启动内置服务器：

```bash
php -S localhost:8000 -t public
```

访问 `http://localhost:8000` 即可看到欢迎页面。

---

## 2. 路由定义

路由文件位于 `app/route/web.php`：

```php
use core\Router;

/** @var Router $router */

// 基础路由
$router->get('/', function() {
    return \core\Response::make('Hello LightPHP!');
});

// 带参数的路由
$router->get('/hello/{name}', function($name) {
    return \core\Response::make("Hello, {$name}!");
});

// RESTful 路由
$router->get('/users', [UserController::class, 'index']);
$router->post('/users', [UserController::class, 'store']);
$router->get('/users/{id}', [UserController::class, 'show']);
$router->put('/users/{id}', [UserController::class, 'update']);
$router->delete('/users/{id}', [UserController::class, 'destroy']);

// 路由分组（前缀 + 中间件）
$router->group(['prefix' => 'api', 'middleware' => [Cors::class]], function($router) {
    $router->get('/posts', [PostController::class, 'index']);
    $router->post('/posts', [PostController::class, 'store']);
});

// 使用中间件别名
$router->aliasMiddleware('cors', \middleware\Cors::class);
$router->aliasMiddleware('throttle', \middleware\Throttle::class);
$router->middlewareGroup('api', ['cors', 'throttle']);

$router->group(['prefix' => 'api', 'middleware' => ['api']], function($router) {
    $router->get('/data', [ApiController::class, 'data']);
});
```

---

## 3. 控制器

控制器位于 `app/controller/`，继承 `core\Controller`：

```php
namespace controller;

use core\Controller;
use core\Request;
use model\User;

class UserController extends Controller
{
    public function index()
    {
        $users = User::paginate(15);
        return $this->success($users, '用户列表');
    }

    public function show(int $id)
    {
        $user = User::find($id);
        if (!$user) {
            return $this->notFound('用户不存在');
        }
        return $this->success($user->toArray());
    }

    public function store(Request $request)
    {
        $validator = new \core\Validate();
        if (!$validator->validate($request->all(), [
            'name'     => 'required|min:2',
            'email'    => 'required|email',
            'password' => 'required|min:6',
        ])) {
            return $this->error($validator->firstError(), 422);
        }

        $id = User::create($validator->validated());
        return $this->success(['id' => $id], '创建成功');
    }

    public function update(Request $request, int $id)
    {
        $data = $request->only(['name', 'email']);
        User::update($id, $data);
        return $this->success([], '更新成功');
    }

    public function destroy(int $id)
    {
        User::delete($id);
        return $this->success([], '删除成功');
    }
}
```

---

## 4. 请求与响应

### 请求

```php
use core\Request;

$request = new Request();

// 获取输入
$name   = $request->input('name', 'default');
$all    = $request->all();
$only   = $request->only(['name', 'email']);
$except = $request->except(['password']);

// 类型安全获取 (v2.0.0 新增)
$name   = $request->string('name');          // → string
$age    = $request->integer('age');           // → int
$price  = $request->float('price');           // → float
$active = $request->boolean('active');        // → bool
$tags   = $request->arrayInput('tags');       // → array

// 合并数据 (v2.0.0 新增)
$request->merge(['extra' => 'value']);

// 请求信息
$method = $request->method();    // GET, POST, PUT, DELETE...
$uri    = $request->uri();       // /api/users?page=1
$ip     = $request->ip();        // 127.0.0.1
$isAjax = $request->isAjax();    // true/false
```

### 响应

```php
use core\Response;

// HTML 响应
$response = Response::make('<h1>Hello</h1>');

// JSON 响应
$response = Response::json(['code' => 0, 'data' => $users]);

// 重定向
$response = Response::redirect('/login');

// 链式调用
$response = Response::json($data)
    ->header('X-Custom', 'value')
    ->status(201);
```

### 宏扩展 (v2.0.0 新增)

```php
// 运行时扩展 Request 方法
\core\Request::macro('isMobile', function() {
    $ua = $this->userAgent();
    return (bool) preg_match('/Mobile|Android|iPhone/i', $ua);
});

$request = new \core\Request();
if ($request->isMobile()) {
    // 移动端逻辑
}

// 运行时扩展 Response 方法
\core\Response::macro('noContent', function() {
    return self::make('', 204);
});

$core\Response::macro('csv', function(string $content) {
    return self::make($content)
        ->header('Content-Type', 'text/csv')
        ->header('Content-Disposition', 'attachment; filename="export.csv"');
});
```

---

## 5. 数据库查询构建器

```php
use db\Connection;

$db = new Connection($config);

// 基础查询
$users = $db->table('users')
    ->where('status', '=', 'active')
    ->orderBy('created_at', 'DESC')
    ->limit(10)
    ->fetchAll();

// 条件查询
$db->table('users')
    ->where('age', '>=', 18)
    ->whereIn('role', ['admin', 'editor'])
    ->whereNull('deleted_at')
    ->fetchAll();

// OR 条件
$db->table('users')
    ->where('status', '=', 'active')
    ->whereOr([
        ['role', '=', 'admin'],
        ['role', '=', 'editor'],
    ])
    ->fetchAll();

// JOIN 联表
$db->table('orders')
    ->select(['orders.*', 'users.name as user_name'])
    ->join('users', 'orders.user_id', '=', 'users.id')
    ->leftJoin('products', 'orders.product_id', '=', 'products.id')
    ->fetchAll();

// 聚合函数
$count = $db->table('users')->count();
$avg   = $db->table('orders')->avg('amount');
$max   = $db->table('orders')->max('amount');

// 插入
$id = $db->table('users')->insert([
    'name'  => 'John',
    'email' => 'john@example.com',
]);

// 更新（必须有 WHERE 条件）
$db->table('users')
    ->where('id', '=', 1)
    ->update(['name' => 'Jane']);

// 删除（必须有 WHERE 条件）
$db->table('users')
    ->where('id', '=', 1)
    ->delete();

// 分页
$result = $db->table('users')->paginate(15, 1);
// $result = ['items' => [...], 'total' => 100, 'per_page' => 15, 'current_page' => 1, ...]

// 查询缓存
$users = $db->table('users')
    ->where('status', '=', 'active')
    ->cache('active_users', 300)  // 缓存 5 分钟
    ->fetchAll();

// 事务
$db->beginTransaction();
try {
    $db->table('orders')->insert([...]);
    $db->table('products')->where('id', '=', $productId)->update([...]);
    $db->commit();
} catch (\Throwable $e) {
    $db->rollback();
    throw $e;
}
```

---

## 6. 模型 ORM

```php
namespace model;

use core\Controller;

class User extends Model
{
    protected string $table = 'users';
    protected array $fillable = ['name', 'email', 'password', 'status'];
    protected array $hidden = ['password'];
    protected array $casts = [
        'status'     => 'int',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
```

### 基础操作

```php
// 查询
$user  = User::find(1);
$user  = User::findBy('email', 'john@example.com');
$users = User::all();
$users = User::where('status', '=', 'active')->fetchAll();

// 创建
$id = User::create(['name' => 'John', 'email' => 'john@example.com']);

// 更新
User::update(1, ['name' => 'Jane']);

// 删除
User::delete(1);

// 分页
$result = User::paginate(15, 1);

// save() 方法 (v2.0.0 新增)
$user = new User(['name' => 'John', 'email' => 'john@example.com']);
$user->save();  // INSERT

$user = User::find(1);
$user->name = 'Jane';
$user->save();  // UPDATE
```

### 关联关系

```php
class User extends Model
{
    public function posts()
    {
        return $this->hasMany(Post::class, 'user_id', 'id');
    }

    public function profile()
    {
        return $this->hasOne(Profile::class, 'user_id', 'id');
    }
}

class Post extends Model
{
    public function author()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function tags()
    {
        return $this->belongsToMany(Tag::class, 'post_tags', 'post_id', 'tag_id');
    }
}

// 使用预加载避免 N+1
$users = User::with(['posts', 'profile'])->fetchAll();
```

---

## 7. 模型事件与观察者 (v2.0.0 新增)

### 事件监听

```php
use model\User;

// 注册事件监听器
User::onEvent('creating', function($user) {
    $user->password = \core\Hash::make($user->password);
});

User::onEvent('deleting', function($user) {
    if ($user->is_admin) {
        return false;  // 返回 false 取消删除
    }
});
```

支持的事件：`creating`、`created`、`updating`、`updated`、`saving`、`saved`、`deleting`、`deleted`

### 观察者

```php
class UserObserver
{
    public function creating($user): void
    {
        $user->password = \core\Hash::make($user->password);
    }

    public function created($user): void
    {
        // 发送欢迎邮件等
    }

    public function deleting($user): bool
    {
        // 禁止删除管理员
        return !$user->is_admin;
    }
}

// 注册观察者
User::observe(new UserObserver());
// 或传入类名自动实例化
User::observe(UserObserver::class);
```

---

## 8. 访问器与修改器 (v2.0.0 新增)

```php
class User extends Model
{
    // 访问器：读取时自动转换
    public function getNameAttribute($value): string
    {
        return ucwords($value);  // john doe → John Doe
    }

    // 修改器：写入时自动处理
    public function setEmailAttribute($value): void
    {
        $this->attributes['email'] = strtolower($value);  // 自动转小写
    }

    // 密码修改器
    public function setPasswordAttribute($value): void
    {
        $this->attributes['password'] = \core\Hash::make($value);
    }
}

// 使用
$user = new User();
$user->email = 'JOHN@EXAMPLE.COM';       // 自动转为 john@example.com
$user->password = '123456';               // 自动哈希
echo $user->name;                         // 自动首字母大写
```

---

## 9. 查询作用域 (v2.0.0 新增)

```php
class User extends Model
{
    // 定义作用域
    public function scopeActive($query)
    {
        return $query->where('status', '=', 'active');
    }

    public function scopeAdmin($query)
    {
        return $query->where('role', '=', 'admin');
    }

    public function scopeRecent($query)
    {
        return $query->orderBy('created_at', 'DESC');
    }
}

// 使用作用域
$activeUsers = User::active()->fetchAll();
$recentAdmins = User::admin()->recent()->limit(10)->fetchAll();
```

---

## 10. 软删除 (v2.0.0 新增)

> ⚠️ **重要**：软删除是**实例方法**而非静态方法。需要先获取或创建模型实例再调用。

```php
use traits\SoftDelete;

class Post extends Model
{
    use SoftDelete;
    protected string $table = 'posts';
}

// ===== 软删除（默认） =====
$post = new Post();
$post->delete(1);  // 设置 deleted_at 而非真正删除

// ===== 查询 =====
$posts = Post::all();                       // 自动排除已软删除的记录

// 包含已软删除的记录
$allPosts = (new Post())->withTrashed()->fetchAll();

// 仅查询已软删除的记录
$trashed = (new Post())->onlyTrashed()->fetchAll();

// ===== 恢复软删除 =====
$post = Post::find(1);
if ($post && $post->trashed()) {
    $post->restore();
}

// ===== 强制物理删除 =====
(new Post())->force()->delete(1);           // 真正 DELETE FROM posts WHERE id=1

// 检查是否已被软删除
$isTrashed = $post->trashed();
```

> ⚠️ **数据库要求**：使用软删除的表必须有 `deleted_at` 字段（DATETIME 类型，默认 NULL）。

---

## 11. 中间件

### 定义中间件

> 💡 **结构说明**：中间件是普通类（无需继承基类），只需要实现 `handle(Request $request, callable $next): mixed` 方法即可。中间件放在 `app/middleware/` 目录下。

```php
namespace middleware;

use core\Request;
use core\Response;

class AuthMiddleware
{
    public function handle(Request $request, callable $next): mixed
    {
        if (!isset($_SESSION['user_id'])) {
            return Response::json(['message' => '未登录'], 401);
        }
        return $next($request);
    }
}
```

### 注册中间件 (v2.0.0 增强)

```php
// 在路由文件或 Application 中注册

$router = new \core\Router();

// 中间件别名
$router->aliasMiddleware('auth', \middleware\AuthMiddleware::class);
$router->aliasMiddleware('cors', \middleware\Cors::class);
$router->aliasMiddleware('csrf', \middleware\CsrfMiddleware::class);
$router->aliasMiddleware('throttle', \middleware\Throttle::class);

// 中间件组
$router->middlewareGroup('web', ['csrf', 'auth']);
$router->middlewareGroup('api', ['cors', 'throttle']);

// 全局中间件（每个请求都执行）
$router->setGlobalMiddleware([\middleware\Cors::class]);

// 路由中使用别名
$router->group(['middleware' => ['web']], function($router) {
    $router->get('/dashboard', [DashboardController::class, 'index']);
});

$router->group(['prefix' => 'api', 'middleware' => ['api']], function($router) {
    $router->get('/data', [ApiController::class, 'data']);
});
```

---

## 12. Pipeline 管道 (v2.0.0 新增)

Pipeline 实现洋葱模型，可用于中间件或其他需要层层处理的场景：

```php
use core\Pipeline;

// 基本使用
$result = (new Pipeline())
    ->send($request)                    // 传入请求对象
    ->through([                         // 经过中间件
        new AuthMiddleware(),
        new ThrottleMiddleware(),
    ])
    ->then(function($request) {         // 最终处理
        return $router->dispatch($request);
    });

// 自定义方法名
$result = (new Pipeline())
    ->send($data)
    ->through($processors)
    ->via('process')                    // 调用 process() 而非 handle()
    ->then(fn($data) => $data);

// 直接返回
$result = (new Pipeline())
    ->send($request)
    ->through($middleware)
    ->thenReturn();                     // 最终处理直接返回 passable
```

---

## 13. Macroable 宏扩展 (v2.0.0 新增)

运行时动态为类添加方法：

```php
use core\Request;
use core\Response;

// 注册单个宏
Request::macro('isApi', function() {
    return str_starts_with($this->uri(), '/api/');
});

// 使用
$request = new Request();
if ($request->isApi()) {
    // API 请求逻辑
}

// 静态宏
Response::macro('noContent', function() {
    return self::make('', 204);
});
$response = Response::noContent();

// Mixin 批量注册
class RequestHelpers
{
    public function isMobile()
    {
        return fn() => (bool) preg_match('/Mobile|Android/i', $this->userAgent());
    }

    public function wantsJson()
    {
        return fn() => str_contains($this->header('Accept', ''), 'application/json');
    }
}

Request::mixin(new RequestHelpers());

// 检查宏是否存在
Request::hasMacro('isMobile');  // true

// 清空所有宏
Request::flushMacros();
```

---

## 14. 异常处理 (v2.0.0 新增)

```php
use core\ExceptionHandler;

// 创建异常处理器
$handler = new ExceptionHandler($logger, $debug);

// 自定义不报告的异常
class MyExceptionHandler extends ExceptionHandler
{
    protected array $dontReport = [
        \core\exception\RouteNotFoundException::class,
    ];
}

// 报告异常（自动写入日志，跳过 dontReport）
$handler->report($exception);

// 渲染异常为 HTTP 响应
$response = $handler->render($request, $exception);
```

---

## 15. 缓存系统

```php
use cache\CacheManager;

$cache = new CacheManager(['driver' => 'file']);

// 基础操作
$cache->set('key', 'value', 3600);     // 设置（1小时过期）
$value = $cache->get('key');            // 获取
$cache->has('key');                     // 检查
$cache->delete('key');                  // 删除
$cache->clear();                        // 清空

// 缓存回退（防止缓存击穿）
$users = $cache->remember('active_users', 300, function() {
    return $db->table('users')->where('status', '=', 'active')->fetchAll();
});

// 递增/递减
$cache->increment('page_views');
$cache->decrement('stock:1001');

// 标签化缓存
$cache->tags(['user', 'profile'])->set('user:1:profile', $data, 3600);
$cache->flushByTag('user');  // 清除所有 user 标签的缓存

// 切换驱动
$cache->driver('redis')->set('key', 'value');
$cache->setDefaultDriver('redis');
```

---

## 16. 视图与模板

### PHP 原生模板

```php
// 渲染视图
$html = $view->render('users/index', ['users' => $users]);

// 布局继承 — layout.php
<html>
<body><?php $__view->yield('content'); ?></body>
</html>

// 子视图 — users/index.php
<?php $__view->extend('layout'); ?>
<?php $__view->startSection('content'); ?>
<h1>用户列表</h1>
<?php $__view->endSection(); ?>
```

### Blade 模板

```php
$blade = new \view\Blade(VIEW_PATH, STORAGE_PATH . 'views/');

// 渲染
$html = $blade->render('users.index', ['users' => $users]);
```

模板语法：

```blade
{{-- 输出（自动转义） --}}
<h1>{{ $title }}</h1>

{{-- 原始输出（不转义） --}}
<div>{!! $htmlContent !!}</div>

{{-- 控制语句 --}}
@if($user->isAdmin())
    <span>管理员</span>
@elseif($user->isEditor())
    <span>编辑</span>
@else
    <span>普通用户</span>
@endif

@foreach($users as $user)
    <li>{{ $user->name }}</li>
@endforeach

{{-- 包含子视图 --}}
@include('partials.header', ['title' => '首页'])

{{-- CSRF 令牌 --}}
<form method="POST">
    @csrf
    <input name="name">
</form>
```

---

## 17. 数据验证

```php
use core\Validate;

$validator = new Validate();

// 定义规则
$rules = [
    'name'     => 'required|min:2|max:50',
    'email'    => 'required|email',
    'age'      => 'required|integer|min:1|max:150',
    'password' => 'required|min:8',
    'role'     => 'in:admin,editor,user',
    'website'  => 'url',
    'ip'       => 'ip',
];

// 自定义错误消息
$messages = [
    'name.required'     => '请填写姓名',
    'email.email'       => '邮箱格式不正确',
    'password.min'      => '密码至少:min位',
];

$validator->messages($messages);

// 执行验证
if ($validator->validate($_POST, $rules)) {
    $validData = $validator->validated();
    // $validData 只包含验证通过的字段
} else {
    $errors = $validator->errors();
    $first  = $validator->firstError();
}
```

支持的验证规则：`required`、`email`、`min`、`max`、`numeric`、`integer`、`float`、`url`、`ip`、`alpha`、`alphaNum`、`in`、`notIn`、`regex`、`date`、`confirmed`

---

## 18. 数据库迁移与数据填充

### 迁移

```php
use db\Schema;

// 创建表
Schema::create('posts', function($table) {
    $table->id();
    $table->string('title');
    $table->text('content');
    $table->integer('user_id')->unsigned();
    $table->boolean('published')->default(false);
    $table->timestamps();
    $table->softDeletes();

    $table->foreign('user_id')->references('id')->on('users');
    $table->index('created_at');
});

// 修改表
Schema::table('posts', function($table) {
    $table->string('summary')->after('title')->nullable();
});

// 运行迁移
$migration = new \db\Migration();
$migration->run();       // 执行所有待执行迁移
$migration->rollback();  // 回滚上一批
$migration->reset();     // 回滚所有
$migration->fresh();     // 重置并重新执行
```

### 数据填充 (v2.0.0 新增)

```php
use db\Seeder;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $this->db->table('users')->insert([
            'name'     => 'Admin',
            'email'    => 'admin@example.com',
            'password' => \core\Hash::make('password'),
            'status'   => 1,
        ]);

        // 调用其他 Seeder
        $this->call(PostSeeder::class);
    }
}

// 注册并执行
Seeder::register(UserSeeder::class);
Seeder::register(PostSeeder::class);
Seeder::runAll($db);
```

---

## 19. 集合

```php
use core\Collection;

$users = collect([
    ['name' => 'John', 'age' => 25, 'role' => 'admin'],
    ['name' => 'Jane', 'age' => 30, 'role' => 'editor'],
    ['name' => 'Bob',  'age' => 25, 'role' => 'user'],
]);

// 过滤与映射
$names = $users->pluck('name');                     // ['John', 'Jane', 'Bob']
$admins = $users->where('role', 'admin');           // 过滤
$older = $users->filter(fn($u) => $u['age'] > 25); // 自定义过滤

// 聚合
$avgAge = $users->avg('age');    // 26.67
$maxAge = $users->max('age');    // 30

// 排序
$sorted = $users->sortBy('age');
$sortedDesc = $users->sortByDesc('age');

// 分组
$grouped = $users->groupBy('age');
// [25 => [...], 30 => [...]]

// 链式调用
$result = $users
    ->filter(fn($u) => $u['age'] >= 25)
    ->sortBy('name')
    ->pluck('name')
    ->values();
```

---

## 完整项目结构

```
lightphp/
├── app/
│   ├── cache/          # 缓存驱动（File/Redis/Memcached）
│   ├── config/         # 配置文件
│   ├── controller/     # 控制器
│   ├── core/           # 框架核心
│   │   ├── console/    # CLI 命令
│   │   ├── contract/   # 接口契约
│   │   ├── exception/  # 异常类
│   │   ├── traits/     # 核心特征（Macroable）
│   │   ├── Application.php
│   │   ├── Container.php
│   │   ├── ExceptionHandler.php  # 异常处理器 (v2.0)
│   │   ├── Pipeline.php          # 管道 (v2.0)
│   │   ├── Router.php
│   │   ├── Request.php
│   │   ├── Response.php
│   │   └── ...
│   ├── db/             # 数据库（QueryBuilder/Schema/Seeder）
│   ├── log/            # 日志
│   ├── middleware/     # 中间件
│   ├── model/          # 模型
│   ├── route/          # 路由定义
│   ├── traits/         # 应用特征（SoftDelete/HasModelEvents）
│   └── view/           # 视图引擎（PHP/Blade/Smarty）
├── docs/               # 文档
├── public/             # Web 入口
├── storage/            # 存储（缓存/日志/上传）
└── tests/              # 测试
```

---

**Happy Coding with LightPHP! 🚀**
