<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Pools;

use RuntimeException;

/**
 * Base exception for connection pool errors
 */
abstract class PoolException extends RuntimeException
{
}
