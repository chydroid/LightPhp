<?php

declare(strict_types=1);

namespace traits;

/**
 * HasModelEvents 特征
 *
 * 为模型提供事件监听与观察者模式支持。
 * 允许在模型的生命周期事件（creating、created、updating、updated、saving、saved、deleting、deleted）
 * 上注册回调监听器或观察者类，当事件触发时自动调用已注册的监听器。
 * 如果任意监听器返回 false，则取消当前操作。
 */
trait HasModelEvents
{
    /**
     * 已注册的事件监听器
     *
     * 键为事件名称，值为该事件下的回调数组。
     *
     * @var array<string, callable[]>
     */
    private static array $eventListeners = [];

    /**
     * 已注册的观察者实例
     *
     * 键为观察者类名，值为观察者对象实例。
     *
     * @var array<string, object>
     */
    private static array $observers = [];

    /**
     * 注册事件监听器
     *
     * 将一个回调函数注册到指定事件名称下，当该事件触发时回调将被执行。
     * 支持的事件包括：creating、created、updating、updated、saving、saved、deleting、deleted。
     *
     * @param string $event 事件名称
     * @param callable $callback 事件触发时执行的回调函数，接收当前模型实例作为参数
     */
    public static function onEvent(string $event, callable $callback): void
    {
        static::$eventListeners[$event][] = $callback;
    }

    /**
     * 注册观察者类
     *
     * 传入一个观察者对象或类名，自动检测其中与事件名称同名的方法
     * （creating、created、updating、updated、saving、saved、deleting、deleted），
     * 并将匹配的方法注册为对应事件的监听器。
     *
     * @param object|string $observer 观察者对象实例或类名（类名时将自动实例化）
     */
    public static function observe(object|string $observer): void
    {
        if (is_string($observer)) {
            $observer = new $observer();
        }

        $className = $observer::class;

        if (isset(static::$observers[$className])) {
            return;
        }

        static::$observers[$className] = $observer;

        $events = ['creating', 'created', 'updating', 'updated', 'saving', 'saved', 'deleting', 'deleted'];

        foreach ($events as $event) {
            if (method_exists($observer, $event)) {
                static::$eventListeners[$event][] = [$observer, $event];
            }
        }
    }

    /**
     * 清空所有事件监听器和观察者
     *
     * 移除当前类中所有已注册的事件监听器及观察者实例，
     * 使模型恢复到未注册任何事件监听的状态。
     */
    public static function flushEventListeners(): void
    {
        static::$eventListeners = [];
        static::$observers = [];
    }

    /**
     * 触发指定事件
     *
     * 按注册顺序调用该事件下的所有监听器，将当前模型实例作为参数传入。
     * 如果任意监听器返回 false，则立即停止后续监听器的执行并返回 false，
     * 表示应取消当前操作；否则返回 true。
     *
     * @param string $event 事件名称
     * @return bool 所有监听器均未返回 false 时返回 true，否则返回 false
     */
    public function fireEvent(string $event): bool
    {
        $listeners = static::$eventListeners[$event] ?? [];

        foreach ($listeners as $callback) {
            if (call_user_func($callback, $this) === false) {
                return false;
            }
        }

        return true;
    }
}
