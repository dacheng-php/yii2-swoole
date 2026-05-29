<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Coroutine;

/**
 * ResettableInterface identifies components that need priority reset during context cleanup.
 *
 * Components implementing this interface will be reset during request scope cleanup.
 * This is useful for components like User and Session that carry request-owned state.
 *
 * The reset() method should:
 * - Clear any per-request state
 * - Reset internal flags and caches
 * - NOT close pooled DB/Redis connections directly (connection components are handled first)
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
 * @see CoroutineApplication::releaseCoroutineComponents()
 */
interface ResettableInterface
{
    /**
     * Resets the component's per-request state.
     *
 * This method is called after connection components are closed and before the
 * coroutine component store is cleared.
     *
 */
    public function reset(): void;
}
