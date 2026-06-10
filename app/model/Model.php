<?php
declare(strict_types=1);

namespace model;

use db\QueryBuilder;
use traits\HasModelEvents;

class Model
{
    use HasModelEvents;
    protected string $table = '';
    protected string $primaryKey = 'id';
    protected array $fillable = [];
    protected array $hidden = [];
    protected array $casts = [];
    protected string $dateFormat = 'Y-m-d H:i:s';
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

    public function find(int|string $id): ?static
    {
        $row = $this->newQuery()->where($this->primaryKey, '=', $id)->fetch();
        return $row ? $this->newFromBuilder($row) : null;
    }

    public function findBy(string $column, mixed $value): ?static
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
            throw new \InvalidArgumentException("Invalid column name: {$column}");
        }

        $row = $this->newQuery()->where($column, '=', $value)->fetch();
        return $row ? $this->newFromBuilder($row) : null;
    }

    public function all(): array
    {
        $rows = $this->newQuery()->fetchAll();
        return array_map(fn($row) => $this->newFromBuilder($row), $rows);
    }

    public function select(array $columns = ['*']): QueryBuilder
    {
        return $this->newQuery()->select($columns);
    }

    public function where(string $column, mixed $operator = null, mixed $value = null): QueryBuilder
    {
        return $this->newQuery()->where($column, $operator, $value);
    }

    public function create(array $data): int
    {
        $data = $this->filterFillable($data);
        $data = $this->syncTimestamps($data, 'create');
        return $this->newQuery()->insert($data);
    }

    public function update(int|string $id, array $data): int
    {
        $data = $this->filterFillable($data);
        unset($data[$this->primaryKey]);
        $data = $this->syncTimestamps($data, 'update');
        return $this->newQuery()->where($this->primaryKey, '=', $id)->update($data);
    }

    /**
     * 根据主键删除模型实例
     * 
     * @param int|string $id 主键值
     * @return int 受影响行数
     */
    public function delete(int|string $id): int
    {
        if (!$this->fireEvent('deleting')) {
            return 0;
        }
        $result = $this->newQuery()->where($this->primaryKey, '=', $id)->delete();
        $this->fireEvent('deleted');
        return $result;
    }

    public function paginate(int $perPage = 15, int $page = 1): array
    {
        $result = $this->newQuery()->paginate($perPage, $page);
        $result['items'] = array_map(fn($row) => $this->newFromBuilder($row), $result['items']);
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
    protected function hasOne(string $related, ?string $foreignKey = null, ?string $localKey = null): self
    {
        $instance = new $related();
        $foreignKey = $foreignKey ?? $this->getForeignKey();
        $localKey = $localKey ?? $this->primaryKey;

        $result = $instance->newQuery()
            ->where($foreignKey, '=', $this->getAttribute($localKey))
            ->fetch();

        return $result ? $instance->newFromBuilder($result) : new $related();
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
    protected function belongsTo(string $related, ?string $foreignKey = null, ?string $ownerKey = null): ?static
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
     * 预加载关联 - 批量查询避免 N+1
     *
     * @param static[] $models 模型集合
     * @param string $relation 关联名
     * @param class-string<static> $relatedClass 关联模型类
     * @param string $type 关联类型: hasOne|hasMany|belongsTo
     */
    public static function eagerLoad(array $models, string $relation, string $relatedClass, string $type = 'hasMany'): array
    {
        if (empty($models)) {
            return $models;
        }

        $instance = new $relatedClass();
        $firstModel = reset($models);

        if ($type === 'hasMany' || $type === 'hasOne') {
            $foreignKey = $firstModel->getForeignKey();
            $localKey = $firstModel->primaryKey;

            $ids = array_unique(array_map(fn($m) => $m->getAttribute($localKey), $models));

            if (empty($ids)) {
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
            $foreignKey = $relation . '_id';
            $ownerKey = $instance->primaryKey;

            $ids = array_unique(array_filter(array_map(fn($m) => $m->getAttribute($foreignKey), $models)));

            if (empty($ids)) {
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
            $this->exists = true;
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
        $this->fireEvent('updated');
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
        $now = date($this->dateFormat);

        if ($type === 'create' && !isset($data['created_at'])) {
            $data['created_at'] = $now;
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
            'array' => $value === null ? [] : (is_array($value) ? $value : (json_decode($value, true) ?? [])),
            'json' => $value === null ? null : (is_array($value) ? json_encode($value) : $value),
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

    public function __set(string $name, $value): void
    {
        $this->setAttribute($name, $value);
    }

    public function __clone(): void
    {
        foreach ($this->relations as $name => $relation) {
            if ($relation instanceof self) {
                $this->relations[$name] = clone $relation;
            } elseif (is_array($relation)) {
                $this->relations[$name] = array_map(fn($r) => $r instanceof self ? clone $r : $r, $relation);
            }
        }
        $this->exists = false;
        unset($this->attributes[$this->primaryKey]);
    }

    public function __isset(string $name): bool
    {
        return array_key_exists($name, $this->attributes) || array_key_exists($name, $this->relations);
    }

    public function __call(string $method, array $args)
    {
        $proxiedMethods = ['whereIn', 'whereNull', 'whereNotNull', 'whereBetween',
            'orderBy', 'groupBy', 'having', 'limit', 'leftJoin', 'rightJoin',
            'join', 'count', 'sum', 'avg', 'max', 'min', 'chunk', 'first',
            'fetch', 'fetchAll', 'value'];

        if (in_array($method, $proxiedMethods, true)) {
            return call_user_func_array([$this->newQuery(), $method], $args);
        }

        $scopeMethod = 'scope' . ucfirst($method);
        if (method_exists($this, $scopeMethod)) {
            array_unshift($args, $this->newQuery());
            return call_user_func_array([$this, $scopeMethod], $args);
        }

        throw new \BadMethodCallException(sprintf('Method %s::%s does not exist', static::class, $method));
    }

    public static function __callStatic(string $method, array $args)
    {
        $allowedMethods = ['where', 'whereIn', 'whereNull', 'whereNotNull', 'whereBetween',
            'orderBy', 'groupBy', 'having', 'limit', 'leftJoin', 'rightJoin',
            'join', 'count', 'sum', 'avg', 'max', 'min', 'chunk', 'first',
            'fetch', 'fetchAll', 'value', 'all', 'find', 'findBy', 'create',
            'update', 'delete', 'paginate', 'select', 'eagerLoad'];

        if (!in_array($method, $allowedMethods, true)) {
            $instance = new static();
            $scopeMethod = 'scope' . ucfirst($method);
            if (method_exists($instance, $scopeMethod)) {
                array_unshift($args, $instance->newQuery());
                return call_user_func_array([$instance, $scopeMethod], $args);
            }
            throw new \BadMethodCallException(sprintf('Method %s::%s does not exist', static::class, $method));
        }

        if ($method === 'eagerLoad') {
            return call_user_func_array([static::class, $method], $args);
        }

        $instance = new static();
        return call_user_func_array([$instance, $method], $args);
    }
}