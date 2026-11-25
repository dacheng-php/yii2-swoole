<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Debug\Panels;

use Dacheng\Yii2\Swoole\Redis\Session as SwooleRedisSession;
use Dacheng\Yii2\Swoole\Web\Request as SwooleWebRequest;
use Yii;
use yii\base\InlineAction;
use yii\debug\panels\RequestPanel as BaseRequestPanel;

/**
 * Coroutine-safe RequestPanel reading request, response headers, and session data
 * from Web\Request / Web\Response / Redis\Session instead of PHP superglobals and headers_list().
 */
class RequestPanel extends BaseRequestPanel
{
    public function save(): array
    {
        $request = Yii::$app->getRequest();
        $response = Yii::$app->getResponse();
        $hasCensorList = count($this->censoredVariableNames) > 0;

        $requestHeaders = [];
        foreach ($request->getHeaders() as $name => $value) {
            if ($hasCensorList && in_array($name, $this->censoredVariableNames, true)) {
                $value = $this->censorString;
            }
            $requestHeaders[$name] = (is_array($value) && count($value) === 1) ? current($value) : $value;
        }

        $responseHeaders = [];
        foreach ($response->getHeaders()->toArray() as $name => $values) {
            $headerName = str_replace(' ', '-', ucwords(str_replace('-', ' ', (string) $name)));
            if ($hasCensorList && (in_array($headerName, $this->censoredVariableNames, true) || in_array($name, $this->censoredVariableNames, true))) {
                $responseHeaders[$headerName] = $this->censorString;
            } elseif (is_array($values) && count($values) === 1) {
                $responseHeaders[$headerName] = current($values);
            } else {
                $responseHeaders[$headerName] = $values;
            }
        }

        if (Yii::$app->requestedAction) {
            if (Yii::$app->requestedAction instanceof InlineAction) {
                $action = get_class(Yii::$app->requestedAction->controller) . '::' . Yii::$app->requestedAction->actionMethod . '()';
            } else {
                $action = get_class(Yii::$app->requestedAction) . '::run()';
            }
        } else {
            $action = null;
        }

        $rawBody = $request->getRawBody();
        $data = [
            'flashes' => $this->getFlashes(),
            'statusCode' => $response->getStatusCode(),
            'requestHeaders' => $requestHeaders,
            'responseHeaders' => $responseHeaders,
            'route' => Yii::$app->requestedAction ? Yii::$app->requestedAction->getUniqueId() : Yii::$app->requestedRoute,
            'action' => $action,
            'actionParams' => Yii::$app->requestedParams,
            'general' => [
                'method' => $request->getMethod(),
                'isAjax' => $request->getIsAjax(),
                'isPjax' => $request->getIsPjax(),
                'isFlash' => $request->getIsFlash(),
                'isSecureConnection' => $request->getIsSecureConnection(),
            ],
            'requestBody' => $rawBody === '' ? [] : [
                'Content Type' => $request->getContentType(),
                'Raw' => $rawBody,
                'Decoded' => $request->getBodyParams(),
            ],
        ];

        $swooleReq = $request instanceof SwooleWebRequest ? $request->getSwooleRequest() : null;
        foreach ($this->displayVars as $name) {
            $key = trim($name, '_');
            if ($swooleReq !== null) {
                $data[$key] = match ($name) {
                    '_GET' => $request->getQueryParams(),
                    '_POST' => $swooleReq->post ?? [],
                    '_COOKIE' => $swooleReq->cookie ?? [],
                    '_FILES' => $swooleReq->files ?? [],
                    '_SERVER' => $swooleReq->server ?? [],
                    '_SESSION' => $this->getSessionSnapshot(),
                    default => empty($GLOBALS[$name]) ? [] : $GLOBALS[$name],
                };
            } else {
                $data[$key] = empty($GLOBALS[$name]) ? [] : $GLOBALS[$name];
            }
        }

        return $this->censorArray($data);
    }

    protected function getFlashes(): array
    {
        $session = Yii::$app->has('session', true) ? Yii::$app->get('session') : null;
        if ($session === null || !$session->getIsActive()) {
            return [];
        }

        $counters = $session->get($session->flashParam, []);
        if (!is_array($counters) || $counters === []) {
            return [];
        }

        $flashes = [];
        if ($session instanceof SwooleRedisSession) {
            $snapshot = $session->getSessionData();
            foreach (array_keys($counters) as $key) {
                if (array_key_exists($key, $snapshot)) {
                    $flashes[$key] = $snapshot[$key];
                }
            }

            return $flashes;
        }

        foreach (array_keys($counters) as $key) {
            if ($session->has($key)) {
                $flashes[$key] = $session->get($key);
            }
        }

        return $flashes;
    }

    /**
     * @return array<string, mixed>
     */
    private function getSessionSnapshot(): array
    {
        $session = Yii::$app->has('session', true) ? Yii::$app->get('session') : null;
        if ($session === null || !$session->getIsActive()) {
            return [];
        }

        if ($session instanceof SwooleRedisSession) {
            return $session->getSessionData();
        }

        return iterator_to_array($session);
    }
}
