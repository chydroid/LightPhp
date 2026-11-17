# LightPHP 全面审计报告（v2.16.0 审计轮）

> 审计日期：2026-11-17
> 审计方式：4 个并行只读子代理分模块审计（核心 HTTP / ORM-DB / 缓存-视图-配置 / 工具类），
> 再由主代理逐条**实证复核**。仅修复被证实为 bug 的条目。

## 一、结论摘要

| 分类 | 数量 | 处理 |
|------|------|------|
| 确认 bug（已实证复现） | 5 | 已修复 + 回归测试 |
| 误报（实证证明行为正确） | 5 | 不修复 |
| 潜在问题 / 存疑 / 已知限制 | 14 | 记录，谨慎起见不改动 |
| 测试覆盖盲区（非运行时 bug） | 1 | 记录 |

测试基线：修复前 `php tests/run_tests.php` = **1258/1258 通过**。

---

## 二、确认并修复的 bug（5 项）

### BUG-01 `Middleware::shouldSkip()` 中 `except = ['/']` 永远无法命中根路径
- **位置**：`app/middleware/Middleware.php:20`
- **现象**：配置 `except = ['/']` 的中间件在根路径 `/` 上不会跳过。
- **根因**：`normalizeUri()` 对根路径返回 `'/'`，而白名单分支执行 `rtrim($pattern, '/')` 后 `'/'` 变成 `''`，`'' === '/'` 恒为 false。
- **实证**：`REQUEST_URI=/` + `except=['/']` → `shouldSkip()` 返回 `false`（应为 `true`）；`except=['/api']` + `/api` 返回 `true`（对照组正常）。
- **修复**：`rtrim` 结果为空时归一化为 `'/'`。

### BUG-02 `ValidationException::getFirstError()` 遇首个字段空错误数组时提前返回 null
- **位置**：`app/core/exception/ValidationException.php:21-27`
- **现象**：`errors = ['email' => [], 'name' => ['name required']]` 返回 `null`，丢失了真实错误。
- **根因**：`foreach` 首轮即 `return $fieldErrors[0] ?? null;`，即使该字段错误数组为空也不继续查找。
- **实证**：上述输入返回 `NULL`（应为 `"name required"`）。
- **修复**：跳过空数组（及非数组）条目，返回首个真实错误；兼容「字段 => 字符串消息」格式。

### BUG-03 `Collection::flatten()` 不展平嵌套的 `Collection` 对象
- **位置**：`app/core/Collection.php:319-330`
- **现象**：`[1, [2, new Collection([3,4])]]` 展平后为 `[1,2,[3,4]]`，嵌套集合未被展平。
- **根因**：仅判断 `is_array($item)`；`Collection` 实现 `IteratorAggregate` 但不是 array。
- **实证**：`flatten()->all()` 输出 `[1,2,[3,4]]`（应为 `[1,2,3,4]`）。
- **修复**：增加 `instanceof self` 分支递归展平嵌套集合。与 Laravel Collection 语义一致。

### BUG-04 `Env::get()` 对 `$_ENV` 中的非字符串值调用 `strtolower()` 抛 TypeError
- **位置**：`app/core/Env.php:111-117`
- **现象**：`$_ENV[$key]` 为非字符串（如 bool）时，`strtolower($val)` 在 strict_types 下抛 `TypeError`。
- **根因**：`Env::load()` 会把类型化值（true/false/null）写入 `$_ENV`（第 87 行），而 `get()` 直接把 `$_ENV` 值传给只接受 string 的 `strtolower()`。
- **实证**：`$_ENV['X'] = true` → `Env::get('X')` 抛 `TypeError: strtolower(): Argument #1 must be of type string, true given`。
- **修复**：比较时使用 `(string) $val`，返回值保留原始类型化值。

### BUG-05 `Config::load()` 对应用自身配置目录调用时 fatal「Cannot redeclare class」
- **位置**：`app/config/Config.php:82-93`
- **现象**：`Config::load(APP_PATH . 'config')` 触发 `Fatal error: Cannot redeclare class config\Config`。
- **根因**：`glob('*.php')` 把 `Config.php` 自身纳入；`require $file` 重复声明类。去重守卫 `array_key_exists('Config', items)` 无效——`Config.php` require 返回 `1`（非数组），从不写入 `$items`。
- **实证**：单次调用即 fatal（类已被自动加载）。
- **对照**：`Application::loadConfig()` 早已通过 `if ($name === 'Config') continue;` 规避此问题，说明这是遗漏。
- **修复**：`Config::load()` 同样跳过 `Config.php`。

---

## 三、误报（实证证明行为正确，不修复）

| 编号 | 子代理声称 | 实证结果 | 结论 |
|------|-----------|---------|------|
| FP-01 | `QueryBuilder::delete()` 不走类型化绑定，浮点比较失效 | `where('score','>',1.5)->delete()` 正确删除 2 行（保留 1.5），与 `fetchAll()` 路径一致 | 行为正确 |
| FP-02 | `paginate()` 硬编码 `count()` 使含 HAVING 查询永久 0 结果 | `groupBy+having` 的 `count`/`paginate.total`/`items` 均为 3，完全一致 | 行为正确 |
| FP-03 | `whereRaw(':name', ['name'=>v])` 命名占位符 no-op 触发 PDOException | 带冒号/不带冒号两种 key 均正确返回 1 行（历史已修复） | 行为正确 |
| FP-04 | `getSql()/execute()` 对 groupBy 生成重复 GROUP BY | `getSql()` 输出单条 `GROUP BY \`g\``（历史已修复） | 行为正确 |
| FP-05 | OPTIONS 预检使用 foreach 残留的末条路由 middleware | 差分实验：marker 仅挂在命中路由时预检执行、仅挂在末条路由时预检**不**执行 → 实际使用的是命中路由 | 行为正确 |

> 说明：FP-01~04 与 CHANGELOG 中历次修复记录吻合，子代理读到的是旧认知；FP-05 由差分实验直接证伪。

---

## 四、潜在问题 / 存疑 / 已知限制（记录，不改动）

| 编号 | 条目 | 位置 | 不改原因 |
|------|------|------|---------|
| P-01 | `Request::ip()` 在可信代理下取 XFF 最左值 | `app/core/Request.php:391` | 这是可信代理下的标准做法；是否可伪造取决于代理是否正确覆写 XFF，属部署配置 |
| P-02 | `FormRequest` 子类用 `new` 而非容器实例化 | `app/core/Router.php:987` | 需确认框架是否允许 FormRequest 构造依赖；低概率，改动风险大于收益 |
| P-03 | 同组中间件重复出现时 `$seen` 静默跳过第二次 | `app/core/Router.php:113` | 重复组极罕见；去重本身是合理行为 |
| P-04 | Smarty 未开启 `escape_html`，`{$var}` 原样输出 | `app/view/Smarty.php` | Smarty 默认行为；强制转义可能破坏依赖原始 HTML 的模板，需产品决策 |
| P-05 | `Config::$items` 在应用启动流程未被填充 | `app/config/Config.php` | `Application` 使用独立 `$this->config` + 容器；静态 Config 是独立 API，非运行时缺陷 |
| P-06 | `RedisCache::increment/decrement` 非原子读改写 | `app/cache/RedisCache.php:237` | 需 Redis 实测；序列化值无法直接用 INCR，改造涉及设计权衡 |
| P-07 | `MemcachedCache::setMany()` 未复用 TTL 归一化 | `app/cache/MemcachedCache.php:279` | 需 Memcached 实测；低影响 |
| P-08 | `MemcachedCache` 对序列化值做原子 increment | `app/cache/MemcachedCache.php:191` | 存疑，依赖 memcached 行为 |
| P-09 | `Smarty::display/fetch/exists` 未校验模板路径 | `app/view/Smarty.php:65` | 依赖调用方是否把用户输入透传为模板名 |
| P-10 | `Helper::url()` 未走可信主机白名单 | `app/view/Helper.php:41` | 与 `Request::isTrustedHost()` 不一致，但影响局限且需产品决策 |
| P-11 | `Macroable::mixin()` 未跳过静态方法 | `app/core/traits/Macroable.php:84` | 注释与实现不符，但静态方法返回闭包即可作为工厂，无实证故障 |
| P-12 | `Model::persistCreate/update` 不触发 `saving/saved` 事件 | `app/model/Model.php:237` | 事件语义不一致，但改变触发时机影响面大，需规格确认 |
| P-13 | `Model::getAttribute()` 用 `method_exists` 不区分可见性 | `app/model/Model.php:640` | 私有访问器属边缘用法；从外部调用属误用 |
| P-14 | `SoftDelete` 静态作用域用单槽全局状态 | `app/traits/SoftDelete.php:35` | 连续 `withTrashed()/onlyTrashed()` 可能相互污染，但需重新设计作用域栈，风险高 |

> 已知限制（非缺陷）：`Model::with()` 链式查询关联丢失——README 已明示，提供了 `withRelation()` 作为正确方案。

---

## 五、测试覆盖盲区（记录）

- `tests/run_tests.php` 中「EventDispatcher 优先级」与「通配符」测试相互独立，缺少「跨 pattern 全局优先级排序」的交叉断言。该逻辑是历史 bug 修复点，建议后续补测（非运行时 bug，本轮不改）。

---

## 六、修复计划与实施

1. 修复 BUG-01 ~ BUG-05（5 处最小改动，匹配现有风格）。
2. 新增 5 条回归测试到 `tests/test_regressions.php`。
3. 运行全量测试，确认无回归（预期 1263/1263 通过）。
4. `php -l` 全量语法检查。
5. Git 复核 status/diff，提交并推送。

**实施状态**：见文末 CHANGELOG「[Unreleased] 第六轮审计修复」条目。

---

# 第七轮复检审计（v2.16.0 复检轮）

> 审计日期：2026-11-17
> 方式：4 个并行只读子代理分模块排查 + 主代理逐条实证脚本复核。
> 原则：仅修复可实证复现的 bug；设计选择、行为差异、二次证伪项一律记录不改动。

## 七-一、结论摘要

| 分类 | 数量 | 处理 |
|------|------|------|
| 确认 bug（已实证复现） | 5 | 已修复 + 回归测试 |
| 二次证伪（行为正确） | 2 | 不修复 |
| 设计选择 / 行为差异 | 3 | 不修复 |
| 潜在问题（与上轮重叠） | 4 | 记录，不改动 |

测试基线：修复前 **1258/1258**；本轮修复后 **1276/1276** 全部通过。

## 七-二、确认并修复的 bug（5 项）

### R7-BUG-01 `Container::make()` 显式类类型参数被容器自动解析覆盖
- **位置**：`app/core/Container.php:234`
- **现象**：`make(Foo::class, ['dep' => $explicit])`，构造函数 `dep` 为类类型时得到容器绑定的实例而非显式对象。
- **根因**：依赖解析顺序颠倒——先对命名类类型执行 `$this->get()` 并 `continue`，显式参数分支永远轮不到。
- **实证**：`singleton(stdClass, from-container)` + 显式 `explicit` → `dep.tag = 'from-container'`（应为 `'explicit'`）。
- **修复**：循环起始处优先检查 `$parameters`，命中即用并 `continue`。

### R7-BUG-02 `HttpException::getStatusCode()` 恒返 500
- **位置**：`app/core/exception/HttpException.php:10`
- **现象**：`new HttpException(404)` 的 `getHttpStatusCode() = 404`，但继承的 `getStatusCode() = 500`。
- **根因**：构造函数只设置自身 `$httpStatusCode`，未同步父类 `FrameworkException::$statusCode`（默认 500）。
- **实证**：两个 getter 返回值不一致。
- **修复**：构造时同步 `$this->statusCode = $httpStatusCode`。

### R7-BUG-03 `QueryBuilder::count()` 忽略 `distinct()`
- **位置**：`app/db/QueryBuilder.php:751`
- **现象**：`distinct()->select('email')->count()` 对 `a,a,b` 返回 3（应为 2）。
- **根因**：`count()` 覆写 `select` 为 `COUNT(*)`，`DISTINCT` 作用于单行聚合结果无效。
- **实证**：`getSql()` 为 `SELECT DISTINCT email`，而 count 为 3；显式 `count('email')` 亦为 3；多列 `select(['email','city'])` 亦被忽略。
- **修复**：单列下沉为 `COUNT(DISTINCT col)`（未显式传列时从 `select` 字段提取，需去反引号）；多列/表达式改用 `SELECT COUNT(*) FROM (SELECT DISTINCT ...)` 子查询去重计数。

### R7-BUG-04 `Schema::table()` 为 `dropColumn/renameColumn/change` 误加 `ADD COLUMN` 前缀
- **位置**：`app/db/Schema.php:105`（SQLite 分支）与 `compileAlter()`
- **现象**：`dropColumn('email')` 生成 `ALTER TABLE ... ADD COLUMN DROP COLUMN \`email\``（非法 SQL）。
- **根因**：`compileAlter()` 与 SQLite 分支把所有非 `ADD ` 开头的变更串一律当作新列定义补 `ADD COLUMN`。
- **实证**：反射注入 `dropColumn` 变更串，`compileAlter()` 输出带误加前缀。
- **修复**：新增 `isFullAlterClause()`，识别 `ADD/DROP/RENAME/MODIFY/CHANGE` 完整子句，不再补前缀。

### R7-BUG-05 `bin/console make:model` 生成模板继承不存在的 `core\Model`
- **位置**：`bin/console:196`
- **现象**：生成的模型含 `use core\Model;`，但 `core/Model.php` 不存在（真实基类为 `model\Model`）。
- **根因**：模板字符串硬编码错误命名空间，与 `Generator.php:81` 已修正的 `use model\Model;` 不一致。
- **实证**：模板含 `use core\Model` 且 `core/Model.php` 不存在。
- **修复**：模板改为 `use model\Model;`。

## 七-三、二次证伪（不修复）

| 编号 | 声称 | 实证结果 | 结论 |
|------|------|---------|------|
| R7-FP-01 | OPTIONS 预检错用末条路由 middleware | 差分实验：marker 挂命中路由时预检执行、挂末条路由时不执行 → 实际用命中路由 | 行为正确 |
| R7-FP-02 | `View::include()` 子视图未转义导致 XSS | 子视图 `<?= $x ?>` 实测输出 `&lt;script&gt;...`，已被转义 | 行为正确 |

## 七-四、设计选择 / 行为差异（不修复）

| 编号 | 条目 | 判定 |
|------|------|------|
| R7-D-01 | Application 对非法返回值回 HTTP 200、body `code=500` | 注释中「500」指 body 字段，属既有设计，非状态行错误 |
| R7-D-02 | `Model::toArray()` 不应用访问器（Laravel 会应用） | 设计差异，改动面大且可能破坏依赖原始属性的调用方 |
| R7-D-03 | `sum()/avg()` 在 SQLite 返回 float | 实测正常，MySQL 场景无法实证，记录为潜在 |

## 七-五、潜在问题（与上轮重叠，记录不改动）

| 编号 | 条目 | 位置 | 不改原因 |
|------|------|------|---------|
| R7-P-01 | `Application::handleException()` 生产环境回显异常消息 | `app/core/Application.php:386` | 与上轮 P 类重叠；已走 `getHttpStatusCode()`，消息回显需产品决策 |
| R7-P-02 | `ExceptionHandler` 未引用 `SAFE_MESSAGES` 白名单 | `app/core/exception/ExceptionHandler.php:145` | 静态一致，属安全策略增强，非实证故障 |
| R7-P-03 | `TaggedCache::flush()` 忽略 `flushByTag()` 返回值 | `app/cache/TaggedCache.php` | 返回值语义需与缓存驱动契约确认 |
| R7-P-04 | `Blade` 未编译 `@env/@endenv`、FileCache `.lock` 清理 | `app/view/Blade.php`、`app/cache/FileCache.php` | 需功能需求确认，非 bug |

## 七-六、修复计划与实施

1. 修复 R7-BUG-01 ~ R7-BUG-05（5 处最小改动）。
2. 新增 5 条回归测试到 `tests/test_regressions.php`。
3. `php -l` 全量语法检查 + 全量测试，确认无回归（1276/1276 通过）。
4. Git 复核 status/diff，提交并推送。

**实施状态**：见 CHANGELOG「[Unreleased] 第七轮复检审计修复」条目。
