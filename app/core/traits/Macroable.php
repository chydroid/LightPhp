<?php

declare(strict_types=1);

namespace core\traits;

/**
 * Macroable 特征
 *
 * 为类提供宏（Macro）能力，允许在运行时动态地为类添加方法。
 * 该特征借鉴了 Laravel 的 Macroable 设计，支持通过闭包或回调动态扩展类的行为，
 * 也支持通过混入（Mixin）对象批量注册宏方法。
 */
trait Macroable
{
    /**
     * 已注册的宏列表
     *
     * 二维存储：[声明类名 => [宏名 => callable]]。
     *
     * 不能直接用单层 `static::$macros[]`：静态属性在继承链中被共享，
     * 任一子类注册宏都会污染父类与所有兄弟类，flushMacros() 也会连带清空他人
     * （与 JsonResource::resolveWrap() 处理 $wrap 的问题同源）。
     * 这里按「实际注册的那个类」隔离存储，查找时再沿继承链向上回溯，
     * 从而保证：子类仍能继承父类的宏，但兄弟类之间互不可见、
     * flushMacros() 只影响调用它的那一类。
     *
     * @var array<string, array<string, callable>>
     */
    protected static array $macros = [];

    /**
     * 沿继承链查找宏：先看本类，再逐级向上看父类
     *
     * @return callable|null 未注册时返回 null
     */
    protected static function findMacro(string $name): ?callable
    {
        $class = static::class;
        while ($class !== false) {
            if (isset(self::$macros[$class][$name])) {
                return self::$macros[$class][$name];
            }
            $class = get_parent_class($class) ?: false;
        }

        return null;
    }

    /**
     * 注册一个宏方法
     *
     * 将一个可调用的回调函数注册到指定名称下，之后即可通过该名称调用该宏。
     *
     * @param string $name 宏名称，即后续调用的方法名
     * @param callable $macro 宏对应的可调用的结构，通常为闭包
     */
    public static function macro(string $name, callable $macro): void
    {
        self::$macros[static::class][$name] = $macro;
    }

    /**
     * 将一个混入对象的所有公共方法注册为宏
     *
     * 遍历混入对象的所有公共方法，将每个方法**调用后返回的闭包**注册为同名宏。
     * 因此混入方法必须是「零参数工厂」；无法在不传参的情况下调用的方法
     * （如 `greet(string $who)`）会被跳过，而不会让整个 mixin() 崩溃。
     * 可通过 $replace 参数控制是否覆盖已存在的同名宏。
     *
     * @param object $mixin 混入对象，其公共方法将被注册为宏
     * @param bool $replace 是否覆盖已存在的同名宏，默认为 true
     */
    public static function mixin(object $mixin, bool $replace = true): void
    {
        $reflection = new \ReflectionClass($mixin);

        $methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);

        foreach ($methods as $method) {
            $name = $method->getName();

            // 魔术方法（含显式声明的 __construct）与静态方法都不是宏工厂
            if ($name === '__construct' || str_starts_with($name, '__') || $method->isAbstract()) {
                continue;
            }

            if (! $replace && static::hasMacro($name)) {
                continue;
            }

            $args = static::mixinArguments($method, $mixin);
            if ($args === null) {
                continue;
            }

            $closure = $method->invokeArgs($mixin, $args);

            if (! is_callable($closure)) {
                continue;
            }

            static::macro($name, $closure);
        }
    }

    /**
     * 为 mixin 工厂方法推导调用实参
     *
     * 必填参数若其类型声明可接受混入对象自身，则传入 $mixin
     * （对应 Laravel 的 mixin 惯例：`function f(Foo $foo)`）；
     * 无法在不臆造值的前提下满足的参数一律放弃该方法（返回 null）。
     *
     * @return array<int, mixed>|null 无法调用时返回 null
     */
    protected static function mixinArguments(\ReflectionMethod $method, object $mixin): ?array
    {
        $args = [];
        foreach ($method->getParameters() as $parameter) {
            if ($parameter->isOptional()) {
                break;
            }
            $type = $parameter->getType();
            // 无类型声明或 mixed：可传入混入对象
            if ($type === null || ($type instanceof \ReflectionNamedType && $type->getName() === 'mixed')) {
                $args[] = $mixin;
                continue;
            }
            // 只在类型确实接受混入对象时才传，否则放弃该方法
            if (static::typeAcceptsMixin($type, $mixin)) {
                $args[] = $mixin;
                continue;
            }
            return null;
        }

        return $args;
    }

    /**
     * 判断参数类型是否接受给定的混入对象
     */
    private static function typeAcceptsMixin(\ReflectionType $type, object $mixin): bool
    {
        $types = $type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType
            ? $type->getTypes()
            : [$type];

        foreach ($types as $single) {
            if (! $single instanceof \ReflectionNamedType) {
                continue;
            }
            $name = $single->getName();
            if ($single->isBuiltin()) {
                if ($name === 'mixed' || $name === 'object') {
                    return true;
                }
                continue;
            }
            if ($mixin instanceof $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * 检查指定名称的宏是否已注册
     *
     * @param string $name 宏名称
     * @return bool 如果该名称的宏已注册则返回 true，否则返回 false
     */
    public static function hasMacro(string $name): bool
    {
        return static::findMacro($name) !== null;
    }

    /**
     * 清空当前类自身注册的所有宏
     *
     * 只清空调用它的这一类所注册的宏（父类与兄弟类的宏不受影响）。
     */
    public static function flushMacros(): void
    {
        unset(self::$macros[static::class]);
    }

    /**
     * 动态调用宏方法
     *
     * 当调用类中不存在的方法时，会尝试查找已注册的宏并执行。
     * 如果宏是一个闭包（Closure），则会将其绑定到当前类的实例上，
     * 使得闭包内部可以使用 $this 访问当前对象的属性和方法。
     *
     * @param string $method 被调用的方法名
     * @param array $args 传递给方法的参数列表
     * @return mixed 宏方法的返回值
     * @throws \BadMethodCallException 当指定名称的宏不存在时抛出异常
     */
    public function __call(string $method, array $args)
    {
        $macro = static::findMacro($method);
        if ($macro === null) {
            throw new \BadMethodCallException(
                sprintf('方法 %s::%s 不存在', static::class, $method)
            );
        }

        if ($macro instanceof \Closure && ! static::isStaticClosure($macro)) {
            // 静态闭包（static fn () => ...）不能绑定实例，
            // 直接使用原闭包，否则会得到 "Value of type null is not callable"。
            $bound = $macro->bindTo($this, static::class);
            if ($bound !== null) {
                $macro = $bound;
            }
        }

        return $macro(...$args);
    }

    /**
     * 判断闭包是否为 static 闭包
     *
     * static 闭包不能绑定实例（bindTo() 会返回 null 并抛 Warning），
     * 因此必须在调用 bindTo() 之前先判定。
     */
    private static function isStaticClosure(\Closure $closure): bool
    {
        return (new \ReflectionFunction($closure))->isStatic();
    }

    /**
     * 动态静态调用宏方法
     *
     * 当静态调用类中不存在的方法时，会尝试查找已注册的宏并执行。
     *
     * @param string $method 被调用的方法名
     * @param array $args 传递给方法的参数列表
     * @return mixed 宏方法的返回值
     * @throws \BadMethodCallException 当指定名称的宏不存在时抛出异常
     */
    public static function __callStatic(string $method, array $args)
    {
        $macro = static::findMacro($method);
        if ($macro === null) {
            throw new \BadMethodCallException(
                sprintf('方法 %s::%s 不存在', static::class, $method)
            );
        }

        if ($macro instanceof \Closure) {
            $bound = $macro->bindTo(null, static::class);
            if ($bound !== null) {
                $macro = $bound;
            }
        }

        return $macro(...$args);
    }
}
