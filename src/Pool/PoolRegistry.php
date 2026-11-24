<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Pool;

use Closure;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Throwable;
use Yii;

/**
 * Worker-scoped registry of all active ConnectionPool instances.
 *
 * Centralizes pool creation locking, prewarming, idle reaping, observability stats,
 * and graceful worker shutdown.
 */
final class PoolRegistry
{
    /**
     * @var array<string, ConnectionPool>
     */
    private static array $pools = [];

    /**
     * @var array<string, Channel>
     */
    private static array $locks = [];

    /**
     * @param Closure(): ConnectionPool $factory
     */
    public static function getOrCreate(string $key, Closure $factory, float $lockTimeout = 5.0): ConnectionPool
    {
        if (isset(self::$pools[$key])) {
            return self::$pools[$key];
        }

        if (Coroutine::getCid() < 0) {
            return self::$pools[$key] = $factory();
        }

        $lock = self::$locks[$key] ??= self::createLock();
        $token = $lock->pop($lockTimeout);

        if ($token === false) {
            throw new \RuntimeException(sprintf('Timed out waiting to initialize connection pool "%s".', $key));
        }

        try {
            if (!isset(self::$pools[$key])) {
                self::$pools[$key] = $factory();
            }

            return self::$pools[$key];
        } finally {
            $lock->push($token);
        }
    }

    public static function get(string $key): ?ConnectionPool
    {
        return self::$pools[$key] ?? null;
    }

    /**
     * @return array<string, ConnectionPool>
     */
    public static function all(): array
    {
        return self::$pools;
    }

    public static function prewarmAll(): void
    {
        foreach (self::$pools as $pool) {
            try {
                $pool->prewarm();
            } catch (Throwable $e) {
                Yii::warning(
                    sprintf('Failed to prewarm pool "%s": %s', $pool->getName(), $e->getMessage()),
                    __METHOD__
                );
            }
        }
    }

    public static function reapAllIdle(): int
    {
        $reaped = 0;
        foreach (self::$pools as $pool) {
            try {
                $reaped += $pool->reapIdle();
            } catch (Throwable $e) {
                Yii::warning(
                    sprintf('Failed to reap idle connections in pool "%s": %s', $pool->getName(), $e->getMessage()),
                    __METHOD__
                );
            }
        }

        return $reaped;
    }

    /**
     * @return array<string, array{name:string,capacity:int,min_idle:int,created:int,idle:int,in_use:int,waiters:int,closed:bool}>
     */
    public static function stats(): array
    {
        $result = [];
        foreach (self::$pools as $key => $pool) {
            $result[$key] = $pool->getStats();
        }

        return $result;
    }

    public static function shutdownAll(): void
    {
        foreach (self::$pools as $key => $pool) {
            try {
                $pool->shutdown();
            } catch (Throwable $e) {
                Yii::warning(
                    sprintf('Failed to shutdown pool "%s": %s', $key, $e->getMessage()),
                    __METHOD__
                );
            }
        }

        foreach (self::$locks as $lock) {
            try {
                $lock->close();
            } catch (Throwable) {
                // Ignore close errors during shutdown.
            }
        }

        self::$pools = [];
        self::$locks = [];
    }

    private static function createLock(): Channel
    {
        $lock = new Channel(1);
        $lock->push(true);

        return $lock;
    }
}
