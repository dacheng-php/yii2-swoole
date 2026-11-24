<?php

namespace app\controllers;

use Dacheng\Yii2\Swoole\Coroutine\Concurrent;
use Yii;
use yii\web\Controller;
use yii\web\Response;

/**
 * LogController demonstrates async logging capabilities
 */
class LogController extends Controller
{
    /**
     * @inheritdoc
     */
    public function beforeAction($action)
    {
        // Disable CSRF validation for API testing
        $this->enableCsrfValidation = false;
        return parent::beforeAction($action);
    }

    /**
     * Tests async logging with different levels
     * 
     * GET /log/test?level=info&message=Test
     * 
     * @return array
     */
    public function actionTest()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $level = Yii::$app->request->get('level', 'info');
        $message = Yii::$app->request->get('message', 'Test log message');
        $count = (int)Yii::$app->request->get('count', 1);

        $startTime = microtime(true);

        for ($i = 0; $i < $count; $i++) {
            switch ($level) {
                case 'error':
                    Yii::error($message . " #{$i}", __METHOD__);
                    break;
                case 'warning':
                    Yii::warning($message . " #{$i}", __METHOD__);
                    break;
                case 'info':
                    Yii::info($message . " #{$i}", __METHOD__);
                    break;
                case 'trace':
                    Yii::trace($message . " #{$i}", __METHOD__);
                    break;
                default:
                    Yii::info($message . " #{$i}", __METHOD__);
            }
        }

        $duration = microtime(true) - $startTime;

        return [
            'success' => true,
            'level' => $level,
            'count' => $count,
            'duration_ms' => round($duration * 1000, 2),
            'messages_per_second' => $count > 0 ? round($count / $duration, 2) : 0,
            'message' => "Logged {$count} {$level} message(s) in {$duration}s",
        ];
    }

    /**
     * Stress test for async logging
     * 
     * GET /log/stress?count=10000
     * 
     * @return array
     */
    public function actionStress()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $count = max(1, (int) Yii::$app->request->get('count', 10000));
        $concurrent = max(1, min(100, (int) Yii::$app->request->get('concurrent', 10)));

        $startTime = microtime(true);

        // Run concurrent logging tasks with structured concurrency
        $baseBatch = intdiv($count, $concurrent);
        $remainder = $count % $concurrent;
        $tasks = [];
        for ($i = 0; $i < $concurrent; $i++) {
            $batchSize = $baseBatch + ($i < $remainder ? 1 : 0);
            $tasks[$i] = static function () use ($i, $batchSize): int {
                for ($j = 0; $j < $batchSize; $j++) {
                    Yii::info("Coroutine {$i}: Stress test message #{$j}", __METHOD__);
                }

                return $batchSize;
            };
        }

        $counters = Concurrent::run($tasks, concurrency: $concurrent);

        $duration = max(0.0001, microtime(true) - $startTime);
        $totalLogged = array_sum($counters);

        return [
            'success' => true,
            'total_messages' => $totalLogged,
            'concurrent_workers' => $concurrent,
            'duration_ms' => round($duration * 1000, 2),
            'messages_per_second' => $totalLogged > 0 ? round($totalLogged / $duration, 2) : 0,
            'message' => "Stress test completed: {$totalLogged} messages in {$duration}s",
        ];
    }

    /**
     * Tests mixed log levels
     * 
     * GET /log/mixed?count=100
     * 
     * @return array
     */
    public function actionMixed()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $count = (int)Yii::$app->request->get('count', 100);
        $startTime = microtime(true);

        $levels = ['error', 'warning', 'info', 'trace'];
        $counters = ['error' => 0, 'warning' => 0, 'info' => 0, 'trace' => 0];

        for ($i = 0; $i < $count; $i++) {
            $level = $levels[$i % count($levels)];
            $counters[$level]++;

            switch ($level) {
                case 'error':
                    Yii::error("Error message #{$i}", __METHOD__);
                    break;
                case 'warning':
                    Yii::warning("Warning message #{$i}", __METHOD__);
                    break;
                case 'info':
                    Yii::info("Info message #{$i}", __METHOD__);
                    break;
                case 'trace':
                    Yii::trace("Trace message #{$i}", __METHOD__);
                    break;
            }
        }

        $duration = microtime(true) - $startTime;

        return [
            'success' => true,
            'total_messages' => $count,
            'by_level' => $counters,
            'duration_ms' => round($duration * 1000, 2),
            'messages_per_second' => $count > 0 ? round($count / $duration, 2) : 0,
            'message' => "Mixed level logging completed in {$duration}s",
        ];
    }

    /**
     * Returns logging statistics
     * 
     * GET /log/stats
     * 
     * @return array
     */
    public function actionStats()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $logTarget = null;
        $targets = Yii::$app->log->targets ?? [];
        
        foreach ($targets as $target) {
            if ($target instanceof \Dacheng\Yii2\Swoole\Log\FileTarget) {
                $logTarget = $target;
                break;
            }
        }

        if ($logTarget === null) {
            return [
                'success' => false,
                'message' => 'Log\\FileTarget not found in log configuration',
            ];
        }

        $logFile = $logTarget->logFile;
        $fileExists = file_exists($logFile);
        $fileSize = $fileExists ? filesize($logFile) : 0;
        $lineCount = 0;

        if ($fileExists && $fileSize > 0) {
            $lineCount = count(file($logFile));
        }

        $result = [
            'success' => true,
            'log_file' => $logFile,
            'file_exists' => $fileExists,
            'file_size_bytes' => $fileSize,
            'file_size_kb' => round($fileSize / 1024, 2),
            'line_count' => $lineCount,
            'max_file_size_kb' => $logTarget->maxFileSize,
            'max_log_files' => $logTarget->maxLogFiles,
            'rotation_enabled' => $logTarget->enableRotation,
            'worker_initialized' => $logTarget->getWorker() !== null,
            'worker_stats' => $logTarget->getWorker()?->getStats(),
        ];

        return $result;
    }
}
