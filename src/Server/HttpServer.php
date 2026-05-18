<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Server;

use Swoole\Atomic;
use Swoole\Coroutine;
use Swoole\Coroutine\Http\Server as SwooleCoroutineHttpServer;
use Swoole\Http\Request;
use Swoole\Http\Response;
use yii\base\Component;
use yii\base\InvalidConfigException;
use yii\di\Instance;

class HttpServer extends Component
{
    public const EVENT_BEFORE_START = 'beforeStart';

    public const EVENT_AFTER_START = 'afterStart';

    public const EVENT_BEFORE_STOP = 'beforeStop';

    public const EVENT_AFTER_STOP = 'afterStop';

    /**
     * @var StaticFileServer|null Static file server instance
     */
    private ?StaticFileServer $staticFileServer = null;

    /**
     * @var bool Whether to enable request tracking for debugging
     */
    public bool $enableRequestTracking = false;

    public string $host = '127.0.0.1';

    public int $port = 9501;

    public array $settings = [];

    /**
     * @var RequestDispatcherInterface|array|string
     */
    public $dispatcher;

    /**
     * @var callable|null
     */
    public $serverFactory;

    /**
     * @var string|null Document root for static files
     */
    public ?string $documentRoot = null;

    /**
     * @var array Static file extensions and their MIME types
     *
     * Leave empty to use StaticFileServer defaults (common web file types).
     * @see StaticFileServer::DEFAULT_MIME_TYPES
     */
    public array $staticFileExtensions = [];

    /**
     * @var string|null Custom server header value (default: null to use Swoole's default)
     */
    public ?string $serverHeader = null;

    private ?SwooleCoroutineHttpServer $server = null;

    private bool $isRunning = false;

    private ?SignalHandler $signalHandler = null;

    /**
     * @var Atomic Coroutine-safe active request counter
     */
    private ?Atomic $activeRequests = null;

    public function init(): void
    {
        parent::init();

        if (!isset($this->dispatcher)) {
            throw new InvalidConfigException('Property "dispatcher" must be set.');
        }

        $this->dispatcher = Instance::ensure($this->dispatcher, RequestDispatcherInterface::class);

        if ($this->serverFactory === null) {
            $this->serverFactory = static function (string $host, int $port): SwooleCoroutineHttpServer {
                return new SwooleCoroutineHttpServer($host, $port, false, true);
            };
        }

        if (!is_callable($this->serverFactory)) {
            throw new InvalidConfigException('Property "serverFactory" must be a valid callable.');
        }

        // Initialize static file server if document root is configured
        if ($this->documentRoot !== null) {
            $this->staticFileServer = new StaticFileServer(
                $this->documentRoot,
                empty($this->staticFileExtensions) ? StaticFileServer::DEFAULT_MIME_TYPES : $this->staticFileExtensions
            );
        }
    }

    /**
     * Starts swoole coroutine http server.
     *
     * This triggers events: beforeStart, afterStart, afterStop.
     * This runs in a single process using coroutines for concurrency.
     * This delegates swoole request to yii2 request through Dispatcher.
     */
    public function start(): void
    {
        if ($this->isRunning) {
            return;
        }

        $this->trigger(self::EVENT_BEFORE_START);

        // Set coroutine configuration
        Coroutine::set([
            'hook_flags' => SWOOLE_HOOK_ALL,
            'max_coroutine' => $this->settings['max_coroutine'] ?? 100000,
            'log_level' => $this->settings['log_level'] ?? SWOOLE_LOG_WARNING,
        ]);

        $dispatcher = $this->dispatcher;
        $host = $this->host;
        $port = $this->port;
        $settings = $this->settings;
        $factory = $this->serverFactory;
        $afterStartEvent = function () {
            $this->trigger(self::EVENT_AFTER_START);
        };
        $afterStopEvent = function () {
            $this->isRunning = false;
            $this->server = null;
            $this->trigger(self::EVENT_AFTER_STOP);
        };

        $this->isRunning = true;

        // Initialize coroutine-safe atomic counter
        $this->activeRequests = new Atomic(0);

        // Initialize signal handler
        $this->signalHandler = new SignalHandler();
        $this->setupShutdownCallbacks();
        $this->signalHandler->register();

        try {
            Coroutine\run(function () use ($factory, $host, $port, $settings, $dispatcher, $afterStartEvent, $afterStopEvent): void {
                $this->server = $factory($host, $port);
                $this->applyCoroutineServerSettings($settings);

                $server = $this->server;
                $afterStartEvent();

                $this->server->handle('/', function (Request $request, Response $response) use ($dispatcher, $server): void {
                    $this->handleRequest($request, $response, $dispatcher, $server);
                });

                try {
                    $this->server->start();
                } finally {
                    if ($this->signalHandler !== null) {
                        $this->signalHandler->unregister();
                    }
                    $afterStopEvent();
                }
            });
        } catch (\Swoole\ExitException $e) {
            // Swoole exit is expected during graceful shutdown
        }
    }

    /**
     * Applies settings supported by Swoole coroutine HTTP server.
     *
     * @param array<string, mixed> $settings
     */
    private function applyCoroutineServerSettings(array $settings): void
    {
        $coroutineSettings = [];

        if (isset($settings['backlog'])) {
            $coroutineSettings['backlog'] = $settings['backlog'];
        }
        if (isset($settings['open_tcp_nodelay'])) {
            $coroutineSettings['open_tcp_nodelay'] = $settings['open_tcp_nodelay'];
        }
        if (isset($settings['tcp_fastopen'])) {
            $coroutineSettings['open_tcp_fastopen'] = $settings['tcp_fastopen'];
        }

        if ($coroutineSettings !== []) {
            $this->server->set($coroutineSettings);
        }
    }

    /**
     * Sets up shutdown callbacks for graceful shutdown
     */
    private function setupShutdownCallbacks(): void
    {
        if (!$this->signalHandler) {
            return;
        }

        // Priority 10: Wait for in-flight requests
        $this->signalHandler->onShutdown('wait_requests', function () {
            $this->signalHandler->waitForInflightRequests(
                fn() => $this->activeRequests !== null && $this->activeRequests->get() > 0,
                5.0
            );
        }, 10);

        // Priority 20: Stop accepting new requests (shutdown server)
        $this->signalHandler->onShutdown('stop_server', function () {
            if ($this->server) {
                $this->server->shutdown();
            }
        }, 20);

        // Priority 30: Flush logs
        $this->signalHandler->onShutdown('flush_logs', function () {
            ShutdownHelper::flushLogs(false);
        }, 30);

        // Priority 40: Close connection pools
        $this->signalHandler->onShutdown('close_pools', function () {
            ShutdownHelper::closeConnectionPools(false);
        }, 40);
    }

    /**
     * Stops swoole coroutine http server.
     *
     * This triggers event: beforeStop.
     */
    public function stop(): void
    {
        if (!$this->isRunning || $this->server === null) {
            return;
        }

        $this->trigger(self::EVENT_BEFORE_STOP);

        $this->server->shutdown();
    }

    /**
     * Handles a single HTTP request.
     *
     * This method processes each incoming request through the following steps:
     * 1. Set custom server header (if configured)
     * 2. Check for shutdown request
     * 3. Initialize request context for debugging
     * 4. Track active request count
     * 5. Serve static files or dispatch to Yii2
     * 6. Handle errors and cleanup
     *
     * @param Request $request The Swoole HTTP request
     * @param Response $response The Swoole HTTP response
     * @param RequestDispatcherInterface $dispatcher The request dispatcher
     * @param SwooleCoroutineHttpServer $server The server instance
     */
    private function handleRequest(
        Request $request,
        Response $response,
        RequestDispatcherInterface $dispatcher,
        SwooleCoroutineHttpServer $server
    ): void {
        // Set custom server header if configured
        if ($this->serverHeader !== null) {
            $response->header('Server', $this->serverHeader);
        }

        // Check if shutdown is requested
        if ($this->signalHandler && $this->signalHandler->isShutdownRequested()) {
            $response->status(503);
            $response->header('Content-Type', 'text/plain; charset=UTF-8');
            $response->end('Server is shutting down');
            return;
        }

        // Initialize request context for debugging
        $requestId = $this->initializeRequestContext($request);

        // Track active requests (coroutine-safe)
        $this->activeRequests?->add(1);

        try {
            // Try to serve static files first using dedicated static file server
            if ($this->staticFileServer !== null && $this->staticFileServer->serve($request, $response)) {
                return;
            }

            // Process through middleware pipeline, then dispatch to Yii2
            $dispatcher->dispatch($request, $response, $server);
        } catch (\Throwable $e) {
            if (method_exists($response, 'isWritable') && !$response->isWritable()) {
                return;
            }

            $response->status(500);
            $response->header('Content-Type', 'text/plain; charset=UTF-8');
            $body = $this->formatErrorResponse($e, $requestId);
            $response->end($body);
        } finally {
            $this->activeRequests?->sub(1);
            $this->cleanupRequestContext();
        }
    }

    /**
     * Initializes request context for debugging.
     *
     * @param Request $request The HTTP request
     * @return string The request ID
     */
    private function initializeRequestContext(Request $request): string
    {
        if (!$this->enableRequestTracking) {
            return '';
        }

        $requestId = uniqid('req_', true);
        $context = Coroutine::getContext();
        $context['request_id'] = $requestId;
        $context['request_start'] = microtime(true);
        $context['request_uri'] = $request->server['request_uri'] ?? '/';

        return $requestId;
    }

    /**
     * Cleans up request context.
     */
    private function cleanupRequestContext(): void
    {
        if (!$this->enableRequestTracking) {
            return;
        }

        $context = Coroutine::getContext();
        unset($context['request_id'], $context['request_start'], $context['request_uri']);
    }

    /**
     * Formats error response with debugging information.
     *
     * @param \Throwable $e The exception
     * @param string $requestId The request ID
     * @return string The formatted error response
     */
    private function formatErrorResponse(\Throwable $e, string $requestId): string
    {
        if (!defined('YII_DEBUG') || !YII_DEBUG) {
            return 'Internal Server Error';
        }

        $output = "Error: {$e->getMessage()}\n\n";
        $output .= "Request ID: {$requestId}\n";
        $output .= "Coroutine ID: " . Coroutine::getCid() . "\n";

        // Add pool statistics if available
        $context = Coroutine::getContext();
        if (isset($context['request_uri'])) {
            $output .= "URI: {$context['request_uri']}\n";
        }

        $output .= "\nStack Trace:\n" . $e->getTraceAsString();

        return $output;
    }

}
