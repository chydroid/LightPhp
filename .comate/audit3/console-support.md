# LightPHP 第三轮审计 — Console / EventDispatcher / Collection / Session / Cookie / Hash / Env / Logger / Seeder / Config / Generator / Connection

- 审计日期：2026-10-05
- PHP 8.4.7 (cli) / pdo_sqlite、openssl、mbstring 可用
- 审计范围：`app/core/console/*`、`app/core/Generator.php`、`app/core/EventDispatcher.php`、`app/core/Collection.php`、`app/core/Session.php`、`app/core/Cookie.php`、`app/core/Hash.php`、`app/core/Env.php`、`app/config/Config.php`、`app/log/Logger.php`、`app/db/Seeder.php`、`app/db/Connection.php`、`bin/console`、`bin/make.php`、`bin/generate-apidoc.php`
- 复现脚本全部位于 `%TEMP%\lp_audit\`，**仓库内未创建任何文件**（`git status --short` 仅显示本报告目录 `.comate/audit3/` 与既有 `verify3.php`）

## 本轮统计

| 分类 | 数量 |
|---|---|
| CRITICAL | 0 |
| HIGH | 4 |
| MEDIUM | 12 |
| LOW | 12 |
| 已排除的疑似项 / 误报 | 6 |

**上一轮误报复核结论**：本轮重新验证了上一轮高关注项，确认 `Session::start()` 的 `httponly/samesite/strict_mode`、`Hash` 的 AES-256-GCM、`Connection` 的 SAVEPOINT 嵌套、`Config::cache()` 原子替换**均已正确**，未重复报告。`Cookie::$secure` 未走可信代理一项**确认仍未修复**（见 L-5）。

---

# 一、已验证缺陷

## H-1 `Command::parseInput()` 把负数位置参数当成选项，`migrate:rollback -1` 静默变 1 层

**文件+行号**：`app/core/console/Command.php:73-79`

**问题代码**：
```php
} elseif (str_starts_with($arg, '-')) {
    $name = substr($arg, 1);
    $value = true;
    if (isset($args[$i + 1]) && !str_starts_with($args[$i + 1], '-')) {
        $value = $args[++$i];
    }
    $this->options[$name] = $value;
}
```

**复现代码**（`%TEMP%\lp_audit\repro_console.php`）：
```php
[$a, $o] = mk('rollback {steps?}')->run2(['-1']);
```

**真实输出**：
```
===== K1. 负数位置参数被当作选项 =====
[OK]   rollback -1  => arguments/options => array (
  0 => array ( 'steps' => NULL, ),
  1 => array ( 1 => true, ),
)
```

**根因**：位置参数分支只判断 `str_starts_with($arg, '--')` 与 `'-'`，没有「该签名是否声明了同名选项」或「是否为合法数字」的判定。`-1` 被拆成选项名 `"1"`、值 `true`，位置参数槽位留空，随后被默认值 `null` 填充。

**触发场景**：`php bin/console migrate:rollback -1` —— 用户想回滚**全部**迁移，实际执行 `rollback(1)`；`migrate:rollback --steps -1` 同样失效。

**建议修复**：先收集签名中已声明的选项名前缀集合，仅当 `-x` 能匹配到已声明的短选项时才走选项分支；否则按位置参数处理（数值型 `-1` 应视为负数）。

**级别**：HIGH（回滚命令语义被静默改变，且无任何提示）

---

## H-2 `Command::parseInput()` 贪婪吞掉紧随选项的位置参数

**文件+行号**：`app/core/console/Command.php:69-71`、`:76-78`

**问题代码**：
```php
if (isset($args[$i + 1]) && !str_starts_with($args[$i + 1], '-')) {
    $value = $args[++$i];
}
```

**复现代码**（`repro_console.php`）：
```php
[$a, $o] = mk('make:model {name} {table?}')->run2(['--force', 'User']);
```

**真实输出**：
```
===== K2. --opt 贪婪吞掉后续位置参数 =====
[OK]   make:model --force User => array (
  0 => array ( 'name' => NULL, 'table' => NULL, ),
  1 => array ( 'force' => 'User', ),
)
```

**根因**：框架无法区分「`--force` 是布尔开关，后面的 `User` 是位置参数」与「`--force` 接收一个值」。它无条件消费下一个 token。真实命令中 `php bin/console make:model --force User` 会让 `name` 变成 `null`，随即在 `bin/console:180` 的 `preg_match(..., $name)` 处致命失败（见 H-3）。

**建议修复**：只有当签名中声明的该选项**带默认值占位**（即 `{--opt=}`，说明期望一个值）时才消费下一个 token；纯开关 `{--flag}` 不消费。

**级别**：HIGH


---

## H-3 `bin/console` 缺少必填参数时抛未捕获 TypeError，退出码 255 而非友好提示

**文件+行号**：`bin/console:178`、`:216`、`:244`、`:273`、`:87`

**问题代码**（`bin/console:177-180`，`make:model`）：
```php
$name = $this->argument('name');
$table = $this->argument('table') ?? strtolower($name) . 's';

if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
```

**复现命令**（真实执行，仓库零改动）：
```
> php bin/console make:controller
Fatal error: Uncaught TypeError: preg_match(): Argument #2 ($subject) must be of type string, null given
  in C:\Users\CY\.cline\worktrees\55bfe\LightPhp\bin\console:216
#1 ... core\console\Command@anonymous->handle()
--- EXIT: -1 ---

> php bin/console config:show
Fatal error: Uncaught TypeError: config\Config::get(): Argument #1 ($key) must be of type string, null given
  in ...\bin\console on line 87
--- EXIT: -1 ---

> php bin/console make:model
Fatal error: Uncaught TypeError: strtolower(): Argument #1 ($string) must be of type string, null given
  in ...\bin\console:178
--- EXIT: -1 ---
```

**根因**：`parseInput()` 对缺失的必填参数填 `null`（`Command.php:90` 的 `$def['default'] ?? null`），而 `Console::run()` **不做必填校验**（`Console.php:45-49` 直接 `handle()`）。命令体内直接把它喂给 `strict_types=1` 的 `preg_match`/`strtolower`/`Config::get(string)`。同时 `Console::run()` 无 try/catch，异常一路冒泡成 PHP fatal。

**建议修复**：`parseInput()` 结束后校验必填参数（`required` 标记已保留），缺失则抛出带用法提示的异常；`Console::run()` 捕获 `\Throwable` 并 `return 1`。

**级别**：HIGH（4 个内置命令均受影响，CLI 可用性直接受损）

---

## H-4 `EventDispatcher` 的优先级不跨通配符 pattern 生效

**文件+行号**：`app/core/EventDispatcher.php:31`（排序）、`:148-154`（合并）

**问题代码**：
```php
// listen(): 只在单个 pattern 的监听器数组内部排序
usort($this->listeners[$event], fn($a, $b) => $b['priority'] <=> $a['priority']);

// getListenersForEvent(): 按 pattern 的注册顺序拼接，完全不看 priority
foreach ($this->listeners as $pattern => $registered) {
    if ($this->matchWildcard($pattern, $event)) {
        foreach ($registered as $entry) {
            $listeners[] = $entry['listener'];
        }
    }
}
```

**复现代码**（`%TEMP%\lp_audit\repro_final.php`）：
```php
$b = new EventDispatcher();
$b->listen('user.created', fn($x) => 'exact-prio0', 0);
$b->listen('user.*',        fn($x) => 'wild-prio10', 10);
echo json_encode($b->dispatch('user.created'));
```

**真实输出**：
```
同 pattern 内 [期望 high,low] = ["high","low"]      ← 优先级生效
跨 pattern（精确先注册，wild 优先级更高）= ["exact-prio0","wild-prio10"]
^ 实际执行顺序跟随 pattern 注册顺序，而非全局优先级
```

**根因**：`$priority` 只用于单个 pattern 内部的 `usort`；`getListenersForEvent()` 把多个 pattern 的结果按 `$this->listeners` 的**插入顺序**直接 `[]` 拼接，丢弃了 `priority` 字段。

**影响**：文档明确宣称「支持优先级排序」（类注释第 8 行），但只要精确监听器与通配符监听器混用，优先级即失效，且结果依赖注册顺序——表现为「我调了优先级但没生效」。

**建议修复**：在 `getListenersForEvent()` 中把匹配到的 entry 连同 `priority` 收集，拼接后再 `usort` 一次（PHP 8.0+ 的 `usort` 已稳定，同优先级自动保持注册顺序，可用 V8 实测 `["first","second","third"]` 作为回归基准）。

**级别**：HIGH（框架核心特性失效）

---

## M-1 `Console::run()` 无条件拦截 `list`，用户注册的 `list` 命令永远无法执行

**文件+行号**：`app/core/console/Console.php:33-35`

**复现代码**（`repro_console.php`）：
```php
$con = new Console();
$con->register(new class extends Command {
    protected string $signature = 'list';
    public function handle(): int { echo "MY LIST RAN\n"; return 42; }
});
$rc = $con->run(['console', 'list']);
```

**真实输出**：
```
[OK]   run list 的返回码 [期望 42] => 0
[OK]   run list 的输出 => "LightPHP Console v2.15.9 ... | list    |                             |  | list    | List all available commands |"
```

**根因**：`run()` 在查表**之前**就 `return $this->listCommands()`，注册项被完全绕过。输出表格中还出现两行 `list`（一行描述为空来自被覆盖的注册项，一行来自 `listCommands()` 第 74 行的硬编码追加）。

**建议修复**：`run()` 中改为 `if ($commandName === 'list' && !isset($this->commands['list']))`；并让 `listCommands()` 不再无条件追加硬编码的 `list` 行。


---

## M-2 `Command` 签名中声明的选项默认值从不写入 `$this->options`

**文件+行号**：`app/core/console/Command.php:88-92`

**问题代码**：
```php
foreach ($positionalDefs as $def) {                    // ← 只有位置参数
    if (!isset($this->arguments[$def['name']])) {
        $this->arguments[$def['name']] = $def['default'] ?? null;
    }
}
// ← 完全没有对应的 options 回填逻辑
```

**复现代码**（`repro_console.php`）：
```php
$c = mk('cmd {--flag} {--opt=hello}');
[$a, $o] = $c->run2([]);
```

**真实输出**：
```
===== K5. 选项默认值 default=false 是否生效 =====
[OK]   cmd (无参数) options => array ( )
[OK]   hasOption(flag) [期望 true] => false
[OK]   option(flag) [期望 false] => NULL
```

**根因**：`parseOption()`（`:154-169`）正确解析出 `'default' => false` / `'hello'`，但回填循环**只遍历 `$positionalDefs`**（`:54-58` 构造），option 定义被解析出来后即丢弃。

**影响**：`option('flag')` 期望 `false`（布尔开关语义）却得到 `null`；`option('opt')` 期望 `'hello'` 却得到 `null`。`bin/console` 的 `serve` 用 `{--host=localhost}` 却靠 `$this->option('host', 'localhost')` 二次兜底才没出错——该缺陷已被下游 workaround 掩盖。

**建议修复**：
```php
foreach ($definition as $def) {
    if ($def['type'] === 'option' && !array_key_exists($def['name'], $this->options)) {
        $this->options[$def['name']] = $def['default'];
    }
}
```

**级别**：MEDIUM

---

## M-3 `Collection::avg($key)` 分母与 `array_column` 的实际行数不一致

**文件+行号**：`app/core/Collection.php:101-106`

**问题代码**：
```php
public function avg(?string $key = null): float|int
{
    $count = $this->count();          // 全部行数
    if ($count === 0) return 0;
    return $this->sum($key) / $count; // sum 内部 array_column 会丢弃缺列的行
}
```

**复现代码**（`repro_final.php`）：
```php
$rows = [['v' => 10], ['v' => 20], ['other' => 99]];
Collection::make($rows)->avg('v');
```

**真实输出**：
```
sum('v')   = 30
avg('v')   = 10  (正确应为 15)
min('v')   = 10
max('v')   = 20
```

**根因**：`sum($key)` 走 `array_column($this->items, $key)`，缺 `v` 键的行被 PHP 静默丢弃（分子只算 2 行 = 30），但分母 `$this->count()` 仍是 3，得 10。**同一行数据 `sum`/`min`/`max` 全对，只有 `avg` 错**——因为只有 `avg` 除以 `count()`。

**影响**：统计类代码（报表、平均分、平均耗时）会静默偏低，偏低幅度取决于缺失比例，无任何警告。

**建议修复**：分母与分子取同一数据源。
```php
$values = $key === null ? $this->items : array_column($this->items, $key);
return $values === [] ? 0 : array_sum($values) / count($values);
```

**级别**：MEDIUM

---

## M-4 `Collection::split()` 对空集合抛未捕获 ValueError；负数静默只返回 1 块

**文件+行号**：`app/core/Collection.php:416-420`

**问题代码**：
```php
$chunks = array_chunk($this->items, (int) ceil(count($this->items) / max(1, $number)));
```

**复现代码**（`repro_collection.php`）：`Collection::make([])->split(3);`

**真实输出**：
```
===== C1. Collection::split() 边界 =====
[FAIL] split(3) 空集合 => ValueError: array_chunk(): Argument #2 ($length) must be greater than 0
[FAIL] split(0) 空集合 => ValueError: array_chunk(): Argument #2 ($length) must be greater than 0
[FAIL] split(-2) 空集合 => ValueError: array_chunk(): Argument #2 ($length) must be greater than 0
...
[OK]   split(-2) 7元素 块数 => 1
```

**根因**：`count([]) === 0`，`ceil(0 / max(1, $number))` 得 `0.0`，`max(1,...)` 的保护被 `ceil` 归零击穿，`array_chunk($items, 0)` 抛 `ValueError`。空集合是分页/分块的常见输入，调用方无从预知。

**建议修复**：
```php
if ($number < 1) { throw new \InvalidArgumentException('split() requires $number >= 1'); }
if ($this->items === []) { return new static([]); }
```

**级别**：MEDIUM

---

## M-5 `Collection::zip()` 对非连续整数键静默产出全 null

**文件+行号**：`app/core/Collection.php:379-387`

**问题代码**：
```php
$result[] = [$this->items[$i] ?? null, $items[$i] ?? null];  // ← 按位置下标取值
```

**复现代码**（`repro_collection.php`）：
```php
Collection::make(['k1' => 'a', 'k2' => 'b'])->zip(['x', 'y']);
Collection::make([0 => 'a', 2 => 'b', 5 => 'c'])->zip(['x', 'y', 'z']);
```

**真实输出**：
```
[OK]   zip 字符串键 => array ( 0 => array ( 0 => NULL, 1 => 'x', ), 1 => array ( 0 => NULL, 1 => 'y', ) )
[OK]   zip 稀疏整数键 => array (
  0 => array ( 0 => 'a', 1 => 'x', ),
  1 => array ( 0 => NULL, 1 => 'y', ),
  2 => array ( 0 => 'b', 1 => 'z', ),
)
```

**根因**：用 `$i` 作下标索引 `$this->items`，隐含假设左侧是 `0..n-1` 连续列表。注意稀疏输入下**顺序还被打乱**（`0=>'a', 2=>'b', 5=>'c'` 产出 `'a',null,'b'`），因为按位置而非按遍历顺序取值。

**建议修复**：改用 `array_values($this->items)` 归一化后再按下标配对。

**级别**：MEDIUM

---

## M-6 `Config::get()` / `has()` 在路径中段遇到标量时抛未捕获 TypeError

**文件+行号**：`app/config/Config.php:15-20`、`:48-53`

**问题代码**：
```php
foreach ($keys as $k) {
    if (!array_key_exists($k, $value)) {   // ← $value 可能是 string/int/null
        return $default;
    }
    $value = $value[$k];
}
```

**复现代码**（`repro_log_config.php`）：
```php
Config::set('a.b', 'scalar');
Config::get('a.b.c', 'DEF');
Config::has('a.b.c');
```

**真实输出**：
```
===== CF1. Config 中间标量 =====
[OK]   Config::get("a.b.c") => 'TypeError: array_key_exists(): Argument #2 ($array) must be of type array, string given'
[OK]   Config::has("a.b.c") => 'TypeError: array_key_exists(): Argument #2 ($array) must be of type array, string given'
[FAIL] set("p.q",1) 后 set("p","flat") 再 get("p.q") => TypeError: array_key_exists(): Argument #2 ($array) must be of type array, string given
```

**根因**：未对中间层做 `is_array()` 检查。`set('a.b','scalar')` 后 `a` 是 `['b'=>'scalar']`，再取 `a.b.c` 时 `$value` 变成字符串，`array_key_exists()` 在 `strict_types=1` 下直接 TypeError。注意 `set()` 侧有防护（`:36` 的 `!is_array($config[$k])`），`get()`/`has()` 侧没有——**写路径防了，读路径没防**。

**建议修复**：
```php
foreach ($keys as $k) {
    if (!is_array($value) || !array_key_exists($k, $value)) {
        return $default;   // has() 返回 false
    }
    $value = $value[$k];
}
```

**级别**：MEDIUM

---

## M-7 `Config::load()` 省略尾斜杠时静默加载 0 个文件

**文件+行号**：`app/config/Config.php:69`（`glob($path . '*.php')`）

**复现脚本**（`%TEMP%\lp_audit\t_config_load.php`）与**真实输出**：
```
--- Config::load 需要路径带结尾分隔符 ---
  trailing-slash   path='C:\Users\CY\AppData\Local\Temp/lpT_1340/' => 'v'
  no-slash         path='C:\Users\CY\AppData\Local\Temp/lpT_1340'  => NULL
```

**根因**：`$path . '*.php'` 硬编码拼接正斜杠。调用方传 `APP_PATH . 'config'`（无尾斜杠）时 glob 变成 `.../config*.php`，匹配 0 个文件；`$files === []` 是合法值，函数**静默返回，无异常无警告**。

**当前状态**：`Application::loadConfig()`（`Application.php:90`）传的是 `APP_PATH . 'config/'`，**框架自身路径正确**。因此这是 public API 陷阱而非现网故障——但任何第三方/测试代码按直觉传目录名就会得到「配置全空」的诡异现象。

**建议修复**：`$files = glob(rtrim($path, '/\\') . '/*.php');`，并在匹配 0 个文件时 `error_log` 提示。

**级别**：MEDIUM

---

## M-8 `Config::load()` 用 `require`，二次调用导致函数重复声明 fatal

**文件+行号**：`app/config/Config.php:76`

**问题代码**：`$result = require $file;   // 非 require_once`

**复现脚本**（`t_config_load.php`）与**真实输出**：
```
--- Config::load 重复 require 同一文件（含函数声明）---
  第一次: a = 1

Fatal error: Cannot redeclare function lp_u_fn() (previously declared in
C:\Users\CY\AppData\Local\Temp\lpU_1340\withfn.php:2) in ...\withfn.php on line 2
```

**根因**：配置目录一旦包含任何函数/类声明（非纯 `return [...]`），第二次 `Config::load()` 即致命失败。当前 `Application` 只调用一次，但 `Config::load()` 是 public API（测试、多应用、插件场景都会重入）。

**建议修复**：改用 `require_once`（重复调用时结果仍会被 `self::$items[$name] = $result` 覆盖为等价内容，无副作用）。

**级别**：MEDIUM

---

## M-9 `subscribeClass()` 每次派发都 `new` 新实例，订阅者状态全部丢失

**文件+行号**：`app/core/EventDispatcher.php:251-254`、`:305-311`

**问题代码**：
```php
$this->listen($event, function (string $e, mixed ...$payload) use ($subscriberClass, $name) {
    $instance = new $subscriberClass();     // ← 每次派发都新建
    return $instance->$name($e, ...$payload);
});
```

**复现代码**（`repro_events2.php`）：
```php
class SubCounter {
    public static int $total = 0;
    public int $n = 0;
    public function onTick(): void { $this->n++; self::$total++; }
}
$d->subscribeClass(SubCounter::class);
$d->dispatch('tick'); $d->dispatch('tick');
```

**真实输出**：
```
静态计数器 total（证明回调确实执行了2次） => 2
```

**根因**：闭包每次调用都实例化新对象，实例属性 `$n` 每次归零。订阅者无法持有任何状态（计数器、缓存、去重集合、DB 连接均不可用），跨事件的初始化开销也无法摊销。

**建议修复**：在 `subscribeClass()` 内一次性实例化并复用（构造依赖可经 `Container` 解析）：
```php
$instance = new $subscriberClass();
$this->listen($event, fn(string $e, mixed ...$p) => $instance->$name($e, ...$p));
```

**级别**：MEDIUM

---

## M-10 `subscribeClass()` 对需要构造参数的订阅者，监听器静默永久失效

**文件+行号**：`app/core/EventDispatcher.php:252`（无参 `new`）配合 `:91-95`（吞异常）

**复现代码**（`repro_final.php`）：
```php
class NeedsCtor { public function __construct(private string $x) {} public function onFoo(): void {} }
$d->subscribeClass(NeedsCtor::class);
echo $d->hasListeners('foo');  echo $d->dispatch('foo');
```

**真实输出**：
```
hasListeners('foo') = true
dispatch('foo') 返回 = []
^ 若为空数组 => 监听器存在但每次调用都抛 ArgumentCountError 并被 catch 吞掉
```

**根因**：`new $subscriberClass()` 不传参，带必填构造参数的类抛 `ArgumentCountError`；`dispatch()` 的 `catch (\Throwable) { error_log(...); continue; }`（`:91-95`）把它吞掉。结果：`hasListeners()` 返回 `true` 让人以为订阅成功，实际**每次派发都失败**，只有 `error_log` 里有一条信息。

**建议修复**：`subscribeClass()` 注册时预先实例化一次以暴露构造错误（或经 `Container` 解析依赖），不要把失败推迟到每次派发。

**级别**：MEDIUM

---

## M-11 `Hash::make()` / `makeToken()` / `makeKey()` 不校验入参，抛未捕获 ValueError

**文件+行号**：`app/core/Hash.php:8-11`、`:94-97`、`:105-108`

**复现脚本**（`%TEMP%\lp_audit\repro_state.php`）与**真实输出**：
```
===== H1. Hash cost 边界 =====
[FAIL] make cost=1 => ValueError: Invalid bcrypt cost parameter specified: 1
[FAIL] make cost=0 => ValueError: Invalid bcrypt cost parameter specified: 0
[FAIL] make cost=32 => ValueError: Invalid bcrypt cost parameter specified: 32
[OK]   needsRehash cost=0 => true

===== H2. makeToken/makeKey 边界 =====
[FAIL] makeToken(0)  => ValueError: random_bytes(): Argument #1 ($length) must be greater than 0
[FAIL] makeToken(-1) => ValueError: random_bytes(): Argument #1 ($length) must be greater than 0
[FAIL] makeKey(0)    => ValueError: random_bytes(): Argument #1 ($length) must be greater than 0
[FAIL] makeKey(-5)   => ValueError: random_bytes(): Argument #1 ($length) must be greater than 0
```

**根因**：三个方法直接把外部可影响的整数透传给 `password_hash()` / `random_bytes()`。PHP 8 把「非法 cost」「非正长度」升级为 `ValueError`，`Hash` 未做前置校验也未捕获。

**特别说明**：`cost=31` 是**合法**的（实测 `password_hash` 接受），但耗时天文数字（单次 >30 秒），本轮复现脚本因超时被迫移除该用例——若 `$cost` 可被配置/请求影响，这是一个**可被触发的拒绝服务**，当前无任何上限保护。

**建议修复**：入口处 `if ($cost < 4 || $cost > 15) throw new \InvalidArgumentException(...)`；`makeToken`/`makeKey` 校验 `$length >= 1`。

**级别**：MEDIUM（`needsRehash` 不抛异常、返回 `true` 触发重新哈希，属安全侧的正确降级——**已验证为非缺陷**）

---

## M-12 `Generator::setDbConfig()` 不重置已建立的连接，后续操作继续用旧库

**文件+行号**：`app/core/Generator.php:16-27`

**问题代码**：
```php
public function setDbConfig(array $config): void
{
    $this->config = $config;   // ← 没有 $this->db = null
}

private function getDb(): \db\Connection
{
    if ($this->db === null) {   // ← 已连接后永远不再重建
        $this->db = new \db\Connection($this->config);
    }
    return $this->db;
}
```

**复现代码**（`repro_generator.php`）：
```php
$g->setDbConfig(['driver'=>'sqlite','database'=>':memory:']);
$g->getTables();                                    // 建立连接
$g->setDbConfig(['driver'=>'sqlite','database'=>'/nonexistent_dir_xyz/db.sqlite']);
$g->getTables();
```

**真实输出**：
```
未触发连接时 db 属性 => 'null (延迟连接)'
getTables() 后 db 属性 => '已连接: db\Connection'
再次 setDbConfig 后 db 属性（未重置！） => '仍连接旧库: :memory:'
getTables() 返回（静默吞异常） => array ( )
```

**根因**：`setDbConfig()` 只换配置数组不清连接，`getDb()` 的 `if ($this->db === null)` 守卫使旧连接被永久复用。新配置**静默失效**——调用方以为切库成功，实际仍在旧库上跑，且 `getTables()` 的 `catch (\Exception) { return []; }`（`:37-39`）把异常吞成空数组，问题完全不可见。

---

# 二、LOW 级缺陷

## L-1 `Collection::take(PHP_INT_MIN)` → `abs()` 溢出为 float → TypeError

**文件+行号**：`app/core/Collection.php:174-180`

**复现代码**：`Collection::make(range(1,5))->take(PHP_INT_MIN)`

**真实输出**：
```
[FAIL] take(PHP_INT_MIN) => TypeError: array_slice(): Argument #3 ($length) must be of type ?int, float given
```

**根因**：`abs(PHP_INT_MIN)` 因整数溢出返回 float `9.223372036854776E+18`，传给 `array_slice()` 的 `?int $length` 触发 TypeError。

**建议修复**：对 `$limit` 做钳位，`PHP_INT_MIN` 时直接返回全部元素。

**级别**：LOW（需极端输入，但说明缺边界防护）

---

## L-2 `Collection::pull()` 传入数组/对象键 → 未捕获 TypeError

**文件+行号**：`app/core/Collection.php:440`

**复现代码**：`Collection::make(['a'=>1])->pull([])`

**真实输出**：
```
[FAIL] pull([]) => TypeError: array_key_exists(): Argument #1 ($key) must be a valid array offset type
```

**根因**：`$key` 声明为 `mixed`，直接传入 `array_key_exists()`。签名暗示接受任意类型，实际不接受（`offsetExists` 同样如此）。

**建议修复**：入口加 `if (!is_int($key) && !is_string($key)) { throw new \InvalidArgumentException(...); }`

**级别**：LOW

---

## L-3 `Collection::unique()` 无 key 时用 `array_unique` 默认 `SORT_STRING`，不同类型被合并

**文件+行号**：`app/core/Collection.php:268-272`

**复现代码**：`Collection::make([1,'1',true,2,0,false,null])->unique()`

**真实输出**：
```
[OK]   unique [1,"1",true,2] => array ( 0 => 1, 3 => 2, 4 => 0, 5 => false )
```

**根因**：`array_unique()` 默认 `SORT_STRING`，把 `1`、`'1'`、`true` 全部字符串化为 `"1"` 后去重，只保留首个；`null` 被字符串化为 `""` 与 `false`→`""` 混淆（输出中 `false` 保留而 `null` 被丢弃）。`contains()` 用的是严格比较 `in_array(..., true)`，两者语义不一致。

**建议修复**：`array_unique($this->items, SORT_REGULAR)`。

**级别**：LOW

---

## L-4 `Collection::chunk()` 传 0 或负数时静默返回单块

**文件+行号**：`app/core/Collection.php:324-339`

**复现代码**：`Collection::make(range(1,5))->chunk(0)` / `->chunk(-3)`

**真实输出**：
```
chunk(0) 5元素块数 = 1
chunk(-3) 5元素块数 = 1
```

**根因**：`if (count($chunk) === $size)` 中 `$size <= 0` 时条件恒不成立（`count($chunk)` 从 1 递增），循环结束时全部元素被塞进唯一一块。静默无错但结果完全错误。

**建议修复**：入口 `if ($size < 1) { throw new \InvalidArgumentException(...); }`。

**级别**：LOW

---

## L-5 `Cookie::set()` / `delete()` 的 `$secure` 默认不走可信代理判断（Session 已修，Cookie 漏改）

**文件+行号**：`app/core/Cookie.php:15-17`、`:41-43`

**复现脚本**（`repro_cookie.php`）与**真实输出**：
```
Request::isSecureFromServer() (Session 用的) => 'true'
Cookie::set 前 6 行:
    public static function set(string $key, mixed $value, int $expire = 0, ... ?bool $secure = null, ...): bool
    {
        if ($secure === null) {
            $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        }
```

**根因**：`CHANGELOG.md:133` 记录的修复只联动改了 `Session::start()`（现为 `Request::isSecureFromServer()`，`Session.php:18`），`Cookie` 仍是老写法。Nginx TLS 终止部署下 PHP 看到的是 http → 所有 `Cookie::set()` 写出的 cookie 都不带 `Secure`。

**建议修复**：`$secure = \core\Request::isSecureFromServer();`

**级别**：LOW（本项目标准部署若为「Cloudflare/负载均衡 → 直连 PHP」则影响升为 MEDIUM。**注**：此条已在 `.comate/audit/support.md` 第 50 项记录，本轮复测确认仍未修复）

---

## L-6 `Cookie::set()` 不回写 `$_COOKIE`，同请求内读不到自己刚写的值

**文件+行号**：`app/core/Cookie.php:36`（对比 `:44` 的 `delete()` 有 `unset($_COOKIE[$key])`）

**复现脚本**与**真实输出**：
```
===== CK2. Cookie::set 是否回写 $_COOKIE =====
[OK]   set 后 has() 同请求 => 'false'
```

**根因**：`set()` 只调 `setcookie()`，不更新超全局数组；`delete()` 却做了 `unset($_COOKIE[$key])`。同一请求内 `Cookie::set('tok','v'); Cookie::get('tok')` 返回 `null`。

**建议修复**：`setcookie(...)` 成功后 `$_COOKIE[$key] = $value;`。

**级别**：LOW


**建议修复**：`setDbConfig()` 中加 `$this->db = null;`。同时 `getTables()`/`getTableColumns()` 的 `catch` 应至少 `error_log`。

**级别**：MEDIUM


---

## L-7 `Env::get()` 对 `$_ENV` 中的非字符串值调用 `strtolower()` → TypeError

**文件+行号**：`app/core/Env.php:111-118`

**复现脚本**（`repro_state.php`）：
```php
$_ENV['WEIRD'] = true;
Env::get('WEIRD');
```

**真实输出**：
```
[OK]   Env::get(WEIRD) $_ENV=true => 'TypeError: strtolower(): Argument #1 ($string) must be of type string, true given'
```

**根因**：`$val = $_ENV[$key]; $lower = strtolower($val);` 未做类型检查。注意 `Env::set()` 写入 `$_ENV` 的是**字符串**（`:132`），但 `Env::load()` 在 `CHANGELOG.md:91-93` 修复后写入的是**类型化值**（`:87` `$_ENV[$key] = $value`）。即 `.env` 中 `FOO=true` 时 `$_ENV['FOO'] === true`（bool），若该键未进入 `self::$vars`（如 `$loaded` 守卫跳过了该次 load），`get()` 走到此分支即崩。

**建议修复**：`$lower = strtolower((string) $val);` 或先 `is_string()` 判断。

**级别**：LOW（触发路径较窄）

---

## L-8 `Session::flash($key, null)` 无法写入 null，读写语义二义

**文件+行号**：`app/core/Session.php:136-145`

**复现脚本**（`t_session2.php`）与**真实输出**：
```
===== S1b. flash(key, null) —— 想写 null 却变成读 =====
  flash("n", null) 返回: NULL
  _SESSION 里有 _flash_n 吗: false
  flashSet("n2", null) 后 _flash_n2: true
  flashGet("n2") 返回: NULL
```

**根因**：`$value === null` 被当作「省略了第二参数 → 进入读取分支」。想 flash 一个 null 值永远做不到。对照 `flashSet()`（`:147-152`）签名强制两参，无此问题。

**建议修复**：用 `func_num_args()` 区分，或把读取分支改为独立的 `flashPull()`。

**级别**：LOW

---

## L-9 `Session::all()` 泄漏内部簿记键 `_flash_old` / `_flash_new`

**文件+行号**：`app/core/Session.php:166-170`

**复现脚本**与**真实输出**：
```
===== S4. all() 是否泄漏内部簿记键 =====
[OK]   all() 键 => array ( 0 => '_flash_new', )
```

**根因**：`return $_SESSION;` 未过滤框架内部键。业务代码 `Session::all()` 后 `json_encode()` 会把 `_flash_*` 一起吐给客户端（可能泄露 flash 里的敏感数据），或污染日志快照。

**建议修复**：`return array_diff_key($_SESSION, array_flip(['_flash_old', '_flash_new']));`

---

## L-10 `Logger` 把 `INF` / `NAN` 上下文静默记为 `0`

**文件+行号**：`app/log/Logger.php:72`

**复现脚本**（`repro_log_config.php`）与**真实输出**：
```
===== L3. 不可编码上下文 INF/NAN/资源 =====
[OK]   行 => '[2026-10-05 03:58:56] INFO: vals INF {"b":0,"c":null}'
```

**根因**：`json_encode(..., JSON_PARTIAL_OUTPUT_ON_ERROR)`（`:72`）把 `NAN`/`INF` 替换为 `0`、资源替换为 `null`，不报错。日志里的数值**与真实值不符**且无任何标记。插值路径的 `'a' => INF` 反而正确渲染成字符串 `INF`（`:213` 的 `(string) INF === 'INF'`），两条路径行为不一致。

**建议修复**：编码前检测 `is_finite()`，非有限值替换为字符串 `'INF'`/`'NAN'` 而非数字 `0`。

**级别**：LOW（会误导排障）

---

## L-11 `Generator::generateResourceRoutes()` 不校验表名，可产出语法非法的路由代码

**文件+行号**：`app/core/Generator.php:244-258`

**复现脚本**（`t_gen_path.php`）与**真实输出**：
```
routes('a/b') => $router->get('/a/b', [\controller\A/bController::class, 'index']);
routes('bad name!') => $router->get('/bad-name!controller', [\controller\BadName!Controller::class, 'index']);
```

**根因**：`generateModel`/`generateController` 都有 `/^[a-zA-Z0-9_]+$/` 前置校验（`:58`、`:97`），`generateResourceRoutes()` **没有**。`bin/make.php routes <table>`（`:143-153`）也未做校验，直接把 `a/b` 变成 `\controller\A/bController` —— 复制粘贴进路由文件即 PHP 语法错误。

**建议修复**：对 `$controllerName` 复用 `/^[a-zA-Z_][a-zA-Z0-9_]*$/` 校验。

**级别**：LOW（生成器输出，非运行时路径）

---

## L-12 `Logger::log()` 对非法级别抛异常，会中断「记录日志」这一容错操作

**文件+行号**：`app/log/Logger.php:57-59`

**复现脚本**与**真实输出**：
```
[FAIL] setLevel("warn") => InvalidArgumentException: Invalid log level: warn
[FAIL] log("warn", ...) => InvalidArgumentException: Invalid log level: warn
```

**根因**：`throw new \InvalidArgumentException` 用于**运行时**的 `log()` 调用。`log()` 是 PSR-3 接口、是被 `catch` 块广泛调用的容错操作，此处抛异常等于「日志失败 → 业务失败」。`setLevel()` 抛异常合理（配置错误），两者不应一致。

**建议修复**：`log()` 中非法级别降级为 `error_log()` 提示并按 `error` 处理，不抛。

**级别**：LOW

---

# 三、疑似（未验证）与已排除的误报

| # | 疑似项 | 复核方法 | 结论 |
|---|---|---|---|
| S-1 | `Config::load()` 因 Windows 反斜杠导致 glob 失效、配置静默为空 | `t_glob.php` 对比 5 种分隔符组合；`t_config_load.php` 对照有/无尾斜杠 | **误报（已排除）**。`glob()` 在 Windows 上对正斜杠与反斜杠都能匹配；先前观察到「加载为空」是复现脚本**漏传尾分隔符**所致。真正的 API 陷阱已作为 M-7 单列 |
| S-2 | `Config::load()` 二次调用无条件 fatal | 纯 `return [...]` 的配置文件重复 load | **条件性成立**，已按 M-8 记录：仅当配置含函数/类声明时 fatal，纯数组配置可重复加载 |
| S-3 | `Hash::encrypt()` GCM 密文可被篡改或用错密钥解开 | 篡改字节 + 错误密钥 + 非 base64 + 长度不足 四组 | **正确，非缺陷**。全部返回 `null`，AEAD 认证有效 |
| S-4 | `Connection` 嵌套事务 savepoint 层级错乱 | 3 层嵌套分别 commit / rollback，含全回滚后复位 | **正确，非缺陷**。SAVEPOINT 命名 `sp_{level}` 与增减层级严格对应，`inTransaction()` 各阶段均符合预期 |
| S-5 | `Connection::beginTransaction()` 在外部已有事务时崩溃 | 外部 `$pdo->beginTransaction()` 后再调 | 确实抛 `PDOException: There is already an active transaction`，但这是 PDO 的正确防御，且框架未承诺支持外部事务。**不作为缺陷报告** |
| S-6 | `Logger` 写入不可写目录时抛异常 | `/proc/nonexistent_xyz/` | **正确，非缺陷**。降级为 `error_log`，不抛出（`Logger.php:93-95`） |
| S-7 | `Hash::make($v, 31)` 可被用于拒绝服务 | 尝试执行 | **无法完整验证**（单次耗时 >30s，复现脚本被迫放弃该用例）。静态确认 `password_hash` 接受 cost=31 且 `Hash` 无上限校验，风险路径成立但未取得完整运行证据，已并入 M-11 说明 |



---

# 四、已验证正确的重要逻辑（交叉核对用）

### EventDispatcher
- **通配符 `[^.]+` 语义正确**：`a.*` 命中 `a.b`，不命中 `a.b.c`（Laravel 同款语义）
- **`wildcardCache` 1024 阈值清理后仍能正常派发**：连续派发 1100 个 `z.N` 后 `z.2000` 仍命中
- **`forget()` 同时清空 `wildcardCache` 与 `wildcardRegexCache`**：`forget('k.*')` 后 `hasListeners('k.a')` 正确变 `false`，`dispatch('k.a')` 返回 `[]`
- **同 pattern 内优先级生效**：`listen('e',low,0); listen('e',high,10)` → `["high","low"]`
- **同优先级保持注册顺序**（PHP 8 `usort` 稳定性）：三次 `listen(...,5)` → `["first","second","third"]`
- **`getSubscribedEvents()` 多方法+优先级解析正确**：`['x.y'=>[['m1',10],['m2',-5]]]` → `["M1","M2"]`
- **递归派发检测生效**：同事件递归派发触发 `E_USER_WARNING` 并返回空结果，未栈溢出
- **通配符嵌套派发正常**：`a.*` 监听器内 dispatch `a.c` 不误判为递归
- **`dispatch()` 与 `until()` 共享同一递归栈**，互不绕过

### Hash
- **AES-256-GCM 认证完整**：错误密钥→`null`；篡改密文→`null`；非 base64→`null`；长度不足→`null`；`encrypt('')` 往返得 `''`
- **IV(12B)+tag(16B) 的位置切分正确**，`aes-256-gcm` 用法无误
- **密钥派生固定**：`sha256` 原始 32 字节，短 key（如 `'k'`）也能正确往返
- **`setApplicationKey('')` 不阻断启动**，真正加解密时才抛异常（设计正确，已有测试覆盖）

### Session
- **`ageFlash()` 两轮老化逻辑正确**：连续 `flashSet` 同 key 时新值不被旧值清理逻辑误删（实测 v1→v2 正常）
- **session cookie 参数正确**：`httponly=true` / `samesite=Lax`；`session.use_strict_mode=1` 在 `session_start()` **之前**设置，防 session fixation 生效
- **`secure` 已走 `Request::isSecureFromServer()`**（`Session.php:18`），尊重可信代理——这是 `Cookie` 尚未跟进的地方（见 L-5）
- **`token()` 跨调用稳定**（64 hex），`regenerate()` 正常轮换 session id
- **`has()` 用 `array_key_exists`**，null 值不会丢失（`get('nullkey','D')` 正确返回 `'D'`）
- **`flush()` 确实清空数据**（`a` → `'GONE'`）

### Logger
- **级别过滤正确**：默认 `info`，`debug()` 被丢弃；`setLevel('debug')` 后写入
- **`clear()` 日期格式有正则防护**：`clear('../x')` 抛 `InvalidArgumentException`（路径穿越被阻断）
- **`json_encode` 失败不会 TypeError**：`JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR` + `is_string()` 兜底（`:72-81`）修复到位
- **上下文分类正确**：数组→`{"__type":"array","__count":N}`，对象→类名，已插值的标量不再重复输出
- **换行清洗正确**：`\r\n`/`\r`/`\n` 统一替换为空格，日志不串行
- **写入用 `FILE_APPEND | LOCK_EX`**，并发追加有文件锁保护

### Env
- **引号解析正确**：`export KEY=value`、双引号内 `\"` 转义、单引号内 `#`、`a#b`（无空格不截断）
- **`set()` 类型化正确**：`Env::set('X',true)` → `get()` 得 `bool true`，`putenv` 得字符串 `'true'`
- **`load()` 优先级正确**：系统环境变量已存在时 `.env` 不覆盖（`:84`）
- **`load()` 一次性守卫生效**：已 `$loaded` 后二次 `load()` 静默跳过（设计取舍）
- **`' #'` 截断行为符合 dotenv 惯例**：`PASS=my secret #notcomment` → `'my secret'`（截断是预期行为，非缺陷）

### Config
- **`cache()` / `loadCached()` 往返正确**：嵌套数组（含对象 `var_export` 成 `(object) array()`）均能还原
- **`cache()` 走 `.tmp` + `rename()` 原子替换**，Windows 上重复执行成功
- **`loadCached()` 合并方向正确**：`array_replace_recursive($cached, self::$items)` 保证运行时已设置项优先
- **`cache()` 生成的守卫有效**：`Application::loadConfig()` 先定义 `LIGHTPHP_CONFIG_CACHE` 再 require，避免了执行 `config:cache` 后全站 403 的陷阱
- **`set()` 的写路径有 `is_array()` 防护**（`:36`）——与 `get()` 缺防护形成对比（M-6）

### Generator
- **表名/类名注入防护到位**：`a\`b`、`a-b`、`9Bad`、`..\..\evil` 全部被 `InvalidArgumentException` 拦截
- **`saveModel`/`saveController` 的路径穿越被上层正则挡住**（`..\..\evil` → `Invalid model name`）
- **命名推导合理**：`user_profiles` → `UserProfiles` / `UserProfilesController` → 路由 `/user-profiles`
- **`generateModel()` 在 DB 不可达时仍产出语法合法的 Model 骨架**（`$fillable=[]`，不崩溃）
- **`getTables()`/`getTableColumns()` 对 sqlite 静默返回 `[]`**（`SHOW TABLES` 是 MySQL 语法）——属已知限制，非缺陷
- **`generateUpdateValidation()` 的 `str_replace('required','optional')` 在当前规则集下无误伤**（实测 `required|numeric` → `optional|numeric`，规则由框架自身生成）

### Connection
- **嵌套事务正确**：内层 `rollback()` 仅回滚到 savepoint，外层数据保留（实测 A 保留、B 消失）
- **层级失衡安全**：无事务时 `commit()`/`rollback()` 返回 `false` 而非崩溃；3 层 commit 逐级递减至 `inTransaction()===false`
- **全回滚后 `transactionLevel` 正确复位**，可再次 `beginTransaction()`
- **参数校验齐全**：host/port/database/charset/sqlite 路径均有正则与范围检查
- **`beginTransaction()` 用 `exec()` 而非 `return $result`**——避免了 SAVEPOINT 返回 int 触发 `bool` 返回类型 TypeError
- **`table()`/`query()`/`execute()` 返回类型契约一致**，无类型违反

### Collection（数组元素场景）
- **`map()` 保键正确**：`array_combine($keys, $values)` 保持原键，空集合不报错
- **`filter()` 用 `ARRAY_FILTER_USE_BOTH`**，回调同时收到 value 与 key
- **`nth()` 抛 `InvalidArgumentException` 当 `nth < 1`**，边界有防护
- **`keyBy`/`pluck` 对缺键元素用合成键兜底**，不抛 undefined index
- **`sortBy()` 的三种模式（SORT_REGULAR/STRING/NUMERIC）均正确**
- **in-place 与新实例语义一致**：除 `pull`/`forget`/`tap`/`each`（返回 `$this`）外全部返回 `new static`；实测 `forget()` 返回 `===` 原对象且原对象被修改（符合其文档定位）

### Seeder
- **注册去重正确**：重复 `register(S1)` 不产生重复项
- **`call()` 的类型校验有效**：`stdClass` 与不存在的类均抛 `InvalidArgumentException`（`is_subclass_of` 在 `new` 之前，不会二次失败）
- **`register()` 按 `static::class` 分桶是设计选择**：实测 `Root::getSeeders()` 有值而 `S1::getSeeders()` 为空，属预期的命名空间隔离而非缺陷

### Console
- **`table()` 宽度计算正确**：各列独立取最大宽度，分隔线对齐（已用长短不一的单元格验证）
- **未知命令返回码 1**，输出含 ANSI 颜色
- **`--version` / `-V` / `--help` / `-h` 分支正确**，返回 0
- **`parseInput()` 可重复调用**（`arguments`/`options` 在开头被重置为 `[]`）
- **默认值解析正确**：`{x=a=b}` → `'a=b'`；`{x=-5}` → `'-5'`（`explode('=', ..., 2)` 限制为 2 段）
- **含点命令名可注册**：`a.b.c` 正常派发，返回码 7
- **`php bin/console list` 退出码 0**，表格对齐正确

---

# 五、修复优先级建议

| 优先级 | 条目 | 理由 |
|---|---|---|
| P0 | H-4（优先级不跨 pattern） | 框架核心特性失效，且行为依赖注册顺序，极难排查 |
| P0 | H-1、H-2（参数解析负数/贪婪吞参） | 直接导致 `migrate:rollback -1` 语义错误；两处可一并重构 `parseInput` 的分派逻辑 |
| P1 | H-3（缺参 fatal 255） | 4 个内置命令受影响，加 try/catch + 必填校验即可一次性解决 |
| P1 | M-3（avg 分母）、M-6（Config 标量 TypeError） | 静默错误结果 / 未捕获 TypeError，改动小收益高 |
| P2 | M-1～M-12 | 均为边界与语义问题，不影响主流程但会持续制造困惑 |
| P3 | L-1～L-12 | 多为 API 陷阱与文档-实现不一致 |

**建议合并修复的三组**：
1. **输入解析层**（H-1 + H-2 + M-1 + M-2 + H-3）：同属 `Command`/`Console`，建议统一重构——收集声明的选项名集合 → 区分开关/取值选项 → 数值型 `-` 前缀归入位置参数 → 回填 option 默认值 → 补必填校验 → `Console::run()` 加 try/catch。
2. **Config 读路径**（M-6 + M-7）：都是 `Config` 的输入健壮性，共用 `rtrim` / `is_array` 两个小改动。
3. **`Cookie` 补齐 `Session` 已做的修复**（L-5）：一行改动即可闭环 CHANGELOG 中已声明的行为一致性。

---

## 附：复现脚本清单（位于 `%TEMP%\lp_audit\`，仓库内未创建任何文件）

| 脚本 | 覆盖范围 |
|---|---|
| `bootstrap.php` | 框架引导 + `section()`/`t()` 断言辅助 |
| `repro_collection.php` | C1–C10：Collection 全量边界 |
| `repro_events.php` | E1–E9：EventDispatcher 优先级/通配符/递归/缓存 |
| `repro_events2.php` | E4b/E5b/E10–E14：subscribeClass 反射与异常吞噬 |
| `repro_console.php` | K1–K13：Command 解析 + Console 调度 + table() |
| `repro_state.php` | S1–S7 / H1–H4 / E1x–E5x：Session / Hash / Env |
| `repro_log_config.php` | L1–L6 日志、CF1 Config 标量 |
| `repro_db.php` | CF2/CF3、SD1 Seeder、CN1–CN4 Connection 事务 |
| `repro_cookie.php` | CK1–CK6：Cookie 往返/secure/回写 |
| `repro_generator.php` | G1–G7：Generator 延迟连接/校验/路径 |
| `repro_final.php` | V1–V8：跨模块交叉确认 |
| `t_config_load.php`、`t_config_glob.php`、`t_glob.php` | Config::load 尾斜杠与重复 require 专项 |
| `t_session2.php` | Session flash 二义 / 内部键泄漏 / token |
| `t_gen_path.php`、`t_hash.php`、`t_sess.php` | Generator 路径穿越、bcrypt 边界、Session 基线 |

所有 `[FAIL]` 行均为**真实抛出的异常**（非模拟），`[OK]` 行为真实返回值。涉及 `Generator::saveModel`/`generateAll` 等会写入仓库的用例已改用非法入参提前阻断，`git status --short` 确认仓库零污染。

===== L3. 不可编码上下文 INF/NAN/资源 =====
[OK]   行 => '[2026-10-05 03:58:56] INFO: vals INF {"b":0,"c":null}'
```

**根因**：`json_encode(..., JSON_PARTIAL_OUTPUT_ON_ERROR)`（`:72`）把 `NAN`/`INF` 替换为 `0`、资源替换为 `null`，不报错。日志里的数值**与真实值不符**且无任何标记。插值路径的 `'a' => INF` 反而正确渲染成字符串 `INF`（`:213` 的 `(string) INF === 'INF'`），两条路径行为不一致。

**建议修复**：编码前检测 `is_finite()`，非有限值替换为字符串 `'INF'`/`'NAN'` 而非数字 `0`。

**级别**：LOW（会误导排障）

