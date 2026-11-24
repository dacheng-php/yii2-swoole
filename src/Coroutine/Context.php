<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Coroutine;

use Swoole\Coroutine;

/**
 * Coroutine-local key-value store with safe fallback outside Swoole coroutines.
 */
final class Context
{
    private const PREFIX = '__yii2SwooleCtx:';

    /**
     * @var array<string, mixed>
     */
    private static array $fallback = [];

    public static function get(string $key, mixed $default = null): mixed
    {
        if (Coroutine::getCid() < 0) {
            return array_key_exists($key, self::$fallback) ? self::$fallback[$key] : $default;
        }

        $context = Coroutine::getContext();
        $fullKey = self::PREFIX . $key;

        return isset($context[$fullKey]) || array_key_exists($fullKey, (array) $context)
            ? $context[$fullKey]
            : $default;
    }

    public static function set(string $key, mixed $value): void
    {
        if (Coroutine::getCid() < 0) {
            self::$fallback[$key] = $value;
            return;
        }

        $context = Coroutine::getContext();
        $context[self::PREFIX . $key] = $value;
    }

    public static function has(string $key): bool
    {
        if (Coroutine::getCid() < 0) {
            return array_key_exists($key, self::$fallback);
        }

        $context = Coroutine::getContext();
        $fullKey = self::PREFIX . $key;

        return isset($context[$fullKey]) || array_key_exists($fullKey, (array) $context);
    }

    public static function remove(string $key): void
    {
        if (Coroutine::getCid() < 0) {
            unset(self::$fallback[$key]);
            return;
        }

        $context = Coroutine::getContext();
        unset($context[self::PREFIX . $key]);
    }

    /**
     * Copies selected context keys from a parent coroutine into the current coroutine.
     *
     * @param string[] $keys
     */
    public static function copyFrom(int $parentCid, array $keys): void
    {
        if ($parentCid < 0 || Coroutine::getCid() < 0 || $keys === []) {
            return;
        }

        $parent = Coroutine::getContext($parentCid);
        if ($parent === null) {
            return;
        }

        $current = Coroutine::getContext();
        foreach ($keys as $key) {
            $fullKey = self::PREFIX . $key;
            if (isset($parent[$fullKey])) {
                $current[$fullKey] = $parent[$fullKey];
            }
        }
    }
}
