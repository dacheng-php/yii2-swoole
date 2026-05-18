<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Coroutine;

/**
 * ResettableInterface identifies components that need priority reset during context cleanup.
 *
 * Components implementing this interface will be reset (via reset() method) before
 * general component cleanup occurs. This is useful for components like User that
 * need to clear their state before connections are closed.
 *
 * The reset() method should:
 * - Clear any per-request state
 * - Reset internal flags and caches
 * - NOT close connections (that's handled separately for connection components)
 *
 * Example implementation:
 * ```php
 * class CoroutineUser extends \yii\web\User implements ResettableInterface
 * {
 *     public function reset(): void
 *     {
 *         $this->_identity = null;
 *         $this->_access = [];
 *     }
 * }
 * ```
 *
 * @see CoroutineApplication::resetCoroutineContext()
 */
interface ResettableInterface
{
    /**
     * Resets the component's per-request state.
     *
     * This method is called during coroutine context reset, after connection
     * components are closed but before general cleanup of other components.
     *
     * @return void
     */
    public function reset(): void;
}
