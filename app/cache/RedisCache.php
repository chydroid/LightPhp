<?php
declare(strict_types=1);

namespace cache;

use core\contract\CacheInterface;

/**
     * Redis 缓存驱动
     * 
     * 使用 Redis 作为缓存存储，支持持久连接、管道操作和标签化缓存。
     * 
     * @see \Redis
     */
class RedisCache implements CacheInterface
{
    /** @var \Redis Redis 连接实例 */
    private object $redis;

    /** @var string 缓存键前缀 */
    private string $prefix;

    /** @var int 默认过期时间（秒） */
    private int $defaultTtl;

    /**
     * 构造函数
     * 
     * @param array $config 配置数组
     */
    public function __construct(array $config = [])
    {
        if (!class_exists(\Redis::class)) {
            throw new \RuntimeException('Redis extension is not installed. Install php-redis or use file driver.');
        }

        $this->redis = new \Redis();
        $host = $config['host'] ?? '127.0.0.1';
        $port = (int) ($config['port'] ?? 6379);
        $timeout = (float) ($config['timeout'] ?? 2.5);
        $persistent = $config['persistent'] ?? false;
        $persistentId = $config['persistent_id'] ?? 'lightphp';
        $password = $config['password'] ?? null;
        $database = (int) ($config['database'] ?? 0);
        $this->prefix = $config['prefix'] ?? 'lightphp:cache:';
        $this->defaultTtl = (int) ($config['expire'] ?? 3600);

        if ($persistent) {
            $this->redis->pconnect($host, $port, $timeout, $persistentId);
        } else {
            $this->redis->connect($host, $port, $timeout);
        }

        if ($password !== null && $password !== '') {
            $this->redis->auth($password);
        }

        if ($database !== 0) {
            $this->redis->select($database);
        }

        $this->redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_JSON);
    }

    /**
     * 构建完整缓存键（添加前缀）
     * 
     * @param string $key 缓存键
     * @return string 完整键名
     */
    private function key(string $key): string
    {
        return $this->prefix . $key;
    }

    /**
     * 获取缓存值
     * 
     * @param string $key 缓存键
     * @param mixed $default 默认值
     * @return mixed 缓存值或默认值
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $fullKey = $this->key($key);
        $value = $this->redis->get($fullKey);
        // Redis GET returns false for non-existent keys (with JSON serializer)
        if ($value === false && !$this->redis->exists($fullKey)) {
            return $default;
        }
        return $value;
    }

    /**
     * 设置缓存值
     * 
     * @param string $key 缓存键
     * @param mixed $value 缓存值
     * @param int|null $ttl 过期时间（秒）
     * @return bool 是否成功
     */
    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $ttl = $ttl ?? $this->defaultTtl;
        $fullKey = $this->key($key);

        if ($ttl > 0) {
            return $this->redis->setex($fullKey, $ttl, $value);
        }

        return $this->redis->set($fullKey, $value);
    }

    /**
     * 检查缓存是否存在
     * 
     * @param string $key 缓存键
     * @return bool 是否存在
     */
    public function has(string $key): bool
    {
        return (bool) $this->redis->exists($this->key($key));
    }

    /**
     * 删除缓存
     * 
     * @param string $key 缓存键
     * @return bool 是否成功
     */
    public function delete(string $key): bool
    {
        $this->redis->del($this->key($key));
        return true;
    }

    /**
     * 批量删除缓存
     * 
     * @param array $keys 缓存键数组
     * @return bool 是否成功
     */
    public function deleteMany(array $keys): bool
    {
        if (empty($keys)) {
            return true;
        }
        $fullKeys = array_map(fn($k) => $this->key($k), $keys);
        return (bool) $this->redis->del($fullKeys);
    }

    /**
     * 清空所有缓存
     * 
     * 使用 SCAN 命令遍历删除，避免 KEYS 命令阻塞。
     * 
     * @return bool 是否成功
     */
    public function clear(): bool
    {
        $iterator = null;
        $safetyLimit = 1000;
        while ($safetyLimit-- > 0) {
            $keys = $this->redis->scan($iterator, $this->prefix . '*', 100);
            if ($keys === false) {
                break;
            }
            if (!empty($keys)) {
                $this->redis->del($keys);
            }
            if ($iterator === 0) {
                break;
            }
        }
        return true;
    }

    /**
     * 获取缓存，不存在则执行回调并缓存结果（带锁机制）
     * 
     * 使用 Redis 的 SET NX 实现分布式锁。
     * 
     * @param string $key 缓存键
     * @param int $ttl 过期时间（秒）
     * @param callable $callback 回调函数
     * @return mixed 缓存值
     */
    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        $sentinel = new \stdClass();
        $value = $this->get($key, $sentinel);
        if ($value !== $sentinel) {
            return $value;
        }

        $lockKey = $this->key('lock:' . $key);
        $lockValue = bin2hex(random_bytes(8));
        $locked = $this->redis->set($lockKey, $lockValue, ['nx', 'ex' => 10]);

        if ($locked) {
            try {
                $value = $this->get($key, $sentinel);
                if ($value !== $sentinel) {
                    return $value;
                }
                $value = $callback();
                $this->set($key, $value, $ttl);
                return $value;
            } finally {
                // 使用 Lua 脚本原子释放锁，仅释放自己持有的锁
                $script = "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) else return 0 end";
                $this->redis->eval($script, [$lockKey, $lockValue], 1);
            }
        }

        for ($retry = 0; $retry < 5; $retry++) {
            usleep(random_int(10000, 100000));
            $value = $this->get($key, $sentinel);
            if ($value !== $sentinel) {
                return $value;
            }
        }

        // 锁获取失败且重试耗尽，仍需执行回调以避免永久阻塞
        $value = $callback();
        $this->set($key, $value, $ttl);
        return $value;
    }

    /**
     * 递增缓存值
     * 
     * @param string $key 缓存键
     * @param int $step 步长
     * @return int 递增后的值
     */
    public function increment(string $key, int $step = 1): int
    {
        $fullKey = $this->key($key);
        $exists = (bool) $this->redis->exists($fullKey);
        if (!$exists) {
            $this->set($key, $step);
            return $step;
        }
        // 保留原有 TTL
        $ttl = $this->redis->ttl($fullKey);
        $value = $this->redis->get($fullKey);
        $current = ($value === false) ? 0 : (int) $value;
        $new = $current + $step;
        if ($ttl > 0) {
            $this->redis->setex($fullKey, $ttl, $new);
        } else {
            $this->redis->set($fullKey, $new);
        }
        return $new;
    }

    /**
     * 递减缓存值
     *
     * @param string $key 缓存键
     * @param int $step 步长
     * @return int 递减后的值
     */
    public function decrement(string $key, int $step = 1): int
    {
        $fullKey = $this->key($key);
        $exists = (bool) $this->redis->exists($fullKey);
        if (!$exists) {
            $this->set($key, max(0, -$step));
            return 0;
        }
        $ttl = $this->redis->ttl($fullKey);
        $value = $this->redis->get($fullKey);
        $current = ($value === false) ? 0 : (int) $value;
        $new = max(0, $current - $step);
        if ($ttl > 0) {
            $this->redis->setex($fullKey, $ttl, $new);
        } else {
            $this->redis->set($fullKey, $new);
        }
        return $new;
    }

    /**
     * 批量获取缓存
     * 
     * @param array $keys 缓存键数组
     * @return array 缓存值数组
     */
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

        $values = $this->redis->mget($fullKeys);
        if ($values === false) {
            $values = [];
        }

        foreach ($fullKeys as $i => $fullKey) {
            $originalKey = $keyMap[$fullKey];
            $value = $values[$i] ?? null;
            // Redis mget returns false for non-existent keys with JSON serializer
            $results[$originalKey] = ($value === false && !$this->redis->exists($fullKey)) ? null : $value;
        }

        return $results;
    }

    /**
     * 批量设置缓存（使用管道）
     * 
     * @param array $values 缓存键值对数组
     * @param int|null $ttl 过期时间（秒）
     * @return bool 是否成功
     */
    public function setMany(array $values, ?int $ttl = null): bool
    {
        $ttl = $ttl ?? $this->defaultTtl;
        $pipe = $this->redis->multi(\Redis::PIPELINE);

        foreach ($values as $key => $value) {
            $fullKey = $this->key((string) $key);
            if ($ttl > 0) {
                $pipe->setex($fullKey, $ttl, $value);
            } else {
                $pipe->set($fullKey, $value);
            }
        }

        $pipe->exec();
        return true;
    }

    /**
     * 获取并删除缓存
     * 
     * @param string $key 缓存键
     * @param mixed $default 默认值
     * @return mixed 缓存值或默认值
     */
    public function pull(string $key, mixed $default = null): mixed
    {
        $fullKey = $this->key($key);
        $value = $this->redis->get($fullKey);
        if ($value === false && !$this->redis->exists($fullKey)) {
            return $default;
        }
        $this->redis->del($fullKey);
        return $value;
    }

    /**
     * 创建标签化缓存实例
     * 
     * @param array $tags 标签数组
     * @return TaggedCache 标签化缓存实例
     */
    public function tags(array $tags): TaggedCache
    {
        return new TaggedCache($this, $tags);
    }

    /**
     * 为缓存键附加标签
     *
     * 对齐标签 SET 与被引用缓存项的 TTL，避免过期键名在 SET 中永久累积导致内存泄漏。
     * 策略：取缓存项 TTL 与标签当前 TTL 的最大值，只延长不缩短。
     *
     * @param string $key 缓存键
     * @param string $tag 标签名
     */
    public function attachTag(string $key, string $tag): void
    {
        $tagKey = $this->prefix . 'tag:' . $tag;
        $this->redis->sAdd($tagKey, $key);

        $fullKey = $this->key($key);
        $itemTtl = $this->redis->ttl($fullKey);  // -2=不存在, -1=永久, >0=秒
        $tagTtl = $this->redis->ttl($tagKey);     // -2=不存在(刚创建), -1=永久, >0=秒

        // 缓存项或标签为永久，标签保持永久（不 expire）
        if ($itemTtl === -1 || $tagTtl === -1) {
            return;
        }
        // 双方都不存在或无 TTL 信息，不操作
        if ($itemTtl <= 0 && $tagTtl <= 0) {
            return;
        }
        $maxTtl = max($itemTtl, $tagTtl);
        // 只延长不缩短，避免误删活跃标签
        if ($maxTtl > 0 && $maxTtl > $tagTtl) {
            $this->redis->expire($tagKey, $maxTtl);
        }
    }

    /**
     * 按标签清除所有关联缓存
     * 
     * @param string $tag 标签名
     * @return bool 是否成功
     */
    public function flushByTag(string $tag): bool
    {
        $tagKey = $this->prefix . 'tag:' . $tag;
        $keys = $this->redis->sMembers($tagKey);

        if (!empty($keys)) {
            // Filter out keys that no longer exist (expired)
            $existingFullKeys = [];
            foreach ($keys as $k) {
                $fullKey = $this->key($k);
                if ($this->redis->exists($fullKey)) {
                    $existingFullKeys[] = $fullKey;
                }
            }
            if (!empty($existingFullKeys)) {
                $this->redis->del($existingFullKeys);
            }
        }

        $this->redis->del($tagKey);
        return true;
    }

    /**
     * 获取 Redis 连接实例
     * 
     * @return \Redis Redis 连接
     */
    public function connection(): object
    {
        return $this->redis;
    }
}