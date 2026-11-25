<?php

namespace app\controllers;

use Yii;
use yii\web\Controller;

/**
 * Health check controller
 * Provides endpoints for monitoring application and service health
 */
class HealthController extends Controller
{
    /**
     * Health check endpoint
     * Returns the health status of the application and its dependencies
     * 
     * @return array Health status information
     */
    public function actionIndex()
    {
        $startTime = microtime(true);
        $status = 'healthy';
        $checks = [];
        
        // Check PHP version
        $checks['php'] = [
            'status' => 'ok',
            'version' => PHP_VERSION,
        ];
        
        // Check Swoole
        $checks['swoole'] = [
            'status' => 'ok',
            'version' => swoole_version(),
            'coroutine_id' => \Swoole\Coroutine::getCid(),
        ];
        
        // Check database connection
        if (Yii::$app->has('db')) {
            try {
                $dbStartTime = microtime(true);
                $result = Yii::$app->db->createCommand('SELECT 1')->queryScalar();
                $dbTime = round((microtime(true) - $dbStartTime) * 1000, 2);
                
                $checks['database'] = [
                    'status' => (int) $result === 1 ? 'ok' : 'error',
                    'response_time_ms' => $dbTime,
                ];
                
                // Check for connection pool stats if available
                if (method_exists(Yii::$app->db, 'getPool')) {
                    $poolStats = Yii::$app->db->getPool()->getStats();
                    $checks['database']['pool'] = $poolStats;
                }
            } catch (\Throwable $e) {
                $status = 'unhealthy';
                $checks['database'] = [
                    'status' => 'error',
                    'error' => $e->getMessage(),
                ];
            }
        }
        
        // Check Redis connection
        if (Yii::$app->has('redis')) {
            try {
                $redisStartTime = microtime(true);
                $result = Yii::$app->redis->executeCommand('PING');
                $redisTime = round((microtime(true) - $redisStartTime) * 1000, 2);
                
                $checks['redis'] = [
                    'status' => ($result === true || $result === 'PONG') ? 'ok' : 'error',
                    'response_time_ms' => $redisTime,
                ];
                
                // Check for connection pool stats if available
                if (method_exists(Yii::$app->redis, 'getPool')) {
                    $poolStats = Yii::$app->redis->getPool()->getStats();
                    $checks['redis']['pool'] = $poolStats;
                }
            } catch (\Throwable $e) {
                $status = 'unhealthy';
                $checks['redis'] = [
                    'status' => 'error',
                    'error' => $e->getMessage(),
                ];
            }
        }
        
        // Check queue if configured
        if (Yii::$app->has('queue')) {
            try {
                $checks['queue'] = [
                    'status' => 'ok',
                    'class' => get_class(Yii::$app->queue),
                ];
            } catch (\Throwable $e) {
                $checks['queue'] = [
                    'status' => 'error',
                    'error' => $e->getMessage(),
                ];
            }
        }
        
        // Memory usage
        $checks['memory'] = [
            'status' => 'ok',
            'usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
        ];
        
        // Coroutine stats
        $coroutineStats = \Swoole\Coroutine::stats();
        $checks['coroutines'] = [
            'status' => 'ok',
            'count' => $coroutineStats['coroutine_num'] ?? 0,
            'peak' => $coroutineStats['coroutine_peak_num'] ?? 0,
        ];
        
        $totalTime = round((microtime(true) - $startTime) * 1000, 2);
        
        // Set appropriate HTTP status code
        $httpStatus = $status === 'healthy' ? 200 : 503;
        Yii::$app->response->setStatusCode($httpStatus);
        
        return $this->asJson([
            'status' => $status,
            'timestamp' => time(),
            'response_time_ms' => $totalTime,
            'checks' => $checks,
        ]);
    }
    
    /**
     * Liveness probe endpoint
     * Simple endpoint to check if the application is running
     * 
     * @return array Liveness status
     */
    public function actionLive()
    {
        return $this->asJson([
            'status' => 'alive',
            'timestamp' => time(),
        ]);
    }
    
    /**
     * Readiness probe endpoint
     * Checks if the application is ready to serve traffic
     * 
     * @return array Readiness status
     */
    public function actionReady()
    {
        $ready = true;
        $checks = [];
        
        // Check critical services only
        if (Yii::$app->has('db')) {
            try {
                Yii::$app->db->createCommand('SELECT 1')->queryScalar();
                $checks['database'] = 'ready';
            } catch (\Throwable $e) {
                $ready = false;
                $checks['database'] = 'not ready';
            }
        }
        
        if (Yii::$app->has('redis')) {
            try {
                Yii::$app->redis->executeCommand('PING');
                $checks['redis'] = 'ready';
            } catch (\Throwable $e) {
                $ready = false;
                $checks['redis'] = 'not ready';
            }
        }
        
        $httpStatus = $ready ? 200 : 503;
        Yii::$app->response->setStatusCode($httpStatus);
        
        return $this->asJson([
            'status' => $ready ? 'ready' : 'not ready',
            'timestamp' => time(),
            'checks' => $checks,
        ]);
    }

    /**
     * Runtime telemetry endpoint backed by RuntimeStats::snapshot().
     */
    public function actionStats()
    {
        return $this->asJson(\yii2swoole\server\RuntimeStats::snapshot());
    }
}

