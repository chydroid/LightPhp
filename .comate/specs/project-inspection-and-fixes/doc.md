# LightPHP 项目全面检查与修复文档

## 概述

对 LightPHP 框架项目进行了全面源码审查，共发现 **12 个潜在 BUG**（按严重程度分类）和 **10 项改进建议**。

---

## 一、BUG 清单

### 🔴 严重 BUG（必须修复）

#### BUG-1: QueryBuilder `whereBetween` 占位符与后续 `where` 条件冲突

- **文件**: `app/db/QueryBuilder.php:171-178`
- **描述**: `whereBetween()` 使用 `count($this->bindings)` 生成占位符，而 `where()` 使用 `count($this->where)` 生成占位符。当混合使用时，两者会产生相同的占位符名称，导致 SQL 执行错误。
- **复现步骤**:
  1. `$qb->where('age', '>', 18)->whereBetween('score', 60, 100)->where('name', '=', 'test')`
  2. 生成的占位符: `:w_0`(age), `:w_1`(score_min), `:w_2`(score_max), `:w_1`(name) → `:w_1` 冲突！
- **影响**: SQL 执行时绑定参数错乱，可能导致查询结果错误或 SQL 错误。
- **修复方案**: 统一使用 `count($this->bindings)` 生成所有占位符，或者将 `whereBetween` 的占位符命名为独立命名空间。

#### BUG-2: QueryBuilder `where()` 不支持带点号的列名

- **文件**: `app/db/QueryBuilder.php:89-106`
- **描述**: `where()` 方法直接使用反引号包裹列名: `` `{$column}` ``。当列名为 `users.id` 时，会被错误地变成 `` `users.id` ``（MySQL 会当作名为 'users.id' 的单一列），而不是正确的 `` `users`.`id` ``。
- **影响**: 所有使用表别名.列名形式的查询（包括 JOIN 查询、关联查询）都会生成错误的 SQL。
- **修复方案**: 在 `where()` 及其变体方法中调用 `sanitizeColumn()` 替代直接拼接列名。

#### BUG-3: Container 别名解析异常状态未清理

- **文件**: `app/core/Container.php:84-94`
- **描述**: `resolved()` 方法中，当别名解析链发生异常时，`$this->aliasResolving` 追踪状态不会被重置为 `[]`。这会导致后续所有别名解析操作都失败，报告"循环引用"错误。
- **影响**: 一旦别名解析过程中抛出异常，容器中所有别名相关的 `get()` 调用都会失败。
- **修复方案**: 使用 `try/finally` 确保 `$this->aliasResolving = []` 在任何情况下都被执行。

### 🟠 高危 BUG（建议修复）

#### BUG-4: Response 安全头可能被自定义头覆盖

- **文件**: `app/core/Response.php:73-88`
- **描述**: `send()` 方法先调用 `addSecurityHeaders()` 添加安全头，再遍历 `$this->headers` 输出自定义头。如果开发者设置了与安全头同名的自定义头，安全策略会被覆盖失效。
- **影响**: 安全头策略（CSP、X-Frame-Options 等）可能被意外绕过。
- **修复方案**: 先输出自定义头，再输出安全头，确保安全策略最终生效。

#### BUG-5: CsrfMiddleware 的 `except` 属性未实际使用

- **文件**: `app/middleware/CsrfMiddleware.php:11`
- **描述**: CsrfMiddleware 继承自 Middleware 基类，基类定义了 `shouldSkip()` 方法用于检查 `$this->except` 路由豁免列表。但 CsrfMiddleware 的 `handle()` 方法中没有调用 `shouldSkip()`，直接对**所有**非 GET/HEAD/OPTIONS 请求进行 CSRF 校验。
- **影响**: 无法豁免特定路由（如 Webhook 回调）的 CSRF 检查，导致这些路由不可用。
- **修复方案**: 在 CSRF 校验前调用 `$this->shouldSkip()`。

#### BUG-6: Env 存储类型不一致

- **文件**: `app/core/Env.php:40-50, 80-89`
- **描述**:
  - `load()`: 从 `.env` 文件读取时，`$value` 转换为 bool/null 存入 `self::$vars` 和 `$_ENV`，但 `putenv` 存的是原始字符串
  - `set()`: `$_ENV[$key]` 存原始值（如 bool），`putenv` 存字符串化值
  - `get()`: 优先查 `self::$vars`（返回正确类型），然后查 `getenv()`（二次转换），再查 `$_ENV`
  - 如果代码其他部分直接使用 `getenv()`，获取的是字符串（如 `'true'`）而不是 bool
- **影响**: 配置值类型不一致可能导致意外行为。
- **修复方案**: `set()` 中 `$_ENV[$key]` 也应存储字符串化的值，保持与 `putenv` 一致；或统一通过 `Env::get()` 访问。
- **注意**: 当前所有框架内部通过 `Env::get()` 访问，实际影响有限，但作为框架 API 应保证行为一致性。

### 🟡 中等 BUG

#### BUG-7: `isActive` 辅助函数通配符行为错误

- **文件**: `app/view/Helper.php:64-74`
- **描述**: 当 `$path === '*'` 时，返回 `$uri !== '/' ? '' : $active`，即**只有** URI 是 '/' 时才标记为 active，这与通配符语义"匹配所有路径"完全相反。
- **影响**: 视图导航高亮功能异常。
- **修复方案**: `*` 应始终返回 `$active`。

#### BUG-8: QueryBuilder 缓存写入缺少文件锁

- **文件**: `app/db/QueryBuilder.php:310-332`
- **描述**: `setCacheFor()` 使用 `file_put_contents($tmpFile, ...)` 和 `rename($tmpFile, $cacheFile)` 的原子写入方式。但在高并发下，`getCacheFor()` 的共享锁读取可能读到不完整的缓存文件（如果 PHP 没有原子重命名支持）。
- **影响**: 极少情况下可能读到不完整的缓存数据。
- **修复方案**: 在 `getCacheFor()` 中添加 JSON 完整性校验。

#### BUG-9: Blade `@extends` 不支持向布局传递变量

- **文件**: `app/view/Blade.php:71-76`
- **描述**: `render()` 方法中检测到 `__layout` 时，以空数据 `[]` 渲染布局模板。子模板中定义的所有变量都无法传递到父布局中。
- **影响**: 布局模板无法访问子模板传入的数据。
- **修复方案**: 将当前 `$data` 传递给布局渲染。

#### BUG-10: `Captcha::generate()` 返回明文验证码

- **文件**: `app/core/Captcha.php:29-33`
- **描述**: `generate()` 返回数组中包含 `'code' => $code` 明文验证码。如果开发者将返回数据整体传递给前端（如 JSON 响应中返回完整数组），验证码会被直接泄露。
- **影响**: 安全风险，验证码机制失效。
- **修复方案**: 从返回数组中移除 `code` 字段，仅返回 image 数据。用户代码可通过 Session 自行获取验证码用于对比。

### 🟢 低优先级 BUG

#### BUG-11: `Model::with()` 对已存在对象调用 `loadRelation` 时重复查询

- **文件**: `app/model/Model.php:103-115`
- **描述**: `with()` 每次调用都会通过 `loadRelation()` 重新查询并覆盖 `$this->relations`。如果对同一模型对象多次调用 `with('profile')`，会执行相同的查询。
- **影响**: 轻微性能浪费。
- **修复方案**: 在 `loadRelation()` 中添加缓存检查。

#### BUG-12: `View::extend()` 直接输出而非返回

- **文件**: `app/view/View.php:112-123`
- **描述**: `extend()` 方法使用 `require` 直接输出布局文件内容，而不是在缓冲区中处理。这会导致布局内容在视图渲染过程中被提前输出。
- **影响**: 布局功能使用异常，输出顺序错乱。
- **修复方案**: 在 `render()` 中使用缓冲区处理布局包含。

---

## 二、改进建议

### 架构层面

| 编号 | 建议 | 说明 | 优先级 |
|------|------|------|--------|
| S-1 | **添加 PHPUnit 测试框架支持** | 当前自建测试运行器功能有限（无 mock、无数据提供器、无断言丰富度低），建议引入 PHPUnit | 高 |
| S-2 | **错误页面可定制化** | 404/500 错误页面硬编码在 Router 和 Application 中，应改为可配置的模板 | 中 |
| S-3 | **路由缓存机制** | 生产环境可缓存已编译的路由正则，减少每次请求的路由匹配开销 | 中 |
| S-4 | **Session 多驱动支持** | 当前仅支持原生 PHP Session，可扩展文件/Redis/数据库驱动 | 中 |
| S-5 | **数据库读写分离** | Connection 层支持主从分离，提升数据库性能 | 低 |

### 功能层面

| 编号 | 建议 | 说明 | 优先级 |
|------|------|------|--------|
| S-6 | **请求日志中间件** | 添加请求/响应日志记录中间件，方便调试和审计 | 中 |
| S-7 | **队列系统** | 添加简单队列任务处理（基于数据库或 Redis） | 低 |
| S-8 | **Validate 自定义规则扩展** | 当前 `unique`/`exists` 规则直接抛异常，应有机制让用户注册自定义验证规则 | 中 |

### 代码质量层面

| 编号 | 建议 | 说明 | 优先级 |
|------|------|------|--------|
| S-9 | **添加代码静态分析** | 引入 PHPStan 或 Psalm 进行静态分析，确保类型安全 | 中 |
| S-10 | **完善文档注释** | 核心类和接口缺少完整的 PHPDoc，影响 IDE 自动补全和代码理解 | 低 |

---

## 三、修复计划

### 修复范围

本次修复将覆盖 **BUG-1 到 BUG-7**（严重/高危/中等），**BUG-8 到 BUG-12** 作为低优先级在本次不做修改。

### 受影响文件清单

| 文件路径 | 涉及 BUG | 修改类型 |
|---------|---------|---------|
| `app/db/QueryBuilder.php` | BUG-1, BUG-2 | 修改方法 `where()`, `whereBetween()`, `whereIn()` |
| `app/core/Container.php` | BUG-3 | 修改方法 `resolved()` |
| `app/core/Response.php` | BUG-4 | 修改方法 `send()` |
| `app/middleware/CsrfMiddleware.php` | BUG-5 | 修改方法 `handle()` |
| `app/core/Env.php` | BUG-6 | 修改方法 `set()` |
| `app/view/Helper.php` | BUG-7 | 修改方法 `isActive()` |
| `app/core/Captcha.php` | BUG-10 | 修改方法 `generate()` |