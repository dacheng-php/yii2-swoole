<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Coroutine;

use Closure;
use Swoole\Coroutine;
use Yii;
use yii\base\InvalidConfigException;
use yii\db\ConnectionInterface;
use yii\redis\Connection as RedisConnection;
use yii\web\Application;

/**
 * CoroutineApplication provides component isolation for Swoole coroutine environments.
 *
 * Each coroutine gets its own isolated component instances, preventing state leakage
 * between concurrent requests. Shared components (like cache, log) remain singletons
 * across all coroutines.
 *
 * Configuration example:
 * ```php
 * 'components' => [
 *     'db' => [
 *         'class' => \Dacheng\Yii2\Swoole\Db\CoroutineDbConnection::class,
 *         // ...
 *     ],
 * ],
 * 'sharedComponentIds' => ['cache', 'log'],  // Optional: override default shared IDs
 * 'connectionComponentClasses' => [...],      // Optional: custom connection classes
 * ```
 *
 * @property array $sharedComponentIds Component IDs that remain shared across coroutines
 * @property array $persistentComponentIds Component IDs that persist across context resets
 * @property array $connectionComponentClasses Classes that should be closed first during reset
 */
class CoroutineApplication extends Application
{
    private const CONTEXT_COMPONENTS_KEY = '__yiiCoroutineComponents';

    /**
     * @var string[] Component IDs that should remain shared across coroutines.
     * These components are not isolated per-coroutine and behave as singletons.
     */
    public array $sharedComponentIds = [
        'cache',
        'formatter',
        'i18n',
        'log',
        'errorHandler',
        'urlManager',
    ];

    /**
     * @var string[] Component IDs that should not be cleared during context reset.
     * These components may have state that should persist across requests.
     */
    public array $persistentComponentIds = [
        'queue',
    ];

    /**
     * @var string[] Classes or interfaces that identify connection components.
     * Components matching these classes will be closed first (before other cleanup)
     * to ensure connections are returned to pools properly.
     *
     * Extend this list if you have custom connection classes.
     */
    public array $connectionComponentClasses = [
        ConnectionInterface::class,      // yii\db\Connection
        RedisConnection::class,          // yii\redis\Connection
    ];

    public function __get($name)
    {
        if ($this->isCoroutineContext() && !$this->isSharedComponent($name)) {
            $store = $this->getCoroutineComponentStore();
            if (array_key_exists($name, $store)) {
                return $store[$name];
            }

            return $this->get($name, false);
        }

        return parent::__get($name);
    }

    public function __isset($name)
    {
        if ($this->isCoroutineContext() && !$this->isSharedComponent($name)) {
            $store = $this->getCoroutineComponentStore();
            if (array_key_exists($name, $store)) {
                return $store[$name] !== null;
            }

            return $this->has($name);
        }

        return parent::__isset($name);
    }

    /**
     * @inheritdoc
     */
    public function get($id, $throwException = true)
    {
        if (!$this->isCoroutineContext() || $this->isSharedComponent($id)) {
            return parent::get($id, $throwException);
        }

        $store = $this->getCoroutineComponentStore();
        if (array_key_exists($id, $store)) {
            return $store[$id];
        }

        $definitions = $this->getComponents();
        if (!array_key_exists($id, $definitions)) {
            if ($throwException) {
                throw new InvalidConfigException("Unknown component ID: {$id}");
            }

            return null;
        }

        $component = $this->createCoroutineComponent($definitions[$id]);
        $this->setCoroutineComponent($id, $component);

        return $component;
    }

    public function has($id, $checkInstance = false)
    {
        if ($checkInstance && $this->isCoroutineContext() && !$this->isSharedComponent($id)) {
            $store = $this->getCoroutineComponentStore();
            if (array_key_exists($id, $store)) {
                return true;
            }
        }

        return parent::has($id, $checkInstance);
    }

    /**
     * Clears coroutine-bound components and resets per-request application state.
     *
     * This method uses a three-phase cleanup strategy to ensure proper resource management:
     *
     * **Phase 1: Close connection components first**
     * - DB and Redis connections must be returned to pools before other cleanup
     * - This prevents checked-out connections from leaking across requests
     *
     * **Phase 2: Reset components implementing ResettableInterface**
     * - Components like User have state that needs controlled reset
     * - Reset implementations should avoid relying on request-owned connections
     *
     * **Phase 3: Clean up remaining components**
     * - Generic cleanup for components with close/reset/clear methods
     * - Persistent components (e.g., queue) are skipped
     *
     * **Phase 4: Clear stores and application state**
     * - Final cleanup of component store and application-level state
     *
     * @see ResettableInterface
     * @see $connectionComponentClasses
     * @see $persistentComponentIds
     */
    public function resetCoroutineContext(): void
    {
        if (!$this->isCoroutineContext()) {
            return;
        }

        $store = $this->getCoroutineComponentStore();
        $processed = [];

        // Phase 1: Close connection components first to return them to pools
        $this->closeConnectionComponents($store, $processed);

        // Phase 2: Reset components implementing ResettableInterface (e.g., User)
        $this->resetResettableComponents($store, $processed);

        // Phase 3: Clean up remaining components (skip persistent and already processed)
        $this->cleanupRemainingComponents($store, $processed);

        $this->setCoroutineComponentStore([]);

        // Reset application state
        $this->controller = null;
        $this->requestedRoute = null;
        $this->requestedAction = null;
        $this->requestedParams = null;
        $this->requestedModule = null;
        $this->state = self::STATE_BEGIN;

        // Clear coroutine context
        $context = Coroutine::getContext();
        if ($context !== null) {
            unset($context[self::CONTEXT_COMPONENTS_KEY]);
        }
    }

    /**
     * Closes connection components first to ensure connections are returned to pools.
     *
     * @param array $store The component store
     * @param array $processed Track processed component IDs
     */
    private function closeConnectionComponents(array $store, array &$processed): void
    {
        foreach ($store as $id => $component) {
            if (!is_object($component) || isset($processed[$id])) {
                continue;
            }

            if ($this->isConnectionComponent($component)) {
                $this->safeClose($component, $id);
                $processed[$id] = true;
            }
        }
    }

    /**
     * Resets components that implement ResettableInterface.
     *
     * @param array $store The component store
     * @param array $processed Track processed component IDs
     */
    private function resetResettableComponents(array $store, array &$processed): void
    {
        foreach ($store as $id => $component) {
            if (!is_object($component) || isset($processed[$id])) {
                continue;
            }

            if ($component instanceof ResettableInterface) {
                $this->safeReset($component, $id);
                $processed[$id] = true;
            }
        }
    }

    /**
     * Cleans up remaining components via close/reset/clear methods.
     *
     * @param array $store The component store
     * @param array $processed Track processed component IDs
     */
    private function cleanupRemainingComponents(array $store, array &$processed): void
    {
        foreach ($store as $id => $component) {
            if (!is_object($component) || isset($processed[$id])) {
                continue;
            }

            // Skip persistent components
            if ($this->isPersistentComponent($id)) {
                continue;
            }

            // Try cleanup methods in order of preference
            if (method_exists($component, 'close')) {
                $this->safeClose($component, $id);
            } elseif (method_exists($component, 'reset')) {
                $this->safeReset($component, $id);
            } elseif (method_exists($component, 'clear')) {
                $this->safeClear($component, $id);
            }
        }
    }

    /**
     * Checks if a component is a connection that should be closed first.
     *
     * @param object $component The component to check
     * @return bool True if this is a connection component
     */
    private function isConnectionComponent(object $component): bool
    {
        foreach ($this->connectionComponentClasses as $class) {
            if ($component instanceof $class) {
                return true;
            }
        }

        return false;
    }

    /**
     * Safely closes a component, catching any exceptions.
     *
     * @param object $component The component to close
     * @param string $id The component ID (for logging)
     */
    private function safeClose(object $component, string $id): void
    {
        try {
            $component->close();
        } catch (\Throwable $e) {
            \Yii::error("Error closing component '{$id}': " . $e->getMessage(), __METHOD__);
        }
    }

    /**
     * Safely resets a component, catching any exceptions.
     *
     * @param object $component The component to reset
     * @param string $id The component ID (for logging)
     */
    private function safeReset(object $component, string $id): void
    {
        try {
            $component->reset();
        } catch (\Throwable $e) {
            \Yii::error("Error resetting component '{$id}': " . $e->getMessage(), __METHOD__);
        }
    }

    /**
     * Safely clears a component, catching any exceptions.
     *
     * @param object $component The component to clear
     * @param string $id The component ID (for logging)
     */
    private function safeClear(object $component, string $id): void
    {
        try {
            $component->clear();
        } catch (\Throwable $e) {
            \Yii::error("Error clearing component '{$id}': " . $e->getMessage(), __METHOD__);
        }
    }

    /**
     * Checks if a component ID should remain shared across coroutines.
     *
     * @param string $id The component ID
     * @return bool True if the component should be shared
     */
    protected function isSharedComponent(string $id): bool
    {
        return in_array($id, $this->sharedComponentIds, true);
    }

    /**
     * Checks if a component ID should persist across context resets.
     *
     * @param string $id The component ID
     * @return bool True if the component should persist
     */
    protected function isPersistentComponent(string $id): bool
    {
        return in_array($id, $this->persistentComponentIds, true);
    }

    /**
     * Checks if currently running in a coroutine context.
     *
     * @return bool True if in a coroutine context
     */
    protected function isCoroutineContext(): bool
    {
        return Coroutine::getCid() >= 0;
    }

    /**
     * Returns the coroutine-local component store.
     *
     * @return array<string, mixed>
     */
    public function getCoroutineComponentStore(): array
    {
        $context = Coroutine::getContext();
        $store = $context[self::CONTEXT_COMPONENTS_KEY] ?? [];

        if (!is_array($store)) {
            $store = [];
        }

        return $store;
    }

    /**
     * Sets the coroutine-local component store.
     *
     * @param array<string, mixed> $components
     */
    protected function setCoroutineComponentStore(array $components): void
    {
        $context = Coroutine::getContext();
        $context[self::CONTEXT_COMPONENTS_KEY] = $components;
    }

    /**
     * Sets a component in the coroutine-local store.
     *
     * @param string $id The component ID
     * @param mixed $component The component instance
     */
    protected function setCoroutineComponent(string $id, $component): void
    {
        $store = $this->getCoroutineComponentStore();
        $store[$id] = $component;
        $this->setCoroutineComponentStore($store);
    }

    /**
     * Creates a new component instance for coroutine isolation.
     *
     * @param mixed $definition The component definition
     * @return mixed The created component
     */
    private function createCoroutineComponent($definition)
    {
        if (is_object($definition) && !$definition instanceof Closure) {
            return clone $definition;
        }

        return Yii::createObject($definition);
    }
}
