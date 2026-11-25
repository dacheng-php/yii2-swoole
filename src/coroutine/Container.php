<?php

declare(strict_types=1);

namespace yii2swoole\coroutine;

use Swoole\Coroutine;
use yii\di\Container as BaseContainer;

/**
 * Coroutine-isolated Yii2 DI Container.
 *
 * Singletons registered via setSingleton() are isolated per coroutine by default to prevent
 * cross-request state leakage, while explicitly whitelisted stateless classes remain shared
 * across the worker process.
 */
class Container extends BaseContainer
{
    private const CONTEXT_SINGLETONS_KEY = '__yii2SwooleDiSingletons';

    /**
     * Classes/interfaces safe to share as worker-wide singletons.
     *
     * @var string[]
     */
    public array $sharedSingletonClasses = [
        'yii\base\Security',
        'yii\i18n\I18N',
        'yii\i18n\Formatter',
    ];

    /**
     * @var array<string, true>
     */
    private array $coroutineSingletons = [];

    /**
     * @var array<string, bool>
     */
    private array $shareDecisionCache = [];

    public function setSingleton($class, $definition = [], $params = [])
    {
        if ($this->shouldShare((string) $class)) {
            return parent::setSingleton($class, $definition, $params);
        }

        $this->coroutineSingletons[(string) $class] = true;

        return $this->set($class, $definition, $params);
    }

    public function hasSingleton($class, $checkInstance = false)
    {
        $class = (string) $class;
        if (isset($this->coroutineSingletons[$class])) {
            if ($checkInstance) {
                return $this->getCoroutineSingleton($class) !== null;
            }

            return true;
        }

        return parent::hasSingleton($class, $checkInstance);
    }

    public function get($class, $params = [], $config = [])
    {
        $className = (string) $class;
        if (!$this->isCoroutineContext() || !isset($this->coroutineSingletons[$className])) {
            return parent::get($class, $params, $config);
        }

        $instance = $this->getCoroutineSingleton($className);
        if ($instance === null) {
            $instance = parent::get($class, $params, $config);
            $this->setCoroutineSingleton($className, $instance);
        }

        return $instance;
    }

    public function clear($class): void
    {
        unset($this->coroutineSingletons[(string) $class], $this->shareDecisionCache[(string) $class]);
        parent::clear($class);
    }

    public function getCoroutineSingletonCount(): int
    {
        if ($this->coroutineSingletons === [] || !$this->isCoroutineContext()) {
            return 0;
        }

        $store = Coroutine::getContext()[self::CONTEXT_SINGLETONS_KEY] ?? null;

        return is_array($store) ? count($store) : 0;
    }

    public function clearCoroutineSingletons(): void
    {
        if ($this->coroutineSingletons === [] || !$this->isCoroutineContext()) {
            return;
        }

        $context = Coroutine::getContext();
        if (isset($context[self::CONTEXT_SINGLETONS_KEY])) {
            unset($context[self::CONTEXT_SINGLETONS_KEY]);
        }
    }

    protected function shouldShare(string $class): bool
    {
        if (isset($this->shareDecisionCache[$class])) {
            return $this->shareDecisionCache[$class];
        }

        foreach ($this->sharedSingletonClasses as $sharedClass) {
            if ($class === $sharedClass || is_subclass_of($class, $sharedClass)) {
                return $this->shareDecisionCache[$class] = true;
            }
        }

        return $this->shareDecisionCache[$class] = false;
    }

    protected function isCoroutineContext(): bool
    {
        return Coroutine::getCid() >= 0;
    }

    private function getCoroutineSingleton(string $class): mixed
    {
        $context = Coroutine::getContext();

        return $context[self::CONTEXT_SINGLETONS_KEY][$class] ?? null;
    }

    private function setCoroutineSingleton(string $class, mixed $instance): void
    {
        $context = Coroutine::getContext();
        $store = $context[self::CONTEXT_SINGLETONS_KEY] ?? [];
        $store[$class] = $instance;
        $context[self::CONTEXT_SINGLETONS_KEY] = $store;
    }
}
