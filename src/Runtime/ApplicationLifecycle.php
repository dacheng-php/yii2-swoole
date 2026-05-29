<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Runtime;

use Dacheng\Yii2\Swoole\Coroutine\CoroutineApplication;
use Throwable;
use Yii;
use yii\web\CookieCollection;
use yii\web\Response as YiiResponse;

final class ApplicationLifecycle
{
    private ?string $defaultResponseFormat = null;

    public function prepare(CoroutineApplication $app): void
    {
        $this->prepareLogger($app);

        if ($this->defaultResponseFormat === null) {
            $this->defaultResponseFormat = $app->getResponse()->format;
        }
    }

    public function cleanup(CoroutineApplication $app): void
    {
        $app->releaseCoroutineComponents();
        $this->clearResponse($app);
        $this->clearRequest($app);
        $this->flushLogger($app);
        $this->resetView($app);
        $app->clearCoroutineContext();
    }

    private function clearResponse(CoroutineApplication $app): void
    {
        $response = $app->getResponse();

        $response->data = null;
        $response->content = null;
        $response->stream = null;
        $response->format = $this->defaultResponseFormat ?? YiiResponse::FORMAT_HTML;
        $response->clear();
    }

    private function clearRequest(CoroutineApplication $app): void
    {
        $request = $app->getRequest();

        $request->setBodyParams([]);
        $request->setQueryParams([]);
        $request->setRawBody('');

        $request->getHeaders()->removeAll();

        $cookies = $request->getCookies();
        $this->withWritableCookieCollection($cookies, static function () use ($cookies): void {
            $cookies->removeAll();
        });
    }

    private function withWritableCookieCollection(CookieCollection $cookies, callable $callback): void
    {
        $originalReadOnly = $cookies->readOnly;
        $cookies->readOnly = false;

        try {
            $callback();
        } finally {
            $cookies->readOnly = $originalReadOnly;
        }
    }

    private function flushLogger(CoroutineApplication $app): void
    {
        if (!$app->has('log')) {
            return;
        }

        $log = $app->getLog();
        $logger = $log->getLogger();

        if ($logger instanceof \yii\log\Logger) {
            $logger->flush(true);
            $logger->messages = [];

            foreach ($log->targets as $target) {
                if ($target instanceof \yii\log\Target) {
                    $target->messages = [];
                }
            }
        }
    }

    private function prepareLogger(CoroutineApplication $app): void
    {
        if (!$app->has('log')) {
            return;
        }

        $log = $app->getLog();
        $logger = $log->getLogger();

        if ($logger instanceof \yii\log\Logger) {
            $logger->flush(true);
            $logger->messages = [];
        }
    }

    private function resetView(CoroutineApplication $app): void
    {
        $store = $app->getCoroutineComponentStore();
        if (!isset($store['view'])) {
            return;
        }

        try {
            $store['view']->params = [];
        } catch (Throwable $e) {
            Yii::error('Error resetting view params: ' . $e->getMessage(), __CLASS__);
        }
    }
}
