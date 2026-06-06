<?php
declare(strict_types=1);

namespace core\contract;

interface CacheInterface
{
    public function get(string $key, mixed $default = null): mixed;
    public function set(string $key, mixed $value, ?int $ttl = null): bool;
    public function has(string $key): bool;
    public function delete(string $key): bool;
    public function clear(): bool;
    public function remember(string $key, int $ttl, callable $callback): mixed;
    public function increment(string $key, int $step = 1): int;
    public function decrement(string $key, int $step = 1): int;

    /** @return array<string, mixed> */
    public function many(array $keys): array;
    public function setMany(array $values, ?int $ttl = null): bool;
    public function deleteMany(array $keys): bool;
    public function pull(string $key, mixed $default = null): mixed;

    /**
     * 获取标签化缓存实例
     * @param string[] $tags
     */
    public function tags(array $tags): \cache\TaggedCache;

    /**
     * 按标签清除所有关联缓存
     */
    public function flushByTag(string $tag): bool;

    /**
     * 为指定 key 附加标签
     */
    public function attachTag(string $key, string $tag): void;
}