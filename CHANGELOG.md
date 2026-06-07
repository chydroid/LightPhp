# Changelog

All notable changes to the LightPHP framework will be documented in this file.

## [2.0.3] - 2026-06-07

### 文档 (Documentation)

- **docs 目录 MD 文件全面审查与修正** — 对 `docs/` 目录下所有 Markdown 文档进行系统检查，确保与 v2.0.2 代码实现保持一致。
  - `quick-start.md` — 版本号更新至 v2.0.2，修正 SoftDelete API（强调实例方法），移除中间件错误的基类继承示例
  - `guide.md` — 修正 SoftDelete 章节的 API 用法，强调 `$model->delete()` 实例方法
  - `testing-guide.md` — 测试数从 259 更新为 173，修正测试覆盖表格，替换过时的 TestRunner 示例
  - `api.md` — 修复不存在的 `Model::destroy()` 为 `delete()` / `deleteById()`，修正 whereOr 示例，添加 Request/Model 返回值说明
  - `ecommerce-full-tutorial.md` — 添加模型访问模式说明（实例 vs 关联数组）
  - `admin-panel-tutorial.md` — 添加模型访问模式说明（实例 vs 关联数组）

---

## [2.0.2] - 2026-06-07

### 运行时 Bug 修复 (Bug Fixes)

- **[严重] Router 控制器 Request 注入丢失中间件修改** — 控制器方法通过类型提示注入 `Request` 时，代码创建 `new Request()` 而非使用 `dispatch()` 传入的请求对象。现已将 `$request` 传递到 `executeHandler()`，让中间件对请求的修改在控制器中可见。 (`app/core/Router.php`)
- **[严重] Router 路由正则定界符冲突** — 自定义正则 `{#}` 与定界符 `#` 冲突，攻击者可利用导致正则注入。现改用 `~` 作为定界符避免与自定义正则中的 `#` 冲突。 (`app/core/Router.php`)
- **[严重] Generator 代码生成注入风险** — `phpArray()` 拼接值未转义反斜杠/单引号，攻击者可通过特殊字符注入 PHP 代码。现已对值做 `str_replace` 转义。 (`app/core/Generator.php`)
- **[中等] Generator 填充字段未检测自定义主键** — `getFillableColumns()` 硬编码排除 `id` 字段，使用 UUID 或自定义主键时会被错误识别为可填充字段。现已通过 `getPrimaryKey()` 动态获取主键名。 (`app/core/Generator.php`)
- **[中等] Generator tinyint(1) 未识别为 bool** — MySQL 中 `tinyint(1)` 通常用作 boolean，但被识别为 int 类型。现已添加 `tinyint(1)` → `bool` 的映射。 (`app/core/Generator.php`)
- **[中等] Collection pluck() 键名冲突** — `pluck()` 和 `keyBy()` 在键为数字时使用 `count($results)` 兜底，可能与已有键冲突。现改用唯一字符串占位符。 (`app/core/Collection.php`)
- **[中等] Collection offsetExists 对 null 返回 false** — `isset()` 对 null 值返回 false，与 `get()` 行为不一致。现改为 `array_key_exists()` 统一语义。 (`app/core/Collection.php`)
- **[中等] Validate min/max 缺参数** — `min`/`max` 缺少参数时直接读取 `$params[0]` 触发 `Error`。现已添加 `empty($params)` 检查。 (`app/core/Validate.php`)
- **[中等] Validate 未知规则静默忽略** — 拼写错误的规则（如 `require` 写成 `requir`）不会触发任何错误。现已添加 `trigger_error()` 警告。 (`app/core/Validate.php`)
- **[中等] ExceptionHandler currentRequest 为空时崩溃** — `shouldReturnJson()` 在 `currentRequest` 为 null 时抛出 `TypeError`。现已添加 null 检查。 (`app/core/ExceptionHandler.php`)
- **[中等] Session 启动失败无感知** — `session_start()` 失败时 `self::$started` 仍被设为 true，导致后续操作静默失败。现已记录日志。 (`app/core/Session.php`)
- **[中等] Session destroy() 缺少 samesite** — `setcookie()` 老式参数不支持 samesite。现改用数组参数格式并保留 samesite。 (`app/core/Session.php`)
- **[中等] Upload finfo 资源泄漏** — `finfo_file()` 抛异常时 `finfo_close()` 不会被调用。现已使用 `try-finally` 确保资源释放。 (`app/core/Upload.php`)
- **[低] Request php://input 失败为 null** — `file_get_contents()` 失败时 `rawContent` 为 null，下游 json 解码报错。现已使用 `?: ''` 兜底。 (`app/core/Request.php`)
- **[低] Pipeline passable 未初始化** — 未调用 `send()` 时 `$passable` 未初始化。现已设为默认 `null`。 (`app/core/Pipeline.php`)
- **[低] Env file() 失败崩溃** — `.env` 文件不可读时 `file()` 返回 false 进入 foreach 触发警告。现已添加 false 检查。 (`app/core/Env.php`)
- **[改进] database.php 支持环境变量** — 数据库连接配置改为通过 `env()` 读取 `.env`，便于不同环境部署。 (`app/config/database.php`)
- **[改进] TestRunner assert 抛出异常** — 之前失败时仅 echo 不抛异常，循环测试会继续执行掩盖错误。现已改为抛 `RuntimeException` 让单测立即失败。 (`tests/run_tests.php`)

### 测试 (Tests)

- 修复了 TestRunner assert 行为，确保测试失败时立即中断
- 累计测试用例：300+（包含本轮新增/调整）

---

## [2.0.1] - 2026-06-07

### 安全修复 (Security)

- **[严重] QueryBuilder SQL 注入** — `join()` 方法的 `$first`/`$second` 参数未经验证直接拼入 SQL，攻击者可通过反引号逃逸注入恶意语句。现已添加 `validateColumnName()` + `sanitizeColumn()` 校验。 (`app/db/QueryBuilder.php`)
- **[严重] QueryBuilder INSERT/UPDATE 键名注入** — `buildInsert()` 和 `buildUpdate()` 中 `$data` 的键名直接拼入 SQL，现已添加 `validateColumnName()` 校验。 (`app/db/QueryBuilder.php`)
- **[严重] CORS 通配符源与凭证冲突** — 当 `allowed_origins` 包含 `*` 且 `supports_credentials` 为 `true` 时，浏览器会拒绝响应。现已修复：有凭证时回退到具体 Origin 并添加 `Vary: Origin` 头。 (`app/middleware/Cors.php`)
- **[严重] CORS 不允许的 Origin 未被拒绝** — 非 OPTIONS 请求中，不在允许列表的 Origin 仍会继续传递到控制器，CORS 保护形同虚设。现已对不允许的 Origin 返回 403。 (`app/middleware/Cors.php`)
- **[中等] CSRF 空 Token 绕过** — 当 Session 启动失败时，`Session::token()` 可能返回空字符串，`hash_equals('', '')` 返回 `true`，导致空 CSRF Token 通过验证。现已添加 `$sessionToken === ''` 检查。 (`app/middleware/CsrfMiddleware.php`)
- **[中等] Blade @json XSS** — `@json` 指令使用 `json_encode()` 未添加 `JSON_HEX_TAG` 等标志，当 JSON 数据包含 `</script>` 时可导致 XSS。现已添加 `JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP`。 (`app/view/Blade.php`)
- **[低] View normalizePath 过度删除** — `str_replace('..', '', $path)` 会删除合法文件名中的 `..`（如 `file..backup.txt`）。现改为仅移除 `../` 和 `/..` 路径遍历模式。 (`app/view/View.php`)

### 运行时 Bug 修复 (Bug Fixes)

- **[严重] Router 控制器参数解析无兜底** — 当控制器方法参数既不在路由参数中、也没有类类型提示、也没有默认值时，该参数被静默跳过，导致 `invokeArgs` 传入参数不足触发 `ArgumentCountError`。现已添加 `else` 分支抛出 `RuntimeException`。 (`app/core/Router.php`)
- **[严重] Router Request 注入丢失中间件修改** — 控制器方法通过类型提示注入 `Request` 时，代码创建 `new Request()` 而非使用 `dispatch()` 传入的请求对象，中间件对请求的所有修改在控制器中不可见。现已使用 `dispatch()` 传入的 `$request`。 (`app/core/Router.php`)
- **[严重] Router 闭包路由缓存损坏** — `cacheRoutes()` 使用 `var_export()` 序列化路由表，但闭包无法被序列化，导致缓存文件损坏。现已添加闭包检查，含闭包的路由表拒绝缓存。 (`app/core/Router.php`)
- **[严重] Application getConfig TypeError** — 对非数组配置值使用点号访问时，`array_key_exists()` 收到非数组参数在 PHP 8 中抛出 `TypeError`。现已添加 `is_array()` 检查。 (`app/core/Application.php`)
- **[严重] Container has() 违反 PSR-11** — `has()` 只检查 bindings/instances/aliases，不检查 `class_exists()`，而 `get()` 会尝试自动构建类，导致 `has()` 返回 `false` 但 `get()` 能成功。现已添加 `class_exists()` 检查。 (`app/core/Container.php`)
- **[中等] Container 无参构造函数多余参数** — 类无构造函数参数时，`newInstanceArgs($parameters)` 传入非空参数触发 `ArgumentCountError`。现改为 `new $class()`。 (`app/core/Container.php`)
- **[中等] Container 别名循环检测清空追踪** — `finally` 块中 `$this->aliasResolving = []` 清空整个追踪数组，嵌套别名解析时外层追踪丢失。现改为 `unset($this->aliasResolving[$abstract])`。 (`app/core/Container.php`)
- **[中等] Pipeline carry() 仅支持对象** — `carry()` 假设管道始终是对象，字符串类名或闭包会触发致命错误。现已支持字符串类名、闭包和对象三种管道类型。 (`app/core/Pipeline.php`)
- **[中等] Request has() 对 null 值返回 false** — `has()` 使用 `isset()` 检查参数是否存在，但 `isset()` 对 `null` 返回 `false`，与 `input()` 行为不一致。现改为 `array_key_exists()`。 (`app/core/Request.php`)
- **[中等] Model __clone 未清除主键** — 克隆已存在模型后 `exists` 被设为 `false`，但主键未清除，调用 `save()` 时触发唯一键冲突。现已添加 `unset($this->attributes[$this->primaryKey])`。 (`app/model/Model.php`)
- **[中等] Model __isset 对 null 属性返回 false** — `__isset()` 对 attributes 使用 `isset()`（null 返回 false），对 relations 使用 `array_key_exists()`（null 返回 true），逻辑不一致。现统一为 `array_key_exists()`。 (`app/model/Model.php`)
- **[中等] Model array cast 返回 null** — `castAttribute()` 中 `array` 类型在 JSON 解码失败时返回 `null`，后续 `foreach` 触发 `TypeError`。现改为返回空数组 `[]`。 (`app/model/Model.php`)
- **[中等] QueryBuilder count() 分页计数错误** — `count()` 未重置 `groupBy` 和 `having`，包含 GROUP BY 的查询返回每组计数而非总行数。现已重置。 (`app/db/QueryBuilder.php`)
- **[中等] QueryBuilder reset() 未重置缓存属性** — `reset()` 未重置 `cacheKey`/`cacheTtl`/`cacheEnabled`，重用 QueryBuilder 时会使用前一次查询的缓存配置。现已添加重置。 (`app/db/QueryBuilder.php`)
- **[低] QueryBuilder fetchAll() 未处理 false** — `PDOStatement::fetchAll()` 失败时返回 `false`，违反返回类型契约。现已添加防御性检查。 (`app/db/QueryBuilder.php`)

### 缓存驱动修复 (Cache)

- **[严重] RedisCache get() 无法区分"不存在"和"值为 false"** — `\Redis::get()` 对键不存在和值为 `false` 都返回 `false`，导致存储 `false` 后读取返回默认值。现使用 `exists()` 先判断键是否存在。 (`app/cache/RedisCache.php`)
- **[严重] RedisCache/MemcachedCache remember() 无法处理 null 值** — `remember()` 使用 `$value !== null` 判断缓存是否存在，无法区分"缓存值为 null"和"缓存不存在"。现使用哨兵对象模式。 (`app/cache/RedisCache.php`, `app/cache/MemcachedCache.php`)
- **[严重] MemcachedCache unserialize() 误判 serialize(false)** — `unserialize()` 对 `serialize(false)` 返回 `false`，被误判为反序列化失败，返回原始字符串而非布尔值。现已添加 `serialize(false)` 特判。 (`app/cache/MemcachedCache.php`)
- **[严重] MemcachedCache increment/decrement 非原子操作** — 使用 get-then-set 模式，并发下丢失更新。现改用 Memcached 原生 `increment()`/`decrement()` 原子操作。 (`app/cache/MemcachedCache.php`)
- **[中等] RedisCache pull() 与 get() 相同的 false 值问题** — `pull()` 同样使用 `$value !== false` 判断，现已使用 `exists()` 先判断。 (`app/cache/RedisCache.php`)
- **[中等] FileCache clear() 错误跳过缓存文件** — `str_contains(basename($file), 'tag_')` 会跳过 MD5 哈希恰好包含 `tag_` 的合法缓存文件，且不清理标签文件。现已移除错误检查并添加标签文件清理。 (`app/cache/FileCache.php`)
- **[中等] FileCache increment/decrement 锁文件泄漏** — `increment()`/`decrement()` 的 `finally` 块未清理锁文件，频繁操作会积累大量 `.lock` 文件。现已添加 `@unlink($lockFile)`。 (`app/cache/FileCache.php`)
- **[中等] TaggedCache set 失败仍标记标签** — 底层存储 `set()` 返回 `false` 时仍执行 `tagKey()`，导致标签数据与实际缓存不一致。现改为仅在成功时标记。 (`app/cache/TaggedCache.php`)
- **[中等] TaggedCache 缺少委托方法** — 缺少 `increment`/`decrement`/`deleteMany`/`pull`/`clear` 等方法，无法在需要 `CacheInterface` 的场景使用。现已补全。 (`app/cache/TaggedCache.php`)

### 模板引擎修复 (Template Engine)

- **[严重] Blade @verbatim 功能完全失效** — `@verbatim`/`@endverbatim` 仅被替换为空字符串，区域内的 `@if`/`@foreach` 等指令仍被编译。现改为编译前提取保护、编译后还原。 (`app/view/Blade.php`)
- **[低] Blade extract 顺序** — `extract($data)` 在 `$__blade = $this` 之前执行，若 `$data` 包含 `__blade` 键会产生短暂变量覆盖。现已将 `$__blade = $this` 移到 `extract` 之前。 (`app/view/Blade.php`)

### 其他修复 (Other)

- **[中等] Logger 无效级别静默丢弃** — `log()` 对无效级别静默返回，违反 PSR-3 规范。现改为抛出 `InvalidArgumentException`。 (`app/log/Logger.php`)
- **[中等] Logger setLevel 无校验** — `setLevel()` 不验证级别合法性，传入无效级别导致后续运行时警告。现已添加校验。 (`app/log/Logger.php`)
- **[中等] Collection map() 丢失关联键** — `array_map()` 传入多数组时重建数字索引，丢失关联键。现使用 `array_combine()` 保留原始键。 (`app/core/Collection.php`)
- **[中等] Collection sortBy() 忽略 options 参数** — `$options` 参数在方法体中从未使用。现已实现 `SORT_STRING`/`SORT_NUMERIC` 选项。 (`app/core/Collection.php`)
- **[中等] Collection first/last 调用不存在的函数** — `\value()` 是 Laravel 辅助函数，LightPHP 中未定义，集合为空时触发 Fatal Error。现改为 `is_callable()` 判断。 (`app/core/Collection.php`)
- **[低] EventDispatcher 嵌套 dispatch 被完全禁止** — 使用布尔值 `$dispatching` 禁止所有嵌套事件派发，合法场景（如注册事件触发邮件事件）被错误拒绝。现改为栈检测，仅阻止同一事件的递归派发。 (`app/core/EventDispatcher.php`)
- **[低] EventDispatcher until/dispatch 语义冲突** — `dispatch()` 中 `false` 返回值先 `break` 再记录，`until()` 看不到 `false`。现已将 `$results[] = $result` 移到 `break` 判断之前。 (`app/core/EventDispatcher.php`)

### 测试 (Tests)

- 新增 22 个缓存驱动单元测试，覆盖 RedisCache、MemcachedCache、TaggedCache、FileCache 的核心功能
- 测试结果：330/333 通过（3 个失败因 PHP 未安装 mbstring/openssl 扩展，与本次修复无关）
