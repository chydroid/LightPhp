<?php
declare(strict_types=1);

namespace core;

/**
 * 文件存储 - 多盘支持
 *
 * 通过 config/storage.php 配置多个磁盘，每个磁盘独立指定驱动与根目录。
 * 默认驱动通过 storage.default 配置。
 *
 * 用法：
 *   $storage = new \core\Storage();
 *   $storage->disk('local')->put('foo.txt', 'hello');
 *   echo $storage->disk()->get('foo.txt');  // 不传参使用默认盘
 *   $url = $storage->disk('public')->url('foo.txt');
 *
 * 配置示例（app/config/storage.php）：
 *   return [
 *       'default' => 'local',
 *       'disks' => [
 *           'local'  => ['driver' => 'local', 'root' => STORAGE_PATH . 'app/', 'url' => null],
 *           'public' => ['driver' => 'local', 'root' => PUBLIC_PATH . 'uploads/', 'url' => '/uploads'],
 *       ],
 *   ];
 */
class Storage
{
    /** @var array<string, Disk> 当前实例已实例化的盘 */
    private array $disks = [];

    private array $config;

    /**
     * @param array|null $config 不传则从 config\Config 读取 storage 配置
     */
    public function __construct(?array $config = null)
    {
        if ($config === null) {
            // 兼容未初始化 Application 的场景（CLI / 测试）
            if (class_exists(\config\Config::class)) {
                $this->config = \config\Config::get('storage', [
                    'default' => 'local',
                    'disks' => [
                        'local' => [
                            'driver' => 'local',
                            'root' => STORAGE_PATH . 'app/',
                            'url' => null,
                        ],
                    ],
                ]);
            } else {
                $this->config = [
                    'default' => 'local',
                    'disks' => [
                        'local' => [
                            'driver' => 'local',
                            'root' => STORAGE_PATH . 'app/',
                            'url' => null,
                        ],
                    ],
                ];
            }
        } else {
            $this->config = $config;
        }
    }

    /**
     * 获取磁盘实例
     *
     * @param string|null $name 为 null 时使用默认盘
     * @return Disk
     * @throws \InvalidArgumentException 当盘未配置或驱动不支持时
     */
    public function disk(?string $name = null): Disk
    {
        $name = $name ?? ($this->config['default'] ?? 'local');
        if (isset($this->disks[$name])) {
            return $this->disks[$name];
        }

        $disks = $this->config['disks'] ?? [];
        if (!isset($disks[$name])) {
            throw new \InvalidArgumentException("Storage disk [{$name}] is not configured.");
        }

        $config = $disks[$name];
        $driver = $config['driver'] ?? 'local';

        $disk = match ($driver) {
            'local' => new LocalDisk($config),
            default => throw new \InvalidArgumentException("Unsupported storage driver [{$driver}] for disk [{$name}]."),
        };

        return $this->disks[$name] = $disk;
    }

    /**
     * 默认盘上的方法代理
     */
    public function put(string $path, mixed $content): bool
    {
        return $this->disk()->put($path, $content);
    }

    public function get(string $path): string
    {
        return $this->disk()->get($path);
    }

    public function exists(string $path): bool
    {
        return $this->disk()->exists($path);
    }

    public function delete(string $path): bool
    {
        return $this->disk()->delete($path);
    }

    public function url(string $path): string
    {
        return $this->disk()->url($path);
    }

    public function files(string $directory = ''): array
    {
        return $this->disk()->files($directory);
    }

    public function directories(string $directory = ''): array
    {
        return $this->disk()->directories($directory);
    }

    /**
     * 重置内部盘缓存（测试用）
     */
    public function resetCache(): void
    {
        $this->disks = [];
    }
}
