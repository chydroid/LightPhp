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
            // 路径中段可能是标量（如 config('a.b') 中 a.b 是字符串），
            // 此时继续下钻会触发 array_key_exists(): Argument #2 must be of type array。
            if (!is_array($value) || !array_key_exists($k, $value)) {
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
            // 与 get() 一致：路径中段为标量时应视为「不存在」而非抛 TypeError
            if (!is_array($value) || !array_key_exists($k, $value)) {
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

        // 规范化目录分隔符：调用方传 'app/config' 与 'app/config/' 都应可用。
        // 直接 glob($path . '*.php') 时前者会拼成 'app/config*.php' 而匹配不到，
        // 导致静默加载 0 个文件。
        $path = rtrim($path, '/\\') . DIRECTORY_SEPARATOR;

        $files = glob($path . '*.php');
        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            $name = basename($file, '.php');
            // 二次加载同一目录会重复 require 同一文件，导致其中的
            // 函数/类重复声明 fatal；已加载过的配置直接跳过。
            if (array_key_exists($name, self::$items)) {
                continue;
            }
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

        if (!defined('LIGHTPHP_CONFIG_CACHE')) {
            define('LIGHTPHP_CONFIG_CACHE', true);
        }
        $cached = require $cacheFile;
        if (!is_array($cached)) {
            return false;
        }

        self::$items = array_replace_recursive($cached, self::$items);
        return true;
    }

    /**
     * 生成配置缓存文件
     */
    public static function cache(string $cacheFile): bool
    {
        $content = '<?php if(!defined(\'LIGHTPHP_CONFIG_CACHE\')){http_response_code(403);exit;} return ' . var_export(self::$items, true) . ';';
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