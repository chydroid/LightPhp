# LightPHP 测试指南

> 📖 **为什么要测试？** 测试就像是自动化的"质检员"。每次你修改代码后，运行测试可以在几秒内验证所有功能是否仍然正常工作，避免"改了一个地方，坏了另一个地方"的问题。对于生产环境的项目，完善的测试是保证代码质量的生命线。

## 概述

LightPHP 使用自带的轻量级测试框架，测试文件全部集中在 `tests/` 目录下。当前共 **259 个单元测试**，覆盖了框架所有核心组件：

| 测试模块 | 说明 | 测试数量 |
|---------|------|---------|
| Env | 环境变量加载与读取 | 3 |
| Cookie | Cookie 的设置、获取、删除 | 4 |
| Hash | 密码哈希、加密解密 | 5 |
| Session | 会话数据的读写 | 5 |
| Validate | 16 种验证规则全覆盖 | 18 |
| Request | HTTP 请求参数的处理 | 9 |
| FileCache | 文件缓存的增删改查 | 5 |
| Container | 依赖注入容器（PSR-11） | 8 |
| Router | 路由注册、匹配、中间件 | 12 |
| Response | HTTP 响应构建 | 7 |
| EventDispatcher | 事件监听与触发 | 13 |
| Collection | 数组集合操作 | 28 |
| Facade | 门面模式 | 6 |
| ServiceProvider | 服务提供者 | 4 |
| Blade | 模板编译引擎 | 12 |
| Command | CLI 命令系统 | 15 |
| Schema | 数据库迁移建表 | 8 |
| Middleware | CORS、Throttle 中间件 | 10 |
| Others | 其他核心功能 | 35 |

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

### 1. 环境变量测试（Env）

| 测试 | 说明 |
|------|------|
| `Env::load loads .env file` | 加载 .env 文件 |
| `Env::get returns value` | 获取环境变量值 |
| `Env::get returns default for missing key` | 缺失键返回默认值 |

### 2. Cookie 测试

| 测试 | 说明 |
|------|------|
| `Cookie::set and get` | 设置和获取 Cookie |
| `Cookie::get returns default for missing key` | 缺失键返回默认值 |
| `Cookie::has returns true for existing cookie` | 检查 Cookie 是否存在 |
| `Cookie::delete removes cookie` | 删除 Cookie |

### 3. 加密哈希测试（Hash）

| 测试 | 说明 |
|------|------|
| `Hash::make creates bcrypt hash` | 创建 bcrypt 哈希 |
| `Hash::verify correct password` | 验证正确密码 |
| `Hash::verify incorrect password` | 验证错误密码 |
| `Hash::encrypt and decrypt` | 加密和解密 |
| `Hash::decrypt invalid data returns null` | 解密无效数据返回 null |

### 4. Session 测试

| 测试 | 说明 |
|------|------|
| `Session::set and get` | 设置和获取 Session |
| `Session::get returns default for missing key` | 缺失键返回默认值 |
| `Session::has returns true for existing key` | 检查 Session 是否存在 |
| `Session::delete removes key` | 删除 Session 键 |
| `Session::token generates token` | 生成 CSRF Token |

### 5. 验证器测试（Validate）

| 测试 | 说明 |
|------|------|
| `Validate::required rule` | 必填验证 |
| `Validate::email rule` | 邮箱验证 |
| `Validate::min rule` | 最小值验证 |
| `Validate::max rule` | 最大值验证 |
| `Validate::numeric rule` | 数字验证 |
| `Validate::integer rule` | 整数验证 |
| `Validate::float rule` | 浮点数验证 |
| `Validate::url rule` | URL 验证 |
| `Validate::ip rule` | IP 地址验证 |
| `Validate::alpha rule` | 字母验证 |
| `Validate::alphaNum rule` | 字母数字验证 |
| `Validate::in rule` | 列表包含验证 |
| `Validate::notIn rule` | 列表排除验证 |
| `Validate::regex rule` | 正则验证 |
| `Validate::date rule` | 日期格式验证 |
| `Validate::confirmed rule` | 确认字段验证 |
| `Validate::passes returns true for valid data` | passes 方法 |
| `Validate::fails returns true for invalid data` | fails 方法 |
| `Validate::firstError returns first error` | 获取首个错误 |

### 6. 请求测试（Request）

| 测试 | 说明 |
|------|------|
| `Request::get returns query param` | 获取查询参数 |
| `Request::post returns post param` | 获取 POST 参数 |
| `Request::all returns all input` | 获取所有输入 |
| `Request::only returns selected fields` | 获取指定字段 |
| `Request::except excludes fields` | 排除指定字段 |
| `Request::has checks field existence` | 检查字段是否存在 |
| `Request::method returns request method` | 获取请求方法 |
| `Request::isAjax detects AJAX` | 检测 AJAX 请求 |
| `Request::isGet/isPost checks method` | 检查请求方法类型 |

### 7. 缓存测试（FileCache）

| 测试 | 说明 |
|------|------|
| `FileCache::set and get` | 设置和获取缓存 |
| `FileCache::has checks existence` | 检查缓存是否存在 |
| `FileCache::delete removes item` | 删除缓存项 |
| `FileCache::remember caches callback result` | remember 方法 |
| `FileCache::increment/decrement` | 计数器增减 |

### 8. Pipeline 管道测试

| 测试 | 说明 |
|------|------|
| `Pipeline basic flow` | 基本管道流程 |
| `Pipeline onion model` | 洋葱模型执行顺序 |
| `Pipeline via custom method` | 自定义管道方法 |
| `Pipeline thenReturn` | thenReturn 方法 |
| `Pipeline empty pipes` | 空管道处理 |

### 9. Macroable 宏扩展测试

| 测试 | 说明 |
|------|------|
| `Macroable register and call macro` | 注册和调用宏 |
| `Macroable static macro call` | 静态宏调用 |
| `Macroable mixin` | 混入注册 |
| `Macroable hasMacro` | 检查宏是否存在 |
| `Macroable flushMacros` | 清空宏 |
| `Macroable __call throws for unknown` | 调用不存在宏抛异常 |
| `Macroable __callStatic throws for unknown` | 静态调用不存在宏抛异常 |
| `Macroable closure binds to instance` | 闭包绑定实例 |
| `Macroable mixin with replace option` | 混入替换选项 |

### 10. SoftDelete 软删除测试

| 测试 | 说明 |
|------|------|
| `SoftDelete sets deleted_at` | 软删除设置 deleted_at |
| `SoftDelete excludes trashed by default` | 默认排除已删除记录 |
| `SoftDelete restore` | 恢复软删除记录 |

### 11. HasModelEvents 模型事件测试

| 测试 | 说明 |
|------|------|
| `Model event creating` | creating 事件 |
| `Model event created` | created 事件 |
| `Model observer` | 观察者模式 |
| `Model event returns false prevents action` | 事件返回 false 阻止操作 |

### 12. 访问器/修改器测试

| 测试 | 说明 |
|------|------|
| `Getter accessor` | 访问器 |
| `Setter mutator` | 修改器 |

### 13. 查询作用域测试

| 测试 | 说明 |
|------|------|
| `Query scope method` | 查询作用域方法 |

### 14. Seeder 数据填充测试

| 测试 | 说明 |
|------|------|
| `Seeder register and runAll` | 注册和批量运行 |

### 15. 中间件别名/组测试

| 测试 | 说明 |
|------|------|
| `Router aliasMiddleware` | 中间件别名注册 |
| `Router middlewareGroup` | 中间件组注册 |
| `Router resolveMiddleware` | 中间件解析 |

### 16. Request 类型过滤测试

| 测试 | 说明 |
|------|------|
| `Request string method` | 字符串类型过滤 |
| `Request integer method` | 整数类型过滤 |
| `Request float method` | 浮点数类型过滤 |
| `Request boolean method` | 布尔值类型过滤 |
| `Request arrayInput method` | 数组类型过滤 |
| `Request merge method` | 合并数据 |

### 17. ExceptionHandler 异常处理器测试

| 测试 | 说明 |
|------|------|
| `ExceptionHandler report` | 异常报告 |
| `ExceptionHandler render` | 异常渲染 |
| `ExceptionHandler shouldReport` | 判断是否应报告 |

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
