<?php

namespace app\controllers;

use Yii;
use yii\web\Controller;
use yii\web\Response;
use yii2swoole\coroutine\Concurrent;
use yii2swoole\redis\Connection as RedisConnection;
use Swoole\Coroutine;

class RedisController extends Controller
{
    public function behaviors()
    {
        return [];
    }

    public function beforeAction($action)
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        return parent::beforeAction($action);
    }

    /**
     * Set a key-value pair in Redis
     * Usage: GET /redis/set?key=mykey&value=myvalue
     */
    public function actionSet()
    {
        $key = Yii::$app->request->get('key', 'test:key');
        $value = Yii::$app->request->get('value', 'value_' . uniqid());

        $redis = $this->getRedis();
        $redis->set($key, $value);

        return [
            'success' => true,
            'action' => 'set',
            'key' => $key,
            'value' => $value,
            'coroutine_id' => Coroutine::getCid(),
        ];
    }

    /**
     * Get a value from Redis
     * Usage: GET /redis/get?key=mykey
     */
    public function actionGet()
    {
        $key = Yii::$app->request->get('key', 'test:key');

        $redis = $this->getRedis();
        $value = $redis->get($key);

        return [
            'success' => true,
            'action' => 'get',
            'key' => $key,
            'value' => $value,
            'coroutine_id' => Coroutine::getCid(),
        ];
    }

    /**
     * Get pool statistics
     * Usage: GET /redis/stats
     */
    public function actionStats()
    {
        $redis = $this->getRedis();

        $stats = null;
        if ($redis instanceof RedisConnection) {
            $stats = $redis->getPoolStats();
        }

        return [
            'success' => true,
            'action' => 'stats',
            'pool_stats' => $stats,
            'is_coroutine' => $redis instanceof RedisConnection,
            'coroutine_id' => Coroutine::getCid(),
        ];
    }

    /**
     * Test concurrent operations
     * Usage: GET /redis/concurrent?count=10
     */
    public function actionConcurrent()
    {
        $count = (int) Yii::$app->request->get('count', 10);
        $count = max(1, min($count, 100)); // Limit to 100

        $redis = $this->getRedis();
        $start = microtime(true);

        $tasks = [];
        for ($i = 0; $i < $count; $i++) {
            $tasks[$i] = static function () use ($i): array {
                $redis = Yii::$app->redis;
                $key = 'test:concurrent:' . uniqid('', true) . ":$i";
                $value = "value_$i";

                $redis->set($key, $value);
                $retrieved = $redis->get($key);
                $redis->del($key);

                return [
                    'success' => $retrieved === $value,
                    'coroutine_id' => Coroutine::getCid(),
                ];
            };
        }

        $results = Concurrent::run($tasks, concurrency: 20);

        $elapsed = max(0.0001, microtime(true) - $start);
        $successful = count(array_filter($results, static fn (array $r): bool => $r['success']));

        return [
            'success' => true,
            'action' => 'concurrent',
            'total_operations' => $count,
            'successful' => $successful,
            'elapsed_seconds' => round($elapsed, 4),
            'operations_per_second' => round($count / $elapsed, 2),
            'pool_stats' => $redis->getPoolStats(),
        ];
    }

    /**
     * Benchmark Redis operations
     * Usage: GET /redis/benchmark?operations=100
     */
    public function actionBenchmark()
    {
        $operations = (int) Yii::$app->request->get('operations', 100);
        $operations = min($operations, 1000); // Limit to 1000

        $redis = $this->getRedis();
        
        // Measure SET operations
        $start = microtime(true);
        for ($i = 0; $i < $operations; $i++) {
            $redis->set("bench:$i", "value_$i");
        }
        $setTime = microtime(true) - $start;

        // Measure GET operations
        $start = microtime(true);
        for ($i = 0; $i < $operations; $i++) {
            $redis->get("bench:$i");
        }
        $getTime = microtime(true) - $start;

        // Cleanup
        for ($i = 0; $i < $operations; $i++) {
            $redis->del("bench:$i");
        }

        return [
            'success' => true,
            'action' => 'benchmark',
            'operations' => $operations,
            'set_time_seconds' => round($setTime, 4),
            'set_ops_per_second' => round($operations / $setTime, 2),
            'get_time_seconds' => round($getTime, 4),
            'get_ops_per_second' => round($operations / $getTime, 2),
            'pool_stats' => $redis->getPoolStats(),
        ];
    }

    private function getRedis(): RedisConnection
    {
        $redis = Yii::$app->redis;
        if (!$redis instanceof RedisConnection) {
            throw new \RuntimeException('Redis component must be configured to use Redis\\Connection.');
        }

        return $redis;
    }
}
