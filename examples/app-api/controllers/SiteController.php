<?php

namespace app\controllers;

use Throwable;
use Yii;
use yii\base\Exception as YiiException;
use yii\web\Controller;
use yii\web\HttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class SiteController extends Controller
{
    public function actions()
    {
        return [];
    }

    /**
     * Displays homepage.
     */
    public function actionIndex()
    {
        return $this->asJson([
            'message' => 'Welcome to Yii2 Swoole HTTP Server!',
            'time' => date('Y-m-d H:i:s'),
            'swoole' => swoole_version(),
            'yii' => Yii::getVersion(),
        ]);
    }

    /**
     * Memory diagnostic endpoint
     */
    public function actionMemory()
    {
        // Get logger stats
        $loggerMessages = 0;
        $targetMessages = [];
        if (Yii::$app->has('log')) {
            $logger = Yii::$app->log->getLogger();
            $loggerMessages = count($logger->messages ?? []);

            foreach (Yii::$app->log->targets as $name => $target) {
                $targetMessages[$name] = count($target->messages ?? []);
            }
        }

        // Get component counts to see what's loaded
        $componentCounts = [];
        if (method_exists(Yii::$app, 'getComponents')) {
            $components = Yii::$app->getComponents(true);
            foreach ($components as $id => $component) {
                if (is_object($component)) {
                    $componentCounts[$id] = get_class($component);
                }
            }
        }

        // Check cache type
        $cacheClass = 'not set';
        if (Yii::$app->has('cache')) {
            $cache = Yii::$app->get('cache', false);
            if ($cache) {
                $cacheClass = get_class($cache);
            }
        }

        return $this->asJson([
            'memory' => [
                'current' => round(memory_get_usage(true) / 1024 / 1024, 2) . ' MB',
                'peak' => round(memory_get_peak_usage(true) / 1024 / 1024, 2) . ' MB',
                'current_real' => round(memory_get_usage(false) / 1024 / 1024, 2) . ' MB',
            ],
            'logger' => [
                'messages_in_logger' => $loggerMessages,
                'messages_in_targets' => $targetMessages,
            ],
            'cache' => [
                'class' => $cacheClass,
            ],
            'components_loaded' => count($componentCounts),
            'coroutines' => \Swoole\Coroutine::stats(),
            'gc' => [
                'runs' => gc_status()['runs'] ?? 'n/a',
                'collected' => gc_status()['collected'] ?? 'n/a',
            ],
        ]);
    }

    /**
     * Force garbage collection endpoint
     */
    public function actionGc()
    {
        $before = memory_get_usage(true);
        $cycles = gc_collect_cycles();
        $after = memory_get_usage(true);
        $freed = $before - $after;

        return $this->asJson([
            'cycles_collected' => $cycles,
            'memory_freed' => round($freed / 1024 / 1024, 2) . ' MB',
            'memory_before' => round($before / 1024 / 1024, 2) . ' MB',
            'memory_after' => round($after / 1024 / 1024, 2) . ' MB',
        ]);
    }

    /**
     * DB Connection Pool Stats
     */
    public function actionDbPool()
    {
        $dbStats = null;
        $redisStats = null;
        $hasDb = false;

        // Check if this coroutine has a DB instance
        if (Yii::$app instanceof \Dacheng\Yii2\Swoole\Coroutine\CoroutineApplication) {
            $hasDb = Yii::$app->has('db', true);  // Check if instance exists
        }

        if (Yii::$app->has('db')) {
            try {
                $db = Yii::$app->get('db', false);
                if ($db && method_exists($db, 'getPool')) {
                    $pool = $db->getPool();
                    $dbStats = $pool->getStats();
                }
            } catch (\Throwable $e) {
                $dbStats = ['error' => $e->getMessage()];
            }
        }

        if (Yii::$app->has('redis')) {
            try {
                $redis = Yii::$app->get('redis', false);
                if ($redis && method_exists($redis, 'getPool')) {
                    $pool = $redis->getPool();
                    $redisStats = $pool->getStats();
                }
            } catch (\Throwable $e) {
                $redisStats = ['error' => $e->getMessage()];
            }
        }

        return $this->asJson([
            'db_pool' => $dbStats,
            'redis_pool' => $redisStats,
            'this_coroutine_has_db_instance' => $hasDb,
            'coroutine_id' => \Swoole\Coroutine::getCid(),
        ]);
    }

    /**
     * Test endpoint - explicitly close DB after use
     */
    public function actionTestDbClose()
    {
        // Use the DB
        $row = Yii::$app->db->createCommand('SELECT 1 as num')->queryOne();

        // Explicitly close it
        if (method_exists(Yii::$app->db, 'close')) {
            Yii::$app->db->close();
        }

        return $this->asJson([
            'result' => $row,
            'db_closed' => true,
        ]);
    }

    /**
     * Test action with parameters
     */
    public function actionTest()
    {
        $request = Yii::$app->request;

        return $this->asJson([
            'method' => $request->method,
            'path' => $request->pathInfo,
            'query' => $request->queryParams,
            'post' => $request->bodyParams,
            'headers' => $request->headers->toArray(),
            'cookies' => array_keys($request->cookies->toArray()),
        ]);
    }

    /**
     * Test custom ArrayHelper (issue #47)
     */
    public function actionTestArrayHelper()
    {
        $testData = ['foo' => 'bar', 'nested' => ['key' => 'value']];

        return $this->asJson([
            'custom_test_method' => \yii\helpers\ArrayHelper::test($testData),
            'standard_getValue' => \yii\helpers\ArrayHelper::getValue($testData, 'foo'),
            'success' => true,
        ]);
    }

    /**
     * Test cookie setting
     */
    public function actionSetCookie()
    {
        $response = Yii::$app->response;
        $response->cookies->add(new \yii\web\Cookie([
            'name' => 'test_cookie',
            'value' => 'cookie_value_' . time(),
            'expire' => time() + 3600,
        ]));

        return $this->asJson([
            'message' => 'Cookie set successfully',
        ]);
    }

    /**
     * Test cookie reading
     */
    public function actionGetCookie()
    {
        $request = Yii::$app->request;
        $cookieValue = $request->cookies->getValue('test_cookie', 'not set');

        return $this->asJson([
            'test_cookie' => $cookieValue,
            'all_cookies' => $request->cookies->toArray(),
        ]);
    }

    /**
     * Test coroutine with sleep
     */
    public function actionSleep()
    {
        $seconds = (int)Yii::$app->request->get('seconds', 1);
        $seconds = min($seconds, 5); // Max 5 seconds

        \Swoole\Coroutine::sleep($seconds);

        return $this->asJson([
            'message' => "Slept for {$seconds} seconds using coroutine",
            'timestamp' => time(),
        ]);
    }

    public function actionError()
    {
        $exception = Yii::$app->errorHandler->exception;

        if ($exception === null) {
            $exception = new NotFoundHttpException('Page not found.');
        }

        $statusCode = $exception instanceof HttpException ? $exception->statusCode : 500;
        $response = Yii::$app->response;
        $response->setStatusCode($statusCode);
        $statusText = $response->statusText ?: (Response::$httpStatuses[$statusCode] ?? 'Error');

        $data = [
            'name' => $this->resolveExceptionName($exception, $statusText),
            'message' => $exception->getMessage() ?: $statusText,
            'status' => $statusCode,
        ];

        if (YII_DEBUG) {
            $data['type'] = get_class($exception);
            $data['file'] = $exception->getFile();
            $data['line'] = $exception->getLine();
            $data['trace'] = explode(PHP_EOL, $exception->getTraceAsString());
        }

        return $this->asJson($data);
    }

    private function resolveExceptionName(Throwable $exception, string $statusText): string
    {
        if ($exception instanceof HttpException) {
            return $statusText;
        }

        if ($exception instanceof YiiException) {
            return $exception->getName();
        }

        return 'Error';
    }
}
