<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Log;

interface CoroutineTargetInterface
{
    public function clearCoroutineMessages(): void;
}
