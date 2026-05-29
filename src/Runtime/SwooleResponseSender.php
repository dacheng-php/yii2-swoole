<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Runtime;

use Swoole\Http\Response;
use Yii;
use yii\base\InvalidConfigException;
use yii\web\Cookie;
use yii\web\Response as YiiResponse;
use yii\web\ResponseFormatterInterface;

final class SwooleResponseSender
{
    public function send(Response $swooleResponse, YiiResponse $yiiResponse): void
    {
        if (!$this->isResponseWritable($swooleResponse)) {
            return;
        }

        if (!$yiiResponse->isSent) {
            $yiiResponse->trigger(YiiResponse::EVENT_BEFORE_SEND);
            $this->prepareResponseBody($yiiResponse);
        }

        $this->sendHeaders($swooleResponse, $yiiResponse);
        $this->sendCookies($swooleResponse, $yiiResponse);
        $body = $this->captureResponseBody($yiiResponse);
        $swooleResponse->end($body);

        if (!$yiiResponse->isSent) {
            $yiiResponse->trigger(YiiResponse::EVENT_AFTER_SEND);
            $yiiResponse->isSent = true;
        }
    }

    public function isResponseWritable(Response $response): bool
    {
        return $response->isWritable();
    }

    private function prepareResponseBody(YiiResponse $response): void
    {
        if ($response->format === YiiResponse::FORMAT_RAW) {
            if ($response->content === null && $response->data !== null) {
                $response->content = $response->data;
            }
            return;
        }

        $formatter = $response->formatters[$response->format] ?? null;
        if ($formatter === null && isset($response->formatters['default'])) {
            $formatter = $response->formatters['default'];
        }

        if ($formatter !== null) {
            if (!$formatter instanceof ResponseFormatterInterface) {
                $formatter = Yii::createObject($formatter);
            }

            if (!$formatter instanceof ResponseFormatterInterface) {
                throw new InvalidConfigException('Invalid response formatter for format: ' . $response->format);
            }

            $formatter->format($response);
            return;
        }

        if ($response->format === YiiResponse::FORMAT_HTML) {
            if ($response->content === null && $response->data !== null) {
                $response->content = (string) $response->data;
            }
            return;
        }

        throw new InvalidConfigException('Unsupported response format: ' . $response->format);
    }

    private function sendHeaders(Response $swooleResponse, YiiResponse $yiiResponse): void
    {
        $swooleResponse->status($yiiResponse->getStatusCode());

        foreach ($yiiResponse->getHeaders()->toArray() as $name => $values) {
            foreach ((array) $values as $value) {
                $swooleResponse->header($name, (string) $value);
            }
        }
    }

    private function sendCookies(Response $swooleResponse, YiiResponse $yiiResponse): void
    {
        foreach ($yiiResponse->cookies as $cookie) {
            if (!$cookie instanceof Cookie) {
                continue;
            }

            $swooleResponse->cookie(
                $cookie->name,
                (string) $cookie->value,
                $cookie->expire,
                $cookie->path,
                $cookie->domain,
                $cookie->secure,
                $cookie->httpOnly,
                $cookie->sameSite ?? ''
            );
        }
    }

    private function captureResponseBody(YiiResponse $yiiResponse): string
    {
        if ($yiiResponse->stream === null) {
            return (string) $yiiResponse->content;
        }

        $bufferLevel = ob_get_level();
        ob_start();

        try {
            $this->sendStreamContent($yiiResponse);
            return ob_get_contents() ?: '';
        } finally {
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }
        }
    }

    private function sendStreamContent(YiiResponse $response): void
    {
        $stream = $response->stream;

        if (is_callable($stream)) {
            $data = call_user_func($stream);
            foreach ($data as $datum) {
                echo $datum;
                flush();
            }
            return;
        }

        if (is_array($stream)) {
            $this->sendFileStream($stream);
            return;
        }

        Yii::warning('Unknown stream type in response: ' . gettype($stream), __METHOD__);
    }

    private function sendFileStream(array $stream): void
    {
        [$handle, $begin, $end] = $stream;
        $chunkSize = 8 * 1024 * 1024;

        if (is_resource($handle) && stream_get_meta_data($handle)['seekable'] ?? false) {
            fseek($handle, $begin);
        }

        while (is_resource($handle) && !feof($handle) && ($pos = ftell($handle)) <= $end) {
            if ($pos + $chunkSize > $end) {
                $chunkSize = $end - $pos + 1;
            }
            echo fread($handle, $chunkSize);
            flush();
        }

        if (is_resource($handle)) {
            fclose($handle);
        }
    }
}
