<?php

declare(strict_types=1);

namespace yii2swoole\server;

use yii2swoole\coroutine\Container;
use yii2swoole\coroutine\Telemetry;
use yii2swoole\log\CoroutineTargetInterface;
use yii2swoole\log\Logger;
use yii2swoole\pool\PoolAwareInterface;
use yii2swoole\pool\PoolRegistry;
use yii2swoole\web\Application;
use yii2swoole\web\ErrorHandler;
use yii2swoole\web\RequestContext;
use yii2swoole\web\Response;
use yii2swoole\web\UploadedFile;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Throwable;
use Yii;
use yii\base\Application as BaseApplication;
use yii\base\InvalidConfigException;

/**
 * Worker-scoped HTTP request lifecycle orchestrator.
 *
 * Bootstraps one Web\Application per Swoole worker (inside onWorkerStart),
 * warms up shared components & prototypes, and handles every incoming Swoole
 * request with strict coroutine isolation and deterministic cleanup.
 */
final class RequestHandler
{
    private ?Application $application = null;

    private ?Channel $bootstrapLock = null;

    /** @var array<string, mixed>|null */
    private ?array $configArray = null;

    private int $totalRequests = 0;

    private int $activeRequests = 0;

    /**
     * @param string|array<string, mixed> $webConfig Path to Yii2 web config file or config array
     */
    public function __construct(
        private readonly string|array $webConfig,
        private readonly ?string $serverHeader = null,
        private readonly bool $sendRequestIdHeader = false
    ) {
    }

    /**
     * Bootstraps the worker application exactly once (post-fork), serialized by a
     * coroutine singleflight lock so early concurrent requests wait cleanly instead
     * of re-entering bootstrap() when warmup() yields on Redis/DB I/O.
     */
    public function bootstrap(): Application
    {
        if ($this->application !== null) {
            return $this->application;
        }

        if (Coroutine::getCid() < 0) {
            return $this->doBootstrap();
        }

        if ($this->bootstrapLock === null) {
            $this->bootstrapLock = new Channel(1);
            $this->bootstrapLock->push(true);
        }

        $token = $this->bootstrapLock->pop(30.0);
        if ($token === false) {
            throw new \RuntimeException('Timed out waiting for worker application bootstrap.');
        }

        try {
            if ($this->application !== null) {
                return $this->application;
            }

            return $this->doBootstrap();
        } finally {
            $this->bootstrapLock->push($token);
        }
    }

    private function doBootstrap(): Application
    {
        $config = $this->loadConfig();
        $class = $config['class'] ?? Application::class;
        if (!is_string($class) || !is_a($class, Application::class, true)) {
            throw new InvalidConfigException(sprintf(
                'Web application class "%s" must be %s or a subclass.',
                (string) $class,
                Application::class
            ));
        }
        unset($config['class']);

        // Install coroutine-safe DI container and logger before instantiating Application
        Yii::$container = new Container();
        Yii::setLogger(new Logger());

        /** @var Application $app */
        $app = new $class($config);
        Yii::$app = $app;

        $app->validateCoroutineConfiguration();
        $app->warmup();

        // Prewarm any pools configured with minIdle > 0 on shared connection components
        $this->prewarmConfiguredPools($app);

        // Release any temporary connections acquired by warmup() on the bootstrap coroutine
        $this->releaseWarmupConnections($app);

        return $this->application = $app;
    }

    public function getApplication(): Application
    {
        return $this->application ?? $this->bootstrap();
    }

    public function handle(SwooleRequest $swooleRequest, SwooleResponse $swooleResponse): void
    {
        $app = $this->application ?? $this->bootstrap();

        $this->totalRequests++;
        $this->activeRequests++;

        if ($this->serverHeader !== null) {
            $swooleResponse->header('Server', $this->serverHeader);
        }

        $context = RequestContext::start($swooleRequest, $swooleResponse);
        if ($this->sendRequestIdHeader) {
            $swooleResponse->header('X-Request-Id', $context->getRequestId());
        }
        if (Telemetry::isEnabled()) {
            Telemetry::beginRequest($context->startedAt, $context->getRequestId());
        }

        $initialObLevel = ob_get_level();

        try {
            [$request, $response] = $app->beginCoroutineRequest();
            $request->populateFromSwoole($swooleRequest);
            $response->bindSwooleResponse($swooleResponse);

            if ($app->hasEventHandlers(BaseApplication::EVENT_BEFORE_REQUEST)) {
                $app->state = BaseApplication::STATE_BEFORE_REQUEST;
                $app->trigger(BaseApplication::EVENT_BEFORE_REQUEST);
                $app->state = BaseApplication::STATE_HANDLING_REQUEST;
            }

            $handledResponse = $app->handleRequest($request);

            if ($handledResponse !== $response && $handledResponse instanceof Response && $handledResponse->getSwooleResponse() === null) {
                $handledResponse->bindSwooleResponse($swooleResponse);
            }

            if ($app->hasEventHandlers(BaseApplication::EVENT_AFTER_REQUEST)) {
                $app->state = BaseApplication::STATE_AFTER_REQUEST;
                $app->trigger(BaseApplication::EVENT_AFTER_REQUEST);
            }

            $handledResponse->send();
        } catch (Throwable $exception) {
            $this->handleException($app, $exception, $swooleResponse);
        } finally {
            $this->cleanup($app, $initialObLevel);
            $this->activeRequests = max(0, $this->activeRequests - 1);
        }
    }

    /**
     * Stops background periodic timers/workers during onWorkerExit while keeping
     * connection pools open for any in-flight requests to finish cleanly.
     */
    public function stopBackgroundTasks(): void
    {
        if ($this->application !== null && $this->application->has('log')) {
            try {
                $log = $this->application->getLog();
                $log->getLogger()->flush(true);
                foreach ($log->targets as $target) {
                    if (method_exists($target, 'shutdown')) {
                        $target->shutdown();
                    }
                }
            } catch (Throwable $e) {
                error_log('[yii2-swoole] Error stopping log targets on WorkerExit: ' . $e->getMessage());
            }
        }
    }

    /**
     * Gracefully flushes remaining logs and closes all connection pools on WorkerStop
     * after all in-flight request coroutines have finished.
     */
    public function shutdown(): void
    {
        $this->stopBackgroundTasks();
        PoolRegistry::shutdownAll();

        if ($this->bootstrapLock !== null) {
            $this->bootstrapLock->close();
            $this->bootstrapLock = null;
        }
    }

    /**
     * @return array{total_requests:int,active_requests:int}
     */
    public function getStats(): array
    {
        return [
            'total_requests' => $this->totalRequests,
            'active_requests' => $this->activeRequests,
        ];
    }

    private function handleException(Application $app, Throwable $exception, SwooleResponse $swooleResponse): void
    {
        try {
            $errorHandler = $app->getErrorHandler();
            if ($errorHandler instanceof ErrorHandler) {
                $errorHandler->handleException($exception);
                return;
            }
        } catch (Throwable $handlerError) {
            Yii::error($handlerError, __METHOD__);
        }

        Yii::error($exception, __METHOD__);
        if ($swooleResponse->isWritable()) {
            $isDebug = defined('YII_DEBUG') && YII_DEBUG;
            $swooleResponse->status(500);
            $swooleResponse->header('Content-Type', 'text/plain; charset=UTF-8');
            $swooleResponse->end($isDebug ? (string) $exception : 'Internal Server Error');
        }
    }

    private function cleanup(Application $app, int $initialObLevel): void
    {
        $this->discardLeakedOutputBuffers($initialObLevel);

        if (Logger::hasCoroutineActivity() || Telemetry::isEnabled()) {
            try {
                $this->flushCoroutineLogger($app);
            } catch (Throwable $e) {
                error_log('[yii2-swoole] Error flushing coroutine logger: ' . $e->getMessage());
            }
        }

        $app->clearCoroutineContext();
        UploadedFile::reset();
        Telemetry::clear();
        RequestContext::clear();
    }

    private function discardLeakedOutputBuffers(int $targetLevel): void
    {
        while (ob_get_level() > $targetLevel) {
            if (!@ob_end_clean()) {
                @ob_clean();
                break;
            }
        }
    }

    private function flushCoroutineLogger(Application $app): void
    {
        if (!$app->has('log')) {
            return;
        }

        $log = $app->getLog();
        $logger = $log->getLogger();

        if ($logger instanceof Logger) {
            $logger->flush(true);
            foreach ($log->targets as $target) {
                if ($target instanceof CoroutineTargetInterface) {
                    $target->clearCoroutineMessages();
                }
            }
            Logger::clearCoroutineMessages();
        }
    }

    private function prewarmConfiguredPools(Application $app): void
    {
        foreach ($app->sharedComponentIds as $id) {
            if (!$app->has($id, true)) {
                continue;
            }
            $component = $app->get($id, false);
            if ($component instanceof PoolAwareInterface) {
                try {
                    $component->getPool()->prewarm();
                } catch (Throwable $e) {
                    Yii::warning("Failed to prewarm pool for component '{$id}': {$e->getMessage()}", __METHOD__);
                }
            }
        }
    }

    private function releaseWarmupConnections(Application $app): void
    {
        foreach ($app->sharedComponentIds as $id) {
            if (!$app->has($id, true)) {
                continue;
            }
            $component = $app->get($id, false);
            if ($component instanceof PoolAwareInterface && method_exists($component, 'close')) {
                try {
                    $component->close();
                } catch (Throwable) {
                    // Best-effort cleanup after warmup.
                }
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function loadConfig(): array
    {
        if ($this->configArray !== null) {
            return $this->configArray;
        }

        if (is_array($this->webConfig)) {
            return $this->configArray = $this->webConfig;
        }

        if (!is_file($this->webConfig)) {
            throw new InvalidConfigException("Web application config file not found: {$this->webConfig}");
        }

        $loaded = require $this->webConfig;
        if (!is_array($loaded)) {
            throw new InvalidConfigException("Web application config file must return an array: {$this->webConfig}");
        }

        return $this->configArray = $loaded;
    }
}
