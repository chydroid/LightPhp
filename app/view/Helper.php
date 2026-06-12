<?php
declare(strict_types=1);

namespace view;

class Helper
{
    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    public static function old(string $key, mixed $default = ''): string
    {
        $container = \core\Container::getInstance();
        if ($container !== null && $container->has('request')) {
            $value = $container->get('request')->input($key, $default);
        } else {
            $value = $_POST[$key] ?? $_GET[$key] ?? $default;
        }
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    public static function dump(mixed $value): void
    {
        echo '<pre>';
        var_dump($value);
        echo '</pre>';
    }

    public static function asset(string $path): string
    {
        // 防止协议前缀注入和路径遍历
        if (preg_match('#^https?://#i', $path) || str_contains($path, '..')) {
            return '';
        }
        $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
        return $base . '/' . ltrim($path, '/');
    }

    public static function url(string $path = ''): string
    {
        $scheme = (($_SERVER['HTTPS'] ?? 'off') === 'on') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $host = preg_replace('/[^a-zA-Z0-9.:-]/', '', $host);
        $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
        return "{$scheme}://{$host}{$base}/" . ltrim($path, '/');
    }

    public static function now(string $format = 'Y-m-d H:i:s'): string
    {
        return date($format);
    }

    public static function truncate(string $text, int $length = 100, string $suffix = '...'): string
    {
        if (mb_strlen($text) <= $length) {
            return $text;
        }
        return mb_substr($text, 0, $length) . $suffix;
    }

    public static function yesno(bool $value, array $labels = ['No', 'Yes']): string
    {
        return $value ? ($labels[1] ?? 'Yes') : ($labels[0] ?? 'No');
    }

    public static function json(mixed $value): string
    {
        $result = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP);
        return $result === false ? '' : $result;
    }

    public static function isActive(string $path, string $active = 'active'): string
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $uri = is_string($uri) ? rtrim($uri, '/') : '/';
        $path = rtrim($path, '/');
        
        if ($path === '*') {
            return $active;
        }
        
        return $uri === $path || str_starts_with($uri, $path . '/') ? $active : '';
    }
}
