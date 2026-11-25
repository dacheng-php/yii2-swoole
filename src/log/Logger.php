<?php

declare(strict_types=1);

namespace yii2swoole\log;

use yii2swoole\coroutine\Telemetry;
use Swoole\Coroutine;
use yii\log\Dispatcher;
use yii\log\Logger as BaseLogger;

/**
 * Coroutine-safe Yii2 Logger keeping log message buffers isolated per Swoole coroutine.
 *
 * The logger itself remains shared so Yii::getLogger() and yii\log\Dispatcher keep
 * their normal integration, but mutable request messages are stored in coroutine
 * context to avoid one request flushing or clearing another request's logs.
 */
class Logger extends BaseLogger
{
    private const CONTEXT_MESSAGES_KEY = '__yiiSwooleLoggerMessages';
    private const CONTEXT_DIRTY_KEY = '__yiiSwooleLoggerDirty';

    public static function hasCoroutineActivity(): bool
    {
        if (Coroutine::getCid() < 0) {
            return false;
        }

        return !empty(Coroutine::getContext()[self::CONTEXT_DIRTY_KEY]);
    }

    public function log($message, $level, $category = 'application')
    {
        if (!$this->isCoroutineContext()) {
            parent::log($message, $level, $category);

            return;
        }

        $time = microtime(true);
        $traces = [];
        if ($this->traceLevel > 0) {
            $count = 0;
            $ts = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
            array_pop($ts);
            foreach ($ts as $trace) {
                if (isset($trace['file'], $trace['line']) && strpos($trace['file'], YII2_PATH) !== 0) {
                    unset($trace['object'], $trace['args']);
                    $traces[] = $trace;
                    if (++$count >= $this->traceLevel) {
                        break;
                    }
                }
            }
        }

        $messages = $this->getMessages();
        $data = [$message, $level, $category, $time, $traces, memory_get_usage()];
        if ($this->profilingAware && in_array($level, [self::LEVEL_PROFILE_BEGIN, self::LEVEL_PROFILE_END], true)) {
            $messages[($level === self::LEVEL_PROFILE_BEGIN ? 'begin-' : 'end-') . md5(json_encode($message))] = $data;
        } else {
            $messages[] = $data;
        }
        $this->setMessages($messages);
        Coroutine::getContext()[self::CONTEXT_DIRTY_KEY] = true;

        if ($this->flushInterval > 0 && count($messages) >= $this->flushInterval) {
            $this->flush();
        }
    }

    public function flush($final = false)
    {
        if (!$this->isCoroutineContext()) {
            parent::flush($final);

            return;
        }

        $currentMessages = $this->getMessages();
        if ($this->profilingAware) {
            $keep = [];
            $messages = [];
            foreach ($currentMessages as $index => $message) {
                if (is_int($index)) {
                    $messages[] = $message;
                } else {
                    if (strncmp((string) $index, 'begin-', 6) === 0) {
                        $oppositeProfile = 'end-' . substr((string) $index, 6);
                    } else {
                        $oppositeProfile = 'begin-' . substr((string) $index, 4);
                    }
                    if (array_key_exists($oppositeProfile, $currentMessages)) {
                        $messages[] = $message;
                    } else {
                        $keep[$index] = $message;
                    }
                }
            }
            if ($this->flushInterval > 0 && count($keep) >= $this->flushInterval) {
                $this->setMessages([]);
                $this->log(
                    'Number of dangling profiling block messages reached flushInterval value and therefore these were flushed. Please consider setting higher flushInterval value or making profiling blocks shorter.',
                    self::LEVEL_WARNING
                );
                $messages = array_merge($messages, array_values($keep));
            } else {
                $this->setMessages($keep);
            }
        } else {
            $messages = $currentMessages;
            $this->setMessages([]);
        }

        if ($this->dispatcher instanceof Dispatcher) {
            $this->dispatcher->dispatch($messages, $final);
        }
    }

    public function getProfiling($categories = [], $excludeCategories = [])
    {
        if (!$this->isCoroutineContext()) {
            return parent::getProfiling($categories, $excludeCategories);
        }

        $timings = $this->calculateTimings($this->getMessages());
        if (empty($categories) && empty($excludeCategories)) {
            return $timings;
        }

        foreach ($timings as $outerIndex => $outerTimingItem) {
            $currentIndex = $outerIndex;
            $matched = empty($categories);
            foreach ($categories as $category) {
                $prefix = rtrim($category, '*');
                if (
                    ($outerTimingItem['category'] === $category || $prefix !== $category)
                    && strpos($outerTimingItem['category'], $prefix) === 0
                ) {
                    $matched = true;
                    break;
                }
            }

            if ($matched) {
                foreach ($excludeCategories as $category) {
                    $prefix = rtrim($category, '*');
                    foreach ($timings as $innerIndex => $innerTimingItem) {
                        $currentIndex = $innerIndex;
                        if (
                            ($innerTimingItem['category'] === $category || $prefix !== $category)
                            && strpos($innerTimingItem['category'], $prefix) === 0
                        ) {
                            $matched = false;
                            break;
                        }
                    }
                }
            }

            if (!$matched) {
                unset($timings[$currentIndex]);
            }
        }

        return array_values($timings);
    }

    public function getElapsedTime()
    {
        if ($this->isCoroutineContext()) {
            $startedAt = Telemetry::getRequestStartedAt();
            if ($startedAt !== null) {
                return max(0.0, microtime(true) - $startedAt);
            }
        }

        return parent::getElapsedTime();
    }

    /**
     * Merges buffered coroutine log/profiling messages from the current child coroutine into a
     * parent coroutine so concurrent sub-tasks (e.g. Concurrent::run / HttpClient::batchSend)
     * appear in the parent request's logs, DB queries, and Timeline waterfall.
     */
    public static function mergeCoroutineMessagesIntoParent(int $parentCid): void
    {
        if (!extension_loaded('swoole') || $parentCid < 0 || Coroutine::getCid() < 0) {
            return;
        }

        if (!Coroutine::exists($parentCid)) {
            return;
        }

        $childContext = Coroutine::getContext();
        $childMessages = $childContext[self::CONTEXT_MESSAGES_KEY] ?? null;
        if (!is_array($childMessages) || $childMessages === []) {
            return;
        }

        $parentContext = Coroutine::getContext($parentCid);
        if ($parentContext === null) {
            return;
        }

        $parentMessages = $parentContext[self::CONTEXT_MESSAGES_KEY] ?? [];
        if (!is_array($parentMessages)) {
            $parentMessages = [];
        }

        // Append as sequential list entries so paired begin/end profiling records from the same
        // child coroutine remain contiguous and never overwrite identical tokens from sibling tasks.
        foreach ($childMessages as $message) {
            $parentMessages[] = $message;
        }

        $parentContext[self::CONTEXT_MESSAGES_KEY] = $parentMessages;
        $parentContext[self::CONTEXT_DIRTY_KEY] = true;

        unset($childContext[self::CONTEXT_MESSAGES_KEY], $childContext[self::CONTEXT_DIRTY_KEY]);
    }

    public static function clearCoroutineMessages(): void
    {
        if (Coroutine::getCid() < 0) {
            return;
        }

        $context = Coroutine::getContext();
        unset($context[self::CONTEXT_MESSAGES_KEY], $context[self::CONTEXT_DIRTY_KEY]);
    }

    /**
     * @return array<int|string, mixed>
     */
    private function getMessages(): array
    {
        if (!$this->isCoroutineContext()) {
            return $this->messages;
        }

        return Coroutine::getContext()[self::CONTEXT_MESSAGES_KEY] ?? [];
    }

    /**
     * @param array<int|string, mixed> $messages
     */
    private function setMessages(array $messages): void
    {
        if (!$this->isCoroutineContext()) {
            $this->messages = $messages;

            return;
        }

        Coroutine::getContext()[self::CONTEXT_MESSAGES_KEY] = $messages;
    }

    private function isCoroutineContext(): bool
    {
        return extension_loaded('swoole') && Coroutine::getCid() >= 0;
    }
}
