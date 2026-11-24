<?php

use Dacheng\Yii2\Swoole\{Log, Redis, Web};
use yii\helpers\BaseArrayHelper;

$commonConfig = require __DIR__ . '/common.php';

$config = [
    'class' => Web\Application::class,
    'id' => 'yii2-swoole-example',
    'basePath' => dirname(__DIR__),
    'bootstrap' => ['log'],
    'components' => [
        'request' => [
            'cookieValidationKey' => 'test-secret-key',
            'enableCsrfValidation' => false,
            'parsers' => [
                'application/json' => \yii\web\JsonParser::class,
            ],
        ],
        'response' => [
            'format' => Web\Response::FORMAT_JSON,
        ],
        'session' => [
            'class' => Redis\Session::class,
            'redis' => 'redis',
            'keyPrefix' => 'phpsession:',
            'timeout' => (int) (getenv('YII_SESSION_TIMEOUT') ?: 1440),
        ],
        'user' => [
            'identityClass' => \app\models\UserIdentity::class,
            'enableAutoLogin' => false,
        ],
        'log' => [
            'traceLevel' => YII_DEBUG ? 3 : 0,
            'flushInterval' => 100,
            'targets' => [
                [
                    'class' => Log\FileTarget::class,
                    'levels' => YII_DEBUG ? ['error', 'warning', 'info'] : ['error', 'warning'],
                    'exportInterval' => 100,
                    'logFile' => '@runtime/logs/app.log',
                    'maxFileSize' => 10240,
                    'maxLogFiles' => 5,
                    'logVars' => [],
                    'microtime' => true,
                ],
            ],
        ],
        'urlManager' => [
            'enablePrettyUrl' => true,
            'showScriptName' => false,
            'rules' => [
                '' => 'site/index',
                '<controller:\w+>/<action:\w+>' => '<controller>/<action>',
            ],
        ],
    ],
    'params' => require __DIR__ . '/params.php',
];

return BaseArrayHelper::merge($commonConfig, $config);
