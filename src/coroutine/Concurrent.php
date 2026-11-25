<?php

declare(strict_types=1);

namespace yii2swoole\coroutine;

use yii2swoole\log\Logger;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\WaitGroup;
use Throwable;

/**
 * Structured concurrency helpers for Yii2 business logic running on Swoole.
 *
 * Features:
 * - Preserves associative array keys across concurrent tasks.
 * - Optional concurrency limit (semaphore) to protect downstream DB/Redis/APIs.
 * - Captures exceptions safely inside child coroutines without crashing the worker.
 * - Graceful sequential fallback when executed outside a Swoole coroutine.
 * - Optional context key propagation from parent coroutine to children.
 */
final class Concurrent
{
    /**
     * Runs tasks concurrently and returns an array of results preserving keys.
     * Throws the first encountered exception after all launched tasks finish.
     *
     * @template TKey of array-key
     * @template TValue
     * @param array<TKey, callable(): TValue> $tasks
     * @param int $concurrency Maximum concurrent coroutines (0 = unlimited)
     * @param string[] $propagateContextKeys Context keys (from Context::set) to copy to child coroutines
     * @return array<TKey, TValue>
     */
    public static function run(array $tasks, int $concurrency = 0, array $propagateContextKeys = []): array
    {
        $settled = self::settle($tasks, $concurrency, $propagateContextKeys);
        $results = [];

        foreach ($settled as $key => $outcome) {
            if ($outcome['status'] === 'rejected') {
                throw $outcome['reason'];
            }
            $results[$key] = $outcome['value'];
        }

        return $results;
    }

    /**
     * Runs tasks concurrently and returns settled outcomes for every task without throwing.
     *
     * @template TKey of array-key
     * @template TValue
     * @param array<TKey, callable(): TValue> $tasks
     * @param int $concurrency Maximum concurrent coroutines (0 = unlimited)
     * @param string[] $propagateContextKeys
     * @return array<TKey, array{status:'fulfilled',value:TValue}|array{status:'rejected',reason:Throwable}>
     */
    public static function settle(array $tasks, int $concurrency = 0, array $propagateContextKeys = []): array
    {
        if ($tasks === []) {
            return [];
        }

        if (Coroutine::getCid() < 0) {
            $outcomes = [];
            foreach ($tasks as $key => $task) {
                try {
                    $outcomes[$key] = ['status' => 'fulfilled', 'value' => $task()];
                } catch (Throwable $e) {
                    $outcomes[$key] = ['status' => 'rejected', 'reason' => $e];
                }
            }

            return $outcomes;
        }

        $parentCid = Coroutine::getCid();
        $telemetryEnabled = Telemetry::isEnabled();
        $batchStart = $telemetryEnabled ? microtime(true) : 0.0;
        $outcomes = [];
        // Pre-populate keys to preserve original iteration order
        foreach (array_keys($tasks) as $key) {
            $outcomes[$key] = null;
        }

        $wg = new WaitGroup();
        $semaphore = $concurrency > 0 ? new Channel($concurrency) : null;

        foreach ($tasks as $key => $task) {
            if ($semaphore !== null) {
                $semaphore->push(true);
            }

            $wg->add();
            Coroutine::create(static function () use ($key, $task, $wg, $semaphore, $parentCid, $propagateContextKeys, $telemetryEnabled, &$outcomes): void {
                // Register completion defer FIRST so LIFO execution guarantees all task-registered
                // Coroutine::defer() callbacks (e.g. DB/Redis pool returns) run BEFORE releasing
                // the semaphore and unblocking the parent WaitGroup.
                Coroutine::defer(static function () use ($wg, $semaphore, $parentCid, $telemetryEnabled): void {
                    if ($telemetryEnabled) {
                        Logger::mergeCoroutineMessagesIntoParent($parentCid);
                    }
                    if ($semaphore !== null) {
                        $semaphore->pop();
                    }
                    $wg->done();
                });

                try {
                    if ($telemetryEnabled) {
                        Telemetry::attachParent($parentCid);
                    }
                    if ($propagateContextKeys !== []) {
                        Context::copyFrom($parentCid, $propagateContextKeys);
                    }
                    $outcomes[$key] = ['status' => 'fulfilled', 'value' => $task()];
                } catch (Throwable $e) {
                    $outcomes[$key] = ['status' => 'rejected', 'reason' => $e];
                }
            });
        }

        $wg->wait();

        if ($semaphore !== null) {
            $semaphore->close();
        }

        if ($telemetryEnabled) {
            Telemetry::recordConcurrentBatch(count($tasks), $concurrency, (microtime(true) - $batchStart) * 1000.0);
        }

        return $outcomes;
    }

    /**
     * Concurrently maps a collection through a callback with bounded concurrency.
     *
     * @template TKey of array-key
     * @template TItem
     * @template TResult
     * @param iterable<TKey, TItem> $items
     * @param callable(TItem, TKey): TResult $mapper
     * @param int $concurrency Maximum concurrent coroutines
     * @param string[] $propagateContextKeys
     * @return array<TKey, TResult>
     */
    public static function map(
        iterable $items,
        callable $mapper,
        int $concurrency = 10,
        array $propagateContextKeys = []
    ): array {
        $tasks = [];
        foreach ($items as $key => $item) {
            $tasks[$key] = static fn () => $mapper($item, $key);
        }

        return self::run($tasks, max(1, $concurrency), $propagateContextKeys);
    }
}
