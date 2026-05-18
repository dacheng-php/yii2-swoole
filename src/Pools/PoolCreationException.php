<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Pools;

/**
 * Thrown when connection factory fails to create a connection.
 */
class PoolCreationException extends PoolException
{
    public function __construct(string $poolType, ?\Throwable $previous = null)
    {
        parent::__construct(
            "Failed to create a {$poolType} connection for the pool.",
            0,
            $previous
        );
    }
}
