# LightPHP 测试指南

> 📖 **为什么要测试？** 测试就像是自动化的"质检员"。每次你修改代码后，运行测试可以在几秒内验证所有功能是否仍然正常工作，避免"改了一个地方，坏了另一个地方"的问题。对于生产环境的项目，完善的测试是保证代码质量的生命线。

## 概述

LightPHP 使用自带的轻量级测试框架，测试文件全部集中在 `tests/` 目录下。当前共 **173 个测试用例**，覆盖了框架所有核心组件：

| 测试模块 | 说明 | 测试数量 |
|---------|------|---------|
| Router | 路由注册、匹配、参数、中间件、别名、组 | 7 |
| Container | 依赖注入容器（PSR-11） | 6 |
| Request | HTTP 请求参数与方法、类型过滤 | 7 |
| Response | HTTP 响应构建 | 1 |
| Validate | 验证规则与 passes/fails | 6 |
| Session | 会话读写、删除、Flash | 3 |
| Cookie | Cookie 静态方法与安全选项 | 2 |
| Hash | 密码哈希、AES 加密解密 | 3 |
| Env | 环境变量加载、读取、批量 | 2 |
| Model | ORM 方法、访问器、修改器、作用域、关联 | 11 |
| View | 视图自动转义 | 2 |
| Middleware | 抽象类、CSRF、CORS、Throttle | 4 |
| FileCache | 文件缓存、标签集成、pull、批量操作 | 8 |
| ApiDoc | API 文档生成、Markdown、JSON | 3 |
| EventDispatcher | 事件监听、触发、优先级、订阅 | 9 |
| Collection | 数组集合 map/filter/groupBy 等 | 30 |
| Facade | 门面模式与容器解析 | 2 |
| ServiceProvider | 服务提供者抽象与注册 | 2 |
| Blade | 模板编译、指令、echo 转义 | 6 |
| Command/Console | CLI 命令签名、参数、选项、注册 | 7 |
| Schema/Blueprint | 数据库迁移建表、字段定义 | 6 |
| Application | 容器配置、事件、Provider 注册 | 3 |
| Exception | 异常层级与处理器 | 4 |
| Pipeline | 管道洋葱模型、via、thenReturn | 5 |
| Macroable | 宏注册、调用、mixin、flush | 10 |
| SoftDelete | 软删除 trait、trashed、force 切换 | 3 |
| HasModelEvents | 模型事件、观察者、返回 false 取消 | 4 |
| Seeder | 数据填充抽象类 | 1 |
| RedisCache | 类结构、接口契约、完整功能 | 5 |
| MemcachedCache | 类结构、接口契约、完整功能 | 5 |
| TaggedCache | 标签化缓存、flush、批量 | 10 |
| **合计** | | **173** |

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

Results: 259 passed, 0 failed
All tests passed!
```

---

## 测试框架

### 测试类结构

LightPHP 使用轻量级测试框架，核心类为 `tests\TestCase`：

```php
<?php

class TestCase
{
    protected int $passed = 0;
    protected int $failed = 0;
    protected array $errors = [];

    public function assert(mixed $condition, string $message): void
    {
        if ($condition) {
            $this->passed++;
            echo "[PASS] $message\n";
        } else {
            $this->failed++;
            $this->errors[] = $message;
            echo "[FAIL] $message\n";
        }
    }

    public function assertEquals(mixed $expected, mixed $actual, string $message): void
    {
        $this->assert($expected === $actual, "$message (expected: " . var_export($expected, true) . ", got: " . var_export($actual, true) . ")");
    }

    public function assertNull(mixed $value, string $message): void
    {
        $this->assert($value === null, $message);
    }

    public function assertNotNull(mixed $value, string $message): void
    {
        $this->assert($value !== null, $message);
    }

    public function assertTrue(mixed $value, string $message): void
    {
        $this->assert($value === true, $message);
    }

    public function assertFalse(mixed $value, string $message): void
    {
        $this->assert($value === false, $message);
    }

    public function assertCount(int $expected, array $array, string $message): void
    {
        $this->assertEquals($expected, count($array), $message);
    }

    public function assertStringContains(string $needle, string $haystack, string $message): void
    {
        $this->assert(str_contains($haystack, $needle), $message);
    }

    public function getResults(): array
    {
        return [
            'passed' => $this->passed,
            'failed' => $this->failed,
            'errors' => $this->errors,
        ];
    }
}
```

---

## 测试覆盖范围

`tests/run_tests.php` 包含 173 个测试用例，覆盖框架所有核心模块。下表给出每个模块的代表性测试场景与目的：

### 1. 核心组件测试

- **Router（7）**：基础路由注册、路由分组、参数匹配、middleware 方法、aliasMiddleware、middlewareGroup、setGlobalMiddleware
- **Container（6）**：bind/singleton/has、自动依赖解析、实例方法（instance）
- **Request（7）**：method/isPost、string/integer/float/boolean/arrayInput 类型过滤、merge 数据合并
- **Response（1）**：JSON 响应构建
- **Application（3）**：setConfig（支持点分键）、getEvents、registerProvider

### 2. 数据校验与安全

- **Validate（6）**：required/email、min/max、passes/fails、unique 规则（抛异常）
- **Hash（3）**：bcrypt 哈希与 verify、AES 加密解密、错误数据返回 null
- **Session（3）**：set/get/delete、Flash set/get
- **Cookie（2）**：静态方法存在性、delete 安全参数
- **Env（2）**：load/get/默认值、has/all 批量读取

### 3. 缓存体系（28）

- **FileCache（8）**：has、remember、increment/decrement、tags、attachTag/flushByTag、pull、deleteMany、setMany/many
- **TaggedCache（10）**：set/get/has/delete、remember、many/setMany、flush、tags 追加、默认值
- **RedisCache（5）**：类结构、扩展未安装抛异常、接口契约、connection、完整功能
- **MemcachedCache（5）**：类结构、扩展未安装抛异常、接口契约、connection、完整功能

### 4. 数据库与模型（22）

- **Model（8）**：静态方法、getForeignKey、ORM 方法（hasOne/hasMany/belongsTo/belongsToMany/eagerLoad）、toArray/toJson、with/LoadRelation、访问器（getNameAttribute）、修改器（setEmailAttribute）、查询作用域（scopeActive）
- **Schema/Blueprint（6）**：Schema/Blueprint/Migration 类存在、setConnection、hasTable/hasColumn、字段定义、rename、truncate
- **SoftDelete（3）**：trait 存在、trashed 方法、forceDelete/softDelete 模式切换
- **HasModelEvents（4）**：trait 存在、onEvent、fireEvent 返回 false 取消、observe 观察者
- **Seeder（1）**：抽象类与 run/register/call/runAll 方法

### 5. 中间件与管道（13）

- **Middleware（4）**：抽象类与 handle 方法、CsrfMiddleware、CORS、Throttle
- **Pipeline（5）**：基本洋葱模型、thenReturn 直接返回、空管道、via 自定义方法名、中间件可修改请求/响应
- **ExceptionHandler（3）**：类存在、shouldReport 过滤、dontReport 忽略列表
- **Exception（1）**：FrameworkException 异常层级结构

### 6. 事件、容器与契约（14）

- **EventDispatcher（9）**：listen/dispatch、wildcard、hasListeners、stop propagation、until、priority、forget、flush、subscribe
- **Facade（2）**：类存在、容器未设置时抛 RuntimeException
- **ServiceProvider（2）**：抽象类与 register/boot、子类持有容器
- **Contracts（1）**：CacheInterface/LoggerInterface/ConnectionInterface 存在

### 7. 集合与函数式（30）

**Collection**：basic、map、filter、filter 无回调、where、whereIn、pluck、pluck with key、only、except、sum、avg、min/max、sortBy、take、skip、first/last、first with callback、groupBy、keyBy、contains、isEmpty/isNotEmpty、unique、reduce、each、tap、pipe、json serialize、array access、countable

### 8. 视图与模板（8）

- **View（2）**：自动转义 HTML、withoutAutoEscape 关闭转义
- **Blade（6）**：类与 render/compileString 存在、echo 转义编译、原始 echo、if/foreach 指令编译、自定义 directive

### 9. CLI 控制台（7）

**Command/Console**：signature 解析、argument 解析、option 解析、默认参数、register/list、unknown command 返回 1、signature with defaults

### 10. 扩展机制（8）

**Macroable**：注册并调用、hasMacro、flushMacros、闭包绑定 $this、mixin 批量注册、mixin 不覆盖模式、调用不存在宏抛异常、Response 宏扩展（csv）

### 11. API 文档（3）

**ApiDoc**：generate 返回数组、toMarkdown 包含标题、toJson 是合法 JSON

> 💡 **覆盖率说明**：以上覆盖范围从 `tests/run_tests.php` 中的 `$runner->run(...)` 用例直接整理得出，与代码实现完全一致。如果新增功能，请同步添加对应测试用例。

---

## 编写新测试

### 添加测试步骤

> 💡 **新手提示**：写测试就像写"检查清单"。你描述一个场景，调用一个方法，然后断言"结果应该是 X"。如果结果不是 X，测试就失败，提醒你这里有 bug。

1. 打开 `tests/run_tests.php`
2. 在 `TestRunner` 类中添加一个新的 `private function testXxx(): void` 方法
3. 在 `run()` 方法中调用 `$this->testXxx();`

### 测试示例

```php
// 基本断言：验证结果是否符合预期
private function testMyFeature(): void
{
    $result = MyFeature::doSomething('input');
    $this->assertEquals('expected', $result, 'MyFeature::doSomething 返回了预期结果');
}

// 测试 null 值
private function testNullHandling(): void
{
    $result = SomeClass::find(999);  // 不存在的 ID
    $this->assertNull($result, 'find 不存在的 ID 应该返回 null');
}

// 测试数组
private function testArrayResult(): void
{
    $items = Service::getList();
    $this->assertCount(5, $items, 'getList 返回了 5 条数据');
    $this->assertNotNull($items[0]['id'], '列表第一项有 id 字段');
}

// 测试字符串
private function testStringOutput(): void
{
    $html = Blade::render('welcome', ['name' => 'World']);
    $this->assertStringContains('World', $html, '渲染结果包含传入的变量值');
}
```

### 可用的断言方法

| 方法 | 说明 | 示例 |
|------|------|------|
| `assert($condition, $msg)` | 通用断言，条件为真则通过 | `assert($count > 0, '数据不为空')` |
| `assertEquals($expected, $actual, $msg)` | 验证等于（=== 严格比较） | `assertEquals(42, $answer, '答案是 42')` |
| `assertNull($value, $msg)` | 验证值为 null | `assertNull($result, '结果为空')` |
| `assertNotNull($value, $msg)` | 验证值不为 null | `assertNotNull($user, '用户存在')` |
| `assertTrue($value, $msg)` | 验证值为 true | `assertTrue($success, '操作成功')` |
| `assertFalse($value, $msg)` | 验证值为 false | `assertFalse($failed, '操作未失败')` |
| `assertCount($n, $array, $msg)` | 验证数组元素个数 | `assertCount(3, $items, '有 3 条记录')` |
| `assertStringContains($needle, $haystack, $msg)` | 验证字符串包含子串 | `assertStringContains('ok', $html, '包含 ok')` |

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
private function testDatabaseQuery(): void
{
    $config = \config\Config::get('database');
    $default = $config['default'] ?? 'mysql';
    $connection = $config['connections'][$default] ?? [];

    $db = new \db\Connection($connection);
    $result = $db->table('users')->where('id', '=', 1)->fetch();
    $this->assertNotNull($result, '数据库查询返回了结果');
}
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
