<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Cache;

use Dacheng\Yii2\Swoole\Coroutine\ResettableInterface;
use Dacheng\Yii2\Swoole\Redis\CoroutineRedisConnection;
use Swoole\Coroutine;
use yii\di\Instance;
use yii\redis\Exception;
use yii\redis\Cache as BaseCache;

/**
 * CoroutineRedisCache implements a coroutine-safe cache backend based on Redis.
 * 
 * This cache component extends yii2-redis Cache and uses CoroutineRedisConnection
 * to provide efficient connection pooling in Swoole coroutine environments.
 * 
 * Configuration example:
 * 
 * ```php
 * [
 *     'components' => [
 *         'cache' => [
 *             'class' => 'Dacheng\Yii2\Swoole\Cache\CoroutineRedisCache',
 *             'redis' => [
 *                 'class' => 'Dacheng\Yii2\Swoole\Redis\CoroutineRedisConnection',
 *                 'hostname' => 'localhost',
 *                 'port' => 6379,
 *                 'database' => 0,
 *                 'poolMaxActive' => 32,
 *                 'poolWaitTimeout' => 3.0,
 *             ]
 *         ],
 *     ],
 * ]
 * ```
 * 
 * Or if you have configured the coroutine redis connection as an application component:
 * 
 * ```php
 * [
 *     'components' => [
 *         'cache' => [
 *             'class' => 'Dacheng\Yii2\Swoole\Cache\CoroutineRedisCache',
 *             'redis' => 'redis', // id of the coroutine connection component
 *         ],
 *     ],
 * ]
 * ```
 * 
 * For replica support in coroutine environments:
 * 
 * ```php
 * [
 *     'components' => [
 *         'cache' => [
 *             'class' => 'Dacheng\Yii2\Swoole\Cache\CoroutineRedisCache',
 *             'enableReplicas' => true,
 *             'replicas' => [
 *                 [
 *                     'class' => 'Dacheng\Yii2\Swoole\Redis\CoroutineRedisConnection',
 *                     'hostname' => 'redis-replica-1.local',
 *                 ],
 *                 [
 *                     'class' => 'Dacheng\Yii2\Swoole\Redis\CoroutineRedisConnection',
 *                     'hostname' => 'redis-replica-2.local',
 *                 ],
 *             ],
 *         ],
 *     ],
 * ]
 * ```
 * 
 * All cache operations are performed through the coroutine connection pool,
 * ensuring efficient resource utilization in high-concurrency scenarios.
 */
class CoroutineRedisCache extends BaseCache implements ResettableInterface
{
    private const CONTEXT_STATE_KEY = '__yiiSwooleRedisCacheState';

    /**
     * @var CoroutineRedisConnection|string|array the coroutine Redis connection
     */
    public $redis = 'redis';

    /**
     * Runtime state used outside Swoole coroutine contexts.
     *
     * @var array<string, mixed>
     */
    private array $runtimeState = [
        'replica' => null,
        'isCluster' => null,
        'hashTagAvailable' => false,
    ];

    /**
     * Initializes the cache component.
     * Ensures the redis connection is a valid CoroutineRedisConnection instance.
     */
    public function init()
    {
        parent::init();
        $this->redis = Instance::ensure($this->redis, CoroutineRedisConnection::class);
    }

    /**
     * Returns the current replica connection or the main connection.
     * Ensures replicas are also coroutine connections.
     * 
     * @return CoroutineRedisConnection
     */
    protected function getReplica()
    {
        if ($this->enableReplicas === false) {
            return $this->redis;
        }

        $replica = $this->getRuntimeValue('replica');
        if ($replica instanceof CoroutineRedisConnection) {
            return $replica;
        }

        if (empty($this->replicas)) {
            $this->setRuntimeValue('replica', $this->redis);

            return $this->redis;
        }

        $replicas = $this->replicas;
        shuffle($replicas);
        $replica = Instance::ensure(array_shift($replicas), CoroutineRedisConnection::class);
        $this->setRuntimeValue('replica', $replica);

        return $replica;
    }

    public function getIsCluster()
    {
        if ($this->forceClusterMode !== null) {
            return $this->forceClusterMode;
        }

        $isCluster = $this->getRuntimeValue('isCluster');
        if ($isCluster === null) {
            $isCluster = false;
            try {
                $this->redis->executeCommand('CLUSTER INFO');
                $isCluster = true;
            } catch (Exception) {
                // Redis without cluster support reports an error for CLUSTER INFO.
            }
            $this->setRuntimeValue('isCluster', $isCluster);
        }

        return $isCluster;
    }

    public function buildKey($key)
    {
        if (
            is_string($key)
            && $this->isCluster
            && preg_match('/^(.*)({.+})(.*)$/', $key, $matches) === 1
        ) {
            $this->setRuntimeValue('hashTagAvailable', true);

            return parent::buildKey($matches[1] . $matches[3]) . $matches[2];
        }

        return parent::buildKey($key);
    }

    public static function clearCoroutineRuntimeState(): void
    {
        if (Coroutine::getCid() < 0) {
            return;
        }

        $context = Coroutine::getContext();
        unset($context[self::CONTEXT_STATE_KEY]);
    }

    public function reset(): void
    {
        if ($this->redis instanceof CoroutineRedisConnection) {
            $this->redis->close();
        }

        $replica = $this->getRuntimeValue('replica');
        if ($replica instanceof CoroutineRedisConnection && $replica !== $this->redis) {
            $replica->close();
        }

        $this->setRuntimeState($this->defaultRuntimeState());
    }

    protected function getValues($keys)
    {
        if ($this->isCluster && !$this->getRuntimeValue('hashTagAvailable')) {
            return parent::getValues($keys);
        }

        $response = $this->getReplica()->executeCommand('MGET', $keys);
        $result = [];
        $i = 0;
        foreach ($keys as $key) {
            $result[$key] = $response[$i++];
        }

        $this->setRuntimeValue('hashTagAvailable', false);

        return $result;
    }

    protected function setValues($data, $expire)
    {
        if ($this->isCluster && !$this->getRuntimeValue('hashTagAvailable')) {
            return parent::setValues($data, $expire);
        }

        $args = [];
        foreach ($data as $key => $value) {
            $args[] = $key;
            $args[] = $value;
        }

        $failedKeys = [];
        if ($expire == 0) {
            $this->redis->executeCommand('MSET', $args);
        } else {
            $expire = (int) ($expire * 1000);
            $this->redis->executeCommand('MULTI');
            $this->redis->executeCommand('MSET', $args);
            $index = [];
            foreach ($data as $key => $value) {
                $this->redis->executeCommand('PEXPIRE', [$key, $expire]);
                $index[] = $key;
            }
            $result = $this->redis->executeCommand('EXEC');
            array_shift($result);
            foreach ($result as $i => $r) {
                if ($r != 1) {
                    $failedKeys[] = $index[$i];
                }
            }
        }

        $this->setRuntimeValue('hashTagAvailable', false);

        return $failedKeys;
    }

    /**
     * Returns the Redis connection pool statistics.
     * 
     * @return array{created: int, idle: int, in_use: int, waiters: int, capacity: int, closed: bool}
     */
    public function getPoolStats(): array
    {
        return $this->redis->getPoolStats();
    }

    private function getRuntimeValue(string $name)
    {
        $state = $this->getRuntimeState();

        return $state[$name] ?? null;
    }

    private function setRuntimeValue(string $name, $value): void
    {
        $state = $this->getRuntimeState();
        $state[$name] = $value;
        $this->setRuntimeState($state);
    }

    /**
     * @return array<string, mixed>
     */
    private function getRuntimeState(): array
    {
        if (Coroutine::getCid() < 0) {
            return $this->runtimeState;
        }

        $context = Coroutine::getContext();
        $key = (string) spl_object_id($this);
        $states = $context[self::CONTEXT_STATE_KEY] ?? [];

        return $states[$key] ?? $this->defaultRuntimeState();
    }

    /**
     * @param array<string, mixed> $state
     */
    private function setRuntimeState(array $state): void
    {
        if (Coroutine::getCid() < 0) {
            $this->runtimeState = $state;

            return;
        }

        $context = Coroutine::getContext();
        $key = (string) spl_object_id($this);
        $states = $context[self::CONTEXT_STATE_KEY] ?? [];
        $states[$key] = $state;
        $context[self::CONTEXT_STATE_KEY] = $states;
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultRuntimeState(): array
    {
        return [
            'replica' => null,
            'isCluster' => null,
            'hashTagAvailable' => false,
        ];
    }
}
