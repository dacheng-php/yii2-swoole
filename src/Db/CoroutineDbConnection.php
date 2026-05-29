<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Db;

use Dacheng\Yii2\Swoole\Pools\DbConnectionPool;
use Dacheng\Yii2\Swoole\Pools\PoolManager;
use PDO;
use Swoole\Coroutine;
use Yii;
use yii\caching\CacheInterface;
use yii\db\Connection;
use yii\db\Transaction;

/**
 * CoroutineDbConnection provides connection pooling for database access in Swoole coroutine context.
 *
 * This class extends Yii2's db Connection and manages a pool of PDO instances
 * that are shared across coroutines. Each coroutine acquires a PDO connection
 * from the pool when needed and returns it after use.
 *
 * Key features:
 * - Automatic connection pooling when in coroutine context
 * - Graceful fallback to non-pooled mode when not in coroutine
 * - Transparent integration with existing Yii2 database code
 * - Configurable pool size and timeout
 *
 * @property int $poolMaxActive Maximum number of connections in pool (default: 20)
 * @property float $poolWaitTimeout Maximum time to wait for connection (default: 3.0)
 * @property bool $enableCoroutinePooling Whether pooling is enabled (default: true)
 */
class CoroutineDbConnection extends Connection
{
    use PoolManager;

    private const CONTEXT_STATE_KEY = '__yiiSwooleDbConnectionState';

    /**
     * Maximum number of connections in the pool.
     */
    public int $poolMaxActive = 20;

    /**
     * Maximum time to wait for an available connection (in seconds).
     */
    public float $poolWaitTimeout = 3.0;

    /**
     * Whether to enable connection pooling in coroutine context.
     */
    public bool $enableCoroutinePooling = true;

    /**
     * @var bool Whether to validate connections on acquire from pool.
     */
    public bool $enableHealthCheck = false;

    /**
     * Runtime state used outside Swoole coroutine contexts.
     *
     * @var array<string, mixed>
     */
    private array $runtimeState = [
        'pdo' => null,
        'released' => false,
        'transaction' => null,
        'queryCacheInfo' => [],
    ];

    public function init(): void
    {
        parent::init();
        unset($this->pdo);
    }

    public function __get($name)
    {
        if ($name === 'pdo') {
            return $this->getRuntimeValue('pdo');
        }

        return parent::__get($name);
    }

    public function __set($name, $value): void
    {
        if ($name === 'pdo') {
            $this->setRuntimeValue('pdo', $value);

            return;
        }

        parent::__set($name, $value);
    }

    public function __isset($name): bool
    {
        if ($name === 'pdo') {
            return $this->getRuntimeValue('pdo') !== null;
        }

        return parent::__isset($name);
    }

    public function __unset($name): void
    {
        if ($name === 'pdo') {
            $this->setRuntimeValue('pdo', null);

            return;
        }

        parent::__unset($name);
    }

    public function getIsActive()
    {
        return $this->getRuntimeValue('pdo') !== null;
    }

    public static function clearCoroutineRuntimeState(): void
    {
        if (Coroutine::getCid() < 0) {
            return;
        }

        $context = Coroutine::getContext();
        unset($context[self::CONTEXT_STATE_KEY]);
    }

    public function open(): void
    {
        if ($this->getRuntimeValue('pdo') !== null) {
            return;
        }

        if (!$this->enableCoroutinePooling || Coroutine::getCid() < 0) {
            parent::open();

            return;
        }

        $this->setRuntimeValue('pdo', $this->ensurePool()->acquire());
        $this->setRuntimeValue('released', false);

        $this->trigger(self::EVENT_AFTER_OPEN);
    }

    public function close(): void
    {
        if (!$this->enableCoroutinePooling || Coroutine::getCid() < 0) {
            parent::close();

            return;
        }

        $pdo = $this->getRuntimeValue('pdo');
        if ($pdo === null) {
            return;
        }

        if (!$this->getRuntimeValue('released')) {
            $this->setRuntimeValue('released', true);
            $discard = false;

            $transaction = $this->getTransaction();
            if ($transaction !== null && $transaction->getIsActive()) {
                try {
                    $transaction->rollBack();
                    \Yii::warning('Active DB transaction was rolled back before returning the connection to the pool.', __CLASS__);
                } catch (\Throwable $e) {
                    $discard = true;
                    \Yii::error('Error rolling back active DB transaction: ' . $e->getMessage(), __CLASS__);
                }
            }

            $this->setRuntimeValue('transaction', null);
            $this->setRuntimeValue('queryCacheInfo', []);
            $this->setRuntimeValue('pdo', null);

            try {
                if ($discard) {
                    $this->ensurePool()->discard($pdo);
                } else {
                    $this->ensurePool()->release($pdo);
                }
            } catch (\Throwable $e) {
                \Yii::error('Error releasing connection to pool: ' . $e->getMessage() . "\n" . $e->getTraceAsString(), __CLASS__);
            }
        } else {
            $this->setRuntimeValue('pdo', null);
        }
    }

    public function reset(): void
    {
        if ($this->getRuntimeValue('pdo') !== null && !$this->getRuntimeValue('released')) {
            $this->close();
        }

        $this->setRuntimeValue('released', false);
        $this->setRuntimeValue('transaction', null);
        $this->setRuntimeValue('queryCacheInfo', []);
        $this->setRuntimeValue('pdo', null);
    }

    public function getTransaction()
    {
        $transaction = $this->getRuntimeValue('transaction');

        return $transaction instanceof Transaction && $transaction->getIsActive() ? $transaction : null;
    }

    public function beginTransaction($isolationLevel = null)
    {
        $this->open();

        if (($transaction = $this->getTransaction()) === null) {
            $transaction = new Transaction(['db' => $this]);
            $this->setRuntimeValue('transaction', $transaction);
        }

        $transaction->begin($isolationLevel);

        return $transaction;
    }

    public function transaction(callable $callback, $isolationLevel = null)
    {
        $transaction = $this->beginTransaction($isolationLevel);
        $level = $transaction->level;

        try {
            $result = call_user_func($callback, $this);
            if ($transaction->isActive && $transaction->level === $level) {
                $transaction->commit();
            }
        } catch (\Exception $e) {
            $this->rollbackTransactionOnLevel($transaction, $level);
            throw $e;
        } catch (\Throwable $e) {
            $this->rollbackTransactionOnLevel($transaction, $level);
            throw $e;
        }

        return $result;
    }

    public function cache(callable $callable, $duration = null, $dependency = null)
    {
        $stack = $this->getRuntimeValue('queryCacheInfo');
        $stack[] = [$duration === null ? $this->queryCacheDuration : $duration, $dependency];
        $this->setRuntimeValue('queryCacheInfo', $stack);

        try {
            return call_user_func($callable, $this);
        } finally {
            $this->popQueryCacheInfo();
        }
    }

    public function noCache(callable $callable)
    {
        $stack = $this->getRuntimeValue('queryCacheInfo');
        $stack[] = false;
        $this->setRuntimeValue('queryCacheInfo', $stack);

        try {
            return call_user_func($callable, $this);
        } finally {
            $this->popQueryCacheInfo();
        }
    }

    public function getQueryCacheInfo($duration, $dependency)
    {
        if (!$this->enableQueryCache) {
            return null;
        }

        $stack = $this->getRuntimeValue('queryCacheInfo');
        $info = end($stack);
        if (is_array($info)) {
            if ($duration === null) {
                $duration = $info[0];
            }
            if ($dependency === null) {
                $dependency = $info[1];
            }
        }

        if ($duration === 0 || $duration > 0) {
            if (is_string($this->queryCache) && Yii::$app) {
                $cache = Yii::$app->get($this->queryCache, false);
            } else {
                $cache = $this->queryCache;
            }
            if ($cache instanceof CacheInterface) {
                return [$cache, $duration, $dependency];
            }
        }

        return null;
    }

    /**
     * Returns the connection pool instance.
     *
     * @return DbConnectionPool
     */
    public function getPool(): DbConnectionPool
    {
        return $this->ensurePool();
    }

    protected function buildPoolKey(): string
    {
        // Use raw string as key - no need for hashing
        // Components are separated by '|' to prevent collisions
        return implode('|', [
            static::class,
            (string) $this->dsn,
            (string) $this->username,
            (string) $this->charset,
        ]);
    }

    protected function createPool(): DbConnectionPool
    {
        return new DbConnectionPool(
            fn (): PDO => $this->createPdoForPool(),
            $this->poolMaxActive,
            $this->poolWaitTimeout,
            $this->enableHealthCheck
        );
    }

    protected static function getPoolClass(): string
    {
        return DbConnectionPool::class;
    }

    /**
     * Creates a PDO instance for the connection pool.
     *
     * This method creates and initializes a PDO connection without modifying
     * $this->pdo, ensuring coroutine safety during pool initialization.
     *
     * @return PDO The initialized PDO connection
     */
    private function createPdoForPool(): PDO
    {
        $pdo = parent::createPdoInstance();
        $this->initPdoConnection($pdo);

        return $pdo;
    }

    /**
     * Initializes a PDO connection with configured attributes and emulation settings.
     *
     * This method replicates the essential initialization logic from parent::initConnection()
     * without relying on $this->pdo, making it safe for pool creation.
     *
     * @param PDO $pdo The PDO instance to initialize
     */
    private function initPdoConnection(PDO $pdo): void
    {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Set charset if specified (MySQL specific)
        if ($this->charset !== null && stripos((string)$this->dsn, 'charset=') === false) {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'mysql') {
                $pdo->exec('SET NAMES ' . $pdo->quote($this->charset));
            }
        }

        // Configure emulation based on PDO driver
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, $this->emulatePrepare ?? false);
        }

        // Trigger after open event on the connection instance
        $this->trigger(self::EVENT_AFTER_OPEN);
    }

    private function rollbackTransactionOnLevel(Transaction $transaction, int $level): void
    {
        if ($transaction->isActive && $transaction->level === $level) {
            try {
                $transaction->rollBack();
            } catch (\Exception $e) {
                \Yii::error($e, __METHOD__);
            }
        }
    }

    private function popQueryCacheInfo(): void
    {
        $stack = $this->getRuntimeValue('queryCacheInfo');
        array_pop($stack);
        $this->setRuntimeValue('queryCacheInfo', $stack);
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
            'pdo' => null,
            'released' => false,
            'transaction' => null,
            'queryCacheInfo' => [],
        ];
    }
}
