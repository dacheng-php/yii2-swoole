<?php

// Uncomment below only if you need to change from production defaults
//defined('YII_DEBUG') or define('YII_DEBUG', true);
//defined('YII_ENV') or define('YII_ENV', 'dev');

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';

Yii::$classMap['yii\helpers\ArrayHelper'] = __DIR__ . '/../helpers/ArrayHelper.php';

$config = require __DIR__ . '/../config/web.php';

Yii::createObject($config)->run();
