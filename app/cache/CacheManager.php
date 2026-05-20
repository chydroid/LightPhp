<?php
declare(strict_types=1);

namespace cache;

use core\contract\CacheInterface;

/**
 * 缓存管理器
 * 
 * 负责管理多个缓存驱动，支持文件、Redis、Memcached 等驱动。
 * 通过动态代理实现对默认驱动的直接调用。
 */
class CacheManager
{
    /** @var array<string, CacheInterface> 已解析的驱动实例 */
    private array $drivers = [];

    /** @var array<string, array> 驱动配置 */
    private array $config;

    /** @var array<string, callable> 自定义驱动创建器 */
    private array $customCreators = [];

    /** @var string 默认驱动名称 */
    private string $defaultDriver;

    /**
     * 构造函数
     * 
     * @param array $config 缓存配置数组
     */
    public function __construct(array $config)
    {
        $this->config = $config['stores'] ?? [];
        $this->defaultDriver = $config['default'] ?? 'file';
    }

    /**
     * 获取指定驱动实例
     * 
     * @param string|null $name 驱动名称，为 null 时使用默认驱动
     * @return CacheInterface 缓存驱动实例
     */
    public function driver(?string $name = null): CacheInterface
    {
        $name = $name ?? $this->defaultDriver;

        if (isset($this->drivers[$name])) {
            return $this->drivers[$name];
        }

        return $this->drivers[$name] = $this->resolve($name);
    }

    /**
     * 切换默认驱动
     * 
     * @param string $name 驱动名称
     */
    public function setDefaultDriver(string $name): void
    {
        $this->defaultDriver = $name;
    }

    /**
     * 获取默认驱动名
     * 
     * @return string 默认驱动名称
     */
    public function getDefaultDriver(): string
    {
        return $this->defaultDriver;
    }

    /**
     * 注册自定义驱动创建器
     * 
     * @param string $name 驱动名称
     * @param callable $resolver 驱动创建回调
     */
    public function extend(string $name, callable $resolver): void
    {
        $this->customCreators[$name] = $resolver;
        unset($this->drivers[$name]);
    }

    /**
     * 获取所有已解析的驱动名
     * 
     * @return string[] 驱动名称数组
     */
    public function getResolvedDrivers(): array
    {
        return array_keys($this->drivers);
    }

    /**
     * 解析驱动实例
     * 
     * @param string $name 驱动名称
     * @return CacheInterface 缓存驱动实例
     * @throws \InvalidArgumentException 当驱动不支持时
     */
    private function resolve(string $name): CacheInterface
    {
        // 优先使用自定义创建器
        if (isset($this->customCreators[$name])) {
            return $this->customCreators[$name]();
        }

        $storeConfig = $this->config[$name] ?? [];
        $driver = $storeConfig['driver'] ?? $name;

        // 根据驱动类型创建实例
        return match ($driver) {
            'file'      => new FileCache($storeConfig),
            'redis'     => new RedisCache($storeConfig),
            'memcached' => new MemcachedCache($storeConfig),
            default     => throw new \InvalidArgumentException("Unsupported cache driver: {$driver}. Supported drivers: file, redis, memcached"),
        };
    }

    /**
     * 动态代理调用到默认驱动
     * 
     * @param string $method 方法名
     * @param array $args 参数数组
     * @return mixed 方法返回值
     */
    public function __call(string $method, array $args): mixed
    {
        return $this->driver()->$method(...$args);
    }
}