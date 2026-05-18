<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Server;

use Dacheng\Yii2\Swoole\Coroutine\CoroutineApplication;
use Swoole\Coroutine\Http\Server as SwooleCoroutineHttpServer;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Throwable;
use Yii;
use yii\base\ErrorHandler;
use yii\base\InvalidConfigException;
use yii\base\UserException;
use yii\web\Application;
use yii\web\Cookie;
use yii\web\CookieCollection;
use yii\web\HeaderCollection;
use yii\web\HttpException;
use yii\web\Request as YiiRequest;
use yii\web\Response as YiiResponse;
use yii\web\ResponseFormatterInterface;

/**
 * RequestDispatcher orchestrates conversion and processing of Swoole requests to Yii2.
 *
 * This class acts as a bridge between Swoole's coroutine HTTP server and Yii2's
 * request handling framework. It manages the complete request lifecycle:
 *
 * 1. Acquiring/releasing application instance
 * 2. Converting Swoole requests to Yii2 requests
 * 3. Managing global PHP state (superglobals)
 * 4. Invoking Yii2 application
 * 5. Converting Yii2 responses to Swoole responses
 * 6. Cleanup and resource management
 */
class RequestDispatcher implements RequestDispatcherInterface
{
    /**
     * @var string Path to Yii2 application configuration file
     */
    public ?string $appConfig = null;

    /**
     * @var Application|null The shared application instance
     */
    private ?Application $application = null;

    /**
     * @var array|null Cached application configuration
     */
    private ?array $applicationConfig = null;

    /**
     * @var string|null Application class name
     */
    private ?string $applicationClass = null;

    /**
     * @var string|null Entry script path
     */
    private ?string $entryScript = null;

    /**
     * @var string|null Default response format for cleanup
     */
    private ?string $defaultResponseFormat = null;

    /**
     * Creates a new RequestDispatcher.
     *
     * @param string|null $appConfig Path to Yii2 application config
     * @param array $config Additional configuration
     */
    public function __construct(?string $appConfig = null, array $config = [])
    {
        $this->appConfig = $appConfig;
    }

    /**
     * {@inheritdoc}
     */
    public function init(): void
    {
        if ($this->appConfig === null) {
            throw new InvalidConfigException('Property "appConfig" must be configured with a Yii web application config file.');
        }
    }

    /**
     * {@inheritdoc}
     *
     * This is the main entry point for request processing. Each request goes through
     * following stages:
     *
     * 1. **Setup**: Acquire application, set working directory, apply globals
     * 2. **Prepare**: Populate request, prepare logger
     * 3. **Execute**: Handle request through Yii2 application
     * 4. **Cleanup**: Return connections, clear state, restore globals
     */
    public function dispatch(Request $request, Response $response, SwooleCoroutineHttpServer $server): void
    {
        // Stage 1: Setup
        $previousApp = Yii::$app instanceof Application ? Yii::$app : null;
        $app = $this->getApplication();
        Yii::$app = $app;

        $previousCwd = $this->setWorkingDirectory($app);
        $restoreGlobals = $this->applySwooleGlobals($request, $app);

        // Stage 2: Prepare
        $this->populateRequest($app, $request);
        $this->prepareApplication($app);

        // Stage 3: Execute
        try {
            $handledResponse = $app->handleRequest($app->getRequest());
            $this->finalizeResponse($response, $handledResponse);
        } catch (Throwable $exception) {
            $this->handleRequestException($exception, $app, $response);
        } finally {
            // Stage 4: Cleanup
            $this->finallyCleanup($app, $previousApp, $previousCwd, $restoreGlobals);
        }
    }

    // ==================== Request Handling ====================

    /**
     * Populates a Yii2 request from a Swoole request.
     */
    private function populateRequest(Application $app, Request $swooleRequest): void
    {
        $yiiRequest = $app->getRequest();
        if (!$yiiRequest instanceof YiiRequest) {
            throw new InvalidConfigException('Application "request" component must be an instance of yii\\web\\Request.');
        }

        $this->setRequestProperties($yiiRequest, $swooleRequest);
        $this->setHostInfo($yiiRequest, $swooleRequest);
        $this->setUrlProperties($yiiRequest, $swooleRequest);
        $this->populateHeaders($yiiRequest, $swooleRequest);
        $this->populateCookies($yiiRequest, $swooleRequest);
        $this->populateFiles($yiiRequest, $swooleRequest);
        $this->storeSwooleRequest($app, $swooleRequest);
    }

    /**
     * Applies Swoole request data to PHP superglobals.
     *
     * @return callable Function to restore original globals
     */
    private function applySwooleGlobals(Request $request, Application $app): callable
    {
        $entryScript = $this->getEntryScriptPath($app);
        $originalGlobals = [
            '_SERVER' => $_SERVER ?? [],
            '_GET' => $_GET ?? [],
            '_POST' => $_POST ?? [],
            '_FILES' => $_FILES ?? [],
            '_COOKIE' => $_COOKIE ?? [],
            '_REQUEST' => $_REQUEST ?? [],
        ];

        $this->applyServerVars($request, $entryScript);
        $this->applyInputVars($request);

        return function () use ($originalGlobals): void {
            $_SERVER = $originalGlobals['_SERVER'];
            $_GET = $originalGlobals['_GET'];
            $_POST = $originalGlobals['_POST'];
            $_FILES = $originalGlobals['_FILES'];
            $_COOKIE = $originalGlobals['_COOKIE'];
            $_REQUEST = $originalGlobals['_REQUEST'];
        };
    }

    private function setRequestProperties(YiiRequest $request, Request $swooleRequest): void
    {
        $server = $swooleRequest->server ?? [];
        $method = strtoupper($server['request_method'] ?? 'GET');

        $request->setQueryParams($swooleRequest->get ?? []);
        $request->setBodyParams($swooleRequest->post ?? []);
        $request->setRawBody($swooleRequest->rawContent() ?: '');

        if (method_exists($request, 'setMethod')) {
            $request->setMethod($method);
        }
    }

    private function setHostInfo(YiiRequest $request, Request $swooleRequest): void
    {
        $server = $swooleRequest->server ?? [];
        $headers = $swooleRequest->header ?? [];

        $scheme = $headers['x-forwarded-proto']
            ?? (!empty($server['https']) && $server['https'] !== 'off' ? 'https' : 'http');
        $hostHeader = $headers['host'] ?? ($server['server_name'] ?? '127.0.0.1');

        $request->setHostInfo(sprintf('%s://%s', $scheme, $hostHeader));
    }

    private function setUrlProperties(YiiRequest $request, Request $swooleRequest): void
    {
        $server = $swooleRequest->server ?? [];
        $queryString = $server['query_string'] ?? '';
        $uri = $server['request_uri'] ?? '/';

        $fullUrl = $queryString === '' ? $uri : $uri . '?' . $queryString;
        $pathInfo = $uri;

        if (($pos = strpos($pathInfo, '?')) !== false) {
            $pathInfo = substr($pathInfo, 0, $pos);
        }
        $pathInfo = ltrim($pathInfo, '/');

        $request->setUrl($fullUrl);
        $request->setScriptUrl('/index.php');
        $request->setBaseUrl('');
        $request->setPathInfo($pathInfo);

        if (!Yii::getAlias('@web', false)) {
            Yii::setAlias('@web', '');
        }
    }

    private function populateHeaders(YiiRequest $request, Request $swooleRequest): void
    {
        $headers = $swooleRequest->header ?? [];
        $headerCollection = $request->getHeaders();

        if ($headerCollection instanceof HeaderCollection) {
            $headerCollection->removeAll();
            foreach ($headers as $name => $value) {
                $headerCollection->set($name, $value);
            }
        }
    }

    private function populateCookies(YiiRequest $request, Request $swooleRequest): void
    {
        $cookies = $swooleRequest->cookie ?? [];
        $cookieCollection = $request->getCookies();

        if (!$cookieCollection instanceof CookieCollection) {
            return;
        }

        $this->withWritableCookieCollection($cookieCollection, function () use ($cookieCollection, $cookies): void {
            $cookieCollection->removeAll();
            foreach ($cookies as $name => $value) {
                $cookieCollection->add(new Cookie([
                    'name' => $name,
                    'value' => $value,
                ]));
            }
        });
    }

    private function withWritableCookieCollection(CookieCollection $cookies, callable $callback): void
    {
        $originalReadOnly = $cookies->readOnly;
        $cookies->readOnly = false;

        try {
            $callback();
        } finally {
            $cookies->readOnly = $originalReadOnly;
        }
    }

    private function populateFiles(YiiRequest $request, Request $swooleRequest): void
    {
        $files = $swooleRequest->files ?? [];

        if (!empty($files)) {
            $request->setBodyParams(array_merge($request->getBodyParams(), $files));
        }
    }

    private function storeSwooleRequest(Application $app, Request $swooleRequest): void
    {
        $app->params['__swooleRequest'] = $swooleRequest;
    }

    private function applyServerVars(Request $request, string $scriptFile): void
    {
        $server = [];

        foreach ($request->server ?? [] as $key => $value) {
            $server[strtoupper($key)] = $value;
        }

        foreach ($request->header ?? [] as $key => $value) {
            $normalized = strtoupper(str_replace('-', '_', $key));
            if ($normalized === 'CONTENT_TYPE' || $normalized === 'CONTENT_LENGTH') {
                $server[$normalized] = $value;
            } else {
                $server['HTTP_' . $normalized] = $value;
            }
        }

        $server['REQUEST_METHOD'] = $server['REQUEST_METHOD'] ?? 'GET';
        $server['REQUEST_URI'] = $server['REQUEST_URI'] ?? '/';
        $server['QUERY_STRING'] = $server['QUERY_STRING']
            ?? $request->server['query_string'] ?? http_build_query($request->get ?? []);
        $server['REMOTE_ADDR'] = $server['REMOTE_ADDR'] ?? ($request->server['remote_addr'] ?? '127.0.0.1');
        $server['REMOTE_PORT'] = $server['REMOTE_PORT'] ?? ($request->server['remote_port'] ?? 0);
        $server['SERVER_PROTOCOL'] = $server['SERVER_PROTOCOL'] ?? ($request->server['server_protocol'] ?? 'HTTP/1.1');
        $server['SERVER_NAME'] = $server['SERVER_NAME'] ?? ($request->header['host'] ?? 'localhost');
        $server['SERVER_PORT'] = $server['SERVER_PORT'] ?? ($request->server['server_port'] ?? 80);
        $server['SCRIPT_FILENAME'] = $server['SCRIPT_FILENAME'] ?? $scriptFile;
        $server['SCRIPT_NAME'] = $server['SCRIPT_NAME'] ?? '/index.php';
        $server['PHP_SELF'] = $server['PHP_SELF'] ?? $server['SCRIPT_NAME'];
        $server['DOCUMENT_ROOT'] = $server['DOCUMENT_ROOT'] ?? dirname($scriptFile);

        $_SERVER = $server;
    }

    private function applyInputVars(Request $request): void
    {
        $_GET = $request->get ?? [];
        $_POST = $request->post ?? [];
        $_FILES = $request->files ?? [];
        $_COOKIE = $request->cookie ?? [];
        $_REQUEST = array_merge($_GET, $_POST);
    }

    // ==================== Response Handling ====================

    /**
     * Finalizes a Yii2 response and sends it via Swoole.
     */
    private function finalizeResponse(Response $swooleResponse, YiiResponse $yiiResponse): void
    {
        if (!$this->isResponseWritable($swooleResponse)) {
            return;
        }

        if (!$yiiResponse->isSent) {
            $yiiResponse->trigger(YiiResponse::EVENT_BEFORE_SEND);
            $this->prepareResponseBody($yiiResponse);
        }

        $this->sendHeaders($swooleResponse, $yiiResponse);
        $this->sendCookies($swooleResponse, $yiiResponse);
        $body = $this->captureResponseBody($yiiResponse);
        $swooleResponse->end($body);

        if (!$yiiResponse->isSent) {
            $yiiResponse->trigger(YiiResponse::EVENT_AFTER_SEND);
            $yiiResponse->isSent = true;
        }
    }

    /**
     * Handles an exception during request processing.
     */
    private function handleRequestException(Throwable $exception, Application $app, Response $response): void
    {
        try {
            if (!$app->has('errorHandler')) {
                $this->handleFallbackException($exception, $response);
                return;
            }

            /** @var ErrorHandler|null $errorHandler */
            $errorHandler = $app->getErrorHandler();
            if ($errorHandler === null) {
                $this->handleFallbackException($exception, $response);
                return;
            }

            $errorHandler->exception = $exception;
            $errorHandler->logException($exception);

            $yiiResponse = $app->getResponse();
            if ($yiiResponse->isSent) {
                $yiiResponse->isSent = false;
            }

            if ($exception instanceof HttpException) {
                $yiiResponse->setStatusCode($exception->statusCode);
            } else {
                $yiiResponse->setStatusCode(500);
            }

            $isDebug = defined('YII_DEBUG') && YII_DEBUG;

            if ($yiiResponse->format === YiiResponse::FORMAT_JSON) {
                $yiiResponse->data = $this->formatExceptionToArray($exception, $isDebug);
            } else {
                $yiiResponse->data = $this->renderExceptionHtml($errorHandler, $exception, $isDebug);
            }

            $errorHandler->exception = null;

            if ($this->isResponseWritable($response)) {
                $this->finalizeResponse($response, $yiiResponse);
            }
        } catch (Throwable $handlerException) {
            Yii::error($handlerException, __METHOD__ . '::errorHandler');
            $this->handleFallbackException($exception, $response);
        }
    }

    /**
     * Formats a fallback error response when error handler fails.
     */
    private function handleFallbackException(Throwable $exception, Response $response): void
    {
        Yii::error($exception, __METHOD__);

        if (!$this->isResponseWritable($response)) {
            return;
        }

        $isDebug = defined('YII_DEBUG') && YII_DEBUG;
        $body = $isDebug ? (string) $exception : 'Internal Server Error';

        $response->status(500);
        $response->header('Content-Type', 'text/plain; charset=UTF-8');
        $response->end($body);
    }

    private function isResponseWritable(Response $response): bool
    {
        return !method_exists($response, 'isWritable') || $response->isWritable();
    }

    private function prepareResponseBody(YiiResponse $response): void
    {
        if ($response->format === YiiResponse::FORMAT_RAW) {
            if ($response->content === null && $response->data !== null) {
                $response->content = $response->data;
            }
            return;
        }

        $formatter = $response->formatters[$response->format] ?? null;
        if ($formatter === null && isset($response->formatters['default'])) {
            $formatter = $response->formatters['default'];
        }

        if ($formatter !== null) {
            if (!$formatter instanceof ResponseFormatterInterface) {
                $formatter = Yii::createObject($formatter);
            }

            if (!$formatter instanceof ResponseFormatterInterface) {
                throw new InvalidConfigException('Invalid response formatter for format: ' . $response->format);
            }

            $formatter->format($response);
            return;
        }

        if ($response->format === YiiResponse::FORMAT_HTML) {
            if ($response->content === null && $response->data !== null) {
                $response->content = (string) $response->data;
            }
            return;
        }

        throw new InvalidConfigException('Unsupported response format: ' . $response->format);
    }

    private function sendHeaders(Response $swooleResponse, YiiResponse $yiiResponse): void
    {
        $swooleResponse->status($yiiResponse->getStatusCode());

        foreach ($yiiResponse->getHeaders()->toArray() as $name => $values) {
            foreach ((array) $values as $value) {
                $swooleResponse->header($name, (string) $value);
            }
        }
    }

    private function sendCookies(Response $swooleResponse, YiiResponse $yiiResponse): void
    {
        foreach ($yiiResponse->cookies as $cookie) {
            if (!$cookie instanceof Cookie) {
                continue;
            }

            $swooleResponse->cookie(
                $cookie->name,
                (string) $cookie->value,
                $cookie->expire,
                $cookie->path,
                $cookie->domain,
                $cookie->secure,
                $cookie->httpOnly,
                $cookie->sameSite ?? ''
            );
        }
    }

    private function captureResponseBody(YiiResponse $yiiResponse): string
    {
        if ($yiiResponse->stream === null) {
            return (string) $yiiResponse->content;
        }

        $bufferLevel = ob_get_level();
        ob_start();

        try {
            $this->sendStreamContent($yiiResponse);
            return ob_get_contents() ?: '';
        } finally {
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }
        }
    }

    private function sendStreamContent(YiiResponse $response): void
    {
        $stream = $response->stream;

        if (is_callable($stream)) {
            $data = call_user_func($stream);
            foreach ($data as $datum) {
                echo $datum;
                flush();
            }
            return;
        }

        if (is_array($stream)) {
            $this->sendFileStream($stream);
            return;
        }

        Yii::warning('Unknown stream type in response: ' . gettype($stream), __METHOD__);
    }

    private function sendFileStream(array $stream): void
    {
        [$handle, $begin, $end] = $stream;
        $chunkSize = 8 * 1024 * 1024;

        if (is_resource($handle) && stream_get_meta_data($handle)['seekable'] ?? false) {
            fseek($handle, $begin);
        }

        while (is_resource($handle) && !feof($handle) && ($pos = ftell($handle)) <= $end) {
            if ($pos + $chunkSize > $end) {
                $chunkSize = $end - $pos + 1;
            }
            echo fread($handle, $chunkSize);
            flush();
        }

        if (is_resource($handle)) {
            fclose($handle);
        }
    }

    private function renderExceptionHtml(ErrorHandler $errorHandler, Throwable $exception, bool $isDebug): string
    {
        $useErrorView = !$isDebug || $exception instanceof UserException;
        $viewFile = $useErrorView ? $errorHandler->errorView : $errorHandler->exceptionView;

        return $errorHandler->renderFile($viewFile, [
            'exception' => $exception,
        ]);
    }

    /**
     * Formats an exception as an array for JSON responses.
     *
     * @return array<string, mixed>
     */
    private function formatExceptionToArray(Throwable $exception, bool $debug = false): array
    {
        if (!$debug) {
            if (!($exception instanceof UserException) && !($exception instanceof HttpException)) {
                $exception = new HttpException(500, Yii::t('yii', 'An internal server error occurred.'));
            }
        }

        $array = [
            'name' => ($exception instanceof \yii\base\Exception || $exception instanceof \yii\base\ErrorException)
                ? $exception->getName()
                : 'Exception',
            'message' => $exception->getMessage(),
            'code' => $exception->getCode(),
        ];

        if ($exception instanceof HttpException) {
            $array['status'] = $exception->statusCode;
        }

        if ($debug) {
            $array['type'] = get_class($exception);
            $array['file'] = $exception->getFile();
            $array['line'] = $exception->getLine();
            $array['stack-trace'] = explode("\n", $exception->getTraceAsString());
        }

        if (($prev = $exception->getPrevious()) !== null) {
            $array['previous'] = $this->formatExceptionToArray($prev, $debug);
        }

        return $array;
    }

    // ==================== Application Lifecycle ====================

    /**
     * Prepares the application for request handling.
     */
    private function prepareApplication(Application $app): void
    {
        $this->prepareLogger($app);

        // Store default response format on first request
        if ($this->defaultResponseFormat === null) {
            $response = $app->getResponse();
            $this->defaultResponseFormat = $response->format;
        }
    }

    /**
     * Cleans up application state after request handling.
     */
    private function cleanupApplication(Application $app): void
    {
        $this->closeConnections($app);
        $this->clearResponse($app);
        $this->clearRequest($app);
        $this->flushLogger($app);
        $this->resetCoroutineContext($app);
    }

    /**
     * Performs final cleanup after request processing.
     */
    private function finallyCleanup(Application $app, ?Application $previousApp, $previousCwd, callable $restoreGlobals): void
    {
        // Clear Swoole request from params
        $app->params['__swooleRequest'] = null;

        // Execute cleanup lifecycle
        $this->cleanupApplication($app);

        // Restore globals
        $restoreGlobals();

        // Restore previous application
        $this->restorePreviousApplication($previousApp);

        // Restore working directory
        if ($previousCwd !== false) {
            chdir($previousCwd);
        }
    }

    private function closeConnections(Application $app): void
    {
        if (!$app instanceof CoroutineApplication) {
            return;
        }

        $store = method_exists($app, 'getCoroutineComponentStore')
            ? $app->getCoroutineComponentStore()
            : [];

        // Close DB connection
        if (isset($store['db']) && is_object($store['db']) && method_exists($store['db'], 'close')) {
            $this->safeClose($store['db'], 'DB');
        }

        // Close Redis connection
        if (isset($store['redis']) && is_object($store['redis']) && method_exists($store['redis'], 'close')) {
            $this->safeClose($store['redis'], 'Redis');
        }

        // Close session
        if ($app->has('session')) {
            try {
                $session = $app->get('session', false);
                if ($session && method_exists($session, 'close')) {
                    $session->close();
                }
            } catch (Throwable $e) {
                Yii::error("Error closing session: " . $e->getMessage(), __CLASS__);
            }
        }
    }

    private function safeClose(object $connection, string $type): void
    {
        try {
            $connection->close();
        } catch (Throwable $e) {
            Yii::error("Error closing {$type}: " . $e->getMessage(), __CLASS__);
        }
    }

    private function clearResponse(Application $app): void
    {
        $response = $app->getResponse();

        $response->data = null;
        $response->content = null;
        $response->stream = null;
        $response->format = $this->defaultResponseFormat ?? YiiResponse::FORMAT_HTML;

        if (property_exists($response, '_headers')) {
            $response->_headers = null;
        }
        if (property_exists($response, '_cookies')) {
            $response->_cookies = null;
        }

        $response->clear();
    }

    private function clearRequest(Application $app): void
    {
        $request = $app->getRequest();

        if (!method_exists($request, 'setBodyParams')) {
            return;
        }

        $request->setBodyParams([]);
        $request->setQueryParams([]);
        $request->setRawBody('');

        if (method_exists($request, 'getHeaders')) {
            $headers = $request->getHeaders();
            if (method_exists($headers, 'removeAll')) {
                $headers->removeAll();
            }
        }

        if (method_exists($request, 'getCookies')) {
            $cookies = $request->getCookies();
            if ($cookies instanceof CookieCollection) {
                $this->withWritableCookieCollection($cookies, function () use ($cookies): void {
                    $cookies->removeAll();
                });
            } elseif (method_exists($cookies, 'removeAll')) {
                $cookies->removeAll();
            }
        }
    }

    private function flushLogger(Application $app): void
    {
        if (!$app->has('log')) {
            return;
        }

        $log = $app->getLog();
        $logger = $log->getLogger();

        if ($logger instanceof \yii\log\Logger) {
            $logger->flush(true);
            $logger->messages = [];

            foreach ($log->targets as $target) {
                if ($target instanceof \yii\log\Target) {
                    $target->messages = [];
                }
            }
        }
    }

    private function prepareLogger(Application $app): void
    {
        if (!$app->has('log')) {
            return;
        }

        $log = $app->getLog();
        $logger = $log->getLogger();

        if ($logger instanceof \yii\log\Logger) {
            $logger->flush(true);
            $logger->messages = [];
        }
    }

    private function resetCoroutineContext(Application $app): void
    {
        if ($app instanceof CoroutineApplication) {
            $app->resetCoroutineContext();
        }

        // Clear view params (breadcrumbs, etc.)
        if ($app->has('view')) {
            try {
                $view = $app->get('view', false);
                if ($view && is_object($view) && property_exists($view, 'params')) {
                    $view->params = [];
                }
            } catch (Throwable $e) {
                // Ignore view cleanup errors
            }
        }
    }

    // ==================== Application Management ====================

    /**
     * Gets or creates the application instance.
     */
    private function getApplication(): Application
    {
        if ($this->application !== null) {
            return $this->application;
        }

        [$class, $config] = $this->loadApplicationConfig();

        /** @var class-string<Application> $class */
        $this->application = new $class($config);

        return $this->application;
    }

    /**
     * Loads application configuration from file.
     *
     * @return array{0: class-string<Application>, 1: array} Class name and config
     */
    private function loadApplicationConfig(): array
    {
        if ($this->applicationConfig !== null && $this->applicationClass !== null) {
            return [$this->applicationClass, $this->applicationConfig];
        }

        $config = require $this->appConfig;

        if ($config instanceof Application) {
            throw new InvalidConfigException('Application config must return an array, not an instance of yii\\web\\Application.');
        }

        if (!is_array($config)) {
            throw new InvalidConfigException('Application config must return an array or configure yii\\web\\Application.');
        }

        $class = $config['class'] ?? CoroutineApplication::class;

        if (!is_string($class) || !is_a($class, Application::class, true)) {
            throw new InvalidConfigException(sprintf('Application class "%s" must be a subclass of %s.', (string) $class, Application::class));
        }

        unset($config['class']);

        $this->applicationClass = $class;
        $this->applicationConfig = $config;

        return [$class, $config];
    }

    /**
     * Gets the entry script path for the application.
     */
    private function getEntryScriptPath(Application $app): string
    {
        if ($this->entryScript !== null) {
            return $this->entryScript;
        }

        $candidates = [
            Yii::getAlias('@app/web/index.php', false),
            Yii::getAlias('@webroot/index.php', false),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && is_file($candidate)) {
                return $this->entryScript = realpath($candidate) ?: $candidate;
            }
        }

        return $this->entryScript = $app->getBasePath() . '/web/index.php';
    }

    /**
     * Sets the working directory to the application's web root.
     *
     * This ensures relative paths (like './fonts/books.ttf' in CaptchaAction)
     * resolve correctly.
     *
     * @return string|false Previous working directory
     */
    private function setWorkingDirectory(Application $app)
    {
        $previousCwd = getcwd();
        $webRoot = Yii::getAlias('@app/web', false) ?: Yii::getAlias('@webroot', false);

        if ($webRoot && is_dir($webRoot)) {
            chdir($webRoot);
        }

        return $previousCwd;
    }

    /**
     * Restores the previous application instance.
     */
    private function restorePreviousApplication(?Application $previousApp): void
    {
        if ($previousApp !== null) {
            Yii::$app = $previousApp;
        } else {
            Yii::$app = null;
        }
    }
}
