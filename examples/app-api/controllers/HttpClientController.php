<?php

declare(strict_types=1);

namespace app\controllers;

use Dacheng\Yii2\Swoole\HttpClient\Client;
use Dacheng\Yii2\Swoole\HttpClient\Transport;
use Yii;
use yii\web\Controller;
use yii\web\Response;

class HttpClientController extends Controller
{
    public function actionIndex(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $client = new Client([
            'baseUrl' => 'http://httpbun.com',
            'requestConfig' => [
                'format' => Client::FORMAT_JSON,
            ],
            'responseConfig' => [
                'format' => Client::FORMAT_JSON,
            ],
        ]);

        $response = $client->get('get', ['param1' => 'value1', 'param2' => 'value2'])->send();

        return [
            'success' => $response->isOk,
            'statusCode' => $response->statusCode,
            'data' => $response->data,
        ];
    }

    public function actionPost(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $client = new Client([
            'baseUrl' => 'http://httpbun.com',
        ]);

        $response = $client->post('post', ['key' => 'value'])
            ->setFormat(Client::FORMAT_JSON)
            ->send();

        return [
            'success' => $response->isOk,
            'statusCode' => $response->statusCode,
            'data' => $response->data,
        ];
    }

    public function actionBatch(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $client = new Client([
            'baseUrl' => 'http://httpbun.com',
        ]);

        $requests = [
            'get' => $client->get('get', ['test' => '1']),
            'headers' => $client->get('headers'),
            'delay' => $client->get('delay/1'),
        ];

        $startTime = microtime(true);
        $responses = $client->batchSend($requests);
        $elapsed = microtime(true) - $startTime;

        $results = [];
        foreach ($responses as $key => $response) {
            $results[$key] = [
                'success' => $response->isOk,
                'statusCode' => $response->statusCode,
            ];
        }

        return [
            'elapsed' => round($elapsed, 3),
            'note' => 'All requests executed in parallel using coroutines',
            'results' => $results,
        ];
    }

    public function actionHeaders(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $client = new Client([
            'baseUrl' => 'http://httpbun.com',
        ]);

        $response = $client->get('headers')
            ->addHeaders([
                'X-Custom-Header' => 'Custom Value',
                'User-Agent' => 'Yii2-Swoole-HttpClient/1.0',
            ])
            ->send();

        return [
            'success' => $response->isOk,
            'statusCode' => $response->statusCode,
            'data' => $response->data,
        ];
    }

    public function actionTimeout(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $client = new Client([
            'baseUrl' => 'http://httpbun.com',
            'transport' => [
                'class' => Transport::class,
                'requestTimeout' => 2,
            ],
        ]);

        try {
            $response = $client->get('delay/5')->send();

            return [
                'success' => true,
                'message' => 'Request completed',
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'message' => 'Request timed out as expected',
            ];
        }
    }

    public function actionSsl(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $client = new Client([
            'baseUrl' => 'https://httpbun.com',
            'requestConfig' => [
                'options' => [
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                ],
            ],
        ]);

        try {
            $response = $client->get('get')->send();

            return [
                'success' => $response->isOk,
                'statusCode' => $response->statusCode,
                'message' => 'SSL request successful',
            ];
        } catch (\Exception $e) {
            $isSSLError = strpos($e->getMessage(), 'enable-openssl') !== false;
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'message' => $isSSLError 
                    ? 'Swoole not compiled with SSL support. Recompile with --enable-openssl'
                    : 'SSL request failed',
            ];
        }
    }
}

