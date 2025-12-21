<?php

use yii\helpers\BaseArrayHelper;

$commonConfig = require __DIR__ . '/common.php';

$config = [
    'id' => 'basic-console',
    'basePath' => dirname(__DIR__),
    'bootstrap' => [
        'swooleHttpServer',
    ],
    'controllerNamespace' => 'app\commands',
    'aliases' => [
        '@bower' => '@vendor/bower-asset',
        '@npm'   => '@vendor/npm-asset',
        '@tests' => '@app/tests',
    ],
    'controllerMap' => [
        'swoole' => [
            'class' => \Dacheng\Yii2\Swoole\Console\SwooleController::class,
            'serverComponent' => 'swooleHttpServer',
        ],
        /*
        'fixture' => [ // Fixture generation command line.
            'class' => 'yii\faker\FixtureController',
        ],
        */
    ],
    'components' => [
        'swooleHttpServer' => [
            'memoryLimit' => '2G',
            'classMap' => [],
            'dispatcher' => new \Dacheng\Yii2\Swoole\Server\RequestDispatcher(__DIR__ . '/web.php'),
        ],
    ],
    'params' => require __DIR__ . '/params.php',
];

return BaseArrayHelper::merge($commonConfig, $config);
