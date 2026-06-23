<?php
declare(strict_types=1);

namespace cache;

use core\contract\CacheInterface;

class TaggedCache
{
    private CacheInterface $store;

    /** @var string[] */
    private array $tags;

    public function __construct(CacheInterface $store, array $tags)
    {
        $this->store = $store;
        $this->tags = $tags;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->store->get($key, $default);
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $result = $this->store->set($key, $value, $ttl);
        if ($result) {
            $this->tagKey($key);
        }
        return $result;
    }

    public function has(string $key): bool
    {
        return $this->store->has($key);
    }

    public function delete(string $key): bool
    {
        return $this->store->delete($key);
    }

    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        $sentinel = new \stdClass();
        $value = $this->store->get($key, $sentinel);
        if ($value !== $sentinel) {
            return $value;
        }

        // 缓存未命中时才执行回调并附加标签
        $value = $this->store->remember($key, $ttl, $callback);
        $this->tagKey($key);
        return $value;
    }

    public function increment(string $key, int $step = 1): int
    {
        $result = $this->store->increment($key, $step);
        // increment 可能在 key 不存在时创建新 key（如 MemcachedCache 通过 add() 初始化），
        // 需要打标签以确保 flush() 能清除该 key
        $this->tagKey($key);
        return $result;
    }

    public function decrement(string $key, int $step = 1): int
    {
        $result = $this->store->decrement($key, $step);
        $this->tagKey($key);
        return $result;
    }

    public function deleteMany(array $keys): bool
    {
        return $this->store->deleteMany($keys);
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        return $this->store->pull($key, $default);
    }

    public function clear(): bool
    {
        return $this->flush();
    }

    public function tags(array $tags): self
    {
        return new self($this->store, array_merge($this->tags, $tags));
    }

    /** @return array<string, mixed> */
    public function many(array $keys): array
    {
        return $this->store->many($keys);
    }

    /**
     * @param array<string, mixed> $values
     */
    public function setMany(array $values, ?int $ttl = null): bool
    {
        $result = $this->store->setMany($values, $ttl);
        if ($result) {
            foreach ($values as $key => $v) {
                $this->tagKey((string) $key);
            }
        }
        return $result;
    }

    /**
     * 清除所有标签关联的缓存
     */
    public function flush(): bool
    {
        foreach ($this->tags as $tag) {
            $this->store->flushByTag($tag);
        }
        return true;
    }

    private function tagKey(string $key): void
    {
        foreach ($this->tags as $tag) {
            $this->store->attachTag($key, $tag);
        }
    }
}