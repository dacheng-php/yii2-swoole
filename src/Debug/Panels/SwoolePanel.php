<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Debug\Panels;

use Dacheng\Yii2\Swoole\Coroutine\Container;
use Dacheng\Yii2\Swoole\Coroutine\ResettableInterface;
use Dacheng\Yii2\Swoole\Coroutine\Telemetry;
use Dacheng\Yii2\Swoole\Log\FileTarget;
use Dacheng\Yii2\Swoole\Pool\PoolRegistry;
use Dacheng\Yii2\Swoole\Redis\Session as SwooleRedisSession;
use Dacheng\Yii2\Swoole\Web\Application as SwooleWebApplication;
use Dacheng\Yii2\Swoole\Web\RequestContext;
use Swoole\Coroutine;
use Yii;
use yii\debug\Panel;

/**
 * Swoole 6 runtime, coroutine, connection pool, Redis/Session, and worker telemetry panel.
 */
class SwoolePanel extends Panel
{
    /**
     * Per-request memory delta warning threshold in bytes (defaults to 2 MB).
     */
    public int $memoryDeltaWarningBytes = 2_097_152;

    public function getName(): string
    {
        return 'Swoole';
    }

    public function getSummary(): string
    {
        return Yii::$app->getView()->renderFile(
            dirname(__DIR__) . '/views/panels/swoole/summary.php',
            ['panel' => $this]
        );
    }

    public function getDetail(): string
    {
        return Yii::$app->getView()->renderFile(
            dirname(__DIR__) . '/views/panels/swoole/detail.php',
            ['panel' => $this]
        );
    }

    public function save(): array
    {
        $requestTelemetry = Telemetry::snapshotRequest();
        $runtimeSnapshot = Telemetry::getRuntimeSnapshot();

        if ($runtimeSnapshot === []) {
            $runtimeSnapshot = [
                'php_version' => PHP_VERSION,
                'swoole_version' => extension_loaded('swoole') ? phpversion('swoole') : null,
                'config' => [],
                'worker' => [
                    'id' => null,
                    'pid' => getmypid(),
                    'uptime_seconds' => 0.0,
                    'memory_mb' => round(memory_get_usage(true) / 1048576, 2),
                    'memory_peak_mb' => round(memory_get_peak_usage(true) / 1048576, 2),
                ],
                'requests' => ['total_requests' => 0, 'active_requests' => 0],
                'coroutine' => extension_loaded('swoole') ? Coroutine::stats() : [],
                'server' => [],
                'pools' => PoolRegistry::stats(),
            ];
        }

        if ($requestTelemetry === [] && extension_loaded('swoole') && Coroutine::getCid() >= 0) {
            $ctx = RequestContext::current();
            $cid = Coroutine::getCid();
            $requestTelemetry = [
                'request_id' => $ctx?->getRequestId(),
                'cid' => $cid,
                'parent_cid' => Coroutine::getPcid($cid),
                'duration_ms' => $ctx !== null ? round($ctx->elapsedMs(), 3) : 0.0,
                'memory_start' => memory_get_usage(),
                'memory_end' => memory_get_usage(),
                'memory_delta' => 0,
                'memory_peak' => memory_get_peak_usage(true),
                'switch_delta' => null,
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

        $appInfo = $this->collectApplicationInfo();
        $sessionInfo = $this->collectSessionInfo($requestTelemetry);
        $logWorkers = $this->collectLogWorkers();

        $payload = [
            'request' => $requestTelemetry,
            'runtime' => $runtimeSnapshot,
            'app' => $appInfo,
            'session' => $sessionInfo,
            'log_workers' => $logWorkers,
        ];
        $payload['alerts'] = $this->buildAlerts($payload);

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function collectApplicationInfo(): array
    {
        $scopedComponents = [];
        $sharedCount = 0;

        if (Yii::$app instanceof SwooleWebApplication) {
            $sharedCount = count(Yii::$app->sharedComponentIds);
            foreach (Yii::$app->getCoroutineComponentStore() as $id => $component) {
                $scopedComponents[] = [
                    'id' => (string) $id,
                    'class' => is_object($component) ? get_class($component) : gettype($component),
                    'resettable' => $component instanceof ResettableInterface,
                ];
            }
        }

        $diCoroutineSingletons = Yii::$container instanceof Container
            ? Yii::$container->getCoroutineSingletonCount()
            : 0;

        return [
            'scoped_components' => $scopedComponents,
            'shared_component_count' => $sharedCount,
            'di_coroutine_singletons' => $diCoroutineSingletons,
        ];
    }

    /**
     * @param array<string, mixed> $requestTelemetry
     * @return array<string, mixed>|null
     */
    private function collectSessionInfo(array $requestTelemetry): ?array
    {
        if (!empty($requestTelemetry['session']) && is_array($requestTelemetry['session'])) {
            return $requestTelemetry['session'];
        }

        if (Yii::$app !== null && Yii::$app->has('session', true)) {
            $session = Yii::$app->get('session', false);
            if ($session instanceof SwooleRedisSession) {
                return $session->getDebugInfo();
            }
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function collectLogWorkers(): array
    {
        if (Yii::$app === null || !Yii::$app->has('log', true)) {
            return [];
        }

        $workers = [];
        foreach (Yii::$app->getLog()->targets as $name => $target) {
            if ($target instanceof FileTarget) {
                $worker = $target->getWorker();
                $stats = $worker !== null
                    ? $worker->getStats()
                    : ['buffered' => 0, 'dropped' => 0, 'max_buffer_size' => 0, 'running' => false];
                $workers[] = [
                    'target' => (string) $name,
                    'log_file' => (string) $target->logFile,
                    'buffered' => $stats['buffered'],
                    'dropped' => $stats['dropped'],
                    'max_buffer_size' => $stats['max_buffer_size'],
                    'running' => $stats['running'],
                ];
            }
        }

        return $workers;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, array{level:'warning'|'error',message:string}>
     */
    private function buildAlerts(array $payload): array
    {
        $alerts = [];
        $req = $payload['request'] ?? [];
        $poolSummary = $req['pool_summary'] ?? [];

        if (($poolSummary['exhausted'] ?? 0) > 0) {
            $alerts[] = [
                'level' => 'error',
                'message' => sprintf('Connection pool exhausted %d time(s) during this request.', (int) $poolSummary['exhausted']),
            ];
        }
        if (($poolSummary['discards'] ?? 0) > 0) {
            $alerts[] = [
                'level' => 'warning',
                'message' => sprintf('%d broken pooled connection(s) discarded during this request.', (int) $poolSummary['discards']),
            ];
        }
        if (($poolSummary['total_wait_ms'] ?? 0.0) > 50.0) {
            $alerts[] = [
                'level' => 'warning',
                'message' => sprintf('Spent %.2f ms waiting for connection pool slots.', (float) $poolSummary['total_wait_ms']),
            ];
        }

        $redisSummary = $req['redis_summary'] ?? [];
        if (($redisSummary['failed'] ?? 0) > 0) {
            $alerts[] = [
                'level' => 'error',
                'message' => sprintf('%d Redis command(s) failed.', (int) $redisSummary['failed']),
            ];
        } elseif (($redisSummary['retries'] ?? 0) > 0) {
            $alerts[] = [
                'level' => 'warning',
                'message' => sprintf('Redis socket retried %d time(s) during this request.', (int) $redisSummary['retries']),
            ];
        }

        $memDelta = (int) ($req['memory_delta'] ?? 0);
        if ($memDelta > $this->memoryDeltaWarningBytes) {
            $alerts[] = [
                'level' => 'warning',
                'message' => sprintf(
                    'Request memory grew by +%.2f MB (threshold %.2f MB); check for resident state retention.',
                    $memDelta / 1048576,
                    $this->memoryDeltaWarningBytes / 1048576
                ),
            ];
        }

        foreach ($payload['runtime']['pools'] ?? [] as $poolName => $stats) {
            if (($stats['waiters'] ?? 0) > 0) {
                $alerts[] = [
                    'level' => 'warning',
                    'message' => sprintf('Pool "%s" has %d coroutine(s) waiting for a slot.', (string) $poolName, (int) $stats['waiters']),
                ];
            }
        }

        foreach ($payload['log_workers'] ?? [] as $lw) {
            if (($lw['dropped'] ?? 0) > 0) {
                $alerts[] = [
                    'level' => 'warning',
                    'message' => sprintf('Async LogWorker "%s" has dropped %d message(s).', (string) $lw['target'], (int) $lw['dropped']),
                ];
            }
        }

        return $alerts;
    }
}
