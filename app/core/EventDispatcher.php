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

    private bool $dispatching = false;

    public function listen(string $event, callable $listener, int $priority = 0): void
    {
        $this->listeners[$event][] = [
            'listener' => $listener,
            'priority' => $priority,
        ];

        usort($this->listeners[$event], fn($a, $b) => $b['priority'] <=> $a['priority']);

        unset($this->wildcardCache[$event]);
        $this->wildcardRegexCache = [];
    }

    public function forget(string $event): void
    {
        unset($this->listeners[$event], $this->wildcardCache[$event], $this->wildcardRegexCache[$event]);
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

        if ($this->dispatching) {
            trigger_error(
                "EventDispatcher: Nested dispatch detected for event [{$event}] while already dispatching. "
                . 'Nested event dispatching is not supported to prevent infinite recursion.',
                E_USER_WARNING
            );
            return $results;
        }

        $this->dispatching = true;

        try {
            $listeners = $this->getListenersForEvent($event);

            foreach ($listeners as $listener) {
                try {
                    $result = $listener($event, ...$payload);
                    if ($result === false) {
                        break;
                    }
                    $results[] = $result;
                } catch (\Throwable $e) {
                    $results[] = $e;
                }
            }
        } finally {
            $this->dispatching = false;
        }

        return $results;
    }

    /**
     * 触发事件（直到某个监听器返回非null值）
     */
    public function until(string $event, mixed ...$payload): mixed
    {
        $results = $this->dispatch($event, ...$payload);
        foreach ($results as $result) {
            if ($result !== null) {
                return $result;
            }
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