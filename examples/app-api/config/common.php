<?php

use Dacheng\Yii2\Swoole\{Db, Queue, Redis};

return [
    'bootstrap' => ['queue'],
    'components' => [
        'redis' => [
            'class' => Redis\Connection::class,
            'hostname' => getenv('YII_REDIS_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('YII_REDIS_PORT') ?: 6379),
            'database' => (int) (getenv('YII_REDIS_DATABASE') ?: 0),
            'password' => getenv('YII_REDIS_PASSWORD') ?: null,
            'socketClientFlags' => STREAM_CLIENT_CONNECT,
            'connectionTimeout' => 1.0,
            'dataTimeout' => 1.0,
            'pool' => [
                'maxActive' => (int) (getenv('YII_REDIS_POOL_MAX_ACTIVE') ?: 20),
                'minIdle' => (int) (getenv('YII_REDIS_POOL_MIN_IDLE') ?: 0),
                'waitTimeout' => (float) (getenv('YII_REDIS_POOL_WAIT_TIMEOUT') ?: 5.0),
            ],
        ],
        'cache' => [
            'class' => Redis\Cache::class,
            'redis' => 'redis',
            'keyPrefix' => 'yii2-cache:',
        ],
        'queue' => [
            'class' => Queue\RedisQueue::class,
            'redis' => 'redis',
            'channel' => 'yii2-queue:',
            'concurrency' => (int) (getenv('YII_QUEUE_CONCURRENCY') ?: 10),
        ],
        'db' => [
            'class' => Db\Connection::class,
            'dsn' => getenv('YII_DB_DSN') ?: 'mysql:host=127.0.0.1;dbname=yii2swoole',
            'username' => getenv('YII_DB_USERNAME') ?: 'root',
            'password' => getenv('YII_DB_PASSWORD') ?: '',
            'charset' => 'utf8mb4',
            'enableSchemaCache' => true,
            'schemaCacheDuration' => 3600,
            'schemaCache' => 'cache',
            'pool' => [
                'maxActive' => (int) (getenv('YII_DB_POOL_MAX_ACTIVE') ?: 10),
                'minIdle' => (int) (getenv('YII_DB_POOL_MIN_IDLE') ?: 0),
                'waitTimeout' => (float) (getenv('YII_DB_POOL_WAIT_TIMEOUT') ?: 5.0),
            ],
        ],
    ],
];
