<?php

declare(strict_types=1);

namespace yii2swoole\log;

interface CoroutineTargetInterface
{
    public function clearCoroutineMessages(): void;
}
