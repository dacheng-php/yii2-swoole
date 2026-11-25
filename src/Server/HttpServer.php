<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Server;

use Dacheng\Yii2\Swoole\Coroutine\Telemetry;
use Dacheng\Yii2\Swoole\Pool\PoolRegistry;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Swoole\Http\Server as SwooleHttpServer;
use Swoole\Timer;
use Throwable;
use Yii;
use yii\base\Component;
use yii\base\InvalidConfigException;

/**
 * Production-grade Swoole HTTP Server component for Yii2.
 *
 * Uses Swoole\Http\Server in SWOOLE_BASE mode by default:
 * - Maximum throughput: zero Master->Worker IPC overhead, kernel SO_REUSEPORT load-balancing.
 * - Multi-core elastic scaling: set workers=1 for single-worker containers or workers=N/auto for multi-core.
 * - Native C-level static file handler, HTTP compression, reload_async, memory high-watermark guard, and PID management.
 * - Strict fork-safety: Yii2 Application and all connection pools are bootstrapped exclusively inside onWorkerStart.
 */
class HttpServer extends Component
{
    public const EVENT_BEFORE_START = 'beforeStart';
    public const EVENT_WORKER_START = 'workerStart';
    public const EVENT_WORKER_STOP = 'workerStop';

    public string $host = '127.0.0.1';

    public int $port = 9501;

    /**
     * Swoole server mode: SWOOLE_BASE (default, lowest latency & IPC-free) or SWOOLE_PROCESS.
     */
    public int $mode = SWOOLE_BASE;

    /**
     * Number of worker processes (0 = auto based on CPU cores).
     */
    public int $workers = 0;

    /**
     * Path to Yii2 web application config file (or config array).
     *
     * @var string|array<string, mixed>|null
     */
    public string|array|null $webConfig = null;

    /**
     * Optional document root for Swoole's native C-level static file handler.
     */
    public ?string $documentRoot = null;

    /**
     * PID file path used by CLI stop/reload/status commands (defaults to @runtime/swoole-http.pid).
     */
    public ?string $pidFile = null;

    /**
     * Swoole engine log file path (defaults to @runtime/logs/swoole.log; set '' to log to stdout).
     */
    public ?string $logFile = null;

    /**
     * Swoole log rotation policy (e.g. SWOOLE_LOG_ROTATION_DAILY, or null to disable).
     */
    public ?int $logRotation = SWOOLE_LOG_ROTATION_DAILY;

    /**
     * PHP/terminal error log file path (defaults to @runtime/logs/error.log; set '' to leave PHP default).
     */
    public ?string $errorLogFile = null;

    public ?string $serverHeader = 'yii2-swoole';

    public bool $sendRequestIdHeader = false;

    public bool $enableCompression = true;

    public bool $daemonize = false;

    /**
     * Max requests per worker before automatic graceful restart (0 = disabled, recommended for
     * leak-free apps so high-QPS traffic does not trigger unnecessary worker restarts).
     */
    public int $maxRequest = 0;

    /**
     * Worker memory high-watermark in MB for graceful self-recycling (0 = disabled).
     * Checked on the worker maintenance timer (zero per-request overhead).
     * Requires a Manager process (SWOOLE_PROCESS or SWOOLE_BASE with workers > 1) to respawn.
     */
    public int $maxMemoryMb = 256;

    /**
     * Max seconds to wait for in-flight requests during graceful reload/shutdown.
     */
    public int $maxWaitTime = 30;

    public int $maxCoroutine = 100000;

    public int $hookFlags = SWOOLE_HOOK_ALL;

    /**
     * Interval in seconds to reap expired idle connections inside each worker (0 = disabled).
     */
    public float $poolReapInterval = 30.0;

    /**
     * Development mode: watch PHP source files and trigger graceful reload on changes.
     */
    public bool $watch = false;

    /**
     * Directories to monitor when $watch is enabled (defaults to @app).
     *
     * @var string[]
     */
    public array $watchDirs = [];

    /**
     * Additional native Swoole\Http\Server settings (merged last so user overrides win).
     *
     * @var array<string, mixed>
     */
    public array $settings = [];

    private ?SwooleHttpServer $server = null;

    public function init(): void
    {
        parent::init();

        if ($this->webConfig === null) {
            throw new InvalidConfigException('Property "webConfig" must be configured with a Yii2 web config file path or array.');
        }

        if (is_string($this->webConfig)) {
            $this->webConfig = Yii::getAlias($this->webConfig);
        }

        if ($this->documentRoot !== null) {
            $this->documentRoot = Yii::getAlias($this->documentRoot);
        }

        $runtimePath = Yii::$app ? Yii::$app->getRuntimePath() : sys_get_temp_dir();
        $runtimePath = rtrim($runtimePath, DIRECTORY_SEPARATOR);

        if ($this->pidFile === null) {
            $this->pidFile = $runtimePath . DIRECTORY_SEPARATOR . 'swoole-http.pid';
        } else {
            $this->pidFile = Yii::getAlias($this->pidFile);
        }

        if ($this->logFile === null) {
            $this->logFile = $runtimePath . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'swoole.log';
        } elseif ($this->logFile !== '') {
            $this->logFile = Yii::getAlias($this->logFile);
        }

        if ($this->errorLogFile === null) {
            $this->errorLogFile = $runtimePath . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'error.log';
        } elseif ($this->errorLogFile !== '') {
            $this->errorLogFile = Yii::getAlias($this->errorLogFile);
        }
    }

    public function getProcessManager(): ProcessManager
    {
        return new ProcessManager((string) $this->pidFile);
    }

    public function resolveWorkerCount(): int
    {
        if ($this->workers > 0) {
            return $this->workers;
        }

        $cpus = function_exists('swoole_cpu_num') ? swoole_cpu_num() : 1;

        return max(1, $cpus);
    }

    public function start(): void
    {
        if (!extension_loaded('swoole')) {
            throw new InvalidConfigException('The swoole PHP extension (>= 6.0) is required.');
        }

        $this->ensureDirectoryForFile((string) $this->pidFile);
        if ($this->logFile !== null && $this->logFile !== '') {
            $this->ensureDirectoryForFile($this->logFile);
        }
        $this->configureErrorLog();

        $pm = $this->getProcessManager();
        if ($pm->isRunning()) {
            throw new \RuntimeException(sprintf(
                'Swoole HTTP server is already running (PID %d, pidFile=%s).',
                (int) $pm->readPid(),
                $this->pidFile
            ));
        }
        $pm->cleanupStalePidFile();

        $this->trigger(self::EVENT_BEFORE_START);

        $workerNum = $this->resolveWorkerCount();
        $effectiveMode = $this->mode;

        // SWOOLE_BASE with worker_num=1 runs in single-process mode without a Manager process,
        // where Server::reload() fails with ERRNO 507. Switch explicitly for --watch dev mode.
        if ($this->watch && $effectiveMode === SWOOLE_BASE && $workerNum === 1) {
            $effectiveMode = SWOOLE_PROCESS;
            fwrite(
                STDOUT,
                "[yii2-swoole] Notice: --watch requires a Manager process for reload(); using SWOOLE_PROCESS for single-worker watch mode.\n"
            );
        }

        $server = new SwooleHttpServer($this->host, $this->port, $effectiveMode);
        $this->server = $server;

        $server->set($this->buildSwooleSettings($workerNum, $effectiveMode));

        $handler = null;
        $reapTimerId = null;
        $memoryWarned = false;

        $server->on('Start', function (SwooleHttpServer $srv) use ($workerNum, $effectiveMode): void {
            $modeLabel = $effectiveMode === SWOOLE_PROCESS ? 'SWOOLE_PROCESS' : 'SWOOLE_BASE';
            fwrite(STDOUT, sprintf(
                "Yii2-Swoole HTTP server started at http://%s:%d (mode=%s, workers=%d, pid=%d%s)\n",
                $this->host,
                $this->port,
                $modeLabel,
                $workerNum,
                $srv->master_pid,
                $this->watch ? ', watch=ON' : ''
            ));
        });

        $server->on('WorkerStart', function (SwooleHttpServer $srv, int $workerId) use (&$handler, &$reapTimerId, &$memoryWarned, $effectiveMode, $workerNum): void {
            $this->configureErrorLog();

            if (function_exists('opcache_reset') && $this->watch) {
                @opcache_reset();
            }

            $handler = new RequestHandler(
                webConfig: $this->webConfig,
                serverHeader: $this->serverHeader,
                sendRequestIdHeader: $this->sendRequestIdHeader
            );

            try {
                $handler->bootstrap();
                RuntimeStats::bind($srv, $handler, $workerId, [
                    'mode' => $effectiveMode === SWOOLE_PROCESS ? 'SWOOLE_PROCESS' : 'SWOOLE_BASE',
                    'workers' => $workerNum,
                    'hook_flags' => $this->hookFlags,
                    'max_coroutine' => $this->maxCoroutine,
                    'max_memory_mb' => $this->maxMemoryMb,
                    'max_request' => $this->maxRequest,
                    'watch' => $this->watch,
                ]);
                Telemetry::registerRuntimeSnapshotProvider(static fn (): array => RuntimeStats::snapshot());

                $maintenanceInterval = $this->poolReapInterval > 0
                    ? $this->poolReapInterval
                    : ($this->maxMemoryMb > 0 ? 15.0 : 0.0);

                if ($maintenanceInterval > 0) {
                    $reapTimerId = Timer::tick((int) ($maintenanceInterval * 1000), function () use ($srv, $workerId, &$memoryWarned): void {
                        if ($this->poolReapInterval > 0) {
                            PoolRegistry::reapAllIdle();
                        }

                        if ($this->maxMemoryMb > 0) {
                            $usageMb = memory_get_usage(true) / 1048576;
                            if ($usageMb >= $this->maxMemoryMb) {
                                // Only recycle worker when a Manager process exists to respawn it
                                if ($srv->manager_pid > 0) {
                                    error_log(sprintf(
                                        '[yii2-swoole] Worker #%d (pid=%d) memory %.2fMB reached limit %dMB; triggering graceful restart.',
                                        $workerId,
                                        (int) getmypid(),
                                        $usageMb,
                                        $this->maxMemoryMb
                                    ));
                                    $srv->stop($workerId);
                                } elseif (!$memoryWarned) {
                                    $memoryWarned = true;
                                    error_log(sprintf(
                                        '[yii2-swoole] Worker #%d (pid=%d) memory %.2fMB reached limit %dMB, but single-process SWOOLE_BASE has no Manager to respawn it.',
                                        $workerId,
                                        (int) getmypid(),
                                        $usageMb,
                                        $this->maxMemoryMb
                                    ));
                                }
                            }
                        }
                    });
                }

                $this->trigger(self::EVENT_WORKER_START);
            } catch (Throwable $e) {
                $msg = sprintf(
                    "[yii2-swoole] Worker #%d bootstrap failed: %s\n%s",
                    $workerId,
                    $e->getMessage(),
                    $e->getTraceAsString()
                );
                error_log($msg);
                if (!$this->daemonize) {
                    fwrite(STDERR, $msg . "\n");
                }
                $srv->shutdown();
            }
        });

        $server->on('Request', static function (SwooleRequest $request, SwooleResponse $response) use (&$handler): void {
            if ($handler === null) {
                $response->status(503);
                $response->header('Content-Type', 'text/plain; charset=UTF-8');
                $response->end('Worker not ready');
                return;
            }

            $handler->handle($request, $response);
        });

        // Clear periodic timers and stop background log workers on WorkerExit while keeping
        // connection pools alive until all in-flight requests drain.
        $server->on('WorkerExit', static function (SwooleHttpServer $srv, int $workerId) use (&$handler, &$reapTimerId): void {
            if ($reapTimerId !== null) {
                Timer::clear($reapTimerId);
                $reapTimerId = null;
            }

            if ($handler instanceof RequestHandler) {
                $handler->stopBackgroundTasks();
            }
        });

        $server->on('WorkerStop', function (SwooleHttpServer $srv, int $workerId) use (&$handler, &$reapTimerId): void {
            if ($reapTimerId !== null) {
                Timer::clear($reapTimerId);
                $reapTimerId = null;
            }

            if ($handler instanceof RequestHandler) {
                $handler->shutdown();
            }

            Telemetry::registerRuntimeSnapshotProvider(null);
            RuntimeStats::reset();
            $this->trigger(self::EVENT_WORKER_STOP);
        });

        if ($this->watch) {
            $watchDirs = $this->resolveWatchDirectories();
            if ($watchDirs !== []) {
                (new FileWatcher($watchDirs))->attach($server);
            }
        }

        $server->start();
    }

    /**
     * @return array<string, mixed>
     */
    public function buildSwooleSettings(int $workerNum, ?int $effectiveMode = null): array
    {
        $mode = $effectiveMode ?? $this->mode;

        $defaults = [
            'worker_num' => $workerNum,
            'enable_coroutine' => true,
            'hook_flags' => $this->hookFlags,
            'max_coroutine' => $this->maxCoroutine,
            'max_request' => $this->maxRequest,
            'reload_async' => true,
            'max_wait_time' => $this->maxWaitTime,
            'backlog' => 2048,
            'open_tcp_nodelay' => true,
            'http_compression' => $this->enableCompression,
            'pid_file' => (string) $this->pidFile,
            'daemonize' => $this->daemonize,
            'log_level' => (defined('YII_DEBUG') && YII_DEBUG) ? SWOOLE_LOG_INFO : SWOOLE_LOG_WARNING,
        ];

        if ($this->logFile !== null && $this->logFile !== '') {
            $defaults['log_file'] = $this->logFile;
            $defaults['log_date_format'] = '%Y-%m-%d %H:%M:%S';
            if ($this->logRotation !== null) {
                $defaults['log_rotation'] = $this->logRotation;
            }
        }

        if ($this->maxRequest > 0) {
            $defaults['max_request_grace'] = max(1, (int) ($this->maxRequest * 0.2));
        }

        if ($workerNum > 1 && $mode === SWOOLE_BASE) {
            $defaults['enable_reuse_port'] = true;
        }

        if ($this->documentRoot !== null && is_dir($this->documentRoot)) {
            $defaults['enable_static_handler'] = true;
            $defaults['document_root'] = $this->documentRoot;
        }

        return array_merge($defaults, $this->settings);
    }

    private function ensureDirectoryForFile(string $filePath): void
    {
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }

    private function configureErrorLog(): void
    {
        if ($this->errorLogFile === null || $this->errorLogFile === '') {
            return;
        }

        $this->ensureDirectoryForFile($this->errorLogFile);
        ini_set('log_errors', '1');
        ini_set('error_log', $this->errorLogFile);
    }

    /**
     * @return string[]
     */
    private function resolveWatchDirectories(): array
    {
        if ($this->watchDirs !== []) {
            return array_map(static fn (string $dir): string => Yii::getAlias($dir), $this->watchDirs);
        }

        $appPath = Yii::getAlias('@app', false);
        if (is_string($appPath) && is_dir($appPath)) {
            return [$appPath];
        }

        return is_string($this->webConfig) ? [dirname($this->webConfig, 2)] : [];
    }
}
