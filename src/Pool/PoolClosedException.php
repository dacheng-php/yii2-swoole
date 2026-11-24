<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Pool;

class PoolClosedException extends PoolException
{
    public function __construct(public readonly string $poolName)
    {
        parent::__construct(sprintf('Connection pool "%s" has been closed.', $poolName));
    }
}
