<?php

declare(strict_types=1);

namespace core;

/**
 * 管道类，用于中间件的洋葱模型执行
 *
 * 实现类似 Laravel 的 Pipeline 模式，将多个中间件按洋葱模型依次包裹，
 * 请求从外层向内层传递，响应从内层向外层返回。
 */
class Pipeline
{
    /**
     * 通过管道传递的对象（通常是请求对象）
     */
    private mixed $passable = null;

    /**
     * 管道数组（中间件数组）
     *
     * @var array<int, mixed>
     */
    private array $pipes = [];

    /**
     * 在每个管道上调用的方法名
     */
    private string $method = 'handle';

    /**
     * 容器实例，用于依赖注入
     */
    private ?Container $container = null;

    /**
     * 设置通过管道传递的对象
     *
     * @param mixed $passable 通过管道传递的对象（通常是请求对象）
     * @return self 返回自身以支持链式调用
     */
    public function send(mixed $passable): self
    {
        $this->passable = $passable;

        return $this;
    }

    /**
     * 设置管道数组（中间件数组）
     *
     * @param array $pipes 管道数组（中间件数组）
     * @return self 返回自身以支持链式调用
     */
    public function through(array $pipes): self
    {
        $this->pipes = $pipes;

        return $this;
    }

    /**
     * 设置在每个管道上调用的方法名
     *
     * @param string $method 方法名
     * @return self 返回自身以支持链式调用
     */
    public function via(string $method): self
    {
        $this->method = $method;

        return $this;
    }

    /**
     * 设置容器实例，用于依赖注入
     *
     * @param Container $container 容器实例
     * @return self 返回自身以支持链式调用
     */
    public function viaContainer(Container $container): self
    {
        $this->container = $container;

        return $this;
    }

    /**
     * 运行管道并传入目标闭包
     *
     * 将所有中间件按洋葱模型包裹目标闭包，依次执行。
     * 中间件从数组末尾开始向前包裹，执行时从数组开头开始依次调用。
     *
     * @param \Closure $destination 目标闭包（管道的最内层处理逻辑）
     * @return mixed 管道执行完毕后的返回值
     */
    public function then(\Closure $destination): mixed
    {
        $pipeline = array_reduce(
            array_reverse($this->pipes),
            $this->carry(),
            $destination
        );

        return $pipeline($this->passable);
    }

    /**
     * 运行管道并返回结果
     *
     * 目标闭包直接返回传递的对象，即管道最内层不做额外处理，
     * 仅将请求对象原样返回。
     *
     * @return mixed 管道执行完毕后的返回值
     */
    public function thenReturn(): mixed
    {
        return $this->then(fn ($passable) => $passable);
    }

    /**
     * 获取表示管道洋葱切片的闭包
     *
     * 返回一个闭包，该闭包接收上一次迭代的闭包（$stack）和当前管道（$pipe），
     * 返回一个新的闭包。新闭包在被调用时，会将传递的对象和上一次的闭包
     * 作为参数传给当前管道的指定方法，从而实现洋葱模型的层层包裹。
     *
     * 每个中间件的签名应为：handle(mixed $passable, \Closure $next): mixed
     * 其中 $passable 为请求对象，$next 为下一层管道的闭包。
     *
     * @return \Closure 返回用于 array_reduce 的迭代闭包
     */
    public function carry(): \Closure
    {
        return fn (\Closure $stack, mixed $pipe) => function (mixed $passable) use ($stack, $pipe) {
            if (is_string($pipe) && class_exists($pipe)) {
                $instance = $this->container ? $this->container->get($pipe) : new $pipe();
                if (!method_exists($instance, $this->method)) {
                    throw new \RuntimeException(sprintf('Middleware [%s] missing method [%s]', $pipe, $this->method));
                }
                return $instance->{$this->method}($passable, $stack);
            }
            if (is_callable($pipe)) {
                return $pipe($passable, $stack);
            }
            if (is_object($pipe)) {
                if (!method_exists($pipe, $this->method)) {
                    throw new \RuntimeException(sprintf('Middleware [%s] missing method [%s]', get_class($pipe), $this->method));
                }
                return $pipe->{$this->method}($passable, $stack);
            }
            throw new \RuntimeException('Invalid pipe type: ' . gettype($pipe));
        };
    }
}
