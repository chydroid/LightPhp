# LightPHP ORM 与数据库指南

本文档详细介绍数据库操作、ORM 模型、查询构建器和迁移系统。

---

## 目录

1. [数据库配置](#1-数据库配置)
2. [建立连接](#2-建立连接)
3. [查询构建器](#3-查询构建器)
4. [Model ORM](#4-model-orm)
5. [模型关联](#5-模型关联)
6. [软删除](#6-软删除)
7. [模型事件](#7-模型事件)
8. [访问器与修改器](#8-访问器与修改器)
9. [查询作用域](#9-查询作用域)
10. [事务管理](#10-事务管理)
11. [数据库迁移](#11-数据库迁移)

---

## 1. 数据库配置

配置文件：`app/config/database.php`

### MySQL

```php
'default' => 'mysql',
'connections' => [
    'mysql' => [
        'driver'    => 'mysql',
        'host'      => '127.0.0.1',
        'port'      => 3306,
        'database'  => 'lightphp',
        'username'  => 'root',
        'password'  => '',
        'charset'   => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'options'   => [
            \PDO::ATTR_TIMEOUT => 5,
            \PDO::ATTR_PERSISTENT => false,
            // SSL 配置...
        ],
    ],
],
```

### SQLite

```php
'default' => 'sqlite',
'connections' => [
    'sqlite' => [
        'driver'   => 'sqlite',
        'database' => STORAGE_PATH . 'database.sqlite',
    ],
],
```

---

## 2. 建立连接

```php
// 使用默认连接
$db = new \db\Connection($config['connections'][$config['default']]);

// 通过 Application 获取
$app = \core\Application::getInstance();
$db = $app->getContainer()->get(\db\Connection::class);
```

---

## 3. 查询构建器

```php
// 基本查询
$users = $db->table('users')
    ->select('id', 'name', 'email')
    ->where('age', '>', 18)
    ->orderBy('created_at', 'DESC')
    ->limit(10)
    ->get();

// 单行查询
$user = $db->table('users')->where('id', '=', 1)->first();

// 聚合查询
$count = $db->table('users')->where('active', '=', 1)->count();
$maxAge = $db->table('users')->max('age');

// 插入
$id = $db->table('users')->insert([
    'name'  => 'Alice',
    'email' => 'alice@example.com',
    'age'   => 25,
]);

// 更新
$affected = $db->table('users')
    ->where('id', '=', 1)
    ->update(['name' => 'Bob']);

// 删除
$affected = $db->table('users')->where('id', '=', 1)->delete();

// JOIN
$results = $db->table('orders')
    ->select('orders.*', 'users.name')
    ->join('users', 'users.id', '=', 'orders.user_id')
    ->where('orders.status', '=', 'paid')
    ->get();

// 分组与聚合
$results = $db->table('orders')
    ->select('user_id', 'COUNT(*) as order_count', 'SUM(total) as total_spent')
    ->groupBy('user_id')
    ->having('order_count', '>', 5)
    ->get();

// 分页
$result = $db->table('users')->paginate(15, 1);
// 返回 ['items' => [...], 'total' => 100, 'page' => 1, 'per_page' => 15]
```

---

## 4. Model ORM

### 定义模型

```php
<?php
declare(strict_types=1);

namespace model;

use model\Model;

class User extends Model
{
    protected string $table = 'users';
    protected string $primaryKey = 'id';
    protected array $fillable = ['name', 'email', 'password', 'age'];
    protected array $casts = [
        'age' => 'int',
        'is_active' => 'bool',
        'metadata' => 'json',
    ];
}
```

### 基本操作

```php
// 查询所有
$users = (new User())->all();

// 按主键查询
$user = (new User())->find(1);

// 按条件查询
$activeUsers = (new User())->where('is_active', '=', true)->get();

// 创建
$user = new User();
$id = $user->create([
    'name'  => 'Alice',
    'email' => 'alice@example.com',
]);

// 更新
$affected = (new User())->update(1, ['name' => 'Bob']);

// 删除
$affected = (new User())->delete(1);

// 查找或失败
$user = (new User())->findOrFail(1);  // 不存在时抛异常
```

---

## 5. 模型关联

### 一对一

```php
class User extends Model
{
    public function profile()
    {
        return $this->hasOne(Profile::class, 'user_id', 'id');
    }
}

$profile = $user->profile;
```

### 一对多

```php
class User extends Model
{
    public function posts()
    {
        return $this->hasMany(Post::class, 'user_id', 'id');
    }
}

$posts = $user->posts;
```

---

## 6. 软删除

使用 `SoftDelete` trait 启用软删除：

```php
use traits\SoftDelete;

class Post extends Model
{
    use SoftDelete;
    // 自动管理 deleted_at 列
}

// delete() 会设置 deleted_at 而非真正删除
$post->delete($id);

// 查询自动排除已软删除的记录
$posts = (new Post())->all();  // 不含 deleted_at != NULL

// 包含已软删除的记录
$posts = (new Post())->withTrashed()->get();

// 仅查询已软删除的记录
$posts = (new Post())->onlyTrashed()->get();

// 恢复软删除
$post->restore($id);
```

---

## 7. 模型事件

使用 `HasModelEvents` trait 注册生命周期钩子：

```php
use traits\HasModelEvents;

class User extends Model
{
    use HasModelEvents;

    protected static function boot(): void
    {
        parent::boot();
        // 创建前自动哈希密码
        static::creating(function ($user) {
            $user['password'] = \core\Hash::make($user['password']);
        });
    }
}
```

支持的事件：`creating`、`created`、`updating`、`updated`、`saving`、`saved`、`deleting`、`deleted`。

---

## 8. 访问器与修改器

```php
class User extends Model
{
    // 访问器：读取时自动转换
    public function getNameAttribute($value): string
    {
        return ucfirst($value);
    }

    // 修改器：写入时自动转换
    public function setPasswordAttribute($value): void
    {
        $this->attributes['password'] = \core\Hash::make($value);
    }
}
```

---

## 9. 查询作用域

```php
class Post extends Model
{
    // 定义作用域
    public function scopePublished($query)
    {
        return $query->where('status', '=', 'published');
    }

    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('created_at', '>', date('Y-m-d', strtotime("-{$days} days")));
    }
}

// 使用作用域
$posts = (new Post())->published()->recent(30)->get();
```

---

## 10. 事务管理

```php
$db = new \db\Connection($config);

// 基本事务
$db->beginTransaction();
try {
    $db->table('users')->insert([...]);
    $db->table('profiles')->insert([...]);
    $db->commit();
} catch (\Exception $e) {
    $db->rollback();
    throw $e;
}

// 嵌套事务（Savepoint）
$db->beginTransaction();       // level 1
$db->beginTransaction();       // SAVEPOINT sp_2 (level 2)
$db->commit();                 // RELEASE SAVEPOINT sp_2
$db->commit();                 // level 0, 真正提交
```

框架自动通过 SAVEPOINT 支持嵌套事务，并在 PDO 隐式回滚后通过 `inTransaction()` 重新同步事务层级。

---

## 11. 数据库迁移

### 创建迁移

```bash
php bin/console make:migration create_users_table
```

### 运行迁移

```bash
php bin/console migrate
```

### 迁移文件结构

```php
<?php
declare(strict_types=1);

return [
    'up' => function(\db\Schema $schema) {
        $schema->create('users', function($table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
        });
    },
    'down' => function(\db\Schema $schema) {
        $schema->dropIfExists('users');
    },
];
```

### Schema 构建器支持的列类型

| 方法 | 类型 |
|------|------|
| `id()` | BIGINT AUTO_INCREMENT PRIMARY KEY |
| `string($name, $length=255)` | VARCHAR |
| `text($name)` | TEXT |
| `integer($name)` | INT |
| `bigInteger($name)` | BIGINT |
| `boolean($name)` | TINYINT(1) |
| `decimal($name, $precision, $scale)` | DECIMAL |
| `datetime($name)` | DATETIME |
| `date($name)` | DATE |
| `json($name)` | JSON / TEXT |
| `timestamps()` | created_at + updated_at |
