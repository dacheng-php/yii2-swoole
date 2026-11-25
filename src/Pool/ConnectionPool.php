<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Pool;

use Closure;
use Dacheng\Yii2\Swoole\Coroutine\Telemetry;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\WaitGroup;
use Throwable;
use Yii;

/**
 * Elastic, slot-safe coroutine connection pool.
 *
 * Design:
 * - Pure PHP LIFO idle stack ($idleStack): zero-syscall hot path on acquire/release,
 *   hot connections stay warm while cold tail connections naturally age out via maxIdleTime.
 * - Dedicated wakeup channel ($waitQueue): only touched when the pool is saturated and a
 *   coroutine must yield, avoiding ghost signals contaminating idle counts.
 * - Explicit PooledConnection state machine: double-release or double-discard is safely ignored,
 *   guaranteeing no connection can ever be loaned to two coroutines simultaneously.
 * - Exact slot accounting ($createdCount): failed creation immediately restores the slot and
 *   wakes a waiting coroutine.
 */
final class ConnectionPool
{
    /**
     * LIFO stack of idle connections (index 0 = coldest, top = hottest).
     *
     * @var PooledConnection[]
     */
    private array $idleStack = [];

    private Channel $waitQueue;

    private int $createdCount = 0;

    private int $inUseCount = 0;

    private int $waiterCount = 0;

    private bool $closed = false;

    /**
     * @param Closure(): mixed $factory
     * @param Closure(mixed): void $closer
     * @param (Closure(mixed): bool)|null $pinger
     */
    public function __construct(
        private readonly string $name,
        private readonly Closure $factory,
        private readonly Closure $closer,
        private readonly ?Closure $pinger = null,
        private readonly PoolConfig $config = new PoolConfig()
    ) {
        $this->waitQueue = new Channel(1);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getConfig(): PoolConfig
    {
        return $this->config;
    }

    /**
     * Prewarms the pool up to minIdle connections.
     */
    public function prewarm(): int
    {
        if ($this->closed || $this->config->minIdle <= 0) {
            return 0;
        }

        $target = min($this->config->minIdle, $this->config->maxActive);
        $needed = max(0, $target - $this->createdCount);
        if ($needed === 0) {
            return 0;
        }

        $created = 0;

        if (Coroutine::getCid() >= 0) {
            $wg = new WaitGroup();
            for ($i = 0; $i < $needed; $i++) {
                if ($this->closed || $this->createdCount >= $this->config->maxActive) {
                    break;
                }
                $this->createdCount++;
                $wg->add();
                Coroutine::create(function () use ($wg, &$created): void {
                    try {
                        $item = $this->instantiateConnection();
                        if ($this->closed) {
                            $this->destroySlot($item);
                            return;
                        }
                        $item->markIdle(microtime(true));
                        $this->idleStack[] = $item;
                        $this->wakeOneWaiter();
                        $created++;
                    } catch (Throwable $e) {
                        $this->createdCount = max(0, $this->createdCount - 1);
                        $this->wakeOneWaiter();
                        Yii::warning(
                            sprintf('Prewarm failed for pool "%s": %s', $this->name, $e->getMessage()),
                            __METHOD__
                        );
                    } finally {
                        $wg->done();
                    }
                });
            }
            $wg->wait();

            return $created;
        }

        for ($i = 0; $i < $needed; $i++) {
            if ($this->closed || $this->createdCount >= $this->config->maxActive) {
                break;
            }
            $this->createdCount++;
            try {
                $item = $this->instantiateConnection();
                if ($this->closed) {
                    $this->destroySlot($item);
                    continue;
                }
                $item->markIdle(microtime(true));
                $this->idleStack[] = $item;
                $created++;
            } catch (Throwable $e) {
                $this->createdCount = max(0, $this->createdCount - 1);
                Yii::warning(
                    sprintf('Prewarm failed for pool "%s": %s', $this->name, $e->getMessage()),
                    __METHOD__
                );
            }
        }

        return $created;
    }

    /**
     * Acquires a healthy pooled connection.
     *
     * @throws PoolClosedException
     * @throws PoolExhaustedException
     * @throws PoolCreationException
     */
    public function acquire(): PooledConnection
    {
        if ($this->closed) {
            throw new PoolClosedException($this->name);
        }

        $telemetry = Telemetry::isEnabled();
        $acquireStart = $telemetry ? microtime(true) : 0.0;
        $pinged = false;
        $deadline = null;

        while (true) {
            if ($this->closed) {
                throw new PoolClosedException($this->name);
            }

            // 1. Hot path (LIFO): pop the warmest idle connection without any channel overhead.
            while ($this->idleStack !== []) {
                $item = array_pop($this->idleStack);
                if (!$item instanceof PooledConnection || !$item->markInUse()) {
                    continue;
                }

                $checkedPing = false;
                if ($this->isHealthy($item, $checkedPing)) {
                    if ($checkedPing) {
                        $pinged = true;
                    }
                    if ($this->closed) {
                        $this->destroySlot($item);
                        throw new PoolClosedException($this->name);
                    }
                    $this->inUseCount++;
                    if ($telemetry) {
                        Telemetry::recordPoolAcquire($this->name, (microtime(true) - $acquireStart) * 1000.0, false, $pinged);
                    }
                    return $item;
                }

                $this->destroySlot($item);
            }

            if ($this->closed) {
                throw new PoolClosedException($this->name);
            }

            // 2. Lazy creation path: atomically reserve a slot before yielding on connect I/O.
            if ($this->createdCount < $this->config->maxActive) {
                $this->createdCount++;
                try {
                    $created = $this->instantiateConnection();
                } catch (Throwable $e) {
                    $this->createdCount = max(0, $this->createdCount - 1);
                    $this->wakeOneWaiter();
                    throw $e;
                }

                if ($this->closed) {
                    $this->destroySlot($created);
                    throw new PoolClosedException($this->name);
                }

                $this->inUseCount++;
                if ($telemetry) {
                    Telemetry::recordPoolAcquire($this->name, (microtime(true) - $acquireStart) * 1000.0, true, $pinged);
                }
                return $created;
            }

            // 3. Saturated path: wait for a release/discard wakeup until waitTimeout expires.
            if (Coroutine::getCid() < 0) {
                if ($telemetry) {
                    Telemetry::recordPoolAcquire($this->name, (microtime(true) - $acquireStart) * 1000.0, false, $pinged, true);
                }
                throw new PoolExhaustedException($this->name, $this->config->waitTimeout, $this->getStats());
            }

            $deadline ??= microtime(true) + $this->config->waitTimeout;
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                if ($telemetry) {
                    Telemetry::recordPoolAcquire($this->name, (microtime(true) - $acquireStart) * 1000.0, false, $pinged, true);
                }
                throw new PoolExhaustedException($this->name, $this->config->waitTimeout, $this->getStats());
            }

            $this->waiterCount++;
            try {
                $woke = $this->waitQueue->pop($remaining);
            } finally {
                $this->waiterCount = max(0, $this->waiterCount - 1);
            }

            if ($this->closed) {
                throw new PoolClosedException($this->name);
            }

            if ($woke === false) {
                if ($this->idleStack !== [] || $this->createdCount < $this->config->maxActive) {
                    if (microtime(true) < $deadline) {
                        continue;
                    }
                }

                if ($telemetry) {
                    Telemetry::recordPoolAcquire($this->name, (microtime(true) - $acquireStart) * 1000.0, false, $pinged, true);
                }
                throw new PoolExhaustedException($this->name, $this->config->waitTimeout, $this->getStats());
            }
        }
    }

    /**
     * Returns a healthy connection back to the pool.
     * Double-release is safely ignored via the PooledConnection state machine.
     */
    public function release(PooledConnection $item): void
    {
        if (!$item->isInUse()) {
            return;
        }

        $this->inUseCount = max(0, $this->inUseCount - 1);
        if (Telemetry::isEnabled()) {
            Telemetry::recordPoolRelease($this->name, false);
        }

        if ($this->closed) {
            $this->destroySlot($item);
            return;
        }

        $now = microtime(true);
        if ($this->config->maxLifetime > 0 && ($now - $item->createdAt) >= $this->config->maxLifetime) {
            $this->destroySlot($item);
            $this->wakeOneWaiter();
            return;
        }

        if ($item->markIdle($now)) {
            $this->idleStack[] = $item;
            $this->wakeOneWaiter();
        }
    }

    /**
     * Discards a broken connection and releases its capacity slot immediately.
     * Double-discard is safely ignored via the PooledConnection state machine.
     */
    public function discard(PooledConnection $item): void
    {
        if ($item->isClosed()) {
            return;
        }

        if ($item->isInUse()) {
            $this->inUseCount = max(0, $this->inUseCount - 1);
        }

        if (Telemetry::isEnabled()) {
            Telemetry::recordPoolRelease($this->name, true);
        }

        $this->destroySlot($item);
        $this->wakeOneWaiter();
    }

    /**
     * Reaps expired idle connections from the coldest end of the LIFO stack
     * while keeping at least minIdle healthy connections (unless maxLifetime is exceeded).
     */
    public function reapIdle(): int
    {
        if ($this->closed || $this->idleStack === []) {
            return 0;
        }

        $now = microtime(true);
        $reaped = 0;
        $retained = [];
        $snapshot = $this->idleStack;
        $this->idleStack = [];

        foreach ($snapshot as $item) {
            if (!$item->isIdle()) {
                continue;
            }

            $lifetimeExceeded = $this->config->maxLifetime > 0 && ($now - $item->createdAt) >= $this->config->maxLifetime;
            $idleExceeded = $this->config->maxIdleTime > 0 && ($now - $item->lastUsedAt) >= $this->config->maxIdleTime;
            $canShrink = $this->createdCount > $this->config->minIdle;

            if ($lifetimeExceeded || ($idleExceeded && $canShrink)) {
                $this->destroySlot($item);
                $reaped++;
                continue;
            }

            $retained[] = $item;
        }

        if ($retained !== []) {
            if ($this->closed) {
                foreach ($retained as $item) {
                    $this->destroySlot($item);
                }
            } else {
                // Preserve LIFO ordering: retained cold-to-warm items sit beneath any newly released items.
                $this->idleStack = $this->idleStack === []
                    ? $retained
                    : array_merge($retained, $this->idleStack);
            }
        }

        if ($reaped > 0) {
            $this->wakeOneWaiter();
        }

        return $reaped;
    }

    /**
     * Gracefully closes the pool and all idle connections.
     * Safe to invoke both inside and outside a coroutine (e.g. onWorkerStop).
     */
    public function shutdown(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        $idle = $this->idleStack;
        $this->idleStack = [];
        foreach ($idle as $item) {
            $this->destroySlot($item);
        }

        $this->waitQueue->close();
    }

    /**
     * @return array{name:string,capacity:int,min_idle:int,created:int,idle:int,in_use:int,waiters:int,closed:bool}
     */
    public function getStats(): array
    {
        return [
            'name' => $this->name,
            'capacity' => $this->config->maxActive,
            'min_idle' => $this->config->minIdle,
            'created' => $this->createdCount,
            'idle' => count($this->idleStack),
            'in_use' => $this->inUseCount,
            'waiters' => $this->waiterCount,
            'closed' => $this->closed,
        ];
    }

    private function instantiateConnection(): PooledConnection
    {
        try {
            $connection = ($this->factory)();
        } catch (PoolCreationException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new PoolCreationException($this->name, $e);
        }

        return new PooledConnection($connection, microtime(true));
    }

    private function isHealthy(PooledConnection $item, bool &$pinged = false): bool
    {
        $now = microtime(true);

        if ($item->isExpired($now, $this->config->maxLifetime, $this->config->maxIdleTime)) {
            return false;
        }

        if ($this->pinger !== null && $item->needsHealthCheck($now, $this->config->idlePingInterval)) {
            $pinged = true;
            try {
                return (bool) ($this->pinger)($item->connection);
            } catch (Throwable) {
                return false;
            }
        }

        return true;
    }

    private function destroySlot(PooledConnection $item): void
    {
        if (!$item->markClosed()) {
            return;
        }

        $this->createdCount = max(0, $this->createdCount - 1);

        try {
            ($this->closer)($item->connection);
        } catch (Throwable) {
            // Best-effort close on discarded/expired connection.
        }
    }

    private function wakeOneWaiter(): void
    {
        if ($this->closed || $this->waiterCount <= 0 || Coroutine::getCid() < 0) {
            return;
        }

        // Only push if a consumer is currently suspended on waitQueue (non-blocking).
        $stats = $this->waitQueue->stats();
        if (((int) ($stats['consumer_num'] ?? 0)) > 0) {
            $this->waitQueue->push(true, 0.0);
        }
    }
}
