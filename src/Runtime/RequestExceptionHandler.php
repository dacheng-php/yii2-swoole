<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Runtime;

use Swoole\Http\Response;
use Throwable;
use Yii;
use yii\base\ErrorHandler;
use yii\base\UserException;
use yii\web\Application;
use yii\web\HttpException;
use yii\web\Response as YiiResponse;

final class RequestExceptionHandler
{
    public function __construct(private readonly SwooleResponseSender $responseSender)
    {
    }

    public function handle(Throwable $exception, Application $app, Response $response): void
    {
        try {
            if (!$app->has('errorHandler')) {
                $this->handleFallbackException($exception, $response);
                return;
            }

            /** @var ErrorHandler|null $errorHandler */
            $errorHandler = $app->getErrorHandler();
            if ($errorHandler === null) {
                $this->handleFallbackException($exception, $response);
                return;
            }

            $errorHandler->exception = $exception;
            $errorHandler->logException($exception);

            $yiiResponse = $app->getResponse();
            if ($yiiResponse->isSent) {
                $yiiResponse->isSent = false;
            }

            if ($exception instanceof HttpException) {
                $yiiResponse->setStatusCode($exception->statusCode);
            } else {
                $yiiResponse->setStatusCode(500);
            }

            $isDebug = defined('YII_DEBUG') && YII_DEBUG;

            if ($yiiResponse->format === YiiResponse::FORMAT_JSON) {
                $yiiResponse->data = $this->formatExceptionToArray($exception, $isDebug);
            } else {
                $yiiResponse->data = $this->renderExceptionHtml($errorHandler, $exception, $isDebug);
            }

            if ($this->responseSender->isResponseWritable($response)) {
                $this->responseSender->send($response, $yiiResponse);
            }
        } catch (Throwable $handlerException) {
            Yii::error($handlerException, __METHOD__ . '::errorHandler');
            $this->handleFallbackException($exception, $response);
        } finally {
            if (isset($errorHandler) && $errorHandler instanceof ErrorHandler) {
                $errorHandler->exception = null;
            }
        }
    }

    private function handleFallbackException(Throwable $exception, Response $response): void
    {
        Yii::error($exception, __METHOD__);

        if (!$this->responseSender->isResponseWritable($response)) {
            return;
        }

        $isDebug = defined('YII_DEBUG') && YII_DEBUG;
        $body = $isDebug ? (string) $exception : 'Internal Server Error';

        $response->status(500);
        $response->header('Content-Type', 'text/plain; charset=UTF-8');
        $response->end($body);
    }

    private function renderExceptionHtml(ErrorHandler $errorHandler, Throwable $exception, bool $isDebug): string
    {
        $useErrorView = !$isDebug || $exception instanceof UserException;
        $viewFile = $useErrorView ? $errorHandler->errorView : $errorHandler->exceptionView;

        return $errorHandler->renderFile($viewFile, [
            'exception' => $exception,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatExceptionToArray(Throwable $exception, bool $debug = false): array
    {
        if (!$debug) {
            if (!($exception instanceof UserException) && !($exception instanceof HttpException)) {
                $exception = new HttpException(500, Yii::t('yii', 'An internal server error occurred.'));
            }
        }

        $array = [
            'name' => ($exception instanceof \yii\base\Exception || $exception instanceof \yii\base\ErrorException)
                ? $exception->getName()
                : 'Exception',
            'message' => $exception->getMessage(),
            'code' => $exception->getCode(),
        ];

        if ($exception instanceof HttpException) {
            $array['status'] = $exception->statusCode;
        }

        if ($debug) {
            $array['type'] = get_class($exception);
            $array['file'] = $exception->getFile();
            $array['line'] = $exception->getLine();
            $array['stack-trace'] = explode("\n", $exception->getTraceAsString());
        }

        if (($prev = $exception->getPrevious()) !== null) {
            $array['previous'] = $this->formatExceptionToArray($prev, $debug);
        }

        return $array;
    }
}
