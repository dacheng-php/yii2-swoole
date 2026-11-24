<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Web;

use Swoole\Coroutine;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;

/**
 * Lightweight per-request metadata bound to the current Swoole coroutine.
 *
 * Avoids per-request random_int() syscalls and microtime() overhead on hot paths
 * by generating request IDs lazily with a monotonic per-worker counter.
 */
final class RequestContext
{
    private const CONTEXT_KEY = '__yii2SwooleRequestContext';

    private static ?string $pidPrefix = null;

    private static int $sequence = 0;

    private ?string $generatedRequestId;

    private ?float $startedAtTimestamp = null;

    public function __construct(
        public readonly SwooleRequest $swooleRequest,
        public readonly SwooleResponse $swooleResponse,
        ?string $requestId = null
    ) {
        $this->generatedRequestId = $requestId;
    }

    public static function start(SwooleRequest $request, SwooleResponse $response, ?string $requestId = null): self
    {
        $ctx = new self($request, $response, $requestId);

        if (Coroutine::getCid() >= 0) {
            Coroutine::getContext()[self::CONTEXT_KEY] = $ctx;
        }

        return $ctx;
    }

    public static function current(): ?self
    {
        if (Coroutine::getCid() < 0) {
            return null;
        }

        $ctx = Coroutine::getContext()[self::CONTEXT_KEY] ?? null;

        return $ctx instanceof self ? $ctx : null;
    }

    public static function clear(): void
    {
        if (Coroutine::getCid() >= 0) {
            unset(Coroutine::getContext()[self::CONTEXT_KEY]);
        }
    }

    public function getRequestId(): string
    {
        if ($this->generatedRequestId !== null) {
            return $this->generatedRequestId;
        }

        $headerId = $this->swooleRequest->header['x-request-id'] ?? null;
        if (is_string($headerId) && $headerId !== '') {
            return $this->generatedRequestId = $headerId;
        }

        self::$pidPrefix ??= dechex((int) getmypid()) . '-';

        return $this->generatedRequestId = self::$pidPrefix . (++self::$sequence);
    }

    public function getMethod(): string
    {
        return strtoupper((string) ($this->swooleRequest->server['request_method'] ?? 'GET'));
    }

    public function getUri(): string
    {
        return (string) ($this->swooleRequest->server['request_uri'] ?? '/');
    }

    public function elapsedMs(): float
    {
        if ($this->startedAtTimestamp === null) {
            $requestTime = $this->swooleRequest->server['request_time_float'] ?? null;
            $this->startedAtTimestamp = is_numeric($requestTime) ? (float) $requestTime : microtime(true);
        }

        return (microtime(true) - $this->startedAtTimestamp) * 1000.0;
    }

    public function __get(string $name): mixed
    {
        return match ($name) {
            'requestId' => $this->getRequestId(),
            'method' => $this->getMethod(),
            'uri' => $this->getUri(),
            'startedAt' => $this->startedAtTimestamp ??= (float) ($this->swooleRequest->server['request_time_float'] ?? microtime(true)),
            default => throw new \yii\base\UnknownPropertyException('Getting unknown property: ' . self::class . '::' . $name),
        };
    }
}
