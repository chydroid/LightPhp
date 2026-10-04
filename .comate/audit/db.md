# LightPHP 数据库层安全与缺陷审计报告

- **审计范围**：`app/db/QueryBuilder.php` (1068 行)、`app/db/Connection.php` (233 行)、`app/db/Schema.php` (815 行, 含 `Blueprint` / `Migration`)、`app/db/Seeder.php` (94 行)、`app/model/Model.php` (709 行)、`app/model/User.php` (26 行)、`app/traits/SoftDelete.php` (159 行)、`app/traits/HasModelEvents.php` (129 行)
- **审计日期**：2025-10-03
- **运行环境验证**：PHP 8.4.7 (cli, NTS, x64)、`pdo_sqlite` 可用
- **方法**：全文件逐行阅读 + 对 SQLite in-memory 库编写探针脚本实测复现（所有"触发场景"均已实际执行验证）
- **基线**：`php tests/run_tests.php` → `968/968 passed`（说明下列缺陷**均未被现有测试覆盖**）
- **结论概览**：CRITICAL 3 项、HIGH 8 项、MEDIUM 18 项、LOW 15 项，共 44 条

---

## 目录

- CRITICAL (3) / HIGH (8) / MEDIUM (18) / LOW (15)
- 已验证正确清单
- 修复优先级建议

---

## 关键结论摘要

| # | 级别 | 标题 | 影响面 |
|---|------|------|--------|
| C1 | CRITICAL | `Model::__callStatic` 对已存在的实例方法是死代码，文档主推的静态 API 全部抛 `Error` | 整个 ORM 门面 |
| C2 | CRITICAL | `create()` / `update()` 完全绕过 mutator，`User` 的密码哈希被跳过 | 安全（明文密码落库） |
| C3 | CRITICAL | `Model::update($id,$data)` 后再 `save()` 会静默插入重复行 | 数据完整性 |
| H1 | HIGH | `whereRaw()` 命名占位符与自动占位符同命名空间，冲突时静默错绑值 | 查询正确性 / 越权 |
| H2 | HIGH | `sanitizeColumn()` 无法处理 `table.*`，`belongsToMany()` 100% 抛异常 | 关系功能全废 |
| H3 | HIGH | `Schema::table()` 未重置 `commands`，与 `create()` 状态重置不对称 | DDL 破坏 |
| H4 | HIGH | `Blueprint::unique()/index()` 生成 MySQL-only 语法，SQLite 必失败 | 跨库不可移植 |
| H5 | HIGH | `Migration::getClassFromFile()` 正则取文件里第一个 `class` | 迁移误执行 / fatal |
| H6 | HIGH | `db\Blueprint`、`db\Migration` 违反 PSR-4，无法自动加载 | 生产 CLI fatal |
| H7 | HIGH | `sum/avg/max/min()` 静默丢弃 `GROUP BY` | 静默错误统计 |
| H8 | HIGH | `Connection` 完全忽略 `prefix`/`collation`/`strict`/`engine` 配置 | 连错表 |
| M1-M18 | MEDIUM | 边界值、clone 语义、事务、SQLite 差异、关联与序列化等 | 一致性 |
| L1-L15 | LOW | API 覆盖面、异常类型、命名规范、长驻进程资源等 | 可维护性 |

---

## CRITICAL (3)

### [CRITICAL] C1 `Model::__callStatic` 是死代码，文档主推的静态 API 全部抛 `Error: Non-static method ... cannot be called statically`
- 文件: app/model/Model.php  行号: 675-708（`$allowedMethods` 677-681）
- 代码:
```php
public static function __callStatic(string $method, array $args)
{
    $allowedMethods = ['where', 'whereIn', ..., 'all', 'find', 'findBy', 'create',
        'update', 'delete', 'paginate', 'select', 'eagerLoad'];

    if (!in_array($method, $allowedMethods, true)) {
        // scope / public-instance-method fallback ...
        throw new \BadMethodCallException(...);
    }
    if ($method === 'eagerLoad') { ... }
    $instance = new static();
    return call_user_func_array([$instance, $method], $args);   // line 706-707
}
```
- 问题: PHP 的魔术方法分派规则是——`__callStatic` **只在被调用的方法不可访问或不存在时**才触发。`Model` 上 `find / all / create / where / select / first / paginate / update / delete / findBy / with / save / toArray / firstOrCreate / firstOrNew / findOrFail / firstOrFail` 以及 `SoftDelete` 的 `restore / trashed / force` 全部是 **public 非静态**方法，PHP 8 在静态上下文中直接抛 `Error`，**根本不会进入 `__callStatic`**。因此 `$allowedMethods` 这 17 个条目里除 `eagerLoad`（本身就是 static）和 `count`（无实例同名方法，靠 `__call` 兜底）之外，**16 个全是死代码**。这不是"降级"而是"完全不可用"。
- 触发场景: 实测（PHP 8.4.7）：
  ```php
  User::find(1);        // Error: Non-static method model\Model::find() cannot be called statically
  User::all();          // Error: ... all() ...
  User::create([...]);  // Error: ... create() ...
  User::where('id',1);  // Error: ... where() ...
  User::select(['id']); // Error: ... select() ...
  User::first();        // Error: ... first() ...
  User::paginate(15,1); // Error: ... paginate() ...
  User::update(1,[...]);// Error: ... update() ...
  User::delete(2);      // Error: ... delete() ...
  User::findBy('name','a'); // Error: ... findBy() ...
  SoftUser::restore();  // Error: Non-static method SU::restore() cannot be called statically
  SoftUser::with('x');  // Error: Non-static method model\Model::with() cannot be called statically
  ```
  而 `docs/api.md:199` 明确写着「`Model` 的大部分方法也可通过 `__callStatic` 静态调用（如 `User::find(1)` 等价于 `(new User())->find(1)`）」，`README.md:130-139`、`docs/quick-start.md:357-378`、`docs/guide.md:661-696`、`docs/api.md:238-315` 全部示例都基于这套 API。**照文档写的代码 100% fatal。**
- 建议修复: 两条路选一（推荐第一条，与文档保持一致）：
  1. 把 `find/findOrFail/findBy/first/firstOrFail/all/select/where/create/update/delete/paginate/with/save/toArray/firstOrCreate/firstOrNew` 改成 `public static function`，内部用 `(new static())->...`；需要实例状态的（`save/toArray/trashed/restore`）保留实例方法并同步修正文档。
  2. 若坚持实例方法 API，则删除 `__callStatic` 里的 `$allowedMethods` 死列表，并把 `docs/api.md` / `README.md` / `docs/quick-start.md` / `docs/guide.md` 中全部静态示例改为 `(new User())->...`。
  无论选哪条，**必须补一条 `User::find(1)` 级别的回归测试**，当前 968 条测试无一覆盖静态入口，所以问题一直没被发现。

### [CRITICAL] C2 `Model::create()` / `Model::update()` 完全绕过 mutator，`User::setPasswordAttribute()` 被跳过 → 明文密码落库
- 文件: app/model/Model.php  行号: 147-163（`create`）、165-178（`update`）；对照 app/model/User.php 20-25
- 代码:
```php
public function create(array $data): int|string
{
    $this->attributes = $this->filterFillable($data);   // line 149 —— 直接数组赋值，未走 setAttribute()
    if (!$this->fireEvent('creating')) { return 0; }
    $data = $this->syncTimestamps($this->attributes, 'create');
    $id = $this->newQuery()->insert($data);             // line 154 —— 原始明文直接 INSERT
    ...
}
public function update(int|string $id, array $data): int
{
    $this->attributes = $this->filterFillable($data);   // line 167 —— 同样绕过
    $result = $this->newQuery()->where($this->primaryKey, '=', $id)->update($data);  // line 173
}
```
```php
// app/model/User.php:20-25
protected function setPasswordAttribute(mixed $value): void
{
    if ($value !== null && $value !== '') {
        $this->attributes['password'] = password_hash((string) $value, PASSWORD_DEFAULT);
    }
}
```
- 问题: mutator（`setXxxAttribute`）只在 `__set()` → `setAttribute()` 这条路径上被触发（Model.php 475-483）。`create()` 和 `update()` 把用户数据**直接数组赋值**给 `$this->attributes`，完全不经 `setAttribute()`。于是：
  - `create(['password' => 'secret'])` 会把 `'secret'` 明文写进 `users.password`；
  - `update(1, ['password' => 'newpass'])` 会把 `'newpass'` 明文写进库。
  这是一个**认证安全漏洞**：任何依赖 `create()`/`update()` 写入密码的注册/改密流程都会产生明文口令；且 `$hidden=['password']` 只影响序列化输出，掩盖了问题。
- 触发场景: 实测：
  ```php
  $id = (new User())->create(['name'=>'c','email'=>'c@x.com','password'=>'secret']);
  (new User())->find($id)->getAttribute('password');   // => "secret"   ← 明文，未哈希

  $m = (new User())->find(1);
  $m->update(1, ['password' => 'newpass']);
  // SELECT password FROM users WHERE id=1 => "newpass"   ← 明文
  ```
  对照组（走 `__set` 的路径是对的）：
  ```php
  $m = new User(); $m->name='e'; $m->password='plain'; $m->save();
  // => password = "$2y$12$Eg8vGwYZ.SsapZlvO3qsm.rJlsfCoy65qbZMzaXlnwD7vacdRGAjG"  ✅
  ```
  即：**同一个模型，`save()` 会哈希，`create()`/`update()` 不会**——同一份业务代码换一种写法就产生不同安全结果。
- 建议修复: 在 `create()`/`update()` 中把赋值改成逐键走 `setAttribute()`：
  ```php
  // create()
  $this->attributes = [];
  foreach ($data as $k => $v) { $this->setAttribute((string)$k, $v); }
  $data = $this->syncTimestamps($this->filterFillable($this->attributes), 'create');
  ```
  ```php
  // update()：先过白名单，再逐键走 mutator
  $attrs = [];
  foreach ($data as $k => $v) {
      if (!in_array($k, $this->fillable, true)) { continue; }
      $this->setAttribute((string)$k, $v);
      $attrs[(string)$k] = $this->attributes[(string)$k];
  }
  $attrs = $this->syncTimestamps($attrs, 'update');
  ```
  另需修复附带边界：`setPasswordAttribute('')` 时既不写入也不删除 key，导致 `password` 列从 INSERT 中消失（回落到 DB 默认值/NULL），而调用方以为写入的是空串。应在 `''` 分支显式 `$this->attributes['password'] = null`，或直接抛 `InvalidArgumentException`。

### [CRITICAL] C3 `Model::update($id, $data)` 覆写整个 `attributes` 并 `unset` 主键，紧随其后的 `save()` 静默插入重复行
- 文件: app/model/Model.php  行号: 165-178（`update`）、496-534（`save`）
- 代码:
```php
public function update(int|string $id, array $data): int
{
    $this->attributes = $this->filterFillable($data);   // 167：原有属性（未在 $data 中的列）全部丢失
    if (!$this->fireEvent('updating')) { return 0; }
    unset($this->attributes[$this->primaryKey]);        // 171：主键被抹掉
    ...
}

public function save(): int|string
{
    $pk = $this->attributes[$this->primaryKey] ?? null; // 502：已被 unset → null
    if (!$this->exists || $pk === null) {                // 504：命中 INSERT 分支
        ...
        $id = $this->newQuery()->insert($data);          // 510：插入一条字段残缺的新行
```
- 问题: `update()` 是实例方法，但它把模型当成"一次性写入容器"用：整份 `attributes` 被 `$data` 覆盖，主键被显式移除，而 `$exists` 仍保持 `true`。随后调用 `save()` 时 `$pk === null`，命中 504 行的 INSERT 分支，**生成一条主键不同、字段残缺的新记录**，原记录保留 → 同一条业务数据出现两行，且没有任何异常或警告。函数第 3 个参数 `$id` 也没有写回 `$this->attributes[$this->primaryKey]`，这是最直接的可观测症状。
- 触发场景: 实测：
  ```php
  $m = (new User())->find(1);      // id=1, name='a'
  $m->update(1, ['name' => 'renamed']);
  $newId = $m->save();             // => "4"
  SELECT id, name FROM users;
  // 1|renamed
  // 2|b
  // 3|c
  // 4|renamed      ← 重复行，email/password 等列全部丢失
  ```
- 建议修复:
  1. `update()` 中保留主键：在 `unset` 之前 `$this->attributes[$this->primaryKey] = $id;`（或把 `unset` 改为只作用于待写字段数组 `$data`）；
  2. `update()` 不应整体覆写 `attributes`，改为 `array_merge($this->attributes, $newAttrs)`，让后续 `save()` 的增量语义成立；
  3. 若 `update()` 的设计意图就是"按 id 批量更新入口"，则改名 `updateById(int|string $id, array $data): int` 并在内部新建实例执行，彻底消除"作用在当前实例"的误用（与已有的 `deleteById` 语义对齐）。
  4. 补回归测试：`find(1)->update(1, [...])->save()` 之后表行数必须不变。

---

## HIGH (8)

### [HIGH] H1 `QueryBuilder::whereRaw()` 的命名占位符与自动生成的 `:w_N` 同命名空间，冲突时静默错绑值
- 文件: app/db/QueryBuilder.php  行号: 931-954（对照 229、263、293-296、424、610、636 处的自动编号）
- 代码:
```php
public function whereRaw(string $sql, array $bindings = []): self
{
    $this->where[] = $sql;
    foreach ($bindings as $key => $value) {
        if (is_int($key)) {
            $placeholder = ':wr_' . count($this->bindings);   // 匿名 ? 用独立前缀，安全
            $search = '?';
        } else {
            $placeholder = $key[0] === ':' ? $key : ':' . $key;   // ← 用户自定义名，直接占用 bindings 键空间
            $search = $placeholder;
        }
        $this->bindings[$placeholder] = $value;                  // ← 同名即覆盖已有绑定
        ...
    }
}
```
- 问题: 自动占位符命名规则是 `':w_' . count($this->bindings)`（`:w_0`、`:w_1`…），而 `whereRaw()` 的具名参数**没有任何前缀隔离**。只要调用方传的键名与某个已存在的自动占位符重名，`$this->bindings[$placeholder] = $value` 就会**直接覆盖该键的值，而 SQL 中所有引用该占位符的位置全部改成新值**。这不是报错，是**静默返回错误结果**——对鉴权/多租户隔离类查询意味着越权风险。
- 触发场景: 实测：
  ```php
  $qb = (new Connection(...))->table('users')->where('status', '=', 1);   // 绑定 :w_0 => 1
  $qb->whereRaw('name = :w_0', [':w_0' => 'zzz']);
  $qb->getSql();     // => 'SELECT * FROM `users` WHERE `status` = :w_0 AND name = :w_0'
  $qb->fetchAll();   // => []      ← 本该返回 status=1 且 name='zzz' 的行
  ```
  `status = :w_0` 里的 `:w_0` 也被绑定成 `'zzz'` 了。若调用方写 `->whereRaw("tenant_id = :w_1 AND role = ?", [':w_1' => $otherTenant])` 且已有 1 个 where 绑定，则**租户过滤条件会被替换成攻击者提供的值**。
- 建议修复: 给 `whereRaw()` 的具名绑定加独立前缀空间，并禁止与自动前缀冲突：
  ```php
  } else {
      $name = ltrim($key, ':');
      if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
          throw new \InvalidArgumentException("Invalid raw placeholder name: {$key}");
      }
      if (preg_match('/^(w_|h_|u_|i_|wr_)\d/', $name)) {
          throw new \InvalidArgumentException("Raw placeholder '{$name}' collides with reserved prefixes.");
      }
      $placeholder = ':raw_' . count($this->bindings);
      $search = ':' . $name;      // SQL 中的 :name 被替换成 :raw_N
  }
  ```
  （值绑定到 `:raw_N`，SQL 里的 `:name` 文本替换为 `:raw_N`。）同时补一条 `:w_0` 冲突的回归测试。

### [HIGH] H2 `sanitizeColumn()` 无法处理 `table.*`，导致 `Model::belongsToMany()` 100% 抛 `InvalidArgumentException`
- 文件: app/db/QueryBuilder.php  行号: 119-149（`sanitizeColumn`）、157-162（`validateColumnName`）、170-184（`select`）；调用点 app/model/Model.php 309-322（`belongsToMany`，第 316 行）
- 代码:
```php
private function sanitizeColumn(string $column): string
{
    if ($column === '*') { return $column; }
    if (preg_match('/^[a-zA-Z0-9_\.]+$/', $column)) {      // ← 正则不含 `*`，`users.*` 到不了下面的星号分支
        if (str_contains($column, '.')) {
            ...
            if (count($segments) === 2) {
                [$alias, $col] = $segments;
                if ($col === '*') {                       // ← 死代码：永远进不来
                    return "`{$alias}`.*";
                }
                return "`{$alias}`.`{$col}`";
            }
```
```php
// app/model/Model.php:315-319
$rows = $instance->newQuery()
    ->select(["{$instance->table}.*"])        // ← line 316，必然触发上面的异常
    ->join($pivotTable, ...)
```
- 问题: `validateColumnName()` 的正则 `/^[a-zA-Z0-9_\.\*]+$/` **允许** `*`，但 `sanitizeColumn()` 的正则 `/^[a-zA-Z0-9_\.]+$/` **不允许**。两者不一致，导致 `'users.*'` 通过校验后在 sanitize 阶段抛异常。`sanitizeColumn` 中专门为 `$col === '*'` 编写的分支（136-138 行）是**永远不可达的死代码**，说明作者本意是支持 `table.*`。后果是多对多关联 `belongsToMany()` **完全不可用**，一调用就抛异常。
- 触发场景: 实测：
  ```php
  class Role extends \model\Model { protected string $table = 'roles';
      public function users2() { return $this->belongsToMany(\model\User::class, 'role_user', 'role_id', 'user_id'); } }
  $role = new Role(); $role->id = 1;
  $role->users2();
  // !! InvalidArgumentException: Invalid column name: users.*
  ```
  单独验证亦然：`(new QueryBuilder($pdo))->table('users')->select(['users.*'])` 抛同样的异常；而 `select(['*.*'])` 抛 `InvalidArgumentException: Invalid column name: *.*`。
- 建议修复: 统一两个正则，使 `table.*` 合法、`*.*` 非法：
  ```php
  private function sanitizeColumn(string $column): string
  {
      if ($column === '*') { return '*'; }
      // 星号只允许作为限定名的第二段
      if (!preg_match('/^[a-zA-Z0-9_]+(\.[a-zA-Z0-9_]+|\.\*)?$/', $column)) {
          throw new \InvalidArgumentException("Invalid column name: {$column}");
      }
      if (!str_contains($column, '.')) { return "`{$column}`"; }
      [$alias, $col] = explode('.', $column, 2);
      return $col === '*' ? "`{$alias}`.*" : "`{$alias}`.`{$col}`";
  }
  ```
  同时把 `validateColumnName()` 里的 `\*` 从通用白名单中去掉（改由 `sanitizeColumn()` 单独判定），避免 `where('id*', ...)` 之类无意义输入被放行。补一条 `belongsToMany()` 的 SQLite 回归测试。

### [HIGH] H3 `Schema::table()` 未重置 `commands`，与 `create()` 的状态重置不对称
- 文件: app/db/Schema.php  行号: 46-61（`create`，第 50-51 行重置）、63-77（`table`，**只重置了 `columns`**）、176-187（`compileAlter`）
- 代码:
```php
public function create(string $table, callable $callback): bool
{
    $this->table = $table;
    $this->columns = [];      // line 50
    $this->commands = [];     // line 51  ← 有
    ...
}

public function table(string $table, callable $callback): bool
{
    $this->table = $table;
    $this->columns = [];      // line 67
    // ✗ 缺少 $this->commands = [];
    $blueprint = new Blueprint($table, $this->driver);
    $callback($blueprint);                       // ← 回调抛异常时状态残留
    $this->columns  = $blueprint->getColumns();
    $this->commands = $blueprint->getCommands();  // line 73
```
- 问题: `create()` 重置 `columns + commands`，`table()` 只重置 `columns`。入口状态不对称导致三类残留：
  (a) 回调内 `Blueprint` 抛异常时，`$this->commands` / `$this->columns` 保留上一次的值。由于 `compileAlter()`（176-187 行）把 `columns + commands` 一起编译，同一 `Schema` 实例上的下一次 `table()` 会把上一次的 `UNIQUE KEY` / `FOREIGN KEY` 合并进本次 ALTER。
  (b) `$this->comment` / `$this->engine` / `$this->charset` / `$this->collation` **从未被重置**。`create('a', ...)` 前调用过 `$schema->comment('X')` 之后，所有后续 `create()` 生成的 DDL 都永久带着 `COMMENT='X'`。
  (c) `Schema::setConnection()` 是全局单例（30-34 行），以上状态在整进程内跨表共享，测试或多库场景互相污染。
- 触发场景: 静态分析 + 同实例复用场景（`Schema::setConnection()` 为单例）：
  ```php
  $s = \db\Schema::setConnection($pdo);
  $s->comment('old comment');
  try { $s->table('t1', function($t) { throw new \RuntimeException('boom'); }); } catch (\Throwable $e) {}
  $s->table('t1', function($t) { $t->string('b'); });   // 命令集混入上一次的残留
  ```
- 建议修复: 抽出统一的状态重置并在所有入口调用：
  ```php
  private function resetState(string $table): void
  {
      $this->table = $table;
      $this->columns = [];
      $this->commands = [];
      $this->comment = '';
  }
  ```
  `create()` / `table()` / `drop()` / `truncate()` 统一调用。同时把表注释改为 `create($table, $callback, ?string $comment = null)`，取消实例级长期驻留。建议一并取消 `Schema::$instance` 单例（见 M10），改为由容器注入实例。

### [HIGH] H4 `Blueprint::unique()` / `index()` 生成 MySQL-only 语法，SQLite 上必然失败
- 文件: app/db/Schema.php  行号: 399-407（`unique`）、409-417（`index`）；对照 254-258（`id()` 对 sqlite 已分支）、325-334（`timestamps()` 对 sqlite 已分支）
- 代码:
```php
public function unique(): self
{
    ...
    $this->commands[] = "UNIQUE KEY `uk_{$col}` (`{$col}`)";   // line 405 —— MySQL 专有
    return $this;
}
public function index(): self
{
    ...
    $this->commands[] = "KEY `idx_{$col}` (`{$col}`)";          // line 415 —— MySQL 专有
    return $this;
}
```
- 问题: `Blueprint` 的其它方法（`id()`、`timestamps()`）都按 `$this->driver` 做了分支，但 `unique()` / `index()` 没有。SQLite 不支持 `UNIQUE KEY` / `KEY` 表内约束（SQLite 用 `UNIQUE(col)`；普通索引需独立 `CREATE INDEX`）。`compileCreate()` 会把这些原样拼进 `CREATE TABLE`，必然语法错误。虽然构造函数接收了 `$driver`（第 241 行），这两个方法完全没用。
- 触发场景: 实测：
  ```php
  $s = \db\Schema::setConnection($pdo);   // sqlite::memory:
  $s->create('tix', function(\db\Blueprint $t) { $t->id(); $t->string('a')->unique()->index(); });
  // !! core\exception\DatabaseException: Schema operation failed:
  //    SQLSTATE[HY000]: General error: 1 near "KEY": syntax error
  //    SQL: CREATE TABLE `tix` (
  //        `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  //        `a` VARCHAR(255),
  //        UNIQUE KEY `uk_a` (`a`),
  //        KEY `idx_a` (`a`)
  //    )
  ```
  即：**在 SQLite（测试环境 / 单机部署常用）上无法创建任何带唯一约束或索引的表**。968 条测试全通过，说明测试用例从不在 SQLite 上使用 `unique()`/`index()`。
- 建议修复: 按 driver 分支 + 延迟索引：
  ```php
  public function unique(): self
  {
      $col = trim($this->lastColumn, '`');
      $this->commands[] = $this->driver === 'sqlite'
          ? "UNIQUE (`{$col}`)"
          : "UNIQUE KEY `uk_{$col}` (`{$col}`)";
      return $this;
  }
  public function index(): self
  {
      $col = trim($this->lastColumn, '`');
      if ($this->driver === 'sqlite') {
          $this->deferredIndexes[] = "CREATE INDEX IF NOT EXISTS `idx_{$col}` ON `{$this->table}` (`{$col}`)";
      } else {
          $this->commands[] = "KEY `idx_{$col}` (`{$col}`)";
      }
      return $this;
  }
  ```
  新增 `getDeferredIndexes(): array`，由 `Schema::create()`/`table()` 在主 DDL 成功后逐条 `exec()`。补 SQLite + `unique()` 回归测试。

### [HIGH] H5 `Migration::getClassFromFile()` 用正则取文件里"第一个 class"，选错类导致 fatal 或迁移静默失效
- 文件: app/db/Schema.php  行号: 798-814（`getClassFromFile`）、625-659（`run`）
- 代码:
```php
private function getClassFromFile(string $file): string
{
    $content = file_get_contents($this->migrationsPath . $file);
    $content = preg_replace('/\/\*.*?\*\//s', '', $content);
    $content = preg_replace('/\/\/.*$/m', '', $content);
    $namespace = '';
    if (preg_match('/namespace\s+([\w\\\\]+)/', $content, $nm)) { $namespace = $nm[1] . '\\'; }
    if (preg_match('/class\s+(\w+)/', $content, $m)) {    // ← 取全文第一个 class
        return $namespace . $m[1];
    }
    return '';
}
```
- 问题: 该方法只做"剥注释 + 取第一个 class 名"，不区分 `abstract class` / `interface` / `trait` / 辅助类。`run()` 拿到类名后直接 `new $class($this->pdo)`：
  - 匹配到 `abstract class` / `interface` → **PHP fatal，整个 migrate 命令崩溃**；
  - 匹配到迁移文件里的辅助类（构造函数无参或兼容）→ 迁移**什么都不做却被 `record()` 记为 Ran**，产生"迁移已执行"的假象，后续 `migrate` 永远跳过它。
  另：`preg_replace('/\/\/.*$/m', ...)` 会把字符串字面量里的 `//`（如 `'https://example.com'`）当成行注释截断，可能破坏后续正则匹配。
- 触发场景: 实测（临时迁移目录）：
  ```php
  // 1_abc.php: namespace X; abstract class Base {} class Abc { function up(){} }
  $m->run();   // Error: Cannot instantiate abstract class X\Base     ← migrate 直接 fatal

  // 2024_01_01_120000_create_x.php:
  //   namespace App\Migrations; class Helper {} class CreateX { function __construct($p){} function up(){} }
  $m->status();  // {"2024_01_01_120000_create_x.php":"Pending"}
  $m->run();     // ["2024_01_01_120000_create_x.php"]  ← 匹配到 Helper，up() 从未执行却被记为 Ran

  // 两个迁移文件使用同名类 → Fatal error: Cannot redeclare class X\Dup  ← 整个 run 中断
  ```
- 建议修复: 用 tokenizer 取代正则猜测，并加护栏：
  ```php
  private function getClassFromFile(string $file): string
  {
      $src    = file_get_contents($this->migrationsPath . $file);
      $ns     = preg_match('/^\s*namespace\s+([\w\\\\]+)\s*;/m', $src, $m) ? $m[1] . '\\' : '';
      $tokens = token_get_all($src);
      for ($i = 0, $n = count($tokens); $i < $n; $i++) {
          if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_CLASS) { continue; }
          $j = $i + 1;
          while ($j < $n && is_array($tokens[$j])
                 && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { $j++; }
          if ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
              $next = $tokens[$j + 1] ?? null;
              if (!is_array($next) || $next[0] !== T_EXTENDS) { return $ns . $tokens[$j][1]; }
          }
      }
      return '';
  }
  ```
  ```php
  // run() 内护栏
  if ($class === '') { throw new \RuntimeException("No class found in migration {$file}"); }
  $rc = new \ReflectionClass($class);
  if ($rc->isAbstract() || $rc->isInterface()) {
      throw new \RuntimeException("Migration {$file} resolves to non-instantiable {$class}");
  }
  if (!method_exists($instance, 'up')) {
      throw new \RuntimeException("Migration {$class} has no up() method");
  }
  ```
  另：类名重复时抛明确异常而非 fatal；`//` 剥离仅在字符串字面量之外生效。

### [HIGH] H6 `db\Blueprint` / `db\Migration` 违反 PSR-4，无法被自动加载
- 文件: app/db/Schema.php  行号: 233（`class Blueprint`）、614（`class Migration`）；对照 app/core/Loader.php 8-23、36-57
- 代码:
```php
// app/core/Loader.php:17  →  'db\\' => APP_PATH . 'db/'
// PSR-4 要求一个类一个文件：app/db/Blueprint.php、app/db/Migration.php
// 实际：两者都塞在 app/db/Schema.php 里
```
- 问题: `Loader::autoload()`（第 36-57 行）按 `$prefix + 相对类名` 拼路径并用 `realpath()` 校验目录穿越。`\db\Blueprint` → `app/db/Blueprint.php`（不存在，`realpath()` 返回 `false` → `continue`）→ 类找不到。当前之所以"能用"，纯粹是因为 `Schema` 类被别处先加载过，顺带定义了同文件里的另两个类。`tests/run_tests.php` 里的 `new \db\Blueprint('test')`（5043 行）与 `new \db\Migration($pdo, $tmpDir)`（2816 行）能通过，也只是因为前几百条测试已加载过 `Schema.php`。**任何以 `Migration` 起步的独立进程都会 fatal。**
- 触发场景: 实测（全新进程，仅 `Loader::register()`）：
  ```php
  new \db\Blueprint('x');            // Error: Class "db\Blueprint" not found
  new \db\Migration($pdo, __DIR__); // Error: Class "db\Migration" not found
  ```
  （同一进程里先访问 `\db\Schema` 之后两者才可实例化——正说明是加载顺序依赖。）
- 建议修复: 拆分为 `app/db/Blueprint.php`、`app/db/Migration.php`，各自 `<?php declare(strict_types=1); namespace db;`；`Schema.php` 只保留 `Schema` 类。若必须保持单文件，则在 `Loader.php` 增加 classmap：`['db\Blueprint' => APP_PATH.'db/Schema.php', 'db\Migration' => APP_PATH.'db/Schema.php']`，但拆文件更符合项目既有 PSR-4 约定（需同步 `composer.json`）。
  补测试：在**独立子进程**中 `require Loader.php; new \db\Migration(...)`，确保不依赖加载顺序。

### [HIGH] H7 `sum()` / `avg()` / `max()` / `min()` 静默丢弃 `GROUP BY`，返回全局聚合值
- 文件: app/db/QueryBuilder.php  行号: 781-800（`sum`）、802-821（`avg`）、823-842（`max`）、844-863（`min`）；对照 737-766（`count` 正确处理了 GROUP BY）
- 代码:
```php
public function sum(string $column): float
{
    ...
    $clone = clone $this;
    $clone->select = "SUM(`{$column}`) as __sum";
    ...
    $clone->groupBy = '';        // line 792 ← 无条件清空 GROUP BY
    $clone->having = [];
    $clone->clearHavingBindings();
    $sql = $clone->buildSelect();   // SELECT SUM(`v`) as __sum FROM `t`   ← 分组消失
```
- 问题: `count()` 正确地用 `SELECT COUNT(*) FROM (inner) AS __count_sub` 子查询处理了 GROUP BY（第 748-751 行），但 `sum/avg/max/min` 走的是"清空 groupBy + 替换 select"的路径。调用方以为拿到的是"按组聚合"的结果，实际拿到的是**全表单值**，且没有任何警告。这是最危险的一类缺陷：报表/统计口径全错但无任何异常。
- 触发场景: 实测（表 `t(g,v)` 数据 `('a',1),('a',2),('b',100)`）：
  ```php
  $q->groupBy('g')->sum('v');    // => 103.0    期望 [3, 100]
  $q->groupBy('g')->max('v');    // => 100      期望 [2, 100]
  $q->groupBy('g')->count();     // => 2        ✅ count 是对的
  $q->groupBy('g')->fetchAll();  // => [{g:'a',v:1}, {g:'b',v:100}]   ← 任意取每组首行
  ```
  `count()` 正确、`sum()` 错误，两者语义不一致，说明这是遗漏而非有意设计。
- 建议修复: 与 `count()` 保持一致，用子查询包裹：
  ```php
  public function sum(string $column): float
  {
      $this->assertNotRaw('sum');
      $this->validateAggregateColumn($column);
      $clone = clone $this;
      $clone->limit = 0; $clone->offset = 0; $clone->orderBy = '';
      $clone->forUpdate = false; $clone->lock = null;
      if ($this->groupBy !== '') {
          $inner = $clone->buildSelect();     // 保留 groupBy / having / 绑定
          $sql = "SELECT SUM(`{$column}`) as __sum FROM ({$inner}) AS __agg_sub";
      } else {
          $clone->select = "SUM(`{$column}`) as __sum";
          $clone->groupBy = ''; $clone->having = []; $clone->clearHavingBindings();
          $sql = $clone->buildSelect();
      }
      $stmt = $this->pdo->prepare($sql);
      $stmt->execute($clone->bindings);
      $result = $stmt->fetch();
      return (float)(is_array($result) ? ($result['__sum'] ?? 0) : 0);
  }
  ```
  `avg/max/min` 同理。另：聚合分支需显式清除 `distinct`（`SELECT DISTINCT SUM(...)` 语义无意义，见 M4）。
  补回归测试：`groupBy('g')->sum('v')` 必须等于 `groupBy('g')->fetchAll()` 手工聚合的结果。

### [HIGH] H8 `Connection` 完全忽略 `prefix` / `collation` / `strict` / `engine` 配置项
- 文件: app/db/Connection.php  行号: 32-99（`connect`）、app/config/database.php 38-40、57、73
- 代码:
```php
private function connect(): void
{
    $driver = $this->config['driver'] ?? 'mysql';     // line 43 ← 未知 driver 也走 MySQL 分支
    ...
    $charset  = $this->config['charset']  ?? 'utf8mb4';
    $dsn = "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";  // line 89
    // ✗ 从未读取 config['prefix']    （表前缀）
    // ✗ 从未读取 config['collation'] （排序规则）
    // ✗ 从未读取 config['strict']    （SQL 模式）
    // ✗ 从未读取 config['engine']    （默认存储引擎）
```
- 问题: `app/config/database.php` 明确定义了 `prefix`、`collation`、`strict`、`engine` 四个键（mysql 连接 38-40 行、全局 73 行），文档也宣传支持表前缀，但 `Connection::connect()` **一个都没读**。结果：
  - 设置了 `DB_PREFIX=app_` 的部署会**静默地查询/写入无前缀表**，在多应用共享库的场景下读写到别的应用的数据，或直接 `Table doesn't exist`；这属于"配置看起来生效了"的高危类别。
  - `strict` / `collation` / `engine` 同样失效，用户无法通过配置关闭 MySQL 严格模式或指定排序规则。
  另外第 43 行只有 `sqlite` 被显式判断，**任何未知 driver（如 `pgsql`、`sqlsrv`）都会静默按 MySQL 连接**，报错信息是笼统的 "Database connection failed"，完全看不出是 driver 拼错。
- 触发场景: 实测：
  ```php
  new \db\Connection(['driver'=>'sqlite','database'=>':memory:','prefix'=>'pre_'])->getDatabase();  // => ":memory:"（prefix 无任何作用）
  new \db\Connection(['driver'=>'pgsql','database'=>'x']);
  // !! core\exception\DatabaseException: Database connection failed. Please check your configuration.
  //    （真实原因是 driver=pgsql 被当成 mysql）
  ```
  `prefix` 也无任何 API 暴露其被使用：`Connection::table()`（127-130）直接 `$this->pdo` + 用户表名，`QueryBuilder::validateTableName()`（93-98）也不允许表名带点号以外的分隔符。
- 建议修复:
  1. **要么实现、要么删配置**（推荐实现）：
  ```php
  $this->prefix = (string)($this->config['prefix'] ?? '');
  $pdo = new \PDO($dsn, $username, $password, $options);
  if ($this->prefix !== '') {
      $pdo->exec("SET SESSION sql_mode=CONCAT(@@sql_mode, ',STRICT_TRANS_TABLES')"); // strict 单独白名单控制
  }
  ```
  2. `QueryBuilder` 构造函数增加 `?string $tablePrefix = null`，`buildSelect/buildInsert/buildUpdate/buildDelete` 与 `Schema` 统一使用 `$this->prefix . $table`；`validateTableName()` 相应放宽。
  3. `connect()` 增加 driver 白名单：
  ```php
  if (!in_array($driver, ['mysql', 'sqlite'], true)) {
      throw new \InvalidArgumentException("Unsupported database driver: {$driver}");
  }
  ```
  4. 若决定不支持前缀，则立刻从 `app/config/database.php` 与文档中删除 `prefix`/`collation`/`strict`/`engine`，避免误导。

---

## MEDIUM (18)

### [MEDIUM] M1 `limit(0)` 退化为"无 LIMIT"，`limit(0, $offset)` 的 offset 被整段丢弃
- 文件: app/db/QueryBuilder.php  行号: 430-441（`limit`）、582-587（`buildSelect`）
- 代码:
```php
public function limit(int $limit, int $offset = 0): self
{
    if ($limit < 0) { throw new \InvalidArgumentException('Limit must be non-negative, got ' . $limit); }
    if ($offset < 0) { throw new \InvalidArgumentException('Offset must be non-negative, got ' . $offset); }
    $this->limit = $limit;      // 允许 0
    $this->offset = $offset;
    return $this;
}
...
if ($this->limit > 0) {                 // line 582 ← limit===0 时整段 LIMIT/OFFSET 都不输出
    $sql .= " LIMIT {$this->limit}";
    if ($this->offset > 0) { $sql .= " OFFSET {$this->offset}"; }
}
```
- 问题: `limit(0)` 在 SQL 语义中意为"一条也不要返回"，但这里因 `limit > 0` 的判断被完全省略，返回**全表所有行**。这是危险的静默失败：任何"取 0 条做存在性判断""按 0 分页"的写法都会变成全表扫描 + 全量载入内存。`limit(0, $offset)` 同理，offset 一并消失。
- 触发场景: 实测（表 `users` 3 行）：
  ```php
  (new QueryBuilder($pdo))->table('users')->where('id','>',0)->limit(0)->getSql();
  // => 'SELECT * FROM `users` WHERE `id` > :w_0'      ← LIMIT 完全消失
  count((new QueryBuilder($pdo))->table('users')->limit(0)->fetchAll());   // => 3（期望 0）
  (new QueryBuilder($pdo))->table('users')->limit(0, 2)->getSql();          // => 'SELECT * FROM `users`'
  ```
- 建议修复: 用 `?int` 区分"未设置"与"设置为 0"：
  ```php
  private ?int $limit = null;   // 原为 int $limit = 0
  // buildSelect()
  if ($this->limit !== null) {
      $sql .= " LIMIT {$this->limit}";
      if ($this->offset > 0) { $sql .= " OFFSET {$this->offset}"; }
  }
  ```
  同时把 `count()/sum()/avg()/max()/min()/chunk()` 里的 `$clone->limit = 0;` 改为 `$clone->limit = null;`（它们的本意是"清除限制"而非"限制为 0"）。
  补测试：`limit(0)->fetchAll()` 必须返回 `[]`。

### [MEDIUM] M2 `whereRaw()` 的匿名 `?` 替换不跳过字符串字面量
- 文件: app/db/QueryBuilder.php  行号: 931-954（重点 936-951）
- 代码:
```php
$placeholder = ':wr_' . count($this->bindings);
$search = '?';
...
$this->where[count($this->where) - 1] = preg_replace(
    '/' . preg_quote($search, '/') . '/',
    $placeholder,
    $this->where[count($this->where) - 1],
    1                      // ← 只替换第一个 "?" 字面字符
);
```
- 问题: 用纯文本 `preg_replace('/\?/')` 替换，无法区分"参数占位符"与"SQL 字符串字面量里的问号"。用户写 `whereRaw("note LIKE '%?%' AND status = ?", [1])` 时，第一个被替换的是字符串里的 `?`，真正的占位符保持不变 → SQL 里仍是 `?` 而绑定只有 1 个 → PDO 抛 `column index out of range`；替换位置错乱还会改变查询语义。
- 触发场景: 实测：
  ```php
  (new Connection(...))->table('users')->whereRaw("name = '?' AND name = ?", ['a']);
  // => PDOException: SQLSTATE[HY000]: General error: 25 column index out of range
  ```
- 建议修复: 不要在字符串层面替换 `?`，让 PDO 原生处理位置参数；只支持具名占位符：
  ```php
  public function whereRaw(string $sql, array $bindings = []): self
  {
      $this->where[] = $sql;
      foreach ($bindings as $key => $value) {
          if (is_int($key)) { $this->bindings[] = $value; continue; }  // 位置参数交给 PDO 顺序绑定
          $name = ltrim((string)$key, ':');
          if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
              throw new \InvalidArgumentException("Invalid raw placeholder name: {$key}");
          }
          $this->bindings[':' . $name] = $value;
      }
      return $this;
  }
  ```
  补测试：`whereRaw("a = '?' AND b = ?", ['x'])` 能正确返回预期行。

### [MEDIUM] M3 `whereRaw()` 传入空字符串键触发 "Uninitialized string offset" 警告并留下未替换的 `?`
- 文件: app/db/QueryBuilder.php  行号: 940-943
- 代码:
```php
$placeholder = $key[0] === ':' ? $key : ':' . $key;   // line 942 ← $key 为 '' 时 $key[0] 越界
```
- 问题: PHP 8 下对空字符串取下标 0 触发 `Warning: Uninitialized string offset 0`，表达式求值为 `""`，占位符退化成 `":"`，`preg_replace('/\:/', ...)` 会去替换 SQL 里所有冒号（包括合法占位符），行为完全不可预测。
- 触发场景: 实测：
  ```php
  (new QueryBuilder($pdo))->table('users')->whereRaw('status = ?', ['' => 1])->getSql();
  // Warning: Uninitialized string offset 0 in QueryBuilder.php on line 942
  // => 'SELECT * FROM `users` WHERE status = ?'     ← 占位符没被替换
  ```
- 建议修复: 在循环入口做键名校验（与 H1 的修复合并）：
  ```php
  $name = ltrim((string)$key, ':');
  if ($name === '' || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
      throw new \InvalidArgumentException("Invalid raw placeholder name: '{$key}'");
  }
  ```
  即在访问 `$key[0]` 之前先判空并校验格式。

### [MEDIUM] M4 聚合方法在有 `DISTINCT` 时生成 `SELECT DISTINCT SUM(...)` 等无意义 SQL
- 文件: app/db/QueryBuilder.php  行号: 781-863（`sum/avg/max/min`）、737-766（`count`）
- 代码:
```php
$clone->select = "SUM(`{$column}`) as __sum";
// ✗ 从未清除 $clone->distinct（buildSelect 第 560 行仍会输出 "SELECT DISTINCT "）
```
- 问题: `distinct` 标志被 clone 一并继承，聚合函数作用于单行结果，`DISTINCT` 完全无语义。多数情况下结果恰好相同，但会产生误导性的 SQL 日志/慢查询；配合 `GROUP BY` 时（本应走子查询，见 H7），`SELECT DISTINCT SUM(...) FROM ... GROUP BY ...` 语义更加混乱。
- 触发场景: 实测：
  ```php
  (new Connection(...))->table('users')->distinct()->sum('score');
  // SQL: SELECT DISTINCT SUM(`score`) as __sum FROM `users`   => 30.0
  ```
- 建议修复: 在四个聚合方法的 clone 上加 `$clone->distinct = false;`；`count()` 中的 `COUNT(*) as __count` 同样应清除。

### [MEDIUM] M5 `chunk()` 基于 OFFSET 分页且不强制 ORDER BY，可能漏行 / 重复行
- 文件: app/db/QueryBuilder.php  行号: 901-923（对照 968-997 的 `chunkById`）
- 代码:
```php
$clone->limit($count, ($page - 1) * $count);   // ← OFFSET 分页
$results = $clone->fetchAll();
...
$page++;
```
- 问题: 三个问题叠加：
  (a) 不校验也不补充 `ORDER BY`。无 ORDER BY 时 SQL 不保证行序，OFFSET 分页在并发写入或优化器选择不同执行计划时**会跳过或重复行**。
  (b) 回调内对当前批次做写操作（典型场景：chunk + delete/update）会改变结果集大小，导致后续页 OFFSET 错位，漏掉本应处理的行。
  (c) 回调只有 `=== false` 能中断，返回 `null`/`0` 都会继续。
- 触发场景: 实测（3 行数据、chunk(2)，恰好按 rowid 返回未暴露问题）：
  ```php
  $qb->chunk(2, function($rows) use (&$seen) { foreach($rows as $r) $seen[] = $r['id']; });
  // => [1,2,3]（本次侥幸正确）；换成 MySQL 无 ORDER BY 或在回调中删除行即错位
  ```
- 建议修复: 要求显式排序，把不确定性暴露为错误：
  ```php
  public function chunk(int $count, callable $callback): void
  {
      if ($count < 1) { throw new \InvalidArgumentException(...); }
      if ($this->orderBy === '') {
          throw new \LogicException('chunk() requires an explicit orderBy() for stable pagination; use chunkById() for write scenarios.');
      }
      ...
  }
  ```
  并在文档中明确推荐写场景用 `chunkById()`。补"无 orderBy 抛异常"的测试。

### [MEDIUM] M6 `chunkById()` 起始值硬编码为 `0`，负数 / UUID / 非数值主键会漏掉全部数据
- 文件: app/db/QueryBuilder.php  行号: 968-997
- 代码:
```php
$lastId = 0;                                  // line 973
do {
    $clone = clone $this;
    $clone->where($column, '>', $lastId);      // line 976
    $clone->orderBy($column, 'ASC');           // line 977 —— 追加而非替换已有 orderBy
    $clone->limit($count);
```
- 问题: 三个边界缺陷：
  (a) `$lastId = 0` 假定主键为正整数自增值。负数主键、UUID/字符串主键场景下 `where(col,'>',0)` **静默返回空集**，一条数据都处理不到。
  (b) `orderBy()` 是**追加**语义：若调用方已 `->orderBy('created_at')`，SQL 变成 `ORDER BY created_at, id ASC`，主键递增的前提被破坏，翻页逻辑失效。
  (c) 若调用方在链上已有 `where(...)`，`clone` 每轮都会基于已复制的 bindings 继续追加，翻页 SQL 文本随页数线性膨胀（日志/缓存键异常）。
- 触发场景: 实测（`name` 作为主键列，值为 'a','b'）：
  ```php
  $out = [];
  (new Connection(...))->table('users')->chunkById(1, function($r) use (&$out) { $out[] = $r; }, 'name');
  count($out);   // => 1（应为 2）——where(name,'>',0) 过滤掉了 'a'
  ```
- 建议修复: 首轮不加 where，并先清空 orderBy：
  ```php
  $lastId = null; $page = 1;
  do {
      $clone = clone $this;
      if ($lastId !== null) { $clone->where($column, '>', $lastId); }
      $clone->orderBy = '';                 // ← 避免追加冲突
      $clone->orderBy($column, 'ASC');
      $clone->limit = $count; $clone->offset = 0;
      $results = $clone->fetchAll();
      if (empty($results)) { break; }
      if ($callback($results, $page) === false) { break; }
      $bare = str_contains($column, '.') ? substr(strrchr($column, '.'), 1) : $column;
      $lastId = end($results)[$bare] ?? null;
      if ($lastId === null) { break; }
      $page++;
  } while (count($results) === $count);
  ```

### [MEDIUM] M7 `paginate()` 在 `GROUP BY` 查询上返回残缺分组
- 文件: app/db/QueryBuilder.php  行号: 1009-1028（`paginate`）、737-766（`count`）
- 代码:
```php
$total = $this->count();              // count() 用子查询正确统计分组数
...
$clone = clone $this;
$clone->limit($perPage, ($page - 1) * $perPage);
$items = $clone->fetchAll();          // ← 直接对分组查询加 LIMIT，取到的是"每组的任意一行"
```
- 问题: `count()` 正确地用 `SELECT COUNT(*) FROM (SELECT ... GROUP BY ...) AS __count_sub` 统计分组数，但 `items` 那一侧**没有做同样的子查询包装**，而是对 `GROUP BY` 查询直接加 `LIMIT/OFFSET`。结果 `total` 是组数，`items` 是每组的任意一行，分页语义完全错乱（`has_more` 也会算错）。
- 触发场景: 实测（表 `t(g,v)`：`('a',1),('a',2),('b',100)`）：
  ```php
  $q->groupBy('g')->paginate(1, 2);
  // total = 3, last_page = 3
  // items = [{g:'a', v:1}]      ← 期望 [{g:'b', ...}]
  ```
- 建议修复: `items` 也走子查询包裹：
  ```php
  if ($this->groupBy !== '') {
      $inner = clone $this;
      $inner->limit = null; $inner->offset = 0; $inner->orderBy = ''; $inner->forUpdate = false;
      $innerSql = $inner->buildSelect();
      $items = (new self($this->pdo))->raw(
          "SELECT * FROM ({$innerSql}) AS __page_sub LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage),
          $this->bindings
      )->fetchAll();
  } else {
      $clone = clone $this; $clone->limit($perPage, ($page - 1) * $perPage); $items = $clone->fetchAll();
  }
  ```
  补测试：`groupBy()->paginate()` 的 items 行数必须等于 `min(perPage, total)`。

### [MEDIUM] M8 `Migration::reset()` / `fresh()` 在全新库上抛 `PDOException`，无法作为幂等的"重置"入口
- 文件: app/db/Schema.php  行号: 690-695（`reset`）、697-701（`fresh`）、790-796（`getAll`）
- 代码:
```php
public function reset(): array
{
    $all = $this->getAll();          // ← 没有 $this->ensureTable()
    if (empty($all)) return [];
    return $this->rollback(count(array_unique(array_column($all, 'batch'))));
}
public function fresh(): array { $this->reset(); return $this->run(); }

private function getAll(): array
{
    $stmt = $this->pdo->query("SELECT * FROM migrations ORDER BY batch, id");
    if ($stmt === false) return [];   // ← ERRMODE_EXCEPTION 下不会返回 false，只会抛异常
    ...
}
```
- 问题: `run()` / `rollback()` / `status()` 都先调 `ensureTable()`，唯独 `reset()` 没有。`getAll()` 里的 `if ($stmt === false) return []` 是无效防御——`Connection` 设置了 `PDO::ERRMODE_EXCEPTION`，`query()` 在表不存在时**抛异常**而非返回 `false`。因此在一个从未 migrate 过的库上执行 `migrate:reset` / `migrate:fresh` 直接 fatal，且错误信息是裸 `PDOException`（未经 `DatabaseException` 包装）。
- 触发场景: 实测（全新 `sqlite::memory:`）：
  ```php
  $m = new \db\Migration($pdo, $emptyDir);
  $m->reset();   // !! PDOException: SQLSTATE[HY000]: General error: 1 no such table: migrations
  $m->fresh();   // !! PDOException: SQLSTATE[HY000]: General error: 1 no such table: migrations
  ```
  这是**状态相关**的：只要之前任何操作建过 `migrations` 表，`reset()` 就返回 `[]`（看起来正常），所以很容易在测试里"通过"而在生产的空库上炸。
- 建议修复: `reset()` 入口补 `ensureTable()`，并去掉无效的 `false` 判断：
  ```php
  public function reset(): array
  {
      $this->ensureTable();
      $all = $this->getAll();
      if (empty($all)) { return []; }
      return $this->rollback(count(array_unique(array_column($all, 'batch'))));
  }
  private function getAll(): array
  {
      return $this->pdo->query("SELECT * FROM migrations ORDER BY batch, id")->fetchAll(\PDO::FETCH_ASSOC);
  }
  ```
  补测试：空库上 `reset()` 与 `fresh()` 都必须返回 `[]` 而不抛异常。

### [MEDIUM] M9 `Migration::run()` 无事务包裹，单个迁移失败会留下半成品结构且中断整个批次
- 文件: app/db/Schema.php  行号: 625-659（`run`）
- 代码:
```php
require_once $this->migrationsPath . $file;                 // line 642
$class = $this->getClassFromFile($file);                   // line 643
if (!class_exists($class)) { continue; }                   // 645-647 ← 静默跳过
$instance = new $class($this->pdo);
if (method_exists($instance, 'up')) { $instance->up(); }    // 650-652 ← 无 up() 也继续
$this->record($file, $batch);                              // line 654
```
- 问题: 四个缺陷：
  (a) **无事务包裹**。多个 `ALTER TABLE` 之间失败会留下无法回滚的中间态。
  (b) `require_once` 后类名冲突 → **PHP fatal，整个 run 中断**（不是可捕获的异常），已完成的迁移状态无法汇总。
  (c) `class_exists($class)` 为 false 时 `continue` **静默跳过**，终端没有任何提示。
  (d) `method_exists($instance, 'up')` 为 false 时**仍然 `record()`**——把一个什么都没做的迁移标记为已执行。
- 触发场景: 实测（两个迁移文件使用同名类）：
  ```
  Fatal error: Cannot redeclare class X\Dup (previously declared in .../1_one.php:3) in .../2_two.php on line 3
  ```
  实测（文件含辅助类 `Helper` + 迁移类 `CreateX`，见 H5）：`run()` 返回"已执行"，但匹配到的是 `Helper`，`CreateX::up()` **从未被调用**。
- 建议修复:
  ```php
  require_once $this->migrationsPath . $file;
  $class = $this->getClassFromFile($file);
  if ($class === '' || !class_exists($class)) {
      throw new \RuntimeException("No instantiable migration class found in {$file}");
  }
  $rc = new \ReflectionClass($class);
  if ($rc->isAbstract() || $rc->isInterface()) {
      throw new \RuntimeException("Migration {$file} resolves to non-instantiable {$class}");
  }
  $instance = new $class($this->pdo);
  if (!method_exists($instance, 'up')) {
      throw new \RuntimeException("Migration {$class} has no up() method");
  }
  $this->pdo->beginTransaction();          // 尽力而为（MySQL DDL 会隐式提交）
  try {
      $instance->up();
      $this->record($file, $batch);
      $this->pdo->commit();
  } catch (\Throwable $e) {
      if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
      throw new \RuntimeException("Migration {$file} failed: " . $e->getMessage(), 0, $e);
  }
  $migrated[] = $file;
  ```
  并加类名重复检测（比对 `get_declared_classes()`）。

### [MEDIUM] M10 `Schema::$instance` 全局单例使多连接 / 多库场景互相污染
- 文件: app/db/Schema.php  行号: 22（`private static ?self $instance`）、30-34（`setConnection`）、36-42（`connection`）
- 代码:
```php
private static ?self $instance = null;

public static function setConnection(\PDO $pdo): self
{
    self::$instance = new self($pdo);    // ← 全局覆盖，无栈、无作用域
    return self::$instance;
}
```
- 问题: 静态单例 + 实例级可变状态（`$table` / `$columns` / `$commands` / `$comment` / `$engine` / `$charset` / `$collation`）：
  (a) **多连接不支持**：主库用 mysql、测试库用 sqlite 时，`setConnection()` 会静默替换前者；
  (b) 与 H3 叠加：`comment` / `engine` / `commands` 在多次 `create()` 之间残留；
  (c) 长驻进程（queue worker）里 PDO 失效后单例不会重建；
  (d) 单元测试无法并行隔离。
- 触发场景: 静态分析即可确认；与 H3 的残留场景组合可实际观测。`tests/run_tests.php` 中 6 处 `Schema::setConnection($pdo)` 全是"每次建新实例"的写法，因此问题从未暴露。
- 建议修复: 去掉单例，改为实例注入 + 容器绑定：
  ```php
  // app/core/Application.php
  $this->container->singleton(\db\Schema::class,
      fn() => \db\Schema::setConnection($this->container->get('db')->getPdo()));
  ```
  调用方改为 `$container->get(\db\Schema::class)`；如需保留 `Schema::connection()`，至少提供 `pushConnection()` / `popConnection()` 形成栈，并在每次 DDL 结束后恢复实例状态。

### [MEDIUM] M11 `create()` 不触发 `saving`，`update()` 不触发 `saving` / `saved` —— 三条写入路径事件序列不一致
- 文件: app/model/Model.php  行号: 147-163（`create`）、165-178（`update`）、496-534（`save`）
- 代码:
```php
// create(): 只触发 creating / created
$this->attributes = $this->filterFillable($data);
if (!$this->fireEvent('creating')) { return 0; }
...
$this->fireEvent('created');

// update(): 只触发 updating / updated
if (!$this->fireEvent('updating')) { return 0; }
...
if ($result > 0) { $this->fireEvent('updated'); }

// save(): 触发 saving → creating/updating → created/updated → saved
if (!$this->fireEvent('saving')) { return 0; }
```
- 问题: 三条写入路径的事件集合完全不一致。任何挂在 `saving` 上做通用逻辑（审计日志、字段规范化、租户注入、防篡改校验）的监听器，在 `create()` / `update()` 路径上**根本不会被调用**，形成一致性与安全缺口。
- 触发场景: 实测：
  ```php
  User::onEvent('saving',   fn($m) => $log[] = 'saving');
  User::onEvent('creating', fn($m) => $log[] = 'creating');
  User::onEvent('updating', fn($m) => $log[] = 'updating');
  User::onEvent('updated',  fn($m) => $log[] = 'updated');

  (new User())->create(['name'=>'q','email'=>'q']);   // => ['creating']             ← saving 缺失
  (new User())->find(1)->update(1, ['name'=>'zz']);   // => ['updating','updated']    ← saving/saved 缺失
  (new User())->find(1)->save();                      // => ['saving','updating','updated','saved'] ✅
  ```
- 建议修复: 让 `create()` / `update()` 委托给 `save()`，保证事件序列唯一：
  ```php
  public function create(array $data): int|string
  {
      foreach ($data as $k => $v) { $this->setAttribute((string)$k, $v); }   // 同时修复 C2
      return $this->save();
  }
  public function updateById(int|string $id, array $data): int
  {
      $instance = new static();
      $instance->attributes[$this->primaryKey] = $id;
      foreach ($data as $k => $v) { $instance->setAttribute((string)$k, $v); }
      return (int) $instance->save();
  }
  ```
  补测试：三条路径触发的事件序列必须一致。

### [MEDIUM] M12 `Model::update()` 覆写整个 `attributes`，未在 `$data` 中的列全部从内存消失
- 文件: app/model/Model.php  行号: 165-178
- 代码:
```php
$this->attributes = $this->filterFillable($data);   // line 167 ← 整体替换，不是 merge
```
- 问题: 模型实例持有的是**完整行**（`newFromBuilder($row)`）。`update()` 把它替换成"仅本次传入的字段"，于是 `email`、`created_at`、`status` 等全部从内存消失。后续 `$model->email`、`toArray()`、事件监听器读属性都会拿到 `null`；若再调用 `save()` 则触发 C3 的重复插入。
- 触发场景: 实测：
  ```php
  $m = (new User())->find(1);      // attributes: id=1, name='a', email='a@x', ...
  $m->update(1, ['name' => 'b']);
  $m->email;      // => null（内存中已丢失；DB 里仍是 'a@x'）
  $m->toArray();  // => ['name'=>'b']  ← 只剩一个字段
  ```
- 建议修复: 改为增量合并（与 C3 一并修）：
  ```php
  $newAttrs = [];
  foreach ($data as $k => $v) {
      if ($this->fillable !== ['*'] && !in_array($k, $this->fillable, true)) { continue; }
      $this->setAttribute((string)$k, $v);
      $newAttrs[(string)$k] = $this->attributes[(string)$k];
  }
  $this->attributes[$this->primaryKey] = $id;
  $result = $this->newQuery()->where($this->primaryKey, '=', $id)
      ->update($this->syncTimestamps($newAttrs, 'update'));
  ```
  补测试：`update()` 后 `$m->email` 必须保持原值。

### [MEDIUM] M13 `SoftDelete::delete()` 软删除后不刷新内存中的 `deleted_at`，`trashed()` 仍返回 `false`
- 文件: app/traits/SoftDelete.php  行号: 128-158（`delete`）、46-49（`trashed`）；对照 56-80（`restore` 会刷新）
- 代码:
```php
if ($this->forceDeleting) {
    $result = $this->db()->where($this->primaryKey, '=', $id)->delete();
} else {
    $result = $this->db()->where($this->primaryKey, '=', $id)
        ->update(['deleted_at' => date($this->dateFormat)]);   // ← 只写库，不更新 $this->attributes
}
if ($result > 0) { $this->fireEvent('deleted'); }
return $result;                                                  // ← 内存状态未同步
```
- 问题: 软删除是"逻辑删除"，但内存中的模型对象仍持有旧行快照，`$model->trashed()` 返回 `false`、`$model->deleted_at` 返回 `null`。同一次请求内后续的 `toArray()`、条件判断、级联逻辑都会用到过期状态。`restore()`（第 74 行）正确地做了 `$this->attributes['deleted_at'] = null;`，说明这是遗漏。
  另：`forceDeleting` 分支的硬删除**也没有**把 `$this->exists` 置为 `false`，删除后的实例仍 `exists === true`；且第 140 行的"已软删则跳过"保护分支因为 `trashed()` 恒为 `false` 而**永远不生效**，同一条记录可被反复软删除并覆盖 `deleted_at`。
- 触发场景: 实测：
  ```php
  $m = (new SoftUser())->find(1);
  $r = $m->delete();                 // => 1
  $m->getAttribute('deleted_at');    // => null     ← 应为 '2026-10-03 12:11:38'
  $m->trashed();                     // => false    ← 应为 true
  $m->exists;                        // => true
  $m->delete();                      // => 1（而不是 0）—— 保护分支失效，deleted_at 被覆盖
  ```
- 建议修复:
  ```php
  if ($this->forceDeleting) {
      $result = $this->db()->where($this->primaryKey, '=', $id)->delete();
      if ($result > 0) { $this->exists = false; }
  } else {
      $now = date($this->dateFormat);
      $result = $this->db()->where($this->primaryKey, '=', $id)->update(['deleted_at' => $now]);
      if ($result > 0) { $this->attributes['deleted_at'] = $now; }
  }
  ```
  补测试：`$m->delete(); $m->trashed()` 必须为 `true`；重复 `delete()` 第二次必须返回 `0`。

### [MEDIUM] M14 `Seeder::register()` 与 `runAll()` 按 `static::class` 分组，子类注册 + 基类运行会静默不执行
- 文件: app/db/Seeder.php  行号: 56-68（`register`）、75-83（`runAll`）、90-93（`getSeeders`）
- 代码:
```php
public static function register(string $seederClass): void
{
    ...
    $caller = static::class;                 // ← 用调用者的类名做分组键
    if (!isset(self::$seeders[$caller])) { self::$seeders[$caller] = []; }
    if (!in_array($seederClass, self::$seeders[$caller], true)) { self::$seeders[$caller][] = $seederClass; }
}
public static function runAll(\db\Connection $db): void
{
    $caller = static::class;                 // ← 同一个键，但只在"同一个类"调用时才匹配
    $seeders = self::$seeders[$caller] ?? [];  // ← 不匹配时静默返回空
    foreach ($seeders as $seederClass) { (new $seederClass($db))->run(); }
}
```
- 问题: 分组键是**晚期静态绑定的调用者类名**。只要注册与执行的调用者不是同一个类，注册的种子就会被静默丢弃（`?? []` 无任何告警）。很容易踩中的组合：`DatabaseSeeder extends Seeder`，在 `DatabaseSeeder` 中写 `public static function registerAll()` 内部调用 `self::register()`（键 = `DatabaseSeeder`），而入口脚本调用 `Seeder::runAll($db)`（键 = `db\Seeder`）→ **一个种子都不跑，也没有任何输出，却返回"成功"**。`docs/quick-start.md:938-940` 恰好用的是基类 `Seeder::register()` + `Seeder::runAll()`（能工作），只换一处调用者就静默失效。
- 触发场景: 实测：
  ```php
  Seeder::register('SeedA');
  Seeder::getSeeders();    // => 1
  DBSeeder::getSeeders();  // => 0    ← 同一个静态属性，子类视角下是另一个键
  ```
  即 `DBSeeder::register('SeedA')` 之后调用 `Seeder::runAll($db)` 会输出空、返回成功。
- 建议修复: 不要用调用者类名做键，改用全局注册表 + 显式分组，并对"无注册项"报错：
  ```php
  private static array $seeders = [];

  public static function register(string $seederClass, ?string $group = null): void
  {
      if (!is_subclass_of($seederClass, self::class)) { throw new \InvalidArgumentException(...); }
      $group ??= self::class;                 // 默认归到基类，不再按调用者分裂
      self::$seeders[$group][] = $seederClass;
      self::$seeders[$group] = array_values(array_unique(self::$seeders[$group]));
  }
  public static function runAll(\db\Connection $db, ?string $group = null): void
  {
      $group ??= self::class;
      $seeders = self::$seeders[$group] ?? [];
      if ($seeders === []) { throw new \RuntimeException("No seeders registered for group '{$group}'."); }
      foreach ($seeders as $cls) { (new $cls($db))->run(); }
  }
  ```
  补测试：`SubSeeder::register()` + `Seeder::runAll()` 必须能跑到。

### [MEDIUM] M15 `where()` 两参数简写把操作符当值，调用方漏写 value 时静默生成错误条件
- 文件: app/db/QueryBuilder.php  行号: 195-233（`where`）、235-273（`whereOr`）；app/model/Model.php 137-145（转发层）
- 代码:
```php
$isThreeArgForm = func_num_args() >= 3;
if (!$isThreeArgForm && $operator !== null) {
    $value = $operator;      // ← 无条件把第 2 参数当作"值"
    $operator = '=';
}
```
- 问题: `func_num_args() >= 3` 是唯一的区分手段，于是 `where('status', '=')`（想写操作符却漏了 value）会被解释成 **`status = '='`**，去数据库里找字面量等于 `=` 的行。不报错，只是安静地返回空/错误结果。`where('name','LIKE')` 同理变成 `name = 'LIKE'`。`Model::where()` 转发层（第 141-144 行）用同样的 `func_num_args()` 转发，把问题一并传下去。
- 触发场景: 实测：`$model->where('g','=')->getSql();` → `'SELECT * FROM `t` WHERE `g` = :w_0'`，且绑定的值是字符串 `'='`。调用方本意是"未写完的代码"，结果是一条匹配不到任何行的查询。
- 建议修复: 对已知操作符做歧义检测，命中即抛异常而不是猜测：
  ```php
  if (!$isThreeArgForm && $operator !== null) {
      $maybeOp = strtoupper(trim((string)$operator));
      if (in_array($maybeOp, self::ALLOWED_OPERATORS, true)) {
          throw new \InvalidArgumentException(
              "Ambiguous where('{$column}', '{$operator}'): looks like an operator, value is missing. Use where('{$column}', '=', \$value)."
          );
      }
      $value = $operator;
      $operator = '=';
  }
  ```
  或引入显式 `whereEq()` / `whereOp()`，并在文档中标注两参形式仅用于值。

### [MEDIUM] M16 `syncTimestamps()` 无 `$timestamps` 开关，模型默认强制写入 `created_at` / `updated_at`
- 文件: app/model/Model.php  行号: 574-592（`syncTimestamps`）、147-163（`create`）、496-534（`save`）
- 代码:
```php
protected function syncTimestamps(array $data, string $type): array
{
    $now = date($this->dateFormat);
    if ($type === 'create') {
        if (!isset($data['created_at'])) { $data['created_at'] = $now; }   // ← 无条件
        if (!isset($data['updated_at'])) { $data['updated_at'] = $now; }
    }
    if ($type === 'update' && !isset($data['updated_at'])) { $data['updated_at'] = $now; }
    return $data;
}
```
- 问题: 没有 Laravel 式的 `public $timestamps = true/false` 开关。任何继承 `Model` 且表里没有 `created_at` / `updated_at` 列的模型（关联表、日志表、KV 表、外部遗留表）**无法创建/更新**，直接抛 `no such column`。也没有提供时间列名自定义。
- 触发场景: 实测：`class CM extends \model\Model { protected string $table='c'; protected array $fillable=['*']; }` 后 `(new CM())->create(['flag'=>'false'])` → `PDOException: SQLSTATE[HY000]: General error: 1 table c has no column named created_at`。
- 建议修复: 增加开关并让列名可配：
  ```php
  protected bool $timestamps = true;
  protected string $createdAtColumn = 'created_at';
  protected string $updatedAtColumn = 'updated_at';

  protected function syncTimestamps(array $data, string $type): array
  {
      if (!$this->timestamps) { return $data; }
      $now = date($this->dateFormat);
      if ($type === 'create') {
          $data[$this->createdAtColumn] ??= $now;
          $data[$this->updatedAtColumn] ??= $now;
      } elseif ($type === 'update') {
          $data[$this->updatedAtColumn] ??= $now;
      }
      return $data;
  }
  ```
  补测试：`protected bool $timestamps = false;` 的模型在无时间列的表上能正常 create/update。

### [MEDIUM] M17 `Model::toArray()` 用 `spl_object_id()` 做循环检测，同一实例被多个关联引用时静默丢数据
- 文件: app/model/Model.php  行号: 406-443（重点 408-414、429-437、440-442）
- 代码:
```php
public function toArray(): array
{
    static $visited = [];          // ← 函数级 static
    $oid = spl_object_id($this);
    if (isset($visited[$oid])) { return []; }     // ← 命中即返回空数组
    $visited[$oid] = true;
    try {
        ...
        foreach ($this->relations as $name => $value) {
            if ($value instanceof self) { $data[$name] = $value->toArray(); }
            elseif (is_array($value)) { $data[$name] = array_map(fn($v) => $v instanceof self ? $v->toArray() : $v, $value); }
        }
        return $data;
    } finally { unset($visited[$oid]); }
}
```
- 问题: 用 `spl_object_id()` 同时承担"循环检测"和"去重"两个职责，二者无法区分：
  (a) **真正的循环**（`$user->posts()[0]->author() === $user`）确实需要返回 `[]`；
  (b) **DAG 中的共享节点**（同一个模型实例被挂在两个 relation 名下，例如 `author` 与 `editor` 指向同一人）会被误判为循环，第二个 relation 序列化成 `[]`——**静默丢数据**，无任何警告。
  另：`spl_object_id()` 在对象回收后会复用；虽然 `finally` 及时清理避免了主要风险，但把"共享"和"循环"混为一谈的语义问题是根本性的。
- 触发场景:
  ```php
  $m2 = (new User())->find(1);
  $m = new Post();
  $m->relations['author'] = $m2;
  $m->relations['editor'] = $m2;      // 同一实例
  $m->toArray();
  // => ['author' => [...], 'editor' => []]     ← editor 被误吞
  ```
  （`belongsToMany()` 每行 `newFromBuilder` 新建实例，故默认路径不会触发；一旦模型被缓存/复用即暴露。）
- 建议修复: 用深度参数替代全局 `visited`，并把严格循环检测做成可选开关：
  ```php
  private const MAX_SERIALIZE_DEPTH = 8;

  public function toArray(?SplObjectStorage $seen = null, int $depth = 0): array
  {
      if ($depth > self::MAX_SERIALIZE_DEPTH) { return []; }        // 深度保护（替代 spl_object_id）
      ...
      $data[$name] = $value->toArray(null, $depth + 1);
      ...
  }
  // 需要严格环检测时由调用方传入 $seen：
  //   $seen = new \SplObjectStorage(); if ($seen->contains($this)) { return []; } $seen->attach($this);
  ```

### [MEDIUM] M18 关联查询在本地键为 `null` 时退化为 `IS NULL`；`eagerLoad` 的分组键 `?? 0` 与 `null` 不一致
- 文件: app/model/Model.php  行号: 243-254（`hasOne`）、265-276（`hasMany`）、286-297（`belongsTo`）、309-322（`belongsToMany`）、332-400（`eagerLoad`，重点 360、366-368、390、395）
- 代码:
```php
// hasOne / hasMany / belongsTo
$result = $instance->newQuery()
    ->where($foreignKey, '=', $this->getAttribute($localKey))   // getAttribute 可能返回 null
    ->fetch();
// QueryBuilder::where() 见到 null 会转成 IS NULL  ← 于是变成 "WHERE user_id IS NULL"，返回一批无关行

// eagerLoad
$grouped[$rm[$foreignKey] ?? 0][] = $instance->newFromBuilder($rm);   // line 360：null → 键 0
...
$model->relations[$relation] = $grouped[$key] ?? [];                 // line 368：$key 可能为 null → 键 ''
```
- 问题: 三类边界缺陷：
  (a) **关联查询未做 null 短路**：`getAttribute($localKey)` 返回 `null` 时（新实例、字段未加载、外键为空），`where('user_id','=',null)` 被转成 `IS NULL`，把**全表所有外键为空的行**当作关联结果返回。正确行为应直接返回 `null` / `[]`。
  (b) `eagerLoad` 分组用 `?? 0` 产生整数键 `0`，匹配时 `$key` 为 `null` 时 PHP 数组键规范化为 `""`，二者**永远不相等** → 关联结果被静默丢弃为 `[]`（"预加载写了但一个都没生效"的典型症状）。
  (c) `belongsToMany` 无去重、无 pivot 数据回填、无 `attach` / `detach` / `sync`，功能不完整。
- 触发场景: 实测：`$p = new P2(); $p->user_id = null; $p->owner();` → `null`（碰巧正确，因为 `users.id` 无 NULL）。但换成 `hasMany('user_id')` 且表中存在 `user_id IS NULL` 的行时，会把这些孤儿行全部返回而非 `[]`。`eagerLoad` 的键不一致问题同理：分组存入键 `0`，匹配用键 `''`，结果恒为 `[]`。
- 建议修复:
  ```php
  protected function hasMany(string $related, ?string $foreignKey = null, ?string $localKey = null): array
  {
      $instance = new $related();
      $foreignKey = $foreignKey ?? $this->getForeignKey();
      $localKey   = $localKey   ?? $this->primaryKey;
      $localValue = $this->getAttribute($localKey);
      if ($localValue === null) { return []; }        // ← 短路
      return array_map(fn($row) => $instance->newFromBuilder($row),
          $instance->newQuery()->where($foreignKey, '=', $localValue)->fetchAll());
  }
  ```
  `hasOne` / `belongsTo` 短路返回 `null`。`eagerLoad` 统一键规范化：
  ```php
  $fk = $rm[$foreignKey] ?? null;
  if ($fk === null) { continue; }                     // 不给 NULL 外键建组
  $grouped[(string)$fk][] = $instance->newFromBuilder($rm);
  ...
  $k = $model->getAttribute($localKey);
  $model->relations[$relation] = $k === null ? [] : ($grouped[(string)$k] ?? []);
  ```
  补测试：本地键为 `null` 时 `hasMany` 返回 `[]`、`hasOne` 返回 `null`；关联行外键为 `NULL` 时 `eagerLoad` 不报错且非空外键正常挂载。

---

## LOW (15)

### [LOW] L1 `getSql()` / `execute()` 在 INSERT / UPDATE 构建器上语义混乱
- 文件: app/db/QueryBuilder.php  行号: 1030-1045（`execute` / `getSql`）、550-596（`buildSelect`）
- 代码:
```php
public function execute(): int
{
    $sql = $this->buildSelect();     // ← 永远编译成 SELECT
    $stmt = $this->pdo->prepare($sql);
    return $stmt->execute($this->bindings) ? $stmt->rowCount() : 0;
}
public function getSql(): string { return $this->buildSelect(); }
```
- 问题: `insert()` / `update()` 调用后，builder 上已积累了 `:u_*` / `:i_*` 绑定，此时 `getSql()` 仍返回一条不含这些占位符的 SELECT，容易误导调试与日志。对非 raw builder 调 `execute()` 会真的执行一条 SELECT 并返回 `rowCount()`（MySQL 下恒为 0），与"执行语句"的语义不符。仅 `Connection::execute()` 走 raw 模式时行为正确。
- 触发场景: 实测：`(new Connection($pdo))->table('users')->execute();` → `0`（实际执行了一条 SELECT）。
- 建议修复: 记录待执行语句，或对非 SELECT builder 抛异常：
  ```php
  public function getSql(): string
  {
      return $this->pendingStatement ?? $this->buildSelect();
  }
  ```

### [LOW] L2 `raw('')` 空 SQL 未做前置校验，抛出未包装的 `ValueError`
- 文件: app/db/QueryBuilder.php  行号: 542-548（`raw`）、550-554（`buildSelect`）
- 代码:
```php
public function raw(string $sql, array $bindings = []): self
{
    $this->isRaw = true;
    $this->where = [$sql];      // ← 不校验 $sql 是否为空
```
- 问题: `raw('')` 会让 `buildSelect()` 返回 `''`，随后 `PDO::prepare('')` 在 PHP 8 下抛 `ValueError: PDO::prepare(): Argument #1 ($query) must not be empty`。这是 PHP 的 `ValueError`（不是 `PDOException`），与 `Connection` / `Schema` 中 `\PDOException` 的捕获逻辑不一致，异常类型对调用方不统一。
- 触发场景: 实测：`(new QueryBuilder($pdo))->raw('')->fetchAll();` → `ValueError: PDO::prepare(): Argument #1 ($query) must not be empty`
- 建议修复: 在 `raw()` 入口加 `if (trim($sql) === '') { throw new \InvalidArgumentException('Raw SQL cannot be empty.'); }`。

### [LOW] L3 `validateColumnName()` 允许 `*`，使 `*` 可以进入 where / orderBy / having / groupBy
- 文件: app/db/QueryBuilder.php  行号: 157-162
- 代码:
```php
private function validateColumnName(string $column): void
{
    if (!preg_match('/^[a-zA-Z0-9_\.\*]+$/', $column)) { throw ... }
}
```
- 问题: `*` 只在 `select()` 中才有意义，但当前所有需要列名的方法（`where` / `whereNull` / `whereBetween` / `orderBy` / `groupBy` / `having` / `findBy`）都放行 `*`。虽然 `sanitizeColumn()` 会在非 select 路径上抛异常（不至于生成坏 SQL），但错误信息是"Invalid column name"，且对 `'users.*'` 这种合法 select 写法也会误伤（见 H2）。这是"白名单过宽 + 两处规则不一致"的根源。
- 触发场景: `$qb->where('id*', '=', 1);` 通过 `validateColumnName()`，随后在 `sanitizeColumn()` 处抛 `InvalidArgumentException`；`$qb->select(['users.*'])` 同样被误伤。
- 建议修复: 拆成两个校验器：`assertSelectableColumn()`（允许 `table.*`）与 `assertColumn()`（不允许 `*`），分别用于 select 与其余场景。

### [LOW] L4 `orderByRaw()` 完全不校验表达式，是设计上的 SQL 注入入口
- 文件: app/db/QueryBuilder.php  行号: 387-395（`orderByRaw`）、931-954（`whereRaw`）
- 代码:
```php
public function orderByRaw(string $expression): self
{
    if ($this->orderBy !== '') { $this->orderBy .= ', ' . $expression; }
    else { $this->orderBy = $expression; }
    return $this;
}
```
- 问题: 这是 raw 方法的应有行为，但目前**没有任何文档警示或命名隔离**（`orderByRaw` 与 `whereRaw` 混在同一命名空间），开发者极易把用户输入直接拼进去：`(new User())->orderByRaw($request->get('sort'))` 即为注入点。`groupBy()` 是白名单化的（安全），二者不对称容易误判安全边界。
- 触发场景: 静态分析即可确认；`docs/api.md` 中未对 `orderByRaw` 做任何警示。
- 建议修复: 保留功能但在 docblock 标注 `# 警告：绝不可传入未转义的用户输入`；补一个参数化版本 `orderBySubQuery(string $sql, array $bindings)` 供动态排序；并在 `docs/api.md` 增加"raw 方法的安全边界"一节。

### [LOW] L5 `Connection` 缺少 `transaction(callable)` 闭包助手；异常后 `transactionLevel` 可能与 PDO 状态漂移
- 文件: app/db/Connection.php  行号: 161-222
- 代码:
```php
public function beginTransaction(): bool { ...; $this->transactionLevel++; return $result; }
public function commit(): bool   { if ($this->transactionLevel <= 0) { return false; } ... }
public function rollback(): bool { if ($this->transactionLevel <= 0) { return false; } ... }
```
- 问题: 两个层面：
  (a) **无 `transaction(callable)` 闭包助手**。Laravel 风格的 `$db->transaction(fn() => ...)` 能保证异常时自动回滚，这里必须手写 `try/catch`，漏写就是脏事务。（嵌套 SAVEPOINT 逻辑本身**已验证正确**，见文末清单。）
  (b) **`transactionLevel` 不复位**：若某段代码在 `$level = 1` 时直接调 `$this->pdo->rollBack()`（绕过 `Connection::rollback()`），`transactionLevel` 会停在 1，之后的 `commit()` 会误发 `RELEASE SAVEPOINT sp_1` → MySQL 报 `SAVEPOINT does not exist`。`beginTransaction()` 第 163-165 行的自愈分支只在"下一次 begin"时才触发。
- 触发场景: 实测（正常路径全部正确，问题只在绕过 API 时出现）：
  ```php
  $c->beginTransaction(); $c->beginTransaction();
  $c->rollback(); $c->commit();      // rows = [id 1]  ✅ SAVEPOINT 嵌套正确
  $c->commit(); $c->commit();        // [false, false] ✅ 溢出安全
  // 但 $c->getPdo()->rollBack() 之后再 $c->commit() → SAVEPOINT sp_1 does not exist
  ```
- 建议修复: 增加 `transaction(callable $cb)` 与 `transactionLevel(): int` 公共接口，统一异常出口：
  ```php
  public function transaction(callable $cb): mixed
  {
      $this->beginTransaction();
      try { $result = $cb($this); $this->commit(); return $result; }
      catch (\Throwable $e) { $this->rollback(); throw $e; }
  }
  ```

### [LOW] L6 `Model::__call()` 代理白名单不全，链式 API 覆盖面小于 `QueryBuilder`
- 文件: app/model/Model.php  行号: 655-673
- 代码:
```php
$proxiedMethods = ['whereIn', 'whereOr', 'whereNull', 'whereNotNull', 'whereBetween',
    'orderBy', 'groupBy', 'having', 'limit', 'leftJoin', 'rightJoin',
    'join', 'count', 'sum', 'avg', 'max', 'min', 'chunk', 'first',
    'fetch', 'fetchAll', 'value'];
```
- 问题: `QueryBuilder` 有但白名单漏掉的：`distinct`、`whereRaw`、`chunkById`、`pluck`、`forUpdate`、`lock`、`when`、`reset`、`getBindings`、`offset`。调用 `$model->distinct()` 会先落到 scope 检查再抛 `BadMethodCallException`，错误信息是"方法不存在"，与"实际存在但未代理"不符。缺 `offset()` 意味着模型层无法单独设置偏移。
- 触发场景: `$model->distinct();` → `BadMethodCallException: Method User::distinct does not exist`。
- 建议修复: 白名单改为按 `QueryBuilder` 的 public 方法自动生成并加黑名单：
  ```php
  $proxiedMethods = array_values(array_diff(
      get_class_methods(QueryBuilder::class),
      ['insert', 'update', 'delete', 'raw', 'reset', 'setCacheFor', 'getCacheFor', '__construct']
  ));
  ```
  同时给 `QueryBuilder` 补 `offset(int $offset): self`。

### [LOW] L7 `castAttribute()` 的 JSON / bool 转换在边界输入上语义偏差且静默
- 文件: app/model/Model.php  行号: 594-610
- 代码:
```php
return match ($this->casts[$key]) {
    'bool', 'boolean' => (bool) $value,        // ← 'false' 字符串 → true
    'array' => $value === null ? null : (is_array($value) ? $value : (json_decode($value, true) ?? [])),
    'json'  => $value === null ? null : (is_string($value) ? json_decode($value, true) : $value),
    'date'  => ($ts = strtotime((string) $value)) !== false ? date('Y-m-d', $ts) : null,
    'datetime' => ($ts = strtotime((string) $value)) !== false ? date($this->dateFormat, $ts) : null,
    default => $value
};
```
- 问题: 四个静默偏差：
  (a) `(bool) 'false'` → `true`（PHP 字符串转布尔语义），DB 里存 `'false'` 时结果错误；
  (b) `'array'` 对非法 JSON 用 `?? []` 兜底 → **损坏数据被伪装成空数组**，调用方无法区分"本来就是空"与"解析失败"；
  (c) `'json'` 对非法 JSON 直接返回 `null`，同样无告警；
  (d) 不支持 `serialize` / `decimal` / 自定义闭包 cast，也不支持嵌套路径（`meta->a`）。
- 触发场景: `$row['flag'] = 'false'` + `casts['flag'] = 'bool'` → `getAttribute('flag') === true`。
- 建议修复:
  ```php
  'bool', 'boolean' => in_array($value, [null, '', '0', 'false', 'FALSE', 'no'], true) ? false : (bool) $value,
  'array' => $value === null ? null : (is_array($value) ? $value : self::decodeJson($value, $key, [])),
  // 自定义闭包
  if (($fn = $this->casts[$key] ?? null) instanceof \Closure) { return $fn($value, $this); }
  ```
  其中 `decodeJson()` 在 `json_last_error() !== JSON_ERROR_NONE` 时抛出可识别的异常。

### [LOW] L8 `Blueprint` 的长度 / 精度参数无校验，可生成非法 DDL
- 文件: app/db/Schema.php  行号: 260-263（`string`）、295-298（`decimal`）
- 代码:
```php
public function string(string $name, int $length = 255): self
{ return $this->addColumn("`{$name}`", "VARCHAR({$length})"); }
public function decimal(string $name, int $precision = 10, int $scale = 2): self
{ return $this->addColumn("`{$name}`", "DECIMAL({$precision},{$scale})"); }
```
- 问题: 参数直接插值，无边界校验（类型是 `int`，无注入风险，但会生成非法 DDL）。错误只在执行 DDL 时暴露，并被 `Schema::execute()` 包装成 `DatabaseException`，错误信息夹带整段 SQL，不易定位。
- 触发场景: 实测：`(new \db\Blueprint('t'))->string('a', 0)->getColumns()` → `["`a` VARCHAR(0)"]`；`->string('a', -5)` → `["`a` VARCHAR(-5)"]`（非法 DDL）；`->decimal('a', 10, -2)` → `["`a` DECIMAL(10,-2)"]`。
- 建议修复: `if ($length < 1 || $length > 65535) { throw new \InvalidArgumentException("VARCHAR length out of range: {$length}"); }`；`decimal` 校验 `$precision >= 1 && $scale >= 0 && $scale <= $precision`。

### [LOW] L9 `Blueprint::modifyColumn()` 在无前置列时静默 no-op，与 `unique()` / `index()` 抛异常不一致
- 文件: app/db/Schema.php  行号: 577-588（`modifyColumn`）、399-417（`unique`/`index` 已抛 `RuntimeException`）
- 代码:
```php
private function modifyColumn(string $modifier, bool $insertAfterName = false): self
{
    if ($this->lastColumn !== null) {   // ← 不满足时直接返回，什么也不做
        ...
    }
    return $this;
}
```
- 问题: `nullable()` / `notNull()` / `default()` / `unsigned()` / `comment()` / `after()` 全部经由 `modifyColumn()`。在没有任何列定义的 `Blueprint` 上调用它们会**静默返回**，用户以为修饰符已生效、实际 DDL 里根本没有，排查成本极高。同类的 `unique()` / `index()` 已被修成抛 `RuntimeException`（见 CHANGELOG 历史条目），二者行为不一致，说明 `modifyColumn` 是漏改的。
- 触发场景: 实测：`(new \db\Blueprint('t'))->nullable();` → `getColumns() === []`（静默丢弃）；而 `(new \db\Blueprint('t'))->unique();` → 抛 `RuntimeException`（已修）。
- 建议修复: 与 `unique()` 对齐：
  ```php
  private function modifyColumn(string $modifier, bool $insertAfterName = false): self
  {
      if ($this->lastColumn === null || $this->columns === []) {
          throw new \RuntimeException('Column modifiers must follow a column definition (e.g., $table->string("name")->nullable()).');
      }
      ...
  }
  ```
  补测试：`$b = new \db\Blueprint('t'); $b->nullable();` 必须抛 `RuntimeException`。

### [LOW] L10 `Blueprint::change()` / `dropColumn()` / `renameColumn()` 生成 MySQL-only 方言，SQLite 上必然失败
- 文件: app/db/Schema.php  行号: 514-522（`change`）、524-543（`dropColumn`/`renameColumn`）、176-187（`compileAlter`）
- 代码:
```php
public function change(): self
{
    ...
    $this->columns[$idx] = 'MODIFY COLUMN ' . $this->columns[$idx];   // MySQL 专有
}
```
- 问题: `MODIFY COLUMN` 是 MySQL 专有语法（SQLite 需 `RENAME COLUMN` 或重建表），`DROP COLUMN` 在旧版 SQLite 上也不支持。`Blueprint` 构造函数接收 `$driver`（第 241 行）却完全不用。SQLite 上调用会在 DDL 执行阶段抛 `DatabaseException`，且错误信息夹带整段 SQL。
- 触发场景: 实测：`(new \db\Blueprint('t'))->string('a')->change()->getColumns();` → `["MODIFY COLUMN `a` VARCHAR(255)"]`；在 SQLite 上 `compileAlter()` 后执行报错。
- 建议修复: 按 driver 生成方言，或在不支持的组合上直接抛明确异常：
  ```php
  public function change(): self
  {
      if ($this->driver === 'sqlite') {
          throw new UnsupportedOnDriverException(
              'change() is not supported on SQLite; use a fresh migration (migrate:fresh) to apply column type changes.'
          );
      }
      ...
  }
  ```

### [LOW] L11 `Connection` 用 `$options + $userOptions` 合并，用户无法覆盖默认 PDO 选项
- 文件: app/db/Connection.php  行号: 44-52；app/config/database.php 22-25
- 代码:
```php
$options = [
    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
    \PDO::ATTR_EMULATE_PREPARES => false,
];
if (!empty($this->config['options']) && is_array($this->config['options'])) {
    $options = $options + $this->config['options'];   // ← 左侧优先，用户配置被忽略
}
```
- 问题: PHP 的 `+` 数组并集**保留左操作数的同名键**，因此 `app/config/database.php` 里想覆盖 `ATTR_EMULATE_PREPARES` / `ATTR_ERRMODE` / `ATTR_DEFAULT_FETCH_MODE` 的配置会被静默丢弃。而配置注释（database.php:22-25）写着"options 数组中的值会合并到默认 PDO 选项之上"，语义与实现相反，会误导使用者。
- 触发场景: 实测：`new \db\Connection(['driver'=>'sqlite','database'=>':memory:','options'=>[PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT]])` 后 `$c->getPdo()->getAttribute(PDO::ATTR_ERRMODE)` 返回 `2`（ERRMODE_EXCEPTION），用户配置被忽略。
- 建议修复: 改为 `array_merge($options, $this->config['options'])`（用户优先），并把配置注释改成"会覆盖默认 PDO 选项"；同时对 `ATTR_ERRMODE` 被覆盖的情况打一条 warning，因为框架内部多处依赖 `ERRMODE_EXCEPTION`（例如 `Migration::getAll()` 的 `=== false` 判断其实是无效防御，见 M8）。

### [LOW] L12 SQLite 文件路径正则允许 `:` 与 `..`，配置被污染时可越出预期目录
- 文件: app/db/Connection.php  行号: 55-64
- 代码:
```php
if (!preg_match('/^[a-zA-Z0-9_.\/\\\\:-]+$/', $database)) {
    throw new \InvalidArgumentException("Invalid database path: {$database}");
}
$dsn = "sqlite:{$database}";
```
- 问题: 字符集包含 `:`（PDO DSN 的分隔符）与 `.`（因此 `../` 可通过）。虽然值来自 `env()` 而非用户输入，风险等级低，但一旦配置来源被污染（`.env` 被覆盖、配置中心下发、`DB_SQLITE_PATH` 接受外部输入），就能指向任意本地路径，PDO 会在该路径创建/读写文件；`getDatabase()` 还会返回未规范化路径。
- 触发场景: `new \db\Connection(['driver'=>'sqlite','database'=>'../../../etc/anything'])` 通过校验并尝试建库；`new \db\Connection(['driver'=>'sqlite','database'=>'x:extra'])` 生成 `sqlite:x:extra` 这种畸形 DSN（行为由驱动决定，跨平台不一致）。
- 建议修复: 归一化并限定根目录：
  ```php
  $base = defined('STORAGE_PATH') ? realpath(STORAGE_PATH) : getcwd();
  $real = realpath($database) ?: $database;
  if ($base !== false && !str_starts_with($real, $base)) {
      throw new \InvalidArgumentException("SQLite database must live under STORAGE_PATH: {$database}");
  }
  if (str_contains($database, ':')) {
      throw new \InvalidArgumentException("Colon is not allowed in SQLite path: {$database}");
  }
  ```
  （`:memory:` 已在第 57 行提前返回，不受影响。）

### [LOW] L13 `Seeder::call()` / `runAll()` 无循环保护，且不校验子类构造函数签名
- 文件: app/db/Seeder.php  行号: 41-48（`call`）、75-83（`runAll`）
- 代码:
```php
public function call(string $seederClass): void
{
    if (!is_subclass_of($seederClass, self::class)) { throw new \InvalidArgumentException(...); }
    $seeder = new $seederClass($this->db);
    $seeder->run();          // ← 无递归深度 / 循环检测
}
```
- 问题: (a) `ASeeder::run()` 里 `$this->call(B::class)`、`B::run()` 里 `$this->call(A::class)` 会无限递归直至栈溢出；(b) `new $seederClass($this->db)` 假定所有 Seeder 子类构造函数都恰好接受 1 个 `\db\Connection` 参数，一旦子类自定义了不同签名，会抛 `ArgumentCountError` 这种难以定位的致命错误（实测 `eval('class MySeeder extends \db\Seeder {}')` 即因抽象方法 + 构造不匹配而 fatal）；(c) `runAll()` 中任一 Seeder 抛异常会中断整个批次，无汇总报告。
- 触发场景: 循环 `call()` → 无限递归 → `Allowed memory size exhausted`。
- 建议修复:
  ```php
  private array $callStack = [];
  public function call(string $seederClass, int $depth = 0): void
  {
      if ($depth > 8) { throw new \RuntimeException('Seeder call depth exceeded (circular call?).'); }
      if (isset($this->callStack[$seederClass])) {
          throw new \RuntimeException("Circular seeder call detected: {$seederClass}");
      }
      $this->callStack[$seederClass] = true;
      $ctor = (new \ReflectionClass($seederClass))->getConstructor();
      if ($ctor !== null && $ctor->getNumberOfParameters() !== 1) {
          throw new \InvalidArgumentException("Seeder {$seederClass} must accept a single \\db\\Connection argument.");
      }
      (new $seederClass($this->db))->run();
      unset($this->callStack[$seederClass]);
  }
  ```
  并让 `runAll()` 逐个 try/catch 收集失败项，最后汇总抛出。

### [LOW] L14 `HasModelEvents`：监听器收不到事件名；observer 静态强引用在长驻进程中泄漏
- 文件: app/traits/HasModelEvents.php  行号: 68-93（`observe`）、117-129（`fireEvent`）
- 代码:
```php
static::$observers[$key][$className] = $observer;              // line 84 —— 静态数组持强引用
...
foreach ($listeners as $callback) {
    if (call_user_func($callback, $this) === false) { return false; }   // line 123
}
```
- 问题: (a) 监听器回调签名只有 `($model)`，**没有 `$eventName`**——一个监听器注册到多个事件时无法区分当前是哪个（只能靠闭包外部变量）；(b) `static::$observers` 持有 observer 实例的强引用，长驻进程（queue worker / Octane）里注册的 observer 永不释放，除非显式 `flushEventListeners()`；(c) `=== false` 是严格判断（与 Laravel 一致），返回 `0` / `''` / `null` 的监听器**不会**取消操作，属易踩的语义陷阱；(d) `observe()` 用 `$observer::class` 去重，传入两个不同实例时只生效第一个。
- 触发场景: `User::onEvent('creating', $cb); User::onEvent('created', $cb);` 同一 `$cb` 无法区分当前事件；queue worker 中每次任务 `observe()` 都会新增一条引用。
- 建议修复: 回调签名扩展为 `($model, string $event)`（用 `func_num_args()` 向后兼容）；observer 改为存类名 + 惰性 `new`，或用 `WeakReference`；补 `Model::flushAllEventListeners()` 供 worker 每次任务前调用。

### [LOW] L15 `getForeignKey()` 对复数 / 驼峰类名生成不合理的键名；`Model::all()` 无上限
- 文件: app/model/Model.php  行号: 622-626（`getForeignKey`）、126-130（`all`）
- 代码:
```php
protected function getForeignKey(): string
{
    $class = basename(str_replace('\\', '/', static::class));
    return lcfirst($class) . '_id';        // UserRole → userRole_id（期望 user_role_id）
}
public function all(): array
{
    return array_map(fn($row) => $this->newFromBuilder($row), $this->newQuery()->fetchAll());
}
```
- 问题: (a) `lcfirst()` 只改首字母：`UserRole` → `userRole_id`、`APIClient` → `aPIClient_id`。而 `Blueprint::foreign()` / `references()` 等处普遍使用小写列名约定（`app/db/Schema.php:453-470` 的正则虽允许大写，但 `sanitizeColumn()` 会原样保留大小写），键名与列名在大小写敏感的配置下可能对不上，产生 `Unknown column`。(b) `all()` **无 LIMIT**，直接全表载入内存；一旦表有百万行会 OOM，且 `Model::__call` 也不代理 `paginate`。
- 触发场景: `class UserRole extends Model {}` → `getForeignKey()` 返回 `userRole_id`，而 schema 里通常是 `user_role_id` → `hasMany()` 生成 `WHERE userRole_id = ?`，MySQL 8 默认大小写敏感列名时报 `Unknown column`。
- 建议修复:
  ```php
  protected function getForeignKey(): string
  {
      $base = basename(str_replace('\\', '/', static::class));
      $snake = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $base));   // UserRole → user_role
      return $snake . '_id';
  }
  public function all(int $limit = 0, int $offset = 0): array
  {
      $q = $this->newQuery();
      if ($limit > 0) { $q->limit($limit, $offset); }
      return array_map(fn($row) => $this->newFromBuilder($row), $q->fetchAll());
  }
  ```
  并在 `docs/api.md` 中推荐大数据量场景一律用 `paginate()`。

---

## 已验证正确清单

以下项经逐行阅读 + 实测复现，**确认无缺陷**，列出以避免后续重复排查：

### SQL 注入面
- `QueryBuilder::validateTableName()`（93-98）、`Schema::sanitizeName()`（130-137）、`Blueprint` 各标识符校验均使用**锚定**正则 `/^[a-zA-Z0-9_]+$/`（`\w` 子集，无换行/空格/引号可穿）。
- `sanitizeColumn()` 在不匹配白名单时**直接抛异常**（第 148 行），已移除早期"回退返回原始值"的危险实现。
- 所有值一律走 PDO 命名占位符（`ATTR_ERRMODE=EXCEPTION` + `ATTR_EMULATE_PREPARES=false`，即真实预处理）。实测 `where('name','x')` 生成 `` WHERE `name` = :w_0 ``，绑定值不出现在 SQL 文本中。
- `Blueprint::comment()` / `default()` / `enum()` / `Schema::comment()` 的引号转义正确。实测：`comment("x', extra TEXT); -- ")` → `COMMENT 'x'', extra TEXT); -- '`；`enum(["x'","y"])` → `ENUM('x''','y')`；`default("x', extra INT) -- ")` → `DEFAULT 'x'', extra INT) -- '`，均无法逃逸。
- `Blueprint` 列名注入被拦截：实测 `string('a` , `b INT) -- ')` → `InvalidArgumentException: Invalid column name`。
- `JOIN` 的表名 / 操作符 / 类型三重白名单（第 344-361 行），操作符仅 `= < > <= >= <>`，类型仅 `INNER LEFT RIGHT CROSS`，均用 `in_array(..., true)` 严格比较。
- `Migration::run()` 在 `require_once` 前用**锚定**正则过滤文件名（`/^\d{4}_\d{2}_\d{2}_\d{6}_\w+\.php$/`、`/^\d+_\w+\.php$/`），`\w` 不含 `/` 与 `.`，无路径穿越。
- `Migration::record()` / `delete()` / `getLastBatch()` 全部使用 `?` 位置占位符；`getLastBatch()` 的 `LIMIT {$steps}` 由 `max(1, $steps)` + `int` 声明类型双重保证。

### 占位符与绑定一致性
- 自动占位符编号（`:w_N` / `:h_N` / `:u_N` / `:i_N` / `:wr_N` / `:w_N_or_M`）在各方法内基于 `count($this->bindings)` 单调递增，实测未发现碰撞。
- 五个聚合方法在清空 `having` 后都调用了 `clearHavingBindings()`（第 772-779 行）。实测 `->having('status','>',0)->count()` → `2`、`->min('score')` → `10`、`->sum('score')` → `30.0`、`->avg()` 均正常，**未出现 `HY093 Invalid parameter number`**。
- `count()` 在有 `GROUP BY` 时走子查询包裹且**保留 having 绑定**：实测 `->groupBy('status')->having('status','>',0)->count()` → `2`（正确）。
- `whereBetween()` 在已有 where 绑定后编号正确：实测 `->where('id','>',0)->whereBetween('id',1,5)` → `` WHERE `id` > :w_0 AND `id` BETWEEN :w_1 AND :w_2 ``。
- `insert()` / `update()` 在已有 where 绑定后 `:i_N` / `:u_N` 继续递增：实测 `->where('status','=',1)->update(['name'=>'zz'])` 正确命中并更新 1 行，`rowCount()` 正确。

### 空值 / 边界语义
- `where()` 对 `null` 的处理完全正确（第 217-227 行）：`= null` / `LIKE null` → `IS NULL`；`!=` / `<>` / `NOT LIKE` + null → `IS NOT NULL`；其余操作符（`<` `>` `<=` `>=`）+ null 抛 `InvalidArgumentException`。`whereOr()` 与 `having()` 同构处理（第 251-261、412-422 行）。
- `whereIn()` 对空数组生成恒假条件 `0 = 1`；对含 `null` 的数组正确拆分为 `col IN (...) OR col IS NULL`（实测 `whereIn('id',[null])` → `` WHERE `id` IS NULL ``），避免了 `IN (1, NULL)` 永不匹配的经典陷阱。
- `whereBetween()` 对 `null` 边界抛 `InvalidArgumentException`（第 330-332 行）。
- `buildUpdate()` / `buildDelete()` 在无 WHERE 时抛 `RuntimeException`（防全表误更新/误删），实测有效。
- `insert()` / `update()` 对空数据抛 `InvalidArgumentException`；`buildSelect()` 在无表名时抛 `RuntimeException`（实测 `(new QueryBuilder($pdo))->getSql()` → `RuntimeException: No table name specified`）。
- `limit()` 对负数抛 `InvalidArgumentException`；`chunk()` / `chunkById()` 对 `< 1` 的尺寸也做了校验。
- `paginate()` 对 `$perPage` / `$page` 做了 `max(1, ...)` 兜底；`total = 0` 时 `last_page = 1`、`has_more = false`，无除零风险。
- raw 模式下 `insert()` / `update()` / `count()` / `sum()` 均抛 `RuntimeException`（`assertNotRaw` / 显式检查），`buildDelete()` 也在 raw 模式抛异常（实测有效），不会误把 raw SQL 当 DELETE 执行。

### clone 与事务语义
- 所有聚合 / 便捷方法（`count/sum/avg/max/min/value/first/pluck/chunk/chunkById/paginate/fetch`）一律 `clone $this` 后再改子句，**不污染原始 builder**。
- `fetch()` 的 clone 正确地把 `limit` 设为 1、`offset` 设为 0，忽略调用方已设的 limit/offset，符合"取一条"语义。
- `count()` 的 clone 正确清除 `limit` / `offset` / `orderBy` / `forUpdate` / `lock`，因此 `count()` 不会被分页或行锁干扰。
- `Connection` 嵌套事务（SAVEPOINT）**实测完全正确**：`begin → begin → rollback → commit` 后仅保留第 1 层写入的行，`inTransaction()` 归位。
- `commit()` / `rollback()` 在 `$transactionLevel <= 0` 时安全返回 `false`（不抛异常）；`commit` → `commit` 返回 `[true, false]`，无越界。
- `Connection::beginTransaction()` 对 `$transactionLevel > 0` 但 `!inTransaction()` 的漂移状态有自愈分支（第 163-165 行）。
- `SoftDelete::force()` 正确地用 `clone` + 手动恢复 `$exists` / 主键来抵消 `Model::__clone()`（642-647 会清 `exists`、清 `relations`、删主键）的影响。实测 `force()->delete()` 能真正物理删除，`withTrashed()` 查不到。
- `SoftDelete::newQuery()` 用 `$this->db()`（不带软删过滤）而非 `newQuery()` 来做 `delete()` / `restore()` 的写操作，**正确**——否则 `restore()` 会被自己的 `deleted_at IS NULL` 过滤掉。
- `SoftDelete` 的 `trashedQuery` 是实例属性，`withTrashed()` / `onlyTrashed()` 各返回新实例，互不干扰（实测同时存在时行为正确）。

### SQLite / MySQL 差异处理
- `Schema::hasTable()` / `hasColumn()` 在 SQLite 走 `sqlite_master` / `PRAGMA table_info`，MySQL 走 `SHOW TABLES LIKE` / `SHOW COLUMNS ... LIKE`，且 LIKE 通配符转义顺序正确（先 `\` 后 `_` `%`）。标识符已由 `sanitizeName()` 白名单保护，无引号注入。
- `Blueprint::id()` 对 SQLite 生成 `INTEGER PRIMARY KEY AUTOINCREMENT`，对 MySQL 生成 `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY`（实测自定义列名 `id('uid')` 也正确生成）。
- `Blueprint::timestamps()` 对 SQLite 省略 `ON UPDATE CURRENT_TIMESTAMP`（第 328-333 行）。实测 SQLite 建表 SQL 完整合法。
- `Blueprint::softDeletes()` 生成 `TIMESTAMP NULL`，SQLite 接受。
- `Blueprint::onDelete()` / `onUpdate()` 在没有前置 `FOREIGN KEY` 命令时**抛 `RuntimeException`**（不再静默 no-op，见 CHANGELOG 历史修复），动作枚举也做了 `in_array(..., true)` 白名单校验。
- `Blueprint::unique()` / `index()` 在没有前置列定义时**抛 `RuntimeException`**（同上）。
- `Schema::compileAlter()` 在无变更时抛 `RuntimeException` 并给出明确提示，不会生成 `ALTER TABLE` 空语句。
- `Schema::drop()` / `rename()` / `truncate()` 对 SQLite 分别生成 `DROP TABLE IF EXISTS` / `ALTER TABLE ... RENAME TO` / `DELETE FROM`（SQLite 正确方言），MySQL 生成对应 MySQL 方言。
- `Migration::ensureTable()` 对 SQLite 与 MySQL 分别建表（`INTEGER PRIMARY KEY AUTOINCREMENT` vs `INT UNSIGNED AUTO_INCREMENT` + `ENGINE=InnoDB`），字段一致。

### 其它已确认正确
- `Model::filterFillable()` 在 `$fillable` 为空时抛明确的 `RuntimeException` 并提示改用 `['*']`；`['*']` 放行全部。实测无 `$fillable` 的模型 `create()` 会抛异常（属 fail-safe 而非 fail-open）。
- `Model::getAttribute()` 的优先级链（accessor → casts → relations → `null`）与 `setAttribute()` 的 mutator 分派（`set{Name}Attribute`，`ucwords($key,'_')` + 去下划线）均正确；`method_exists()` 对 `protected` 方法同样成立——`User::setPasswordAttribute` 是 protected，探针确认 `__set()` 路径**会**触发哈希（问题只在于 `create()`/`update()` 绕开了 `setAttribute()`，见 C2）。
- `Model::__clone()` 正确地做了"转为新记录"的语义（清 `exists` / `relations` / 主键），`SoftDelete::force()` 已显式补偿。
- `HasModelEvents::fireEvent()` 用 `static::class` 隔离不同模型的监听器，`flushEventListeners()` 只清理当前类（`unset(static::$eventListeners[$key], ...)`），`observe()` 对同一 observer 类去重。实测 observer 的 `creating` 方法能正确拦截 `create()`。
- `Model::create()` 中 `lastInsertId() === 0` 时不置 `exists = true`（第 158-160 行），避免后续 `save()` 误走 `UPDATE ... WHERE pk = 0`。设计正确。
- `QueryBuilder` 查询缓存做了多层防护：文件名 `<?php die; ?>` 前缀（防 web 直访泄露数据）、写入用临时文件 + `LOCK_EX` + `rename` 原子替换、读取时校验 `hash('sha256', $sql)` 与存储的 SQL 哈希一致（防缓存键碰撞串数据）、过期条目读取时 `unlink`、读到空内容或解析失败一律返回 `null`（fail-open 回源 DB，不返回脏数据）。
- `Connection::connect()` 对 host / port / database / charset 全部做了白名单与范围校验（port 1-65535，charset `/^[a-zA-Z0-9_-]+$/`），并把 `\PDOException` 包装成 `DatabaseException`，**不把 DSN / 密码写进异常消息**（错误信息是固定的 "Database connection failed. Please check your configuration."），无凭据泄露。
- `Schema::execute()` 把底层异常包装成 `DatabaseException` 并附带 SQL，便于排查。
- `Model::eagerLoad()` 在 `$ids` 为空时正确短路（给所有模型挂 `[]` 或 `null` 后提前返回，不发无意义的 `IN ()` 查询）。
- `Model::firstOrCreate()` 用全新实例执行 `create()`（第 103 行注释与实现一致），不会污染调用方实例的 `attributes`；`create()` 返回 0（事件被取消）时抛明确异常。
- `QueryBuilder::pluck()` 用 `reset($row)` 取首列值，对单列 select 场景正确。
- `QueryBuilder::value()` 对限定列名正确剥离表前缀（`explode('.', $column, 2)[1]`），实测 `value('users.name')` → `'a'`。
- `QueryBuilder::chunkById()` 对 PDO 返回的非限定列名做了前缀剥离（第 991 行 `strrchr`），逻辑正确（该缺陷仅在主键为 0 起点时暴露，见 M6）。
- 所有被审文件均使用 `declare(strict_types=1)`；未发现因类型声明导致的意外 `TypeError`（`Connection::beginTransaction()` 的 SAVEPOINT 分支已显式 `$result = true`，不再回传 `exec()` 的 `int 0`）。

---

## 修复优先级建议

| 顺序 | 条目 | 理由 |
|------|------|------|
| 1 | C2（mutator 绕过） | 明文密码落库，安全风险最高且改动最小 |
| 2 | C1（静态 API） | 文档主推 API 全线 fatal，影响所有使用者 |
| 3 | H1（占位符冲突） | 静默错误结果，可导致越权 |
| 4 | C3 / H2（重复行 / belongsToMany） | 数据完整性 + 功能全废 |
| 5 | H5 / H6（Migration 类名 + PSR-4） | 生产 CLI 直接 fatal |
| 6 | H7（聚合丢 GROUP BY）、H8（配置失效）、H4（SQLite 方言）、H3（Schema 状态） | 静默错误 / 环境相关崩溃 |
| 7 | M 系列 | 边界与一致性问题，可随迭代修复 |
| 8 | L 系列 | 代码质量与可维护性 |

> **测试覆盖缺口**：本次 44 条缺陷中，**没有一条**能被现有的 968 条测试捕获。建议优先补充 5 个测试：
> 1. 静态 API 冒烟：`User::find(1)` / `User::create([...])`（拦截 C1）
> 2. mutator 三路径对比：`create` / `update` / `save` 写入同一密码后比对库中值（拦截 C2）
> 3. `find(1)->update(1,[...])->save()` 后表行数不变（拦截 C3）
> 4. `belongsToMany()` 在 SQLite 上的可用性（拦截 H2）
> 5. `whereRaw()` 具名占位符与自动占位符冲突场景 + `groupBy()->sum()` 一致性（拦截 H1、H7）
>
> 这 5 个测试即可拦截 5 条高危缺陷，成本极低、收益极高。