<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Server;

use Dacheng\Yii2\Swoole\Pool\PoolRegistry;
use Swoole\Coroutine;
use Swoole\Http\Server as SwooleHttpServer;

/**
 * Provides runtime telemetry snapshots (Swoole server, coroutines, requests, memory, and pools)
 * that applications can expose directly on health/metrics endpoints.
 */
final class RuntimeStats
{
    private static ?SwooleHttpServer $server = null;

    private static ?RequestHandler $handler = null;

    private static ?int $workerId = null;

    private static float $workerStartedAt = 0.0;

    /** @var array<string, mixed> */
    private static array $serverMeta = [];

    /**
     * @param array<string, mixed> $meta
     */
    public static function bind(SwooleHttpServer $server, RequestHandler $handler, int $workerId, array $meta = []): void
    {
        self::$server = $server;
        self::$handler = $handler;
        self::$workerId = $workerId;
        self::$workerStartedAt = microtime(true);
        self::$serverMeta = $meta;
    }

    public static function reset(): void
    {
        self::$server = null;
        self::$handler = null;
        self::$workerId = null;
        self::$workerStartedAt = 0.0;
        self::$serverMeta = [];
    }

    /**
     * @return array<string, mixed>
     */
    public static function snapshot(): array
    {
        $now = microtime(true);

        return [
            'php_version' => PHP_VERSION,
            'swoole_version' => extension_loaded('swoole') ? phpversion('swoole') : null,
            'config' => self::$serverMeta,
            'worker' => [
                'id' => self::$workerId,
                'pid' => getmypid(),
                'uptime_seconds' => self::$workerStartedAt > 0 ? round($now - self::$workerStartedAt, 2) : 0.0,
                'memory_mb' => round(memory_get_usage(true) / 1048576, 2),
                'memory_peak_mb' => round(memory_get_peak_usage(true) / 1048576, 2),
            ],
            'requests' => self::$handler?->getStats() ?? [
                'total_requests' => 0,
                'active_requests' => 0,
            ],
            'coroutine' => extension_loaded('swoole') ? Coroutine::stats() : [],
            'server' => self::$server !== null ? self::$server->stats() : [],
            'pools' => PoolRegistry::stats(),
        ];
    }
}
