<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Pools;

use Swoole\Coroutine\Channel;

/**
 * PoolManager trait provides shared pool state management.
 *
 * This trait eliminates duplication between connection classes that need
 * to manage shared connection pools across coroutines.
 *
 * Each connection class using this trait has its own isolated static pool storage,
 * preventing cross-type pool contamination.
 *
 * @template TPool of AbstractConnectionPool The pool type
 *
 * Classes using this trait must implement:
 * - buildPoolKey(): string - Generate unique key for pool
 * - createPool(): AbstractConnectionPool - Create pool instance
 * - getPoolClass(): string - Return pool class name
 */
trait PoolManager
{
    /**
     * @var array<AbstractConnectionPool> Shared pools indexed by connection key
     *
     * Each class using this trait has its own static storage,
     * ensuring DB and Redis pools are completely isolated.
     */
    private static array $sharedPools = [];

    /**
     * @var array<Channel> Locks to serialize pool initialization per key
     *
     * Prevents race conditions when multiple coroutines try to
     * create a pool for the same configuration simultaneously.
     */
    private static array $poolLocks = [];

    /**
     * @var string|null Cache of pool key for this connection
     */
    private ?string $poolKey = null;

    /**
     * @var float Maximum time to wait for pool initialization lock (seconds)
     */
    protected float $poolLockTimeout = 5.0;

    /**
     * Gets or creates connection pool for this configuration.
     *
     * Uses double-checked locking pattern to safely create pools
     * in concurrent coroutine environments.
     *
     * @return AbstractConnectionPool The existing or newly created pool
     * @throws \RuntimeException When lock acquisition times out
     */
    protected function ensurePool(): AbstractConnectionPool
    {
        $key = $this->poolKey ??= $this->buildPoolKey();

        if (!isset(self::$sharedPools[$key])) {
            $lock = self::$poolLocks[$key] ??= $this->createPoolLock();
            $token = $lock->pop($this->poolLockTimeout);

            if ($token === false) {
                throw new \RuntimeException(
                    "Failed to acquire pool initialization lock for {$key} after {$this->poolLockTimeout}s"
                );
            }

            try {
                // Double-check after acquiring lock
                if (!isset(self::$sharedPools[$key])) {
                    self::$sharedPools[$key] = $this->createPool();
                }
            } finally {
                $lock->push($token);
            }
        }

        return self::$sharedPools[$key];
    }

    /**
     * Returns the connection pool instance.
     *
     * @return AbstractConnectionPool The pool for this connection configuration
     */
    public function getPool(): AbstractConnectionPool
    {
        return $this->ensurePool();
    }

    /**
     * Returns statistics for this connection's pool.
     *
     * @return array{created:int,idle:int,in_use:int,waiters:int,capacity:int,closed:bool}
     */
    public function getPoolStats(): array
    {
        return $this->ensurePool()->getStats();
    }

    /**
     * Builds a unique key for connection pool.
     *
     * The key should include all configuration parameters that affect
     * connection identity (DSN, host, port, database, etc.).
     *
     * @return string The unique pool key
     */
    abstract protected function buildPoolKey(): string;

    /**
     * Creates a new connection pool instance.
     *
     * Called by ensurePool() when a new pool is needed.
     *
     * @return AbstractConnectionPool The newly created pool
     */
    abstract protected function createPool(): AbstractConnectionPool;

    /**
     * Returns pool class name for type checking.
     *
     * Used by shutdownAllPools() to verify pool types
     * before calling shutdown().
     *
     * @return class-string<AbstractConnectionPool> The pool class name
     */
    abstract protected static function getPoolClass(): string;

    /**
     * Creates a lock channel for pool initialization.
     *
     * Uses a Swoole Channel with capacity 1 as a mutex.
     *
     * @return Channel The lock channel
     */
    protected function createPoolLock(): Channel
    {
        $lock = new Channel(1);
        $lock->push(true);

        return $lock;
    }

    /**
     * Shuts down all connection pools of this type.
     *
     * This should be called during application shutdown via ShutdownHelper.
     *
     * Note: Each class using this trait has its own static $sharedPools,
     * so shutdown must be called on each class separately.
     */
    public static function shutdownAllPools(): void
    {
        $poolClass = static::getPoolClass();

        // Shutdown all pools
        foreach (self::$sharedPools as $key => $pool) {
            if ($pool instanceof $poolClass) {
                try {
                    $pool->shutdown();
                } catch (\Throwable $e) {
                    \Yii::warning("Error shutting down pool {$key}: " . $e->getMessage(), __METHOD__);
                }
            }
        }

        // Close and clear all pool locks
        foreach (self::$poolLocks as $key => $lock) {
            try {
                if ($lock instanceof Channel) {
                    $lock->close();
                }
            } catch (\Throwable $e) {
                \Yii::warning("Error closing pool lock {$key}: " . $e->getMessage(), __METHOD__);
            }
        }

        self::$sharedPools = [];
        self::$poolLocks = [];
    }
}
