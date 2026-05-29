<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Runtime;

use yii\web\Request;

final class CoroutineRequest extends Request
{
    private ?string $method = null;

    public function getMethod()
    {
        return $this->method ?? parent::getMethod();
    }

    public function setMethod(?string $method): void
    {
        $this->method = $method === null ? null : strtoupper($method);
    }
}
