<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Pools;

/**
 * Thrown when connection pool channel is closed.
 */
class PoolClosedException extends PoolException
{
    public function __construct(string $poolType)
    {
        parent::__construct("{$poolType} connection pool channel is closed.");
    }
}
