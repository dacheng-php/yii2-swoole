<?php

use yii2swoole\{debug, log, redis, web};
use yii\helpers\BaseArrayHelper;

$commonConfig = require __DIR__ . '/common.php';

$config = [
    'class' => web\Application::class,
    'id' => 'basic',
    'basePath' => dirname(__DIR__),
    'bootstrap' => ['log'],
    'aliases' => [
        '@bower' => '@vendor/bower-asset',
        '@npm' => '@vendor/npm-asset',
        '@webroot' => dirname(__DIR__) . '/web',
        '@web' => '',
    ],
    'components' => [
        'request' => [
            'cookieValidationKey' => 'test-secret-key',
            'baseUrl' => '',
            'scriptUrl' => '/index.php',
            'parsers' => [
                'application/json' => \yii\web\JsonParser::class,
            ],
        ],
        'assetManager' => [
            'basePath' => dirname(__DIR__) . '/web/assets',
            'baseUrl' => '/assets',
        ],
        'session' => [
            'class' => redis\Session::class,
            'redis' => 'redis',
            'keyPrefix' => 'phpsession:',
            'timeout' => (int) (getenv('YII_SESSION_TIMEOUT') ?: 1440),
        ],
        'user' => [
            'identityClass' => \app\models\User::class,
            'enableAutoLogin' => false,
        ],
        'errorHandler' => [
            'errorAction' => 'site/error',
        ],
        'mailer' => [
            'class' => \yii\symfonymailer\Mailer::class,
            'viewPath' => '@app/mail',
            'useFileTransport' => true,
        ],
        'log' => [
            'traceLevel' => YII_DEBUG ? 3 : 0,
            'flushInterval' => 100,
            'targets' => [
                [
                    'class' => log\FileTarget::class,
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

if (YII_ENV_DEV && class_exists(\yii\debug\Module::class)) {
    $config['bootstrap'][] = 'debug';
    $config['modules']['debug'] = [
        'class' => debug\Module::class,
        'allowedIPs' => ['127.0.0.1', '::1'],
    ];
}

return BaseArrayHelper::merge($commonConfig, $config);
