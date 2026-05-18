<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Queue;

use Dacheng\Yii2\Swoole\Redis\CoroutineRedisConnection;
use yii\redis\Connection;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use yii\base\InvalidArgumentException;
use yii\base\NotSupportedException;
use yii\di\Instance;
use yii\queue\cli\Queue as CliQueue;
use yii\queue\interfaces\StatisticsProviderInterface;
use yii\redis\SocketException;

/**
 * Coroutine Redis Queue for Swoole.
 *
 * This queue driver is optimized for Swoole coroutine environments.
 * It uses coroutine-safe Redis connections and supports concurrent job processing.
 *
 * Configuration example:
 * ```php
 * 'queue' => [
 *     'class' => \Dacheng\Yii2\Swoole\Queue\CoroutineRedisQueue::class,
 *     'redis' => 'redis', // CoroutineRedisConnection component
 *     'channel' => 'queue',
 * ],
 * ```
 *
 * @property-read CoroutineRedisStatisticsProvider $statisticsProvider
 */
class CoroutineRedisQueue extends CliQueue implements StatisticsProviderInterface
{
    /**
     * Default timeout values in seconds
     */
    private const LOCK_ACQUIRE_TIMEOUT = 1.0;
    private const LOCK_ACQUIRE_RETRY_DELAY = 10000; // microseconds
    private const WORKER_SHUTDOWN_TIMEOUT = 5.0;
    private const WORKER_GRACE_WAIT = 2.0;
    private const CHANNEL_POP_CHECK_INTERVAL = 0.1;
    private const DELETER_POP_TIMEOUT = 0.1;
    private const CHANNEL_CLOSE_DELAY = 0.2;

    /**
     * @var Connection|CoroutineRedisConnection|array|string
     */
    public $redis = 'redis';

    /**
     * @var string Redis key prefix for queue data
     */
    public $channel = 'queue';

    /**
     * @var string command class name
     */
    public $commandClass = CoroutineRedisCommand::class;

    /**
     * @var array Loop configuration. Use CoroutineLoop to avoid signal handling conflicts.
     */
    public $loopConfig = ['class' => CoroutineLoop::class];

    /**
     * @var bool Whether to execute jobs in the same process (faster) or fork child processes (more isolated).
     */
    public $executeInline = true;

    /**
     * @var int Number of concurrent coroutines for job processing.
     */
    public $concurrency = 10;

    /**
     * @var callable|null Callback that returns true if shutdown is requested
     */
    private $shutdownCallback = null;

    /**
     * @var Channel|null Job queue (Producer -> Workers)
     */
    private ?Channel $jobChannel = null;

    /**
     * @var Channel|null Completed job IDs (Workers -> Deleter)
     */
    private ?Channel $resultChannel = null;

    /**
     * @var bool Whether channels have been closed
     */
    private bool $channelsClosed = false;

    /**
     * @var int Number of currently active worker coroutines
     */
    private int $activeWorkers = 0;

    /**
     * @var bool Whether processing should stop
     */
    private bool $shouldStop = false;

    /**
     * @var bool Whether producer coroutine has finished
     */
    private bool $producerDone = false;

    /**
     * @var bool Whether deleter coroutine has finished
     */
    private bool $deleterDone = false;

    /**
     * @var int Total number of jobs processed
     */
    private int $processedCount = 0;

    /**
     * @var float Processing start time
     */
    private float $startTime = 0.0;

    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();
        $this->redis = Instance::ensure($this->redis, Connection::class);
    }

    /**
     * Sets a callback to check if shutdown is requested
     */
    public function setShutdownCallback(callable $callback): void
    {
        $this->shutdownCallback = $callback;
    }

    /**
     * Checks if shutdown has been requested
     */
    protected function isShutdownRequested(): bool
    {
        if ($this->shutdownCallback === null) {
            return false;
        }

        return (bool) call_user_func($this->shutdownCallback);
    }

    /**
     * Listens queue and runs each job.
     *
     * @param bool $repeat whether to continue listening when queue is empty.
     * @param int $timeout number of seconds to wait for next message.
     * @return null|int exit code.
     */
    public function run($repeat, $timeout = 0)
    {
        // Use concurrent processing if concurrency > 1 and in coroutine context
        if ($this->concurrency > 1 && Coroutine::getCid() >= 0) {
            return $this->runConcurrent($repeat, $timeout);
        }

        // Fallback to serial processing
        \Yii::info("Using serial processing mode (concurrency={$this->concurrency})", __METHOD__);

        return $this->runWorker(function (callable $canContinue) use ($repeat, $timeout) {
            $this->redis->open();

            try {
                $jobCount = 0;
                $startTime = microtime(true);

                \Yii::info("Serial worker loop starting", __METHOD__);

                while ($canContinue() && !$this->isShutdownRequested()) {
                    try {
                        if (($payload = $this->reserve($timeout)) !== null) {
                            list($id, $message, $ttr, $attempt) = $payload;
                            if ($this->handleMessage($id, $message, $ttr, $attempt)) {
                                $this->delete($id);
                            }
                            $jobCount++;
                        } elseif (!$repeat) {
                            break;
                        }

                        if ($this->isShutdownRequested()) {
                            $duration = round(microtime(true) - $startTime, 2);
                            $rate = $duration > 0 ? round($jobCount / $duration, 2) : 0;
                            \Yii::info("Shutdown requested, stopping worker after current job. Processed {$jobCount} jobs in {$duration}s ({$rate} jobs/s)", __METHOD__);
                            break;
                        }
                    } catch (SocketException $e) {
                        $this->redis->close();
                        $this->redis->open();

                        if (!$repeat) {
                            break;
                        }
                    }
                }
            } finally {
                $this->redis->close();
            }
        });
    }

    /**
     * Runs queue with concurrent job processing.
     *
     * @param bool $repeat whether to continue listening when queue is empty.
     * @param int $timeout number of seconds to wait for next message.
     * @return null|int exit code.
     */
    private function runConcurrent(bool $repeat, int $timeout): ?int
    {
        $this->initializeConcurrentChannels();
        $this->initializeConcurrentState();

        // Create three types of coroutines
        $producerCoroutine = $this->createProducerCoroutine($repeat, $timeout);
        $workerCoroutines = $this->createWorkerCoroutines();
        $deleterCoroutine = $this->createDeleterCoroutine();

        $exitCode = $this->waitForConcurrentCompletion($producerCoroutine, $workerCoroutines, $deleterCoroutine);
        $this->cleanupConcurrent();

        $duration = round(microtime(true) - $this->startTime, 2);
        $rate = $duration > 0 ? round($this->processedCount / $duration, 2) : 0;

        $this->logConcurrentCompletion($exitCode, $duration, $rate);

        return $exitCode;
    }

    /**
     * Initializes communication channels between coroutines.
     */
    private function initializeConcurrentChannels(): void
    {
        $capacity = $this->concurrency * 2;
        $this->jobChannel = new Channel($capacity);
        $this->resultChannel = new Channel($capacity);
        $this->channelsClosed = false;
    }

    /**
     * Initializes concurrent processor state variables.
     */
    private function initializeConcurrentState(): void
    {
        $this->channelsClosed = false;
        $this->activeWorkers = 0;
        $this->shouldStop = false;
        $this->producerDone = false;
        $this->deleterDone = false;
        $this->processedCount = 0;
        $this->startTime = microtime(true);
    }

    /**
     * Creates the producer coroutine that fetches jobs from Redis.
     */
    private function createProducerCoroutine(bool $repeat, int $timeout)
    {
        return Coroutine::create(function () use ($repeat, $timeout) {
            $redis = $this->createRedisConnection();
            $redis->open();

            try {
                while ($this->canContinueProducing($repeat) && !$this->isShutdownRequested()) {
                    if (($payload = $this->reserve($timeout, $redis)) !== null) {
                        $this->jobChannel->push($payload);
                    } elseif (!$repeat) {
                        $this->shouldStop = true;
                        break;
                    }
                }

                $this->producerDone = true;
            } catch (SocketException $e) {
                $redis->close();
                $redis->open();

                if (!$repeat) {
                    $this->shouldStop = true;
                }
            }
        });
    }

    /**
     * Creates worker coroutines that process jobs concurrently.
     */
    private function createWorkerCoroutines(): array
    {
        $workers = [];

        for ($i = 0; $i < $this->concurrency; $i++) {
            $workers[] = $this->createWorkerCoroutine($i);
        }

        return $workers;
    }

    /**
     * Creates a single worker coroutine.
     */
    private function createWorkerCoroutine(int $index)
    {
        return Coroutine::create(function () use ($index) {
            $this->activeWorkers++;

            try {
                $redis = $this->createRedisConnection();
                $redis->open();

                while (true) {
                    if ($this->isShutdownRequested() || $this->shouldStop) {
                        \Yii::info("Worker #{$index}: shutdown_requested", __METHOD__);
                        break;
                    }

                    $payload = $this->jobChannel->pop(self::WORKER_GRACE_WAIT);

                    if ($payload === false) {
                        $stats = @$this->jobChannel->stats();
                        if ($stats === false) {
                            \Yii::info("Worker #{$index}: channel_closed", __METHOD__);
                            break;
                        }
                        continue;
                    }

                    if ($payload === null) {
                        break;
                    }

                    [$id, $message, $ttr, $attempt] = $payload;

                    try {
                        $success = $this->handleMessage($id, $message, $ttr, $attempt);
                        if ($success) {
                            $this->resultChannel->push($id);
                            $this->processedCount++;
                        }
                    } catch (\Throwable $e) {
                        \Yii::error("Worker #{$index}: Job #{$id} failed: " . $e->getMessage(), __METHOD__);
                    }
                }
            } catch (SocketException $e) {
                \Yii::error("Worker #{$index}: Connection error: " . $e->getMessage(), __METHOD__);
            } finally {
                $this->activeWorkers--;
            }
        });
    }

    /**
     * Creates the deleter coroutine that removes completed jobs.
     */
    private function createDeleterCoroutine()
    {
        return Coroutine::create(function () {
            $redis = $this->createRedisConnection();
            $redis->open();

            try {
                while (true) {
                    if ($this->isShutdownRequested() || $this->deleterDone) {
                        \Yii::info("Deleter: shutdown_requested", __METHOD__);
                        break;
                    }

                    $id = $this->resultChannel->pop(self::DELETER_POP_TIMEOUT);

                    if ($id !== false) {
                        $this->delete($id, $redis);
                    }

                    if ($this->producerDone && $this->activeWorkers === 0) {
                        \Yii::info("Deleter: finished", __METHOD__);
                        break;
                    }
                }
            } catch (\Throwable $e) {
                \Yii::error("Deleter error: " . $e->getMessage(), __METHOD__);
            }
        });
    }

    /**
     * Waits for all coroutines to complete and handles shutdown.
     */
    private function waitForConcurrentCompletion(
        $producerCoroutine,
        array $workerCoroutines,
        $deleterCoroutine
    ): int {
        $shutdownStartTime = microtime(true);
        $forcedShutdown = false;

        while (!$this->producerDone || $this->activeWorkers > 0 || !$this->deleterDone) {
            Coroutine::sleep(self::CHANNEL_POP_CHECK_INTERVAL);

            $elapsed = microtime(true) - $shutdownStartTime;

            if ($this->isShutdownRequested() && $this->producerDone) {
                $forcedShutdown = true;
                break;
            }

            if ($elapsed >= self::WORKER_SHUTDOWN_TIMEOUT) {
                $forcedShutdown = true;
                break;
            }
        }

        // Close channels if not already closed
        if (!$this->channelsClosed) {
            $this->closeConcurrentChannels();
        }

        return $forcedShutdown ? 1 : 0;
    }

    /**
     * Closes all communication channels.
     */
    private function closeConcurrentChannels(): void
    {
        if (!$this->channelsClosed) {
            try {
                $this->jobChannel->close();
                $this->resultChannel->close();
                $this->channelsClosed = true;
            } catch (\Throwable $e) {
                \Yii::error("Error closing channels: " . $e->getMessage(), __METHOD__);
            }
        }
    }

    /**
     * Cleans up resources after concurrent processing.
     */
    private function cleanupConcurrent(): void
    {
        $this->channelsClosed = false;
        $this->jobChannel = null;
        $this->resultChannel = null;
    }

    /**
     * Checks if we should continue producing jobs.
     */
    private function canContinueProducing(bool $repeat): bool
    {
        return $repeat || !$this->shouldStop;
    }

    /**
     * Logs concurrent job processor completion.
     */
    private function logConcurrentCompletion(int $exitCode, float $duration, float $rate): void
    {
        if ($this->isShutdownRequested()) {
            \Yii::info(sprintf(
                "Queue worker shutdown: %d jobs processed in %.2fs (%.2f jobs/s), exit code %d",
                $this->processedCount,
                $duration,
                $rate,
                $exitCode
            ), __METHOD__);
        } else {
            \Yii::info(sprintf(
                "Queue worker finished: %d jobs processed in %.2fs (%.2f jobs/s), exit code %d",
                $this->processedCount,
                $duration,
                $rate,
                $exitCode
            ), __METHOD__);
        }
    }

    /**
     * @inheritdoc
     */
    public function status($id)
    {
        if (!is_numeric($id) || $id <= 0) {
            throw new InvalidArgumentException("Unknown message ID: $id.");
        }

        $this->redis->open();
        try {
            if ($this->redis->hexists("$this->channel.attempts", $id)) {
                return self::STATUS_RESERVED;
            }

            if ($this->redis->hexists("$this->channel.messages", $id)) {
                return self::STATUS_WAITING;
            }

            return self::STATUS_DONE;
        } finally {
            $this->redis->close();
        }
    }

    /**
     * Clears the queue.
     */
    public function clear()
    {
        $this->redis->open();
        try {
            $retries = (int) (self::LOCK_ACQUIRE_RETRY_DELAY * 100);
            $lockAcquired = false;
            while (!($lockAcquired = $this->redis->set("$this->channel.moving_lock", true, 'NX', 'EX', (int) self::LOCK_ACQUIRE_TIMEOUT)) && $retries-- > 0) {
                usleep(self::LOCK_ACQUIRE_RETRY_DELAY);
            }

            if (!$lockAcquired) {
                \Yii::warning("Could not acquire lock to clear queue, proceeding anyway", __METHOD__);
            }

            $keys = $this->redis->keys("$this->channel.*");
            if (!empty($keys)) {
                $this->redis->executeCommand('DEL', $keys);
                \Yii::info("Cleared " . count($keys) . " keys from queue", __METHOD__);
            }
        } finally {
            $this->redis->close();
        }
    }

    /**
     * Removes a job by ID.
     */
    public function remove($id)
    {
        $this->redis->open();
        try {
            $retries = (int) (self::LOCK_ACQUIRE_RETRY_DELAY * 100);
            while (!$this->redis->set("$this->channel.moving_lock", true, 'NX', 'EX', (int) self::LOCK_ACQUIRE_TIMEOUT) && $retries-- > 0) {
                usleep(self::LOCK_ACQUIRE_RETRY_DELAY);
            }

            if ($this->redis->hdel("$this->channel.messages", $id)) {
                $this->redis->zrem("$this->channel.delayed", $id);
                $this->redis->zrem("$this->channel.reserved", $id);
                $this->redis->lrem("$this->channel.waiting", 0, $id);
                $this->redis->hdel("$this->channel.attempts", $id);

                return true;
            }

            return false;
        } finally {
            $this->redis->close();
        }
    }

    /**
     * Reserves a job from the queue.
     *
     * @param int $timeout timeout in seconds
     * @param Connection|null $redis Optional Redis connection to use
     * @return array|null payload [id, message, ttr, attempt]
     */
    protected function reserve(int $timeout, ?Connection $redis = null): ?array
    {
        $redis = $redis ?? $this->redis;
        if ($redis->set("{$this->channel}.moving_lock", true, 'NX', 'EX', (int) self::LOCK_ACQUIRE_TIMEOUT)) {
            $this->moveExpired("{$this->channel}.delayed", $redis);
            $this->moveExpired("{$this->channel}.reserved", $redis);
        }

        $id = $this->popWaiting($redis, $timeout);

        if (!$id) {
            return null;
        }

        $payload = $redis->hget("{$this->channel}.messages", $id);
        if (null === $payload) {
            return null;
        }

        [$ttr, $message] = explode(';', $payload, 2);
        $redis->zadd("{$this->channel}.reserved", time() + $ttr, $id);
        $attempt = $redis->hincrby("{$this->channel}.attempts", $id, 1);

        return [$id, $message, $ttr, $attempt];
    }

    /**
     * Pops a waiting job from the queue with timeout.
     *
     * @param Connection $redis The Redis connection to use
     * @param int $timeout timeout in seconds
     * @return string|false The job ID or false if none available
     */
    private function popWaiting(Connection $redis, int $timeout)
    {
        if (!$timeout) {
            return $redis->rpop("{$this->channel}.waiting");
        }

        $maxTimeout = 1;
        $elapsed = 0;

        while ($elapsed < $timeout) {
            if ($this->isShutdownRequested()) {
                return false;
            }

            $remainingTimeout = min($maxTimeout, $timeout - $elapsed);
            $result = $redis->brpop("{$this->channel}.waiting", $remainingTimeout);

            if ($result) {
                return $result[1];
            }

            $elapsed += $remainingTimeout;
            Coroutine::sleep(self::CHANNEL_POP_CHECK_INTERVAL);
        }

        return false;
    }

    /**
     * Moves expired jobs from a sorted set to the waiting list.
     *
     * @param string $from The sorted set key to move expired jobs from
     * @param Connection|null $redis Optional Redis connection to use
     */
    protected function moveExpired(string $from, ?Connection $redis = null): void
    {
        $redis = $redis ?? $this->redis;
        $now = time();
        $expired = $redis->zrevrangebyscore($from, $now, '-inf');
        if (!empty($expired)) {
            $redis->zremrangebyscore($from, '-inf', $now);
            foreach ($expired as $id) {
                $redis->rpush("{$this->channel}.waiting", $id);
            }
        }
    }

    /**
     * Deletes message by ID.
     *
     * @param mixed $id The message ID
     * @param Connection|null $redis Optional Redis connection to use
     */
    protected function delete($id, ?Connection $redis = null): void
    {
        $redis = $redis ?? $this->redis;
        $redis->zrem("{$this->channel}.reserved", $id);
        $redis->hdel("{$this->channel}.attempts", $id);
        $redis->hdel("{$this->channel}.messages", $id);
    }

    /**
     * @inheritdoc
     */
    protected function pushMessage($message, $ttr, $delay, $priority)
    {
        if ($priority !== null) {
            throw new NotSupportedException('Job priority is not supported in the driver.');
        }

        $this->redis->open();
        try {
            $id = $this->redis->incr("$this->channel.message_id");
            $this->redis->hset("$this->channel.messages", $id, "$ttr;$message");

            if (!$delay) {
                $this->redis->lpush("$this->channel.waiting", $id);
            } else {
                $this->redis->zadd("$this->channel.delayed", time() + $delay, $id);
            }

            return $id;
        } finally {
            $this->redis->close();
        }
    }

    /**
     * Creates a new Redis connection instance with the same configuration.
     */
    protected function createRedisConnection()
    {
        $redisConfig = [];

        if (is_string($this->redis)) {
            $redisConfig = \Yii::$app->components[$this->redis] ?? [];
        } elseif (is_array($this->redis)) {
            $redisConfig = $this->redis;
        } else {
            $redisConfig = ['class' => get_class($this->redis)];
            $properties = ['hostname', 'port', 'database', 'password', 'username',
                          'poolMaxActive', 'poolWaitTimeout', 'connectionTimeout', 'dataTimeout'];
            foreach ($properties as $prop) {
                if (property_exists($this->redis, $prop)) {
                    $redisConfig[$prop] = $this->redis->$prop;
                }
            }
        }

        return \Yii::createObject($redisConfig);
    }

    private $_statisticsProvider;

    /**
     * @return CoroutineRedisStatisticsProvider
     */
    public function getStatisticsProvider()
    {
        if (!$this->_statisticsProvider) {
            $this->_statisticsProvider = new CoroutineRedisStatisticsProvider($this);
        }
        return $this->_statisticsProvider;
    }
}
