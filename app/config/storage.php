<?php
declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | 默认磁盘
    |--------------------------------------------------------------------------
    |
    | 通过 Storage::disk() 不传参时使用。可在 .env 中通过 STORAGE_DISK 覆盖。
    |
    */
    'default' => env('STORAGE_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | 磁盘配置
    |--------------------------------------------------------------------------
    |
    | 每个磁盘独立指定 driver 与 root（绝对路径）。url 为对外可访问的 URL 前缀，
    | 仅适用于公开磁盘；本地私有磁盘可不配置 url。
    |
    | driver=local 基于文件系统；s3 stub 留作后续扩展（需自行实现 S3Disk）。
    |
    */
    'disks' => [

        'local' => [
            'driver' => 'local',
            'root'   => STORAGE_PATH . 'app/',
            'url'    => null,
            'throw'  => true,
        ],

        'public' => [
            'driver' => 'local',
            'root'   => PUBLIC_PATH . 'uploads/',
            'url'    => '/uploads',
            'throw'  => true,
        ],

        // S3 占位（暂未实现，调用时抛 InvalidArgumentException）
        's3' => [
            'driver' => 's3',
            'key'    => env('AWS_ACCESS_KEY_ID', ''),
            'secret' => env('AWS_SECRET_ACCESS_KEY', ''),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'bucket' => env('AWS_BUCKET', ''),
            'url'    => env('AWS_URL', ''),
        ],

    ],

];
