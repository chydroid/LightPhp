<?php
declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | 默认数据库连接
    |--------------------------------------------------------------------------
    |
    | 支持: mysql, sqlite
    | 通过 DB::connection('sqlite')->query(...) 动态切换
    |
    */
    'default' => env('DB_CONNECTION', 'mysql'),

    /*
    |--------------------------------------------------------------------------
    | 数据库连接配置
    |--------------------------------------------------------------------------
    |
    | 每个连接包含独立的驱动、主机、凭证和 PDO 选项。
    | options 数组中的值会合并到默认 PDO 选项之上，可用于配置
    | SSL/TLS、连接超时、持久连接等。
    |
    */
    'connections' => [

        'mysql' => [
            'driver'    => 'mysql',
            'host'      => env('DB_HOST', '127.0.0.1'),
            'port'      => (int) env('DB_PORT', 3306),
            'database'  => env('DB_DATABASE', 'lightphp'),
            'username'  => env('DB_USERNAME', 'root'),
            'password'  => env('DB_PASSWORD', ''),
            'charset'   => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix'    => env('DB_PREFIX', ''),
            'strict'    => env('DB_STRICT', true),
            'engine'    => env('DB_ENGINE', 'InnoDB'),
            'options'   => [
                // 连接超时（秒）
                \PDO::ATTR_TIMEOUT => env('DB_TIMEOUT', 5),
                // 持久连接（高并发场景下减少连接开销）
                \PDO::ATTR_PERSISTENT => env('DB_PERSISTENT', false),
                // MySQL SSL/TLS 配置（云数据库通常需要）
                // \PDO::MYSQL_ATTR_SSL_CA => env('DB_SSL_CA', ''),
                // \PDO::MYSQL_ATTR_SSL_CERT => env('DB_SSL_CERT', ''),
                // \PDO::MYSQL_ATTR_SSL_KEY => env('DB_SSL_KEY', ''),
                // \PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => env('DB_SSL_VERIFY', false),
            ],
        ],

        'sqlite' => [
            'driver'   => 'sqlite',
            'database' => env('DB_SQLITE_PATH', STORAGE_PATH . 'database.sqlite'),
            'prefix'   => env('DB_PREFIX', ''),
            'options'  => [
                \PDO::ATTR_TIMEOUT => env('DB_TIMEOUT', 5),
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | 全局表前缀
    |--------------------------------------------------------------------------
    |
    | 若未在连接中单独配置 prefix，则使用此全局前缀。
    |
    */
    'prefix' => env('DB_PREFIX', ''),

];
