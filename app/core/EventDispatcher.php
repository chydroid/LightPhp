<?php
declare(strict_types=1);

namespace core;

/**
 * 轻量级事件调度器 - 参考 Laravel EventDispatcher
 * 支持通配符事件、优先级排序、停止传播
 */
class EventDispatcher
{
    /** @var array<string, array<int, array{listener: callable, priority: int}>> */
    private array $listeners = [];

    /** @var array<string, array<int, callable>> */
    private array $wildcardCache = [];

    /** @var array<string, string> 通配符正则预编译缓存 */
    private array $wildcardRegexCache = [];

    /** @var string[] 当前派发中的事件栈，用于检测递归 */
    private array $dispatchingStack = [];

    public function listen(string $event, callable $listener, int $priority = 0): void
    {
        $this->listeners[$event][] = [
            'listener' => $listener,
            'priority' => $priority,
        ];

        usort($this->listeners[$event], fn($a, $b) => $b['priority'] <=> $a['priority']);

        $this->wildcardCache = [];
        $this->wildcardRegexCache = [];
    }

    public function forget(string $event): void
    {
        unset($this->listeners[$event]);
        $this->wildcardCache = [];
        $this->wildcardRegexCache = [];
    }

    public function hasListeners(string $event): bool
    {
        if (isset($this->listeners[$event]) && !empty($this->listeners[$event])) {
            return true;
        }

        foreach ($this->listeners as $pattern => $listeners) {
            if ($this->matchWildcard($pattern, $event) && !empty($listeners)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 触发事件
     *
     * @param string $event 事件名
     * @param mixed ...$payload 载荷
     * @return array<int, mixed> 监听器返回结果
     */
    public function dispatch(string $event, mixed ...$payload): array
    {
        $results = [];

        // 检测同一事件的递归派发，而非禁止所有嵌套派发
        if (in_array($event, $this->dispatchingStack, true)) {
            trigger_error(
                "EventDispatcher: Recursive dispatch detected for event [{$event}]",
                E_USER_WARNING
            );
            return $results;
        }

        $this->dispatchingStack[] = $event;

        try {
            $listeners = $this->getListenersForEvent($event);

            foreach ($listeners as $listener) {
                try {
                    $result = $listener($event, ...$payload);
                    $results[] = $result;
                    if ($result === false) {
                        break;
                    }
                } catch (\Throwable $e) {
                    // 与 until() 一致：异常不塞进 results，记录日志后继续下一个监听器
                    error_log("EventDispatcher: listener for [{$event}] threw " . $e->getMessage());
                    continue;
                }
            }
        } finally {
            array_pop($this->dispatchingStack);
        }

        return $results;
    }

    /**
     * 触发事件（直到某个监听器返回非null值时停止传播）
     */
    public function until(string $event, mixed ...$payload): mixed
    {
        // 与 dispatch() 共享递归检测，避免监听器内调用 until() 触发同一事件导致无限递归
        if (in_array($event, $this->dispatchingStack, true)) {
            trigger_error(
                "EventDispatcher: Recursive dispatch detected for event [{$event}]",
                E_USER_WARNING
            );
            return null;
        }

        $this->dispatchingStack[] = $event;

        try {
            $listeners = $this->getListenersForEvent($event);

            foreach ($listeners as $listener) {
                try {
                    $result = $listener($event, ...$payload);
                } catch (\Throwable $e) {
                    continue;
                }
                if ($result !== null) {
                    return $result;
                }
            }
        } finally {
            array_pop($this->dispatchingStack);
        }

        return null;
    }

    private function getListenersForEvent(string $event): array
    {
        if (isset($this->wildcardCache[$event])) {
            return $this->wildcardCache[$event];
        }

        $listeners = [];

        foreach ($this->listeners as $pattern => $registered) {
            if ($this->matchWildcard($pattern, $event)) {
                foreach ($registered as $entry) {
                    $listeners[] = $entry['listener'];
                }
            }
        }

        // 防止长驻进程派发大量唯一事件名导致内存泄漏
        if (count($this->wildcardCache) >= 1024) {
            $this->wildcardCache = [];
        }
        $this->wildcardCache[$event] = $listeners;

        return $listeners;
    }

    private function matchWildcard(string $pattern, string $event): bool
    {
        if ($pattern === $event) {
            return true;
        }

        if (!str_contains($pattern, '*')) {
            return false;
        }

        $regex = $this->wildcardRegexCache[$pattern] ?? null;
        if ($regex === null) {
            $regex = '#^' . str_replace('\*', '[^.]+', preg_quote($pattern, '#')) . '$#';
            $this->wildcardRegexCache[$pattern] = $regex;
        }

        return (bool) preg_match($regex, $event);
    }

    /**
     * 订阅者注册 - 对象方法批量注册
     *
     * @param object $subscriber 实现 subscribe(EventDispatcher) 方法的订阅者
     */
    public function subscribe(object $subscriber): void
    {
        if (method_exists($subscriber, 'subscribe')) {
            $subscriber->subscribe($this);
        }
    }

    /**
     * 类名订阅者注册 - 通过反射自动绑定监听器
     *
     * 解析顺序：
     *   1. 若类有静态 getSubscribedEvents() 方法，使用其返回值映射：
     *      - [event => method]
     *      - [event => [method, priority]]
     *      - [event => [[method, priority], [method2, priority2]]]
     *   2. 否则扫描 public 方法（跳过构造函数与下划线开头方法）：
     *      - 方法名以 'on' 开头时，去掉前缀后转 snake.case 作为事件名
     *        例：onUserCreated → user.created，onOrderPaid → order.paid
     *
     * 监听器签名为 function(string $event, mixed ...$payload): mixed
     *
     * @param class-string $subscriberClass 订阅者类名
     * @throws \InvalidArgumentException 当类不存在时
     */
    public function subscribeClass(string $subscriberClass): void
    {
        if (!class_exists($subscriberClass)) {
            throw new \InvalidArgumentException("Subscriber class not found: {$subscriberClass}");
        }

        // 优先使用显式声明
        if (method_exists($subscriberClass, 'getSubscribedEvents')) {
            $events = $subscriberClass::getSubscribedEvents();
            if (!is_array($events)) {
                return;
            }
            foreach ($events as $event => $methods) {
                $this->registerSubscriberBinding($subscriberClass, $event, $methods);
            }
            return;
        }

        // 反射自动发现：on{EventName} → event.name
        $reflection = new \ReflectionClass($subscriberClass);
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $name = $method->getName();
            // 跳过构造函数、下划线开头、静态方法、内部继承方法
            if ($name === '__construct' || str_starts_with($name, '_')) {
                continue;
            }
            if ($method->getDeclaringClass()->getName() !== $subscriberClass) {
                continue;
            }
            if (!str_starts_with($name, 'on') || strlen($name) <= 2) {
                continue;
            }

            $event = $this->methodNameToEvent(substr($name, 2));
            if ($event === '') {
                continue;
            }

            $this->listen($event, function (string $e, mixed ...$payload) use ($subscriberClass, $name) {
                $instance = new $subscriberClass();
                return $instance->$name($e, ...$payload);
            });
        }
    }

    /**
     * 将 getSubscribedEvents() 的方法条目注册为监听器
     *
     * @param class-string $class
     * @param string $event
     * @param string|array<int, mixed> $methods
     */
    private function registerSubscriberBinding(string $class, string $event, mixed $methods): void
    {
        // 单方法：'event' => 'method'
        if (is_string($methods)) {
            $this->listen($event, $this->buildSubscriberCallable($class, $methods));
            return;
        }

        if (!is_array($methods)) {
            return;
        }

        // 多条目：'event' => [['m1', p1], ['m2', p2]]
        if (isset($methods[0]) && is_array($methods[0])) {
            foreach ($methods as $entry) {
                if (!is_array($entry) || empty($entry)) {
                    continue;
                }
                $method = (string) $entry[0];
                $priority = (int) ($entry[1] ?? 0);
                $this->listen($event, $this->buildSubscriberCallable($class, $method), $priority);
            }
            return;
        }

        // 单条目带优先级：'event' => ['method', priority]
        if (isset($methods[0]) && is_string($methods[0])) {
            $method = (string) $methods[0];
            $priority = (int) ($methods[1] ?? 0);
            $this->listen($event, $this->buildSubscriberCallable($class, $method), $priority);
            return;
        }
    }

    /**
     * 构造订阅者方法对应的可调用闭包
     *
     * @param class-string $class
     * @param string $method
     */
    private function buildSubscriberCallable(string $class, string $method): callable
    {
        return function (string $event, mixed ...$payload) use ($class, $method) {
            $instance = new $class();
            return $instance->$method($event, ...$payload);
        };
    }

    /**
     * 将 CamelCase 方法名后缀转换为 dot.snake 事件名
     * 例：UserCreated → user.created；OrderPaid → order.paid；Login → login
     */
    private function methodNameToEvent(string $name): string
    {
        // 在大写字母前插入点，转小写
        $dotted = preg_replace('/([a-z0-9])([A-Z])/', '$1.$2', $name) ?? $name;
        $dotted = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1.$2', $dotted) ?? $dotted;
        return strtolower($dotted);
    }

    public function flush(): void
    {
        $this->listeners = [];
        $this->wildcardCache = [];
        $this->wildcardRegexCache = [];
    }
}