<?php
declare(strict_types=1);

namespace core;

class Loader
{
    private static array $prefixes = [
        'core\\'          => APP_PATH . 'core/',
        'core\\console\\' => APP_PATH . 'core/console/',
        'core\\traits\\'  => APP_PATH . 'core/traits/',
        'controller\\'    => APP_PATH . 'controller/',
        'model\\'         => APP_PATH . 'model/',
        'view\\'          => APP_PATH . 'view/',
        'route\\'         => APP_PATH . 'route/',
        'middleware\\'    => APP_PATH . 'middleware/',
        'db\\'            => APP_PATH . 'db/',
        'cache\\'         => APP_PATH . 'cache/',
        'log\\'           => APP_PATH . 'log/',
        'config\\'        => APP_PATH . 'config/',
        'traits\\'        => APP_PATH . 'traits/',
    ];

    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }

        spl_autoload_register([self::class, 'autoload'], true, true);
        self::$registered = true;
    }

    public static function autoload(string $class): void
    {
        // 最长前缀优先：否则先注册的短前缀（如 core\）会永久遮蔽后注册的具体前缀
        // （如 core\traits\），addNamespace() 新增的更具体前缀会被静默忽略。
        $prefixes = self::$prefixes;
        uksort($prefixes, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach ($prefixes as $prefix => $path) {
            $len = strlen($prefix);
            if (strncmp($prefix, $class, $len) === 0) {
                $relativeClass = substr($class, $len);

                // 类名中的 '.' / '..' 段会被拼进真实路径，先行拒绝
                $segments = explode('\\', $relativeClass);
                foreach ($segments as $segment) {
                    if ($segment === '.' || $segment === '..' || $segment === '') {
                        continue 2;
                    }
                }

                $file = $path . str_replace('\\', '/', $relativeClass) . '.php';

                // 防止路径遍历：验证解析后的真实路径仍在预期目录内
                // 基路径必须补上目录分隔符，否则 ns/coreEvil 会被 ns/core 前缀匹配放行
                $realBase = realpath($path);
                $realFile = realpath($file);
                if ($realBase === false || $realFile === false) {
                    continue;
                }
                $realBase = rtrim($realBase, '/\\') . DIRECTORY_SEPARATOR;
                if (!str_starts_with($realFile, $realBase)) {
                    continue;
                }

                if (file_exists($file)) {
                    require $file;
                    return;
                }
            }
        }
    }

    public static function addNamespace(string $prefix, string $path): void
    {
        $prefix = trim($prefix, '\\') . '\\';
        self::$prefixes[$prefix] = rtrim($path, '/') . '/';
    }
}
