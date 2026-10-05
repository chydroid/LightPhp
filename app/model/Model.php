<?php
declare(strict_types=1);

namespace model;

use db\ModelQuery;
use db\QueryBuilder;
use traits\HasModelEvents;

/**
 * Eloquent 风格的模型基类
 *
 * 模型实例可安全地直接 json_encode()：实现 JsonSerializable 后，
 * 编码会走 toArray()，从而自动套用 $hidden 与 $casts。
 */
class Model implements \JsonSerializable
{
    use HasModelEvents;
    protected string $table = '';
    protected string $primaryKey = 'id';
    protected array $fillable = [];
    protected array $hidden = [];
    protected array $casts = [];
    protected string $dateFormat = 'Y-m-d H:i:s';
    /**
     * 是否自动维护 created_at / updated_at
     *
     * 此前 syncTimestamps() 无条件插入这两个字段，开发者若建了不含
     * 时间戳的表（如配置表、日志表、关联表），每次 create()/update()
     * 都会抛 "table X has no column named created_at"。
     * 子类置为 false 即可关闭。
     */
    protected bool $timestamps = true;
    protected array $relations = [];
    protected array $attributes = [];
    protected bool $exists = false;

    private static ?\db\Connection $db = null;

    public function __construct(array $attributes = [])
    {
        $this->attributes = $attributes;
    }

    public static function setDb(\db\Connection $db): void
    {
        self::$db = $db;
    }

    protected function db(): QueryBuilder
    {
        $db = self::$db;
        if ($db === null) {
            $db = self::$db = \core\Container::getInstance()?->get('db');
        }
        if ($db === null) {
            throw new \RuntimeException('Database connection not initialized.');
        }
        return $db->table($this->table);
    }

    protected function newQuery(): QueryBuilder
    {
        return $this->db();
    }

    /**
     * 创建用于执行静态查询入口的实例
     *
     * 子类/trait 可覆盖以注入查询作用域状态。
     * 例如 SoftDelete 通过它让 withTrashed()->all() 复用携带
     * trashedQuery 的实例，而不是丢状态后静默返回错误数据集。
     *
     * @return static
     */
    protected static function makeQueryInstance(): static
    {
        return new static();
    }

    /**
     * 按主键查找记录
     *
     * 静态方法：`User::find(1)`。PHP 允许以 `$user->find(1)` 形式调用静态方法，
     * 因此实例调用方式同样兼容（docs/api.md 承诺两种写法等价）。
     */
    public static function find(int|string $id): ?static
    {
        $instance = static::makeQueryInstance();
        $row = $instance->newQuery()->where($instance->primaryKey, '=', $id)->fetch();
        return $row ? $instance->newFromBuilder($row) : null;
    }

    public static function findOrFail(int|string $id): static
    {
        $model = static::find($id);
        if ($model === null) {
            throw new \RuntimeException("Model " . static::class . " not found with " . (new static())->primaryKey . "={$id}");
        }
        return $model;
    }

    public static function findBy(string $column, mixed $value): ?static
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
            throw new \InvalidArgumentException("Invalid column name: {$column}");
        }

        $instance = static::makeQueryInstance();
        $row = $instance->newQuery()->where($column, '=', $value)->fetch();
        return $row ? $instance->newFromBuilder($row) : null;
    }

    public static function first(): ?static
    {
        $instance = static::makeQueryInstance();
        $row = $instance->newQuery()->limit(1)->fetch();
        return $row ? $instance->newFromBuilder($row) : null;
    }

    public static function firstOrFail(): static
    {
        $model = static::first();
        if ($model === null) {
            throw new \RuntimeException("No " . static::class . " record found");
        }
        return $model;
    }

    public static function firstOrCreate(array $attributes, array $values = []): static
    {
        $instance = static::makeQueryInstance();
        $query = $instance->newQuery();
        foreach ($attributes as $key => $value) {
            $query->where($key, '=', $value);
        }
        $row = $query->fetch();
        if ($row) {
            return $instance->newFromBuilder($row);
        }
        $data = array_merge($attributes, $values);
        $id = static::create($data);
        if (!$id) {
            throw new \RuntimeException('firstOrCreate failed: create() returned empty ID (creating event may have been cancelled)');
        }
        return static::find($id) ?? $instance->newFromBuilder(array_merge([$instance->primaryKey => $id], $data));
    }

    public static function firstOrNew(array $attributes, array $values = []): static
    {
        $instance = static::makeQueryInstance();
        $query = $instance->newQuery();
        foreach ($attributes as $key => $value) {
            $query->where($key, '=', $value);
        }
        $row = $query->fetch();
        if ($row) {
            return $instance->newFromBuilder($row);
        }
        $data = array_merge($attributes, $values);
        $model = new static($data);
        $model->exists = false;
        return $model;
    }

    public static function all(): array
    {
        $instance = static::makeQueryInstance();
        $rows = $instance->newQuery()->fetchAll();
        return array_map(fn($row) => $instance->newFromBuilder($row), $rows);
    }

    public static function select(array $columns = ['*']): ModelQuery
    {
        return new ModelQuery(
            static::makeQueryInstance()->newQuery()->select($columns),
            static::class
        );
    }

    /**
     * 开始一个模型查询链
     *
     * 返回 \db\ModelQuery 而非裸 QueryBuilder：终结方法（fetch /
     * fetchAll / first / paginate / chunk）返回**模型实例**，
     * 因此 $hidden、$casts、访问器全部自动生效。
     *
     * ```php
     * $rows = User::where('status', 1)->fetchAll();   // Model[]
     * echo json_encode($rows);                        // 自动隐藏 password
     * ```
     *
     * @param string $column 列名
     * @param mixed $operator 运算符或值（二参数简写时）
     * @param mixed $value 值（三参数形式时）
     * @return ModelQuery 模型感知查询链
     */
    public static function where(string $column, mixed $operator = null, mixed $value = null): ModelQuery
    {
        $query = new ModelQuery(static::makeQueryInstance()->newQuery(), static::class);
        // 保持参数数量语义，让查询正确区分两参数简写和三参数形式
        if (func_num_args() >= 3) {
            return $query->where($column, $operator, $value);
        }
        return $query->where($column, $operator);
    }

    public static function create(array $data): int|string
    {
        $instance = static::makeQueryInstance();
        return $instance->persistCreate($data);
    }

    public static function update(int|string $id, array $data): int
    {
        return static::makeQueryInstance()->persistUpdate($id, $data);
    }

    /**
     * 写入一条新记录（create 的实例实现）
     *
     * 必须经 setAttribute() 逐字段赋值，修改器（setXxxAttribute）才会生效。
     * 此前直接 $this->attributes = filterFillable($data)，绕过了 mutator，
     * 导致 User::create(['password'=>...]) 把明文密码写进数据库。
     */
    private function persistCreate(array $data): int|string
    {
        $this->attributes = [];
        $this->applyFillable($data);
        if (!$this->fireEvent('creating')) {
            return 0;
        }
        $data = $this->syncTimestamps($this->attributes, 'create');
        $id = $this->newQuery()->insert($data);
        $this->attributes[$this->primaryKey] = $id;
        // 仅在获得有效主键时标记为已存在，避免 lastInsertId=0 时
        // 后续 save() 误走 UPDATE 分支 where pk=0
        if ($id) {
            $this->exists = true;
        }
        $this->fireEvent('created');
        return $id;
    }

    /**
     * 按主键更新记录（update 的实例实现）
     *
     * @param int|string $id 目标主键
     * @param array $data 待更新字段
     */
    private function persistUpdate(int|string $id, array $data): int
    {
        $this->attributes = [];
        $this->applyFillable($data);
        if (!$this->fireEvent('updating')) {
            return 0;
        }
        unset($this->attributes[$this->primaryKey]);
        $data = $this->syncTimestamps($this->attributes, 'update');
        $result = $this->newQuery()->where($this->primaryKey, '=', $id)->update($data);
        if ($result > 0) {
            $this->fireEvent('updated');
        }
        return $result;
    }

    /**
     * 将输入数据按 $fillable 过滤后，逐字段经 setAttribute() 写入
     *
     * 保证所有写入路径（构造、create、update、属性赋值）都触发 mutator。
     *
     * @param array $data 原始输入数据
     */
    protected function applyFillable(array $data): void
    {
        foreach ($this->filterFillable($data) as $key => $value) {
            $this->setAttribute((string) $key, $value);
        }
    }

    /**
     * 删除模型实例
     *
     * - 传入 $id 时按主键删除指定记录
     * - $id 为 null 时删除当前实例（使用 $this->attributes[$primaryKey]）
     *
     * @param int|string|null $id 主键值，为 null 时删除当前实例
     * @return int 受影响行数
     * @throws \RuntimeException 当 $id 为 null 且当前实例无主键值时
     */
    public function delete(int|string|null $id = null): int
    {
        if ($id === null) {
            $id = $this->attributes[$this->primaryKey] ?? null;
            if ($id === null) {
                throw new \RuntimeException(
                    sprintf('Cannot delete model [%s] without a primary key value.', static::class)
                );
            }
        }
        if (!$this->fireEvent('deleting')) {
            return 0;
        }
        $result = $this->newQuery()->where($this->primaryKey, '=', $id)->delete();
        if ($result > 0) {
            $this->fireEvent('deleted');
        }
        return $result;
    }

    public static function paginate(int $perPage = 15, int $page = 1): array
    {
        $instance = static::makeQueryInstance();
        $result = $instance->newQuery()->paginate($perPage, $page);
        $result['items'] = array_map(fn($row) => $instance->newFromBuilder($row), $result['items']);
        return $result;
    }

    public function with(array|string $relations): self
    {
        if (is_string($relations)) {
            $relations = explode(',', $relations);
        }

        foreach ($relations as $relation) {
            $relation = trim($relation);
            $this->loadRelation($relation);
        }

        return $this;
    }

    // ═══════════════════════════════════════════════
    //  关联关系定义方法 - 参考 Laravel Eloquent
    // ═══════════════════════════════════════════════

    /**
     * 定义一对一关联
     * 例: User 有一个 Profile
     *
     * @param class-string<static> $related 关联模型类名
     * @param string|null $foreignKey 外键 (默认: 当前模型名_id)
     * @param string|null $localKey 本地键 (默认: 当前模型主键)
     */
    protected function hasOne(string $related, ?string $foreignKey = null, ?string $localKey = null): ?self
    {
        $instance = new $related();
        $foreignKey = $foreignKey ?? $this->getForeignKey();
        $localKey = $localKey ?? $this->primaryKey;

        $result = $instance->newQuery()
            ->where($foreignKey, '=', $this->getAttribute($localKey))
            ->fetch();

        return $result ? $instance->newFromBuilder($result) : null;
    }

    /**
     * 定义一对多关联
     * 例: Post 有多个 Comment
     *
     * @param class-string<static> $related 关联模型类名
     * @param string|null $foreignKey 外键
     * @param string|null $localKey 本地键
     * @return static[]
     */
    protected function hasMany(string $related, ?string $foreignKey = null, ?string $localKey = null): array
    {
        $instance = new $related();
        $foreignKey = $foreignKey ?? $this->getForeignKey();
        $localKey = $localKey ?? $this->primaryKey;

        $rows = $instance->newQuery()
            ->where($foreignKey, '=', $this->getAttribute($localKey))
            ->fetchAll();

        return array_map(fn($row) => $instance->newFromBuilder($row), $rows);
    }

    /**
     * 定义反向一对一/一对多关联
     * 例: Comment 属于 Post
     *
     * @param class-string<static> $related 关联模型类名
     * @param string|null $foreignKey 外键 (当前表)
     * @param string|null $ownerKey 父表主键
     */
    protected function belongsTo(string $related, ?string $foreignKey = null, ?string $ownerKey = null): ?self
    {
        $instance = new $related();
        $foreignKey = $foreignKey ?? $instance->getForeignKey();
        $ownerKey = $ownerKey ?? $instance->primaryKey;

        $result = $instance->newQuery()
            ->where($ownerKey, '=', $this->getAttribute($foreignKey))
            ->fetch();

        return $result ? $instance->newFromBuilder($result) : null;
    }

    /**
     * 定义多对多关联
     * 例: User 有多个 Role (通过 user_role 中间表)
     *
     * @param class-string<static> $related 关联模型类名
     * @param string $pivotTable 中间表名
     * @param string|null $foreignPivotKey 中间表当前模型外键
     * @param string|null $relatedPivotKey 中间表关联模型外键
     * @return static[]
     */
    protected function belongsToMany(string $related, string $pivotTable, ?string $foreignPivotKey = null, ?string $relatedPivotKey = null): array
    {
        $instance = new $related();
        $foreignPivotKey = $foreignPivotKey ?? $this->getForeignKey();
        $relatedPivotKey = $relatedPivotKey ?? $instance->getForeignKey();

        $rows = $instance->newQuery()
            ->select(["{$instance->table}.*"])
            ->join($pivotTable, "{$instance->table}.{$instance->primaryKey}", '=', "{$pivotTable}.{$relatedPivotKey}")
            ->where("{$pivotTable}.{$foreignPivotKey}", '=', $this->getAttribute($this->primaryKey))
            ->fetchAll();

        return array_map(fn($row) => $instance->newFromBuilder($row), $rows);
    }

    /**
     * 预加载关联并返回模型集合（真正生效的 eager loading）
     *
     * 背景：此前 `with()` 只是把关联挂在「随后会被丢弃」的原型实例上，
     * 拼到查询链上（`Model::with('author')->where(...)->fetchAll()`）时
     * 完全不起作用——实测返回的行里没有任何关联字段，且 `__get` 也不会
     * 触发惰性加载，于是关联功能几乎等于不可用。
     *
     * 本方法按关联名批量取出数据并注入到每个模型实例（一次查询，避免 N+1）：
     *
     * ```php
     * $rows  = (new Post())->where('published', 1)->fetchAll();
     * $posts = Post::withRelation($rows, 'author', 'belongsTo', 'author_id', 'id');
     *
     * echo json_encode($posts[0]->toArray());
     * // {"id":1,"author_id":1,"published":1,"author":{"id":1,"name":"Tom"}}
     * ```
     *
     * @param static[] $rows 查询结果（行数组或 Model 实例均可）
     * @param string $relation 关联方法名（如 'author'）
     * @param string $type 关联类型：hasMany | hasOne | belongsTo
     * @param string|null $foreignKey 外键列
     * @param string|null $ownerKey 本表用于匹配的列
     * @return list<static> 已注入关联的模型实例
     */
    public static function withRelation(
        array $rows,
        string $relation,
        string $type = 'hasMany',
        ?string $foreignKey = null,
        ?string $ownerKey = null
    ): array {
        $prototype = static::makeQueryInstance();
        $models = [];
        foreach ($rows as $row) {
            $models[] = $row instanceof static ? $row : $prototype->newFromBuilder((array) $row);
        }
        if ($models === []) {
            return [];
        }

        $relatedClass = static::inferRelatedClass($models[0], $relation);
        return static::eagerLoad($models, $relation, $relatedClass, $type, $foreignKey, $ownerKey);
    }

    /**
     * 推断关联方法对应的模型类名
     *
     * 优先使用 `protected ?string $authorModel = Author::class;` 这类显式声明，
     * 避免为推断类名而额外执行一次关联查询。
     *
     * @param static $model 模型实例
     * @param string $relation 关联方法名
     * @return class-string 关联模型类名
     */
    protected static function inferRelatedClass(self $model, string $relation): string
    {
        // 约定：protected ?string $xxxModel 声明关联类
        $declared = lcfirst($relation) . 'Model';
        if (property_exists($model, $declared)) {
            $ref = new \ReflectionProperty($model, $declared);
            $ref->setAccessible(true);
            $class = $ref->getValue($model);
            if (is_string($class) && class_exists($class)) {
                return $class;
            }
        }

        // 回退：调用一次关联方法，从返回值取类名（只发生一次）
        if (method_exists($model, $relation)) {
            $result = $model->{$relation}();
            if ($result instanceof self) {
                return get_class($result);
            }
            if (is_array($result) && isset($result[0]) && $result[0] instanceof self) {
                return get_class($result[0]);
            }
        }
        return self::class;
    }

    /**
     * 预加载关联 - 批量查询避免 N+1
     *
     * @param static[] $models 模型集合
     * @param string $relation 关联名
     * @param class-string<static> $relatedClass 关联模型类
     * @param string $type 关联类型: hasOne|hasMany|belongsTo
     */
    public static function eagerLoad(array $models, string $relation, string $relatedClass, string $type = 'hasMany', ?string $foreignKey = null, ?string $ownerKey = null): array
    {
        if (empty($models)) {
            return $models;
        }

        $instance = new $relatedClass();
        $firstModel = reset($models);

        if ($type === 'hasMany' || $type === 'hasOne') {
            $foreignKey = $foreignKey ?? $firstModel->getForeignKey();
            $localKey = $ownerKey ?? $firstModel->primaryKey;

            $ids = array_values(array_unique(array_filter(array_map(fn($m) => $m->getAttribute($localKey), $models), fn($id) => $id !== null)));

            if (count($ids) === 0) {
                foreach ($models as $model) {
                    $model->relations[$relation] = ($type === 'hasOne') ? null : [];
                }
                return $models;
            }

            $relatedModels = $instance->newQuery()
                ->whereIn($foreignKey, array_values($ids))
                ->fetchAll();

            $grouped = [];
            foreach ($relatedModels as $rm) {
                $grouped[$rm[$foreignKey] ?? 0][] = $instance->newFromBuilder($rm);
            }

            foreach ($models as $model) {
                $key = $model->getAttribute($localKey);
                if ($type === 'hasOne') {
                    $model->relations[$relation] = $grouped[$key][0] ?? null;
                } else {
                    $model->relations[$relation] = $grouped[$key] ?? [];
                }
            }
        } elseif ($type === 'belongsTo') {
            $foreignKey = $foreignKey ?? $instance->getForeignKey();
            $ownerKey = $ownerKey ?? $instance->primaryKey;

            $ids = array_values(array_unique(array_filter(array_map(fn($m) => $m->getAttribute($foreignKey), $models), fn($id) => $id !== null)));

            if (count($ids) === 0) {
                foreach ($models as $model) {
                    $model->relations[$relation] = null;
                }
                return $models;
            }

            $relatedModels = $instance->newQuery()
                ->whereIn($ownerKey, array_values($ids))
                ->fetchAll();

            $grouped = [];
            foreach ($relatedModels as $rm) {
                $grouped[$rm[$ownerKey] ?? 0] = $instance->newFromBuilder($rm);
            }

            foreach ($models as $model) {
                $key = $model->getAttribute($foreignKey);
                $model->relations[$relation] = $grouped[$key] ?? null;
            }
        }

        return $models;
    }

    // ═══════════════════════════════════════════════
    //  序列化
    // ═══════════════════════════════════════════════

    public function toArray(): array
    {
        static $visited = [];

        $oid = spl_object_id($this);
        if (isset($visited[$oid])) {
            return [];
        }
        $visited[$oid] = true;

        try {
            $data = $this->attributes;

            foreach ($this->casts as $key => $type) {
                if (array_key_exists($key, $data)) {
                    $data[$key] = $this->castAttribute($key, $data[$key]);
                }
            }

            foreach ($this->hidden as $key) {
                unset($data[$key]);
            }

            foreach ($this->relations as $name => $value) {
                if ($value instanceof self) {
                    $data[$name] = $value->toArray();
                } elseif (is_array($value)) {
                    $data[$name] = array_map(fn($v) => $v instanceof self ? $v->toArray() : $v, $value);
                } else {
                    $data[$name] = $value;
                }
            }

            return $data;
        } finally {
            unset($visited[$oid]);
        }
    }

    public function toJson(int $options = JSON_UNESCAPED_UNICODE): string
    {
        return json_encode($this->toArray(), $options) ?: '{}';
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function getAttribute(string $key): mixed
    {
        $getter = 'get' . str_replace('_', '', ucwords($key, '_')) . 'Attribute';
        if (method_exists($this, $getter)) {
            return $this->$getter(
                array_key_exists($key, $this->attributes) ? $this->attributes[$key] : null
            );
        }

        if (array_key_exists($key, $this->attributes)) {
            return $this->castAttribute($key, $this->attributes[$key]);
        }

        if (array_key_exists($key, $this->relations)) {
            return $this->relations[$key];
        }

        return null;
    }

    public function setAttribute(string $key, mixed $value): void
    {
        $setter = 'set' . str_replace('_', '', ucwords($key, '_')) . 'Attribute';
        if (method_exists($this, $setter)) {
            $this->$setter($value);
            return;
        }
        $this->attributes[$key] = $value;
    }

    // ═══════════════════════════════════════════════
    //  内部方法
    // ═══════════════════════════════════════════════

    /**
     * 保存模型实例到数据库
     * 
     * 如果模型已存在于数据库则更新，否则创建
     * 
     * @return int 受影响行数或新ID
     */
    public function save(): int|string
    {
        if (!$this->fireEvent('saving')) {
            return 0;
        }

        $pk = $this->attributes[$this->primaryKey] ?? null;

        if (!$this->exists || $pk === null) {
            if (!$this->fireEvent('creating')) {
                return 0;
            }
            $data = $this->filterFillable($this->attributes);
            $data = $this->syncTimestamps($data, 'create');
            $id = $this->newQuery()->insert($data);
            $this->attributes[$this->primaryKey] = $id;
            // 仅在获得有效主键时标记为已存在，避免 lastInsertId=0 时
            // 后续 save() 误走 UPDATE 分支 where pk=0
            if ($id) {
                $this->exists = true;
            }
            $this->fireEvent('created');
            $this->fireEvent('saved');
            return $id;
        }

        if (!$this->fireEvent('updating')) {
            return 0;
        }
        $data = $this->filterFillable($this->attributes);
        unset($data[$this->primaryKey]);
        $data = $this->syncTimestamps($data, 'update');
        $result = $this->newQuery()->where($this->primaryKey, '=', $pk)->update($data);
        if ($result > 0) {
            $this->fireEvent('updated');
        }
        $this->fireEvent('saved');
        return $result;
    }

    /**
     * 根据主键删除模型实例（静态调用）
     * 
     * @param int|string $id 主键值
     * @return int 受影响行数
     */
    public static function deleteById(int|string $id): int
    {
        $instance = new static();
        return $instance->delete($id);
    }

    /**
     * 从查询结果构建模型实例（标记为已存在）
     */
    protected function newFromBuilder(array $attributes): static
    {
        $model = new static();
        $model->attributes = $attributes;
        $model->exists = true;
        return $model;
    }

    /**
     * 由一行查询结果构建模型实例
     *
     * 供 \db\ModelQuery 等外部调用方使用：把行数组转成模型，
     * 从而让查询链的终结方法返回模型实例（$hidden / $casts 自动生效）。
     *
     * @param array<string, mixed> $attributes 查询结果行
     * @return static 已标记为存在的模型实例
     */
    public static function makeFromRow(array $attributes): static
    {
        return (new static())->newFromBuilder($attributes);
    }

    /**
     * JSON 序列化
     *
     * 使 `json_encode($model)` / `json_encode($models)` 与 toJson() 行为一致，
     * 自动套用 $hidden 与 $casts——不会因 $attributes 是 protected 而编码成 {}。
     *
     * @return array<string, mixed> 过滤后的属性数组
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * 对查询结果行套用 $hidden，供查询链输出前过滤敏感字段
     *
     * 背景：`Model::where(...)` 返回的是 QueryBuilder，`first()` / `fetchAll()`
     * 产出的是**裸数组**，不会经过 toArray()，因此 $hidden 不生效——
     * 实测 `User::where('id',1)->first()` 返回含明文 password 的数组，
     * 而 `User::find(1)->toArray()` 已正确隐藏。开发者在查询链上直接
     * `return $this->json($rows)` 就会泄漏密码。
     *
     * 这里不在 QueryBuilder 内部过滤，因为 Model 自身的 save()/update()
     * 需要完整的原始属性；改为提供显式方法，让「要输出给外部」的意图可见：
     *
     * ```php
     * // 安全：过滤后再输出
     * $rows = User::where('status', 1)->fetchAll();
     * return $this->json(User::visibleOnly($rows));
     *
     * // 或者直接把查询结果转成模型（推荐，行为与 find() 一致）
     * $users = User::hydrate(User::where('status', 1)->fetchAll());
     * return $this->json($users);   // 每项 toArray() 自动套用 $hidden
     * ```
     *
     * @param array<int|string,mixed> $rows 查询结果行
     * @return array<int|string,mixed> 过滤后的行
     */
    public static function visibleOnly(array $rows): array
    {
        $hidden = (new static())->hidden;
        if ($hidden === []) {
            return $rows;
        }
        // 只处理数组行；已经是 Model 实例的请用 toArray()，这里不做处理
        return array_map(
            static function ($row) use ($hidden) {
                if (!is_array($row)) {
                    return $row;
                }
                foreach ($hidden as $key) {
                    unset($row[$key]);
                }
                return $row;
            },
            $rows
        );
    }

    /**
     * 把查询结果行批量转为模型实例
     *
     * 让 `User::where(...)->fetchAll()` 与 `User::find(...)` 行为一致：
     * 都返回 Model 实例，$hidden / $casts / 访问器全部生效。
     *
     * ```php
     * $users = User::hydrate(User::where('status', 1)->fetchAll());
     * return $this->json(array_map(fn($u) => $u->toArray(), $users));
     * ```
     *
     * @param array<int|string,mixed> $rows 查询结果行
     * @return list<static> 模型实例列表
     */
    public static function hydrate(array $rows): array
    {
        $instance = static::makeQueryInstance();
        $out = [];
        foreach ($rows as $row) {
            $out[] = is_array($row) ? $instance->newFromBuilder($row) : $row;
        }
        return $out;
    }

    protected function filterFillable(array $data): array
    {
        if (empty($this->fillable)) {
            throw new \RuntimeException(
                sprintf('Model [%s] has no $fillable defined. Set $fillable or define it as ["*"] to allow all.', static::class)
            );
        }

        if ($this->fillable === ['*']) {
            return $data;
        }

        return array_intersect_key($data, array_flip($this->fillable));
    }

    protected function syncTimestamps(array $data, string $type): array
    {
        // 子类可通过 $timestamps = false 关闭（表无时间戳列时必需）
        if (!$this->timestamps) {
            return $data;
        }
        $now = date($this->dateFormat);

        if ($type === 'create') {
            if (!isset($data['created_at'])) {
                $data['created_at'] = $now;
            }
            if (!isset($data['updated_at'])) {
                $data['updated_at'] = $now;
            }
        }

        if ($type === 'update' && !isset($data['updated_at'])) {
            $data['updated_at'] = $now;
        }

        return $data;
    }

    protected function castAttribute(string $key, mixed $value): mixed
    {
        if (!isset($this->casts[$key])) {
            return $value;
        }

        return match ($this->casts[$key]) {
            'int', 'integer' => (int) $value,
            'float', 'double' => (float) $value,
            'bool', 'boolean' => (bool) $value,
            'array' => $value === null ? null : (is_array($value) ? $value : (json_decode($value, true) ?? [])),
            'json' => $value === null ? null : (is_string($value) ? json_decode($value, true) : $value),
            'date' => ($ts = strtotime((string) $value)) !== false ? date('Y-m-d', $ts) : null,
            'datetime' => ($ts = strtotime((string) $value)) !== false ? date($this->dateFormat, $ts) : null,
            default => $value
        };
    }

    protected function loadRelation(string $name): void
    {
        if (array_key_exists($name, $this->relations)) {
            return;
        }
        if (method_exists($this, $name)) {
            $this->relations[$name] = $this->$name();
        }
    }

    protected function getForeignKey(): string
    {
        $class = basename(str_replace('\\', '/', static::class));
        return lcfirst($class) . '_id';
    }

    // ═══════════════════════════════════════════════
    //  魔术方法
    // ═══════════════════════════════════════════════

    public function __get(string $name)
    {
        return $this->getAttribute($name);
    }

    public function __set(string $name, mixed $value): void
    {
        $this->setAttribute($name, $value);
    }

    public function __clone(): void
    {
        $this->exists = false;
        $this->relations = [];
        unset($this->attributes[$this->primaryKey]);
    }

    public function __isset(string $name): bool
    {
        return (array_key_exists($name, $this->attributes) && $this->attributes[$name] !== null)
            || (array_key_exists($name, $this->relations) && $this->relations[$name] !== null);
    }

    public function __call(string $method, array $args)
    {
        $proxiedMethods = ['whereIn', 'whereOr', 'whereNull', 'whereNotNull', 'whereBetween',
            'orderBy', 'groupBy', 'having', 'limit', 'leftJoin', 'rightJoin',
            'join', 'count', 'sum', 'avg', 'max', 'min', 'chunk', 'first',
            'fetch', 'fetchAll', 'value'];

        if (in_array($method, $proxiedMethods, true)) {
            // 经 ModelQuery 转发：链式调用保持模型感知，
            // 终结方法返回模型实例而非裸数组
            $query = new ModelQuery($this->newQuery(), static::class);
            return $query->{$method}(...$args);
        }

        $scopeMethod = 'scope' . ucfirst($method);
        if (method_exists($this, $scopeMethod)) {
            // 作用域仍接收裸 QueryBuilder（保持既有签名），
            // 但若其返回 QueryBuilder，则包成 ModelQuery 以免返回裸数组
            array_unshift($args, $this->newQuery());
            $result = call_user_func_array([$this, $scopeMethod], $args);
            return $result instanceof QueryBuilder
                ? new ModelQuery($result, static::class)
                : $result;
        }

        throw new \BadMethodCallException(sprintf('Method %s::%s does not exist', static::class, $method));
    }

    public static function __callStatic(string $method, array $args)
    {
        // 仅 QueryBuilder 的查询方法可通过静态转发（Model::count() 等）。
        // find/first/create/update/paginate/select/where/all 等已是真正的静态方法，
        // 不再经过 __callStatic（PHP 不会再把它们的静态调用分派到这里）。
        $queryMethods = ['whereIn', 'whereOr', 'whereNull', 'whereNotNull', 'whereBetween',
            'orderBy', 'groupBy', 'having', 'limit', 'leftJoin', 'rightJoin',
            'join', 'count', 'sum', 'avg', 'max', 'min', 'chunk', 'value'];

        if (in_array($method, $queryMethods, true)) {
            // 经 ModelQuery 转发：链式调用保持模型感知，
            // 终结方法返回模型实例而非裸数组
            $query = new ModelQuery(static::makeQueryInstance()->newQuery(), static::class);
            return $query->{$method}(...$args);
        }

        if ($method === 'eagerLoad') {
            return call_user_func_array([static::class, $method], $args);
        }

        $instance = new static();

        // 本地作用域：User::active() → scopeActive(QueryBuilder $query, ...$args)
        $scopeMethod = 'scope' . ucfirst($method);
        if (method_exists($instance, $scopeMethod)) {
            // 作用域仍接收裸 QueryBuilder（保持既有签名），
            // 但若其返回 QueryBuilder，则包成 ModelQuery 以免返回裸数组
            array_unshift($args, $instance->newQuery());
            $result = call_user_func_array([$instance, $scopeMethod], $args);
            return $result instanceof QueryBuilder
                ? new ModelQuery($result, static::class)
                : $result;
        }

        // 兜底：允许静态调用实例方法（如 SoftDelete 的 restore/trashed/with）。
        // 注意对仍为实例方法的 delete()/with()，静态调用等价于 (new static())->delete(...)，
        // 不带主键时 delete() 会抛 RuntimeException（语义正确，不做静默兜底）。
        if (method_exists($instance, $method)) {
            $reflection = new \ReflectionMethod($instance, $method);
            if ($reflection->isPublic() && !$reflection->isStatic()) {
                return call_user_func_array([$instance, $method], $args);
            }
        }

        throw new \BadMethodCallException(sprintf('Method %s::%s does not exist', static::class, $method));
    }
}