<?php

use yii2swoole\{console, log, server};
use yii\helpers\BaseArrayHelper;

$commonConfig = require __DIR__ . '/common.php';

$config = [
    'id' => 'yii2-swoole-console',
    'basePath' => dirname(__DIR__),
    'bootstrap' => ['log'],
    'controllerNamespace' => 'app\commands',
    'controllerMap' => [
        'swoole' => [
            'class' => console\SwooleController::class,
            'serverComponent' => 'swooleHttpServer',
        ],
    ],
    'components' => [
        'swooleHttpServer' => [
            'class' => server\HttpServer::class,
            'host' => getenv('SWOOLE_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('SWOOLE_PORT') ?: 9501),
            'mode' => SWOOLE_BASE,
            'workers' => (int) (getenv('SWOOLE_WORKERS') ?: 0),
            'webConfig' => __DIR__ . '/web.php',
            'documentRoot' => dirname(__DIR__) . '/web',
            'serverHeader' => 'yii2-swoole',
            'maxRequest' => 0,
            'maxMemoryMb' => 256,
            'maxWaitTime' => 30,
            'maxCoroutine' => 100000,
            'poolReapInterval' => 30.0,
        ],
        'log' => [
            'traceLevel' => YII_DEBUG ? 3 : 0,
            'flushInterval' => 100,
            'targets' => [
                [
                    'class' => log\FileTarget::class,
                    'levels' => YII_DEBUG ? ['error', 'warning', 'info'] : ['error', 'warning'],
                    'exportInterval' => 100,
                    'logFile' => '@runtime/logs/console.log',
                    'maxFileSize' => 10240,
                    'maxLogFiles' => 5,
                    'logVars' => [],
                    'microtime' => true,
                ],
            ],
        ],
    ],
    'params' => require __DIR__ . '/params.php',
];

return BaseArrayHelper::merge($commonConfig, $config);
