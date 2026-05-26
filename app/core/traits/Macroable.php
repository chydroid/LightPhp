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
     * @var array<string, callable>
     */
    protected static array $macros = [];

    /**
     * 注册一个宏方法
     *
     * 将一个可调用的回调函数注册到指定名称下，之后即可通过该名称调用该宏。
     *
     * @param string $name 宏名称，即后续调用的方法名
     * @param callable $macro 宏对应的可调用结构，通常为闭包
     */
    public static function macro(string $name, callable $macro): void
    {
        static::$macros[$name] = $macro;
    }

    /**
     * 将一个混入对象的所有公共方法注册为宏
     *
     * 遍历混入对象的所有公共方法，将每个方法注册为同名的宏。
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

            if (! $replace && static::hasMacro($name)) {
                continue;
            }

            $closure = $method->getClosure($mixin);

            static::macro($name, $closure);
        }
    }

    /**
     * 检查指定名称的宏是否已注册
     *
     * @param string $name 宏名称
     * @return bool 如果该名称的宏已注册则返回 true，否则返回 false
     */
    public static function hasMacro(string $name): bool
    {
        return isset(static::$macros[$name]);
    }

    /**
     * 清空所有已注册的宏
     *
     * 移除当前类中所有已注册的宏方法，使类恢复到未注册任何宏的状态。
     */
    public static function flushMacros(): void
    {
        static::$macros = [];
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
        if (! static::hasMacro($method)) {
            throw new \BadMethodCallException(
                sprintf('方法 %s::%s 不存在', static::class, $method)
            );
        }

        $macro = static::$macros[$method];

        if ($macro instanceof \Closure) {
            $macro = $macro->bindTo($this, static::class);
        }

        return $macro(...$args);
    }
}
