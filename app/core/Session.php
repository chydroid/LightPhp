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
            $isSecure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
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
                self::ageFlash();
            }
        } elseif (session_status() === PHP_SESSION_ACTIVE) {
            self::$started = true;
            self::ageFlash();
        }
    }

    /**
     * 老化 flash 数据：将新写入的 flash 数据标记为旧，删除上一轮的旧 flash 数据
     */
    private static function ageFlash(): void
    {
        // 删除上一轮标记为旧的 flash 数据
        $old = $_SESSION['_flash_old'] ?? [];
        if (is_array($old)) {
            foreach ($old as $key) {
                unset($_SESSION['_flash_' . $key]);
            }
        }
        // 当前轮的 new 变为下一轮的 old
        $new = $_SESSION['_flash_new'] ?? [];
        $_SESSION['_flash_old'] = is_array($new) ? $new : [];
        $_SESSION['_flash_new'] = [];
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
        @session_destroy();
        self::$started = false;
    }

    public static function regenerate(bool $destroyOld = true): void
    {
        self::start();
        session_regenerate_id($destroyOld);
    }

    public static function flash(string $key, mixed $value = null): mixed
    {
        self::start();
        if ($value === null) {
            return self::pull('_flash_' . $key);
        }
        $_SESSION['_flash_' . $key] = $value;
        $_SESSION['_flash_new'][] = $key;
        return null;
    }

    public static function flashSet(string $key, mixed $value): void
    {
        self::start();
        $_SESSION['_flash_' . $key] = $value;
        $_SESSION['_flash_new'][] = $key;
    }

    public static function flashGet(string $key, $default = null): mixed
    {
        self::start();
        $flashKey = '_flash_' . $key;
        if (!\array_key_exists($flashKey, $_SESSION)) {
            return $default;
        }
        $value = $_SESSION[$flashKey];
        unset($_SESSION[$flashKey]);
        return $value;
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
