# LightPHP 测试指南

> 📖 **为什么要测试？** 测试就像是自动化的"质检员"。每次你修改代码后，运行测试可以在几秒内验证所有功能是否仍然正常工作，避免"改了一个地方，坏了另一个地方"的问题。对于生产环境的项目，完善的测试是保证代码质量的生命线。

## 概述

LightPHP 使用自带的轻量级测试框架，测试文件全部集中在 `tests/` 目录下。当前共 **608 个测试用例**，覆盖了框架所有核心组件：

| 测试模块 | 说明 | 测试数量 |
|---------|------|---------|
| Router | 路由注册、匹配、参数、中间件、别名、组 | 14 |
| Container | 依赖注入容器（PSR-11）、has() 契约对齐、null 单例、别名递归 | 19 |
| Request | HTTP 请求参数与方法、类型过滤、Content-Type 大小写 | 14 |
| Response | HTTP 响应构建 | 1 |
| Validate | 验证规则与 passes/fails、date 空格式、digits 空参数、:length 占位符 | 11 |
| Session | 会话读写、删除、Flash | 5 |
| Cookie | Cookie 静态方法与安全选项 | 6 |
| Hash | 密码哈希、AES 加密解密 | 8 |
| Env | 环境变量加载、读取、批量 | 5 |
| Model | ORM 方法、访问器、修改器、作用域、关联、事件顺序、create PK、saved 0-row、firstOrCreate | 37 |
| View | 视图自动转义 | 3 |
| Middleware | 抽象类、CSRF、CORS、Throttle、通配符嵌套路径、边界秒数 | 10 |
| FileCache | 文件缓存、标签集成、pull、批量操作、attachTag 日志 | 24 |
| ApiDoc | API 文档生成、Markdown、JSON | 5 |
| EventDispatcher | 事件监听、触发、优先级、订阅 | 14 |
| Collection | 数组集合 map/filter/groupBy 等、collapse 关联数组、take(-N)、last null 键 | 48 |
| Facade | 门面模式与容器解析 | 3 |
| ServiceProvider | 服务提供者抽象与注册 | 5 |
| Contracts | 接口契约定义 | 3 |
| Blade | 模板编译、指令、echo 转义、栈空守卫、@each、render 重置、@csrf()、三花括号 | 21 |
| Command/Console | CLI 命令签名、参数、选项、注册 | 9 |
| Schema/Blueprint | 数据库迁移建表、字段定义 | 20 |
| Application | 容器配置、事件、Provider 注册、setConfig 同步 | 5 |
| Exception | 异常层级与处理器 | 9 |
| Pipeline | 管道洋葱模型、via、thenReturn | 5 |
| Macroable | 宏注册、调用、mixin、flush | 14 |
| SoftDelete | 软删除 trait、trashed、force 切换 | 6 |
| HasModelEvents | 模型事件、观察者、返回 false 取消 | 4 |
| Seeder | 数据填充抽象类 | 6 |
| RedisCache | 类结构、接口契约、完整功能、attachTag TTL | 24 |
| MemcachedCache | 类结构、接口契约、完整功能、unserialize 语义、TTL > 30 天 | 26 |
| TaggedCache | 标签化缓存、flush、批量 | 23 |
| QueryBuilder | SQL 构建器、having LIKE、聚合方法锁重置 | 2 |
| **合计** | | **608** |

> 💡 **单元测试 vs 集成测试**：单元测试是测试单个函数/方法的正确性（例如"Hash::make 能生成 bcrypt 哈希吗？"）；集成测试是测试多个模块协同工作的正确性（例如"用户注册接口能正确处理 POST 请求吗？"）。当前项目以单元测试为主，覆盖了框架所有独立功能点。

---

## 快速开始

### 运行所有测试

```bash
php tests/run_tests.php
```

### 运行结果示例

```
LightPHP Unit Tests
===================

[PASS] Env::load loads .env file
[PASS] Env::get returns value
[PASS] Env::get returns default for missing key
[PASS] Cookie::set and get
[PASS] Cookie::get returns default for missing key
[PASS] Cookie::has returns true for existing cookie
[PASS] Cookie::delete removes cookie
[PASS] Hash::make creates bcrypt hash
[PASS] Hash::verify correct password
[PASS] Hash::verify incorrect password
[PASS] Hash::encrypt and decrypt
[PASS] Hash::decrypt invalid data returns null
[PASS] Session::set and get
[PASS] Session::get returns default for missing key
[PASS] Session::has returns true for existing key
[PASS] Session::delete removes key
[PASS] Session::token generates token
[PASS] Validate::required rule
[PASS] Validate::email rule
[PASS] Validate::min rule
[PASS] Validate::max rule
[PASS] Validate::numeric rule
[PASS] Validate::integer rule
[PASS] Validate::float rule
[PASS] Validate::url rule
[PASS] Validate::ip rule
[PASS] Validate::alpha rule
[PASS] Validate::alphaNum rule
[PASS] Validate::in rule
[PASS] Validate::notIn rule
[PASS] Validate::regex rule
[PASS] Validate::date rule
[PASS] Validate::confirmed rule
[PASS] Validate::passes returns true for valid data
[PASS] Validate::fails returns true for invalid data
[PASS] Validate::firstError returns first error
[PASS] Request::get returns query param
[PASS] Request::post returns post param
[PASS] Request::all returns all input
[PASS] Request::only returns selected fields
[PASS] Request::except excludes fields
[PASS] Request::has checks field existence
[PASS] Request::method returns request method
[PASS] Request::isAjax detects AJAX
[PASS] Request::isGet/isPost checks method
[PASS] FileCache::set and get
[PASS] FileCache::has checks existence
[PASS] FileCache::delete removes item
[PASS] FileCache::remember caches callback result
[PASS] FileCache::increment/decrement

Results: 608 passed, 0 failed
All tests passed!
```

---

## 测试框架

### 测试类结构

LightPHP 使用轻量级测试框架，核心类为 `TestRunner`，内联定义于 `tests/run_tests.php`（并非独立的 `tests/TestCase.php` 文件）。测试不通过继承基类来编写，而是通过 `$runner->run()` 注册一个闭包，闭包接收测试对象 `$t`，在其上调用断言方法：

```php
$runner->run('Test Name', function($t) {
    $t->assertEquals($expected, $actual);
    $t->assertTrue($condition);
    $t->assertIsString($value);
    $t->assertThrows(\RuntimeException::class, fn() => throwIt());
    $t->assertContains($needle, $haystack);
});
```

`TestRunner` 在文件末尾实例化（`$runner = new TestRunner();`），所有测试用例均以 `$runner->run('用例名称', function($t) { ... })` 的形式依次注册。每个用例独立运行：断言失败会抛出 `RuntimeException` 并被 `run()` 捕获，记为失败后继续执行下一个用例，不会中断整体流程。运行结束后调用 `$runner->summary()` 输出汇总结果。

---

## 测试覆盖范围

`tests/run_tests.php` 包含 608 个测试用例，覆盖框架所有核心模块。下表给出每个模块的代表性测试场景与目的：

### 1. 核心组件测试

- **Router（14）**：基础路由注册、路由分组、参数匹配、middleware 方法、aliasMiddleware、middlewareGroup、setGlobalMiddleware
- **Container（19）**：bind/singleton/has、自动依赖解析、实例方法（instance）、has() PSR-11 契约对齐（抽象类/接口/具体类/契约一致性）、null 单例缓存、别名递归验证
- **Request（14）**：method/isPost、string/integer/float/boolean/arrayInput 类型过滤、merge 数据合并、Content-Type 大小写不敏感
- **Response（1）**：JSON 响应构建
- **Application（5）**：setConfig（支持点分键）、getEvents、registerProvider、setConfig 容器同步

### 2. 数据校验与安全

- **Validate（11）**：required/email、min/max、passes/fails、unique 规则（抛异常）、date 空格式默认、digits 空参数跳过、:length 占位符映射
- **Hash（8）**：bcrypt 哈希与 verify、AES 加密解密、错误数据返回 null
- **Session（5）**：set/get/delete、Flash set/get
- **Cookie（6）**：静态方法存在性、delete 安全参数
- **Env（5）**：load/get/默认值、has/all 批量读取

### 3. 缓存体系（97）

- **FileCache（24）**：has、remember、increment/decrement、tags、attachTag/flushByTag、pull、deleteMany、setMany/many、attachTag 失败日志记录
- **TaggedCache（23）**：set/get/has/delete、remember、many/setMany、flush、tags 追加、默认值
- **RedisCache（24）**：类结构、扩展未安装抛异常、接口契约、connection、完整功能、attachTag TTL 对齐（设 TTL、保留永久、只延长不缩短）、attachTag 在 sAdd 前读取 TTL
- **MemcachedCache（26）**：类结构、扩展未安装抛异常、接口契约、connection、完整功能、unserialize 语义（损坏返回 null、保留 false 值）、TTL > 30 天转换为时间戳

### 4. 数据库与模型（75）

- **Model（37）**：静态方法、getForeignKey、ORM 方法（hasOne/hasMany/belongsTo/belongsToMany/eagerLoad）、toArray/toJson、with/LoadRelation、访问器（getNameAttribute）、修改器（setEmailAttribute）、查询作用域（scopeActive）、create/update 事件顺序（监听器收到已填充属性、可修改字段、返回 false 中止、save() 回归）、create 前 PK/exists、saved 0 行触发、firstOrCreate 异常
- **QueryBuilder（2）**：having 接受 LIKE 操作符、聚合方法重置 forUpdate/lock
- **Schema/Blueprint（20）**：Schema/Blueprint/Migration 类存在、setConnection、hasTable/hasColumn、字段定义、rename、truncate
- **SoftDelete（6）**：trait 存在、trashed 方法、force() 实例方法（启用强制删除模式，默认即软删除模式，无 softDelete() 方法）
- **HasModelEvents（4）**：trait 存在、onEvent、fireEvent 返回 false 取消、observe 观察者
- **Seeder（6）**：抽象类与 run/register/call/runAll 方法

### 5. 中间件与管道（24）

- **Middleware（10）**：抽象类与 handle 方法、CsrfMiddleware、CORS、Throttle、通配符匹配嵌套路径、Throttle 边界秒数
- **Pipeline（5）**：基本洋葱模型、thenReturn 直接返回、空管道、via 自定义方法名、中间件可修改请求/响应
- **ExceptionHandler（3）**：类存在、shouldReport 过滤、dontReport 忽略列表
- **Exception（6）**：FrameworkException 异常层级结构

### 6. 事件、容器与契约（25）

- **EventDispatcher（14）**：listen/dispatch、wildcard、hasListeners、stop propagation、until、priority、forget、flush、subscribe
- **Facade（3）**：类存在、容器未设置时抛 RuntimeException
- **ServiceProvider（5）**：抽象类与 register/boot、子类持有容器
- **Contracts（3）**：CacheInterface/LoggerInterface/ConnectionInterface 存在

### 7. 集合与函数式（48）

**Collection**：basic、map、filter、filter 无回调、where、whereIn、pluck、pluck with key、only、except、sum、avg、min/max、sortBy、take、skip、first/last、first with callback、groupBy、keyBy、contains、isEmpty/isNotEmpty、unique、reduce、each、tap、pipe、json serialize、array access、countable

### 8. 视图与模板（24）

- **View（3）**：自动转义 HTML、withoutAutoEscape 关闭转义
- **Blade（21）**：类与 render/compileString 存在、echo 转义编译、原始 echo、if/foreach 指令编译、自定义 directive、endSection/endPush/endPrepend 栈空守卫（孤儿结束指令、正常流程回归）、@each 渲染内容、render 重置 pushStack、@csrf() 无残留、三花括号转义

### 9. CLI 控制台（9）

**Command/Console**：signature 解析、argument 解析、option 解析、默认参数、register/list、unknown command 返回 1、signature with defaults

### 10. 扩展机制（14）

**Macroable**：注册并调用、hasMacro、flushMacros、闭包绑定 $this、mixin 批量注册、mixin 不覆盖模式、调用不存在宏抛异常、Response 宏扩展（csv）

### 11. API 文档（5）

**ApiDoc**：generate 返回数组、toMarkdown 包含标题、toJson 是合法 JSON

> 💡 **覆盖率说明**：以上覆盖范围从 `tests/run_tests.php` 中的 `$runner->run(...)` 用例直接整理得出，与代码实现完全一致。如果新增功能，请同步添加对应测试用例。

---

## 编写新测试

### 添加测试步骤

> 💡 **新手提示**：写测试就像写"检查清单"。你描述一个场景，调用一个方法，然后断言"结果应该是 X"。如果结果不是 X，测试就失败，提醒你这里有 bug。

1. 打开 `tests/run_tests.php`
2. 在 `$runner = new TestRunner();` 之后，添加一个新的 `$runner->run('用例名称', function($t) { ... });` 调用
3. 在闭包内通过 `$t->assertXxx(...)` 编写断言

### 测试示例

```php
// 基本断言：验证结果是否符合预期
$runner->run('MyFeature - Do Something', function($t) {
    $result = MyFeature::doSomething('input');
    $t->assertEquals('expected', $result, 'MyFeature::doSomething 返回了预期结果');
});

// 测试 null 值
$runner->run('SomeClass - Find Missing ID', function($t) {
    $result = SomeClass::find(999);  // 不存在的 ID
    $t->assertNull($result, 'find 不存在的 ID 应该返回 null');
});

// 测试数组
$runner->run('Service - Get List', function($t) {
    $items = Service::getList();
    $t->assertCount(5, $items, 'getList 返回了 5 条数据');
    $t->assertNotNull($items[0]['id'], '列表第一项有 id 字段');
});

// 测试字符串（注意：Blade 的 render() 是实例方法，需先实例化）
$runner->run('Blade - Render With Variable', function($t) {
    $blade = new \view\Blade(VIEW_PATH, STORAGE_PATH . 'cache/blade/');
    $html = $blade->render('welcome', ['name' => 'World']);
    $t->assertStringContains('World', $html, '渲染结果包含传入的变量值');
});
```

### 可用的断言方法

以下方法均在 `TestRunner` 中定义，通过测试对象 `$t` 调用（如 `$t->assertEquals(...)`）：

| 方法 | 说明 | 示例 |
|------|------|------|
| `assert($condition, $msg)` | 通用断言，条件为真则通过 | `$t->assert($count > 0, '数据不为空')` |
| `assertEquals($expected, $actual, $msg)` | 验证等于（=== 严格比较） | `$t->assertEquals(42, $answer, '答案是 42')` |
| `assertNotEquals($expected, $actual, $msg)` | 验证不等于 | `$t->assertNotEquals(0, $count, '数量不为 0')` |
| `assertNull($value, $msg)` | 验证值为 null | `$t->assertNull($result, '结果为空')` |
| `assertNotNull($value, $msg)` | 验证值不为 null | `$t->assertNotNull($user, '用户存在')` |
| `assertTrue($value, $msg)` | 验证值为 true | `$t->assertTrue($success, '操作成功')` |
| `assertFalse($value, $msg)` | 验证值为 false | `$t->assertFalse($failed, '操作未失败')` |
| `assertIsArray($value, $msg)` | 验证值为数组 | `$t->assertIsArray($list, '返回数组')` |
| `assertIsString($value, $msg)` | 验证值为字符串 | `$t->assertIsString($name, '名称是字符串')` |
| `assertIsInt($value, $msg)` | 验证值为整数 | `$t->assertIsInt($id, 'ID 是整数')` |
| `assertCount($n, $array, $msg)` | 验证数组元素个数 | `$t->assertCount(3, $items, '有 3 条记录')` |
| `assertArrayHasKey($key, $array, $msg)` | 验证数组包含指定键 | `$t->assertArrayHasKey('id', $row, '包含 id 键')` |
| `assertStringContains($needle, $haystack, $msg)` | 验证字符串包含子串 | `$t->assertStringContains('ok', $html, '包含 ok')` |
| `assertContains($needle, $array, $msg)` | 验证数组包含指定值 | `$t->assertContains(2, $ids, '包含 ID 2')` |
| `assertInstanceOf($class, $object, $msg)` | 验证对象为指定类的实例 | `$t->assertInstanceOf(User::class, $obj, '是 User 实例')` |
| `assertThrows($exceptionClass, $callback, $msg)` | 验证回调抛出指定异常 | `$t->assertThrows(\RuntimeException::class, fn() => doBad(), '应抛异常')` |

### 测试数据库相关功能

数据库测试需要配置 `app/config/database.php` 中的数据库连接信息：

```php
// app/config/database.php
return [
    'default' => 'mysql',
    'connections' => [
        'mysql' => [
            'host'     => env('DB_HOST', '127.0.0.1'),
            'port'     => env('DB_PORT', 3306),
            'database' => env('DB_DATABASE', 'lightphp_test'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset'  => 'utf8mb4',
        ],
    ],
];
```

> ⚠️ **强烈建议使用独立的测试数据库**，避免测试过程影响开发和生产数据。

```php
$runner->run('Database - Query User', function($t) {
    $config = \config\Config::get('database');
    $default = $config['default'] ?? 'mysql';
    $connection = $config['connections'][$default] ?? [];

    $db = new \db\Connection($connection);
    $result = $db->table('users')->where('id', '=', 1)->fetch();
    $t->assertNotNull($result, '数据库查询返回了结果');
});
```

---

## 开发环境搭建

### 环境要求

- PHP 8.0+
- MySQL 5.7+ / MariaDB 10.3+（如需数据库测试）
- 开启 PDO 扩展

### Windows 环境

1. 安装 XAMPP 或 WAMP
2. 确保 PHP 在 PATH 中
3. 配置 `.env` 文件

```bash
# 运行测试
php tests/run_tests.php
```

### Linux / macOS 环境

```bash
# 安装 PHP
sudo apt install php php-mysql php-mbstring php-xml

# 配置 .env
cp .env.example .env

# 运行测试
php tests/run_tests.php
```

### Docker 环境

```dockerfile
FROM php:8.2-cli

RUN docker-php-ext-install pdo_mysql

WORKDIR /app
COPY . .

CMD ["php", "tests/run_tests.php"]
```

```bash
docker build -t lightphp-test .
docker run --rm lightphp-test
```

---

## 持续集成

### GitHub Actions 示例

```yaml
name: Tests

on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest

    services:
      mysql:
        image: mysql:8.0
        env:
          MYSQL_ROOT_PASSWORD: root
          MYSQL_DATABASE: lightphp_test
        ports:
          - 3306:3306

    steps:
      - uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
          extensions: pdo_mysql, mbstring

      - name: Configure environment
        run: cp .env.example .env

      - name: Run tests
        run: php tests/run_tests.php
```

---

## 测试最佳实践

1. **测试独立性**：每个测试应该独立运行，不依赖其他测试的状态
2. **清理状态**：测试完成后清理 Session、Cookie 等状态
3. **边界测试**：测试边界条件（空值、null、最大长度等）
4. **错误路径**：不仅测试正常流程，也要测试异常情况
5. **命名规范**：测试名称应清晰描述测试内容
6. **使用测试数据库**：数据库测试使用独立的测试数据库

---

## 常见问题

### Q: 运行测试时出现 Session 警告？

A: CLI 模式下 Session 不可用是正常的，测试框架会自动处理此情况。

### Q: 缓存测试失败？

A: 确保 `storage/cache/` 目录存在且有写入权限：

```bash
mkdir -p storage/cache
chmod 755 storage/cache
```

### Q: 数据库测试失败？

A: 检查 `.env` 文件中的数据库配置是否正确，确保数据库服务正在运行。

### Q: 如何只运行特定测试？

A: 目前测试运行器执行所有测试。如需只运行特定测试，可以临时注释掉 `run_tests.php` 中不需要的测试调用。
