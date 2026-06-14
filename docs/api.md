# LightPHP API 文档

## 概述

本文档是 LightPHP 框架的完整 API 参考。你可以在这里查阅所有核心类、方法和功能的具体用法。

> 💡 **阅读建议**：如果你是第一次接触本框架，建议先阅读 [README.md](../README.md) 和 [开发指南](guide.md)，掌握基本概念后再翻阅本文档作为日常参考。

### 框架核心设计理念

在查看具体 API 之前，先理解框架的核心设计：

- **轻量优先**：核心功能全部自实现，零外部依赖即可运行
- **安全默认**：SQL 参数绑定防注入、HTML 自动转义防 XSS、CSRF Token 验证、路径穿越防护等安全措施全部内置
- **渐进增强**：按需使用功能（如 Smarty 模板需要时才通过 Composer 安装）
- **PSR 兼容**：Logger 实现 PSR-3 标准，Container 实现 PSR-11 标准
- **类 Laravel/ThinkPHP 风格**：如果你熟悉这两个框架，上手本框架将非常自然

### 响应格式

框架统一使用 JSON 格式作为 API 响应，采用统一的返回结构以便前端统一处理：

成功响应：
```json
{
    "code": 0,
    "message": "success",
    "data": {}
}
```

错误响应：
```json
{
    "code": -1,
    "message": "Error message",
    "data": []
}
```

其中 `code` 为 0 表示成功，非 0 表示不同类型的错误；`message` 是人类可读的消息文本；`data` 是返回的具体数据。

### HTTP 状态码

| 状态码 | 说明 | 典型场景 |
|--------|------|---------|
| 200 | 请求成功 | 正常的 GET/POST 请求返回 |
| 201 | 创建成功 | POST 新增资源成功 |
| 400 | 请求参数错误 | 参数缺失或格式不正确 |
| 401 | 未认证 | 用户未登录或 Token 过期 |
| 403 | 无权限 | 用户无此操作的权限 |
| 404 | 资源不存在 | 查不到对应的数据记录 |
| 422 | 验证失败 | 表单数据校验不通过 |
| 429 | 请求过于频繁 | 触发了速率限制（Throttle） |
| 500 | 服务器内部错误 | 代码异常或数据库连接失败 |

---

## ORM 关联关系

ORM 关联让你在 PHP 代码中自然地表达数据表之间的关系，就像操作内存中的对象一样操作数据库中的数据。

> 💡 **什么是 ORM 关联？** 假设一张 `users` 表和一张 `posts` 表，通过 `posts.user_id` 关联。使用 ORM 关联，你不需要手写 JOIN SQL，只需在模型中定义 `$user->posts()`，框架就会自动处理好查询。

### 关联类型一览

| 关联类型 | 方法 | SQL 等价 | 场景示例 |
|---------|------|---------|---------|
| 一对一 | `hasOne()` | WHERE foreign_key = id LIMIT 1 | 用户 ↔ 个人资料 |
| 一对多 | `hasMany()` | WHERE foreign_key = id | 用户 ↔ 文章列表 |
| 反向一对多 | `belongsTo()` | WHERE id = foreign_key | 文章 ↔ 所属用户 |
| 多对多 | `belongsToMany()` | JOIN 中间表 | 文章 ↔ 标签 |

### hasOne - 一对一关联

用于表示"A 拥有一个 B"的关系，比如每个用户有一份个人资料：

```php
// User 模型中定义与 Profile 的一对一关系
// 参数说明：Profile::class 是关联的模型类，'user_id' 是 Profile 表中的外键字段
class User extends Model
{
    public function profile()
    {
        return $this->hasOne(Profile::class, 'user_id');
    }
}

// 使用：获取 ID 为 1 的用户的个人资料
$user = User::find(1);
$profile = $user->profile();  // 返回 Profile 模型实例，或空的 Profile 对象
// $profile->bio           // 可以像操作普通对象一样访问属性
// $profile->avatar        // 不需要手动 SELECT ... FROM profiles WHERE user_id = 1
```

### hasMany - 一对多关联

用于表示"A 拥有多个 B"的关系，比如一个用户有多篇文章：

```php
class User extends Model
{
    public function posts()
    {
        // 第二个参数 'user_id' 是 posts 表中的外键字段名
        return $this->hasMany(Post::class, 'user_id');
    }
}

$user = User::find(1);
$posts = $user->posts();  // 返回 Post[] 数组，每个元素是一个 Post 模型实例

// 遍历文章
foreach ($posts as $post) {
    echo $post->title;     // 直接访问属性
    echo $post->created_at; // 如果配置了 casts，会自动转为 DateTime 对象
}
```

### belongsTo - 反向关联

"belongsTo"意为"属于"，是 `hasMany` 的反向关系。当你想从文章找到作者时使用：

```php
class Post extends Model
{
    public function author()
    {
        // 参数：关联的 User 模型类，posts 表中的外键字段名
        return $this->belongsTo(User::class, 'user_id');
    }
}

// 使用：找到 ID 为 5 的文章的作者
$post = Post::find(5);
$author = $post->author();  // 返回 User 模型实例
echo $author->name;         // 输出作者姓名
```

### belongsToMany - 多对多关联

多对多关联通过一张**中间表（pivot table）**来记录关系。例如：文章和标签的关系——一篇文章可以有多个标签，一个标签也可以被多篇文章使用：

```php
class Post extends Model
{
    public function tags()
    {
        // 参数说明：
        // Tag::class — 关联的模型类
        // 'post_tag' — 中间表名（默认按字母排序的两表名拼接）
        // 'post_id' — 中间表中指向当前模型的外键
        // 'tag_id' — 中间表中指向关联模型的外键
        return $this->belongsToMany(Tag::class, 'post_tag', 'post_id', 'tag_id');
    }
}

// 使用：获取文章的标签
$post = Post::find(1);
$tags = $post->tags();  // 返回 Tag[] 数组

// 中间表结构示例：
// CREATE TABLE `post_tag` (
//     `post_id` INT UNSIGNED,
//     `tag_id`  INT UNSIGNED,
//     PRIMARY KEY (`post_id`, `tag_id`)
// );
```

### 预加载（Eager Loading）

> 💡 **N+1 问题解释**：假设有 100 个用户，循环中调用 `$user->posts()` 获取每个用户的文章：
> - 不使用预加载：1 次查用户 + 100 次查文章 = 101 次 SQL 查询
> - 使用预加载：1 次查用户 + 1 次查所有用户的文章 = **2 次 SQL 查询**
> - 性能差距是 50 倍！数据越多差距越大。

```php
// 方式一：with() 自动预加载（推荐）
$users = User::where('status', '=', 1)->fetchAll();
$userModels = array_map(fn($r) => new User($r), $users);
User::eagerLoad($userModels, 'posts', Post::class, 'hasMany');
// 此后 $user->posts() 直接从内存取，不会查数据库

// 方式二：在模型实例上使用 with() 方法
$user = User::find(1)->with(['profile', 'posts']);
$profile = $user->profile(); // ⚡ 从缓存取
$posts = $user->posts();     // ⚡ 从缓存取
```

---
## Model 模型基础 CRUD

模型是 ORM 的核心，每个模型类对应一张数据库表。继承 `model\Model` 后，框架自动为你提供了增删改查的全套方法。

> 💡 **模型 ≈ 数据库表的 PHP 对象**。操作模型就是操作数据库记录，不再需要手写 SQL。

> ⚠️ **返回值说明**：`Model::find($id)` / `Model::findBy(...)` 返回 **模型实例**（支持 `$user->id` 的属性访问）；`Model::where(...)->fetchAll()` / `->fetch()` / `->first()` 返回的是 **关联数组**（可使用 `$row['id']`）。需要把模型转为数组请调用 `->toArray()`。
>
> 同时，`Model` 的大部分方法也可通过 `__callStatic` 静态调用（如 `User::find(1)` 等价于 `(new User())->find(1)`），仅 `setDb`、`eagerLoad`、`deleteById` 是真正的静态方法。

### 定义模型

```php
<?php
namespace model;

use model\Model;

class User extends Model
{
    // 对应的数据库表名（不指定则自动推断为类名的蛇形复数形式，如 User → users）
    protected string $table = 'users';

    // 主键字段名（默认为 'id'，通常不需要修改）
    protected string $primaryKey = 'id';

    // 允许批量赋值的字段白名单（⚠️ 安全最佳实践：永远明确指定 fillable）
    protected array $fillable = ['username', 'email', 'password', 'nickname'];

    // 序列化时需要隐藏的字段（JSON 输出时自动过滤）
    protected array $hidden = ['password'];

    // 字段类型转换（自动将数据库值转为 PHP 类型）
    protected array $casts = [
        'created_at' => 'datetime',
        'status'     => 'integer',
    ];
}
```

### 查询——读取数据

```php
// 查询所有记录（返回二维数组）
$users = User::all();

// 根据主键查询单条记录（返回模型实例或 null）
$user = User::find(1);

// 条件查询 + 链式调用
$users = User::where('status', '=', 1)      // status = 1
    ->orderBy('created_at', 'DESC')           // 按创建时间倒序
    ->limit(10)                                // 只取 10 条
    ->fetchAll();                              // 执行查询，返回数组

// 获取单条记录
$user = User::where('email', '=', 'test@example.com')->fetch();

// 聚合查询
$count = User::where('status', '=', 1)->count();        // 计数
$total = User::where('type', '=', 'product')->sum('price'); // 求和
$avg   = User::avg('score');                              // 平均值
$max   = User::max('age');                                // 最大值
$min   = User::min('age');                                // 最小值

// 分页查询
$result = User::where('status', '=', 1)->paginate(15, 1);
// 返回：['items' => [...], 'total' => N, 'per_page' => 15, 'current_page' => 1, ...]
```

### 创建——写入新数据

```php
// 方式一：create() 写入（自动过滤 fillable 白名单外的字段）
$userId = User::create([
    'username' => 'john',
    'email'    => 'john@example.com',
    'password' => Hash::make('secret123'),
]);
// 返回新记录的 ID

// 方式二：new 实例 + save()
$user = new User();
$user->username = 'jane';
$user->email = 'jane@example.com';
$user->password = Hash::make('password');
$user->save();  // 返回插入的 ID
```

### 更新——修改已有数据

```php
// 主键更新
User::update(1, ['username' => 'new_name']);

// 条件更新（⚠️ 必须带 WHERE 条件，防止全表更新）
User::where('id', '=', 1)->update(['username' => 'new_name']);

// 从模型实例更新
$user = User::find(1);
$user->username = 'changed';
$user->save();  // 自动判断是插入还是更新
```

### 删除——移除记录

```php
// 主键删除（实例方法）
(new User())->delete(1);

// 静态调用方式（通过 __callStatic 转发到实例）
User::delete(1);

// 静态快捷方式 deleteById
User::deleteById(1);

// 条件删除（⚠️ 必须带 WHERE 条件）
User::where('id', '=', 1)->delete();
```

### 实用的查询条件

```php
// WHERE IN
User::whereIn('id', [1, 2, 3])->fetchAll();

// WHERE NULL / NOT NULL
User::whereNull('deleted_at')->fetchAll();
User::whereNotNull('email')->fetchAll();

// WHERE BETWEEN
User::whereBetween('age', 18, 60)->fetchAll();

// OR 条件组（每项一个条件，operator 可省略默认为 =）
User::whereOr(['status' => 0, 'role' => ['=', 'admin']])->fetchAll();

// 原生 SQL（需谨慎使用）
User::where('id', '>', 0)
    ->raw('(status = 1 OR role = :role)', ['role' => 'admin'])
    ->fetchAll();

// JOIN 联表查询
User::table('users')
    ->select('users.*, posts.title')
    ->leftJoin('posts', 'users.id', '=', 'posts.user_id')
    ->fetchAll();
```

### 更多查询方法

```php
// findOrFail - 根据主键查询，不存在则抛出异常
$user = User::findOrFail(1);  // 如果 ID=1 不存在，抛出异常

// first - 获取第一条匹配记录（返回关联数组）
$user = User::where('email', '=', 'test@example.com')->first();

// firstOrFail - 获取第一条匹配记录，不存在则抛出异常
$user = User::where('email', '=', 'test@example.com')->firstOrFail();

// firstOrCreate - 查找或创建：存在则返回，不存在则创建并返回
$user = User::firstOrCreate(
    ['email' => 'john@example.com'],  // 查找条件
    ['username' => 'john', 'password' => Hash::make('secret')]  // 创建数据（仅在创建时使用）
);

// firstOrNew - 查找或新建实例：存在则返回，不存在则返回新实例（未保存）
$user = User::firstOrNew(
    ['email' => 'jane@example.com'],  // 查找条件
    ['username' => 'jane']  // 新实例的默认值
);
$user->save();  // 手动调用 save() 才会写入数据库
```

---
## Request 请求对象

`Request` 类封装了所有 HTTP 请求相关的信息，让你安全地获取用户输入。

> 💡 **为什么用 Request 而不是直接访问 `$_GET`/`$_POST`？** Request 提供了统一的获取方式（GET 和 POST 统一从 `all()` 获取），内置了安全的取子集和排除功能，让代码更规范和安全。

> ⚠️ **调用方式**：`Request` 是**实例方法**风格。控制器方法签名声明 `Request $request` 时注入实例；非控制器场景下，可通过 `new Request()` 创建或通过框架注入（容器 / Facade）。下面的 `$request->` 形式请按上下文替换为对应的实例引用。

```php
use core\Request;

// 获取 GET 参数（从 URL 查询字符串）
$id = $request->get('id', 0);             // 第二个参数是默认值

// 获取 POST 参数（从请求体）
$name = $request->post('name', '');

// 获取所有输入（GET + POST 合并）
$all = $request->all();

// 只取指定字段（白名单模式，阻止意外的字段注入）
$safe = $request->only(['username', 'email', 'password']);

// 排除指定字段
$data = $request->except(['_token', '_method']);

// 检查字段是否存在
if ($request->has('email')) { /* ... */ }

// 判断请求方法
$request->isGet();     // → true/false
$request->isPost();    // → true/false
$request->method();    // → 'GET', 'POST', 'PUT', 'DELETE', ...

// 检测 AJAX 请求
if ($request->isAjax()) {
    // 返回 JSON 响应
} else {
    // 返回 HTML 页面
}

// 获取请求 URI 和完整 URL
$uri = $request->uri();   // → '/user/1'
```

---
## Response 响应对象

`Response` 类提供了统一的 HTTP 响应构建方式。控制器可以直接返回 Response 实例，也可以返回数组（框架自动转为 JSON）。

```php
use core\Response;

// ===== JSON 响应（API 接口最常用）=====
Response::json([
    'code'    => 0,
    'message' => 'success',
    'data'    => ['users' => [...]],
]);

// 指定 HTTP 状态码
Response::json(['code' => -1, 'message' => '参数错误'], 422);

// ===== HTML 响应 =====
Response::make('<h1>Hello World</h1>');
Response::make('<h1>Error</h1>', 500);

// ===== 重定向 =====
Response::redirect('/login');
Response::redirect('/login', 302);

// ===== 文件下载 =====
Response::download('/path/to/file.pdf', 'document.pdf');

// ===== 在控制器中 =====
class UserController extends Controller
{
    // 方式一：返回数组（框架自动转为 JSON）
    public function index(): array
    {
        $users = User::all();
        return ['code' => 0, 'data' => $users];
    }

    // 方式二：使用控制器的 json() 快捷方法
    public function show(int $id): Response
    {
        $user = User::find($id);
        if (!$user) {
            return $this->json(['code' => 404, 'message' => '用户不存在'], 404);
        }
        return $this->json(['code' => 0, 'data' => $user]);
    }
}
```

### 安全响应头（自动附加）

> 💡 **安全响应头是什么？** 每次 HTTP 响应返回给浏览器时，框架自动在 HTTP 头中加入安全策略，告诉浏览器"这个页面只允许加载同源的脚本和样式、不允许被其他网站嵌入到 iframe 中"等。这显著提升了应用的安全性。

所有 HTML 响应自动附加以下安全头：

| 响应头 | 作用 |
|-------|------|
| `Content-Security-Policy` | 限制脚本、样式、图片等资源只能从同源加载，阻止 XSS 攻击 |
| `X-Content-Type-Options: nosniff` | 禁止浏览器猜测文件类型（MIME 嗅探），防止恶意文件伪装 |
| `X-Frame-Options: SAMEORIGIN` | 只允许同源页面将本页嵌入 iframe，防止点击劫持 |
| `X-XSS-Protection: 1; mode=block` | 启用浏览器内置 XSS 过滤器 |
| `Referrer-Policy: strict-origin-when-cross-origin` | 跨域时只在目标同协议时发送来源信息 |

```php
// 如果需要关闭安全头（例如自定义 CSP 的 API）：
$response = Response::make($html)->withoutSecurityHeaders();

// JSON/API 响应不会自动附加 CSP 头（Content-Type 非 text/html）
Response::json(['code' => 0, 'data' => $items]);  // 仅附加非 CSP 安全头
```

---
## Validate 验证器

`Validate` 类提供了一套声明式的数据校验规则。在控制器中，拿到用户输入后的第一件事就是**验证**——确保数据符合预期再执行业务逻辑。

> 💡 **验证为什么重要？** 永远不要信任用户的输入。即使用户界面有限制（如下拉框），攻击者可以直接发送 HTTP 请求绕过。服务端验证是最后一道防线。

### 快速入门

```php
use core\Validate;

// 1. 定义验证规则
$rules = [
    'username' => 'required|min:3|max:20|alphaNum',
    'email'    => 'required|email',
    'password' => 'required|min:6|confirmed',
    'age'      => 'integer|min:0|max:150',
    'role'     => 'in:admin,user,moderator',
];

// 2. 获取输入数据
$data = $request->only(['username', 'email', 'password', 'age', 'role']);

// 3. 执行验证
$validator = (new Validate())->validate($data, $rules);

if ($validator->fails()) {
    // 验证失败，返回错误信息
    return Response::json([
        'code'    => 422,
        'message' => $validator->firstError(),  // 第一条错误
        'errors'  => $validator->errors(),       // 所有错误（按字段分组）
    ], 422);
}

// 4. 验证通过，继续处理业务
User::create($data);
```

### 所有可用规则

| 规则 | 格式 | 说明 | 示例 |
|------|------|------|------|
| `required` | `required` | 字段必须存在且非空 | `'name' => 'required'` |
| `email` | `email` | 验证邮箱格式 | `'email' => 'email'` |
| `min` | `min:值` | 字符串长度/数值不小于指定值 | `'age' => 'min:18'` |
| `max` | `max:值` | 字符串长度/数值不大于指定值 | `'name' => 'max:50'` |
| `integer` | `integer` | 必须是整数 | `'age' => 'integer'` |
| `numeric` | `numeric` | 必须是数字（含小数） | `'price' => 'numeric'` |
| `float` | `float` | 必须是浮点数 | `'price' => 'float'` |
| `url` | `url` | 必须是有效 URL 格式 | `'website' => 'url'` |
| `ip` | `ip` | 必须是有效 IP 地址 | `'ip' => 'ip'` |
| `alpha` | `alpha` | 只能包含字母 | `'code' => 'alpha'` |
| `alphaNum` | `alphaNum` | 只能包含字母和数字 | `'username' => 'alphaNum'` |
| `in` | `in:值1,值2,...` | 值必须在指定的列表中 | `'status' => 'in:0,1'` |
| `notIn` | `notIn:值1,值2,...` | 值不能在指定的列表中 | `'name' => 'notIn:admin,root'` |
| `regex` | `regex:/正则/` | 自定义正则表达式 | `'phone' => 'regex:/^1[3-9]\d{9}$/'` |
| `date` | `date` | 必须是有效日期格式 | `'birthday' => 'date'` |
| `confirmed` | `confirmed` | 字段必须与 `{field}_confirmation` 值一致 | `'password' => 'confirmed'` |
| `array` | `array` | 必须是合法的数组 | `'tags' => 'array'` |
| `string` | `string` | 必须是字符串 | `'name' => 'string'` |
| `size` | `size:值` | 字符串长度/数值/数组元素数量必须等于指定值 | `'code' => 'size:6'` |
| `between` | `between:最小,最大` | 字符串长度/数值/数组元素数量必须在指定范围内 | `'age' => 'between:18,60'` |
| `boolean` | `boolean` | 必须是布尔值（true/false, 1/0, '1'/'0'） | `'active' => 'boolean'` |
| `before` | `before:日期` | 必须是早于指定日期的日期 | `'birthday' => 'before:2000-01-01'` |
| `after` | `after:日期` | 必须是晚于指定日期的日期 | `'expire_date' => 'after:today'` |
| `different` | `different:字段名` | 值必须与另一个字段的值不同 | `'password' => 'different:old_password'` |
| `same` | `same:字段名` | 值必须与另一个字段的值相同 | `'password' => 'same:password_confirmation'` |
| `nullable` | `nullable` | 允许字段为 null | `'nickname' => 'nullable|string'` |
| `digits` | `digits:值` | 必须是恰好指定位数的数字 | `'zipcode' => 'digits:6'` |
| `digitsBetween` | `digitsBetween:最小,最大` | 必须是位数在指定范围内的数字 | `'phone' => 'digitsBetween:10,15'` |

### 自定义错误消息

```php
$messages = [
    'username.required' => '用户名不能为空',
    'username.min'      => '用户名至少需要 3 个字符',
    'email.required'    => '邮箱地址不能为空',
    'email.email'       => '请输入有效的邮箱地址',
];

$validator = (new Validate())->validate($data, $rules, $messages);
```

---
## Cache 缓存系统

框架内置了基于文件的缓存系统（FileCache），实现了 `CacheInterface` 接口，方便后续扩展为 Redis、Memcached 等驱动。

> 💡 **缓存的作用**：把计算量大的结果临时存起来，下次直接从缓存读取，避免重复计算或重复查询数据库。典型场景：首页帖子列表、热门标签、配置项等。

```php
use cache\FileCache;

// 创建缓存实例（指定缓存文件存储目录）
$cache = new FileCache(STORAGE_PATH . 'cache/');

// ===== 基础操作 =====
$cache->set('key', 'value');              // 写入缓存
$cache->set('key', 'value', 3600);        // 写入并设置过期时间（秒），3600 = 1小时
$value = $cache->get('key', '默认值');     // 读取缓存，过期或不存在返回默认值
$cache->has('key');                       // 检查是否存在 → true/false
$cache->delete('key');                    // 删除单个缓存
$cache->clear();                          // 清空所有缓存

// ===== 记忆模式（最常用）=====
// 如果缓存存在，直接返回；不存在则执行回调并将结果缓存
$posts = $cache->remember('home_posts', 600, function() {
    // 这段代码只在缓存过期时才执行
    return Post::where('status', '=', 1)
        ->orderBy('created_at', 'DESC')
        ->limit(10)
        ->fetchAll();
});
// 首次执行：查询数据库 → 缓存结果 → 返回
// 10分钟内再次调用：直接从缓存返回，不查数据库！

// ===== 计数器 =====
$cache->increment('page_views');           // +1
$cache->increment('page_views', 5);        // +5
$cache->decrement('stock', 1);             // -1
$count = $cache->get('page_views', 0);     // 读取计数器的值
```

### 通过 Facade 使用（更简洁）

如果你定义了 Cache Facade：

```php
use facade\Cache;

Cache::remember('stats', 3600, fn() => computeStats());
$count = Cache::increment('download_count');
```

### 缓存文件结构

缓存数据存储在 `storage/cache/` 目录下，文件名是 Key 的 HMAC 哈希值，内容包含过期时间和序列化的值。框架会自动处理过期清理，无需手动维护。

---
## QueryBuilder 查询构建器

QueryBuilder 是介于"手写原始SQL"和"模型ORM"之间的中间层。它让你用 PHP 方法构建 SQL 语句，自动处理参数绑定和 SQL 注入防护。

> 💡 **什么时候用 QueryBuilder 而不是 Model？** 当你需要执行复杂查询（多表 JOIN、子查询、UNION 等）或不想定义模型类时，可以直接使用 QueryBuilder。它比手写 SQL 更安全（自动参数绑定），比 Model 更灵活。

```php
use db\Connection;

// 获取数据库连接
$connection = new Connection([
    'host'     => '127.0.0.1',
    'port'     => 3306,
    'database' => 'mydb',
    'username' => 'root',
    'password' => '',
    'charset'  => 'utf8mb4',
]);

// 创建查询（table() 方法返回 QueryBuilder 实例）
$query = $connection->table('users');

// ===== SELECT 查询 =====
$users = $query
    ->select(['id', 'username', 'email'])       // 指定查询字段
    ->where('status', '=', 1)                     // 条件筛选
    ->whereIn('id', [1, 2, 3])                    // WHERE IN
    ->whereBetween('created_at', '2025-01-01', '2025-12-31')
    ->orderBy('created_at', 'DESC')               // 排序
    ->limit(10)                                     // 取 10 条
    ->fetchAll();                                   // 执行，返回数组

// ===== 聚合函数 =====
$total = $query->count();                          // COUNT
$sum   = $query->sum('amount');                    // SUM
$avg   = $query->avg('score');                     // AVG

// ===== INSERT =====
$newId = $connection->table('users')->insert([
    'username' => 'neo',
    'email'    => 'neo@matrix.com',
]);

// ===== UPDATE（必须带 WHERE）=====
$affected = $connection->table('users')
    ->where('id', '=', 1)
    ->update(['username' => 'morpheus']);

// ===== DELETE（必须带 WHERE）=====
$affected = $connection->table('users')
    ->where('id', '=', 999)
    ->delete();

// ===== JOIN 联表查询 =====
$results = $connection->table('users')
    ->select(['users.*', 'posts.title'])
    ->leftJoin('posts', 'users.id', '=', 'posts.user_id')
    ->where('users.status', '=', 1)
    ->orderBy('posts.created_at', 'DESC')
    ->fetchAll();

// ===== GROUP BY + HAVING =====
$stats = $connection->table('orders')
    ->select(['user_id', 'COUNT(*) as total'])
    ->groupBy('user_id')
    ->having('total', '>', 5)
    ->fetchAll();

// ===== DISTINCT 去重 =====
$emails = $connection->table('users')
    ->distinct()
    ->pluck('email');  // 返回去重后的 email 数组

// ===== 原始 orderBy =====
$results = $connection->table('users')
    ->orderByRaw('created_at DESC, name ASC')
    ->fetchAll();

// ===== 原始 where =====
$results = $connection->table('users')
    ->whereRaw('age > ? AND status = ?', [18, 1])
    ->fetchAll();

// ===== pluck 提取单列 =====
$names = $connection->table('users')
    ->where('status', '=', 1)
    ->pluck('name');  // 返回 ['John', 'Jane', ...]

// ===== chunkById 分块处理 =====
// 每次处理 100 条记录，避免一次性加载所有数据到内存
$connection->table('users')
    ->where('status', '=', 1)
    ->chunkById(100, function($users) {
        foreach ($users as $user) {
            // 处理每个用户...
        }
    });

// ===== when 条件构建 =====
// 当 $status 不为 null 时才添加 where 条件
$status = request('status');
$results = $connection->table('users')
    ->when($status, function($query, $status) {
        return $query->where('status', '=', $status);
    })
    ->fetchAll();

// ===== 事务操作 =====
$connection->beginTransaction();
try {
    $connection->table('accounts')->where('id', '=', 1)->update(['balance' => 500]);
    $connection->table('accounts')->where('id', '=', 2)->update(['balance' => 700]);
    $connection->commit();
} catch (\Throwable $e) {
    $connection->rollback();
    throw $e;
}

// ===== 原生 SQL（谨慎使用）=====
$results = $connection->query('SELECT * FROM users WHERE status = ?', [1]);
$affected = $connection->execute('UPDATE users SET status = 0 WHERE id = ?', [1]);
```

> ⚠️ **安全提示**：QueryBuilder 对所有 `where()`、`insert()`、`update()` 传入的值都使用 PDO 参数绑定，不存在 SQL 注入风险。但 `query()` 和 `execute()` 的原生 SQL 方法需要你自行确保参数绑定使用的是 `?` 占位符。

---



## EventDispatcher 事件系统

事件系统是一个**发布/订阅（Pub/Sub）**模式的实现，让代码解耦。一个模块（发布者）触发事件，其他模块（订阅者）监听并独立处理，彼此不需要知道对方的存在。

> 💡 **典型场景**：用户注册成功后：
> - 控制器只负责"创建用户"这一个操作
> - 发送欢迎邮件 → 监听 `user.created` 事件
> - 写入操作日志 → 监听 `user.created` 事件
> - 初始化用户设置 → 监听 `user.created` 事件
> - 三个功能各写各的，互不影响，也不影响控制器的代码

### 基本用法

```php
// 1. 创建事件调度器
$events = new \core\EventDispatcher();

// 2. 注册监听器 — 告诉系统："当 user.created 事件发生时，执行这个回调"
$events->listen('user.created', function($event, array $data) {
    // $event → 事件名，这里是 'user.created'
    // $data  → 触发者传入的上下文数据
    \log\Logger::info("新用户注册：{$data['name']}");
});

// 3. 触发事件 — 告诉系统："user.created 事件发生了，这是相关数据"
$results = $events->dispatch('user.created', [
    'name'  => 'John',
    'email' => 'john@example.com',
]);
// 返回所有监听器的返回值组成的数组

// 4. 通配符监听 — 匹配一类事件
// 'order.*' 会匹配 order.placed、order.paid、order.shipped 等所有以 order. 开头的事件
$events->listen('order.*', fn($event, $data) => \log\Logger::info($event));

// 5. 优先级（数字越大越先执行）
$events->listen('payment.processed', fn() => null, 100); // 优先级 100 → 先执行
$events->listen('payment.processed', fn() => null, 10);  // 优先级 10  → 后执行

// 6. until() — 返回第一个非 null 的结果后停止派发
// 典型场景：找到第一个能处理请求的处理器
$result = $events->until('resolve.service', ['param']);

// 7. 订阅者模式 — 一个类集中管理多个事件的监听
// 适合有复杂事件处理逻辑的场景
$events->subscribe(new EventSubscriber());

// 8. 检查是否存在监听器
$hasListeners = $events->hasListeners('user.created');  // true/false

// 9. 移除某个事件的所有监听器
$events->forget('user.created');

// 10. 清空所有事件的所有监听器
$events->flush();
```

### 订阅者类示例

```php
class EventSubscriber
{
    public function subscribe(\core\EventDispatcher $events): void
    {
        // 一个类中注册多个事件监听
        $events->listen('user.created', [$this, 'onUserCreated']);
        $events->listen('user.deleted', [$this, 'onUserDeleted']);
        $events->listen('order.placed', [$this, 'onOrderPlaced']);
    }

    public function onUserCreated($event, $data) { /* 发送欢迎邮件 */ }
    public function onUserDeleted($event, $data) { /* 清理关联数据 */ }
    public function onOrderPlaced($event, $data) { /* 更新库存 */ }
}
```

---

## Collection 集合类

`Collection` 是对 PHP 数组的面向对象封装。它提供了 40+ 个链式调用的数据处理方法，让数组操作变得像写英文句子一样流畅：`过滤 → 排序 → 取前10条 → 转JSON`。

> 💡 **为什么需要 Collection？** PHP 原生的数组函数（`array_map`、`array_filter` 等）语法冗长且不能链式调用。Collection 让你用 `$data->filter()->sortBy()->take()->toJson()` 的方式一气呵成地完成数据处理。

### 创建集合

```php
// collect() 是全局辅助函数，将任意数组包装成 Collection 对象
$c = collect([1, 2, 3]);

// 也可用静态方法创建
$c = \core\Collection::make([1, 2, 3]);

// 从数据库查询结果创建
$users = collect(User::all());
```

### 过滤与映射

```php
// map — 对每个元素执行回调，返回转换后的新集合
$c->map(fn($n) => $n * 2);           // [2, 4, 6]

// filter — 保留回调返回 true 的元素
$c->filter(fn($n) => $n > 1);        // [2, 3]

// reject — filter 的反操作，保留回调返回 false 的元素
$c->reject(fn($n) => $n === 2);      // [1, 3]

// where — 保留指定字段值匹配的元素（对关联数组有效）
$c->where('status', 1);              // 保留 status = 1 的子数组

// whereIn — 保留指定字段值在列表中的元素
$c->whereIn('id', [1, 2, 3]);

// pluck — 提取所有元素的指定字段
$c->pluck('name');                   // ['John', 'Jane', ...]

// pluck 可以用第二个参数指定键名
$c->pluck('name', 'id');            // [1 => 'John', 2 => 'Jane', ...]

// only — 只保留指定键的元素
$c->only(['a', 'c']);               // 只保留键 a 和 c

// except — 排除指定键的元素
$c->except(['b']);                  // 去掉键 b
```

### 聚合函数

```php
$c->sum('price');        // 求和 → 返回 float|int
$c->avg('score');        // 平均值 → 返回 float|int
$c->min('age');          // 最小值 → 返回 mixed|null
$c->max('age');          // 最大值 → 返回 mixed|null
```

### 更多集合方法

```php
// flatMap — 对每个元素执行回调并展平结果
$c->flatMap(fn($user) => $user->tags);  // 展平嵌套数组

// flatten — 将多维数组展平为一维
$nested = collect([[1, 2], [3, 4]]);
$nested->flatten();  // [1, 2, 3, 4]

// chunk — 将集合分割为指定大小的块
$chunks = collect([1, 2, 3, 4, 5])->chunk(2);
// [[1, 2], [3, 4], [5]]

// diff — 计算两个集合的差集
$diff = collect([1, 2, 3])->diff([2, 3, 4]);  // [1]

// intersect — 计算两个集合的交集
$intersect = collect([1, 2, 3])->intersect([2, 3, 4]);  // [2, 3]

// implode — 将集合元素连接为字符串
$csv = collect(['name', 'age', 'email'])->implode(', ');  // "name, age, email"

// flip — 交换集合的键和值
$flipped = collect(['a' => 1, 'b' => 2])->flip();  // [1 => 'a', 2 => 'b']

// zip — 将多个集合合并为一个元组数组
$zipped = collect([1, 2])->zip(['a', 'b']);  // [[1, 'a'], [2, 'b']]

// nth — 每隔 n 个元素取一个
$every = collect([1, 2, 3, 4, 5, 6])->nth(2);  // [1, 3, 5]

// forPage — 分页返回指定页的元素
$page = collect(range(1, 50))->forPage(2, 15);  // 第2页，每页15条

// slice — 返回集合中从指定位置开始的片段
$slice = collect([1, 2, 3, 4, 5])->slice(2);  // [3, 4, 5]

// split — 将集合分割为指定数量的组
$groups = collect([1, 2, 3, 4, 5])->split(3);  // [[1, 2], [3, 4], [5]]

// collapse — 将嵌套数组展平为一维
$collapsed = collect([[1, 2], [3, 4], [5]])->collapse();  // [1, 2, 3, 4, 5]

// merge — 合并另一个数组或集合到当前集合
$merged = collect([1, 2])->merge([3, 4]);  // [1, 2, 3, 4]

// pull — 从集合中移除并返回指定键的元素
$pulled = collect(['a' => 1, 'b' => 2])->pull('a');  // 返回 1，集合变为 ['b' => 2]

// forget — 从集合中移除指定键的元素
$col = collect(['a' => 1, 'b' => 2, 'c' => 3])->forget('b');  // ['a' => 1, 'c' => 3]
```

### 排序

```php
// sortBy — 按指定字段升序排列
$c->sortBy('name');

// sortByDesc — 按指定字段降序排列
$c->sortByDesc('created_at');

// sort — 自定义排序回调
$c->sort(fn($a, $b) => $a <=> $b);

// reverse — 反转集合顺序
$c->reverse();
```

### 截取

```php
$c->take(10);        // 取前 10 项
$c->skip(5);         // 跳过前 5 项（然后可继续链式调用 take）
```

### 查找

```php
$c->first();                         // 取第一项
$c->first(fn($n) => $n > 5);        // 找到第一个匹配条件的
$c->last();                          // 取最后一项
$c->contains(2);                     // 检查是否包含值 2 → true/false
```

### 分组

```php
// groupBy — 按指定字段分组成多层嵌套数组
$c->groupBy('type');
// 结果：['article' => [...], 'page' => [...]]

// keyBy — 以指定字段作为键重新索引
$c->keyBy('id');
// 结果：[1 => ['id'=>1,...], 2 => ['id'=>2,...]]
```

### 链式调用

```php
// unique — 去重
// values — 重新索引（去掉键名，从 0 开始编号）
// reduce — 归纳计算（参数：回调函数, 初始值）
$c->unique()
  ->values()
  ->reduce(fn($carry, $item) => $carry + $item, 0);
```

### 遍历

```php
// each — 遍历每个元素（通常用于副作用，如输出）
$c->each(fn($item, $key) => echo $item);

// tap — 对集合执行操作但不修改集合（用于调试、日志）
$c->tap(fn($col) => Logger::info('Processing ' . $col->count() . ' items'));

// pipe — 将集合传递给回调，返回回调的结果（用于最终转换）
$c->pipe(fn($col) => $col->toJson());
```

### 导出

```php
$c->toArray();        // 转为 PHP 数组
$c->toJson();         // 转为 JSON 字符串
$c->isEmpty();        // 检查是否为空 → true/false
$c->isNotEmpty();     // 检查是否非空 → true/false
$c->count();          // 元素个数
```

### 全局辅助函数

```php
// collect() — 将数组包装为 Collection
$col = collect([1, 2, 3]);

// value() — 如果参数是闭包则执行并返回值，否则直接返回
$val = value(fn() => 'computed');  // 'computed'
$val = value('static string');     // 'static string'

// app() — 从容器获取服务实例
$db = app(\db\Connection::class);
```

### 实战链式调用示例

```php
// 从 100 个用户中筛选出活跃用户，按注册时间降序排列，取前 10 个，只保留用户名
$result = collect(User::all())
    ->where('status', 1)             // 只取已激活用户
    ->sortByDesc('created_at')       // 按创建时间降序排列
    ->take(10)                        // 只取前 10 个
    ->pluck('name')                   // 只保留 name 字段
    ->values()                         // 重新索引（0, 1, 2, ...）
    ->toJson();                       // 转为 JSON 输出
```

---

## Blade 模板引擎

Blade 是受 Laravel Blade 启发的模板编译器。使用 `.blade.php` 后缀的文件会被自动编译为纯 PHP 代码，编译结果缓存到 `storage/views/` 目录以提高性能。

> 💡 **Blade 的优势**：相比原生 PHP 模板，Blade 提供了更简洁的语法（`{{ $var }}` 代替 `<?= htmlspecialchars($var) ?>`）、自动 XSS 防护、布局继承等高级特性。

### 创建 Blade 实例

```php
$blade = new \view\Blade(
    VIEW_PATH,                    // 视图文件所在目录（ app/view/ ）
    STORAGE_PATH . 'views/'      // 编译缓存目录
);

// 渲染模板文件
echo $blade->render('page', [
    'title' => 'Hello',
    'items' => [1, 2, 3],
]);

// 直接编译字符串（用于测试或邮件模板等场景）
$compiled = $blade->compileString('Hello {{ $name }}');
```

### 语法速查表

| 语法 | 功能 | 注意事项 |
|------|------|---------|
| `{{ $var }}` | HTML 转义输出 | ✅ 推荐使用（自动防 XSS） |
| `{!! $html !!}` | 原始 HTML 输出 | ⚠️ 仅用于可信内容（有 XSS 风险） |
| `@if(...) @elseif(...) @else @endif` | 条件判断 | 与 PHP if 逻辑一致 |
| `@foreach($items as $item) @endforeach` | 遍历循环 | `$loop` 对象可用 |
| `@for($i=0;...) @endfor` | for 循环 | — |
| `@while(...) @endwhile` | while 循环 | — |
| `@extends('layout')` | 继承布局模板 | 必须放在文件第一行 |
| `@section('name') ... @endsection` | 定义内容块 | 搭配 @extends 使用 |
| `@yield('name')` | 渲染内容块 | 用在布局模板中 |
| `@include('view', ['var' => $val])` | 包含子视图 | 子视图可访问父视图的变量 |
| `@csrf` | CSRF 令牌隐藏字段 | `<input type="hidden" name="_token" value="...">` |
| `@method('PUT')` | 表单方法伪装 | `<input type="hidden" name="_method" value="PUT">` |
| `@json($data)` | JSON 编码输出 | — |
| `@php ... @endphp` | 原生 PHP 代码 | 谨慎使用，破坏模板/逻辑分离 |
| `@isset($var) @endisset` | 变量是否已设置 | — |
| `@empty($var) @endempty` | 变量是否为空 | — |
| `@switch($x) @case(1) @break @default @endswitch` | Switch 分支 | — |
| `@unless($expr) @endunless` | 反条件（条件不成立时执行） | — |

### 自定义指令

你可以注册自定义指令来扩展 Blade 的语法：

```php
\view\Blade::directive('datetime', function($expr) {
    // $expr 是模板中 @datetime(...) 括号内的表达式
    return "echo date('Y-m-d', strtotime({$expr}));";
});

// 模板中使用：
// <span>创建于：@datetime($post->created_at)</span>
// 输出：<span>创建于：2025-01-15</span>
```

---

## Schema Builder（数据库表结构构建器）

Schema Builder 让你用 PHP 代码而非 SQL 语句来定义数据表结构。这不只是语法糖——它能让你在不关心具体数据库方言的情况下，写出可移植的表结构定义。

> 💡 **为什么用 Schema Builder 而不是直接写 SQL？**
> - PHP 代码有 IDE 自动补全，不易拼错字段名
> - 自动处理引号、默认值转义等细节
> - 代码即文档，团队新成员能快速理解表结构
> - 配合 Migration 系统，实现数据库版本管理

### 建表操作

```php
use db\Schema;
use db\Blueprint;

// 获取 PDO 连接
$pdo = new \PDO('mysql:host=127.0.0.1;dbname=mydb;charset=utf8mb4', 'root', '');
$schema = Schema::setConnection($pdo);

// 创建表
$schema->create('articles', function(Blueprint $t) {
    $t->id();                                  // BIGINT PRIMARY KEY AUTO_INCREMENT
    $t->string('title', 200);                 // VARCHAR(200) NOT NULL
    $t->text('content');                      // TEXT
    $t->string('slug')->unique();             // VARCHAR(255) UNIQUE — slug 必须唯一
    $t->integer('view_count')->default(0);     // INT DEFAULT 0 — 默认浏览量为 0
    $t->decimal('price', 8, 2)->nullable();   // DECIMAL(8,2) NULL — 价格可为空
    $t->boolean('published')->default(0);     // TINYINT(1) DEFAULT 0 — 默认未发布
    $t->timestamps();                          // created_at + updated_at（自动设置值）
    $t->softDeletes();                        // deleted_at（软删除标记）
});

// 修改已有表
$schema->table('articles', function(Blueprint $t) {
    // 新增字段（放在 title 之后）
    $t->string('excerpt', 500)->nullable()->after('title');

    // 添加普通索引
    $t->index();

    // 删除字段
    $t->dropColumn('old_column');

    // 重命名字段
    $t->renameColumn('title', 'headline');
});
```

### 表操作

```php
// 检查表是否存在
$exists = $schema->hasTable('articles');      // true/false

// 检查列是否存在
$hasColumn = $schema->hasColumn('articles', 'slug');  // true/false

// 重命名整张表
$schema->rename('articles', 'posts');

// 清空表（保留结构，删除所有数据）
$schema->truncate('articles');

// 删除表
$schema->drop('articles');
// 建议使用 dropIfExists 防止"表不存在"错误
$schema->dropIfExists('articles');
```

### 外键约束

```php
$schema->create('comments', function(Blueprint $t) {
    $t->id();
    $t->text('content');

    // 外键：comments.user_id → users.id
    // ON DELETE CASCADE：删除用户时，自动删除该用户的所有评论
    $t->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

    // 多态关联（适用于 comments 这种可能关联多种实体的场景）
    // 自动创建 commentable_id + commentable_type 两个字段
    $t->morphs('commentable');
});
```

### 表配置

```php
// 设置存储引擎、字符集、注释
$schema
    ->engine('InnoDB')              // MySQL 引擎（支持事务和外键）
    ->charset('utf8mb4')            // 字符集（支持 emoji）
    ->comment('Articles table');    // 表注释
```

### 所有可用列类型

| 方法 | SQL 类型 | 说明 |
|------|---------|------|
| `$t->id()` | BIGINT PK AUTO_INCREMENT | 自增主键，最常用的主键类型 |
| `$t->string('name', 255)` | VARCHAR(255) | 变长字符串，需指定最大长度 |
| `$t->text('name')` | TEXT | 文本，最大 65535 字符 |
| `$t->longText('name')` | LONGTEXT | 长文本，最大 4GB |
| `$t->integer('name')` | INT | 整数 |
| `$t->bigInteger('name')` | BIGINT | 大整数 |
| `$t->tinyInteger('name')` | TINYINT | 小整数（-128~127） |
| `$t->boolean('name')` | TINYINT(1) | 布尔值（0 或 1） |
| `$t->decimal('name', 8, 2)` | DECIMAL(8,2) | 精确小数（金额推荐此类型） |
| `$t->float('name')` | FLOAT | 浮点数（精度较低） |
| `$t->double('name')` | DOUBLE | 双精度浮点数 |
| `$t->date('name')` | DATE | 日期（Y-m-d） |
| `$t->dateTime('name')` | DATETIME | 日期时间 |
| `$t->timestamp('name')` | TIMESTAMP | 时间戳 |
| `$t->timestamps()` | created_at + updated_at | 自动时间戳（创建和更新时间） |
| `$t->softDeletes()` | deleted_at | 软删除标记 |
| `$t->json('name')` | JSON | JSON 数据（MySQL 5.7+） |
| `$t->enum('name', ['a','b'])` | ENUM | 枚举值 |
| `$t->morphs('name')` | name_id + name_type | 多态关联字段对 |

### 列修饰符

修饰符用于调整列的行为，可以链式调用：

| 修饰符 | 效果 | 示例 |
|--------|------|------|
| `nullable()` | 允许 NULL 值 | `->string('phone')->nullable()` |
| `notNull()` | 显式声明 NOT NULL | `->string('name')->notNull()` |
| `default($val)` | 设置默认值 | `->integer('count')->default(0)` |
| `unsigned()` | 无符号（非负数） | `->integer('age')->unsigned()` |
| `unique()` | 添加唯一索引 | `->string('email')->unique()` |
| `index()` | 添加普通索引 | `->integer('user_id')->index()` |
| `comment($str)` | 添加列注释 | `->string('name')->comment('用户名')` |
| `after($col)` | 添加到指定列之后 | `->string('phone')->after('email')` |
| `change()` | 标记为修改已有列 | `->string('name', 100)->change()` |

---

## CLI 命令系统

LightPHP 提供了一个完整的命令行系统，支持内置命令和自定义命令。

> 💡 **CLI 是什么？** CLI（Command Line Interface）即命令行界面。在终端中运行 `php bin/console xxx` 来执行各种操作，比如启动服务器、生成代码、执行迁移等。这些命令不同于 Web 请求——它们运行在命令行环境，通常用于开发、部署和维护任务。

### 内置命令列表

```bash
# 列出所有可用命令（新手第一个要记的命令）
php bin/console list

# ===== 开发服务器 =====
php bin/console serve                        # 启动开发服务器（默认端口 8080）
php bin/console serve 3000                   # 指定端口为 3000
php bin/console serve 8080 --host 0.0.0.0   # 允许局域网其他设备访问

# ===== 环境信息 =====
php bin/console config                       # 显示配置概览（列出所有配置节及条目数）
php bin/console config:show database          # 查看数据库详细配置（密码字段自动隐藏）

# ===== 缓存 =====
php bin/console cache:clear                  # 清空所有缓存文件（编译视图/数据缓存）

# ===== 代码生成 =====
php bin/console make:model User              # 生成模型类文件
php bin/console make:controller User         # 生成控制器类文件
php bin/console make:middleware Auth         # 生成中间件类文件
php bin/console make:migration create_users users  # 生成数据库迁移文件

# ===== 数据库迁移 =====
php bin/console migrate                      # 执行所有未执行的迁移文件
php bin/console migrate:rollback             # 回滚最近一批迁移
php bin/console migrate:rollback 3           # 回滚最近 3 批迁移

# ===== 测试 =====
php bin/console test                         # 运行全部单元测试
```

### 创建自定义命令

```php
use core\console\Command;

class ReportCommand extends Command
{
    // 【命令签名】定义了命令名、参数和选项的完整格式
    // {type=daily} — 名为 type 的必需参数，默认值为 daily
    // {date?}     — 名为 date 的可选参数（? 后缀）
    // {--email}   — 布尔选项（有 --email 时为 true，没有时为 false）
    // {--output=json} — 带值的选项
    protected string $signature = 'report:generate {type=daily} {date?} {--email} {--output=json}';
    protected string $description = '生成指定类型和格式的报告';

    public function handle(): int
    {
        // 获取参数
        $type = $this->argument('type');                             // 'daily'
        $date = $this->argument('date', date('Y-m-d'));              // 可选参数，含默认值

        // 检查选项
        if ($this->hasOption('email')) {
            $this->info("将通过邮件发送 {$type} 报告...");
        }

        // 获取带值的选项
        $output = $this->option('output', 'json');                   // 默认为 'json'

        $this->info("正在生成 {$type} 报告，日期：{$date}，格式：{$output}");

        // 返回值：0 = 成功，非 0 = 失败（遵循 Unix 惯例）
        return 0;
    }
}

// 注册命令到 Console
$console = new \core\console\Console('MyApp', '1.0.0');
$console->register(new ReportCommand());
// 运行：$ php bin/console report:generate weekly 2025-01-15 --email
$console->run($argv);
```

### 命令签名语法速查

| 语法 | 含义 | 示例 | 说明 |
|------|------|------|------|
| `{name}` | 必需参数 | `report:generate {type}` | 不传会报错 |
| `{name?}` | 可选参数 | `report:generate {date?}` | 不传则为 null |
| `{name=default}` | 带默认值的参数 | `report:generate {type=daily}` | 不传则用默认值 |
| `{--option}` | 布尔选项（开关） | `{--email}` | 有即为 true |
| `{--option=default}` | 带默认值的选项 | `{--output=json}` | 可用 `--option=val` 指定值 |

### 命令行输出方法

```php
$this->info('正常信息');      // 绿色文字，用于表示操作成功或输出一般信息
$this->error('错误信息');     // 红色文字，用于表示操作失败
$this->warn('警告信息');      // 黄色文字，用于表示需要注意但不致命的问题
$this->line('普通文字');      // 默认终端颜色，普通输出
$this->table($headers, $rows); // 绘制 ASCII 表格，整齐展示数据
```

---

## 认证 API

本章示例展示了如果构建一个典型的 RESTful 认证 API。这些接口展示了如何在实际项目中使用框架的各种功能（模型、验证、加密等）。

### 用户注册

```
POST /api/auth/register
```

请求参数：

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| username | string | 是 | 用户名，用于登录和显示 |
| email | string | 是 | 邮箱地址，需唯一 |
| password | string | 是 | 登录密码（至少 6 位，后端用 bcrypt 存储） |

响应示例：

```json
{
    "code": 0,
    "message": "Registration successful",
    "data": {
        "user": {
            "id": 1,
            "username": "test",
            "email": "test@example.com"
        },
        "token": "eyJ1c2VyX2lkIjoxLCJleHAiOjE3..."
    }
}
```

### 用户登录

```
POST /api/auth/login
```

请求参数：

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| username | string | 是 | 可使用用户名或邮箱登录 |
| password | string | 是 | 登录密码 |

响应示例：

```json
{
    "code": 0,
    "message": "Login successful",
    "data": {
        "user": {
            "id": 1,
            "username": "test",
            "email": "test@example.com",
            "nickname": "测试用户",
            "avatar": null
        },
        "token": "eyJ1c2VyX2lkIjoxLCJleHAiOjE3..."
    }
}
```

### 获取当前用户

```
GET /api/auth/user
```

请求头：
```
Authorization: Bearer <token>
```

此接口返回当前登录用户的完整信息，`token` 从登录接口获取。

### 退出登录

```
POST /api/auth/logout
```

请求头：
```
Authorization: Bearer <token>
```

退出登录会**使当前 token 失效**，之后使用该 token 的请求将收到 401 未认证响应。

---

## 商品 API

### 商品列表（支持搜索和分类筛选）

```
GET /api/products
```

查询参数：

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| category_id | int | 否 | 分类 ID，不传则返回所有分类的商品 |
| keyword | string | 否 | 搜索关键词，匹配商品名称和描述 |
| page | int | 否 | 当前页码，从 1 开始（默认 1） |
| page_size | int | 否 | 每页商品数量（默认 15，最大 100） |

响应示例：

```json
{
    "code": 0,
    "message": "success",
    "data": {
        "items": [
            {
                "id": 1,
                "name": "iPhone 15",
                "slug": "iphone-15",
                "category_id": 1,
                "description": "苹果最新款手机",
                "price": 6999.00,
                "stock": 100,
                "images": ["/images/iphone15.jpg"],
                "status": 1,
                "created_at": "2024-01-15 10:30:00"
            }
        ],
        "total": 50,
        "per_page": 15,
        "current_page": 1,
        "last_page": 4,
        "has_more": true
    }
}
```

### 商品详情

```
GET /api/products/{id}
```

根据商品 ID 获取单个商品的详细信息。`{id}` 为路径参数，替换为实际的商品 ID。

### 推荐商品

```
GET /api/products/featured
```

无需任何参数，返回被标记为"推荐"的商品列表。通常用于首页轮播或推荐位展示。

---

## 分类 API

### 分类列表（树形结构）

```
GET /api/categories
```

返回嵌套的树形分类结构，每个分类可能包含 `children` 子分类数组：

```json
{
    "code": 0,
    "message": "success",
    "data": [
        {
            "id": 1,
            "name": "数码产品",
            "slug": "digital",
            "sort": 1,
            "children": [
                {
                    "id": 5,
                    "name": "手机",
                    "slug": "phones",
                    "sort": 1
                }
            ]
        }
    ]
}
```

### 分类详情

```
GET /api/categories/{id}
```

获取单个分类的详细信息（**不包含**子分类列表）。

---

## 购物车 API

> ⚠️ 以下所有购物车接口**需要认证**，在请求头中携带 `Authorization: Bearer <token>`。

### 购物车列表

```
GET /api/cart
```

返回当前用户购物车中的所有商品及其数量信息。

### 添加到购物车

```
POST /api/cart
```

请求参数：

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| product_id | int | 是 | 要添加的商品 ID |
| quantity | int | 否 | 购买数量（默认 1） |

> 💡 如果购物车中已有该商品，quantity 会**累加**而不是覆盖。

### 更新购物车商品数量

```
PUT /api/cart
```

请求参数：

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| id | int | 是 | 购物车项 ID（不是商品 ID） |
| quantity | int | 是 | 新的数量（设为 0 不会自动删除该项） |

### 删除购物车项

```
DELETE /api/cart
```

请求参数：

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| id | int | 是 | 要删除的购物车项 ID |

### 清空购物车

```
DELETE /api/cart/clear
```

无需参数，直接清除当前用户购物车中的所有商品。

---

## 订单 API

> ⚠️ 以下所有订单接口**需要认证**。

### 创建订单（下单）

```
POST /api/orders
```

请求参数：

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| receiver_name | string | 是 | 收货人姓名 |
| receiver_phone | string | 是 | 收货人联系电话 |
| receiver_address | string | 是 | 收货地址（详细地址） |
| remark | string | 否 | 订单备注 |

> 💡 下单时系统会自动从购物车获取商品列表、计算总价、扣减库存。

### 订单列表

```
GET /api/orders
```

查询参数：

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| page | int | 否 | 页码（默认 1） |
| page_size | int | 否 | 每页数量（默认 15） |

返回当前用户的所有订单，按创建时间降序排列（最新的在最前）。

### 订单详情

```
GET /api/orders/{id}
```

获取指定订单的详细信息，**包括订单中包含的商品列表**。

### 取消订单

```
PUT /api/orders/{id}/cancel
```

> ⚠️ 只能取消**未发货**的订单。已发货的订单无法取消，请联系客服处理。

---

## 管理后台 API

> ⚠️ 以下所有接口**需要管理员认证**。请求头中携带 `Authorization: Bearer <admin_token>`，其中 `admin_token` 由管理员登录接口返回。

### 管理员登录

```
POST /api/admin/auth/login
```

管理员使用此接口登录管理后台。登录成功后获得管理员专属的 token。

### 仪表盘数据

```
GET /api/admin/dashboard
```

返回今日的汇总统计数据，包括：今日新增用户数、今日新增订单数、今日销售额、待处理订单数、库存预警商品列表。

---

### 商品管理 API

| 方法 | 路径 | 说明 |
|------|------|------|
| GET | /api/admin/products | 获取所有商品（含已下架的） |
| POST | /api/admin/products | 添加新商品 |
| GET | /api/admin/products/{id} | 获取商品详情（用于编辑页回填） |
| PUT | /api/admin/products/{id} | 更新商品信息（全量更新） |
| DELETE | /api/admin/products/{id} | 删除单个商品 |
| POST | /api/admin/products/batch-delete | 批量删除（传递 ids 数组） |
| POST | /api/admin/products/batch-update-status | 批量更新上架/下架状态 |

### 分类管理 API

| 方法 | 路径 | 说明 |
|------|------|------|
| GET | /api/admin/categories | 获取分类列表（树形结构） |
| GET | /api/admin/categories/all | 获取全部分类（平铺列表） |
| POST | /api/admin/categories | 添加新分类 |
| PUT | /api/admin/categories/{id} | 更新分类信息 |
| DELETE | /api/admin/categories/{id} | 删除分类 |
| POST | /api/admin/categories/update-sort | 更新分类排序 |

### 订单管理 API

| 方法 | 路径 | 说明 |
|------|------|------|
| GET | /api/admin/orders | 获取所有用户的订单列表 |
| GET | /api/admin/orders/{id} | 获取订单详情 |
| POST | /api/admin/orders/{id}/ship | 标记订单为"已发货" |
| POST | /api/admin/orders/{id}/cancel | 管理员取消订单（强制取消） |
| GET | /api/admin/orders/statistics | 获取订单统计（按日期/状态汇总） |

---

## 路由参考（现有路由表）

| 方法 | 路径 | 控制器 | 说明 |
|------|------|--------|------|
| GET | / | IndexController::index | 首页 |
| GET | /smarty-user | SmartyUserController::index | Smarty 模板示例页面 |
| GET | /user | UserController::index | 用户列表 |
| GET | /user/{id} | UserController::show | 用户详情 |
| POST | /user/store | UserController::store | 创建新用户 |
| PUT | /user/{id} | UserController::update | 更新用户信息 |
| DELETE | /user/{id} | UserController::destroy | 删除用户 |

---

## CSRF 防护

CSRF（Cross-Site Request Forgery，跨站请求伪造）是一种常见的 Web 攻击手段。框架通过 `CsrfMiddleware` 中间件 + `@csrf` 模板指令来防护。

> 💡 **CSRF 原理**：当你登录了网站 A，然后又访问了恶意网站 B。B 的页面中包含一个隐形的表单，自动向网站 A 提交数据。因为你已登录，浏览器会携带 Cookie，网站 A 误以为是你本人提交的。CSRF Token 就是用来阻止这种"冒名提交"的。

### 前端使用 CSRF Token

传统 HTML 表单（需配合 Session::token()）：

```html
<form method="POST" action="/submit">
    <input type="hidden" name="_token" value="<?= \core\Session::token() ?>">
    <!-- 表单内容 -->
</form>
```

Blade 模板中使用 `@csrf` 指令（自动生成隐藏 input）：

```blade
<form method="POST" action="/submit">
    @csrf
    <input type="text" name="title">
    <button type="submit">提交</button>
</form>
```

在 AJAX 请求中，从请求头携带 Token：
```
X-CSRF-TOKEN: <token>
```

### 排除不需要 CSRF 的路由

某些回调接口（如支付回调、Webhook）由第三方发起，无法携带 CSRF Token，需要排除：

```php
$csrf = new CsrfMiddleware();
$csrf->except(['/webhook/payment', '/webhook/github']);
```

---

## Facade 门面模式

Facade（门面）提供了一种"静态代理"的方式来访问容器中的服务。它让你可以用 `Cache::get('key')` 这种简洁的静态语法，替代 `$container->get('cache')->get('key')` 这种冗长的写法。

> 💡 **门面不是静态类！** 门面只是一个"代理入口"，实际的方法调用会被转发到容器中的真实服务实例上。这只是一种语法糖，不影响依赖注入或单元测试。

### 定义门面

```php
use core\Facade;

class Cache extends Facade
{
    // 告诉门面：这个门面对应的真实服务在容器中的 ID 是什么
    protected static function getFacadeAccessor(): string
    {
        return 'cache';
    }
}
```

### 使用门面

```php
// 静态调用自动转发到容器中的 'cache' 服务
Cache::set('key', 'value');          // → $container->get('cache')->set('key', 'value')
$value = Cache::get('key', null);    // → $container->get('cache')->get('key', null)
Cache::delete('key');
Cache::has('key');
Cache::clear();
Cache::remember('stats', 3600, fn() => computeStats());
```

---

## ServiceProvider 服务提供者

服务提供者是框架模块化组织的核心机制。它把"服务如何创建"（绑定）和"服务初始化后做什么"（引导）分离开来，分为两个阶段：

1. **register()** — 注册阶段：把服务绑定到容器（此时其他服务可能还没注册完，不要使用依赖）
2. **boot()** — 引导阶段：所有服务已注册完成，可以安全地进行初始化操作

```php
use core\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    // 注册阶段：只做"绑定"，不要使用其他服务
    public function register(): void
    {
        // 将支付服务绑定为单例
        $this->app->singleton('payment', fn($container) => new PaymentService(
            $container->get('config')['payment'] ?? []
        ));
    }

    // 引导阶段：所有服务都已就绪，做初始化
    public function boot(): void
    {
        // 这里可以安全地获取事件系统并注册监听器
        $this->app->get('events')->listen('order.placed', [$this, 'onOrderPlaced']);
    }

    public function onOrderPlaced($event, $data): void
    {
        // 处理订单已下的后续逻辑
    }
}

// 在应用启动流程中注册：
$app->registerProvider(new AppServiceProvider($app->getContainer()));
$app->run(); // run() 会自动调用 bootProviders()
```

---

## CORS 跨域中间件

CORS（Cross-Origin Resource Sharing）是浏览器的安全机制。当前端和后端部署在不同域名（或不同端口）时，浏览器会限制跨域请求。CORS 中间件给响应添加相应的 HTTP 头，告诉浏览器"允许这个来源的请求"。

```php
use middleware\Cors;

$cors = new Cors([
    // 允许的源域名（⚠️ 生产环境务必指定具体域名，不要用 *）
    'allowed_origins' => ['https://example.com', 'https://app.example.com'],

    // 允许的 HTTP 方法
    'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'],

    // 允许的请求头（前端需要在请求中携带的自定义头要列在此处）
    'allowed_headers' => ['Content-Type', 'Authorization', 'X-CSRF-TOKEN'],

    // 暴露给前端 JavaScript 可读取的响应头
    'exposed_headers' => ['X-RateLimit-Remaining'],

    // 预检请求（OPTIONS）的结果缓存时间，单位秒（86400 = 24小时）
    'max_age' => 86400,

    // 是否允许携带 Cookie（如需跨域携带 Cookie，设为 true）
    'supports_credentials' => true,
]);

// CORS 中间件会自动处理浏览器的 OPTIONS 预检请求
```

---

## Throttle 速率限制中间件

Throttle（节流阀）用于限制客户端的请求频率，防止恶意高频请求消耗服务器资源。

```php
use middleware\Throttle;

// 每分钟 60 次请求（即每秒 1 次）
// 第一个 60 = 允许的请求次数
// 第二个 60 = 时间窗口（秒）
$throttle = new Throttle(60, 60);

// 当超过限制时：
// - HTTP 状态码自动返回 429 Too Many Requests
// - 响应头中包含 Retry-After，告知客户端需要等待多少秒后重试
// - 限制按 IP + 路由 独立计次，不同路由不共享配额
```

---

## Logger 日志系统（PSR-3 兼容）

`log\Logger` 完整实现了 PSR-3 Logger Interface 标准，提供 8 个日志级别，日志按日期自动分割存储。

> 💡 **PSR-3** 是 PHP-FIG（PHP Framework Interop Group）发布的 Logger Interface 标准。实现此标准意味着你的日志代码与其他遵循 PSR-3 的库可以无缝对接。

### 日志级别说明

| 级别 | 方法 | 严重程度 | 使用场景 |
|------|------|---------|---------|
| debug | `$log->debug()` | 💚 最低 | 开发调试信息 |
| info | `$log->info()` | 💚 普通 | 常规操作记录 |
| notice | `$log->notice()` | 💛 值得注意 | 非异常但值得关注 |
| warning | `$log->warning()` | 💛 警告 | 不致命的异常 |
| error | `$log->error()` | 🧡 错误 | 运行错误（不阻断） |
| critical | `$log->critical()` | 🧡 严重 | 影响核心功能 |
| alert | `$log->alert()` | 🔴 告警 | 需立即处理 |
| emergency | `$log->emergency()` | 🔴 紧急 | 系统不可用 |

```php
use log\Logger;

$logger = new Logger();
// 默认日志级别为 debug（开发环境）或 warning（生产环境）

// 基础用法
$logger->info('用户登录成功');
$logger->error('数据库连接失败');

// 带上下文的日志 — 提供结构化数据方便搜索和分析
$logger->info('用户执行了操作', [
    'user_id' => 123,
    'action'  => 'create_post',
    'ip'      => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
]);

// 消息中的 {key} 占位符会自动替换为 context 中的对应值
$logger->info('用户 {user} 购买了 {product}', [
    'user'    => 'Alice',
    'product' => 'iPhone 15',
]);
// 日志输出：[2025-01-15 10:30:00] INFO: 用户 Alice 购买了 iPhone 15

// 未被消息占位符引用的 context 值会附加为 JSON（方便日志分析工具解析）
$logger->info('订单创建成功', [
    'order_id' => 456,       // 消息中无 {order_id}，会附加在尾部
    'amount'   => 6999.00,   // 同上
]);
// 输出：[2025-01-15 10:30:00] INFO: 订单创建成功 {"order_id":456,"amount":6999}
```

日志文件路径：`storage/log/2025-01-15.log`（每天一个文件）。

---

## Session 会话管理

用于在多次请求间保持用户状态（如登录状态、购物车等）。

```php
use core\Session;

// 设置
Session::set('user_id', 123);

// 获取（带默认值）
$user = Session::get('user_id', null);

// 检查
Session::has('user_id');    // true/false

// 删除
Session::delete('user_id');

// 一次性 Flash 消息（读取后自动删除，用于"操作成功"提示）
Session::flash('success', '保存成功');
$msg = Session::flash('success');  // 返回 '保存成功'，同时从 Session 中删除

// CSRF Token
$token = Session::token();  // 生成或获取当前 CSRF 令牌

// 清空所有 Session
Session::flush();
```

---

## Hash 加密与哈希

框架提供两种密码学工具：

1. **bcrypt** — 用于存储**用户密码**。单向哈希，不可逆，即使数据库泄露也无法还原密码
2. **AES-256-GCM** — 用于存储**可解密的数据**。双向加密，密文被篡改后解密会报异常

```php
use core\Hash;

// ===== bcrypt（不可逆）=====
$hashed = Hash::make('password123');                             // 生成哈希值
Hash::verify('password123', $hashed);                            // 验证 → true
Hash::verify('wrong', $hashed);                                  // 验证 → false
// 注意：每次 Hash::make() 的结果都不同（因为包含随机盐），但都能正确验证

// ===== AES-256-GCM（可逆）=====
// ⚠️ 使用前请确保 app/config/app.php 中的 'key' 已设置为你的自定义值
$encrypted = Hash::encrypt('需要加密存储的敏感数据');               // 返回密文
$decrypted = Hash::decrypt($encrypted);                           // 返回原始明文
// 如果密文被篡改（哪怕一个字符），decrypt() 会抛出异常（GCM 模式的认证机制）

// ===== 令牌生成 =====
$token = Hash::makeToken();                                       // 生成安全的随机令牌
$key = Hash::makeKey();                                           // 生成安全的随机密钥
```

---

## Config 配置管理

LightPHP 使用 `app/config/` 下的 PHP 文件管理配置（不使用 .env）。这种方式清晰直观，支持注释和复杂数据结构。

```php
// 通过 Application 实例获取配置（点号分隔嵌套路径）
$debug  = $app->getConfig('app.debug', false);                     // app/config/app.php 中的 debug
$dbHost = $app->getConfig('database.connections.mysql.host');      // 嵌套多层的配置路径

// 运行时动态修改配置
$app->setConfig('app.debug', true);

// 使用 Config 静态工具类
$allDbConfig = \config\Config::get('database');                    // 获取整个 database 配置数组
```

配置文件位置：
- `app/config/app.php` — 应用名称、环境、调试模式、加密密钥
- `app/config/database.php` — 数据库连接（支持多连接）
- `app/config/cache.php` — 缓存驱动和路径配置

---

## 错误码参考

| 错误码 | 说明 | 含义 |
|--------|------|------|
| 0 | 成功 | 请求正常处理完毕 |
| -1 | 通用错误 | 未分类的错误 |
| 401 | 未认证 | Token 无效或未提供 |
| 403 | 无权限 | 当前用户无此操作权限 |
| 404 | 资源不存在 | 查询的记录不存在 |
| 422 | 验证失败 | 输入数据未通过校验规则 |
| 429 | 请求过频 | 触发 Throttle 速率限制 |

---

> 📖 **相关文档**：
> - [README.md](../README.md) — 项目总览和快速入门
> - [开发指南](guide.md) — 30 章完整教程，从零到部署
> - [测试指南](testing-guide.md) — 测试编写和运行
> - [ECOMMERCE 教程](ecommerce-full-tutorial.md) — 电商系统完整开发实战
> - [后台管理教程](admin-panel-tutorial.md) — 后台管理系统开发(未完待续)

---

## Macroable 宏扩展（v2.0 新增）

Macroable trait 允许在运行时动态地向类添加方法，无需修改原始类代码。Request 和 Response 类已内置此 trait。

### 注册宏方法

```php
use core\Request;

Request::macro('userAgentIsMobile', function() {
    return (bool) preg_match('/Mobile|Android|iPhone/i', $this->userAgent());
});

$isMobile = $request->userAgentIsMobile();
```

### Mixin 混入

```php
class RequestHelpers
{
    public function userAgentIsMobile(): \Closure
    {
        return function() {
            return (bool) preg_match('/Mobile|Android|iPhone/i', $this->userAgent());
        };
    }
}

Request::mixin(new RequestHelpers());
```

### API 参考

| 方法 | 签名 | 说明 |
|------|------|------|
| `macro` | `static macro(string $name, callable $macro): void` | 注册一个宏方法 |
| `mixin` | `static mixin(object $mixin, bool $replace = true): void` | 批量注册宏 |
| `hasMacro` | `static hasMacro(string $name): bool` | 检查宏是否已注册 |
| `flushMacros` | `static flushMacros(): void` | 清空所有已注册的宏 |

---

## Pipeline 管道（v2.0 新增）

Pipeline 实现洋葱模型的管道模式，请求依次穿过每一层管道到达核心，响应再反向传回。

### API 参考

| 方法 | 签名 | 说明 |
|------|------|------|
| `send` | `send(mixed $passable): self` | 设置通过管道传递的对象 |
| `through` | `through(array $pipes): self` | 设置管道数组 |
| `via` | `via(string $method): self` | 设置在每个管道上调用的方法名（默认 `handle`） |
| `then` | `then(\Closure $destination): mixed` | 运行管道并传入目标闭包 |
| `thenReturn` | `thenReturn(): mixed` | 运行管道并返回结果 |

### 使用示例

```php
use core\Pipeline;

$result = (new Pipeline())
    ->send($request)
    ->through([$middleware1, $middleware2])
    ->via('handle')
    ->then(function($request) {
        return new Response('OK');
    });
```

---

## ExceptionHandler 异常处理器（v2.0 新增）

ExceptionHandler 提供 report/render 分离的异常处理机制。

### API 参考

| 方法 | 签名 | 说明 |
|------|------|------|
| `__construct` | `__construct(?\log\Logger $logger, bool $debug)` | 构造函数 |
| `report` | `report(\Throwable $e): void` | 报告/记录异常 |
| `shouldReport` | `shouldReport(\Throwable $e): bool` | 判断异常是否应该被报告 |
| `render` | `render(Request $request, \Throwable $e): Response` | 将异常渲染为 HTTP 响应 |
| `renderHttpException` | `renderHttpException(HttpException $e): Response` | 渲染 HTTP 异常 |
| `renderException` | `renderException(\Throwable $e): Response` | 渲染通用异常 |
| `shouldReturnJson` | `shouldReturnJson(Request $request): bool` | 判断是否应返回 JSON 响应 |

### 可覆盖属性

| 属性 | 类型 | 说明 |
|------|------|------|
| `$dontReport` | `array` | 不需要记录日志的异常类名列表 |
| `$dontFlash` | `array` | 不应在响应中暴露的敏感数据字段列表 |

---

## SoftDelete 软删除（v2.0 新增）

SoftDelete trait 为模型提供软删除功能，删除时设置 `deleted_at` 字段而非真正删除记录。

### API 参考

| 方法 | 签名 | 说明 |
|------|------|------|
| `forceDelete` | `static forceDelete(): void` | 启用强制删除模式（物理删除） |
| `softDelete` | `static softDelete(): void` | 恢复软删除模式（默认） |
| `trashed` | `trashed(): bool` | 检查当前实例是否已被软删除 |
| `restore` | `restore(): bool` | 恢复一个被软删除的模型 |
| `withTrashed` | `withTrashed(): static` | 包含软删除记录的查询 |
| `onlyTrashed` | `onlyTrashed(): static` | 仅查询软删除的模型 |
| `delete` | `delete(int\|string $id): int` | 删除模型记录（软删除或强制删除） |

### 使用示例

```php
use traits\SoftDelete;

class Post extends Model
{
    use SoftDelete;
}

$post = Post::find(1);
$post->delete($post->id);       // 软删除
$all = Post::find(1)->withTrashed()->fetchAll();  // 包含软删除
$post->restore();               // 恢复
Post::forceDelete();            // 强制物理删除模式
```

---

## HasModelEvents 模型事件（v2.0 新增）

HasModelEvents trait 为模型提供事件系统和观察者模式支持。

### API 参考

| 方法 | 签名 | 说明 |
|------|------|------|
| `onEvent` | `static onEvent(string $event, callable $callback): void` | 注册事件监听器 |
| `observe` | `static observe(object\|string $observer): void` | 注册观察者类 |
| `flushEventListeners` | `static flushEventListeners(): void` | 清空所有事件监听器和观察者 |
| `fireEvent` | `fireEvent(string $event): bool` | 触发指定事件 |

### 支持的事件

| 事件 | 触发时机 |
|------|---------|
| `creating` | 创建前 |
| `created` | 创建后 |
| `updating` | 更新前 |
| `updated` | 更新后 |
| `saving` | 保存前 |
| `saved` | 保存后 |
| `deleting` | 删除前 |
| `deleted` | 删除后 |

---

## Seeder 数据填充（v2.0 新增）

Seeder 抽象类用于向数据库填充初始数据或测试数据。

### API 参考

| 方法 | 签名 | 说明 |
|------|------|------|
| `__construct` | `__construct(\db\Connection $db)` | 构造函数 |
| `run` | `abstract run(): void` | 执行种子数据填充（必须实现） |
| `call` | `call(string $seederClass): void` | 调用另一个种子类执行 |
| `register` | `static register(string $seederClass): void` | 注册一个种子类 |
| `runAll` | `static runAll(\db\Connection $db): void` | 运行所有已注册的种子类 |

---

## 中间件别名/组/全局注册（v2.0 新增）

Router 新增了中间件别名、中间件组和全局中间件的注册方法。

### API 参考

| 方法 | 签名 | 说明 |
|------|------|------|
| `aliasMiddleware` | `aliasMiddleware(string $name, string\|callable $middleware): self` | 注册中间件别名 |
| `middlewareGroup` | `middlewareGroup(string $name, array $middlewares): self` | 注册中间件组 |
| `setGlobalMiddleware` | `setGlobalMiddleware(array $middlewares): self` | 设置全局中间件 |

### 使用示例

```php
$router = new Router();

$router->aliasMiddleware('auth', \middleware\Auth::class);
$router->middlewareGroup('api', [
    \middleware\Cors::class,
    \middleware\Throttle::class,
]);
$router->setGlobalMiddleware([
    \middleware\Cors::class,
]);
```

---

## Request 类型过滤（v2.0 新增）

Request 新增了类型安全的输入获取方法。

### API 参考

| 方法 | 签名 | 说明 |
|------|------|------|
| `string` | `string(string $key, string $default = ''): string` | 获取输入值并转为字符串 |
| `integer` | `integer(string $key, int $default = 0): int` | 获取输入值并转为整数 |
| `float` | `float(string $key, float $default = 0.0): float` | 获取输入值并转为浮点数 |
| `boolean` | `boolean(string $key, bool $default = false): bool` | 获取输入值并转为布尔值 |
| `arrayInput` | `arrayInput(string $key, array $default = []): array` | 获取输入值并转为数组 |
| `merge` | `merge(array $data): self` | 合并数据到请求 |

---

## Macroable 宏扩展（v2.0 新增）

Macroable trait 允许在运行时动态地向类添加方法，无需修改原始类代码。Request 和 Response 类已内置此 trait。

### 注册宏方法

```php
use core\Request;

// 注册实例宏
Request::macro('userAgentIsMobile', function() {
    return (bool) preg_match('/Mobile|Android|iPhone/i', $this->userAgent());
});

// 使用
$isMobile = $request->userAgentIsMobile();
```

### 注册静态宏

```php
Request::macro('createFromGlobals', function() {
    return new static();
});

// 使用
$req = Request::createFromGlobals();
```

### Mixin 混入

将一个对象的所有公共方法批量注册为宏：

```php
class RequestHelpers
{
    public function userAgentIsMobile(): \Closure
    {
        return function() {
            return (bool) preg_match('/Mobile|Android|iPhone/i', $this->userAgent());
        };
    }

    public function ipIsLocal(): \Closure
    {
        return function() {
            return in_array($this->ip(), ['127.0.0.1', '::1']);
        };
    }
}

Request::mixin(new RequestHelpers());
```

### API 参考

| 方法 | 签名 | 说明 |
|------|------|------|
| `macro` | `static macro(string $name, callable $macro): void` | 注册一个宏方法 |
| `mixin` | `static mixin(object $mixin, bool $replace = true): void` | 批量注册宏（$replace 控制是否覆盖已有宏） |
| `hasMacro` | `static hasMacro(string $name): bool` | 检查宏是否已注册 |
| `flushMacros` | `static flushMacros(): void` | 清空所有已注册的宏 |

---

## Pipeline 管道（v2.0 新增）

Pipeline 实现洋葱模型的管道模式，请求依次穿过每一层管道到达核心，响应再反向传回。

### API 参考

| 方法 | 签名 | 说明 |
|------|------|------|
| `send` | `send(mixed $passable): self` | 设置通过管道传递的对象 |
| `through` | `through(array $pipes): self` | 设置管道数组 |
| `via` | `via(string $method): self` | 设置在每个管道上调用的方法名（默认 `handle`） |
| `then` | `then(\Closure $destination): mixed` | 运行管道并传入目标闭包 |
| `thenReturn` | `thenReturn(): mixed` | 运行管道并返回结果（最内层原样返回） |
| `carry` | `carry(): \Closure` | 获取洋葱切片闭包 |

### 使用示例

```php
use core\Pipeline;

$result = (new Pipeline())
    ->send($request)
    ->through([$middleware1, $middleware2])
    ->via('handle')
    ->then(function($request) {
        return new Response('OK');
    });
```

---

## ExceptionHandler 异常处理器（v2.0 新增）

ExceptionHandler 提供 report/render 分离的异常处理机制，支持配置不记录日志的异常类型和不暴露的敏感字段。

### API 参考

| 方法 | 签名 | 说明 |
|------|------|------|
| `__construct` | `__construct(?\log\Logger $logger, bool $debug)` | 构造函数 |
| `report` | `report(\Throwable $e): void` | 报告/记录异常 |
| `shouldReport` | `shouldReport(\Throwable $e): bool` | 判断异常是否应该被报告 |
| `render` | `render(Request $request, \Throwable $e): Response` | 将异常渲染为 HTTP 响应 |
| `renderHttpException` | `renderHttpException(HttpException $e): Response` | 渲染 HTTP 异常 |
| `renderException` | `renderException(\Throwable $e): Response` | 渲染通用异常 |
| `shouldReturnJson` | `shouldReturnJson(Request $request): bool` | 判断是否应返回 JSON 响应 |

### 可覆盖属性

| 属性 | 类型 | 说明 |
|------|------|------|
| `$dontReport` | `array` | 不需要记录日志的异常类名列表 |
| `$dontFlash` | `array` | 不应在响应中暴露的敏感数据字段列表 |

---

## SoftDelete 软删除（v2.0 新增）

SoftDelete trait 为模型提供软删除功能，删除时设置 `deleted_at` 字段而非真正删除记录。

### API 参考

| 方法 | 签名 | 说明 |
|------|------|------|
| `forceDelete` | `static forceDelete(): void` | 启用强制删除模式（物理删除） |
| `softDelete` | `static softDelete(): void` | 恢复软删除模式（默认） |
| `trashed` | `trashed(): bool` | 检查当前实例是否已被软删除 |
| `restore` | `restore(): bool` | 恢复一个被软删除的模型 |
| `withTrashed` | `withTrashed(): static` | 包含软删除记录的查询 |
| `onlyTrashed` | `onlyTrashed(): static` | 仅查询软删除的模型 |
| `delete` | `delete(int\|string $id): int` | 删除模型记录（软删除或强制删除） |

### 使用示例

```php
use traits\SoftDelete;

class Post extends Model
{
    use SoftDelete;
}

// 软删除
$post = Post::find(1);
$post->delete($post->id);

// 查询包含软删除
$all = Post::find(1)->withTrashed()->fetchAll();

// 仅查询软删除
$trashed = Post::find(1)->onlyTrashed()->fetchAll();

// 恢复
$post->restore();

// 强制物理删除
Post::forceDelete();
```

---

## HasModelEvents 模型事件（v2.0 新增）

HasModelEvents trait 为模型提供事件系统和观察者模式支持。

### API 参考

| 方法 | 签名 | 说明 |
|------|------|------|
| `onEvent` | `static onEvent(string $event, callable $callback): void` | 注册事件监听器 |
| `observe` | `static observe(object\|string $observer): void` | 注册观察者类 |
| `flushEventListeners` | `static flushEventListeners(): void` | 清空所有事件监听器和观察者 |
| `fireEvent` | `fireEvent(string $event): bool` | 触发指定事件 |

### 支持的事件

| 事件 | 触发时机 |
|------|---------|
| `creating` | 创建前 |
| `created` | 创建后 |
| `updating` | 更新前 |
| `updated` | 更新后 |
| `saving` | 保存前 |
| `saved` | 保存后 |
| `deleting` | 删除前 |
| `deleted` | 删除后 |

---

## Seeder 数据填充（v2.0 新增）

Seeder 抽象类用于向数据库填充初始数据或测试数据。

### API 参考

| 方法 | 签名 | 说明 |
|------|------|------|
| `__construct` | `__construct(\db\Connection $db)` | 构造函数 |
| `run` | `abstract run(): void` | 执行种子数据填充（必须实现） |
| `call` | `call(string $seederClass): void` | 调用另一个种子类执行 |
| `register` | `static register(string $seederClass): void` | 注册一个种子类 |
| `runAll` | `static runAll(\db\Connection $db): void` | 运行所有已注册的种子类 |
| `getSeeders` | `static getSeeders(): array` | 获取所有已注册的种子类 |

---

## 中间件别名/组/全局注册（v2.0 新增）

Router 新增了中间件别名、中间件组和全局中间件的注册方法。

### API 参考

| 方法 | 签名 | 说明 |
|------|------|------|
| `aliasMiddleware` | `aliasMiddleware(string $name, string\|callable $middleware): self` | 注册中间件别名 |
| `middlewareGroup` | `middlewareGroup(string $name, array $middlewares): self` | 注册中间件组 |
| `setGlobalMiddleware` | `setGlobalMiddleware(array $middlewares): self` | 设置全局中间件 |

### 使用示例

```php
$router = new Router();

// 注册别名
$router->aliasMiddleware('auth', \middleware\Auth::class);

// 注册中间件组
$router->middlewareGroup('api', [
    \middleware\Cors::class,
    \middleware\Throttle::class,
]);

// 设置全局中间件
$router->setGlobalMiddleware([
    \middleware\Cors::class,
]);

// 在路由中使用别名
$router->get('/profile', [UserController::class, 'profile'])
    ->middleware(['auth']);
```

---

## Request 类型过滤（v2.0 新增）

Request 新增了类型安全的输入获取方法。

### API 参考

| 方法 | 签名 | 说明 |
|------|------|------|
| `string` | `string(string $key, string $default = ''): string` | 获取输入值并转为字符串 |
| `integer` | `integer(string $key, int $default = 0): int` | 获取输入值并转为整数 |
| `float` | `float(string $key, float $default = 0.0): float` | 获取输入值并转为浮点数 |
| `boolean` | `boolean(string $key, bool $default = false): bool` | 获取输入值并转为布尔值 |
| `arrayInput` | `arrayInput(string $key, array $default = []): array` | 获取输入值并转为数组 |
| `merge` | `merge(array $data): self` | 合并数据到请求 |