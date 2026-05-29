<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Runtime;

use RuntimeException;
use Swoole\Coroutine;
use Swoole\Http\Request;

final class RequestScope
{
    private const CONTEXT_KEY = '__yii2_swoole_request_scope';

    public function __construct(
        public readonly string $requestId,
        public readonly float $startedAt,
        public readonly string $uri,
        public readonly Request $swooleRequest
    ) {
    }

    public static function start(Request $swooleRequest, ?string $requestId = null): self
    {
        $scope = new self(
            $requestId ?? sprintf('%x_%d_%d', (int)(microtime(true) * 1000), Coroutine::getCid(), mt_rand(1000, 9999)),
            microtime(true),
            $swooleRequest->server['request_uri'] ?? '/',
            $swooleRequest
        );
        Coroutine::getContext()[self::CONTEXT_KEY] = $scope;

        return $scope;
    }

    public static function current(): self
    {
        $scope = Coroutine::getContext()[self::CONTEXT_KEY] ?? null;

        if (!$scope instanceof self) {
            throw new RuntimeException('No active request scope in the current coroutine.');
        }

        return $scope;
    }

    public function clear(): void
    {
        unset(Coroutine::getContext()[self::CONTEXT_KEY]);
    }
}
