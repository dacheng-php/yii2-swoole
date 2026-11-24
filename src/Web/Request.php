<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Web;

use Swoole\Http\Request as SwooleRequest;
use Yii;
use yii\base\InvalidConfigException;
use yii\web\Cookie;
use yii\web\HeaderCollection;
use yii\web\NotFoundHttpException;
use yii\web\Request as BaseRequest;
use yii\web\RequestParserInterface;

/**
 * Fully Swoole-backed Yii2 Web Request with zero dependency on PHP superglobals
 * ($_SERVER, $_GET, $_POST, $_COOKIE, $_FILES, php://input).
 *
 * - Pre-instantiates stateless request parsers on the prototype in init() to eliminate
 *   per-request reflection overhead on JSON/API endpoints.
 * - Enforces Yii2's trustedHosts header filtering in getHeaders() so untrusted clients
 *   cannot spoof X-Forwarded-Proto / X-Forwarded-For / X-Forwarded-Host / Forwarded headers.
 * - Lazily populates headers, cookies, raw body, and parsed body params on demand.
 */
class Request extends BaseRequest
{
    /**
     * Built-in stateless parsers safe to pre-instantiate once on the request prototype.
     *
     * @var array<string, true>
     */
    private const STATELESS_PARSERS = [
        \yii\web\JsonParser::class => true,
        \yii\web\MultipartFormDataParser::class => true,
    ];

    private ?SwooleRequest $swooleRequest = null;

    private ?HeaderCollection $coroutineHeaders = null;

    private ?string $coroutineMethod = null;

    /** @var array<string, mixed>|null */
    private ?array $coroutineQueryParams = null;

    /** @var array<string, mixed>|null */
    private ?array $coroutineBodyParams = null;

    private ?string $coroutineRawBody = null;

    private ?string $coroutineUrl = null;

    private ?string $coroutinePathInfo = null;

    private ?string $coroutineScriptUrl = null;

    private ?string $coroutineScriptFile = null;

    private ?string $coroutineBaseUrl = null;

    private ?string $coroutineHostInfo = null;

    public function init(): void
    {
        parent::init();

        // Pre-instantiate stateless parsers once on the prototype so cloned requests
        // do not pay Yii::createObject() reflection on every JSON/form API call.
        foreach ($this->parsers as $contentType => $parser) {
            if (is_string($parser) && isset(self::STATELESS_PARSERS[$parser])) {
                $this->parsers[$contentType] = new $parser();
            } elseif (
                is_array($parser)
                && isset($parser['class'])
                && is_string($parser['class'])
                && isset(self::STATELESS_PARSERS[$parser['class']])
            ) {
                $this->parsers[$contentType] = Yii::createObject($parser);
            }
        }
    }

    public function __clone()
    {
        parent::__clone();

        $this->swooleRequest = null;
        $this->coroutineHeaders = null;
        $this->coroutineMethod = null;
        $this->coroutineQueryParams = null;
        $this->coroutineBodyParams = null;
        $this->coroutineRawBody = null;
        $this->coroutineUrl = null;
        $this->coroutinePathInfo = null;
        $this->coroutineHostInfo = null;
    }

    public function populateFromSwoole(SwooleRequest $swooleRequest): void
    {
        $this->swooleRequest = $swooleRequest;
        $this->coroutineHeaders = null;
        $this->coroutineMethod = null;
        $this->coroutineQueryParams = null;
        $this->coroutineBodyParams = null;
        $this->coroutineRawBody = null;
        $this->coroutineUrl = null;
        $this->coroutinePathInfo = null;
        $this->coroutineHostInfo = null;
    }

    public function getSwooleRequest(): ?SwooleRequest
    {
        return $this->swooleRequest;
    }

    /**
     * Returns raw Swoole uploaded files array without touching $_FILES.
     *
     * @return array<string, mixed>
     */
    public function getUploadedFiles(): array
    {
        return $this->swooleRequest->files ?? [];
    }

    /**
     * Resolves route without mutating global $_GET.
     */
    public function resolve()
    {
        $result = Yii::$app->getUrlManager()->parseRequest($this);
        if ($result !== false) {
            [$route, $params] = $result;
            if ($params === []) {
                return [$route, $this->coroutineQueryParams ?? ($this->swooleRequest->get ?? [])];
            }

            $queryParams = $this->getQueryParams();
            $merged = $params + $queryParams;
            $this->coroutineQueryParams = $merged;

            return [$route, $merged];
        }

        throw new NotFoundHttpException(Yii::t('yii', 'Page not found.'));
    }

    public function getHeaders()
    {
        if ($this->swooleRequest === null) {
            return parent::getHeaders();
        }

        if ($this->coroutineHeaders === null) {
            $headers = new HeaderCollection();
            foreach ($this->swooleRequest->header ?? [] as $name => $value) {
                $headers->set((string) $name, $value);
            }
            $this->filterHeaders($headers);
            $this->coroutineHeaders = $headers;
        }

        return $this->coroutineHeaders;
    }

    public function getMethod(): string
    {
        if ($this->coroutineMethod !== null) {
            return $this->coroutineMethod;
        }

        if ($this->swooleRequest === null) {
            return parent::getMethod();
        }

        $post = $this->swooleRequest->post ?? [];
        if (
            isset($post[$this->methodParam])
            && !in_array(strtoupper((string) $post[$this->methodParam]), ['GET', 'HEAD', 'OPTIONS'], true)
        ) {
            return $this->coroutineMethod = strtoupper((string) $post[$this->methodParam]);
        }

        // Fast direct header read avoids instantiating HeaderCollection on simple GET/POST requests
        $override = $this->coroutineHeaders !== null
            ? $this->coroutineHeaders->get('X-Http-Method-Override')
            : ($this->swooleRequest->header['x-http-method-override'] ?? null);

        if (is_string($override) && $override !== '') {
            return $this->coroutineMethod = strtoupper($override);
        }

        return $this->coroutineMethod = strtoupper((string) ($this->swooleRequest->server['request_method'] ?? 'GET'));
    }

    public function setMethod(?string $method): void
    {
        $this->coroutineMethod = $method === null ? null : strtoupper($method);
    }

    public function getRawBody(): string
    {
        if ($this->coroutineRawBody !== null) {
            return $this->coroutineRawBody;
        }

        if ($this->swooleRequest === null) {
            return parent::getRawBody();
        }

        $raw = $this->swooleRequest->rawContent();

        return $this->coroutineRawBody = is_string($raw) ? $raw : '';
    }

    public function setRawBody($rawBody): void
    {
        $this->coroutineRawBody = $rawBody;
    }

    public function getBodyParams()
    {
        if ($this->coroutineBodyParams !== null) {
            return $this->coroutineBodyParams;
        }

        if ($this->swooleRequest === null) {
            return parent::getBodyParams();
        }

        $post = $this->swooleRequest->post ?? [];
        if (isset($post[$this->methodParam])) {
            unset($post[$this->methodParam]);
            return $this->coroutineBodyParams = $post;
        }

        $rawContentType = (string) ($this->getContentType() ?? '');
        $pos = strpos($rawContentType, ';');
        $contentType = $pos !== false ? strtolower(trim(substr($rawContentType, 0, $pos))) : strtolower(trim($rawContentType));

        if ($contentType !== '' && isset($this->parsers[$contentType])) {
            $parser = $this->parsers[$contentType];
            if (!$parser instanceof RequestParserInterface) {
                $parser = Yii::createObject($parser);
                $this->parsers[$contentType] = $parser;
            }
            if (!$parser instanceof RequestParserInterface) {
                throw new InvalidConfigException("The '{$contentType}' request parser must implement RequestParserInterface.");
            }

            return $this->coroutineBodyParams = $parser->parse($this->getRawBody(), $rawContentType);
        }

        if (isset($this->parsers['*'])) {
            $parser = $this->parsers['*'];
            if (!$parser instanceof RequestParserInterface) {
                $parser = Yii::createObject($parser);
                $this->parsers['*'] = $parser;
            }
            if (!$parser instanceof RequestParserInterface) {
                throw new InvalidConfigException('The fallback request parser must implement RequestParserInterface.');
            }

            return $this->coroutineBodyParams = $parser->parse($this->getRawBody(), $rawContentType);
        }

        if ($this->getMethod() === 'POST') {
            return $this->coroutineBodyParams = $post;
        }

        $parsed = [];
        mb_parse_str($this->getRawBody(), $parsed);

        return $this->coroutineBodyParams = $parsed;
    }

    public function setBodyParams($values): void
    {
        $this->coroutineBodyParams = $values;
    }

    public function getQueryParams()
    {
        if ($this->coroutineQueryParams !== null) {
            return $this->coroutineQueryParams;
        }

        if ($this->swooleRequest === null) {
            return parent::getQueryParams();
        }

        return $this->coroutineQueryParams = $this->swooleRequest->get ?? [];
    }

    public function setQueryParams($values): void
    {
        $this->coroutineQueryParams = $values;
    }

    public function getQueryString(): string
    {
        if ($this->swooleRequest === null) {
            return parent::getQueryString();
        }

        return (string) ($this->swooleRequest->server['query_string'] ?? '');
    }

    public function getContentType()
    {
        if ($this->swooleRequest === null) {
            return parent::getContentType();
        }

        $contentType = $this->coroutineHeaders !== null
            ? $this->coroutineHeaders->get('content-type')
            : ($this->swooleRequest->header['content-type'] ?? null);

        if ($contentType !== null) {
            return $contentType;
        }

        return $this->swooleRequest->server['content_type'] ?? null;
    }

    public function getUrl()
    {
        if ($this->coroutineUrl !== null) {
            return $this->coroutineUrl;
        }

        if ($this->swooleRequest === null) {
            return parent::getUrl();
        }

        $server = $this->swooleRequest->server ?? [];
        $uri = (string) ($server['request_uri'] ?? '/');
        $queryString = (string) ($server['query_string'] ?? '');

        if ($queryString !== '' && !str_contains($uri, '?')) {
            $uri .= '?' . $queryString;
        }

        return $this->coroutineUrl = $uri;
    }

    public function setUrl($value): void
    {
        $this->coroutineUrl = $value;
    }

    public function getPathInfo()
    {
        if ($this->coroutinePathInfo !== null) {
            return $this->coroutinePathInfo;
        }

        if ($this->swooleRequest === null) {
            return parent::getPathInfo();
        }

        $uri = (string) ($this->swooleRequest->server['request_uri'] ?? '/');
        if ($uri === '/') {
            return $this->coroutinePathInfo = '';
        }

        if (($pos = strpos($uri, '?')) !== false) {
            $uri = substr($uri, 0, $pos);
        }

        $pathInfo = str_contains($uri, '%') ? urldecode($uri) : $uri;
        $baseUrl = $this->coroutineBaseUrl ?? '';
        if ($baseUrl !== '' && str_starts_with($pathInfo, $baseUrl)) {
            $pathInfo = substr($pathInfo, strlen($baseUrl));
        }

        return $this->coroutinePathInfo = ltrim($pathInfo, '/');
    }

    public function setPathInfo($value): void
    {
        $this->coroutinePathInfo = $value === null ? null : ltrim($value, '/');
    }

    public function getBaseUrl()
    {
        if ($this->coroutineBaseUrl !== null) {
            return $this->coroutineBaseUrl;
        }

        return $this->coroutineBaseUrl = '';
    }

    public function setBaseUrl($value): void
    {
        $this->coroutineBaseUrl = $value === null ? null : rtrim($value, '/');
    }

    public function getScriptUrl()
    {
        if ($this->coroutineScriptUrl !== null) {
            return $this->coroutineScriptUrl;
        }

        return $this->coroutineScriptUrl = '/index.php';
    }

    public function setScriptUrl($value): void
    {
        $this->coroutineScriptUrl = $value === null ? null : '/' . trim($value, '/');
    }

    public function getScriptFile()
    {
        if ($this->coroutineScriptFile !== null) {
            return $this->coroutineScriptFile;
        }

        $webroot = Yii::getAlias('@webroot', false);
        if (is_string($webroot)) {
            return $this->coroutineScriptFile = rtrim($webroot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'index.php';
        }

        return $this->coroutineScriptFile = Yii::$app->getBasePath() . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . 'index.php';
    }

    public function setScriptFile($value): void
    {
        $this->coroutineScriptFile = $value;
    }

    public function getHostInfo()
    {
        if ($this->coroutineHostInfo !== null) {
            return $this->coroutineHostInfo;
        }

        if ($this->swooleRequest === null) {
            return parent::getHostInfo();
        }

        $scheme = $this->getIsSecureConnection() ? 'https' : 'http';

        // Respect trustedHosts-filtered forwarded host headers when configured
        $hostHeader = null;
        if (!empty($this->trustedHosts)) {
            $headers = $this->getHeaders();
            foreach ($this->hostInfoHeaders as $header) {
                $val = $headers->get($header);
                if (is_string($val) && $val !== '') {
                    $hostHeader = $val;
                    break;
                }
            }
        }

        $hostHeader ??= $this->coroutineHeaders !== null
            ? $this->coroutineHeaders->get('host')
            : ($this->swooleRequest->header['host'] ?? null);

        if (is_string($hostHeader) && $hostHeader !== '') {
            if (str_contains($hostHeader, '://')) {
                return $this->coroutineHostInfo = rtrim($hostHeader, '/');
            }
            return $this->coroutineHostInfo = $scheme . '://' . $hostHeader;
        }

        $serverName = $this->getServerName() ?? '127.0.0.1';
        $port = $this->getServerPort();
        $isDefaultPort = ($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443) || $port === null;

        return $this->coroutineHostInfo = $scheme . '://' . $serverName . ($isDefaultPort ? '' : ':' . $port);
    }

    public function setHostInfo($value): void
    {
        $this->coroutineHostInfo = $value === null ? null : rtrim($value, '/');
    }

    public function getIsSecureConnection(): bool
    {
        if ($this->swooleRequest === null) {
            return parent::getIsSecureConnection();
        }

        $https = $this->swooleRequest->server['https'] ?? null;
        if ($https !== null && (strcasecmp((string) $https, 'on') === 0 || (string) $https === '1')) {
            return true;
        }

        // Fast path: without trustedHosts, all proxy secureProtocolHeaders are stripped by Yii2's filterHeaders().
        if (empty($this->trustedHosts)) {
            return false;
        }

        if (($proto = $this->getSecureForwardedHeaderTrustedPart('proto')) !== null) {
            return strcasecmp($proto, 'https') === 0;
        }

        // getHeaders() applies filterHeaders() so only headers from trustedHosts remain.
        $headers = $this->getHeaders();
        foreach ($this->secureProtocolHeaders as $header => $values) {
            $headerValue = $headers->get($header, null, false);
            if ($headerValue !== null) {
                foreach ($values as $value) {
                    if (is_array($headerValue)) {
                        foreach ($headerValue as $item) {
                            if (strcasecmp((string) $item, (string) $value) === 0) {
                                return true;
                            }
                        }
                    } elseif (strcasecmp((string) $headerValue, (string) $value) === 0) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    public function getServerName(): ?string
    {
        if ($this->swooleRequest === null) {
            return parent::getServerName();
        }

        $host = $this->coroutineHeaders !== null
            ? $this->coroutineHeaders->get('host')
            : ($this->swooleRequest->header['host'] ?? null);

        if (is_string($host) && $host !== '') {
            $pos = strpos($host, ':');
            return $pos !== false ? substr($host, 0, $pos) : $host;
        }

        return $this->swooleRequest->server['server_name'] ?? null;
    }

    public function getServerPort(): ?int
    {
        if ($this->swooleRequest === null) {
            return parent::getServerPort();
        }

        $host = $this->coroutineHeaders !== null
            ? $this->coroutineHeaders->get('host')
            : ($this->swooleRequest->header['host'] ?? null);

        if (is_string($host) && ($pos = strrpos($host, ':')) !== false) {
            $port = substr($host, $pos + 1);
            if (ctype_digit($port)) {
                return (int) $port;
            }
        }

        return isset($this->swooleRequest->server['server_port'])
            ? (int) $this->swooleRequest->server['server_port']
            : null;
    }

    public function getRemoteIP(): ?string
    {
        if ($this->swooleRequest === null) {
            return parent::getRemoteIP();
        }

        return $this->swooleRequest->server['remote_addr'] ?? null;
    }

    public function getRemoteHost(): ?string
    {
        if ($this->swooleRequest === null) {
            return parent::getRemoteHost();
        }

        return $this->swooleRequest->server['remote_host'] ?? null;
    }

    public function getAuthCredentials(): array
    {
        if ($this->swooleRequest === null) {
            return parent::getAuthCredentials();
        }

        $authHeader = $this->coroutineHeaders !== null
            ? $this->coroutineHeaders->get('authorization')
            : ($this->swooleRequest->header['authorization'] ?? null);

        if (is_string($authHeader) && strncasecmp($authHeader, 'basic ', 6) === 0) {
            $decoded = base64_decode(substr($authHeader, 6), true);
            if ($decoded !== false && str_contains($decoded, ':')) {
                return explode(':', $decoded, 2);
            }
        }

        return [null, null];
    }

    /**
     * Loads and validates cookies from Swoole request without touching $_COOKIE.
     *
     * @return array<string, Cookie>
     */
    protected function loadCookies(): array
    {
        if ($this->swooleRequest === null) {
            return parent::loadCookies();
        }

        $rawCookies = $this->swooleRequest->cookie ?? [];
        if ($rawCookies === []) {
            return [];
        }

        $cookies = [];
        if ($this->enableCookieValidation) {
            if ($this->cookieValidationKey == '') {
                throw new InvalidConfigException(static::class . '::cookieValidationKey must be configured with a secret key.');
            }
            $security = Yii::$app->getSecurity();
            foreach ($rawCookies as $name => $value) {
                if (!is_string($value)) {
                    continue;
                }
                $data = $security->validateData($value, $this->cookieValidationKey);
                if ($data === false) {
                    continue;
                }
                $unserialized = @unserialize($data, ['allowed_classes' => false]);
                if (is_array($unserialized) && isset($unserialized[0], $unserialized[1]) && $unserialized[0] === $name) {
                    $cookies[$name] = Yii::createObject([
                        'class' => Cookie::class,
                        'name' => $name,
                        'value' => $unserialized[1],
                        'expire' => null,
                    ]);
                }
            }
        } else {
            foreach ($rawCookies as $name => $value) {
                $cookies[$name] = Yii::createObject([
                    'class' => Cookie::class,
                    'name' => $name,
                    'value' => $value,
                    'expire' => null,
                ]);
            }
        }

        return $cookies;
    }
}
