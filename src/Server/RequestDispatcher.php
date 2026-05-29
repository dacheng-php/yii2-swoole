<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Server;

use Dacheng\Yii2\Swoole\Coroutine\CoroutineApplication;
use Dacheng\Yii2\Swoole\Runtime\ApplicationLifecycle;
use Dacheng\Yii2\Swoole\Runtime\RequestExceptionHandler;
use Dacheng\Yii2\Swoole\Runtime\SwooleRequestPopulator;
use Dacheng\Yii2\Swoole\Runtime\SwooleResponseSender;
use Swoole\Coroutine\Http\Server as SwooleCoroutineHttpServer;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Throwable;
use Yii;
use yii\base\InvalidConfigException;
use yii\web\Application;

/**
 * RequestDispatcher orchestrates conversion and processing of Swoole requests to Yii2.
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
    private ?CoroutineApplication $application = null;

    /**
     * @var array|null Cached application configuration
     */
    private ?array $applicationConfig = null;

    /**
     * @var string|null Application class name
     */
    private ?string $applicationClass = null;

    private ApplicationLifecycle $lifecycle;

    private SwooleRequestPopulator $requestPopulator;

    private SwooleResponseSender $responseSender;

    private RequestExceptionHandler $exceptionHandler;

    /**
     * Creates a new RequestDispatcher.
     *
     * @param string|null $appConfig Path to Yii2 application config
     * @param array $config Additional configuration
     */
    public function __construct(?string $appConfig = null, array $config = [])
    {
        $this->appConfig = $appConfig;
        $this->lifecycle = $config['lifecycle'] ?? new ApplicationLifecycle();
        $this->requestPopulator = $config['requestPopulator'] ?? new SwooleRequestPopulator();
        $this->responseSender = $config['responseSender'] ?? new SwooleResponseSender();
        $this->exceptionHandler = $config['exceptionHandler'] ?? new RequestExceptionHandler($this->responseSender);
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
     */
    public function dispatch(Request $request, Response $response, SwooleCoroutineHttpServer $server): void
    {
        $app = $this->getApplication();

        try {
            $this->requestPopulator->populate($app, $request);
            $this->lifecycle->prepare($app);

            $handledResponse = $app->handleRequest($app->getRequest());
            $this->responseSender->send($response, $handledResponse);
        } catch (Throwable $exception) {
            $this->exceptionHandler->handle($exception, $app, $response);
        } finally {
            $this->lifecycle->cleanup($app);
        }
    }

    /**
     * Gets or creates the application instance.
     */
    private function getApplication(): CoroutineApplication
    {
        if ($this->application !== null) {
            return $this->application;
        }

        [$class, $config] = $this->loadApplicationConfig();

        // Inject Coroutine-aware DI container prior to application creation
        Yii::$container = new \Dacheng\Yii2\Swoole\Coroutine\CoroutineContainer();

        /** @var class-string<CoroutineApplication> $class */
        $this->application = new $class($config);
        Yii::$app = $this->application;

        return $this->application;
    }

    /**
     * Pre-warms connection pools defined in the application components.
     */
    public function prewarm(): void
    {
        try {
            $app = $this->getApplication();

            // Retrieve all defined components to identify pools to pre-warm
            $components = $app->getComponents(false);
            foreach ($components as $id => $definition) {
                try {
                    // Eagerly resolve component to trigger connection pool creation
                    $component = $app->get($id, false);
                    if ($component !== null && method_exists($component, 'getPool')) {
                        Yii::info("Pre-warming connection pool for component '{$id}'", __METHOD__);
                        $component->getPool();
                    }
                } catch (\Throwable $e) {
                    Yii::error("Failed to pre-warm connection pool for component '{$id}': " . $e->getMessage(), __METHOD__);
                }
            }
        } catch (\Throwable $e) {
            Yii::error("Error during connection pool pre-warming: " . $e->getMessage(), __METHOD__);
        }
    }

    /**
     * Loads application configuration from file.
     *
     * @return array{0: class-string<CoroutineApplication>, 1: array} Class name and config
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

        if (!is_string($class) || !is_a($class, CoroutineApplication::class, true)) {
            throw new InvalidConfigException(sprintf('Application class "%s" must be a subclass of %s.', (string) $class, CoroutineApplication::class));
        }

        unset($config['class']);

        $this->applicationClass = $class;
        $this->applicationConfig = $config;

        return [$class, $config];
    }

}
