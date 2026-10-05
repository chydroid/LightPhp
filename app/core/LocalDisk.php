<?php
declare(strict_types=1);

namespace core;

/**
 * 本地磁盘驱动 - 基于文件系统
 */
class LocalDisk implements Disk
{
    private string $root;
    private ?string $urlPrefix;

    public function __construct(array $config)
    {
        $root = rtrim($config['root'] ?? '', '/\\');
        if ($root === '') {
            throw new \InvalidArgumentException('LocalDisk requires a non-empty "root" path.');
        }
        $this->root = $root;
        $this->urlPrefix = $config['url'] ?? null;
    }

    public function put(string $path, mixed $content): bool
    {
        // 空路径 / '.' 会把 root 目录本身当成目标文件，
        // 导致 file_put_contents 抛出未抑制的 PHP Warning，直接拒绝。
        if ($this->isEmptyPath($path)) {
            return false;
        }

        $full = $this->normalizePath($path);
        $dir = dirname($full);
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true)) {
                return false;
            }
        }

        if (is_resource($content)) {
            $dest = @fopen($full, 'wb');
            if ($dest === false) {
                return false;
            }
            try {
                stream_copy_to_stream($content, $dest);
                return true;
            } finally {
                fclose($dest);
            }
        }

        return @file_put_contents($full, (string) $content, LOCK_EX) !== false;
    }

    public function get(string $path): string
    {
        $full = $this->normalizePath($path);
        if (!file_exists($full)) {
            throw new \RuntimeException("File not found: {$path}");
        }
        $content = file_get_contents($full);
        if ($content === false) {
            throw new \RuntimeException("Failed to read file: {$path}");
        }
        return $content;
    }

    public function exists(string $path): bool
    {
        return file_exists($this->normalizePath($path));
    }

    public function delete(string $path): bool
    {
        $full = $this->normalizePath($path);
        if (!file_exists($full)) {
            return false;
        }
        if (is_dir($full)) {
            return @rmdir($full);
        }
        return @unlink($full);
    }

    public function url(string $path): string
    {
        if ($this->urlPrefix === null) {
            throw new \RuntimeException("Disk has no public URL configured for path: {$path}");
        }
        // 复用 normalizePath() 的词法校验：否则 url('../../secret.txt') 会产出
        // /uploads/../../secret.txt，浏览器会归一化成 /secret.txt（任意站内路径）。
        $segments = $this->normalizePath($path);
        $relative = ltrim(substr($segments, strlen($this->root)), '/\\');

        $encoded = implode('/', array_map(
            static fn (string $segment): string => rawurlencode($segment),
            $relative === '' ? [] : explode('/', $relative)
        ));

        return rtrim($this->urlPrefix, '/') . '/' . $encoded;
    }

    public function files(string $directory = ''): array
    {
        $dir = $this->normalizePath($directory);
        if (!is_dir($dir)) {
            return [];
        }
        $entries = @scandir($dir);
        if ($entries === false) {
            return [];
        }
        $result = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_file($full)) {
                $rel = $directory === '' ? $entry : trim($directory, '/\\') . '/' . $entry;
                $result[] = str_replace('\\', '/', $rel);
            }
        }
        sort($result);
        return $result;
    }

    public function directories(string $directory = ''): array
    {
        $dir = $this->normalizePath($directory);
        if (!is_dir($dir)) {
            return [];
        }
        $entries = @scandir($dir);
        if ($entries === false) {
            return [];
        }
        $result = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($full)) {
                $rel = $directory === '' ? $entry : trim($directory, '/\\') . '/' . $entry;
                $result[] = str_replace('\\', '/', $rel);
            }
        }
        sort($result);
        return $result;
    }

    public function getRoot(): string
    {
        return $this->root;
    }

    /**
     * 判断路径是否为空路径（解析后落在 root 本身）
     *
     * @param string $path 相对路径
     */
    private function isEmptyPath(string $path): bool
    {
        $trimmed = str_replace('\\', '/', trim($path));
        return $trimmed === '' || $trimmed === '.';
    }

    /**
     * 将相对路径转换为绝对路径，并拒绝路径遍历
     *
     * 通过词法分析剥离 '.' 与 '..' 段，确保最终路径仍在 root 之内。
     * 不依赖 realpath()，因此对尚未创建的文件/目录也能正常工作。
     *
     * @param string $path 相对路径
     */
    private function normalizePath(string $path): string
    {
        // 规范化分隔符
        $normalized = str_replace('\\', '/', $path);
        $parts = explode('/', $normalized);
        $resolved = [];
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                throw new \InvalidArgumentException("Path traversal is not allowed: {$path}");
            }
            $resolved[] = $part;
        }

        $full = $this->root;
        if (!empty($resolved)) {
            $full .= '/' . implode('/', $resolved);
        }
        return $full;
    }
}
