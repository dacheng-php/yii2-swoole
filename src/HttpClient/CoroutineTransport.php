<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\HttpClient;

use Swoole\Coroutine\Http\Client as SwooleHttpClient;
use yii\httpclient\Exception;
use yii\httpclient\Request;
use yii\httpclient\Response;
use yii\httpclient\Transport;
use Yii;

/**
 * CoroutineTransport provides HTTP client functionality for Swoole coroutine environments.
 *
 * This transport uses Swoole's coroutine HTTP client for non-blocking HTTP requests.
 * It supports SSL/TLS with secure defaults (verification enabled by default).
 *
 * Configuration example:
 * ```php
 * 'httpClient' => [
 *     'class' => \yii\httpclient\Client::class,
 *     'transport' => [
 *         'class' => \Dacheng\Yii2\Swoole\HttpClient\CoroutineTransport::class,
 *         'sslVerifyPeer' => true,           // Verify SSL certificate (default: true)
 *         'sslVerifyHost' => true,           // Verify host name (default: true)
 *         'sslCafile' => '/path/to/ca.pem',  // Custom CA bundle (optional)
 *     ],
 * ],
 * ```
 *
 * Security note: SSL verification is enabled by default. Only disable it for
 * development/testing purposes or when connecting to known internal services.
 *
 * @property int $connectionTimeout Connection timeout in seconds
 * @property int $requestTimeout Request timeout in seconds
 * @property bool $keepAlive Whether to keep connections alive
 * @property bool $sslVerifyPeer Whether to verify SSL peer certificate
 * @property bool $sslVerifyHost Whether to verify SSL host name
 * @property string|null $sslCafile Path to CA certificate file
 * @property string|null $sslCapath Path to CA certificate directory
 */
class CoroutineTransport extends Transport
{
    /**
     * @var int Connection timeout in seconds
     */
    public int $connectionTimeout = 3;

    /**
     * @var int Request timeout in seconds
     */
    public int $requestTimeout = 10;

    /**
     * @var bool Whether to keep connections alive for reuse
     */
    public bool $keepAlive = true;

    /**
     * @var bool Whether to verify the SSL peer certificate.
     *
     * IMPORTANT: This should be TRUE in production for security.
     * Only set to FALSE for development/testing or known internal services.
     */
    public bool $sslVerifyPeer = true;

    /**
     * @var bool Whether to verify the SSL certificate host name.
     *
     * When TRUE, verifies that the certificate common name or subject alt name
     * matches the host name being connected to.
     */
    public bool $sslVerifyHost = true;

    /**
     * @var string|null Path to a file containing CA certificates in PEM format.
     *
     * If not set, Swoole will use the system's default CA bundle.
     * Useful when connecting to services with self-signed or internal CA certificates.
     */
    public ?string $sslCafile = null;

    /**
     * @var string|null Path to a directory containing CA certificates.
     *
     * Alternative to sslCafile for loading certificates from a directory.
     */
    public ?string $sslCapath = null;

    public function send($request): Response
    {
        $request->beforeSend();

        $parsedUrl = $this->parseUrl($request->getFullUrl());

        try {
            $client = new SwooleHttpClient(
                $parsedUrl['host'],
                $parsedUrl['port'],
                $parsedUrl['ssl']
            );
        } catch (\Throwable $e) {
            throw new Exception('Failed to create HTTP client: ' . $e->getMessage(), $e->getCode(), $e);
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
            $response = $this->executeRequest($client, $request, $parsedUrl['path']);
        } catch (\Throwable $e) {
            $client->close();
            Yii::endProfile($token, __METHOD__);
            throw new Exception('Request failed: ' . $e->getMessage(), $e->getCode(), $e);
        }

        // Only close connection if keepAlive is disabled
        // When keepAlive is true, let Swoole manage the connection for potential reuse
        if (!$this->keepAlive) {
            $client->close();
        }

        Yii::endProfile($token, __METHOD__);

        $request->afterSend($response);

        return $response;
    }

    /**
     * Formats request content for Yii HTTP client logging.
     *
     * @param mixed $content
     */
    private function formatLogContent($content): string
    {
        if (is_scalar($content) || $content === null) {
            return (string) $content;
        }

        return var_export($content, true);
    }

    /**
     * Closes all keep-alive connections.
     *
     * Call this method during application shutdown to ensure
     * all connections are properly closed.
     */
    public function closeAllConnections(): void
    {
        // Note: Swoole coroutine HTTP client doesn't have a global connection pool
        // Each client instance manages its own connection
        // This method is provided for future connection pool implementation
    }

    public function batchSend(array $requests): array
    {
        if (\Swoole\Coroutine::getCid() < 0) {
            return parent::batchSend($requests);
        }

        $results = [];
        $channels = [];

        foreach ($requests as $key => $request) {
            $channels[$key] = new \Swoole\Coroutine\Channel(1);

            go(function () use ($request, $key, $channels) {
                try {
                    $response = $this->send($request);
                    $channels[$key]->push(['success' => true, 'response' => $response]);
                } catch (\Throwable $e) {
                    $channels[$key]->push(['success' => false, 'error' => $e]);
                }
            });
        }

        foreach ($channels as $key => $channel) {
            $result = $channel->pop();

            if (!$result['success']) {
                throw new Exception('Batch request failed: ' . $result['error']->getMessage(), 0, $result['error']);
            }

            $results[$key] = $result['response'];
        }

        return $results;
    }

    /**
     * Parses a URL into its components.
     *
     * @param string $url The URL to parse
     * @return array{host:string, port:int, ssl:bool, path:string}
     * @throws Exception If the URL is invalid
     */
    private function parseUrl(string $url): array
    {
        $parts = parse_url($url);

        if ($parts === false || !isset($parts['host'])) {
            throw new Exception('Invalid URL: ' . $url);
        }

        $ssl = ($parts['scheme'] ?? 'http') === 'https';
        $port = $parts['port'] ?? ($ssl ? 443 : 80);
        $path = ($parts['path'] ?? '/') .
                (isset($parts['query']) ? '?' . $parts['query'] : '') .
                (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');

        return [
            'host' => $parts['host'],
            'port' => $port,
            'ssl' => $ssl,
            'path' => $path,
        ];
    }

    /**
     * Configures the Swoole HTTP client with transport settings and request options.
     *
     * SSL verification is configured here with secure defaults. Request-level
     * options can override transport-level settings.
     *
     * @param SwooleHttpClient $client The Swoole HTTP client
     * @param Request $request The HTTP request
     */
    private function configureClient(SwooleHttpClient $client, Request $request): void
    {
        // Start with base settings
        $settings = [
            'timeout' => $this->requestTimeout,
            'keep_alive' => $this->keepAlive,
        ];

        // Apply SSL settings (with secure defaults)
        $sslSettings = $this->buildSslSettings($request);
        $settings = array_merge($settings, $sslSettings);

        $client->set($settings);

        // Configure headers
        $headers = [];
        $headerCollection = $request->getHeaders();

        foreach ($headerCollection->toArray() as $name => $values) {
            if (!is_array($values)) {
                $values = [$values];
            }
            // Join multiple header values with comma (per HTTP spec)
            $headers[$name] = implode(', ', $values);
        }

        if (!empty($headers)) {
            $client->setHeaders($headers);
        }
    }

    /**
     * Builds SSL settings with secure defaults.
     *
     * Priority: request options > transport properties > defaults
     *
     * @param Request $request The HTTP request
     * @return array SSL settings for Swoole client
     */
    private function buildSslSettings(Request $request): array
    {
        $options = $request->getOptions();
        $settings = [];

        // SSL peer verification (default: true for security)
        if (isset($options[CURLOPT_SSL_VERIFYPEER])) {
            $settings['ssl_verify_peer'] = (bool) $options[CURLOPT_SSL_VERIFYPEER];
        } else {
            $settings['ssl_verify_peer'] = $this->sslVerifyPeer;
        }

        // SSL host verification (default: true for security)
        if (isset($options[CURLOPT_SSL_VERIFYHOST])) {
            $settings['ssl_verify_host'] = (bool) $options[CURLOPT_SSL_VERIFYHOST];
        } else {
            $settings['ssl_verify_host'] = $this->sslVerifyHost;
        }

        // CA certificate file
        if (isset($options[CURLOPT_CAINFO])) {
            $settings['ssl_cafile'] = $options[CURLOPT_CAINFO];
        } elseif ($this->sslCafile !== null) {
            $settings['ssl_cafile'] = $this->sslCafile;
        }

        // CA certificate directory
        if (isset($options[CURLOPT_CAPATH])) {
            $settings['ssl_capath'] = $options[CURLOPT_CAPATH];
        } elseif ($this->sslCapath !== null) {
            $settings['ssl_capath'] = $this->sslCapath;
        }

        // Client certificate (for mutual TLS)
        if (isset($options[CURLOPT_SSLCERT])) {
            $settings['ssl_cert_file'] = $options[CURLOPT_SSLCERT];
        }

        // Client private key (for mutual TLS)
        if (isset($options[CURLOPT_SSLKEY])) {
            $settings['ssl_key_file'] = $options[CURLOPT_SSLKEY];
        }

        return $settings;
    }

    /**
     * Executes an HTTP request using the Swoole client.
     *
     * @param SwooleHttpClient $client The Swoole HTTP client
     * @param Request $request The HTTP request
     * @param string $path The request path including query string
     * @return Response The HTTP response
     * @throws Exception If the request fails
     */
    private function executeRequest(SwooleHttpClient $client, Request $request, string $path): Response
    {
        $method = strtoupper($request->getMethod());
        $content = $request->getContent();

        $success = match ($method) {
            'GET' => $client->get($path),
            'POST' => $client->post($path, $content),
            'PUT' => $client->setMethod('PUT') && $client->execute($path, $content),
            'DELETE' => $client->setMethod('DELETE') && $client->execute($path, $content),
            'PATCH' => $client->setMethod('PATCH') && $client->execute($path, $content),
            'HEAD' => $client->setMethod('HEAD') && $client->execute($path),
            'OPTIONS' => $client->setMethod('OPTIONS') && $client->execute($path),
            default => throw new Exception('Unsupported HTTP method: ' . $method),
        };

        if (!$success) {
            $errCode = $client->errCode;
            $errMsg = socket_strerror($errCode);
            throw new Exception("HTTP request failed: [{$errCode}] {$errMsg}");
        }

        $responseHeaders = [];

        // Add HTTP status line as first header (yii2-httpclient expects this format)
        $statusLine = sprintf(
            'HTTP/%s %d %s',
            '1.1',
            $client->statusCode,
            $this->getReasonPhrase($client->statusCode)
        );
        $responseHeaders[] = $statusLine;

        // Add response headers
        if (!empty($client->headers)) {
            foreach ($client->headers as $name => $value) {
                $responseHeaders[] = "{$name}: {$value}";
            }
        }

        // Add Set-Cookie headers
        if (!empty($client->set_cookie_headers)) {
            foreach ($client->set_cookie_headers as $cookie) {
                $responseHeaders[] = "Set-Cookie: {$cookie}";
            }
        }

        return $request->client->createResponse($client->body, $responseHeaders);
    }

    /**
     * Returns the HTTP reason phrase for a status code.
     *
     * @param int $statusCode The HTTP status code
     * @return string The reason phrase
     */
    private function getReasonPhrase(int $statusCode): string
    {
        $phrases = [
            200 => 'OK',
            201 => 'Created',
            202 => 'Accepted',
            204 => 'No Content',
            301 => 'Moved Permanently',
            302 => 'Found',
            304 => 'Not Modified',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            500 => 'Internal Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
        ];

        return $phrases[$statusCode] ?? 'Unknown';
    }
}
