<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Pool;

/**
 * Explicit contract for components backed by a worker-scoped ConnectionPool.
 */
interface PoolAwareInterface
{
    public function getPool(): ConnectionPool;
}
