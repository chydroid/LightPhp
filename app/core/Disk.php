<?php
declare(strict_types=1);

namespace core;

/**
 * 磁盘接口 - 各驱动实现的统一契约
 */
interface Disk
{
    /**
     * 写入文件
     *
     * @param string $path 相对路径
     * @param string|resource $content 文件内容或流资源
     * @return bool
     * @throws \InvalidArgumentException 当路径包含路径遍历（..）时
     */
    public function put(string $path, mixed $content): bool;

    /**
     * 读取文件内容
     *
     * @return string
     * @throws \RuntimeException 当文件不存在时
     */
    public function get(string $path): string;

    public function exists(string $path): bool;

    public function delete(string $path): bool;

    /**
     * 返回文件的可访问 URL（无 URL 配置时抛异常）
     */
    public function url(string $path): string;

    /**
     * 列出指定目录下的文件（相对路径）
     *
     * @return list<string>
     */
    public function files(string $directory = ''): array;

    /**
     * 列出指定目录下的子目录（相对路径）
     *
     * @return list<string>
     */
    public function directories(string $directory = ''): array;
}
