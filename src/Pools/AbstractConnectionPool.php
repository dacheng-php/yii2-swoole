<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Pools;

use RuntimeException;
use Swoole\Coroutine\Channel;
use yii\base\InvalidConfigException;

/**
 * Abstract base class for connection pools in coroutine environments.
 *
 * This pool implementation:
 * - Maintains a pool of reusable connections
 * - Uses Swoole channels for lock-free coordination
 * - Supports max pool sizes with wait timeout
 * - Provides graceful shutdown with connection draining
 *
 * @template T of mixed The connection type (PDO, resource, etc.)
 */
abstract class AbstractConnectionPool
{
    protected Channel $channel;

    protected int $maxActive;

    protected float $waitTimeout;

    /**
     * @var callable(): T
     */
    protected $factory;

    protected string $connectionType;

    /**
     * @var bool Whether the pool has been shut down
     */
    protected bool $isClosed = false;

    /**
     * @param callable $factory Creator returning a configured connection
     * @param string $connectionType Connection type name for error messages
     */
    public function __construct(callable $factory, int $maxActive, float $waitTimeout, string $connectionType)
    {
        if ($maxActive < 1) {
            throw new InvalidConfigException('"poolMaxActive" must be greater than or equal to 1.');
        }

        $this->factory = $factory;
        $this->maxActive = $maxActive;
        $this->waitTimeout = $waitTimeout;
        $this->connectionType = $connectionType;
        $this->channel = new Channel($maxActive);

        // Create all connections in a temporary array first to ensure atomic initialization
        // If any connection fails, we clean up all created connections before throwing
        $connections = [];
        $errors = [];
        $cid = \Swoole\Coroutine::getCid();

        try {
            if ($cid >= 0) {
                // Concurrent connection creation in a coroutine context using WaitGroup
                $wg = new \Swoole\Coroutine\WaitGroup();
                // Pre-fill the array to hold correct slots
                $connections = array_fill(0, $this->maxActive, null);

                for ($i = 0; $i < $this->maxActive; $i++) {
                    $wg->add();
                    \Swoole\Coroutine::create(function () use ($i, $wg, &$connections, &$errors) {
                        try {
                            $connections[$i] = $this->createConnection();
                        } catch (\Throwable $exception) {
                            $errors[] = $exception;
                        } finally {
                            $wg->done();
                        }
                    });
                }

                $wg->wait();

                // If any error occurred during concurrent creation, throw the first error to trigger cleanup
                if (!empty($errors)) {
                    throw reset($errors);
                }
            } else {
                // Sequential fallback outside a coroutine context
                for ($i = 0; $i < $this->maxActive; $i++) {
                    $connections[] = $this->createConnection();
                }
            }

            // All connections created successfully, now add them to the pool
            foreach ($connections as $connection) {
                if ($connection !== null) {
                    $this->pushConnection($connection);
                }
            }
        } catch (\Throwable $exception) {
            // Clean up any partially created connections
            foreach ($connections as $connection) {
                if ($connection !== null) {
                    try {
                        $this->closeConnection($connection);
                    } catch (\Throwable) {
                        // Ignore close errors during cleanup
                    }
                }
            }
            // Also drain the channel in case any were pushed
            $this->drainPool();

            throw $exception;
        }
    }

    /**
     * Acquires a connection from the pool.
     *
     * @return mixed The connection
     * @throws PoolExhaustedException When pool is exhausted (timeout)
     * @throws PoolClosedException When pool has been shut down
     * @throws PoolCreationException When connection creation fails
     */
    public function acquire()
    {
        if ($this->isClosed) {
            throw new PoolClosedException($this->connectionType);
        }

        $connection = $this->channel->pop($this->waitTimeout);

        if ($this->isValidConnection($connection)) {
            return $connection;
        }

        if ($connection === false) {
            // Distinguish between pool closed and timeout
            // When channel is closed, push/pop returns false immediately
            // and subsequent operations also fail
            if ($this->isChannelClosed()) {
                throw new PoolClosedException($this->connectionType);
            }

            $stats = $this->getStats();

            throw new PoolExhaustedException(
                $this->connectionType,
                $this->maxActive,
                $stats['idle'],
                $stats['waiters']
            );
        }

        // Connection is null or invalid. Close it before creating a replacement.
        if ($connection !== null) {
            try {
                $this->closeConnection($connection);
            } catch (\Throwable) {
                // Ignore close errors for an already invalid connection.
            }
        }

        // Note: createConnection() may throw PoolCreationException.
        return $this->createConnection();
    }

    /**
     * Checks if the channel has been closed.
     *
     * @return bool True if channel is closed
     */
    protected function isChannelClosed(): bool
    {
        return $this->isClosed;
    }

    /**
     * Releases a connection back to the pool.
     *
     * @param mixed $connection The connection to release
     */
    public function release($connection): void
    {
        if ($this->isClosed) {
            try {
                $this->closeConnection($connection);
            } catch (\Throwable) {
                // Ignore close errors after shutdown.
            }
            return;
        }

        if (!$this->isValidConnection($connection)) {
            try {
                $this->closeConnection($connection);
            } catch (\Throwable) {
                // Ignore close errors for invalid connections.
            }
            $this->replaceDiscardedConnection();
            return;
        }

        $this->pushConnection($connection);
    }

    /**
     * Discards a connection that has encountered an error.
     *
     * Unlike release(), this method closes the connection instead of returning
     * it to the pool. A new connection will be created on the next acquire().
     *
     * @param mixed $connection The connection to discard
     */
    public function discard($connection): void
    {
        try {
            $this->closeConnection($connection);
        } catch (\Throwable $e) {
            // Ignore close errors for discarded connections
        }

        $this->replaceDiscardedConnection();
    }

    protected function replaceDiscardedConnection(): void
    {
        if ($this->isClosed) {
            return;
        }

        try {
            $this->pushConnection($this->createConnection());
        } catch (\Throwable $exception) {
            \Yii::warning(
                sprintf('Failed to create replacement %s connection: %s', $this->connectionType, $exception->getMessage()),
                __METHOD__
            );
        }
    }

    /**
     * Creates a new connection.
     *
     * @return mixed The created connection
     * @throws PoolCreationException When connection creation fails
     */
    protected function createConnection()
    {
        try {
            $connection = ($this->factory)();

            if (!$this->isValidConnection($connection)) {
                throw new RuntimeException('Connection factory must return a valid connection.');
            }

            return $connection;
        } catch (PoolCreationException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new PoolCreationException($this->connectionType, $exception);
        }
    }

    /**
     * Closes a connection.
     *
     * @param mixed $connection The connection to close
     */
    abstract protected function closeConnection($connection): void;

    /**
     * Checks if a connection is valid.
     *
     * @param mixed $connection The connection to validate
     * @return bool True if the connection is valid
     */
    abstract protected function isValidConnection($connection): bool;

    /**
     * Pushes a connection back to the pool.
     *
     * @param mixed $connection The connection to push
     * @throws PoolClosedException When channel is closed
     */
    protected function pushConnection($connection): void
    {
        if ($this->isClosed) {
            $this->closeConnection($connection);
            throw new PoolClosedException($this->connectionType);
        }

        if (!$this->channel->push($connection, 0.0)) {
            $this->closeConnection($connection);
            throw new PoolClosedException($this->connectionType);
        }
    }

    /**
     * Returns pool statistics.
     *
     * Contract:
     * - created: number of eagerly created pool slots configured for this pool.
     * - idle: currently available healthy connections in the channel.
     * - in_use: approximate checked-out connections, calculated as capacity - idle.
     * - waiters: coroutines waiting on acquire().
     * - capacity: maximum active connections allowed by configuration.
     * - closed: whether shutdown() has been called.
     *
     * @return array{created:int,idle:int,in_use:int,waiters:int,capacity:int,closed:bool}
     */
    public function getStats(): array
    {
        $stats = $this->channel->stats();

        return [
            'created' => $this->maxActive,
            'idle' => (int) ($stats['queue_num'] ?? 0),
            'in_use' => max(0, $this->maxActive - (int) ($stats['queue_num'] ?? 0)),
            'waiters' => (int) ($stats['consumer_num'] ?? 0),
            'capacity' => $this->maxActive,
            'closed' => $this->isClosed,
        ];
    }

    /**
     * Drains all connections from the pool.
     */
    protected function drainPool(): void
    {
        try {
            $stats = $this->channel->stats();
            $count = $stats['queue_num'] ?? 0;
        } catch (\Throwable $e) {
            return;
        }

        for ($i = 0; $i < $count; $i++) {
            $connection = $this->channel->pop(0.01);

            if ($connection === false || !$this->isValidConnection($connection)) {
                break;
            }

            try {
                $this->closeConnection($connection);
            } catch (\Throwable $e) {
                // Silently handle close errors
            }
        }
    }

    /**
     * Gracefully shuts down the connection pool.
     * Closes all connections and the channel.
     */
    public function shutdown(): void
    {
        $this->isClosed = true;

        try {
            $this->drainPool();
        } catch (\Throwable $e) {
            // Silently handle drain errors
        }

        try {
            $this->channel->close();
        } catch (\Throwable $e) {
            // Silently handle channel close errors
        }
    }
}
