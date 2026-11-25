<?php

declare(strict_types=1);

namespace yii2swoole\coroutine;

/**
 * Contract for components that hold per-request/per-coroutine mutable state
 * and need deterministic cleanup at the end of a request.
 */
interface ResettableInterface
{
    public function reset(): void;
}
