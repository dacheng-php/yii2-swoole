<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Runtime;

use Swoole\Http\Request;
use yii\base\InvalidConfigException;
use yii\web\Application;
use yii\web\Cookie;
use yii\web\CookieCollection;
use yii\web\Request as YiiRequest;

final class SwooleRequestPopulator
{
    /**
     * Populates a Yii request from a Swoole request.
     */
    public function populate(Application $app, Request $swooleRequest): void
    {
        $yiiRequest = $app->getRequest();

        if (!$yiiRequest instanceof YiiRequest || !method_exists($yiiRequest, 'setMethod')) {
            throw new InvalidConfigException(sprintf(
                'Application "request" component must be an instance of yii\\web\\Request with setMethod(); use %s.',
                CoroutineRequest::class
            ));
        }

        $this->setRequestProperties($yiiRequest, $swooleRequest);
        $this->setHostInfo($yiiRequest, $swooleRequest);
        $this->setUrlProperties($yiiRequest, $swooleRequest);
        $this->populateHeaders($yiiRequest, $swooleRequest);
        $this->populateCookies($yiiRequest, $swooleRequest);
        $this->populateFiles($yiiRequest, $swooleRequest);
    }

    private function setRequestProperties(YiiRequest $request, Request $swooleRequest): void
    {
        $server = $swooleRequest->server ?? [];
        $method = strtoupper($server['request_method'] ?? 'GET');

        $request->setQueryParams($swooleRequest->get ?? []);
        $request->setBodyParams($swooleRequest->post ?? []);
        $request->setRawBody($swooleRequest->rawContent() ?: '');

        $request->setMethod($method);
    }

    private function setHostInfo(YiiRequest $request, Request $swooleRequest): void
    {
        $server = $swooleRequest->server ?? [];
        $headers = $swooleRequest->header ?? [];

        $scheme = $headers['x-forwarded-proto']
            ?? (!empty($server['https']) && $server['https'] !== 'off' ? 'https' : 'http');
        $hostHeader = $headers['host'] ?? ($server['server_name'] ?? '127.0.0.1');

        $request->setHostInfo(sprintf('%s://%s', $scheme, $hostHeader));
    }

    private function setUrlProperties(YiiRequest $request, Request $swooleRequest): void
    {
        $server = $swooleRequest->server ?? [];
        $queryString = $server['query_string'] ?? '';
        $uri = $server['request_uri'] ?? '/';

        $fullUrl = $queryString === '' ? $uri : $uri . '?' . $queryString;
        $pathInfo = $uri;

        if (($pos = strpos($pathInfo, '?')) !== false) {
            $pathInfo = substr($pathInfo, 0, $pos);
        }
        $pathInfo = ltrim($pathInfo, '/');

        $request->setUrl($fullUrl);
        $request->setScriptUrl('/index.php');
        $request->setBaseUrl('');
        $request->setPathInfo($pathInfo);
    }

    private function populateHeaders(YiiRequest $request, Request $swooleRequest): void
    {
        $headers = $swooleRequest->header ?? [];
        $headerCollection = $request->getHeaders();

        $headerCollection->removeAll();
        foreach ($headers as $name => $value) {
            $headerCollection->set($name, $value);
        }
    }

    private function populateCookies(YiiRequest $request, Request $swooleRequest): void
    {
        $cookies = $swooleRequest->cookie ?? [];
        $cookieCollection = $request->getCookies();

        $this->withWritableCookieCollection($cookieCollection, static function () use ($cookieCollection, $cookies): void {
            $cookieCollection->removeAll();
            foreach ($cookies as $name => $value) {
                $cookieCollection->add(new Cookie([
                    'name' => $name,
                    'value' => $value,
                ]));
            }
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

    private function populateFiles(YiiRequest $request, Request $swooleRequest): void
    {
        $files = $swooleRequest->files ?? [];

        if (!empty($files)) {
            $request->setBodyParams(array_merge($request->getBodyParams(), $files));
        }
    }
}
