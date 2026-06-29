<?php
declare(strict_types=1);

namespace core;

use core\contract\NotFoundException;
use core\contract\PsrContainerInterface;

/**
 * 依赖注入容器
 * 
 * 实现 PSR-11 容器接口，提供依赖解析、服务绑定和单例管理功能。
 * 支持反射自动解析构造函数依赖。
 */
class Container implements PsrContainerInterface
{
    /** @var self|null 单例实例 */
    private static ?self $instance = null;

    /** @var array<string, callable> 服务绑定映射 */
    private array $bindings = [];

    /** @var array<string, object> 单例实例缓存 */
    private array $instances = [];

    /** @var array<string, string> 别名映射 */
    private array $aliases = [];

    /** @var array<string, \ReflectionClass> 反射类缓存 */
    private array $reflectionCache = [];

    /** @var array<string, \ReflectionParameter[]> 构造函数参数缓存 */
    private array $constructorParamCache = [];

    /**
     * 获取容器单例实例
     * 
     * @return self|null 容器实例
     */
    public static function getInstance(): ?self
    {
        return self::$instance;
    }

    /**
     * 设置容器单例实例
     * 
     * @param self $container 容器实例
     */
    public static function setInstance(self $container): void
    {
        self::$instance = $container;
    }

    /**
     * 绑定服务到容器
     * 
     * @param string $abstract 抽象接口或类名
     * @param callable $concrete 服务提供者回调
     */
    public function bind(string $abstract, callable $concrete): void
    {
        unset($this->instances[$abstract]);
        $this->bindings[$abstract] = $concrete;
    }

    /**
     * 绑定单例服务
     * 
     * 单例服务只会被解析一次，后续调用返回相同实例。
     * 
     * @param string $abstract 抽象接口或类名
     * @param callable $concrete 服务提供者回调
     */
    public function singleton(string $abstract, callable $concrete): void
    {
        unset($this->instances[$abstract]);
        $this->bindings[$abstract] = function ($container) use ($abstract, $concrete) {
            if (!array_key_exists($abstract, $this->instances)) {
                $this->instances[$abstract] = $concrete($container);
            }
            return $this->instances[$abstract];
        };
    }

    /**
     * 直接绑定实例
     * 
     * 将已实例化的对象绑定到容器。
     * 
     * @param string $abstract 抽象接口或类名
     * @param mixed $instance 对象实例
     */
    public function instance(string $abstract, mixed $instance): void
    {
        $this->instances[$abstract] = $instance;
        $this->bindings[$abstract] = fn ($container) => $instance;
    }

    /**
     * 从容器中解析服务（PSR-11）
     * 
     * @param string $abstract 抽象接口或类名
     * @return mixed 解析后的服务实例
     * @throws NotFoundException 当服务无法解析时
     */
    public function get(string $abstract): mixed
    {
        return $this->resolved($abstract);
    }

    /**
     * 从容器中解析服务，支持传入参数
     * 
     * @param string $abstract 抽象接口或类名
     * @param array $parameters 构造函数参数
     * @return mixed 解析后的服务实例
     */
    public function make(string $abstract, array $parameters = []): mixed
    {
        return $this->resolved($abstract, $parameters);
    }

    /** @var array<string, bool> 别名解析路径追踪，防止循环引用 */
    private array $aliasResolving = [];

    /** @var array<string, bool> 构建路径追踪，防止循环依赖 */
    private array $building = [];

    /**
     * 解析服务
     * 
     * 按以下顺序尝试解析：
     * 1. 检查实例缓存
     * 2. 检查绑定回调
     * 3. 检查别名映射
     * 4. 使用反射自动解析
     * 
     * @param string $abstract 抽象接口或类名
     * @param array $parameters 构造函数参数
     * @return mixed 解析后的服务实例
     */
    private function resolved(string $abstract, array $parameters = []): mixed
    {
        if (array_key_exists($abstract, $this->instances)) {
            return $this->instances[$abstract];
        }

        if (isset($this->bindings[$abstract])) {
            $concrete = $this->bindings[$abstract];
            return $concrete($this);
        }

        if (isset($this->aliases[$abstract])) {
            if (isset($this->aliasResolving[$abstract])) {
                $chain = implode(' -> ', array_keys($this->aliasResolving)) . ' -> ' . $abstract;
                throw new NotFoundException("Circular alias detected: {$chain}");
            }
            $this->aliasResolving[$abstract] = true;
            try {
                $result = $this->resolved($this->aliases[$abstract], $parameters);
            } finally {
                unset($this->aliasResolving[$abstract]);
            }
            return $result;
        }

        if (class_exists($abstract)) {
            return $this->build($abstract, $parameters);
        }

        throw new NotFoundException("Unable to resolve [{$abstract}] from container");
    }

    /**
     * 使用反射构建类实例
     * 
     * 自动解析构造函数依赖，支持类型提示注入。
     * 
     * @param string $class 类名
     * @param array $parameters 构造函数参数
     * @return object 实例化的对象
     */
    private function build(string $class, array $parameters = []): object
    {
        if (isset($this->building[$class])) {
            $chain = implode(' -> ', array_keys($this->building)) . ' -> ' . $class;
            throw new \RuntimeException("Circular dependency detected: {$chain}");
        }
        $this->building[$class] = true;

        try {
        // 使用反射缓存提高性能
        if (!isset($this->reflectionCache[$class])) {
            $this->reflectionCache[$class] = new \ReflectionClass($class);
        }
        $reflection = $this->reflectionCache[$class];

        if (!$reflection->isInstantiable()) {
            throw new NotFoundException("Class [{$class}] is not instantiable");
        }

        // 缓存构造函数参数
        $cacheKey = $class . '::__construct';
        if (!isset($this->constructorParamCache[$cacheKey])) {
            $constructor = $reflection->getConstructor();
            if ($constructor !== null) {
                $this->constructorParamCache[$cacheKey] = $constructor->getParameters();
            } else {
                $this->constructorParamCache[$cacheKey] = [];
            }
        }
        $params = $this->constructorParamCache[$cacheKey];

        // 无参数构造函数，直接实例化
        if ($params === []) {
            return new $class();
        }

        // 解析构造函数依赖
        $dependencies = [];
        foreach ($params as $parameter) {
            $type = $parameter->getType();

            // 如果参数有类型提示且不是内置类型，尝试从容器解析
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                try {
                    $dependencies[] = $this->get($type->getName());
                    continue;
                } catch (NotFoundException $e) {
                }
            }

            // 使用传入的参数或默认值
            if (array_key_exists($parameter->getName(), $parameters)) {
                $dependencies[] = $parameters[$parameter->getName()];
            } elseif ($parameter->isDefaultValueAvailable()) {
                $dependencies[] = $parameter->getDefaultValue();
            } elseif ($type !== null && $type->allowsNull()) {
                $dependencies[] = null;
            } else {
                throw new \RuntimeException(
                    "Unable to resolve parameter [{$parameter->getName()}] for [{$class}]"
                );
            }
        }

        return $reflection->newInstanceArgs($dependencies);
        } finally {
            unset($this->building[$class]);
        }
    }

    /**
     * 检查容器是否有指定服务（PSR-11）
     *
     * PSR-11 契约要求：has() 返回 true 时，get() 不得抛 NotFoundExceptionInterface。
     * 因此对 class_exists 但不可实例化的抽象类/接口/trait 返回 false。
     *
     * @param string $abstract 抽象接口或类名
     * @return bool 是否存在
     */
    public function has(string $abstract): bool
    {
        if (isset($this->aliases[$abstract])) {
            return $this->has($this->aliases[$abstract]);
        }
        if (isset($this->bindings[$abstract])
            || array_key_exists($abstract, $this->instances)) {
            return true;
        }
        if (!class_exists($abstract)) {
            return false;
        }
        try {
            return (new \ReflectionClass($abstract))->isInstantiable();
        } catch (\ReflectionException $e) {
            return false;
        }
    }

    /**
     * 为服务添加别名
     * 
     * @param string $alias 别名
     * @param string $abstract 目标服务
     */
    public function alias(string $alias, string $abstract): void
    {
        $this->aliases[$alias] = $abstract;
    }

    /**
     * 移除指定服务
     * 
     * @param string $abstract 抽象接口或类名
     */
    public function forget(string $abstract): void
    {
        unset($this->bindings[$abstract], $this->instances[$abstract], $this->aliases[$abstract]);
    }

    /**
     * 清空容器所有服务和缓存
     */
    public function flush(): void
    {
        $this->bindings = [];
        $this->instances = [];
        $this->aliases = [];
        $this->reflectionCache = [];
        $this->constructorParamCache = [];
        $this->building = [];
        $this->aliasResolving = [];
    }
}