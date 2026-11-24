<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\HttpClient;

use yii\httpclient\Client as BaseClient;

/**
 * Coroutine-native HTTP Client for Yii2.
 *
 * Defaults to HttpClient\Transport without eagerly loading StreamTransport when Swoole is enabled.
 */
class Client extends BaseClient
{
    public bool $enableCoroutine = true;

    private bool $customTransportSet = false;

    public function setTransport($transport): void
    {
        $this->customTransportSet = true;
        parent::setTransport($transport);
    }

    public function init(): void
    {
        parent::init();

        if ($this->enableCoroutine && extension_loaded('swoole') && !$this->customTransportSet) {
            parent::setTransport(Transport::class);
        }
    }
}
