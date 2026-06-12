<?php
declare(strict_types=1);

namespace cache;

use core\contract\CacheInterface;

/**
 * 文件缓存驱动
 * 
 * 使用文件系统存储缓存数据，支持标签化缓存和并发锁机制。
 * 采用原子写入方式确保数据完整性。
 */
class FileCache implements CacheInterface
{
    /** @var string 缓存文件存储目录 */
    private string $path;

    /** @var int 默认过期时间（秒） */
    private int $defaultTtl;

    /** @var array<string, bool> 锁数组 */
    private static array $locks = [];

    /**
     * 构造函数
     * 
     * @param array|string $config 配置数组或缓存目录路径（向后兼容）
     */
    public function __construct(array|string $config = [])
    {
        if (is_string($config)) {
            $config = ['path' => $config];
        }

        $this->path = rtrim($config['path'] ?? (STORAGE_PATH . 'cache'), '/\\') . '/';
        $this->defaultTtl = (int) ($config['expire'] ?? 3600);

        if (!is_dir($this->path)) {
            if (!mkdir($this->path, 0700, true) && !is_dir($this->path)) {
                throw new \RuntimeException("Cannot create cache directory: {$this->path}");
            }
        }
    }

    /**
     * 获取缓存文件路径
     * 
     * @param string $key 缓存键
     * @return string 文件路径
     */
    private function getFile(string $key): string
    {
        return $this->path . hash('sha256', $key) . '.cache';
    }

    /**
     * 获取标签文件路径
     * 
     * @param string $tag 标签名
     * @return string 文件路径
     */
    private function getTagFile(string $tag): string
    {
        return $this->path . 'tag_' . hash('sha256', $tag) . '.json';
    }

    /**
     * 获取锁文件路径
     * 
     * @param string $key 缓存键
     * @return string 锁文件路径
     */
    private function getLockFile(string $key): string
    {
        return $this->getFile($key) . '.lock';
    }

    /**
     * 判断缓存是否过期
     * 
     * @param array $data 缓存数据
     * @return bool 是否过期
     */
    private function isExpired(array $data): bool
    {
        return isset($data['expire']) && $data['expire'] > 0 && $data['expire'] < time();
    }

    /**
     * 读取缓存数据
     * 
     * @param string $key 缓存键
     * @return array|null 缓存数据或null
     */
    private function read(string $key): array|null
    {
        $file = $this->getFile($key);

        if (!file_exists($file)) {
            return null;
        }

        $content = @file_get_contents($file);
        if ($content === false) {
            return null;
        }

        $data = @json_decode($content, true);
        if (!is_array($data) || !array_key_exists('value', $data)) {
            return null;
        }

        if ($this->isExpired($data)) {
            @unlink($file);
            return null;
        }

        return $data;
    }

    /**
     * 写入缓存数据（原子写入）
     * 
     * 使用临时文件 + rename 的方式保证原子性。
     * 
     * @param string $key 缓存键
     * @param mixed $value 缓存值
     * @param int|null $ttl 过期时间（秒）
     * @return bool 是否成功
     */
    private function write(string $key, mixed $value, ?int $ttl = null): bool
    {
        $file = $this->getFile($key);
        $ttl = $ttl ?? $this->defaultTtl;

        $data = [
            'value'    => $value,
            'expire'   => $ttl > 0 ? time() + $ttl : 0,
            'created'  => time(),
        ];

        $content = json_encode($data, JSON_UNESCAPED_UNICODE);
        if ($content === false) {
            return false;
        }

        $tmpFile = $file . '.' . bin2hex(random_bytes(8)) . '.tmp';
        if (file_put_contents($tmpFile, $content, LOCK_EX) === false) {
            return false;
        }

        if (!@rename($tmpFile, $file)) {
            @unlink($tmpFile);
            return false;
        }

        return true;
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
        $data = $this->read($key);
        if ($data === null) {
            return $default;
        }
        return $data['value'];
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
        return $this->write($key, $value, $ttl);
    }

    /**
     * 检查缓存是否存在
     * 
     * @param string $key 缓存键
     * @return bool 是否存在
     */
    public function has(string $key): bool
    {
        $data = $this->read($key);
        return $data !== null;
    }

    /**
     * 删除缓存
     * 
     * @param string $key 缓存键
     * @return bool 是否成功
     */
    public function delete(string $key): bool
    {
        $file = $this->getFile($key);
        if (file_exists($file)) {
            $lockFile = $this->getLockFile($key);
            @unlink($lockFile);
            return @unlink($file);
        }
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
        foreach ($keys as $key) {
            $this->delete($key);
        }
        return true;
    }

    /**
     * 清空所有缓存
     * 
     * @return bool 是否成功
     */
    public function clear(): bool
    {
        $files = @glob($this->path . '*.cache');
        if ($files !== false) {
            foreach ($files as $file) {
                @unlink($file);
            }
        }

        $tagFiles = @glob($this->path . 'tag_*.json');
        if ($tagFiles !== false) {
            foreach ($tagFiles as $file) {
                @unlink($file);
            }
        }

        $lockFiles = @glob($this->path . '*.lock');
        if ($lockFiles !== false) {
            foreach ($lockFiles as $file) {
                @unlink($file);
            }
        }

        return true;
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
        foreach ($keys as $key) {
            $results[$key] = $this->get($key);
        }
        return $results;
    }

    /**
     * 批量设置缓存
     * 
     * @param array $values 缓存键值对数组
     * @param int|null $ttl 过期时间（秒）
     * @return bool 是否成功
     */
    public function setMany(array $values, ?int $ttl = null): bool
    {
        $success = true;
        foreach ($values as $key => $value) {
            if (!$this->set((string) $key, $value, $ttl)) {
                $success = false;
            }
        }
        return $success;
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
        $value = $this->get($key, $default);
        $this->delete($key);
        return $value;
    }

    /**
     * 获取缓存，不存在则执行回调并缓存结果（带锁机制）
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

        $lockFile = $this->getLockFile($key);
        $fp = @fopen($lockFile, 'c+');
        if ($fp === false) {
            $value = $callback();
            $this->set($key, $value, $ttl);
            return $value;
        }

        try {
            $acquired = false;

            for ($retry = 0; $retry < 3; $retry++) {
                if (flock($fp, LOCK_EX | LOCK_NB)) {
                    $acquired = true;
                    break;
                }
                if ($retry < 2) {
                    usleep(random_int(5000, 50000));
                }
            }

            if (!$acquired) {
                if (!flock($fp, LOCK_EX)) {
                    $value = $callback();
                    $this->set($key, $value, $ttl);
                    return $value;
                }
            }

            $value = $this->get($key, $sentinel);
            if ($value !== $sentinel) {
                return $value;
            }

            $value = $callback();
            $this->set($key, $value, $ttl);
            return $value;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
            @unlink($lockFile);
        }
    }

    /**
     * 递增缓存值（带锁机制）
     * 
     * @param string $key 缓存键
     * @param int $step 步长
     * @return int 递增后的值
     */
    public function increment(string $key, int $step = 1): int
    {
        $lockFile = $this->getLockFile($key);
        $fp = @fopen($lockFile, 'c+');
        if ($fp === false) {
            return $this->incrementFallback($key, $step);
        }

        try {
            flock($fp, LOCK_EX);
            $data = $this->read($key);
            $current = $data !== null ? (int) $data['value'] : 0;
            $new = $current + $step;
            $ttl = ($data !== null && $data['expire'] > 0) ? max(1, $data['expire'] - time()) : 0;
            $this->set($key, $new, $ttl);
            return $new;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
            @unlink($lockFile);
        }
    }

    /**
     * 递减缓存值（带锁机制）
     *
     * @param string $key 缓存键
     * @param int $step 步长
     * @return int 递减后的值
     */
    public function decrement(string $key, int $step = 1): int
    {
        $lockFile = $this->getLockFile($key);
        $fp = @fopen($lockFile, 'c+');
        if ($fp === false) {
            return $this->decrementFallback($key, $step);
        }

        try {
            flock($fp, LOCK_EX);
            $data = $this->read($key);
            $current = $data !== null ? (int) $data['value'] : 0;
            $new = max(0, $current - $step);
            $ttl = ($data !== null && $data['expire'] > 0) ? max(1, $data['expire'] - time()) : 0;
            $this->set($key, $new, $ttl);
            return $new;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
            @unlink($lockFile);
        }
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
     * @param string $key 缓存键
     * @param string $tag 标签名
     */
    public function attachTag(string $key, string $tag): void
    {
        $tagFile = $this->getTagFile($tag);
        $fp = @fopen($tagFile, 'c+');
        if ($fp === false) {
            return;
        }

        try {
            flock($fp, LOCK_EX);
            $content = stream_get_contents($fp);
            $keys = [];

            if ($content !== false && $content !== '') {
                $keys = @json_decode($content, true);
                if (!is_array($keys)) {
                    $keys = [];
                }
            }

            if (!in_array($key, $keys, true)) {
                $keys[] = $key;
            }

            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($keys, JSON_UNESCAPED_UNICODE));
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
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
        $tagFile = $this->getTagFile($tag);

        if (!file_exists($tagFile)) {
            return true;
        }

        $fp = @fopen($tagFile, 'c+');
        if ($fp === false) {
            return false;
        }

        try {
            flock($fp, LOCK_EX);
            $content = stream_get_contents($fp);
            $keys = [];

            if ($content !== false && $content !== '') {
                $keys = @json_decode($content, true);
                if (!is_array($keys)) {
                    $keys = [];
                }
            }

            foreach ($keys as $key) {
                $this->delete((string) $key);
            }

            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, '[]');
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }

        return true;
    }

    private function incrementFallback(string $key, int $step): int
    {
        $data = $this->read($key);
        $current = $data !== null ? (int) $data['value'] : 0;
        $new = $current + $step;
        $ttl = ($data !== null && $data['expire'] > 0) ? max(1, $data['expire'] - time()) : 0;
        $this->set($key, $new, $ttl);
        return $new;
    }

    private function decrementFallback(string $key, int $step): int
    {
        $data = $this->read($key);
        $current = $data !== null ? (int) $data['value'] : 0;
        $new = max(0, $current - $step);
        $ttl = ($data !== null && $data['expire'] > 0) ? max(1, $data['expire'] - time()) : 0;
        $this->set($key, $new, $ttl);
        return $new;
    }
}