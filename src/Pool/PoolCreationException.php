<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Pool;

use Throwable;

class PoolCreationException extends PoolException
{
    public function __construct(public readonly string $poolName, Throwable $previous)
    {
        parent::__construct(
            sprintf('Failed to create connection for pool "%s": %s', $poolName, $previous->getMessage()),
            (int) $previous->getCode(),
            $previous
        );
    }
}
