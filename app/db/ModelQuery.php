<?php declare(strict_types=1);

namespace db;

/**
 * 模型感知的查询链
 *
 * ## 为什么需要它
 *
 * `Model::where(...)` 此前直接返回裸 `QueryBuilder`，其 `fetch()` / `fetchAll()`
 * 产出的是**行数组**，不经过 `Model::toArray()`，因此模型的 `$hidden`
 * （敏感字段隐藏）、`$casts`（类型转换）全部不生效。实测：
 *
 * ```php
 * class User extends \model\Model { protected array $hidden = ['password']; }
 *
 * User::where('id', 1)->first();   // ['id'=>1, ..., 'password'=>'p']  ← 明文泄漏
 * User::find(1)->toArray();        // ['id'=>1, ...]                    ← 已隐藏
 * ```
 *
 * 控制器里一句 `return $this->json($rows)` 就会把密码哈希吐给客户端。
 *
 * 本类让查询链的**终结方法直接返回 Model 实例**，于是 `toArray()` /
 * `toJson()` / `json_encode()` 全部自动套用 `$hidden` 与 `$casts`——
 * 查询链默认安全，不再依赖开发者记得手动过滤。
 *
 * ## 用法
 *
 * ```php
 * $rows  = User::where('status', 1)->fetchAll();   // Model[]
 * echo json_encode($rows);                          // 自动隐藏 password
 *
 * User::where('id', 1)->first();                    // ?User
 * User::where('status', 1)->paginate(15);           // ['items' => Model[], ...]
 * ```
 *
 * 链式调用（`orderBy()` / `limit()` / `where()` ...）会原样转发给内部
 * `QueryBuilder`，因此功能与原来完全一致，只是终结方法的返回类型变了。
 *
 * @see QueryBuilder
 */
class ModelQuery
{
    private QueryBuilder $query;

    /** @var class-string<\model\Model> */
    private string $modelClass;

    /**
     * @param QueryBuilder $query 底层查询构造器
     * @param class-string<\model\Model> $modelClass 结果要 hydrate 成哪个模型
     */
    public function __construct(QueryBuilder $query, string $modelClass)
    {
        $this->query = $query;
        $this->modelClass = $modelClass;
    }

    /**
     * 取出底层 QueryBuilder
     *
     * 供确实需要原始行数组的场景使用（如自定义聚合、调试）。
     * 正常使用请直接调用本类的方法，它们返回模型实例。
     *
     * @return QueryBuilder 底层查询构造器
     */
    public function toBase(): QueryBuilder
    {
        return $this->query;
    }

    /**
     * @return class-string<\model\Model> 结果模型类
     */
    public function getModelClass(): string
    {
        return $this->modelClass;
    }

    /**
     * 把行数组批量转为模型实例
     *
     * @param array<int|string,mixed> $rows 行数组
     * @return list<\model\Model> 模型实例
     */
    private function hydrate(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->modelClass::makeFromRow((array) $row);
        }
        return $out;
    }

    /**
     * 取第一行
     *
     * @return \model\Model|null 模型实例；无结果时为 null
     */
    public function fetch(): ?\model\Model
    {
        $row = $this->query->fetch();
        return $row === null ? null : $this->modelClass::makeFromRow((array) $row);
    }

    /**
     * 取全部行
     *
     * @return list<\model\Model> 模型实例列表
     */
    public function fetchAll(): array
    {
        return $this->hydrate($this->query->fetchAll());
    }

    /**
     * 取第一行（fetch 的别名）
     *
     * @return \model\Model|null 模型实例；无结果时为 null
     */
    public function first(): ?\model\Model
    {
        $row = $this->query->first();
        return $row === null ? null : $this->modelClass::makeFromRow((array) $row);
    }

    /**
     * 分页；items 已 hydrate 成模型实例
     *
     * @param int $perPage 每页条数
     * @param int $page 当前页码
     * @return array{items: list<\model\Model>, total: int, per_page: int, current_page: int, last_page: int, has_more: bool}
     */
    public function paginate(int $perPage = 15, int $page = 1): array
    {
        $result = $this->query->paginate($perPage, $page);
        $result['items'] = $this->hydrate($result['items']);
        return $result;
    }

    /**
     * 分块处理；回调收到的是模型实例列表
     *
     * @param int $count 每块条数
     * @param callable $cb 回调，接收 list<\model\Model>
     * @return void
     */
    public function chunk(int $count, callable $cb): void
    {
        $this->query->chunk($count, fn(array $rows) => $cb($this->hydrate($rows)));
    }

    /**
     * 按主键分块处理（写入场景安全）
     *
     * @param int $count 每块条数
     * @param callable $cb 回调，接收 list<\model\Model>
     * @param string $column 主键列
     * @return void
     */
    public function chunkById(int $count, callable $cb, string $column = 'id'): void
    {
        $this->query->chunkById($count, fn(array $rows) => $cb($this->hydrate($rows)), $column);
    }

    /**
     * 转发其余方法到 QueryBuilder
     *
     * 若返回的是 QueryBuilder（链式调用），会重新包成本类以保持模型感知；
     * 标量聚合（count/sum/value 等）原样返回。
     *
     * @param string $name 方法名
     * @param array $args 参数
     * @return mixed
     */
    public function __call(string $name, array $args): mixed
    {
        $result = $this->query->{$name}(...$args);
        if ($result instanceof QueryBuilder) {
            return new self($result, $this->modelClass);
        }
        return $result;
    }
}