<?php
declare(strict_types=1);

namespace cache;

use core\contract\CacheInterface;

/**
 * Memcached缓存驱动
 * @see \Memcached
 */
class MemcachedCache implements CacheInterface
{
    /** @var \Memcached */
    private object $memcached;
    private string $prefix;
    private int $defaultTtl;

    public function __construct(array $config = [])
    {
        if (!class_exists(\Memcached::class)) {
            throw new \RuntimeException('Memcached extension is not installed. Install php-memcached or use file driver.');
        }

        $persistentId = $config['persistent_id'] ?? 'lightphp';
        $this->memcached = new \Memcached($persistentId);
        $this->prefix = $config['prefix'] ?? 'lightphp:cache:';
        $this->defaultTtl = (int) ($config['expire'] ?? 3600);

        $servers = $config['servers'] ?? [
            ['host' => '127.0.0.1', 'port' => 11211, 'weight' => 100],
        ];

        if (empty($this->memcached->getServerList())) {
            $this->memcached->addServers($servers);
        }

        $options = $config['options'] ?? [];
        if (isset($options['username']) && isset($options['password'])) {
            $this->memcached->setOption(\Memcached::OPT_BINARY_PROTOCOL, true);
            $this->memcached->setSaslAuthData($options['username'], $options['password']);
        }
    }

    private function key(string $key): string
    {
        return $this->prefix . $key;
    }

    private function serialize(mixed $value): string
    {
        return serialize($value);
    }

    private function unserialize(string $value): mixed
    {
        $result = @unserialize($value, ['allowed_classes' => false]);
        if ($result === false && $value !== serialize(false)) {
            return $value;
        }
        return $result;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->memcached->get($this->key($key));
        if ($this->memcached->getResultCode() === \Memcached::RES_NOTFOUND) {
            return $default;
        }
        return is_string($value) ? $this->unserialize($value) : $value;
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $ttl = $ttl ?? $this->defaultTtl;
        $data = is_scalar($value) ? $value : $this->serialize($value);
        return $this->memcached->set($this->key($key), $data, $ttl);
    }

    public function has(string $key): bool
    {
        $this->memcached->get($this->key($key));
        return $this->memcached->getResultCode() === \Memcached::RES_SUCCESS;
    }

    public function delete(string $key): bool
    {
        $this->memcached->delete($this->key($key));
        return $this->memcached->getResultCode() !== \Memcached::RES_NOTFOUND;
    }

    public function deleteMany(array $keys): bool
    {
        if (empty($keys)) {
            return true;
        }
        $fullKeys = array_map(fn($k) => $this->key($k), $keys);
        $result = $this->memcached->deleteMulti($fullKeys);
        if (!is_array($result)) {
            return false;
        }
        return !in_array(false, $result, true);
    }

    public function clear(): bool
    {
        return $this->memcached->flush();
    }

    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        $sentinel = new \stdClass();
        $value = $this->get($key, $sentinel);
        if ($value !== $sentinel) {
            return $value;
        }

        $lockKey = $this->key('lock:' . $key);
        if ($this->memcached->add($lockKey, 1, 10)) {
            try {
                $value = $this->get($key, $sentinel);
                if ($value !== $sentinel) {
                    return $value;
                }
                $value = $callback();
                $this->set($key, $value, $ttl);
                return $value;
            } finally {
                $this->memcached->delete($lockKey);
            }
        }

        for ($retry = 0; $retry < 5; $retry++) {
            usleep(random_int(10000, 100000));
            $value = $this->get($key, $sentinel);
            if ($value !== $sentinel) {
                return $value;
            }
        }

        $value = $callback();
        $this->set($key, $value, $ttl);
        return $value;
    }

    public function increment(string $key, int $step = 1): int
    {
        $fullKey = $this->key($key);
        $result = $this->memcached->increment($fullKey, $step);
        if ($result === false) {
            $this->memcached->add($fullKey, $step, $this->defaultTtl);
            return $step;
        }
        return (int) $result;
    }

    public function decrement(string $key, int $step = 1): int
    {
        $fullKey = $this->key($key);
        $result = $this->memcached->decrement($fullKey, $step);
        if ($result === false) {
            $this->memcached->add($fullKey, max(0, -$step), $this->defaultTtl);
            return max(0, -$step);
        }
        return (int) $result;
    }

    /** @return array<string, mixed> */
    public function many(array $keys): array
    {
        $results = [];
        $fullKeys = [];
        $keyMap = [];

        foreach ($keys as $key) {
            $fullKey = $this->key($key);
            $fullKeys[] = $fullKey;
            $keyMap[$fullKey] = $key;
        }

        $values = $this->memcached->getMulti($fullKeys) ?: [];

        foreach ($fullKeys as $fullKey) {
            $originalKey = $keyMap[$fullKey];
            $v = $values[$fullKey] ?? null;
            $results[$originalKey] = is_string($v) ? $this->unserialize($v) : $v;
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $values
     */
    public function setMany(array $values, ?int $ttl = null): bool
    {
        $ttl = $ttl ?? $this->defaultTtl;
        $data = [];

        foreach ($values as $key => $value) {
            $data[$this->key((string) $key)] = is_scalar($value) ? $value : $this->serialize($value);
        }

        return $this->memcached->setMulti($data, $ttl);
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $fullKey = $this->key($key);
        $value = $this->memcached->get($fullKey);
        $notFound = $this->memcached->getResultCode() === \Memcached::RES_NOTFOUND;
        if ($notFound) {
            return $default;
        }
        $this->memcached->delete($fullKey);
        return is_string($value) ? $this->unserialize($value) : $value;
    }

    public function tags(array $tags): TaggedCache
    {
        return new TaggedCache($this, $tags);
    }

    /**
     * 为指定 key 附加标签
     */
    public function attachTag(string $key, string $tag): void
    {
        $tagKey = $this->prefix . 'tag:' . $tag;
        $keys = [];
        $existing = $this->memcached->get($tagKey);
        if ($existing !== false) {
            $keys = $this->unserialize($existing);
            if (!is_array($keys)) {
                $keys = [];
            }
        }
        if (!in_array($key, $keys, true)) {
            $keys[] = $key;
            $this->memcached->set($tagKey, $this->serialize($keys), 0);
        }
    }

    /**
     * 按标签清除所有关联缓存
     * @return bool
     */
    public function flushByTag(string $tag): bool
    {
        $tagKey = $this->prefix . 'tag:' . $tag;
        $existing = $this->memcached->get($tagKey);

        if ($existing !== false) {
            $keys = $this->unserialize($existing);
            if (is_array($keys) && !empty($keys)) {
                $fullKeys = array_map(fn($k) => $this->key((string) $k), $keys);
                $this->memcached->deleteMulti($fullKeys);
            }
        }

        $this->memcached->delete($tagKey);
        return true;
    }

    /**
     * 获取原生 Memcached 连接用于高级操作
     * @return \Memcached
     */
    public function connection(): object
    {
        return $this->memcached;
    }
}