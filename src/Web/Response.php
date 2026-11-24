<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Web;

use DateTimeInterface;
use Swoole\Http\Response as SwooleResponse;
use Yii;
use yii\base\InvalidConfigException;
use yii\web\Cookie;
use yii\web\Response as BaseResponse;

/**
 * Swoole-backed Yii2 Web Response.
 *
 * Preserves 100% of Yii2 Response lifecycle (EVENT_BEFORE_SEND, prepare(), formatters,
 * EVENT_AFTER_PREPARE, EVENT_AFTER_SEND, HMAC cookie signing) while writing directly to
 * Swoole\Http\Response with streaming write() chunks and zero-copy sendfile() support.
 */
class Response extends BaseResponse
{
    private const STREAM_CHUNK_SIZE = 1048576; // 1MB chunks for streaming responses

    /**
     * Built-in stateless Yii2 formatters safe to pre-instantiate on the response prototype.
     *
     * @var array<string, true>
     */
    private const STATELESS_FORMATTERS = [
        \yii\web\JsonResponseFormatter::class => true,
        \yii\web\XmlResponseFormatter::class => true,
        \yii\web\HtmlResponseFormatter::class => true,
    ];

    /**
     * Normalized HTTP header name cache (e.g. 'content-type' => 'Content-Type').
     *
     * @var array<string, string>
     */
    private static array $headerNameCache = [
        'content-type' => 'Content-Type',
        'content-length' => 'Content-Length',
        'cache-control' => 'Cache-Control',
        'location' => 'Location',
        'etag' => 'Etag',
        'vary' => 'Vary',
    ];

    private ?SwooleResponse $swooleResponse = null;

    /** @var array{path:string,offset:int,length:int}|null */
    private ?array $sendfileTarget = null;

    private bool $cookiesAccessed = false;

    public function init(): void
    {
        parent::init();

        // Pre-instantiate built-in stateless formatters once on the prototype to avoid
        // per-request Yii::createObject() reflection in Response::prepare().
        foreach ($this->formatters as $format => $formatter) {
            if (is_string($formatter) && isset(self::STATELESS_FORMATTERS[$formatter])) {
                $this->formatters[$format] = new $formatter();
            } elseif (
                is_array($formatter)
                && isset($formatter['class'])
                && is_string($formatter['class'])
                && isset(self::STATELESS_FORMATTERS[$formatter['class']])
            ) {
                $this->formatters[$format] = Yii::createObject($formatter);
            }
        }
    }

    public function __clone()
    {
        parent::__clone();

        $this->swooleResponse = null;
        $this->sendfileTarget = null;
        $this->cookiesAccessed = false;
    }

    public function getCookies()
    {
        $this->cookiesAccessed = true;

        return parent::getCookies();
    }

    public function bindSwooleResponse(SwooleResponse $swooleResponse): void
    {
        $this->swooleResponse = $swooleResponse;
        $this->sendfileTarget = null;
        $this->isSent = false;
    }

    public function getSwooleResponse(): ?SwooleResponse
    {
        return $this->swooleResponse;
    }

    public function clear(): void
    {
        parent::clear();
        $this->sendfileTarget = null;
        $this->cookiesAccessed = false;
    }

    /**
     * Uses Swoole's zero-copy sendfile() for whole/range file downloads when bound to Swoole.
     */
    public function sendFile($filePath, $attachmentName = null, $options = [])
    {
        if ($this->swooleResponse === null) {
            return parent::sendFile($filePath, $attachmentName, $options);
        }

        $filePath = Yii::getAlias($filePath);
        if (!is_file($filePath) || !is_readable($filePath)) {
            return parent::sendFile($filePath, $attachmentName, $options);
        }

        if (!isset($options['mimeType'])) {
            $options['mimeType'] = \yii\helpers\FileHelper::getMimeTypeByExtension($filePath);
        }
        if ($attachmentName === null) {
            $attachmentName = basename($filePath);
        }

        $fileSize = filesize($filePath) ?: 0;
        $range = $this->getHttpRange($fileSize);
        if ($range === false) {
            $this->getHeaders()->set('Content-Range', "bytes */{$fileSize}");
            throw new \yii\web\RangeNotSatisfiableHttpException();
        }

        [$begin, $end] = $range;
        if ($begin != 0 || $end != $fileSize - 1) {
            $this->setStatusCode(206);
            $this->getHeaders()->set('Content-Range', "bytes {$begin}-{$end}/{$fileSize}");
        } else {
            $this->setStatusCode(200);
        }

        $mimeType = $options['mimeType'] ?? 'application/octet-stream';
        $this->setDownloadHeaders($attachmentName, $mimeType, !empty($options['inline']), $end - $begin + 1);

        $this->format = self::FORMAT_RAW;
        $this->sendfileTarget = [
            'path' => $filePath,
            'offset' => (int) $begin,
            'length' => (int) ($end - $begin + 1),
        ];

        return $this;
    }

    public function send(): void
    {
        if ($this->swooleResponse === null) {
            parent::send();
            return;
        }

        if ($this->isSent || !$this->swooleResponse->isWritable()) {
            return;
        }

        if ($this->hasEventHandlers(self::EVENT_BEFORE_SEND)) {
            $this->trigger(self::EVENT_BEFORE_SEND);
        }
        $this->prepare();
        if ($this->hasEventHandlers(self::EVENT_AFTER_PREPARE)) {
            $this->trigger(self::EVENT_AFTER_PREPARE);
        }
        $this->sendHeaders();
        $this->sendContent();
        if ($this->hasEventHandlers(self::EVENT_AFTER_SEND)) {
            $this->trigger(self::EVENT_AFTER_SEND);
        }
        $this->isSent = true;
    }

    protected function sendHeaders(): void
    {
        if ($this->swooleResponse === null) {
            parent::sendHeaders();
            return;
        }

        $statusCode = $this->getStatusCode();
        if ($statusCode !== 200) {
            $this->swooleResponse->status($statusCode, $this->statusText);
        }

        foreach ($this->getHeaders()->toArray() as $name => $values) {
            $key = (string) $name;
            $headerName = self::$headerNameCache[$key] ?? null;
            if ($headerName === null) {
                $headerName = str_replace(' ', '-', ucwords(str_replace('-', ' ', $key)));
                if (count(self::$headerNameCache) < 256) {
                    self::$headerNameCache[$key] = $headerName;
                }
            }

            $list = is_array($values) ? array_values(array_map('strval', $values)) : [(string) $values];
            if ($list === []) {
                continue;
            }

            // Swoole\Http\Response::header() overwrites on repeated calls for the same name,
            // while passing an array emits all values as distinct headers in a single C call.
            $this->swooleResponse->header($headerName, count($list) === 1 ? $list[0] : $list);
        }

        if ($this->cookiesAccessed) {
            $this->sendCookies();
        }
    }

    protected function sendCookies(): void
    {
        if ($this->swooleResponse === null) {
            parent::sendCookies();
            return;
        }

        if (!$this->cookiesAccessed) {
            return;
        }

        $cookies = parent::getCookies();
        if ($cookies->getCount() === 0) {
            return;
        }

        $request = Yii::$app->getRequest();
        $validationKey = null;
        if ($request->enableCookieValidation) {
            if ($request->cookieValidationKey == '') {
                throw new InvalidConfigException(get_class($request) . '::cookieValidationKey must be configured with a secret key.');
            }
            $validationKey = $request->cookieValidationKey;
        }

        $security = $validationKey !== null ? Yii::$app->getSecurity() : null;

        foreach ($cookies as $cookie) {
            if (!$cookie instanceof Cookie) {
                continue;
            }

            $value = $cookie->value;
            $expire = $cookie->expire;
            if (is_string($expire)) {
                $expire = strtotime($expire);
            } elseif ($expire instanceof DateTimeInterface) {
                $expire = $expire->getTimestamp();
            }
            if ($expire === null || $expire === false) {
                $expire = 0;
            }

            if ($expire != 1 && $validationKey !== null && $security !== null) {
                $value = $security->hashData(serialize([$cookie->name, $value]), $validationKey);
            }

            $this->swooleResponse->cookie(
                $cookie->name,
                (string) $value,
                (int) $expire,
                $cookie->path,
                $cookie->domain,
                (bool) $cookie->secure,
                (bool) $cookie->httpOnly,
                (string) ($cookie->sameSite ?? '')
            );
        }
    }

    protected function sendContent(): void
    {
        if ($this->swooleResponse === null) {
            parent::sendContent();
            return;
        }

        if ($this->sendfileTarget !== null) {
            $target = $this->sendfileTarget;
            $this->sendfileTarget = null;
            $this->swooleResponse->sendfile($target['path'], $target['offset'], $target['length']);
            return;
        }

        if ($this->stream === null) {
            $this->swooleResponse->end((string) ($this->content ?? ''));
            return;
        }

        $this->sendStreamedContent();
    }

    private function sendStreamedContent(): void
    {
        $swooleResponse = $this->swooleResponse;
        if ($swooleResponse === null) {
            return;
        }

        $stream = $this->stream;

        if (is_callable($stream)) {
            $data = $stream();
            foreach ($data as $chunk) {
                if ($chunk === '' || $chunk === null) {
                    continue;
                }
                if (!$swooleResponse->isWritable() || !$swooleResponse->write((string) $chunk)) {
                    break;
                }
            }
            if ($swooleResponse->isWritable()) {
                $swooleResponse->end();
            }
            return;
        }

        if (is_array($stream)) {
            [$handle, $begin, $end] = $stream;
            try {
                if (is_resource($handle)) {
                    $meta = stream_get_meta_data($handle);
                    if ($meta['seekable'] ?? false) {
                        fseek($handle, (int) $begin);
                    }

                    $chunkSize = self::STREAM_CHUNK_SIZE;
                    while (!feof($handle) && ($pos = ftell($handle)) !== false && $pos <= $end) {
                        $readLength = ($pos + $chunkSize > $end) ? (int) ($end - $pos + 1) : $chunkSize;
                        if ($readLength <= 0) {
                            break;
                        }
                        $chunk = fread($handle, $readLength);
                        if ($chunk === false || $chunk === '') {
                            break;
                        }
                        if (!$swooleResponse->isWritable() || !$swooleResponse->write($chunk)) {
                            break;
                        }
                    }
                }
            } finally {
                if (is_resource($handle)) {
                    fclose($handle);
                }
            }

            if ($swooleResponse->isWritable()) {
                $swooleResponse->end();
            }
            return;
        }

        Yii::warning('Unsupported response stream type: ' . gettype($stream), __METHOD__);
        if ($swooleResponse->isWritable()) {
            $swooleResponse->end();
        }
    }
}
