# Changelog

All notable changes to the LightPHP framework will be documented in this file.

## [2.6.0] - 2026-06-12

### 缺陷修复 (Bug Fixes)

- **[HIGH] QueryBuilder where() null 值生成错误 SQL** — `where('status', null)` 生成 `= NULL` 而非 `IS NULL`，SQL 中 `= NULL` 永远为 false。修复：自动转换为 `IS NULL`/`IS NOT NULL`
- **[HIGH] QueryBuilder whereOr() null 值同理** — `whereOr(['status' => null])` 同样生成错误 SQL。修复：同 where() 处理
- **[MEDIUM] Schema compileCreate() 注释反斜杠未转义** — 注释以 `\` 结尾时转义闭合引号导致 SQL 语法错误。修复：同时转义反斜杠和单引号
- **[MEDIUM] FileCache increment/decrement 键不存在时 TTL=0 永不过期** — 与其他驱动使用 defaultTtl 不一致。修复：键不存在时 TTL=null 让 write() 使用 defaultTtl
- **[MEDIUM] Blade @include 编译期硬编码缓存路径** — 子模板修改后父模板缓存未失效，仍加载旧内容。修复：改为运行时调用 resolveInclude() 检查缓存新鲜度
- **[MEDIUM] SmartyView display() 未重置 layout** — 多次调用 display() 时前一次 layout 泄漏到后续调用。修复：使用后重置 layout
- **[MEDIUM] Cors 通配符+凭证模式二次检查误拒** — 已正确设置 CORS 头但第72行 isOriginAllowed() 仍返回 false 导致 403。修复：排除通配符+凭证情况

## [2.5.0] - 2026-06-12

### 安全修复 (Security Fixes)

- **[HIGH] Router 正则定界符 `~` 与自定义路由正则冲突** — 用户路由正则含 `~` 时导致 preg_match 失败。修复：转义 `~` 字符
- **[HIGH] Router handleNotFound() 过早调用 http_response_code(404)** — 绕过 Response 对象的状态码管理。修复：移除直接调用
- **[HIGH] Validate validated() 返回未验证字段** — 未在规则中定义的字段也被返回，可导致批量赋值攻击。修复：仅返回规则中定义的字段
- **[HIGH] Validate in/notIn 严格比较类型不匹配** — 整数值与字符串参数比较永远失败。修复：将值转为字符串后再比较
- **[MEDIUM] Response redirect() 反斜杠绕过** — `/\evil.com` 可绕过开放重定向检查。修复：同时检查反斜杠
- **[MEDIUM] Request file() 直接读取 $_FILES** — 未使用构造函数快照，可被后续修改。修复：使用 $this->files 快照
- **[MEDIUM] Captcha generate() 异常跳过静态重置** — createImage() 抛异常时静态配置未重置。修复：使用 try/finally

### 缺陷修复 (Bug Fixes)

- **[HIGH] QueryBuilder sum() 缺少 assertNotRaw 检查** — raw 模式下调用 sum() 生成错误 SQL。修复：添加 assertNotRaw
- **[HIGH] QueryBuilder raw() 模式下 update() 未拒绝** — raw SQL 是 SELECT 语义，update 不应执行。修复：添加 isRaw 检查
- **[HIGH] QueryBuilder insert()/update() 空数组生成无效 SQL** — 传入空数组导致 SQL 语法错误。修复：添加空数组校验
- **[HIGH] QueryBuilder limit() 接受负数值** — 生成无效 SQL。修复：添加负数校验
- **[HIGH] Model eagerLoad 忽略传入的 foreignKey/ownerKey 参数** — hasMany/hasOne 分支硬编码外键。修复：使用传入参数
- **[HIGH] Model json 类型 cast 方向错误** — 数据库中 JSON 字符串应解码为数组，而非编码。修复：改为 json_decode
- **[HIGH] Model hasOne() 返回空模型而非 null** — 无关联记录时返回空模型实例，与 belongsTo 不一致。修复：返回 null
- **[HIGH] Model eagerLoad array_filter 误过滤 ID=0** — 不带回调的 array_filter 过滤假值。修复：使用 fn($id) => $id !== null
- **[HIGH] RedisCache increment/decrement TTL 保留逻辑错误** — 先 set 再 expire 导致 TTL 被重置。修复：使用 setex 原子设置
- **[HIGH] RedisCache decrement 可返回负数** — 与其他驱动不一致。修复：添加 max(0, ...)
- **[HIGH] RedisCache attachTag 标签键固定 24h 过期** — 长 TTL 缓存项的标签提前失效。修复：移除固定过期时间
- **[HIGH] MemcachedCache many() falsy 值丢失** — `?:` 运算符将含 falsy 值的数组替换为空数组。修复：使用 GET_PRESERVE_ORDER + is_array 检查
- **[HIGH] MemcachedCache delete() 对不存在键返回 false** — 与 FileCache 不一致。修复：NOTFOUND 时也返回 true
- **[HIGH] RedisCache delete() 对不存在键返回 false** — 与 FileCache 不一致。修复：始终返回 true
- **[HIGH] Connection 不支持嵌套事务** — 嵌套调用抛出 PDOException。修复：使用 SAVEPOINT 模拟嵌套事务
- **[HIGH] Upload save() 缺少危险扩展名二次校验** — 绕过 validate() 直接调用 save() 可上传危险文件。修复：save() 中再次检查
- **[MEDIUM] Throttle decaySeconds=0 导致永久限流** — 应跳过限流而非永久阻塞。修复：decaySeconds<=0 时直接放行
- **[MEDIUM] Request post(null) 与 post($key) 行为不一致** — post() 只返回 JSON 数据丢失 POST 字段。修复：合并 POST 和 JSON
- **[MEDIUM] Request all() 合并顺序与 input() 优先级不匹配** — all() 用 get+post+json，input() 优先 POST。修复：改为 get+json+post
- **[MEDIUM] Model __call 缺少 whereOr 代理** — 静态调用 whereOr 报错。修复：添加到代理列表
- **[MEDIUM] Schema engine() 缺少输入验证** — 可注入任意字符串到 SQL。修复：添加正则验证
- **[MEDIUM] QueryBuilder paginate() 空结果时 last_page=0** — 不符合常规分页约定。修复：total=0 时 last_page=1
- **[MEDIUM] Upload 未拒绝零字节文件** — 空文件通过验证被保存。修复：添加 size===0 检查
- **[MEDIUM] Validate addError() str_replace 数组长度不匹配** — search 数组多于 replace 时用空字符串替换。修复：按索引匹配
- **[LOW] Validate 空规则字符串触发未知规则警告** — `required|` 产生空规则段。修复：跳过空字符串
- **[LOW] Blade @csrf/@method/@break/@default/@continue 缺少词边界** — 可被部分匹配。修复：添加 (?!\w)
- **[LOW] Blade @csrf 未转义输出** — token 值未经 htmlspecialchars。修复：添加转义
- **[LOW] View extend() 无限递归** — 布局继承循环导致栈溢出。修复：添加深度计数器
- **[LOW] Helper asset() 使用 SCRIPT_NAME** — 包含入口文件名导致路径错误。修复：使用 dirname()
- **[LOW] FileCache increment/decrement TTL 可能为负** — 过期边界计算产生负值导致永不过期。修复：使用 max(1, ...)

## [2.4.0] - 2026-06-11

### 安全修复 (Security Fixes)

- **[CRITICAL] Response::redirect() 协议相对URL绕过** — `//evil.com` 可通过正则验证实现开放重定向。修复：使用 str_starts_with 严格验证
- **[CRITICAL] Upload 危险扩展名检查绕过** — `getExtension()` 对危险文件返回空字符串，但 validate() 用返回值检查黑名单导致通过。修复：直接检查文件名所有扩展名
- **[HIGH] Upload 路径前缀碰撞** — `str_starts_with` 无目录分隔符保护，符号链接可导致目录穿越。修复：附加 DIRECTORY_SEPARATOR
- **[HIGH] Logger::clear() 路径遍历** — `$date` 参数未校验，可删除任意文件。修复：添加日期格式正则验证
- **[HIGH] Captcha 空验证码绕过** — Session无验证码时 `hash_equals('','')` 返回 true。修复：空验证码直接返回 false
- **[MEDIUM] View::include() 子视图数据未转义** — 子视图自有数据跳过转义存在XSS风险。修复：对子视图传入数据单独转义
- **[MEDIUM] Env .env值覆盖系统环境变量** — 系统环境变量应优先于.env文件值。修复：仅当系统变量不存在时才使用.env值

### 缺陷修复 (Bug Fixes)

- **[HIGH] QueryBuilder::value() 点号列名SQL错误** — `users.name` 生成 `` `users.name` `` 而非 `` `users`.`name` ``。修复：使用 sanitizeColumn()
- **[HIGH] QueryBuilder::forUpdate() 死代码** — 设置 $forUpdate 但 buildSelect() 未检查。修复：添加 FOR UPDATE 输出
- **[HIGH] QueryBuilder forUpdate+lock 双重锁子句** — 同时调用生成无效SQL。修复：改为 elseif 互斥
- **[HIGH] QueryBuilder ALLOWED_OPERATORS 包含 where() 无法处理的操作符** — IN/BETWEEN/IS 用单占位符绑定导致SQL错误。修复：移除这些操作符
- **[HIGH] Model create()/update() 不触发模型事件** — 直接调用 QueryBuilder 绕过事件系统。修复：添加 fireEvent 调用
- **[HIGH] Model belongsTo() 返回类型 TypeError** — `?static` 在子类返回不同类型实例时抛出 TypeError。修复：改为 `?self`
- **[HIGH] Model eagerLoad belongsTo 硬编码外键** — `{relation}_id` 与 `belongsTo()` 默认外键不一致。修复：使用 getForeignKey()
- **[HIGH] Model __clone 深拷贝关联丢失主键** — 克隆关联模型触发其 __clone 导致丢失主键和exists状态。修复：不再深拷贝关联
- **[HIGH] FileCache increment/decrement 重置TTL** — 操作后使用 defaultTtl 覆盖原有过期时间。修复：保留原TTL
- **[HIGH] RedisCache increment/decrement TTL误判** — 用 `$result === $step` 判断新建key，已有key值等于step时误设TTL。修复：先检查 exists
- **[HIGH] MemcachedCache increment/decrement 竞态条件** — 并发下 add 失败仍返回初始值。修复：add 失败后重试 increment
- **[HIGH] MemcachedCache 序列化不一致** — set() 对标量值不序列化但 get() 总尝试反序列化。修复：统一序列化
- **[HIGH] MemcachedCache attachTag/flushByTag 用 !== false 判断** — 序列化后的 false 值被误判为不存在。修复：使用 getResultCode()
- **[MEDIUM] RedisCache many() 对不存在key返回 false** — 应返回 null 而非 false。修复：检查 exists 转换
- **[MEDIUM] RedisCache flushByTag 未过滤过期key** — 删除已过期key无意义。修复：先检查 exists
- **[MEDIUM] TaggedCache setMany() 失败仍附加标签** — 存储失败时标签指向不存在的key。修复：仅在成功时附加
- **[MEDIUM] Schema compileCreate() 未包含 COLLATE** — collation() 配置无效。修复：添加 COLLATE 子句
- **[MEDIUM] Schema default(false) 生成无效SQL** — 布尔值 false 字符串化为空。修复：添加 is_bool 判断
- **[MEDIUM] Collection groupBy() null转字符串** — null 值被转为 'null' 字符串。修复：使用 array_key_exists
- **[MEDIUM] Collection last() 回调丢失键** — reverse() 重置数字键。修复：用 end/prev 迭代
- **[MEDIUM] Session flash() 缺少老化机制** — flash数据永不过期。修复：添加 ageFlash() new/old 模式
- **[MEDIUM] Container/Router 可空类型参数未处理** — `?Class $param` 无法解析时抛异常而非传 null。修复：检查 allowsNull
- **[MEDIUM] Throttle hit() 每次重置过期时间** — 限流窗口持续滑动。修复：保留原 expire
- **[MEDIUM] Generator nullable类型声明** — `string $param = null` 在 strict_types 下 TypeError。修复：添加 `?` 标记
- **[MEDIUM] Upload 无扩展名文件名尾部带点** — Windows上路径不匹配。修复：空扩展名不加点号
- **[MEDIUM] HasModelEvents observe() 缺少 restoring/restored** — 观察者无法监听恢复事件。修复：添加到事件列表
- **[MEDIUM] SoftDelete 用 db() 绕过 newQuery()** — 可重复软删除已删除记录。修复：改用 newQuery()
- **[MEDIUM] Captcha 静态配置跨请求污染** — 长运行进程中配置不重置。修复：generate() 末尾重置
- **[MEDIUM] Config define() 重复调用致命错误** — 未检查常量是否已定义。修复：添加 defined() 检查
- **[MEDIUM] View validatePath() 路径前缀碰撞** — 同 Upload 问题。修复：附加 DIRECTORY_SEPARATOR
- **[MEDIUM] View render() 输出缓冲区泄漏** — require 异常时 ob 未清理。修复：try/catch 中 ob_end_clean
- **[MEDIUM] RequestLogMiddleware/Cors 使用 $_SERVER** — 绕过 Request 封装。修复：使用 $request 方法

## [2.3.0] - 2026-06-10

### 安全修复 (Security Fixes)

- **[CRITICAL] Response::redirect() 开放重定向漏洞** — 不验证 URL，攻击者可构造 `//evil.com` 重定向。修复：只允许相对路径
- **[HIGH] Generator 代码注入** — `modelName`/`controllerName` 未验证，恶意类名可注入代码到生成的 PHP 文件。修复：添加正则验证
- **[HIGH] Upload 双扩展名绕过** — `shell.php.jpg` 只检查最后扩展名 `jpg`，某些 Web 服务器会执行 `.php.jpg`。修复：检查所有扩展名
- **[HIGH] OutputCache 缓存错误响应** — 4xx/5xx 响应被缓存，后端恢复后仍返回错误。修复：只缓存 2xx/3xx 响应
- **[MEDIUM] Throttle 绕过 Request::ip()** — 直接使用 `$_SERVER['REMOTE_ADDR']`，反向代理后限流失效。修复：使用 `$request->ip()`
- **[MEDIUM] Session 盲信 X-Forwarded-Proto** — 客户端可伪造该头影响 secure 标志。修复：仅使用 `$_SERVER['HTTPS']`
- **[MEDIUM] CsrfMiddleware 直接访问 $_SERVER/$_POST** — 绕过 Request 封装层。修复：使用 `$request->method()`/`$request->post()`/`$request->header()`

### 缺陷修复 (Bug Fixes)

- **[HIGH] OutputCache 未保存状态码** — 缓存回放时始终返回 200，404 页面变为 200。修复：保存并恢复状态码
- **[HIGH] Request::all() JSON 请求丢失 POST 数据** — 有 JSON 时只合并 get+json 忽略 post。修复：合并 get+post+json
- **[MEDIUM] Request::merge() 不合并到 json** — JSON 请求调用 merge 后数据不可见。修复：同时合并到 json
- **[MEDIUM] Throttle::hit() flock() 返回值未检查** — 锁获取失败仍继续读写，高并发下数据损坏。修复：检查返回值
- **[MEDIUM] Middleware::shouldSkip() URI 尾斜杠未归一化** — `/admin/` 无法匹配 pattern `/admin`。修复：rtrim URI
- **[MEDIUM] Container::flush() 未重置 building/aliasResolving** — 异常后调用 flush 无法重置状态。修复：添加重置
- **[MEDIUM] Env 双引号值不支持转义引号** — `"hello\"world"` 被截断为 `hello\`。修复：正确处理 `\"` 转义
- **[MEDIUM] Env::get() 从 $_ENV 取值不做类型转换** — 与 self::$vars 类型不一致。修复：添加 bool/null 转换
- **[MEDIUM] ExceptionHandler TypeError 返回 500** — TypeError/ArgumentCountError 应返回 400。修复：添加专门处理
- **[MEDIUM] Application::handleException() 直接访问 $_SERVER** — 应使用 Request::ip()。修复：通过容器获取 Request
- **[MEDIUM] Generator 模板使用 $_GET** — 应使用 `$request->get()`。修复：替换为 Request 对象
- **[MEDIUM] Router 自定义正则 ReDoS** — 无长度限制的恶意正则可导致拒绝服务。修复：限制 64 字符
- **[LOW] Captcha 验证码非恒定时间比较** — 存在时序攻击风险。修复：使用 `hash_equals()`
- **[LOW] Cookie secure 默认 false** — HTTPS 站点易遗漏。修复：自动推断为 null 时根据 HTTPS 状态决定
- **[LOW] Helper::old() 直接访问 $_POST/$_GET** — 绕过 Request 封装。修复：通过容器获取 Request
- **[LOW] Validate unique/exists 重复添加错误** — 与 applyRule() 双重添加。修复：移除方法内 addError 调用

---

## [2.2.0] - 2026-06-10

### 安全修复 (Security Fixes)

- **[CRITICAL] HasModelEvents 事件监听器跨模型共享** — 所有使用 `HasModelEvents` trait 的模型共享同一份 `$eventListeners` 和 `$observers`，导致为 User 注册的事件在 Post 上也会触发。修复：使用 `static::class` 作为键隔离不同模型的事件
- **[CRITICAL] Request::ip() IP 欺骗漏洞** — 无条件信任 `X-Forwarded-For` 和 `X-Real-IP` 头，攻击者可伪造 IP 绕过限流和访问控制。修复：默认仅使用 `REMOTE_ADDR`，添加可信代理配置
- **[HIGH] Loader::autoload() 路径遍历漏洞** — 类名中的 `..` 可导致加载预期目录之外的文件。修复：验证 `realpath` 在预期目录内
- **[HIGH] ExceptionHandler 生产环境消息泄露** — `HttpException::getMessage()` 直接输出给用户，可能泄露内部信息。修复：生产环境使用状态码对应的安全消息
- **[HIGH] CsrfMiddleware token 不轮换** — 验证通过后 token 不变，可被重放攻击。修复：验证通过后调用 `Session::regenerateToken()`
- **[HIGH] Upload 危险扩展名不完整** — 缺少 `php7`/`php8` 扩展名。修复：添加到黑名单

### 缺陷修复 (Bug Fixes)

- **[HIGH] QueryBuilder 空表名生成无效 SQL** — `buildSelect/Insert/Update/Delete()` 未检查空表名。修复：添加空表名检查并抛出异常
- **[HIGH] Connection DSN 注入风险** — 配置值未验证直接拼接到 DSN。修复：验证 host/port/database/charset 格式
- **[HIGH] Schema::foreign() 外键状态管理** — `foreign()` 不重置 `lastForeignRef`，`on()` 静默失败。修复：`foreign()` 重置状态，`on()` 缺少前置条件时抛异常
- **[HIGH] Schema::getLastBatch() 负数/零值** — `$steps` 为负数或零时生成无效 SQL。修复：`max(1, $steps)`
- **[HIGH] View/Blade sections 跨渲染数据泄漏** — 多次 `render()` 调用之间 sections 不清理，导致前一个视图的 section 泄漏到下一个。修复：`render()` 开始时清理 sections
- **[HIGH] EventDispatcher::until() 不提前停止** — 先执行所有监听器再找非 null 结果，违反"直到"语义。修复：逐个执行，遇到非 null 立即返回
- **[MEDIUM] Request::input()/post() null 值穿透** — `isset()` 对 null 返回 false，导致 JSON 中显式设为 null 的字段穿透到下一层。修复：改用 `array_key_exists()`
- **[MEDIUM] Container 循环依赖无限递归** — A→B→A 导致 Fatal error 而非有意义的异常。修复：添加 `$building` 追踪，检测循环时抛出异常
- **[MEDIUM] SoftDelete::restore() 不触发事件** — 与 `delete()` 行为不一致。修复：添加 `restoring`/`restored` 事件
- **[MEDIUM] Validate::validateUnique/Exists 抛异常** — 应返回 false 标记验证失败，而非抛出 RuntimeException。修复：调用 `addError()` 并返回 false
- **[MEDIUM] Env::load() $_ENV 类型不一致** — 类型转换后的值（bool/null）直接存入 `$_ENV`，与 `set()` 行为不一致。修复：存入原始字符串值
- **[MEDIUM] SmartyView::endsection() ob_get_clean false** — 未处理 `ob_get_clean()` 返回 `false` 的情况。修复：添加 false 检查
- **[LOW] Blade @else 正则缺少词边界** — 可能误匹配 `@elsewhere` 等自定义指令。修复：添加 `(?!\w)` 负向前瞻
- **[LOW] View::render() renderData 未清理** — 渲染完成后 renderData 残留在内存中。修复：`ob_get_clean()` 后清理
- **[LOW] ConnectionInterface 缺少方法声明** — `inTransaction()` 和 `getDatabase()` 未在接口中声明。修复：添加到接口

---

## [2.1.0] - 2026-06-10

### 安全修复 (Security Fixes)

- **[CRITICAL] Upload::validate() 缺少危险扩展名黑名单** — 未配置 `allowedExtensions` 时，可上传 `.php`、`.phar`、`.htaccess` 等可执行文件。修复：添加硬编码的危险扩展名黑名单，始终拒绝 `php/phtml/phar/htaccess/jsp/asp/sh` 等扩展名
- **[HIGH] Response::json() 缺少 JSON_HEX 安全标志** — `json_encode` 未使用 `JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP`，当 JSON 响应被嵌入 HTML `<script>` 标签时可能导致 XSS 攻击。修复：与 `Helper::json()` 和 Blade `@json` 保持一致，添加安全标志

### 缺陷修复 (Bug Fixes)

- **[HIGH] OutputCache::buildCachedResponse() 返回类型不一致** — 返回 `string` 而非 `Response` 对象，与中间件链契约不一致。修复：改为返回 `Response` 对象
- **[HIGH] Cors 中间件凭证+通配符拒绝逻辑缺陷** — 当 `allowed_origins=['*']` 且 `supports_credentials=true` 时，不匹配的 Origin 请求不会被拒绝。修复：统一使用 `isOriginAllowed()` 检查
- **[MEDIUM] EventDispatcher::until() 异常误判** — 监听器抛出异常时，异常对象被当作非 null 返回值。修复：跳过 `\Throwable` 实例
- **[MEDIUM] Env::load() 引号值后注释解析错误** — 如 `KEY="val" # comment` 时引号不被剥离。修复：改用 `strpos` 查找闭合引号
- **[MEDIUM] Router::handleNotFound() null 链式访问** — config 为 null 时链式数组访问导致 PHP warning。修复：提取变量并检查 `is_array()`
- **[MEDIUM] Blueprint::unsigned() 生成无效 SQL** — UNSIGNED 插入列名和类型之间。修复：移除 `$insertAfterName` 参数
- **[LOW] Blade::getCachePath() md5 残留** — 违反项目 sha256 标准。修复：替换为 `hash('sha256', ...)`
- **[LOW] View::extend() 缺少路径验证** — 可能导致布局文件路径遍历。修复：添加 `validatePath()` 检查
- **[LOW] View::include() autoEscape 状态泄漏** — 异常时 `$this->autoEscape` 不会被恢复。修复：使用 try-finally 模式

---

## [2.0.9] - 2026-06-10

### 缺陷修复 (Bug Fixes)

- **[HIGH] OutputCache::buildCachedResponse() 返回类型不一致** — 返回 `string` 而非 `Response` 对象，与中间件链契约不一致，且直接调用 `header()` 绕过 Response 对象。修复：改为返回 `Response` 对象
- **[HIGH] Cors 中间件凭证+通配符拒绝逻辑缺陷** — 当 `allowed_origins=['*']` 且 `supports_credentials=true` 时，不匹配的 Origin 请求不会被拒绝。修复：统一使用 `isOriginAllowed()` 检查，同源请求（无 Origin 头）跳过 CORS 检查
- **[MEDIUM] EventDispatcher::until() 异常误判** — 监听器抛出异常时，异常对象被当作非 null 返回值，导致 `until()` 错误地返回异常对象。修复：跳过 `\Throwable` 实例
- **[MEDIUM] Env::load() 引号值后注释解析错误** — 带引号的值后跟注释（如 `KEY="value" # comment`）时，`str_ends_with` 检查失败导致引号不被剥离。修复：改用 `strpos` 查找闭合引号位置
- **[MEDIUM] Router::handleNotFound() null 链式访问** — `$this->container?->get('config')['app']` 在 config 为 null 时链式数组访问导致 PHP warning。修复：提取变量并检查 `is_array()`
- **[MEDIUM] Blueprint::unsigned() 生成无效 SQL** — `modifyColumn('UNSIGNED', true)` 将 UNSIGNED 插入列名和类型之间（如 `` `age` UNSIGNED INT``）。修复：移除 `$insertAfterName` 参数
- **[LOW] Blade::getCachePath() md5 残留** — 使用 `md5()` 生成缓存路径，违反项目 sha256 标准。修复：替换为 `hash('sha256', ...)`
- **[LOW] View::extend() 缺少路径验证** — `extend()` 方法未调用 `validatePath()`，与 `render()` 不一致，可能导致布局文件路径遍历。修复：添加 `validatePath()` 检查
- **[LOW] View::include() autoEscape 状态泄漏** — `render()` 抛出异常时 `$this->autoEscape` 不会被恢复。修复：使用 try-finally 模式

---

## [2.0.8] - 2026-06-10

### 缺陷修复 (Bug Fixes)

- **[HIGH] Model::save() 更新分支事件逻辑** — `save()` 更新分支在 `$result === 0`（无实际更新）时仍触发 `updated`/`saved` 事件，与 `delete()` 同类BUG。修复：仅在实际影响行数 > 0 时触发
- **[MEDIUM] Collection::map() 空数组兼容** — `array_combine()` 在 PHP 8+ 对空数组返回 `false`，导致 `map()` 对空集合返回损坏对象。修复：添加 `empty($keys)` 守卫

---

## [2.0.7] - 2026-06-07

### 缺陷修复 (Bug Fixes)

- **[HIGH] SoftDelete::delete() 事件逻辑** — 删除失败时（`$result === 0`）仍触发 `deleted` 事件，与 Model::delete() 同类BUG。修复：仅在实际影响行数 > 0 时触发
- **[MEDIUM] QueryBuilder::value() 实例污染** — `value()` 直接修改 `$this->select`，后续调用受影响。修复：使用 `clone` 隔离
- **[MEDIUM] Router HEAD 请求不匹配 GET 路由** — HTTP 规范要求 HEAD 请求应匹配 GET 路由，当前返回 404。修复：dispatch 中 HEAD 自动匹配 GET

---

## [2.0.6] - 2026-06-07

### 安全加固 (Security)

- **[HIGH] 全局 md5→sha256 统一** — OutputCache、Throttle、FileCache 中残留的 `md5()` 全部替换为 `sha256()`，与项目安全标准保持一致

### 缺陷修复 (Bug Fixes)

- **[MEDIUM] Model::delete() 事件逻辑** — 删除失败时（`$result === 0`）不应触发 `deleted` 事件。修复：仅在实际删除行数 > 0 时触发
- **[LOW] Generator 模板命名空间** — 生成的模型模板 `use core\Model` 应为 `use model\Model`

---

## [2.0.5] - 2026-06-07

### 代码质量 (Code Quality)

- **[HIGH] QueryBuilder::paginate() 实例污染** — `paginate()` 调用 `$this->limit()` 修改了当前 QueryBuilder 实例，重复调用时状态被污染。修复：使用 `clone` 隔离分页查询
- **[HIGH] Model::update() 主键泄漏** — `update()` 未从更新数据中移除主键，若主键在 `$fillable` 中会被意外修改。修复：添加 `unset($data[$this->primaryKey])`
- **[MEDIUM] QueryBuilder 缓存哈希算法** — 查询缓存键和 SQL 校验使用 `md5()`，与项目安全标准不一致。修复：统一使用 `sha256`
- **[MEDIUM] Validate 非必填字段误报** — 非必填字段值为 `null`/空字符串时仍执行其他验证规则（如 email、min），导致误报。修复：空值时跳过非 required 规则
- **[MEDIUM] Env 行内注释支持** — `.env` 文件不支持 `KEY=value # comment` 格式，`#` 后内容被当作值。修复：解析时移除行内注释

---

## [2.0.4] - 2026-06-07

### 缺陷修复 (Bug Fixes)

- **[CRITICAL] OutputCache 未初始化变量** — `handle()` 方法中当 `$next($request)` 抛出异常时，`$response` 变量未定义。修复：在 try 块前初始化 `$response = null`
- **[HIGH] EventDispatcher 监听器传播中断** — `dispatch()` 中监听器返回 `false` 时使用 `break` 错误地终止了所有后续监听器。修复为 `continue` 仅跳过当前监听器
- **[MEDIUM] EventDispatcher forget() 缓存清理** — 错误地用事件名作为 `wildcardRegexCache` 的键。修复：清空整个 `wildcardRegexCache` 数组确保缓存一致性
- **[MEDIUM] Upload.files() 单文件兼容** — 单文件上传场景 `$_FILES[$name]['name']` 为字符串时 `count()` 触发 PHP 8+ 警告。修复：增加 `is_array()` 分发兼容单文件/多文件上传
- **[MEDIUM] Application.run() 空响应处理** — `dispatch()` 返回 `null`/`false` 时无任何响应输出。修复：增加兜底逻辑返回 500 错误响应

---

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
