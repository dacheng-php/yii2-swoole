<?php

$commonConfig = require __DIR__ . '/common.php';

$config = [
    'id' => 'basic',
    'basePath' => dirname(__DIR__),
    'bootstrap' => [],
    'aliases' => [
        '@bower' => '@vendor/bower-asset',
        '@npm'   => '@vendor/npm-asset',
        '@webroot' => dirname(__DIR__) . '/web',
    ],
    'components' => [
        'request' => [
            'cookieValidationKey' => 'test-secret-key',
            'enableCsrfValidation' => false,
            'baseUrl' => '',
            'scriptUrl' => '/index.php',
            'parsers' => [
                'application/json' => 'yii\web\JsonParser',
            ],
        ],
        'assetManager' => [
            'basePath' => dirname(__DIR__) . '/web/assets',
            'baseUrl' => '/assets',
        ],
        'session' => [
            'class' => \Dacheng\Yii2\Swoole\Session\CoroutineSession::class,
            'redis' => 'redis',
            'keyPrefix' => 'phpsession:',
            'timeout' => (int)(getenv('YII_SESSION_TIMEOUT') ?: 1440),
        ],
        'user' => [
            'class' => \Dacheng\Yii2\Swoole\User\CoroutineUser::class,
            'identityClass' => 'app\models\User',
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
            'flushInterval' => 1,
            'targets' => [
                [
                    'class' => \Dacheng\Yii2\Swoole\Log\CoroutineFileTarget::class,
                    'levels' => YII_DEBUG ? ['error', 'warning', 'info'] : ['error', 'warning'],
                    'exportInterval' => 1,
                    'logFile' => '@runtime/logs/app.log',
                    'maxFileSize' => 10240, // 10MB
                    'maxLogFiles' => 5,
                    'enableRotation' => true,
                    'categories' => [],
                    'except' => [],
                    'logVars' => false,
                    'microtime' => true,
                ],
            ],
        ],
        'urlManager' => [
            'enablePrettyUrl' => true,
            'showScriptName' => false,
            'rules' => [
                '' => 'site/index',
                '<controller:\\w+>/<action:\\w+>' => '<controller>/<action>',
            ],
        ],
    ],
    'params' => require __DIR__ . '/params.php',
];

$config = \yii\helpers\BaseArrayHelper::merge($config, $commonConfig);

if (YII_ENV_DEV) {
//    if (class_exists('yii\\debug\\Module')) {
//        $config['bootstrap'][] = 'debug';
//        $config['modules']['debug'] = [
//            'class' => 'yii\\debug\\Module',
//        ];
//    }

//    if (class_exists('yii\\gii\\Module')) {
//        $config['bootstrap'][] = 'gii';
//        $config['modules']['gii'] = [
//            'class' => 'yii\\gii\\Module',
//        ];
//    }
}

return $config;
