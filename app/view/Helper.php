<?php
declare(strict_types=1);

namespace view;

class Helper
{
    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    public static function old(string $key, mixed $default = ''): mixed
    {
        return $_POST[$key] ?? $_GET[$key] ?? $default;
    }

    public static function dump(mixed $value): void
    {
        echo '<pre>';
        var_dump($value);
        echo '</pre>';
    }

    public static function asset(string $path): string
    {
        $base = rtrim($_SERVER['SCRIPT_NAME'] ?? '', '/');
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
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
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
