<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Coroutine;

use Swoole\Coroutine;
use yii\di\Container;

/**
 * CoroutineContainer provides coroutine-isolated singleton resolutions for Yii2 DI Container.
 *
 * It prevents state leakage between concurrent requests/coroutines by storing
 * singleton instances in Swoole's coroutine-local context rather than a global
 * static memory store. Whitelisted stateless/global classes remain shared globally.
 */
class CoroutineContainer extends Container
{
    private const CONTEXT_SINGLETONS_KEY = '__yiiCoroutineSingletons';

    /**
     * @var array<string, bool> Whitelist of classes/interfaces that are whitelisted to be shared globally.
     */
    public array $sharedSingletonClasses = [
        'yii\base\Security',
        'yii\i18n\I18N',
        'yii\i18n\Formatter',
    ];

    /**
     * @var array<string, bool> List of registered isolated singleton classes/interfaces.
     */
    private array $_singletonDefinitions = [];

    /**
     * @inheritdoc
     */
    public function setSingleton($class, $definition = [], $params = [])
    {
        if ($this->shouldShare($class)) {
            return parent::setSingleton($class, $definition, $params);
        }

        $this->_singletonDefinitions[$class] = true;

        // Register as a normal definition in the parent container so that it resolves fresh instances
        return $this->set($class, $definition, $params);
    }

    /**
     * @inheritdoc
     */
    public function hasSingleton($class, $checkInstance = false)
    {
        if (isset($this->_singletonDefinitions[$class])) {
            if ($checkInstance) {
                return $this->getCoroutineSingleton($class) !== null;
            }
            return true;
        }

        return parent::hasSingleton($class, $checkInstance);
    }

    /**
     * @inheritdoc
     */
    public function get($class, $params = [], $config = [])
    {
        if (!$this->isCoroutineContext() || $this->shouldShare($class)) {
            return parent::get($class, $params, $config);
        }

        if (isset($this->_singletonDefinitions[$class])) {
            $instance = $this->getCoroutineSingleton($class);
            if ($instance === null) {
                $instance = parent::get($class, $params, $config);
                $this->setCoroutineSingleton($class, $instance);
            }
            return $instance;
        }

        return parent::get($class, $params, $config);
    }

    /**
     * Checks if a class should be shared globally across all coroutines.
     *
     * @param string $class Class or interface name
     * @return bool True if whitelisted for global sharing
     */
    protected function shouldShare(string $class): bool
    {
        foreach ($this->sharedSingletonClasses as $sharedClass) {
            if ($class === $sharedClass || is_subclass_of($class, $sharedClass)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Checks if executing within a valid Swoole coroutine context.
     *
     * @return bool True if inside a coroutine
     */
    protected function isCoroutineContext(): bool
    {
        return Coroutine::getCid() >= 0;
    }

    /**
     * Retrieves a singleton instance from the coroutine-local context.
     *
     * @param string $class Class/interface name
     * @return mixed|null The resolved instance, or null if not resolved yet
     */
    private function getCoroutineSingleton(string $class)
    {
        $context = Coroutine::getContext();
        if ($context === null) {
            return null;
        }

        $store = $context[self::CONTEXT_SINGLETONS_KEY] ?? [];
        return $store[$class] ?? null;
    }

    /**
     * Saves a singleton instance into the coroutine-local context.
     *
     * @param string $class Class/interface name
     * @param mixed $instance The resolved instance
     * @return void
     */
    private function setCoroutineSingleton(string $class, $instance): void
    {
        $context = Coroutine::getContext();
        if ($context !== null) {
            $store = $context[self::CONTEXT_SINGLETONS_KEY] ?? [];
            $store[$class] = $instance;
            $context[self::CONTEXT_SINGLETONS_KEY] = $store;
        }
    }
}
