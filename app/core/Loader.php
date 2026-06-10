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
        foreach (self::$prefixes as $prefix => $path) {
            $len = strlen($prefix);
            if (strncmp($prefix, $class, $len) === 0) {
                $relativeClass = substr($class, $len);
                $file = $path . str_replace('\\', '/', $relativeClass) . '.php';

                // 防止路径遍历：验证解析后的真实路径仍在预期目录内
                $realBase = realpath($path);
                $realFile = realpath($file);
                if ($realBase === false || $realFile === false || !str_starts_with($realFile, $realBase)) {
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
