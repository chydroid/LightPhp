<?php
declare(strict_types=1);

namespace config;

class Config
{
    private static array $items = [];

    public static function get(string $key, mixed $default = null): mixed
    {
        $keys = explode('.', $key);
        $value = self::$items;

        foreach ($keys as $k) {
            if (!array_key_exists($k, $value)) {
                return $default;
            }
            $value = $value[$k];
        }

        return $value;
    }

    public static function set(string $key, mixed $value): void
    {
        $keys = explode('.', $key);
        $config = &self::$items;
        $lastIndex = count($keys) - 1;

        foreach ($keys as $i => $k) {
            if ($i === $lastIndex) {
                $config[$k] = $value;
                break;
            }
            if (!array_key_exists($k, $config) || !is_array($config[$k])) {
                $config[$k] = [];
            }
            $config = &$config[$k];
        }
    }

    public static function has(string $key): bool
    {
        $keys = explode('.', $key);
        $value = self::$items;

        foreach ($keys as $k) {
            if (!array_key_exists($k, $value)) {
                return false;
            }
            $value = $value[$k];
        }

        return true;
    }

    public static function all(): array
    {
        return self::$items;
    }

    public static function load(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $files = glob($path . '*.php');
        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            $name = basename($file, '.php');
            $result = require $file;
            if (is_array($result)) {
                self::$items[$name] = $result;
            }
        }
    }

    /**
     * 从缓存文件加载配置（生产环境推荐）
     * 合并到现有配置中，已加载项优先（不覆盖）
     */
    public static function loadCached(string $cacheFile): bool
    {
        if (!file_exists($cacheFile)) {
            return false;
        }

        $cached = require $cacheFile;
        if (!is_array($cached)) {
            return false;
        }

        self::$items = array_merge($cached, self::$items);
        return true;
    }

    /**
     * 生成配置缓存文件
     */
    public static function cache(string $cacheFile): bool
    {
        $content = '<?php return ' . var_export(self::$items, true) . ';';
        $dir = dirname($cacheFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $tmpFile = $cacheFile . '.tmp';
        if (file_put_contents($tmpFile, $content, LOCK_EX) === false) {
            return false;
        }

        if (!rename($tmpFile, $cacheFile)) {
            @unlink($tmpFile);
            return false;
        }

        return true;
    }
}