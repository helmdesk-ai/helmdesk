<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for database operations. This is
    | the connection which will be utilized unless another connection
    | is explicitly specified when you execute a query / statement.
    |
    */

    'default' => env('DB_CONNECTION', 'sqlite'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Below are all of the database connections defined for your application.
    | An example configuration is provided for each database system which
    | is supported by Laravel. You're free to add / remove connections.
    |
    */

    'connections' => [

        'sqlite' => [  // 主库
            'driver' => 'sqlite',
            'database' => env('DB_DATABASE', storage_path('database/main.sqlite')),
            'busy_timeout' => 5000,
            'journal_mode' => 'WAL',
            'synchronous' => 'NORMAL',
            'transaction_mode' => 'immediate',
        ],

        'sqlite_rag' => [ // RAG / 向量库
            'driver' => 'sqlite',
            'database' => env('DB_RAG_DATABASE', storage_path('database/rag.sqlite')),
            'journal_mode' => 'WAL',
            'synchronous' => 'NORMAL',
            'busy_timeout' => 5000,
            'transaction_mode' => 'immediate',
        ],

        'sqlite_cache' => [ // 缓存库
            'driver' => 'sqlite',
            'database' => env('DB_CACHE_DATABASE', storage_path('database/cache.sqlite')),
            'journal_mode' => 'WAL',
            'synchronous' => 'NORMAL',
            'busy_timeout' => 5000,
            'transaction_mode' => 'immediate',
        ],

        'sqlite_session' => [ // session 库
            'driver' => 'sqlite',
            'database' => env('DB_SESSION_DATABASE', storage_path('database/session.sqlite')),
            'journal_mode' => 'WAL',
            'synchronous' => 'NORMAL',
            'busy_timeout' => 5000,
            'transaction_mode' => 'immediate',
        ],

        'sqlite_jobs' => [ // jobs 库
            'driver' => 'sqlite',
            'database' => env('DB_JOBS_DATABASE', storage_path('database/jobs.sqlite')),
            'journal_mode' => 'WAL',
            'synchronous' => 'NORMAL',
            'busy_timeout' => 5000,
            'transaction_mode' => 'immediate',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't actually been run on the database.
    |
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Redis is an open source, fast, and advanced key-value store that also
    | provides a richer body of commands than a typical key-value system
    | such as Memcached. You may define your connection settings here.
    |
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'laravel')).'-database-'),
            'persistent' => env('REDIS_PERSISTENT', false),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

    ],

];
