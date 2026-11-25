<?php

declare(strict_types=1);

namespace yii2swoole\pool;

class PoolExhaustedException extends PoolException
{
    /**
     * @param array<string, mixed> $stats
     */
    public function __construct(
        public readonly string $poolName,
        public readonly float $waitTimeout,
        public readonly array $stats
    ) {
        parent::__construct(sprintf(
            'Connection pool "%s" exhausted after %.2fs (capacity=%d, created=%d, idle=%d, in_use=%d, waiters=%d).',
            $poolName,
            $waitTimeout,
            (int) ($stats['capacity'] ?? 0),
            (int) ($stats['created'] ?? 0),
            (int) ($stats['idle'] ?? 0),
            (int) ($stats['in_use'] ?? 0),
            (int) ($stats['waiters'] ?? 0)
        ));
    }
}
