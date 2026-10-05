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
    /**
     * 解析最终输出时使用的包装键
     *
     * 不能直接读 static::$wrap：静态属性在继承链中被共享，
     * 任一子类设置 $wrap = 'items' 会同时污染其父类与所有兄弟类
     * （实测 A 设 items 后，B/C 也变成 items）。
     * 这里按「子类是否显式声明了自己的 $wrap」逐层回溯取值。
     *
     * @return string|null 包装键；null 表示不包装
     */
    protected static function resolveWrap(): ?string
    {
        $class = static::class;
        while ($class !== false) {
            $ref = new \ReflectionClass($class);
            if ($ref->hasProperty('wrap')) {
                $prop = $ref->getProperty('wrap');
                // 只认「本类自己声明的」$wrap，父类声明的继续向上回溯
                if ($prop->getDeclaringClass()->getName() === $class) {
                    $value = $prop->isStatic() ? $prop->getValue() : null;
                    return is_string($value) ? $value : null;
                }
            }
            $parent = $ref->getParentClass();
            $class = $parent ? $parent->getName() : false;
        }

        return 'data';
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
        $wrap = static::resolveWrap();

        // 集合模式：对每个 item 实例化本类，调用其 toArray
        if ($this->collectionItems !== null) {
            $items = [];
            foreach ($this->collectionItems as $item) {
                $child = $this->makeForCollection($item);
                $items[] = $child->toArray($request);
            }
            // 此前硬编码 'data'，使子类设置 public static ?string $wrap = 'items'
            // 在集合模式下完全失效（单资源用 items、集合仍用 data）。
            // $wrap 为 null 时不包装，与单资源模式语义保持一致。
            if ($wrap === null) {
                return static::mergeWithMeta($items, $meta, null);
            }
            return static::mergeWithMeta($items, $meta, $wrap);
        }

        // 单资源模式
        $data = $this->toArray($request);
        return static::mergeWithMeta($data, $meta, $wrap);
    }

    /**
     * 合并资源数据与附加元数据
     *
     * 单资源模式与集合模式此前合并顺序不一致（`[$wrap => ...]` 在前 vs 在后），
     * 且都用 array_merge 让 $meta 里的同名键直接顶掉整块资源：
     * additional(['data' => ...]) 会让单资源输出变成 {"data":"OVERRIDDEN"}，
     * 资源数据彻底消失。这里统一为「资源在前、元数据在后」，并对保留的包装键
     * 做保护——交由调用方显式选择是不安全的，直接拒绝更明确。
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $meta
     * @param string|null $wrap 包装键；null 表示不包装（此时不保留任何保留键）
     * @return array<string, mixed>
     */
    protected static function mergeWithMeta(array $data, array $meta, ?string $wrap): array
    {
        if ($wrap !== null && array_key_exists($wrap, $meta)) {
            throw new \LogicException(
                sprintf('additional()/with() 不能覆盖保留的包装键 "%s"', $wrap)
            );
        }

        if ($wrap === null) {
            return array_merge($data, $meta);
        }

        return array_merge([$wrap => $data], $meta);
    }

    /**
     * 为集合中的单个元素创建资源实例
     *
     * 子类若声明了带必填参数的构造器，覆写本方法来提供自己的实例化逻辑。
     *
     * @param mixed $item
     * @return static
     */
    protected function makeForCollection(mixed $item): static
    {
        $reflection = new \ReflectionClass(static::class);
        $constructor = $reflection->getConstructor();
        $required = $constructor === null ? 0 : $constructor->getNumberOfRequiredParameters();

        if ($required <= 1) {
            return new static($item);
        }

        // 构造器需要 1 个以上必填参数时无法用单个 item 满足，
        // 集合模式下子类构造器本就无意义，跳过它并直接挂载 resource。
        /** @var static $instance */
        $instance = $reflection->newInstanceWithoutConstructor();
        $instance->resource = $item;

        return $instance;
    }

/**
     * 创建集合实例自身（不经过子类构造器）
     *
     * 子类若声明了带必填参数的构造器，`new static()` 会抛 ArgumentCountError。
     * 集合模式下本实例只承载 $collectionItems，构造器逻辑无意义，
     * 因此改用 newInstanceWithoutConstructor() 跳过，并把 $resource 置空。
     */
    private static function newCollectionInstance(): static
    {
        $reflection = new \ReflectionClass(static::class);
        $constructor = $reflection->getConstructor();
        $needsArgs = $constructor !== null && $constructor->getNumberOfRequiredParameters() > 0;

        if (! $needsArgs) {
            return new static();
        }

        /** @var static $instance */
        $instance = $reflection->newInstanceWithoutConstructor();
        $instance->resource = null;
        $instance->additional = [];

        return $instance;
    }

    /**
     * 创建集合实例
     *
     * @param iterable<mixed> $items
     * @return static
     */
    public static function collection(iterable $items): static
    {
        // 抽象类无法实例化，直接 new static() 只会抛出难以定位的
        // "Cannot instantiate abstract class" Error，这里给出明确提示。
        if ((new \ReflectionClass(static::class))->isAbstract()) {
            throw new \LogicException(sprintf(
                '资源类 [%s] 是抽象类，无法用于集合；请使用具体子类或覆写 makeForCollection()',
                static::class
            ));
        }

        $instance = self::newCollectionInstance();
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
