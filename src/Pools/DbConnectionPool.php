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
     * @param callable $factory Creator returning a configured PDO instance
     */
    public function __construct(callable $factory, int $maxActive, float $waitTimeout)
    {
        parent::__construct($factory, $maxActive, $waitTimeout, 'Database');
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
