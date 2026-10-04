# 第三轮审计：上一轮 35 项修复的回审报告

- **审计对象**：commit `99ee1e0`（"全面代码审计，修复 35 项安全/正确性缺陷并补充 92 条回归测试"）
- **基线**：`f2dd2a2` (HEAD~1)，通过 `git worktree add $TEMP/lp_prev HEAD~1` 建立对照环境
- **环境**：PHP 8.4.7 (cli) + pdo_sqlite（SQLite 3.46.0）
- **现有测试**：`php tests/run_tests.php` → **1060/1060 passed**（修复前后均全绿，说明现有测试未覆盖下列回归）
- **复现脚本位置**：`%TEMP%\lp_audit3\*.php`（仓库外，未污染工作区）

> 说明：`bin/console test` 在本机因 `passthru()` 被禁用而 Fatal（`bin/console:418`），与本轮修复无关，请直接用 `php tests/run_tests.php`。
> 所有对照实验均通过 `LP_ROOT` 环境变量切换 `APP_PATH` 根目录，在同一份脚本上分别跑 HEAD 与 HEAD~1，输出逐条对比。

---

## 一、已验证缺陷

### [严重] R-01 `View::include()` 关闭 autoEscape 导致 XSS —— 上一轮修复引入的安全回归

- **文件**：`app/view/View.php:275`
- **问题代码**：
  ```php
  public function include(string $view, array $data = []): void
  {
      ...
      try {
          // 关键：临时关闭自动转义，让 render() 统一转义一次，
          // 避免父视图已转义的数据被子视图二次转义
          $this->autoEscape = false;
          echo $this->render($view, $data);
  ```
- **根因**：上一轮为修「`&lt;b&gt;` 双重编码」而加了 `$this->autoEscape = false;`。双重编码确实是 bug，但**修复手段选错了**——它没有消除重复转义，而是把子模板这一次渲染的**全部转义都关掉了**，包括 `sharedData` 与 `composer()` 注入的数据。`render()` 内 `if ($this->autoEscape) { $data = $this->escapeArray($data); }` 是唯一的转义入口，关掉它等于对子模板关闭 XSS 防护。
- **复现脚本**（`r16_view_xss.php`）：
  ```php
  $dir = sys_get_temp_dir() . '/lp_view_tpl2';
  file_put_contents("$dir/child.php", '<?= $note ?>');
  file_put_contents("$dir/parent.php", '<?php $__view->include("child", []); ?>');
  $payload = '<script>alert(document.cookie)</script>';
  $v = new \view\View($dir);
  $v->composer('child', function($vv) use ($payload) { $vv->share('note', $payload); });
  echo $v->render('parent');
  ```
- **真实输出**：
  ```
  === CURRENT (HEAD) ===
  === XSS via composer() data reaching include()'d child ===
    OUT: <script>alert(document.cookie)</script>
    ==> VULNERABLE (raw HTML emitted)
  === XSS via share() before include ===
    OUT: <script>alert(document.cookie)</script>
    ==> VULNERABLE
  === Control: same child rendered directly with autoEscape ON ===
    OUT: &lt;script&gt;alert(document.cookie)&lt;/script&gt;
    ==> safe (escaped)

  === PREVIOUS (HEAD~1) ===
  === XSS via composer() data reaching include()'d child ===
    OUT: &lt;script&gt;alert(document.cookie)&lt;/script&gt;
    ==> safe (escaped)
  === XSS via share() before include ===
    OUT: &lt;script&gt;alert(document.cookie)&lt;/script&gt;
    ==> safe (escaped)
  ```
  HEAD~1 安全（虽有双重编码），HEAD 反而可注入 —— **这是一处安全能力净损失**。
- **修复建议**：删除 `$this->autoEscape = false;`，改为在 `include()` 内对**本次传入的 `$data`** 单独做一次 `escapeArray()`，`sharedData` 不再重复转义（父视图渲染时已转义过）：
  ```php
  try {
      $data = $this->autoEscape ? $this->escapeArray($data) : $data;
      echo $this->render($view, $data);
  ```
  同时保留 `finally` 中的 `$this->autoEscape = $prevAutoEscape;`。另需回归验证 composer 注入数据的转义（见 S-04）。

---
### [严重] R-02 `Model` 全面 static 化摧毁 `SoftDelete` 的 `withTrashed()/onlyTrashed()` 语义

- **文件**：`app/model/Model.php:57,73,84,136,143,148,261` 与 `app/traits/SoftDelete.php:85,95,105`
- **问题代码**：
  ```php
  // Model.php:57 —— static 方法内部一律 new static()
  public static function find(int|string $id): ?static
  {
      $instance = new static();                       // ← 丢弃调用者实例上的状态
      $row = $instance->newQuery()->where(...)->fetch();
  ```
  ```php
  // SoftDelete.php:105 —— newQuery() 依赖实例属性 $trashedQuery
  protected function newQuery(): QueryBuilder
  {
      $query = $this->db();
      if ($this->trashedQuery === 'exclude') { $query->whereNull('deleted_at'); }
      elseif ($this->trashedQuery === 'only')  { $query->whereNotNull('deleted_at'); }
      return $query;
  }
  ```
- **根因**：`withTrashed()` 返回的是一个**携带 `trashedQuery='with'` 的模型实例**。上一轮把 `find/first/all/where/select/paginate` 改成 `public static`，PHP 允许 `$instance->find(...)` 调用静态方法，但方法体里的 `new static()` 会**新建一个 `trashedQuery='exclude'` 的默认实例**，`withTrashed()` 设置的查询状态被完全丢弃。结果：`withTrashed()` 在所有 static 入口上退化为默认「排除已删除」，**静默返回错误数据集**——不报错、不告警，只是数据少了。这是本轮最危险的回归：软删除模型的所有查询入口同时失效。
- **复现脚本**（`r01_softdelete.php`）：
  ```php
  class SDPost extends \model\Model {
      protected string $table = 'posts';
      protected array $fillable = ['title'];
      use \traits\SoftDelete;
  }
  $db->getPdo()->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, deleted_at TEXT NULL, created_at TEXT NULL, updated_at TEXT NULL)');
  SDPost::create(['title'=>'A']); SDPost::create(['title'=>'B']); SDPost::create(['title'=>'C']);
  SDPost::find(2)->delete();                       // 软删 id=2
  echo count(SDPost::all());                        // 期望 2
  echo count(SDPost::withTrashed()->fetchAll());    // 期望 3
  echo count(SDPost::onlyTrashed()->fetchAll());    // 期望 1
  var_dump(SDPost::withTrashed()->find(2));         // 期望 SDPost 实例
  echo count(SDPost::withTrashed()->all());         // 期望 3
  echo json_encode(SDPost::withTrashed()->where("title","B")->fetch());
  echo count(SDPost::onlyTrashed()->all());         // 期望 1
  ```
- **真实输出**：
  ```
  === CURRENT (HEAD) ===
  default all()         = 2   (expect 2)
  withTrashed fetchAll  = 3   (expect 3)      ← 只有 __call 代理的 fetchAll 还正常
  onlyTrashed fetchAll  = 1   (expect 1)

  -- withTrashed()->find(2)
     => null                            ← 应为 SDPost
  -- withTrashed()->all()
     => [{},{}]                          ← 2 条，应为 3 条
  -- withTrashed()->where(title,B)->fetch()
     => null                            ← 应为 id=2 的行
  -- onlyTrashed()->where(title,B)->fetch()
     => null                            ← 应为 id=2 的行
  -- onlyTrashed()->all()
     => [{},{}]                          ← 2 条，应为 1 条
  -- withTrashed()->paginate(10,1)
     => {"items":[{},{}],"total":2,...}  ← total 应为 3
  ```
  ```
  === PREVIOUS (HEAD~1) ===
  default all()         = 2   (expect 2)
  withTrashed fetchAll  = 3   (expect 3)
  onlyTrashed fetchAll  = 1   (expect 1)

  -- withTrashed()->find(2)
     => SDPost
  -- withTrashed()->all()
     => [{},{},{}]
  -- withTrashed()->where(title,B)->fetch()
     => {"id":2,"title":"B","deleted_at":"2026-10-05 03:07:05",...}
  -- onlyTrashed()->where(title,B)->fetch()
     => {"id":2,"title":"B","deleted_at":"2026-10-05 03:07:05",...}
  -- onlyTrashed()->all()
     => [{}]
  ```
  **HEAD~1 全部正确，HEAD 全部错误。**
- **补充说明**：`docs/api.md:2207`、`docs/guide.md:861`、`docs/quick-start.md:552` 明确文档化了 `Post::withTrashed()->fetchAll()` 用法；`fetchAll()` 恰好因为走 `__call` 代理而侥幸正确，但 `find/all/where/paginate` 全部失效。
- **修复建议**：
  1. **推荐（最小改动）**：把 `withTrashed()/onlyTrashed()` 改为返回 `QueryBuilder` 而非模型实例，并在其中直接带上软删条件：
     ```php
     public static function withTrashed(): QueryBuilder { return (new static())->db(); }
     public static function onlyTrashed(): QueryBuilder { return (new static())->db()->whereNotNull('deleted_at'); }
     ```
     此时 `Post::withTrashed()->where(...)->fetchAll()` 语义正确，且不与 static 化冲突。
  2. 「给 static 方法加可选实例入口」不可行——PHP 无法区分 `$obj->find()` 是静态调用还是实例调用，**方案 1 是唯一干净解**。
  3. 必须补测试：`withTrashed()->find()/all()/where()/paginate()/first()` 与 `onlyTrashed()` 的对称用例。

---

### [高] R-03 Blade `@include` 作用域恢复用 `isset()`，父变量为 `null` 时恢复失效并污染父作用域

- **文件**：`app/view/Blade.php:466-472`
- **问题代码**：
  ```php
  . '$__incPrev_' . $suffix . ' = []; '
  . 'foreach ($__incKeys_' . $suffix . ' as $__incK' . $suffix . ') { '
  . 'if (isset($$__incK' . $suffix . ')) { $__incPrev_' . $suffix . '[$__incK' . $suffix . '] = $$__incK' . $suffix . '; } '
  . '} '
  . 'extract(' . $vars . ', EXTR_OVERWRITE); '
  . 'require $__inc_' . $suffix . '; '
  . 'foreach ($__incPrev_' . $suffix . ' as $__incK' . $suffix . ' => $__incV' . $suffix . ') { $$__incK' . $suffix . ' = $__incV' . $suffix . '; } '
  ```
- **根因**：上一轮为修「`EXTR_SKIP` 使 `@include` 传值无法覆盖父变量」而改用 `EXTR_OVERWRITE` + 保存/恢复父作用域，方向正确。但保存前用 `isset($$k)` 判定变量是否存在，**`isset()` 对值为 `null` 的变量返回 `false`**。父作用域中 `$v = null` 是极常见的写法（未初始化/可选参数默认值），此时该变量不被存入 `$__incPrev_`，include 结束后自然也不会被恢复 → 子模板的值泄漏进父作用域，污染后续模板代码。此外，`@include` 引入的**全新变量**（父作用域原本没有的）也从不清理，一并泄漏。
- **复现脚本**（`r32_blade_iso.php`，每次运行使用独立模板/缓存目录，避免编译缓存串味）：
  ```php
  file_put_contents("$tpl/row.blade.php",   "[{{ \$v }}]");
  file_put_contents("$tpl/main.blade.php", "<?php \$v = null; ?>{@include('row',['v'=>'NEW'])}|AFTER=<?= var_export(\$v,true) ?>");
  echo (new \view\Blade($tpl, $cache))->render('main');
  ```
- **真实输出**：
  ```
  === CURRENT (HEAD) ===
  {[NEW]}|AFTER='NEW'          ← 期望 AFTER=NULL
  === PREVIOUS (HEAD~1) ===
  {[]}|AFTER=NULL
  ```
  HEAD~1 虽有「覆盖无效」问题但**不污染父作用域**；HEAD 引入了新的作用域泄漏。
  补充实测（`r05_blade.php`）：父变量为 `'PARENT'`（非 null）时 HEAD 恢复正确（`AFTER=[PARENT]`），说明缺陷**只在 null 时触发**，容易被漏测。
- **修复建议**：用 `array_key_exists` 语义替代 `isset`，并额外记录「原本不存在」的键以便 `unset`：
  ```php
  . 'foreach ($__incKeys_N as $__incKN) { '
  . 'if (array_key_exists($__incKN, get_defined_vars())) { $__incPrev_N[$__incKN] = $$__incKN; } else { $__incNew_N[$__incKN] = true; } '
  . '} '
  ...
  . 'foreach ($__incPrev_N as $__incKN => $__incVN) { $$__incKN = $__incVN; } '
  . 'foreach (($__incNew_N ?? []) as $__incKN) { unset($$__incKN); } '
  ```

---

### [高] R-04 `Model` static 化是破坏性 BC 变更：子类以实例方法覆盖 `create()` 会 Fatal

- **文件**：`app/model/Model.php:57-166`（find/findBy/first/firstOrCreate/firstOrNew/all/select/where/create/update/paginate 全部转 static）
- **问题代码**：`public static function create(array $data): int|string`（Model.php:158）等
- **根因**：PHP 禁止在子类中把父类的 static 方法改回实例方法，也禁止反之——这是**编译期 Fatal Error，不是可捕获异常**。任何已存在的业务模型只要写了 `public function create(array $data)`（Laravel 风格的自定义创建钩子非常常见），升级到本版本后整个应用直接启动失败。
- **复现脚本**（`r17_model.php`）：
  ```php
  eval('class U2 extends \model\Model {
      protected string $table = "u"; protected array $fillable = ["name"];
      public function create(array $d) { return 999; }   // Laravel 风格钩子
  }');
  U2::create(['name' => 'z']);
  ```
- **真实输出**：
  ```
  === D. subclass overriding create() as NON-static (BC break) ===
  Fatal error: Cannot make static method model\Model::create() non static in class U2
    in ...\r17_model.php(35) : eval()'d code on line 1
  ```
  （该 Fatal 在类声明时即触发，无法用 try/catch 兜底，整个请求 500 且白屏。）
- **修复建议**：
  1. 短期：在 CHANGELOG / 升级说明中显式列为 **BREAKING CHANGE**，并给出迁移指引（子类 `create()` 改名为 `persistCreate()` 覆写，或改用 `static function create()`）。
  2. 长期：框架已完成逻辑拆分（`persistCreate`/`persistUpdate`），可让 static 门面仅作薄封装；不要试图同时支持两种调用形态。
  3. **R-02 必须先修**——在 static 化未与 SoftDelete 打通之前，这次 BC 变更的收益为负。

---

### [中] R-05 `QueryBuilder::aggregate()` 有 GROUP BY 时保留 HAVING，但 HAVING 绑定恒为字符串导致数值比较恒为 false

- **文件**：`app/db/QueryBuilder.php:837-840`（`if (!$hasGroup) { ... clearHavingBindings(); }`）、`419`（`$this->bindings[$placeholder] = $value;`）、`844`（`$stmt->execute($clone->bindings);`）
- **问题代码**：
  ```php
  // aggregate()：上一轮改为「有 groupBy 时不清 having」
  $clone->orderBy = '';
  // 注意：不再清空 groupBy
  if (!$hasGroup) {
      $clone->having = [];
      $clone->clearHavingBindings();
  }
  ```
  ```php
  // having()：值直接塞进 bindings，未记录类型
  $placeholder = ':h_' . count($this->bindings);
  $this->bindings[$placeholder] = $value;   // int 5 也存成 5，但 execute(array) 一律按 PARAM_STR 绑
  ```
- **根因**：上一轮修「`->groupBy('g')->sum('v')` 静默返回全表聚合值」时保留了 HAVING（方向正确），但**没有发现底层 `execute(array)` 把所有绑定值都按 `PDO::PARAM_STR` 下发**。SQLite 在 `SUM(v) > '5'` 中按类型优先级比较，TEXT 恒大于 INTEGER，因此 `13 > '5'` 求值为 `0` —— 任何带数值 HAVING 的聚合查询**恒返回空数组**。HEAD~1 因为无条件清空 HAVING 反而「因祸得福」不暴露此 bug；本次修复把它暴露出来了。
- **复现脚本**（`r33_having_root.php`）：
  ```php
  $db->table('t')->insert(['g'=>'a','v'=>-5]); ... // a:-5 a:0 b:10 b:3 c:0
  echo json_encode($db->table('t')->groupBy('g')->having('__sum','>',5)->sum('v'));  // 期望 b=>13
  ```
- **真实输出**：
  ```
  === ROOT CAUSE: execute(array) binds everything as PDO::PARAM_STR ===
    execute([':h_0'=>5])  => []                                <- QueryBuilder 走的路径
    bindValue(INT)       => [{"g":"b","__sum":13}]              <- 正确
  === SQLite type-affinity demo: INTEGER vs TEXT compare ===
  int(0)     # SELECT 13 > '5'
  int(1)     # SELECT CAST(13 AS INTEGER) > 5
  === aggregate()+having() 恒空 ===
    groupBy(g)->having('v','>',5)->sum('v')    = [{"__sum":13,"g":"b"}]   ← 列名比较碰巧正常
    groupBy(g)->having('__sum','>',5)->sum('v') = []                        ← 别名比较恒空（期望 b=>13）
  ```
  对照 HEAD~1（`r09_having.php`）：同一查询返回 `8`（全表和），虽错但非空；HEAD 返回 `[]`。
- **修复建议**：绑定时携带类型，`execute()` 前用 `bindValue` 以正确 `PDO::PARAM_*` 下发：
  ```php
  foreach ($this->bindings as $k => $v) {
      if (is_int($v))       { $stmt->bindValue($k, $v, PDO::PARAM_INT); }
      elseif (is_bool($v))  { $stmt->bindValue($k, $v, PDO::PARAM_BOOL); }
      elseif ($v === null)  { $stmt->bindValue($k, null, PDO::PARAM_NULL); }
      else                  { $stmt->bindValue($k, $v, PDO::PARAM_STR); }
  }
  $stmt->execute();
  ```
  建议统一封装为 `executeStatement(PDOStatement $stmt, array $bindings): bool`，供 `fetch/fetchAll/aggregate/count/insert/update` 共用。

---

### [中] R-06 `max()`/`min()` 用 `?:` 吞掉合法的 `0` 结果

- **文件**：`app/db/QueryBuilder.php:873-882`
- **问题代码**：
  ```php
  public function max(string $column): mixed
  {
      $result = $this->aggregate('MAX', $column, '__max');
      return is_array($result) ? ($result[0]['__max'] ?? null) : ($result ?: null);   // ← ?:
  }
  public function min(string $column): mixed
  {
      $result = $this->aggregate('MIN', $column, '__min');
      return is_array($result) ? ($result[0]['__min'] ?? null) : ($result ?: null);   // ← ?:
  }
  ```
- **根因**：改写为共用 `aggregate()` 时，收尾用了 `?: null` 而非 `?? null`。`?:` 对**任何 falsy 值**（`0`、`0.0`、`'0'`、`''`）都返回右值，于是「最大值恰好是 0」「最小值恰好是 0」这类完全合法的业务场景被误判为「无数据」。金额、库存、计数场景下 0 是最常见的值之一。
- **复现脚本**（`r09_having.php` / `r08_agg.php`）：
  ```php
  foreach ([['a',-5],['a',0],['b',10],['b',3],['c',0]] as [$g,$v]) { $db->table('t')->insert(['g'=>$g,'v'=>$v]); }
  var_dump($db->table('t')->where('g','a')->max('v'));  // 组 a 为 [-5, 0]，max = 0
  var_dump($db->table('t')->where('g','c')->min('v'));  // 组 c 为 [0]，min = 0
  ```
- **真实输出**：
  ```
  === CURRENT (HEAD) ===
    max(v) g=a = NULL  (expect 0)
    min(v) g=c = NULL  (expect 0)
    max(v) empty table = NULL
    min(v) all = -5.0  (expect -5)
    max(v) all = 10.0  (expect 10)

  === PREVIOUS (HEAD~1) ===
    max(v) g=a = 0     (expect 0)     ← 正确
    min(v) g=c = 0     (expect 0)     ← 正确
    max(v) empty table = NULL
    min(v) all = -5    (expect -5)
    max(v) all = 10    (expect 10)
  ```
- **修复建议**：区分「无行」与「值为 0」。让 `aggregate()` 在无行时返回明确的 `null`，再用 `??`：
  ```php
  public function max(string $column): mixed
  {
      $result = $this->aggregate('MAX', $column, '__max');
      if (is_array($result)) { return $result[0]['__max'] ?? null; }
      return $result;   // aggregate() 已在无行时返回 null
  }
  ```
  并把 `aggregate()` 末尾 `return is_array($row) ? ($row[$alias] ?? 0) : 0;` 改为 `?? null`（注意 `sum()` 的 `: float` 返回类型需相应放宽或补 `?? 0.0`）。
  附带观察：`max/min` 现在返回 **float**（`10.0`），HEAD~1 返回 **int**（`10`），属可见的类型变化，虽被 `mixed` 掩盖，仍建议在 CHANGELOG 标注。

---

### [中] R-07 `Application::run()` 的输出缓冲使 `Response::download()` 退化为全量内存物化

- **文件**：`app/core/Application.php:237-238`、`285-288`；`app/core/Response.php:220-225`
- **问题代码**：
  ```php
  public function run(): void
  {
      ob_start();                      // ← 全站响应都被缓冲
      $ownObLevel = ob_get_level();
      ...
      // 正常路径：把缓冲内容一次性输出
      while (ob_get_level() >= $ownObLevel) { ob_end_flush(); }
  }
  ```
  ```php
  // Response::send()
  if ($this->filePath !== null) { readfile($this->filePath); }   // ← 写进上面的缓冲
  ```
- **根因**：上一轮加 `ob_start()` 的目的是「异常时丢弃半截内容、保证 500 状态码生效」，出发点正确。但它**无条件包裹整个响应生命周期**，而 `Response::send()` 的下载分支用 `readfile()` 做流式输出——`readfile()` 的字节现在全部落进 `run()` 的缓冲区，直到请求末尾才一次性 flush。上一轮特意为 `download()` 做的「流式输出、避免大文件全量读入内存」（Response.php:122 注释）被彻底抵消：峰值内存 ≈ 文件大小，且**客户端在下载开始前一个字节都收不到**，大文件下还可能直接撞 `memory_limit` 500。
- **复现脚本**（`r34_dl.php`）：
  ```php
  $big = sys_get_temp_dir().'/lp_big2.bin';
  if (!file_exists($big)) file_put_contents($big, str_repeat('B', 16*1024*1024));
  $resp = \core\Response::download($big, 'x.bin');
  ob_start(); $ownObLevel = ob_get_level();      // 复刻 run() 的契约
  $resp->send();                                  // readfile() 写进我们的缓冲
  $content = ob_get_clean();
  printf("file=%.1fMB buffered_in_memory=%.1fMB peak=%.1fMB Content-Length=%s\n", ...);
  ```
- **真实输出**：
  ```
  file=16.0MB  buffered_in_memory=16.0MB  peak=38.0MB  Content-Length header=16777216
  NOTE: response was fully materialized before any byte reached the client.
  ```
  （8MB 文件的独立复现 `r10_ob.php` 同样得到 `mem peak = 12.00 MB`，即 2MB 基线 + 8MB 缓冲 + 2MB 开销，线性增长可确认。）
  对照：HEAD~1 的 `run()` **完全没有 `ob_start()`**（`git show HEAD~1:app/core/Application.php` 中仅 `handleException()` 的错误页渲染处有 `ob_start()`），下载是真流式。
- **修复建议**：把「异常清理」与「正常输出」解耦——**不要缓冲成功路径**，或对下载/流式响应显式豁免：
  ```php
  // 方案 A（推荐）：流式响应不走缓冲
  $result = $this->router->dispatch();
  if ($result instanceof \core\Response && $result->isStreaming()) {
      $result->send();
      return;
  }
  ```
  方案 B：在 `Response` 上增加 `isStreaming()`（`filePath !== null` 或带 `Content-Disposition` 即为真），`run()` 据此决定是否 `ob_start()`。
  无论哪种方案，都必须保留「异常时 `while (ob_get_level() >= $ownObLevel) ob_end_clean();`」这一正确语义，并补一条「下载响应不落缓冲」的回归测试（断言 `ob_get_level()` 在 `send()` 前后不变）。

---

### [中] R-08 SQLite 索引名 `idx_{col}` 不带表前缀，两表同名列建索引必冲突

- **文件**：`app/db/Schema.php:72`、`124`；`app/db/Schema.php:488-491`（`Blueprint::index()`）
- **问题代码**：
  ```php
  // Blueprint::index()
  $this->indexes[] = "idx_{$col}";     // ← 只有列名，没有表名前缀
  $this->indexColumns[] = $col;
  ...
  // Schema::create() / Schema::table()
  "CREATE INDEX `{$index['name']}` ON `{$this->table}` (`{$index['columns']}`)"
  ```
- **根因**：上一轮为 SQLite 补上「建表后单独 CREATE INDEX」的能力时，索引名沿用了 MySQL 分支既有的 `idx_{col}` 命名。**MySQL 中索引名只需表内唯一，SQLite 中索引名是全库唯一的**。于是任何两张表含有同名列并各自建索引，第二张就报 `index idx_xxx already exists`。这极其常见（多个表都有 `email`、`created_at`、`user_id`），意味着 SQLite（开发/测试环境默认驱动）下迁移几乎无法编写。
- **复现脚本**（`r13_idx.php`）：
  ```php
  $sc->create('users',  function($t) { $t->id(); $t->string('email')->index(); });
  $sc->create('orders', function($t) { $t->id(); $t->string('email')->index(); });
  ```
- **真实输出**：
  ```
  === SQLite index names are DB-global; framework uses idx_{col} w/o table prefix ===
  users created OK
  THREW core\exception\DatabaseException: Schema operation failed:
        SQLSTATE[HY000]: General error: 1 index idx_email already exists
        | SQL: CREATE INDEX `idx_email` ON `orders` (`email`)

  -- same for ALTER path --
  p1,p2 created
  p1 idx ok
  p2 THREW: ... 1 index idx_email2 already exists | SQL: CREATE INDEX `idx_email2` ...
  ```
  对照 HEAD~1（`r11_schema.php`），SQLite 下所有含 `index()` 的建表/改表都因 `KEY` 子句语法错误而失败，**根本走不到命名冲突这一步**：
  ```
  === D. CREATE TABLE with unique + index ===   (HEAD~1)
  THREW ... near "KEY": syntax error
  === CURRENT (HEAD) ===
  bool(true)                                       ← 已修复
  ```
  即：本次修复**解决了语法错误，却引入了命名冲突**——修复不彻底。
- **修复建议**：索引名加上表前缀，SQLite 分支使用全库唯一命名：
  ```php
  // Blueprint::index() —— SQLite 分支
  $this->indexes[] = "idx_{$this->table}_{$col}";
  ```
  MySQL 分支可保留 `idx_{col}`（表内唯一即可），或统一加表前缀以保持跨驱动 SQL 一致、便于迁移比对。

---
---

## 二、疑似（未验证，仅静态推测）

> 以下条目**未附运行输出**，或仅在受限条件下观察，标注为「疑似」，请勿直接据此改代码。

- **疑似 S-01 `Router::normalizeUri()` 对已注册路由模式不做同样规范化**（`Router.php:590-609` vs `234`）。`normalizeUri()` 折叠 `//`，而 `addRoute()` 只做 `'/' . trim($uri, '/')`。若开发者注册 `'/deep//path'`，请求会被折叠成 `/deep/path` 而永不命中（实测确认 404，但无法判断这是「预期安全行为」还是「注册期与请求期规范化不一致」的设计缺口，未在文档中找到约定）。
- **疑似 S-02 `Router::matchRoute()` 拒绝解码后含 `/` 的参数，与 `Router::route()` 的 `urlencode()` 不自洽**（`Router.php:314` vs `727`）。`route('f', ['path' => 'a/b'])` 生成 `/files/a%2Fb`，而该 URL 被自己的 matchRoute 判为 404。安全上是对的（防路径穿越），但框架**自己生成的链接自己不通**。这是本轮新引入的不一致（HEAD~1 上该链路因 `...$params` 命名参数展开而全量抛 `Error`，无法直接对比）。需产品决策：`route()` 对含 `/` 的参数抛异常，或改用 `{path:.+}` 显式约定。
- **疑似 S-03 `Model::__clone()` 清空主键 + `exists=false` 的语义可疑**（`Model.php:694-699`）。实测 `clone $m` 后 `$clone->id === null`、`$clone->exists === null`。`SoftDelete::force()` 已手工恢复 pk/exists 绕过该行为，说明框架自身认为这语义不安全。对普通模型，`clone` 一个已持久化实例却丢失主键，极易在「复制一条记录再改」的场景造成静默数据错误。未见文档说明 `__clone` 的存在意图。
- **疑似 S-04 `View::composer()` 注入的数据在 HEAD~1 与 HEAD 下均不转义**（`r15_view.php` C 项：输出 `<b>composer</b>` 而非 `&lt;b&gt;...`）。属**独立于本轮修复的既有缺陷**（composer 在 `render()` 的 `escapeArray()` 之后执行），但 R-01 的修复恰好绕过了它，两者叠加后 `include()` 路径彻底无转义。建议与 R-01 一并处理。
- **疑似 S-05 `Router::middleware()` 在 `get()` 之后调用会被静默丢弃**（`r24_mworder.php`：`$r->get('/x', ...)->middleware('m1')` 注册结果为 `[]`，dispatch 不经过中间件）。该行为在 HEAD 与 HEAD~1 一致（**非本轮回归**），但 `name()` 用了 `pendingRouteName` 机制支持链式调用，`middleware()` 没有，易误导使用者。建议对齐 `name()` 或显式抛异常。
- **疑似 S-06 `Blade::compileBalanced()` 未加词边界，`@if` 可能误匹配 `@iffy(...)`**（`Blade.php:340`）。实测 `@iffy($a)` 未被编译（`$` 字符阻断），当前安全。但注释中「指令名误匹配由 compileDirectives 规避」的说法与实际保护机制不完全对应，建议补一条针对 `@iffy`/`@forx`/`@unlessy` 的回归测试以固化行为。

---
## 三、已验证正确的重要逻辑

以下逻辑经实测确认**工作正常**，可放心保留：

1. **`Router::executeHandler()` 闭包位置传参修复（真实且重要）**（`Router.php:811-827`）。HEAD~1 上 `$handler(...$params)` 因 PHP 8 命名参数展开规则，对**所有**字符串键路由参数直接抛 `Error`——意味着闭包路由在 HEAD~1 上几乎完全不可用。实测对照（`r04_urlroundtrip.php`）：
   ```
   === PREVIOUS (HEAD~1) ===   8 个用例全部 THREW Error
   === CURRENT (HEAD) ===
     param='plain.txt'  url=/files/plain.txt            => 'FILE[plain.txt]'
     param='Café.txt'   url=/files/Caf%C3%A9.txt        => 'FILE[Café.txt]'   ← 多字节正常
     param='a b.txt'    url=/files/a+b.txt             => 'FILE[a b.txt]'
     param='a+b.txt'    url=/files/a%2Bb.txt           => 'FILE[a+b.txt]'   ← 字面加号正确
     param='x%y.txt'    url=/files/x%25y.txt           => 'FILE[x%y.txt]'
     param='a?b.txt'    url=/files/a%3Fb.txt           => 'FILE[a?b.txt]'
     param='a#b.txt'    url=/files/a%23b.txt           => 'FILE[a#b.txt]'
   ```
   变参闭包 `fn(...$all)`、默认参数闭包、0/1/2 形参闭包全部正确（`r03_router2.php` B 项）。
2. **`Router` 405 / `Allow` 头 / 自动 OPTIONS**（`Router.php:559-571`）。实测 3 条路由（GET/POST/PUT 同 URI）下：`DELETE` → `405` + `Allow: "GET, POST, PUT"`；`OPTIONS` → `204` + 同样的 `Allow`；HEAD 正确复用 GET 路由（`r29_mw2.php`）。
3. **`Router::normalizeUri()` 拒绝控制字符 / 反斜杠 / NUL，折叠重复斜杠**（`Router.php:590-609`）。实测 `//a`、`///a`、`/a/`、`''` 均正确归一；`/a%00b`、`/a\b`、`/%2e%2e/etc` 一律 404；query string 剥离正常（`/search?q=a//b&r=//x` → `SEARCH`，折叠只作用于 path）。`%2F` 路径穿越被拦（`/files/a%2F..%2F..%2Fetc%2Fpasswd` → 404）。
4. **`Router::registerController()` 方法级中间件 + `!` 剔除 + 作用域恢复**（`Router.php:442-472`）。实测（`r21_attr.php`）：
   ```
   CURRENT:  /api/open   mw=["auth","log"]        （继承类级）
             /api/noauth mw=["log"]              （!auth 剔除成功）
             /api/add    mw=["auth","log","extra"]（追加成功）
   after registerController, /plain mw=[]         （前缀/中间件无泄漏）
   PREVIOUS: /api/noauth mw=["auth","log"]        （方法级被静默丢弃 —— 已修复）
             /api/add    mw=["auth","log"]        （方法级被静默丢弃 —— 已修复）
   ```
   无类级中间件时方法级单独生效（`registered = ["m1"]`，dispatch 返回 `MW:`）。
5. **`Router::resolveMiddleware()` 环检测**（`Router.php:99-118`）。三层互引组 `web→g1→{web,g2}→{g1}` 在 `memory_limit=128M` 下正常返回，内存增量 3208 字节，无无限递归；合法菱形依赖（`both→{a,b}→{m1}`）未被误剪。
6. **`Router::cacheRoutes()` 可缓存性校验 + 原子写**（`Router.php:969-1030`）。`isCacheableValue()` 递归拒绝闭包/对象/资源；tmp+rename 原子发布。
7. **`Model` mutator 生效链（`applyFillable` → `setAttribute`）**（`Model.php:176-229`）。这是上一轮修的**明文密码入库**问题，实测确认修复到位：
   ```
   create(['name'=>'a','pw'=>'secret']) → {"pw":"SECRET"}   ← setPwAttribute 生效
   update(id, ['pw'=>'newpass'])         → {"pw":"NEWPASS"}  ← 更新路径同样生效
   ```
   `persistUpdate()` 正确 `unset($this->attributes[$primaryKey])`，不会误更新主键。
8. **`Model::find()`/`all()`/`where()`/`select()` 的静态语义与实例兼容**（`Model.php:57,136,143,148`）。`M::where('name','a')` 两参简写与三参形式均正确（内部用 `func_num_args()` 区分，设计得当）；`$instance->delete()`（无参取实例主键）实测返回 1 且行被删除；本地作用域 `US::admins(1)` 经 `__callStatic` → `scopeAdmins` 正确生效；`M::first()`/`M::select(['name'])` 返回类型正确。
9. **`Blade` `@if ($x)` 带空格写法**（`Blade.php:333-343`）。这是明确的可用性提升，HEAD~1 上 `@if ($a)` 等 8 个惯用写法**全部 ParseError**（无法渲染 `@endif`），HEAD 全部 `OK`：
   ```
   CURRENT:  @if ($a) Y @endif                        => OK
             @if($a) Y @endif                         => OK
             @if ($a && ($b || $c)) Y @endif          => OK   ← 嵌套括号正确
             @foreach ($a as $v) {{ $v }} @endforeach => OK
             @unless ($a) N @endunless                 => OK
             @if ($a) @elseif ($b) E @else O @endif   => OK
             @for ($i=0;$i<3;$i++) {{$i}} @endfor       => OK
             @isset($a) Y @endisset                    => OK
   PREVIOUS: 上述 8 例全部 ParseError
   ```
   关于「possessive 量词 + `(?!\w)` 会失配」的顾虑：**实测未发现失配**，当前 `\s*` + `*+` 组合工作正常。`@switch/@case` 的 ParseError 在 HEAD 与 HEAD~1 上**同样存在**，属既有问题，非本轮回归。
10. **`Blade` 邮箱 token 保护**（`Blade.php:213-222`）。9 组用例实测均正确：`support@endif.com`、`user@endforeach.com`、`mailto:info@foreach.org`、`{{ 'a@b.com' }}`、`@php` 块内、`@section` 内、`@include()` 参数内、verbatim 内全部正确；`user@localhost`（非邮箱形态）与 `1.2.3@4.5.6`（不误伤）也正确。恢复顺序（email 先、verbatim 后）正确，无嵌套破坏。
11. **`Blade` 编译缓存 tmp+rename 原子写**（`Blade.php:176-187`）与 **`Router::cacheRoutes()` 原子写**，策略一致且正确。
12. **`JsonResource::resolveWrap()` 反射回溯**。实测（`r14_json.php`）子类设 `$wrap='items'` 不再污染兄弟类与父类，`$wrap = null` 正确表示不包装；HEAD 与 HEAD~1 在**单资源**路径输出一致（`{"data":...}` / `{"items":...}`），说明该修复未破坏既有行为，且集合模式的 `data` 硬编码确已被 `resolveWrap()` 替换（本次未能构造出有效集合用例，`collection()` 在两版均返回 `{}`，属测试构造问题而非缺陷）。
13. **`View::include()` 双重编码修复本身有效**（`r15_view.php` A 项）。`&amp;lt;b&amp;gt;` 双重编码确实被消除：
    ```
    CURRENT:  OUT: &lt;script&gt;alert(1)&lt;/script&gt;          （单次转义）
    PREVIOUS: OUT: &amp;lt;script&amp;gt;alert(1)&amp;lt;/script&amp;gt;  （双重编码）
    ```
    —— 但手段引入了 R-01 的 XSS 回归，**修复方向对、实现错**。
14. **`Upload::isDangerousFilename()`**。实测 12 组：`.htaccess`/`.user.ini`/`.env`/`web.config` 整体名命中；`a.php.jpg`/`a.PHP`/`x.user.ini.jpg`/`a.php5` 多段扩展名命中；`photo.jpg`/`README.md`/`archive.tar.gz`/`noext` 正确放行。大小写不敏感生效。
15. **`HttpClient` SSRF/TLS/体积上限**。`CURLOPT_PROTOCOLS`、`CURLOPT_PROTOCOLS_STR`、`CURLOPT_NOPROXY`、`CURLOPT_SSL_VERIFYPEER`、`CURLOPT_PROGRESSFUNCTION`、`max_bytes` 全部接线；progress 回调阈值逻辑实测在 `dlnow = maxBytes+1` 时才返回 1（不多不少）。
16. **`Schema` SQLite 逐条 ALTER + `compileAlter()` 补 `ADD COLUMN`**。这是**大幅改善**，HEAD~1 上 SQLite 改表**任何场景都失败**（裸列定义直接语法错误），HEAD 上 `ADD COLUMN` 与 `index()` 均成功；`create()` 的 `unique()`/`index()` 在 SQLite 下正确落库（实测 `sqlite_master` 中 `idx_d`、`idx_n` 及 `sqlite_autoindex_t2_1` 均存在）。`table()` 空变更抛异常、`commands`/`comment` 状态重置均正确。唯一遗留问题为 R-08。
17. **`Application::registerMiddlewareAliases()`**（`Application.php:153-166`）与 **`config:cache` 守卫常量**（`Application.php:79-81`）。内置别名注册逻辑正确（未覆盖用户自定义别名）。
18. **`Response::send()` HEAD 拦截**（`Response.php:216-218`）。RFC 9110 合规，HEAD 不返回消息体。
19. **`QueryBuilder` 查询缓存绑定指纹**。`cacheFingerprint()` 在读写两侧使用完全相同算法（SQL + `serialize(bindings)`），不存在「写进去读不出来」的不对称。
20. **`QueryBuilder::aggregate()` 保留 GROUP BY 按组返回**（`QueryBuilder.php:817-871`）。核心修复有效：
    ```
    CURRENT:  groupBy(g)->sum(v) = [{"__sum":-5,"g":"a"},{"__sum":13,"g":"b"},{"__sum":0,"g":"c"}]  ← 正确
    PREVIOUS: groupBy(g)->sum(v) = 8    ← 全表和，错误
    ```
    分组键提取（含 `alias.col` 形式）正确。遗留问题见 R-05 / R-06。
21. **`FormRequest::body()` 只校验请求体**。`$this->all()` → `$this->post()`，堵住「GET 查询串满足 `required|email`」的提权路径。

---
## 四、确认无问题的上一轮修复项（可放心保留）

| # | 修复项 | 位置 | 验证结论 |
|---|---|---|---|
| 1 | `executeHandler` 闭包位置传参 + 反射截断 | `Router.php:811-827` | **关键修复**，HEAD~1 闭包路由几乎全废（8/8 用例抛 Error），HEAD 全部正确 |
| 2 | `matchRoute` 拒绝解码后含 `/ \ NUL` 的参数 | `Router.php:723-729` | 路径穿越 `%2F..%2F..%2F` 被拦（代价见 S-02） |
| 3 | `normalizeUri` 折叠斜杠 / 拒绝控制字符 | `Router.php:590-609` | 18 组用例全部符合预期，含 authority-form 消歧 |
| 4 | 405 / `Allow` / 自动 OPTIONS | `Router.php:559-571` | 状态码与头均正确 |
| 5 | `group()` try/finally 恢复分组状态 | `Router.php:361-366` | 正确；`registerController` 后 `/plain` 无前缀/中间件泄漏 |
| 6 | `registerController` 方法级 middleware + `!` 剔除 | `Router.php:442-472` | 追加与剔除均正确（HEAD~1 完全丢弃方法级） |
| 7 | `resolveMiddleware` 环检测 | `Router.php:99-118` | 三层互引不递归，菱形依赖不误剪 |
| 8 | `cacheRoutes` isCacheableValue + 原子写 | `Router.php:969-1030` | 递归拒绝不可序列化值；tmp+rename |
| 9 | Model mutator 生效链 | `Model.php:176-229` | 明文密码入库问题**确已修复**（create/update 双路径） |
| 10 | Blade `@if ($x)` 带空格 | `Blade.php:333-343` | 8 组惯用写法从全 ParseError 变为全 OK |
| 11 | Blade 邮箱 token 保护 | `Blade.php:213-222` | 9 组用例全正确，无误伤 |
| 12 | Blade 编译缓存原子写 | `Blade.php:176-187` | 与 FileCache/Router 策略对齐 |
| 13 | View::include 消除双重编码 | `View.php:264-285` | **方向正确**（手段需按 R-01 重写） |
| 14 | Upload 危险文件名 + 多文件判定 | `Upload.php` | 12 组用例全正确 |
| 15 | HttpClient 协议/TLS/体积上限 | `HttpClient.php` | 6 项全部接线，阈值逻辑正确 |
| 16 | Schema SQLite 逐条 ALTER + ADD COLUMN | `Schema.php` | HEAD~1 全场景失败 → HEAD 基本可用（遗留 R-08） |
| 17 | JsonResource resolveWrap 反射回溯 | `JsonResource.php` | 子类隔离正确，无破坏 |
| 18 | ServiceProvider 构造参数可选 | `ServiceProvider.php` | 支撑 providers 配置路径 |
| 19 | OutputCache 过滤 Set-Cookie | `OutputCache.php` | 未引入回归 |
| 20 | Memcached ownsInstance | `MemcachedCache.php` | 共享实例拒绝 flush，逻辑保守正确 |
| 21 | FormRequest 只校验 body | `FormRequest.php` | 堵住查询串提权 |
| 22 | QueryBuilder 缓存绑定指纹 | `QueryBuilder.php` | 读写算法对称，无不对称缺陷 |
| 23 | Response::send HEAD 拦截 | `Response.php:216-218` | RFC 9110 合规 |
| 24 | Model::where func_num_args 两参/三参区分 | `Model.php:148-156` | 两种形式均正确 |
| 25 | sanitizeColumn/validateColumnName 支持 `alias.*` | `QueryBuilder.php:122-152` | 与 sanitize 规则一致，无新的不一致输入 |

---

## 五、处置建议（按优先级）

| 优先级 | 条目 | 一句话行动 |
|---|---|---|
| P0 | **R-01** | 删除 `View.php:275` 的 `autoEscape = false`，改为只对本次 `$data` 转义一次 —— 立即恢复 XSS 防护 |
| P0 | **R-02** | `withTrashed()/onlyTrashed()` 改返回 `QueryBuilder`；否则软删除查询全面失效 |
| P1 | **R-03** | `@include` 作用域恢复改用 `array_key_exists(get_defined_vars())` + 清理新增键 |
| P1 | **R-04** | static 化列为 BREAKING CHANGE 并写迁移指引（R-02 修好前建议整体回退该变更） |
| P2 | **R-05** | 统一绑定类型（`bindValue` + `PDO::PARAM_*`），修 HAVING 数值比较恒 false |
| P2 | **R-06** | `max()/min()` 的 `?:` 改 `??`，区分「无行」与「值为 0」 |
| P2 | **R-07** | `run()` 对流式/下载响应豁免缓冲，恢复真流式 |
| P2 | **R-08** | SQLite 索引名加表前缀（`idx_{table}_{col}`） |
| P3 | S-01~S-06 | 补决策与文档；`middleware()` 链式、`__clone` 语义、composer 转义需单独排期 |

**测试缺口**：现有 1060 条测试对上述 8 个缺陷**零覆盖**（修复前后均 100% 通过）。建议至少补：
- SoftDelete 四种查询入口 × `withTrashed`/`onlyTrashed`（R-02）
- `@include` 父变量为 `null` 时的作用域恢复（R-03）
- `max()/min()` 命中 0 值（R-06）
- 下载响应的 `ob_get_level()` 前后不变（R-07）
- HAVING 数值比较 + 聚合组合（R-05）
- 两表同名列建索引（R-08）

---

*报告基于回审 commit `99ee1e0`。所有「已验证缺陷」条目均附 `%TEMP%\lp_audit3\` 下的最小复现脚本与 HEAD/HEAD~1 双向真实输出。*