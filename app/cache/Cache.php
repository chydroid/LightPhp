<?php
declare(strict_types=1);

namespace cache;

use core\Facade;

/**
 * @method static mixed get(string $key, mixed $default = null)
 * @method static bool set(string $key, mixed $value, ?int $ttl = null)
 * @method static bool has(string $key)
 * @method static bool delete(string $key)
 * @method static bool clear()
 * @method static mixed remember(string $key, int $ttl, callable $callback)
 * @method static int increment(string $key, int $step = 1)
 * @method static int decrement(string $key, int $step = 1)
 * @method static array many(array $keys)
 * @method static bool setMany(array $values, ?int $ttl = null)
 * @method static bool deleteMany(array $keys)
 * @method static mixed pull(string $key, mixed $default = null)
 * @method static \cache\TaggedCache tags(array $tags)
 * @method static \cache\CacheManager driver(?string $name = null)
 *
 * @see \cache\CacheManager
 */
class Cache extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'cache';
    }
}