<?php

return [
    'bootstrap' => [
        'queue',
        'log',
    ],
    'components' => [
        'redis' => [
            'class' => \Dacheng\Yii2\Swoole\Redis\CoroutineRedisConnection::class,
            'hostname' => getenv('YII_REDIS_HOST') ?: '127.0.0.1',
            'port' => (int)(getenv('YII_REDIS_PORT') ?: 6379),
            'database' => (int)(getenv('YII_REDIS_DATABASE') ?: 0),
            'password' => getenv('YII_REDIS_PASSWORD') ?: null,
            'socketClientFlags' => STREAM_CLIENT_CONNECT,
            'connectionTimeout' => 1.0,
            'dataTimeout' => 1.0,
            'poolMaxActive' => (int)(getenv('YII_REDIS_POOL_MAX_ACTIVE') ?: 20),
            'poolWaitTimeout' => (float)(getenv('YII_REDIS_POOL_WAIT_TIMEOUT') ?: 5.0),
        ],
        'cache' => [
            'class' => \Dacheng\Yii2\Swoole\Cache\CoroutineRedisCache::class,
            'redis' => 'redis',
            'keyPrefix' => 'yii2-cache:',
        ],
        'queue' => [
            'class' => \Dacheng\Yii2\Swoole\Queue\CoroutineRedisQueue::class,
            'redis' => 'redis',
            'channel' => 'yii2-queue:',
            'concurrency' => (int)(getenv('YII_QUEUE_CONCURRENCY') ?: 10),
            'executeInline' => true,
        ],
        'db' => [
            'class' => \Dacheng\Yii2\Swoole\Db\CoroutineDbConnection::class,
            'dsn' => getenv('YII_DB_DSN') ?: 'mysql:host=127.0.0.1;dbname=yii2basic',
            'username' => getenv('YII_DB_USERNAME') ?: 'root',
            'password' => getenv('YII_DB_PASSWORD') ?: '',
            'charset' => 'utf8mb4',
            'poolMaxActive' => (int)(getenv('YII_DB_POOL_MAX_ACTIVE') ?: 10),
            'poolWaitTimeout' => (float)(getenv('YII_DB_POOL_WAIT_TIMEOUT') ?: 5.0),
            'enableSchemaCache' => true,
            'schemaCacheDuration' => 3600,
            'schemaCache' => 'cache',
        ],
        'log' => [
            'traceLevel' => YII_DEBUG ? 3 : 0,
            'flushInterval' => 1,
            'targets' => [
                [
                    'class' => \Dacheng\Yii2\Swoole\Log\CoroutineFileTarget::class,
                    'levels' => YII_DEBUG ? ['error', 'warning', 'info'] : ['error', 'warning'],
                    'exportInterval' => 1,
                    'logFile' => '@runtime/logs/console.log',
                    'maxFileSize' => 10240, // 10MB
                    'maxLogFiles' => 5,
                    'enableRotation' => true,
                    'categories' => [],
                    'except' => [],
                    'logVars' => [],
                    'microtime' => true,
                ],
            ],
        ],
    ],
];

