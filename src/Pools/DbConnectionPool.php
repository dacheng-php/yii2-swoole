<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Pools;

use PDO;

/**
 * Database connection pool for PDO connections in coroutine environments.
 *
 * This pool manages PDO instances that can be shared across coroutines.
 * PDO connections are validated using instanceof checks.
 */
final class DbConnectionPool extends AbstractConnectionPool
{
    /**
     * Whether to perform health check when acquiring connections.
     */
    private bool $enableHealthCheck = false;

    /**
     * @param callable $factory Creator returning a configured PDO instance
     * @param int $maxActive Maximum number of connections in pool
     * @param float $waitTimeout Maximum time to wait for connection (seconds)
     * @param bool $enableHealthCheck Whether to validate connections on acquire
     */
    public function __construct(callable $factory, int $maxActive, float $waitTimeout, bool $enableHealthCheck = false)
    {
        $this->enableHealthCheck = $enableHealthCheck;
        parent::__construct($factory, $maxActive, $waitTimeout, 'Database');
    }

    /**
     * Acquires a connection from the pool.
     *
     * If health check is enabled, validates the connection before returning.
     *
     * @return PDO The acquired PDO connection
     * @throws PoolExhaustedException When pool is exhausted
     */
    public function acquire()
    {
        $connection = parent::acquire();

        // Optional health check to verify connection is alive
        if ($this->enableHealthCheck && $this->isValidConnection($connection)) {
            try {
                // Lightweight PDO ping by querying server info attribute
                $connection->getAttribute(PDO::ATTR_SERVER_INFO);
            } catch (\Throwable $e) {
                // Connection is dead, discard and get a new one
                $this->closeConnection($connection);
                $connection = $this->createConnection();
            }
        }

        return $connection;
    }

    /**
     * Checks if health checking is enabled.
     *
     * @return bool True if health check is enabled
     */
    public function isHealthCheckEnabled(): bool
    {
        return $this->enableHealthCheck;
    }

    /**
     * Enables or disables connection health checking.
     *
     * @param bool $enabled Whether to enable health check
     */
    public function setHealthCheckEnabled(bool $enabled): void
    {
        $this->enableHealthCheck = $enabled;
    }

    protected function closeConnection($connection): void
    {
        // PDO connections close automatically when all references are destroyed
        unset($connection);
    }

    protected function isValidConnection($connection): bool
    {
        return $connection instanceof PDO;
    }
}
