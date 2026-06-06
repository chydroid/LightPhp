<?php
declare(strict_types=1);

namespace core;

/**
 * Facade 门面基类 - 参考 Laravel Facade
 * 提供静态代理访问容器中的服务
 *
 * 使用方式:
 *   class Cache extends Facade { protected static function getFacadeAccessor(): string { return 'cache'; } }
 *   Cache::set('key', 'value');
 */
abstract class Facade
{
    /** @var Container|null */
    protected static ?Container $container = null;

    /** @var array<string, object> 已解析门面实例缓存 */
    protected static array $resolved = [];

    public static function setContainer(Container $container): void
    {
        static::$container = $container;
    }

    abstract protected static function getFacadeAccessor(): string;

    protected static function resolve(): object
    {
        $accessor = static::getFacadeAccessor();

        if (isset(static::$resolved[static::class])) {
            return static::$resolved[static::class];
        }

        if (static::$container === null) {
            throw new \RuntimeException('Facade container not set. Call Facade::setContainer() during boot.');
        }

        $instance = static::$container->get($accessor);
        static::$resolved[static::class] = $instance;

        return $instance;
    }

    public static function __callStatic(string $method, array $args): mixed
    {
        $instance = static::resolve();
        return $instance->$method(...$args);
    }

    public static function clearResolved(): void
    {
        static::$resolved = [];
    }
}