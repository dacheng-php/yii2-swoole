<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Server;

use Swoole\Coroutine;

use Yii;
use yii\di\Instance;
use yii\base\Component;
use Swoole\Http\Request;
use Swoole\Http\Response;
use yii\base\BootstrapInterface;
use yii\base\InvalidConfigException;
use Dacheng\Yii2\Swoole\Console\SwooleController;
use Swoole\Coroutine\Http\Server as SwooleCoroutineHttpServer;

class HttpServer extends Component implements BootstrapInterface
{
    public const EVENT_BEFORE_START = 'beforeStart';

    public const EVENT_AFTER_START = 'afterStart';

    public const EVENT_BEFORE_STOP = 'beforeStop';

    public const EVENT_AFTER_STOP = 'afterStop';

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
     * @var string|null Document root for static files (default: @webroot)
     */
    public ?string $documentRoot = '@app/web';

    /**
     * @var array Static file extensions and their MIME types
     */
    public array $staticFileExtensions = [
        'css' => 'text/css',
        'js' => 'application/javascript',
        'json' => 'application/json',
        'xml' => 'application/xml',
        'map' => 'application/json',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'eot' => 'application/vnd.ms-fontobject',
    ];

    /**
     * @var string|null Custom server header value (default: null to use Swoole's default)
     */
    public ?string $serverHeader = null;

    public string $memoryLimit = '512M';

    /**
     * @var array<string, string> Custom class map for overriding Yii2 core classes
     * Example: ['yii\helpers\ArrayHelper' => '/path/to/custom/ArrayHelper.php']
     */
    public array $classMap = [];

    private ?SwooleCoroutineHttpServer $server = null;

    private bool $isRunning = false;

    private ?SignalHandler $signalHandler = null;

    private int $activeRequests = 0;

    private ?string $realDocRoot = null;

    /**
     * getCommandId
     * @return string
     * @throws InvalidConfigException
     */
    protected function getCommandId()
    {
        foreach (Yii::$app->getComponents(false) as $id => $component) {
            if ($component === $this) {
                return $id;
            }
        }
        throw new InvalidConfigException('Swoole Http Server must be an application component.');
    }

    /**
     * bootstrap
     * @param $app
     * @throws InvalidConfigException
     */
    public function bootstrap($app): void
    {
        if ($memoryLimit = getenv('SWOOLE_MEMORY_LIMIT') ?: $this->memoryLimit) {
            ini_set('memory_limit', $memoryLimit);
        }

        Yii::setAlias('@dacheng/swoole', dirname(__DIR__));

        // Apply custom class map to override Yii2 core classes
        if (!empty($this->classMap)) {
            foreach ($this->classMap as $className => $classFile) {
                // Resolve Yii aliases to actual paths
                $resolvedPath = Yii::getAlias($classFile);
                Yii::$classMap[$className] = $resolvedPath;
            }
        }

        $componentId = $this->getCommandId();

        if (!$app->has($componentId)) {
            return;
        }

        $component = $app->get($componentId, false);
        if (!$component instanceof HttpServer) {
            throw new InvalidConfigException(sprintf(
                'Component "%s" must be instance of %s, %s given.',
                $componentId,
                HttpServer::class,
                is_object($component) ? get_class($component) : gettype($component)
            ));
        }

        if ($app instanceof \yii\console\Application) {
            $controllerMap = $app->controllerMap;
            if (!isset($controllerMap['swoole'])) {
                $app->controllerMap['swoole'] = [
                    'class' => SwooleController::class,
                    'serverComponent' => $componentId,
                ];
            }
        }
    }

    public function init(): void
    {
        parent::init();

        $this->settings = array_merge($this->settings,[
            'open_tcp_nodelay' => true,
            'tcp_fastopen' => true,
            'max_coroutine' => 100000,
            'log_level' => YII_DEBUG ? SWOOLE_LOG_DEBUG : SWOOLE_LOG_WARNING,
        ]);

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

        // Pre-compute real document root path if static file serving is enabled
        if ($this->documentRoot !== null) {
            $this->documentRoot = Yii::getAlias($this->documentRoot);
            $this->realDocRoot = realpath($this->documentRoot);
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

        // Initialize signal handler
        $this->signalHandler = new SignalHandler();
        $this->setupShutdownCallbacks();
        $this->signalHandler->register();

        try {
            Coroutine\run(function () use ($factory, $host, $port, $settings, $dispatcher, $afterStartEvent, $afterStopEvent): void {
            $this->server = $factory($host, $port);

            // Apply relevant settings for coroutine server
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
            if (!empty($coroutineSettings)) {
                $this->server->set($coroutineSettings);
            }

            $server = $this->server;

            $afterStartEvent();

            $this->server->handle('/', function (Request $request, Response $response) use ($dispatcher, $server): void {
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

                // Track active requests
                $this->activeRequests++;
                
                try {
                    // Try to serve static files first
                    if ($this->tryServeStaticFile($request, $response)) {
                        return;
                    }

                    $dispatcher->dispatch($request, $response, $server);
                } catch (\Throwable $e) {
                    Yii::error($e,'SwooleDispatchException');
                    if (method_exists($response, 'isWritable') && !$response->isWritable()) {
                        return;
                    }

                    $response->status(500);
                    $response->header('Content-Type', 'text/plain; charset=UTF-8');
                    $body = defined('YII_DEBUG') && YII_DEBUG ? (string) $e : 'Internal Server Error';
                    $response->end($body);
                } finally {
                    $this->activeRequests--;
                }
            });

            try {
                $this->server->start();
            } finally {
                // Cleanup signal handler
                if ($this->signalHandler) {
                    $this->signalHandler->unregister();
                }
                $afterStopEvent();
            }
            });
        } catch (\Swoole\ExitException $e) {
            Yii::error($e,'SwooleExitException');
            // Swoole exit is expected during graceful shutdown
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
                fn() => $this->activeRequests > 0,
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
     * Try to serve static file if the request matches a static file extension
     *
     * @param Request $request
     * @param Response $response
     * @return bool True if static file was served, false otherwise
     */
    private function tryServeStaticFile(Request $request, Response $response): bool
    {
        // Skip static file serving if documentRoot is not configured
        if ($this->documentRoot === null) {
            return false;
        }
        
        $uri = $request->server['request_uri'] ?? '/';
        
        // Remove query string
        if (($pos = strpos($uri, '?')) !== false) {
            $uri = substr($uri, 0, $pos);
        }
        
        // Remove fragment
        if (($pos = strpos($uri, '#')) !== false) {
            $uri = substr($uri, 0, $pos);
        }
        
        // Decode URL-encoded characters to prevent encoded path traversal attacks
        $uri = rawurldecode($uri);
        
        // Security: Check for null bytes and reject
        if (strpos($uri, "\0") !== false) {
            return false;
        }
        
        // Normalize path separators and remove consecutive slashes
        $uri = preg_replace('#/+#', '/', $uri);
        
        // Security: Reject URIs containing path traversal patterns
        if (strpos($uri, '..') !== false) {
            return false;
        }
        
        // Get file extension
        $extension = pathinfo($uri, PATHINFO_EXTENSION);
        
        // Check if it's a static file extension we handle
        if (empty($extension) || !isset($this->staticFileExtensions[$extension])) {
            return false;
        }
        
        // Construct file path
        $filePath = rtrim($this->documentRoot, '/') . '/' . ltrim($uri, '/');
        
        // Security check: ensure the file path is within document root
        if ($this->realDocRoot === false || $this->realDocRoot === null) {
            return false;
        }
        
        $realPath = realpath($filePath);
        if ($realPath === false) {
            return false;
        }
        
        // Security: Ensure realPath is within documentRoot with proper directory boundary check
        $realDocRootWithSeparator = rtrim($this->realDocRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (strpos($realPath . DIRECTORY_SEPARATOR, $realDocRootWithSeparator) !== 0) {
            return false;
        }
        
        // Check if file exists and is readable
        if (!is_file($realPath) || !is_readable($realPath)) {
            return false;
        }
        
        // Read file content
        $content = file_get_contents($realPath);
        if ($content === false) {
            return false;
        }
        
        // Set response headers
        $response->status(200);
        $response->header('Content-Type', $this->staticFileExtensions[$extension]);
        $response->header('X-Content-Type-Options', 'nosniff');
        
        // Add cache headers for static files
        $lastModified = filemtime($realPath);
        $response->header('Last-Modified', gmdate('D, d M Y H:i:s', $lastModified) . ' GMT');
        $response->header('Cache-Control', 'public, max-age=86400'); // 1 day
        
        // Check if client has cached version
        $ifModifiedSince = $request->header['if-modified-since'] ?? null;
        if ($ifModifiedSince !== null && strtotime($ifModifiedSince) >= $lastModified) {
            $response->status(304);
            $response->end();
            return true;
        }
        
        $response->end($content);
        return true;
    }

}
