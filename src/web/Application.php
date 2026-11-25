<?php

declare(strict_types=1);

namespace yii2swoole\web;

use ArrayObject;
use Closure;
use yii2swoole\coroutine\Container;
use yii2swoole\coroutine\ResettableInterface;
use yii2swoole\log\CoroutineTargetInterface;
use yii2swoole\log\Logger;
use yii2swoole\redis\Session;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Throwable;
use Yii;
use yii\base\Component;
use yii\base\InvalidConfigException;
use yii\web\Application as BaseApplication;

/**
 * Coroutine-safe Yii2 Web Application facade.
 *
 * Because PHP cannot intercept static property reads on Yii::$app, this single Application
 * instance acts as a worker-scoped facade that routes:
 * 1. Mutable request-scoped Application properties ($controller, $requestedRoute, $requestedAction,
 *    $requestedParams, $state, $language, $layout, $loadedModules, $homeUrl) to Swoole coroutine context.
 * 2. Shared stateless/internally-isolated components (urlManager, i18n, formatter, security, log,
 *    db, redis, cache, queue, httpClient) via an O(1) direct instance cache ($sharedInstances).
 * 3. Request-scoped components (request, response, session, user, view, assetManager, errorHandler,
 *    and custom non-shared components) via prototype cloning into coroutine-local storage.
 */
class Application extends BaseApplication
{
    private const CONTEXT_COMPONENTS_KEY = '__yii2SwooleAppComponents';
    private const CONTEXT_STATE_KEY = '__yii2SwooleAppState';

    /**
     * Application properties isolated per coroutine to eliminate cross-request races.
     *
     * @var array<string, true>
     */
    private const ISOLATED_PROPERTIES = [
        'controller' => true,
        'requestedRoute' => true,
        'requestedAction' => true,
        'requestedParams' => true,
        'state' => true,
        'language' => true,
        'layout' => true,
        'loadedModules' => true,
    ];

    /**
     * Component IDs shared across all coroutines within a worker process.
     *
     * @var string[]
     */
    public array $sharedComponentIds = [
        'urlManager',
        'i18n',
        'formatter',
        'security',
        'log',
        'db',
        'redis',
        'cache',
        'queue',
        'httpClient',
    ];

    /**
     * Component IDs that MUST remain coroutine-isolated because they hold request state.
     *
     * @var string[]
     */
    public array $requestScopedComponentIds = [
        'request',
        'response',
        'session',
        'user',
        'view',
        'assetManager',
        'errorHandler',
    ];

    /**
     * Fast lookup set compiled from $sharedComponentIds.
     *
     * @var array<string, true>
     */
    private array $sharedComponentMap = [];

    /**
     * Direct O(1) cache of already-instantiated shared components for zero-overhead hot-path reads.
     *
     * @var array<string, object>
     */
    private array $sharedInstances = [];

    /**
     * Cached component definitions to avoid parent::getComponents() copies on hot paths.
     *
     * @var array<string, mixed>
     */
    private array $definitionCache = [];

    /**
     * Clean, untouched prototype instances for fast per-request cloning.
     *
     * @var array<string, object|false>
     */
    private array $componentPrototypes = [];

    /**
     * Singleflight initialization locks for lazily instantiated shared components.
     *
     * @var array<string, Channel>
     */
    private array $sharedInitLocks = [];

    /**
     * Cache of validated controller IDs eligible for direct `new $class($id, $this)`
     * bypassing ReflectionClass / Yii::createObject() on hot paths.
     *
     * @var array<string, class-string<\yii\base\Controller>|false>
     */
    private array $controllerFastCache = [];

    /**
     * Direct references to pre-built request/response prototypes (null if non-cloneable).
     */
    private ?Request $requestPrototype = null;

    private ?Response $responsePrototype = null;

    private string $defaultLanguage = 'en-US';

    /** @var string|bool */
    private string|bool $defaultLayout = 'main';

    private ?string $defaultHomeUrl = null;

    /** @var array<string, mixed> */
    private array $fallbackState = [];

    public function init(): void
    {
        // Build sharedComponentMap before parent::init() / bootstrap() so shared components
        // accessed by extension bootstraps (e.g. i18n, log, queue) inside onWorkerStart
        // coroutines are instantiated as shared singletons instead of coroutine-local clones.
        $this->rebuildSharedComponentMap();

        parent::init();

        $this->defaultLanguage = is_string($this->language) ? $this->language : 'en-US';
        $this->defaultLayout = $this->layout ?? 'main';
        $this->rebuildSharedComponentMap();

        // Unset mutable parent properties so reads/writes route through __get/__set into coroutine context.
        unset(
            $this->controller,
            $this->requestedRoute,
            $this->requestedAction,
            $this->requestedParams,
            $this->state,
            $this->language,
            $this->layout,
            $this->loadedModules
        );

        $this->fallbackState = $this->createInitialState();
    }

    /**
     * Override core components so Yii2 apps get coroutine-safe web components explicitly by default.
     *
     * Because yiisoft/yii2-redis is an optional dependency, `session` defaults to Redis\Session
     * only when yiisoft/yii2-redis is installed; otherwise it is intentionally unset rather than
     * silently falling back to the coroutine-unsafe native yii\web\Session.
     */
    public function coreComponents(): array
    {
        $components = array_merge(parent::coreComponents(), [
            'request' => ['class' => Request::class],
            'response' => ['class' => Response::class],
            'errorHandler' => ['class' => ErrorHandler::class],
            'user' => ['class' => User::class],
        ]);

        if (class_exists(\yii\redis\Session::class)) {
            $components['session'] = ['class' => Session::class];
        } else {
            unset($components['session']);
        }

        return $components;
    }

    /**
     * Avoid registering PHP global error/shutdown handlers or reading CLI $_SERVER for @webroot.
     */
    protected function bootstrap(): void
    {
        if (Yii::getAlias('@webroot', false) === false) {
            Yii::setAlias('@webroot', $this->getBasePath() . DIRECTORY_SEPARATOR . 'web');
        }
        if (Yii::getAlias('@web', false) === false) {
            Yii::setAlias('@web', '');
        }

        // Skip parent yii\web\Application::bootstrap() which touches $this->getRequest()->getScriptFile(),
        // and call yii\base\Application::bootstrap() directly.
        \yii\base\Application::bootstrap();
    }

    protected function registerErrorHandler(&$config): void
    {
        // In Swoole, RequestHandler catches all Throwables and delegates to Web\ErrorHandler.
        // We ensure the errorHandler definition exists without calling register() on PHP globals.
        if (!isset($config['components']['errorHandler']['class'])) {
            $config['components']['errorHandler']['class'] = ErrorHandler::class;
        }
    }

    public function set($id, $definition): void
    {
        unset($this->componentPrototypes[$id], $this->sharedInstances[$id]);
        if ($id === 'request') {
            $this->requestPrototype = null;
        } elseif ($id === 'response') {
            $this->responsePrototype = null;
        }

        if ($definition === null) {
            unset($this->definitionCache[$id]);
        } else {
            $this->definitionCache[$id] = $definition;
            if (is_object($definition) && !$definition instanceof Closure && isset($this->sharedComponentMap[$id])) {
                $this->sharedInstances[$id] = $definition;
            }
        }

        parent::set($id, $definition);
    }

    public function setComponents($components): void
    {
        parent::setComponents($components);
        $this->definitionCache = parent::getComponents(true);
        $this->sharedInstances = [];
        $this->componentPrototypes = [];
        $this->requestPrototype = null;
        $this->responsePrototype = null;
    }

    /**
     * Fast-path controller creation bypassing Yii::createObject() reflection for standard
     * controllers whose constructor only expects ($id, $module, $config = []).
     */
    public function createControllerByID($id)
    {
        if (isset($this->controllerFastCache[$id])) {
            $cached = $this->controllerFastCache[$id];
            if ($cached !== false) {
                return new $cached($id, $this);
            }

            return parent::createControllerByID($id);
        }

        $controller = parent::createControllerByID($id);
        if ($controller === null) {
            return null;
        }

        if (count($this->controllerFastCache) < 1024) {
            $className = get_class($controller);
            $this->controllerFastCache[$id] = $this->isDirectConstructibleController($className) ? $className : false;
        }

        return $controller;
    }

    /**
     * Per-request bootstrap that allocates & registers request/response and
     * initial coroutine state in a single Coroutine::getContext() lookup.
     *
     * @return array{0:Request,1:Response}
     */
    public function beginCoroutineRequest(): array
    {
        $context = Coroutine::getContext();

        $request = $this->requestPrototype !== null
            ? clone $this->requestPrototype
            : $this->instantiateScopedComponent('request', $this->definitionCache['request'] ?? ['class' => Request::class]);

        $response = $this->responsePrototype !== null
            ? clone $this->responsePrototype
            : $this->instantiateScopedComponent('response', $this->definitionCache['response'] ?? ['class' => Response::class]);

        $context[self::CONTEXT_COMPONENTS_KEY] = [
            'request' => $request,
            'response' => $response,
        ];
        $context[self::CONTEXT_STATE_KEY] = [
            'controller' => null,
            'requestedRoute' => null,
            'requestedAction' => null,
            'requestedParams' => null,
            'state' => self::STATE_HANDLING_REQUEST,
            'language' => $this->defaultLanguage,
            'layout' => $this->defaultLayout,
            'homeUrl' => null,
            'loadedModules' => null,
        ];

        return [$request, $response];
    }

    public function getRequest()
    {
        if (Coroutine::getCid() >= 0) {
            $existing = Coroutine::getContext()[self::CONTEXT_COMPONENTS_KEY]['request'] ?? null;
            if ($existing !== null) {
                return $existing;
            }
        }

        return $this->get('request');
    }

    public function getResponse()
    {
        if (Coroutine::getCid() >= 0) {
            $existing = Coroutine::getContext()[self::CONTEXT_COMPONENTS_KEY]['response'] ?? null;
            if ($existing !== null) {
                return $existing;
            }
        }

        return $this->get('response');
    }

    public function __get($name)
    {
        // 1. Hot path: O(1) hit for pre-warmed shared components (db, redis, cache, log, urlManager, etc.)
        if (isset($this->sharedInstances[$name])) {
            return $this->sharedInstances[$name];
        }

        // 2. Request-scoped Application properties
        if (isset(self::ISOLATED_PROPERTIES[$name])) {
            return $this->getAppStateValue($name);
        }

        // 3. Coroutine-scoped components
        if ($this->isCoroutineContext() && !isset($this->sharedComponentMap[$name])) {
            $store = $this->getCoroutineComponentStore();
            if (array_key_exists($name, $store)) {
                return $store[$name];
            }

            if (isset($this->definitionCache[$name])) {
                return $this->get($name);
            }
        }

        return parent::__get($name);
    }

    public function __set($name, $value): void
    {
        if (isset(self::ISOLATED_PROPERTIES[$name])) {
            $this->setAppStateValue($name, $value);
            return;
        }

        parent::__set($name, $value);
    }

    public function __isset($name): bool
    {
        if (isset($this->sharedInstances[$name])) {
            return true;
        }

        if (isset(self::ISOLATED_PROPERTIES[$name])) {
            return $this->getAppStateValue($name) !== null;
        }

        if ($this->isCoroutineContext() && !isset($this->sharedComponentMap[$name])) {
            $store = $this->getCoroutineComponentStore();
            if (array_key_exists($name, $store)) {
                return $store[$name] !== null;
            }

            return isset($this->definitionCache[$name]);
        }

        return parent::__isset($name);
    }

    public function getHomeUrl()
    {
        $homeUrl = $this->getAppStateValue('homeUrl');
        if ($homeUrl !== null) {
            return $homeUrl;
        }

        if ($this->defaultHomeUrl !== null) {
            return $this->defaultHomeUrl;
        }

        if ($this->getUrlManager()->showScriptName) {
            return $this->getRequest()->getScriptUrl();
        }

        return $this->getRequest()->getBaseUrl() . '/';
    }

    public function setHomeUrl($value): void
    {
        if (!$this->isCoroutineContext()) {
            $this->defaultHomeUrl = $value;
            return;
        }

        $this->setAppStateValue('homeUrl', $value);
    }

    public function get($id, $throwException = true)
    {
        if (isset($this->sharedInstances[$id])) {
            return $this->sharedInstances[$id];
        }

        if (!$this->isCoroutineContext()) {
            if ($id === 'session' && !parent::has('session') && $throwException) {
                $this->throwMissingSessionException();
            }

            $instance = parent::get($id, $throwException);
            if (is_object($instance) && isset($this->sharedComponentMap[$id])) {
                $this->sharedInstances[$id] = $instance;
            }
            return $instance;
        }

        if (isset($this->sharedComponentMap[$id])) {
            // Fast path: already instantiated on parent
            if (parent::has($id, true)) {
                $instance = parent::get($id, $throwException);
                if (is_object($instance)) {
                    $this->sharedInstances[$id] = $instance;
                }
                return $instance;
            }

            // Cold-start singleflight: serialize concurrent first-time initialization of a shared component
            $lock = $this->sharedInitLocks[$id] ??= $this->createInitLock();
            $token = $lock->pop(10.0);
            if ($token === false) {
                throw new \RuntimeException(sprintf('Timed out waiting to initialize shared component "%s".', $id));
            }

            try {
                if (isset($this->sharedInstances[$id])) {
                    return $this->sharedInstances[$id];
                }

                $instance = parent::get($id, $throwException);
                if (is_object($instance)) {
                    $this->sharedInstances[$id] = $instance;
                }

                return $instance;
            } finally {
                $lock->push($token);
                if (isset($this->sharedInstances[$id])) {
                    $stats = $lock->stats();
                    if (((int) ($stats['consumer_num'] ?? 0)) === 0) {
                        unset($this->sharedInitLocks[$id]);
                    }
                }
            }
        }

        $store = $this->getCoroutineComponentStore();
        if (array_key_exists($id, $store)) {
            return $store[$id];
        }

        $definition = $this->definitionCache[$id] ?? null;
        if ($definition === null && !array_key_exists($id, $this->definitionCache)) {
            $this->definitionCache = parent::getComponents(true);
            $definition = $this->definitionCache[$id] ?? null;
        }

        if ($definition === null && !array_key_exists($id, $this->definitionCache)) {
            if ($throwException) {
                if ($id === 'session') {
                    $this->throwMissingSessionException();
                }

                throw new InvalidConfigException("Unknown component ID: {$id}");
            }

            return null;
        }

        $component = $this->instantiateScopedComponent($id, $definition);
        $this->setCoroutineComponent($id, $component);

        return $component;
    }

    public function has($id, $checkInstance = false): bool
    {
        if ($checkInstance) {
            if (isset($this->sharedInstances[$id])) {
                return true;
            }
            if ($this->isCoroutineContext() && !isset($this->sharedComponentMap[$id])) {
                $store = $this->getCoroutineComponentStore();
                return array_key_exists($id, $store);
            }
        }

        return parent::has($id, $checkInstance);
    }

    /**
     * Validates coroutine safety invariants during worker startup (fail-fast).
     *
     * @throws InvalidConfigException
     */
    public function validateCoroutineConfiguration(): void
    {
        $this->rebuildSharedComponentMap();

        $illegalShared = array_intersect($this->sharedComponentIds, $this->requestScopedComponentIds);
        if ($illegalShared !== []) {
            throw new InvalidConfigException(sprintf(
                'Components "%s" hold request-scoped state and must not be added to "sharedComponentIds".',
                implode('", "', array_values($illegalShared))
            ));
        }

        if ($this->has('log')) {
            $log = $this->getLog();
            $logger = $log->getLogger();
            if (!$logger instanceof Logger) {
                throw new InvalidConfigException(sprintf(
                    'The shared "log" component must use %s in Swoole coroutine mode.',
                    Logger::class
                ));
            }

            foreach ($log->targets as $index => $target) {
                if (!$target instanceof CoroutineTargetInterface) {
                    throw new InvalidConfigException(sprintf(
                        'Log target "%s" (%s) must implement %s to avoid cross-request log buffer contamination.',
                        (string) $index,
                        get_class($target),
                        CoroutineTargetInterface::class
                    ));
                }
            }
        }
    }

    /**
     * Eagerly instantiates shared components and pre-builds request-scoped prototypes
     * inside onWorkerStart before serving the first HTTP request.
     *
     * Fails fast if any configured shared component has an invalid definition.
     */
    public function warmup(): void
    {
        $this->rebuildSharedComponentMap();
        $this->definitionCache = parent::getComponents(true);

        // Pre-instantiate configured shared singletons (fail-fast on config errors)
        foreach ($this->sharedComponentIds as $id) {
            if (isset($this->definitionCache[$id])) {
                $instance = parent::get($id, true);
                if (is_object($instance)) {
                    $this->sharedInstances[$id] = $instance;
                }
            }
        }

        // Pre-build prototypes for core hot-path request components
        foreach (['request', 'response', 'errorHandler'] as $id) {
            if (isset($this->definitionCache[$id])) {
                $this->preparePrototype($id, $this->definitionCache[$id]);
            }
        }

        if (($this->componentPrototypes['request'] ?? null) instanceof Request) {
            $this->requestPrototype = $this->componentPrototypes['request'];
        }
        if (($this->componentPrototypes['response'] ?? null) instanceof Response) {
            $this->responsePrototype = $this->componentPrototypes['response'];
        }
    }

    /**
     * Deterministically resets every ResettableInterface component in the current coroutine
     * and clears coroutine-local component/state stores.
     */
    public function clearCoroutineContext(): void
    {
        if (!$this->isCoroutineContext()) {
            return;
        }

        $context = Coroutine::getContext();
        $store = $context[self::CONTEXT_COMPONENTS_KEY] ?? null;

        if (is_array($store)) {
            foreach ($store as $id => $component) {
                if ($component instanceof ResettableInterface) {
                    try {
                        $component->reset();
                    } catch (Throwable $e) {
                        Yii::error("Error resetting coroutine component '{$id}': {$e->getMessage()}", __METHOD__);
                    }
                }
            }
        }

        unset($context[self::CONTEXT_COMPONENTS_KEY], $context[self::CONTEXT_STATE_KEY]);

        if (Yii::$container instanceof Container) {
            Yii::$container->clearCoroutineSingletons();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getCoroutineComponentStore(): array
    {
        if (!$this->isCoroutineContext()) {
            return [];
        }

        $context = Coroutine::getContext();
        $store = $context[self::CONTEXT_COMPONENTS_KEY] ?? null;

        return is_array($store) ? $store : [];
    }

    public function isSharedComponent(string $id): bool
    {
        return isset($this->sharedComponentMap[$id]);
    }

    private function rebuildSharedComponentMap(): void
    {
        $this->sharedComponentMap = array_fill_keys($this->sharedComponentIds, true);
    }

    private function throwMissingSessionException(): never
    {
        throw new InvalidConfigException(sprintf(
            'Component "session" is not configured. Native yii\\web\\Session is unsafe under Swoole coroutines; '
            . 'install "yiisoft/yii2-redis" to enable %s by default, or explicitly configure a coroutine-safe "session" component.',
            Session::class
        ));
    }

    private function createInitLock(): Channel
    {
        $lock = new Channel(1);
        $lock->push(true);

        return $lock;
    }

    private function isDirectConstructibleController(string $className): bool
    {
        if (Yii::$container->has($className)) {
            return false;
        }

        try {
            $rc = new \ReflectionClass($className);
            $ctor = $rc->getConstructor();
            if ($ctor === null) {
                return true;
            }
            if ($ctor->getNumberOfRequiredParameters() > 2) {
                return false;
            }
            foreach ($ctor->getParameters() as $idx => $param) {
                if ($idx >= 2 && !$param->isOptional()) {
                    return false;
                }
                if ($idx >= 2 && $param->getType() instanceof \ReflectionNamedType && !$param->getType()->isBuiltin()) {
                    return false;
                }
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function isCoroutineContext(): bool
    {
        return Coroutine::getCid() >= 0;
    }

    private function setCoroutineComponent(string $id, mixed $component): void
    {
        $context = Coroutine::getContext();
        $store = $context[self::CONTEXT_COMPONENTS_KEY] ?? [];
        $store[$id] = $component;
        $context[self::CONTEXT_COMPONENTS_KEY] = $store;
    }

    private function instantiateScopedComponent(string $id, mixed $definition): mixed
    {
        if (!array_key_exists($id, $this->componentPrototypes)) {
            $this->preparePrototype($id, $definition);
        }

        $prototype = $this->componentPrototypes[$id];
        if (is_object($prototype)) {
            return clone $prototype;
        }

        // Definitions with attached events/behaviors (on */as *) or Closure factories
        // must be freshly instantiated so Yii2 preserves their event/behavior bindings.
        if (is_object($definition) && !$definition instanceof Closure) {
            return clone $definition;
        }

        return Yii::createObject($definition);
    }

    private function preparePrototype(string $id, mixed $definition): void
    {
        if (!$this->isPrototypeCloneable($definition)) {
            $this->componentPrototypes[$id] = false;
            return;
        }

        $instance = is_object($definition) ? clone $definition : Yii::createObject($definition);
        if (!$instance instanceof Component) {
            $this->componentPrototypes[$id] = false;
            return;
        }

        $this->componentPrototypes[$id] = $instance;
    }

    /**
     * Checks whether a component definition can be safely prototype-cloned.
     *
     * yii\base\Component::__clone() clears $_events and $_behaviors, so definitions
     * containing 'on *' or 'as *' keys (or Closure factories) fall back to direct creation.
     */
    private function isPrototypeCloneable(mixed $definition): bool
    {
        if ($definition instanceof Closure) {
            return false;
        }

        if (is_array($definition)) {
            foreach ($definition as $key => $value) {
                if (is_string($key) && (str_starts_with($key, 'on ') || str_starts_with($key, 'as '))) {
                    return false;
                }
            }
        }

        return true;
    }

    private function getAppStateValue(string $name): mixed
    {
        if (!$this->isCoroutineContext()) {
            if ($name === 'loadedModules' && !isset($this->fallbackState['loadedModules'])) {
                $this->fallbackState['loadedModules'] = new ArrayObject();
            }

            return $this->fallbackState[$name] ?? null;
        }

        $context = Coroutine::getContext();
        $state = $context[self::CONTEXT_STATE_KEY] ?? null;
        if (!is_array($state)) {
            $state = $this->createInitialState();
            $context[self::CONTEXT_STATE_KEY] = $state;
        }

        if ($name === 'loadedModules' && !$state['loadedModules'] instanceof ArrayObject) {
            $state['loadedModules'] = new ArrayObject();
            $context[self::CONTEXT_STATE_KEY] = $state;
        }

        return $state[$name] ?? null;
    }

    private function setAppStateValue(string $name, mixed $value): void
    {
        if (!$this->isCoroutineContext()) {
            $this->fallbackState[$name] = $value;
            return;
        }

        $context = Coroutine::getContext();
        $state = $context[self::CONTEXT_STATE_KEY] ?? null;
        if (!is_array($state)) {
            $state = $this->createInitialState();
        }
        $state[$name] = $value;
        $context[self::CONTEXT_STATE_KEY] = $state;
    }

    /**
     * @return array<string, mixed>
     */
    private function createInitialState(): array
    {
        return [
            'controller' => null,
            'requestedRoute' => null,
            'requestedAction' => null,
            'requestedParams' => null,
            'state' => self::STATE_BEGIN,
            'language' => $this->defaultLanguage,
            'layout' => $this->defaultLayout,
            'homeUrl' => null,
            'loadedModules' => null,
        ];
    }
}
