<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Pool;

final class PooledConnection
{
    private const STATE_IN_USE = 1;
    private const STATE_IDLE = 2;
    private const STATE_CLOSED = 3;

    public float $lastUsedAt;

    private int $state = self::STATE_IN_USE;

    public function __construct(
        public readonly mixed $connection,
        public readonly float $createdAt
    ) {
        $this->lastUsedAt = $createdAt;
    }

    public function isInUse(): bool
    {
        return $this->state === self::STATE_IN_USE;
    }

    public function isIdle(): bool
    {
        return $this->state === self::STATE_IDLE;
    }

    public function isClosed(): bool
    {
        return $this->state === self::STATE_CLOSED;
    }

    public function markInUse(): bool
    {
        if ($this->state !== self::STATE_IDLE) {
            return false;
        }

        $this->state = self::STATE_IN_USE;

        return true;
    }

    public function markIdle(float $now): bool
    {
        if ($this->state !== self::STATE_IN_USE) {
            return false;
        }

        $this->lastUsedAt = $now;
        $this->state = self::STATE_IDLE;

        return true;
    }

    public function markClosed(): bool
    {
        if ($this->state === self::STATE_CLOSED) {
            return false;
        }

        $this->state = self::STATE_CLOSED;

        return true;
    }

    public function isExpired(float $now, float $maxLifetime, float $maxIdleTime): bool
    {
        if ($maxLifetime > 0 && ($now - $this->createdAt) >= $maxLifetime) {
            return true;
        }

        if ($maxIdleTime > 0 && ($now - $this->lastUsedAt) >= $maxIdleTime) {
            return true;
        }

        return false;
    }

    public function needsHealthCheck(float $now, float $idlePingInterval): bool
    {
        if ($idlePingInterval <= 0) {
            return false;
        }

        return ($now - $this->lastUsedAt) >= $idlePingInterval;
    }

    public function touch(float $now): void
    {
        $this->lastUsedAt = $now;
    }
}
