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
                    $results[] = $e;
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

    public function flush(): void
    {
        $this->listeners = [];
        $this->wildcardCache = [];
        $this->wildcardRegexCache = [];
    }
}