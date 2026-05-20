<?php
declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | 默认缓存驱动
    |--------------------------------------------------------------------------
    |
    | 支持: file, redis, memcached
    | 可通过 Cache::driver('redis')->get('key') 动态切换
    |
    */
    'default' => env('CACHE_DRIVER', 'file'),

    /*
    |--------------------------------------------------------------------------
    | 缓存驱动配置
    |--------------------------------------------------------------------------
    */
    'stores' => [

        'file' => [
            'driver' => 'file',
            'path'   => STORAGE_PATH . 'cache',
            'expire' => 3600,
        ],

        'redis' => [
            'driver'       => 'redis',
            'host'         => env('REDIS_HOST', '127.0.0.1'),
            'port'         => env('REDIS_PORT', 6379),
            'password'     => env('REDIS_PASSWORD', null),
            'database'     => env('REDIS_DB', 0),
            'prefix'       => env('REDIS_PREFIX', 'lightphp:cache:'),
            'expire'       => 3600,
            'timeout'      => 2.5,
            'persistent'   => true,
            'persistent_id' => env('REDIS_PERSISTENT_ID', 'lightphp'),
        ],

        'memcached' => [
            'driver'         => 'memcached',
            'persistent_id'  => env('MEMCACHED_PERSISTENT_ID', 'lightphp'),
            'prefix'         => env('MEMCACHED_PREFIX', 'lightphp:cache:'),
            'expire'         => 3600,
            'servers'        => [
                [
                    'host'   => env('MEMCACHED_HOST', '127.0.0.1'),
                    'port'   => env('MEMCACHED_PORT', 11211),
                    'weight' => 100,
                ],
            ],
            'options' => [
                'username' => env('MEMCACHED_USERNAME', null),
                'password' => env('MEMCACHED_PASSWORD', null),
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | 页面输出缓存
    |--------------------------------------------------------------------------
    |
    | 通过 OutputCache 中间件实现整页缓存
    | 在路由/控制器中配置: ['middleware' => ['output_cache:3600']]
    |
    */
    'output_cache' => [
        'enabled' => env('OUTPUT_CACHE_ENABLED', false),
        'ttl'     => env('OUTPUT_CACHE_TTL', 3600),
        'except'  => ['/admin/*', '/api/*'],
    ],

];