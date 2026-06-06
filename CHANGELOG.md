# Changelog

All notable changes to the LightPHP framework will be documented in this file.

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
