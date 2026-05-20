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
        $this->tagKey($key);
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
        $value = $this->store->remember($key, $ttl, $callback);
        $this->tagKey($key);
        return $value;
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
        foreach ($values as $key => $v) {
            $this->tagKey((string) $key);
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

    /**
     * 追加标签并返回新的实例
     */
    public function tags(array $tags): self
    {
        return new self($this->store, array_merge($this->tags, $tags));
    }

    private function tagKey(string $key): void
    {
        foreach ($this->tags as $tag) {
            $this->store->attachTag($key, $tag);
        }
    }
}