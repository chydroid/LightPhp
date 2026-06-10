<?php
declare(strict_types=1);

namespace core;

class Env
{
    private static array $vars = [];
    private static bool $loaded = false;

    public static function load(string $path): void
    {
        if (self::$loaded) {
            return;
        }

        if (!file_exists($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }
        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_contains($line, '=')) {
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);

                // 处理引号包裹的值：提取引号内的内容，忽略引号后的注释
                if (str_starts_with($value, '"')) {
                    $closingPos = strpos($value, '"', 1);
                    if ($closingPos !== false) {
                        $value = substr($value, 1, $closingPos - 1);
                    }
                } elseif (str_starts_with($value, "'")) {
                    $closingPos = strpos($value, "'", 1);
                    if ($closingPos !== false) {
                        $value = substr($value, 1, $closingPos - 1);
                    }
                } else {
                    // 无引号的值：移除行内注释（# 后的内容）
                    $commentPos = strpos($value, ' #');
                    if ($commentPos !== false) {
                        $value = trim(substr($value, 0, $commentPos));
                    }
                }

                $originalValue = $value;
                if ($value === 'true') $value = true;
                elseif ($value === 'false') $value = false;
                elseif ($value === 'null') $value = null;

                self::$vars[$key] = $value;

                if (!isset($_ENV[$key])) {
                    $_ENV[$key] = $originalValue;
                    putenv("{$key}={$originalValue}");
                }
            }
        }

        self::$loaded = true;
    }

    public static function get(string $key, $default = null): mixed
    {
        if (array_key_exists($key, self::$vars)) {
            return self::$vars[$key];
        }

        $envValue = getenv($key);
        if ($envValue !== false) {
            if ($envValue === 'true') return true;
            if ($envValue === 'false') return false;
            if ($envValue === 'null') return null;
            return $envValue;
        }

        if (isset($_ENV[$key])) {
            return $_ENV[$key];
        }

        return $default;
    }

    public static function set(string $key, $value): void
    {
        self::$vars[$key] = $value;
        $stringValue = match (true) {
            $value === true => 'true',
            $value === false => 'false',
            $value === null => 'null',
            default => (string) $value,
        };
        $_ENV[$key] = $stringValue;
        putenv("{$key}={$stringValue}");
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::$vars) || getenv($key) !== false || isset($_ENV[$key]);
    }

    public static function all(): array
    {
        return self::$vars;
    }
}
