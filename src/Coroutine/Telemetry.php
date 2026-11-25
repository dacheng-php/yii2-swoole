<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Coroutine;

use Closure;
use Swoole\Coroutine;

/**
 * Lightweight, opt-in per-request coroutine telemetry collector and runtime snapshot bridge.
 *
 * Disabled by default so production hot paths only incur a single static bool check (zero allocations).
 * Enabled automatically when Dacheng\Yii2\Swoole\Debug\Module bootstraps.
 *
 * Also bridges Server\RuntimeStats into lower component layers via a registered snapshot provider
 * callback, preserving strict one-way package dependencies (components never depend on Server\).
 */
final class Telemetry
{
    private const CONTEXT_DATA_KEY = '__yii2SwooleTelemetry';
    private const CONTEXT_OWNER_CID_KEY = '__yii2SwooleTelemetryOwnerCid';

    private const MAX_POOL_EVENTS = 200;
    private const MAX_REDIS_COMMANDS = 200;
    private const MAX_CONCURRENT_BATCHES = 100;

    private static bool $enabled = false;

    /**
     * @var (Closure(): array<string, mixed>)|null
     */
    private static ?Closure $runtimeSnapshotProvider = null;

    public static function enable(): void
    {
        self::$enabled = true;
    }

    public static function disable(): void
    {
        self::$enabled = false;
    }

    public static function isEnabled(): bool
    {
        return self::$enabled;
    }

    /**
     * @param (Closure(): array<string, mixed>)|null $provider
     */
    public static function registerRuntimeSnapshotProvider(?Closure $provider): void
    {
        self::$runtimeSnapshotProvider = $provider;
    }

    /**
     * @return array<string, mixed>
     */
    public static function getRuntimeSnapshot(): array
    {
        if (self::$runtimeSnapshotProvider === null) {
            return [];
        }

        try {
            return (self::$runtimeSnapshotProvider)();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Initializes per-request telemetry storage on the root request coroutine.
     */
    public static function beginRequest(float $startedAt, ?string $requestId = null): void
    {
        if (!self::$enabled || !self::inCoroutine()) {
            return;
        }

        $cid = Coroutine::getCid();
        $parentCid = Coroutine::getPcid($cid);
        $coStats = Coroutine::stats();
        $context = Coroutine::getContext();

        $context[self::CONTEXT_OWNER_CID_KEY] = $cid;
        $context[self::CONTEXT_DATA_KEY] = [
            'request_id' => $requestId,
            'cid' => $cid,
            'parent_cid' => $parentCid,
            'started_at' => $startedAt,
            'memory_start' => memory_get_usage(),
            'memory_real_start' => memory_get_usage(true),
            'switch_start' => isset($coStats['coroutine_switch_num']) ? (int) $coStats['coroutine_switch_num'] : null,
            'pool_summary' => [
                'acquires' => 0,
                'releases' => 0,
                'discards' => 0,
                'created' => 0,
                'pinged' => 0,
                'exhausted' => 0,
                'total_wait_ms' => 0.0,
            ],
            'pool_events' => [],
            'pool_events_truncated' => 0,
            'concurrent_summary' => [
                'batches' => 0,
                'total_tasks' => 0,
                'max_concurrency' => 0,
                'total_duration_ms' => 0.0,
            ],
            'concurrent_batches' => [],
            'redis_summary' => [
                'count' => 0,
                'total_duration_ms' => 0.0,
                'retries' => 0,
                'failed' => 0,
                'sticky_commands' => 0,
            ],
            'redis_commands' => [],
            'redis_commands_truncated' => 0,
            'session' => null,
        ];
    }

    /**
     * Binds a child coroutine to its ancestor request coroutine so pool/redis/concurrent
     * telemetry recorded inside child coroutines rolls up into the parent request automatically.
     */
    public static function attachParent(int $parentCid): void
    {
        if (!self::$enabled || $parentCid < 0 || !self::inCoroutine()) {
            return;
        }

        $parentContext = Coroutine::getContext($parentCid);
        if ($parentContext === null) {
            return;
        }

        $ownerCid = $parentContext[self::CONTEXT_OWNER_CID_KEY] ?? $parentCid;
        if (is_int($ownerCid) && $ownerCid >= 0) {
            Coroutine::getContext()[self::CONTEXT_OWNER_CID_KEY] = $ownerCid;
        }
    }

    public static function getRequestStartedAt(): ?float
    {
        if (!self::$enabled || !self::inCoroutine()) {
            return null;
        }

        $ownerContext = self::resolveOwnerContext();
        $startedAt = $ownerContext[self::CONTEXT_DATA_KEY]['started_at'] ?? null;

        return is_float($startedAt) ? $startedAt : null;
    }

    public static function recordPoolAcquire(
        string $pool,
        float $waitMs,
        bool $created,
        bool $pinged,
        bool $exhausted = false
    ): void {
        if (!self::$enabled || !self::inCoroutine()) {
            return;
        }

        $ownerContext = self::resolveOwnerContext();
        if ($ownerContext === null || !isset($ownerContext[self::CONTEXT_DATA_KEY])) {
            return;
        }

        $data = $ownerContext[self::CONTEXT_DATA_KEY];
        $data['pool_summary']['acquires']++;
        $data['pool_summary']['total_wait_ms'] += $waitMs;
        if ($created) {
            $data['pool_summary']['created']++;
        }
        if ($pinged) {
            $data['pool_summary']['pinged']++;
        }
        if ($exhausted) {
            $data['pool_summary']['exhausted']++;
        }

        if (count($data['pool_events']) < self::MAX_POOL_EVENTS) {
            $data['pool_events'][] = [
                'action' => $exhausted ? 'exhausted' : 'acquire',
                'pool' => $pool,
                'cid' => Coroutine::getCid(),
                'wait_ms' => round($waitMs, 3),
                'created' => $created,
                'pinged' => $pinged,
                'time' => microtime(true),
            ];
        } else {
            $data['pool_events_truncated']++;
        }

        $ownerContext[self::CONTEXT_DATA_KEY] = $data;
    }

    public static function recordPoolRelease(string $pool, bool $discarded = false): void
    {
        if (!self::$enabled || !self::inCoroutine()) {
            return;
        }

        $ownerContext = self::resolveOwnerContext();
        if ($ownerContext === null || !isset($ownerContext[self::CONTEXT_DATA_KEY])) {
            return;
        }

        $data = $ownerContext[self::CONTEXT_DATA_KEY];
        if ($discarded) {
            $data['pool_summary']['discards']++;
        } else {
            $data['pool_summary']['releases']++;
        }

        if (count($data['pool_events']) < self::MAX_POOL_EVENTS) {
            $data['pool_events'][] = [
                'action' => $discarded ? 'discard' : 'release',
                'pool' => $pool,
                'cid' => Coroutine::getCid(),
                'wait_ms' => 0.0,
                'created' => false,
                'pinged' => false,
                'time' => microtime(true),
            ];
        } else {
            $data['pool_events_truncated']++;
        }

        $ownerContext[self::CONTEXT_DATA_KEY] = $data;
    }

    public static function recordConcurrentBatch(int $taskCount, int $concurrency, float $durationMs): void
    {
        if (!self::$enabled || !self::inCoroutine()) {
            return;
        }

        $ownerContext = self::resolveOwnerContext();
        if ($ownerContext === null || !isset($ownerContext[self::CONTEXT_DATA_KEY])) {
            return;
        }

        $data = $ownerContext[self::CONTEXT_DATA_KEY];
        $data['concurrent_summary']['batches']++;
        $data['concurrent_summary']['total_tasks'] += $taskCount;
        $effectiveConcurrency = $concurrency > 0 ? min($taskCount, $concurrency) : $taskCount;
        if ($effectiveConcurrency > $data['concurrent_summary']['max_concurrency']) {
            $data['concurrent_summary']['max_concurrency'] = $effectiveConcurrency;
        }
        $data['concurrent_summary']['total_duration_ms'] += $durationMs;

        if (count($data['concurrent_batches']) < self::MAX_CONCURRENT_BATCHES) {
            $data['concurrent_batches'][] = [
                'cid' => Coroutine::getCid(),
                'tasks' => $taskCount,
                'concurrency_limit' => $concurrency,
                'duration_ms' => round($durationMs, 3),
                'time' => microtime(true),
            ];
        }

        $ownerContext[self::CONTEXT_DATA_KEY] = $data;
    }

    public static function recordRedisCommand(
        string $command,
        float $durationMs,
        bool $sticky,
        int $retries,
        bool $failed = false
    ): void {
        if (!self::$enabled || !self::inCoroutine()) {
            return;
        }

        $ownerContext = self::resolveOwnerContext();
        if ($ownerContext === null || !isset($ownerContext[self::CONTEXT_DATA_KEY])) {
            return;
        }

        $data = $ownerContext[self::CONTEXT_DATA_KEY];
        $data['redis_summary']['count']++;
        $data['redis_summary']['total_duration_ms'] += $durationMs;
        $data['redis_summary']['retries'] += $retries;
        if ($failed) {
            $data['redis_summary']['failed']++;
        }
        if ($sticky) {
            $data['redis_summary']['sticky_commands']++;
        }

        if (count($data['redis_commands']) < self::MAX_REDIS_COMMANDS) {
            $data['redis_commands'][] = [
                'command' => $command,
                'cid' => Coroutine::getCid(),
                'duration_ms' => round($durationMs, 3),
                'sticky' => $sticky,
                'retries' => $retries,
                'failed' => $failed,
                'time' => microtime(true),
            ];
        } else {
            $data['redis_commands_truncated']++;
        }

        $ownerContext[self::CONTEXT_DATA_KEY] = $data;
    }

    public static function recordSessionActivity(string $mode, ?string $sessionId, bool $dirty, int $keyCount): void
    {
        if (!self::$enabled || !self::inCoroutine()) {
            return;
        }

        $ownerContext = self::resolveOwnerContext();
        if ($ownerContext === null || !isset($ownerContext[self::CONTEXT_DATA_KEY])) {
            return;
        }

        $maskedId = null;
        if (is_string($sessionId) && $sessionId !== '') {
            $maskedId = strlen($sessionId) > 8
                ? substr($sessionId, 0, 4) . '…' . substr($sessionId, -4)
                : $sessionId;
        }

        $data = $ownerContext[self::CONTEXT_DATA_KEY];
        $data['session'] = [
            'mode' => $mode,
            'session_id_masked' => $maskedId,
            'dirty' => $dirty,
            'key_count' => $keyCount,
        ];
        $ownerContext[self::CONTEXT_DATA_KEY] = $data;
    }

    /**
     * Captures the final per-request telemetry snapshot for SwoolePanel::save().
     *
     * @return array<string, mixed>
     */
    public static function snapshotRequest(): array
    {
        if (!self::inCoroutine()) {
            return [];
        }

        $ownerContext = self::resolveOwnerContext();
        $data = $ownerContext[self::CONTEXT_DATA_KEY] ?? null;
        if (!is_array($data)) {
            return [];
        }

        $now = microtime(true);
        $memoryEnd = memory_get_usage();
        $memoryRealEnd = memory_get_usage(true);
        $coStats = Coroutine::stats();
        $switchEnd = isset($coStats['coroutine_switch_num']) ? (int) $coStats['coroutine_switch_num'] : null;

        $data['ended_at'] = $now;
        $data['duration_ms'] = round(max(0.0, ($now - (float) $data['started_at']) * 1000.0), 3);
        $data['memory_end'] = $memoryEnd;
        $data['memory_delta'] = $memoryEnd - (int) $data['memory_start'];
        $data['memory_real_end'] = $memoryRealEnd;
        $data['memory_real_delta'] = $memoryRealEnd - (int) $data['memory_real_start'];
        $data['memory_peak'] = memory_get_peak_usage(true);
        $data['switch_delta'] = ($switchEnd !== null && $data['switch_start'] !== null)
            ? max(0, $switchEnd - (int) $data['switch_start'])
            : null;
        $data['pool_summary']['total_wait_ms'] = round((float) $data['pool_summary']['total_wait_ms'], 3);
        $data['concurrent_summary']['total_duration_ms'] = round((float) $data['concurrent_summary']['total_duration_ms'], 3);
        $data['redis_summary']['total_duration_ms'] = round((float) $data['redis_summary']['total_duration_ms'], 3);

        return $data;
    }

    public static function clear(): void
    {
        if (!self::inCoroutine()) {
            return;
        }

        $context = Coroutine::getContext();
        unset($context[self::CONTEXT_DATA_KEY], $context[self::CONTEXT_OWNER_CID_KEY]);
    }

    private static function resolveOwnerContext(): ?Coroutine\Context
    {
        $context = Coroutine::getContext();
        if (isset($context[self::CONTEXT_DATA_KEY])) {
            return $context;
        }

        $ownerCid = $context[self::CONTEXT_OWNER_CID_KEY] ?? null;
        if (is_int($ownerCid) && $ownerCid >= 0 && Coroutine::exists($ownerCid)) {
            $ownerContext = Coroutine::getContext($ownerCid);
            if ($ownerContext !== null && isset($ownerContext[self::CONTEXT_DATA_KEY])) {
                return $ownerContext;
            }
        }

        return null;
    }

    private static function inCoroutine(): bool
    {
        return extension_loaded('swoole') && Coroutine::getCid() >= 0;
    }
}
