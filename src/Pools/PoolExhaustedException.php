<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Pools;

/**
 * Thrown when connection pool has no available connections.
 */
class PoolExhaustedException extends PoolException
{
    public function __construct(
        string $poolType,
        int $maxActive,
        int $idle,
        int $waiters
    ) {
        parent::__construct(sprintf(
            '%s connection pool exhausted. Max active: %d, idle: %d, waiting consumers: %d',
            $poolType,
            $maxActive,
            $idle,
            $waiters
        ));
    }
}
