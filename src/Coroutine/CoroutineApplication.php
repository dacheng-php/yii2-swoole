<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Coroutine;

use Closure;
use Swoole\Coroutine;
use Yii;
use yii\base\InvalidConfigException;
use yii\web\Application;

class CoroutineApplication extends Application
{
    private const CONTEXT_COMPONENTS_KEY = '__yiiCoroutineComponents';

    /**
     * @var string[] component IDs that should remain shared across coroutines.
     */
    protected array $sharedComponentIds = [
        'cache',
        'formatter',
        'i18n',
        'log',
        'errorHandler',
        'urlManager',
    ];
    
    /**
     * @var string[] component IDs that should not be cleared during context reset.
     * These components may have state that should persist across requests.
     */
    protected array $persistentComponentIds = [
        'queue',
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
     */
    public function resetCoroutineContext(): void
    {
        if (!$this->isCoroutineContext()) {
            return;
        }

        $store = $this->getCoroutineComponentStore();
        
        // Close DB/Redis connections first to return them to pool
        if (isset($store['db']) && is_object($store['db']) && method_exists($store['db'], 'close')) {
            try {
                $store['db']->close();
            } catch (\Throwable $e) {
                \Yii::error('Error closing db: ' . $e->getMessage(), __CLASS__);
            }
        }
        
        if (isset($store['redis']) && is_object($store['redis']) && method_exists($store['redis'], 'close')) {
            try {
                $store['redis']->close();
            } catch (\Throwable $e) {
                \Yii::error('Error closing redis: ' . $e->getMessage(), __CLASS__);
            }
        }
        
        // Reset user component
        $userComponent = $store['user'] ?? null;
        if (is_object($userComponent) && method_exists($userComponent, 'reset')) {
            try {
                $userComponent->reset();
            } catch (\Throwable $e) {
                // Ignore errors
            }
        }
        
        // Clean up other components
        foreach ($store as $id => $component) {
            if (!is_object($component)) {
                continue;
            }
            
            if ($this->isPersistentComponent($id)) {
                continue;
            }

            if ($id === 'db' || $id === 'redis' || $id === 'user') {
                continue;
            }

            if (method_exists($component, 'close')) {
                try {
                    $component->close();
                } catch (\Throwable $e) {
                    // Ignore errors
                }
            }

            if (method_exists($component, 'reset')) {
                try {
                    $component->reset();
                } catch (\Throwable $e) {
                    // Ignore errors
                }
            }

            if (method_exists($component, 'clear')) {
                try {
                    $component->clear();
                } catch (\Throwable $e) {
                    // Ignore errors
                }
            }
        }

        $this->setCoroutineComponentStore([]);

        // Reset application state
        $this->controller = null;
        $this->requestedRoute = null;
        $this->requestedAction = null;
        $this->requestedParams = null;

        //todo no defined function
//        $this->requestedModule = null;

        $this->state = self::STATE_BEGIN;
        
        // Clear coroutine context
        $context = Coroutine::getContext();
        if ($context !== null) {
            unset($context[self::CONTEXT_COMPONENTS_KEY]);
        }
    }

    protected function isSharedComponent(string $id): bool
    {
        return in_array($id, $this->sharedComponentIds, true);
    }
    
    protected function isPersistentComponent(string $id): bool
    {
        return in_array($id, $this->persistentComponentIds, true);
    }

    protected function isCoroutineContext(): bool
    {
        $cid = Coroutine::getCid();

        return $cid >= 0;
    }

    /**
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
     * @param array<string, mixed> $components
     */
    protected function setCoroutineComponentStore(array $components): void
    {
        $context = Coroutine::getContext();
        $context[self::CONTEXT_COMPONENTS_KEY] = $components;
    }

    protected function setCoroutineComponent(string $id, $component): void
    {
        $store = $this->getCoroutineComponentStore();
        $store[$id] = $component;
        $this->setCoroutineComponentStore($store);
    }

    private function createCoroutineComponent($definition)
    {
        if (is_object($definition) && !$definition instanceof Closure) {
            return clone $definition;
        }

        return Yii::createObject($definition);
    }
}
