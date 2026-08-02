<?php
declare(strict_types=1);

namespace core;

/**
 * API Resource 基类 - 参考 Laravel JsonResource，零依赖实现
 *
 * 用于将模型/数组转换为统一的 API 输出结构，分离数据层与表现层。
 * 子类重写 toArray(Request) 定义单资源输出字段；可选重写 with(Request) 提供附加元数据。
 *
 * 用法：
 *   class UserResource extends \core\JsonResource {
 *       public function toArray($request): array {
 *           return ['id' => $this->resource['id'], 'name' => $this->resource['name']];
 *       }
 *   }
 *   // 控制器（单资源）：
 *   return (new UserResource($user))->response();
 *   // 集合：
 *   return UserResource::collection($users)->response();
 */
class JsonResource
{
    /** @var string|null 包装键；为 null 时不包装。子类可重写。 */
    public static ?string $wrap = 'data';

    /** @var mixed 被包装的资源（单资源模式） */
    protected mixed $resource;

    /** @var array 附加的顶层元数据（与 with() 合并） */
    protected array $additional = [];

    /** @var array|null 集合模式下保存的 items（仅 static::collection() 创建的实例使用） */
    protected ?array $collectionItems = null;

    /**
     * @param mixed $resource 模型实例或数组
     */
    public function __construct(mixed $resource = null)
    {
        $this->resource = $resource;
    }

    /**
     * 子类重写以定义单资源输出字段
     *
     * @param Request|null $request
     * @return array<string, mixed>
     */
    public function toArray(?Request $request = null): array
    {
        return $this->resourceToArray($this->resource);
    }

    /**
     * 默认附加数据（子类可重写）
     *
     * @param Request|null $request
     * @return array<string, mixed>
     */
    public function with(?Request $request = null): array
    {
        return [];
    }

    /**
     * 追加顶层元数据（链式调用）
     *
     * @param array<string, mixed> $data
     * @return $this
     */
    public function additional(array $data): static
    {
        $this->additional = array_merge($this->additional, $data);
        return $this;
    }

    /**
     * 解析为最终输出数组（含包装键与附加元数据）
     *
     * @param Request|null $request
     * @return array<string, mixed>
     */
    public function resolve(?Request $request = null): array
    {
        $meta = array_merge($this->with($request), $this->additional);

        // 集合模式：对每个 item 实例化本类，调用其 toArray
        if ($this->collectionItems !== null) {
            $items = [];
            foreach ($this->collectionItems as $item) {
                $child = new static($item);
                $items[] = $child->toArray($request);
            }
            return array_merge(['data' => $items], $meta);
        }

        // 单资源模式
        $data = $this->toArray($request);
        if (static::$wrap === null) {
            return array_merge($data, $meta);
        }
        return array_merge([static::$wrap => $data], $meta);
    }

    /**
     * 创建集合实例
     *
     * @param iterable<mixed> $items
     * @return static
     */
    public static function collection(iterable $items): static
    {
        $instance = new static();
        $instance->collectionItems = is_array($items) ? $items : iterator_to_array($items);
        return $instance;
    }

    /**
     * 构造 JSON 响应
     *
     * @param Request|null $request
     * @param int $status
     * @return Response
     */
    public function response(?Request $request = null, int $status = 200): Response
    {
        return Response::json($this->resolve($request), $status);
    }

    /**
     * 获取被包装的原始资源
     */
    public function getResource(): mixed
    {
        return $this->resource;
    }

    /**
     * 默认 toArray 实现：将模型/数组转换为数组
     */
    protected function resourceToArray(mixed $resource): array
    {
        if ($resource === null) {
            return [];
        }
        if (is_array($resource)) {
            return $resource;
        }
        if (is_object($resource) && method_exists($resource, 'toArray')) {
            return $resource->toArray();
        }
        if ($resource instanceof \JsonSerializable) {
            $v = $resource->jsonSerialize();
            return is_array($v) ? $v : ['value' => $v];
        }
        return (array) $resource;
    }
}
