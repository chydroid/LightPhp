<?php
declare(strict_types=1);

namespace core;

class Cookie
{
    public static function get(string $key, mixed $default = null): mixed
    {
        return $_COOKIE[$key] ?? $default;
    }

    public static function set(string $key, mixed $value, int $expire = 0, string $path = '/', string $domain = '', ?bool $secure = null, bool $httponly = true, string $samesite = 'Lax'): bool
    {
        if ($secure === null) {
            $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        }
        if (!is_string($value) && !is_numeric($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        $options = [
            'expires' => $expire > 0 ? time() + $expire : 0,
            'path' => $path,
            'domain' => $domain,
            'secure' => $secure,
            'httponly' => $httponly,
            'samesite' => $samesite,
        ];

        return setcookie($key, (string) $value, $options);
    }

    public static function delete(string $key, string $path = '/', string $domain = '', ?bool $secure = null, bool $httponly = true, string $samesite = 'Lax'): bool
    {
        if ($secure === null) {
            $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        }
        unset($_COOKIE[$key]);
        return setcookie($key, '', [
            'expires' => time() - 3600,
            'path' => $path,
            'domain' => $domain,
            'secure' => $secure,
            'httponly' => $httponly,
            'samesite' => $samesite,
        ]);
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, $_COOKIE);
    }

    public static function forever(string $key, mixed $value, string $path = '/', string $domain = '', ?bool $secure = null, bool $httponly = true): bool
    {
        return self::set($key, $value, 5 * 365 * 24 * 3600, $path, $domain, $secure, $httponly);
    }
}
