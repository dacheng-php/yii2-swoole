<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Web;

use Throwable;
use Yii;
use yii\base\UserException;
use yii\web\ErrorHandler as BaseErrorHandler;
use yii\web\HttpException;
use yii\web\Response as BaseResponse;

/**
 * Coroutine-safe Yii2 Web ErrorHandler.
 *
 * Never registers PHP global shutdown/exception handlers and never calls exit().
 * Fully supports Yii2 errorAction routing, JSON/XML/HTML/RAW response formats,
 * and safe fallback when rendering itself throws.
 */
class ErrorHandler extends BaseErrorHandler
{
    public function register(): void
    {
        // Intentionally a no-op: Swoole RequestHandler catches and dispatches Throwables per coroutine.
    }

    public function unregister(): void
    {
        // Intentionally a no-op.
    }

    public function handleException($exception): void
    {
        $this->exception = $exception;

        try {
            $this->logException($exception);
            $this->renderException($exception);
        } catch (Throwable $e) {
            Yii::error($e, __METHOD__);
            $this->renderFallback($exception);
        } finally {
            $this->exception = null;
        }
    }

    protected function renderException($exception): void
    {
        $response = Yii::$app->getResponse();
        $swooleResponse = $response instanceof Response ? $response->getSwooleResponse() : null;

        if ($swooleResponse !== null && !$swooleResponse->isWritable()) {
            return;
        }

        $originalFormat = $response->format;
        $response->clear();
        $response->format = $originalFormat;
        $response->setStatusCodeByException($exception);

        $useErrorView = $response->format === BaseResponse::FORMAT_HTML
            && (!defined('YII_DEBUG') || !YII_DEBUG || $exception instanceof UserException);

        if ($useErrorView && $this->errorAction !== null) {
            $result = Yii::$app->runAction($this->errorAction);
            if ($result instanceof BaseResponse) {
                $response = $result;
                if ($response instanceof Response && $response->getSwooleResponse() === null && $swooleResponse !== null) {
                    $response->bindSwooleResponse($swooleResponse);
                }
            } else {
                $response->data = $result;
            }
        } elseif ($response->format === BaseResponse::FORMAT_HTML) {
            $viewFile = $useErrorView ? $this->errorView : $this->exceptionView;
            $response->data = $this->renderFile($viewFile, ['exception' => $exception]);
        } elseif ($response->format === BaseResponse::FORMAT_RAW) {
            $response->data = (defined('YII_DEBUG') && YII_DEBUG) ? (string) $exception : 'Internal Server Error';
        } else {
            $response->data = $this->convertExceptionToArray($exception);
        }

        $response->send();
    }

    private function renderFallback(Throwable $exception): void
    {
        $ctx = RequestContext::current();
        $swooleResponse = $ctx?->swooleResponse;
        if ($swooleResponse === null || !$swooleResponse->isWritable()) {
            return;
        }

        $status = $exception instanceof HttpException ? $exception->statusCode : 500;
        $isDebug = defined('YII_DEBUG') && YII_DEBUG;
        $body = $isDebug ? (string) $exception : 'Internal Server Error';

        $swooleResponse->status($status);
        $swooleResponse->header('Content-Type', 'text/plain; charset=UTF-8');
        $swooleResponse->end($body);
    }
}
