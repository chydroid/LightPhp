<?php
declare(strict_types=1);

namespace core;

class Session
{
    private static bool $started = false;

    public static function start(): void
    {
        if (self::$started) {
            return;
        }

        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            // 设置安全的 session cookie 参数
            $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
            @session_set_cookie_params([
                'lifetime'  => 0,
                'path'      => '/',
                'secure'    => $isSecure,
                'httponly'  => true,
                'samesite'  => 'Lax',
            ]);
            if (!@session_start()) {
                error_log('LightPHP Session: Failed to start session');
            } else {
                self::$started = true;
            }
        } elseif (session_status() === PHP_SESSION_ACTIVE) {
            self::$started = true;
        }
    }

    public static function set(string $key, mixed $value): void
    {
        self::start();
        $_SESSION[$key] = $value;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        self::start();
        return $_SESSION[$key] ?? $default;
    }

    public static function has(string $key): bool
    {
        self::start();
        return array_key_exists($key, $_SESSION);
    }

    public static function delete(string $key): void
    {
        self::start();
        unset($_SESSION[$key]);
    }

    public static function pull(string $key, mixed $default = null): mixed
    {
        self::start();
        $value = $_SESSION[$key] ?? $default;
        unset($_SESSION[$key]);
        return $value;
    }

    public static function flush(): void
    {
        self::start();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $params['path'],
                'domain'   => $params['domain'],
                'secure'   => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }
        session_destroy();
        self::$started = false;
    }

    public static function regenerate(): void
    {
        self::start();
        session_regenerate_id(true);
    }

    public static function flash(string $key, mixed $value = null): mixed
    {
        self::start();
        if ($value === null) {
            return self::pull('_flash_' . $key);
        }
        $_SESSION['_flash_' . $key] = $value;
        return null;
    }

    public static function flashSet(string $key, mixed $value): void
    {
        self::start();
        $_SESSION['_flash_' . $key] = $value;
    }

    public static function flashGet(string $key, $default = null): mixed
    {
        self::start();
        return self::pull('_flash_' . $key, $default);
    }

    public static function all(): array
    {
        self::start();
        return $_SESSION;
    }

    public static function token(): string
    {
        self::start();
        if (!self::has('_token')) {
            self::set('_token', bin2hex(random_bytes(32)));
        }
        return self::get('_token');
    }

    /**
     * 重新生成 CSRF token
     * 在每次验证通过后调用，防止 token 重放攻击
     */
    public static function regenerateToken(): void
    {
        self::start();
        self::set('_token', bin2hex(random_bytes(32)));
    }

    public static function id(): string
    {
        self::start();
        return session_id();
    }
}
