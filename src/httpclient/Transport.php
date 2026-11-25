<?php

declare(strict_types=1);

namespace yii2swoole\httpclient;

use yii2swoole\coroutine\Concurrent;
use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client as SwooleHttpClient;
use Throwable;
use Yii;
use yii\httpclient\Exception;
use yii\httpclient\Request;
use yii\httpclient\Response;
use yii\httpclient\Transport as BaseTransport;

/**
 * Non-blocking Swoole coroutine HTTP transport for yiisoft/yii2-httpclient.
 *
 * - Proper setData() + execute($path) handling for PUT/PATCH/DELETE/OPTIONS/HEAD.
 * - Uses Coroutine\Concurrent for bounded parallel batchSend() without relying on go().
 * - Always closes client sockets in finally to avoid file descriptor leaks.
 */
class Transport extends BaseTransport
{
    public float $connectionTimeout = 3.0;

    public float $requestTimeout = 10.0;

    public bool $sslVerifyPeer = true;

    public bool $sslVerifyHost = true;

    public ?string $sslCafile = null;

    public ?string $sslCapath = null;

    /**
     * Maximum concurrent requests during batchSend() (0 = unlimited).
     */
    public int $maxBatchConcurrency = 20;

    public function send($request): Response
    {
        if (Coroutine::getCid() < 0) {
            throw new Exception('HttpClient\\Transport::send() requires an active Swoole coroutine context.');
        }

        $request->beforeSend();
        $request->prepare();

        $parsed = $this->parseUrl($request->getFullUrl());

        try {
            $client = new SwooleHttpClient($parsed['host'], $parsed['port'], $parsed['ssl']);
        } catch (Throwable $e) {
            throw new Exception('Failed to create Swoole HTTP client: ' . $e->getMessage(), (int) $e->getCode(), $e);
        }

        $this->configureClient($client, $request);

        $token = $request->client->createRequestLogToken(
            $request->getMethod(),
            $request->getFullUrl(),
            $request->composeHeaderLines(),
            $this->formatLogContent($request->getContent())
        );

        Yii::info($token, __METHOD__);
        Yii::beginProfile($token, __METHOD__);

        try {
            $response = $this->executeRequest($client, $request, $parsed['path']);
        } catch (Throwable $e) {
            Yii::endProfile($token, __METHOD__);
            if ($e instanceof Exception) {
                throw $e;
            }
            throw new Exception('HTTP request failed: ' . $e->getMessage(), (int) $e->getCode(), $e);
        } finally {
            $client->close();
        }

        Yii::endProfile($token, __METHOD__);
        $request->afterSend($response);

        return $response;
    }

    public function batchSend(array $requests): array
    {
        if (Coroutine::getCid() < 0) {
            return parent::batchSend($requests);
        }

        $tasks = [];
        foreach ($requests as $key => $request) {
            $tasks[$key] = fn (): Response => $this->send($request);
        }

        return Concurrent::run($tasks, $this->maxBatchConcurrency);
    }

    /**
     * @return array{host:string,port:int,ssl:bool,path:string}
     */
    private function parseUrl(string $url): array
    {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            throw new Exception('Invalid URL: ' . $url);
        }

        $ssl = strtolower((string) ($parts['scheme'] ?? 'http')) === 'https';
        $port = isset($parts['port']) ? (int) $parts['port'] : ($ssl ? 443 : 80);
        // RFC 9110: URI fragments (#...) are client-side only and must not be sent on the wire
        $path = ($parts['path'] ?? '/')
            . (isset($parts['query']) ? '?' . $parts['query'] : '');

        return [
            'host' => $parts['host'],
            'port' => $port,
            'ssl' => $ssl,
            'path' => $path,
        ];
    }

    private function configureClient(SwooleHttpClient $client, Request $request): void
    {
        $options = $request->getOptions();
        $timeout = isset($options['timeout']) ? (float) $options['timeout'] : $this->requestTimeout;

        $settings = [
            'timeout' => $timeout,
            'connect_timeout' => $this->connectionTimeout,
            'keep_alive' => (bool) ($options['keep_alive'] ?? false),
            'ssl_verify_peer' => isset($options[CURLOPT_SSL_VERIFYPEER])
                ? (bool) $options[CURLOPT_SSL_VERIFYPEER]
                : $this->sslVerifyPeer,
            'ssl_verify_host' => isset($options[CURLOPT_SSL_VERIFYHOST])
                ? (bool) $options[CURLOPT_SSL_VERIFYHOST]
                : $this->sslVerifyHost,
        ];

        if (isset($options[CURLOPT_CAINFO])) {
            $settings['ssl_cafile'] = $options[CURLOPT_CAINFO];
        } elseif ($this->sslCafile !== null) {
            $settings['ssl_cafile'] = $this->sslCafile;
        }

        if (isset($options[CURLOPT_CAPATH])) {
            $settings['ssl_capath'] = $options[CURLOPT_CAPATH];
        } elseif ($this->sslCapath !== null) {
            $settings['ssl_capath'] = $this->sslCapath;
        }

        if (isset($options[CURLOPT_SSLCERT])) {
            $settings['ssl_cert_file'] = $options[CURLOPT_SSLCERT];
        }
        if (isset($options[CURLOPT_SSLKEY])) {
            $settings['ssl_key_file'] = $options[CURLOPT_SSLKEY];
        }

        $client->set($settings);

        $headers = [];
        foreach ($request->getHeaders()->toArray() as $name => $values) {
            $headers[$name] = implode(', ', (array) $values);
        }
        if ($headers !== []) {
            $client->setHeaders($headers);
        }

        $cookies = [];
        foreach ($request->getCookies() as $cookie) {
            $cookies[$cookie->name] = (string) $cookie->value;
        }
        if ($cookies !== []) {
            $client->setCookies($cookies);
        }
    }

    private function executeRequest(SwooleHttpClient $client, Request $request, string $path): Response
    {
        $method = strtoupper($request->getMethod());
        $content = $request->getContent();

        $client->setMethod($method);
        if ($content !== null && $content !== '' && !in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            $client->setData($content);
        }

        $success = $client->execute($path);
        if (!$success || $client->statusCode < 0) {
            $errCode = $client->errCode;
            $errMsg = $client->errMsg ?: ($errCode ? swoole_strerror($errCode) : 'Unknown network error');
            throw new Exception("HTTP request failed (status={$client->statusCode}, errCode={$errCode}): {$errMsg}");
        }

        $responseHeaders = [
            sprintf('HTTP/1.1 %d %s', $client->statusCode, $this->getReasonPhrase($client->statusCode)),
        ];

        if (is_array($client->headers)) {
            foreach ($client->headers as $name => $value) {
                $responseHeaders[] = "{$name}: {$value}";
            }
        }

        if (is_array($client->set_cookie_headers)) {
            foreach ($client->set_cookie_headers as $cookie) {
                $responseHeaders[] = "Set-Cookie: {$cookie}";
            }
        }

        return $request->client->createResponse((string) $client->body, $responseHeaders);
    }

    private function formatLogContent(mixed $content): string
    {
        if (is_scalar($content) || $content === null) {
            return (string) $content;
        }

        return var_export($content, true);
    }

    private function getReasonPhrase(int $statusCode): string
    {
        return \yii\web\Response::$httpStatuses[$statusCode] ?? 'Unknown';
    }
}
