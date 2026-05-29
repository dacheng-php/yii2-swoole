<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Pools;

/**
 * Redis connection pool for socket connections in coroutine environments.
 *
 * This pool implementation:
 * - Maintains a pool of reusable socket resources
 * - Uses Swoole channels for lock-free coordination
 * - Supports max pool sizes with wait timeout
 * - Handles connection validation and replacement
 * - Optionally validates connections with stream health check
 */
final class RedisConnectionPool extends AbstractConnectionPool
{
    /**
     * Whether to perform health check when acquiring connections
     */
    private bool $enableHealthCheck = false;

    /**
     * @param callable $factory Creator returning a configured socket resource
     * @param int $maxActive Maximum number of connections in pool
     * @param float $waitTimeout Maximum time to wait for connection (seconds)
     * @param bool $enableHealthCheck Whether to validate connections on acquire
     */
    public function __construct(callable $factory, int $maxActive, float $waitTimeout, bool $enableHealthCheck = false)
    {
        $this->enableHealthCheck = $enableHealthCheck;
        parent::__construct($factory, $maxActive, $waitTimeout, 'Redis');
    }

    /**
     * Acquires a connection from the pool.
     *
     * If health check is enabled, validates the connection before returning.
     *
     * @return resource The acquired socket connection
     * @throws PoolExhaustedException When pool is exhausted
     */
    public function acquire()
    {
        $connection = parent::acquire();

        // Optional health check to verify connection is alive
        if ($this->enableHealthCheck && $this->isValidConnection($connection)) {
            if (!$this->pingConnection($connection)) {
                // Connection is dead, discard and get a new one
                $this->closeConnection($connection);
                $connection = $this->createConnection();
            }
        }

        return $connection;
    }

    /**
     * Performs a health check on the connection to verify it's alive.
     *
     * Uses stream metadata to check if connection is still open.
     *
     * @param resource $connection The socket connection
     * @return bool True if connection appears healthy
     */
    private function pingConnection($connection): bool
    {
        try {
            $metadata = stream_get_meta_data($connection);
            return !($metadata['eof'] ?? false);
        } catch (\Throwable $e) {
            return false;
        }
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
        if ($this->isStream($connection)) {
            try {
                @fclose($connection);
            } catch (\Throwable) {
                // Ignore errors during close
            }
        }
    }

    protected function isValidConnection($connection): bool
    {
        return $this->isStream($connection);
    }

    private function isStream($connection): bool
    {
        return is_resource($connection) && get_resource_type($connection) === 'stream';
    }
}
