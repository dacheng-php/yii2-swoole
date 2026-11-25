<?php

declare(strict_types=1);

namespace yii2swoole\pool;

/**
 * Explicit contract for components backed by a worker-scoped ConnectionPool.
 */
interface PoolAwareInterface
{
    public function getPool(): ConnectionPool;
}
