<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Db;

use Dacheng\Yii2\Swoole\Pools\DbConnectionPool;
use Dacheng\Yii2\Swoole\Pools\PoolManager;
use PDO;
use Swoole\Coroutine;
use yii\db\Connection;

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
     * Tracks whether the current connection has been released back to pool.
     *
     * @var bool
     */
    private bool $released = false;

    public function open(): void
    {
        if ($this->pdo !== null) {
            return;
        }

        if (!$this->enableCoroutinePooling || Coroutine::getCid() < 0) {
            parent::open();

            return;
        }

        $this->pdo = $this->ensurePool()->acquire();
        $this->released = false;

        $this->trigger(self::EVENT_AFTER_OPEN);
    }

    public function close(): void
    {
        if (!$this->enableCoroutinePooling || Coroutine::getCid() < 0) {
            parent::close();

            return;
        }

        if ($this->pdo === null) {
            return;
        }

        if (!$this->released) {
            $pdo = $this->pdo;
            $this->released = true;
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

            try {
                parent::close();
            } catch (\Throwable $e) {
                $discard = true;
                \Yii::error('Error closing PDO connection: ' . $e->getMessage(), __CLASS__);
            }

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
            parent::close();
        }
    }

    public function reset(): void
    {
        if ($this->pdo !== null && !$this->released) {
            $this->close();
        }
        $this->released = false;
        $this->pdo = null;
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

        // Set default fetch mode if configured
        if ($this->pdoType !== null) {
            $pdo->setAttribute(PDO::ATTR_ORACLE_NULLS, $this->pdoType);
        }

        // Trigger after open event on the connection instance
        $this->trigger(self::EVENT_AFTER_OPEN);
    }
}
