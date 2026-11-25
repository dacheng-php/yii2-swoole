<?php

declare(strict_types=1);

namespace yii2swoole\queue;

use yii2swoole\log\CoroutineTargetInterface;
use yii2swoole\log\Logger;
use yii2swoole\redis\Connection as SwooleRedisConnection;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\WaitGroup;
use Throwable;
use Yii;
use yii\base\InvalidArgumentException;
use yii\base\NotSupportedException;
use yii\di\Instance;
use yii\queue\cli\Queue as CliQueue;
use yii\queue\interfaces\StatisticsProviderInterface;
use yii\redis\Connection;
use yii\redis\SocketException;

/**
 * Coroutine-safe Redis Queue driver with bounded concurrent job execution and clean shutdown.
 */
class RedisQueue extends CliQueue implements StatisticsProviderInterface
{
    private const LOCK_EXPIRE_SECONDS = 2;
    private const LOCK_MAX_RETRIES = 100;
    private const LOCK_RETRY_SLEEP = 0.01;

    /**
     * @var Connection|SwooleRedisConnection|array<string, mixed>|string
     */
    public $redis = 'redis';

    /** @var string */
    public $channel = 'queue';

    /** @var string */
    public $commandClass = RedisCommand::class;

    /** @var array<string, mixed> */
    public $loopConfig = ['class' => Loop::class];

    public bool $executeInline = true;

    public int $concurrency = 10;

    public float $shutdownGraceTimeout = 10.0;

    private bool $shutdownRequested = false;

    /** @var callable|null */
    private $shutdownCallback = null;

    private ?RedisStatisticsProvider $statisticsProviderInstance = null;

    public function init(): void
    {
        parent::init();
        $this->redis = Instance::ensure($this->redis, Connection::class);
    }

    public function setShutdownCallback(callable $callback): void
    {
        $this->shutdownCallback = $callback;
    }

    public function requestShutdown(): void
    {
        $this->shutdownRequested = true;
    }

    protected function isShutdownRequested(): bool
    {
        if ($this->shutdownRequested) {
            return true;
        }

        return $this->shutdownCallback !== null && (bool) ($this->shutdownCallback)();
    }

    public function run($repeat, $timeout = 0)
    {
        $this->shutdownRequested = false;

        if ($this->concurrency > 1 && Coroutine::getCid() >= 0) {
            return $this->runConcurrent((bool) $repeat, (int) $timeout);
        }

        return $this->runWorker(function (callable $canContinue) use ($repeat, $timeout): void {
            while ($canContinue() && !$this->isShutdownRequested()) {
                try {
                    $payload = $this->reserve((int) $timeout);
                    if ($payload !== null) {
                        [$id, $message, $ttr, $attempt] = $payload;
                        if ($this->handleMessage($id, $message, $ttr, $attempt)) {
                            $this->delete($id);
                        }
                        $this->flushJobLogger();
                    } elseif (!$repeat) {
                        break;
                    }
                } catch (SocketException $e) {
                    Yii::warning('Redis socket error in queue worker: ' . $e->getMessage(), __METHOD__);
                    $this->redis->close();
                    if (!$repeat) {
                        break;
                    }
                    $this->sleepBriefly(0.5);
                }
            }
        });
    }

    private function runConcurrent(bool $repeat, int $timeout): int
    {
        $jobChannel = new Channel(max(2, $this->concurrency * 2));
        $resultChannel = new Channel(max(2, $this->concurrency * 2));

        $processedCount = 0;
        $startTime = microtime(true);
        $workersWg = new WaitGroup();
        $deleterWg = new WaitGroup();

        // Start deleter coroutine
        $deleterWg->add();
        Coroutine::create(function () use ($resultChannel, $deleterWg): void {
            try {
                while (true) {
                    $id = $resultChannel->pop(0.2);
                    if ($id === false) {
                        if ($resultChannel->errCode === SWOOLE_CHANNEL_CLOSED) {
                            break;
                        }
                        continue;
                    }
                    try {
                        $this->delete($id);
                    } catch (Throwable $e) {
                        Yii::error("Failed to delete completed job #{$id}: {$e->getMessage()}", __METHOD__);
                    }
                }
            } finally {
                $this->redis->close();
                $deleterWg->done();
            }
        });

        // Start worker coroutines
        for ($i = 0; $i < $this->concurrency; $i++) {
            $workersWg->add();
            Coroutine::create(function () use ($i, $jobChannel, $resultChannel, $workersWg, &$processedCount): void {
                try {
                    while (true) {
                        $payload = $jobChannel->pop(0.5);
                        if ($payload === false) {
                            if ($jobChannel->errCode === SWOOLE_CHANNEL_CLOSED) {
                                break;
                            }
                            if ($this->isShutdownRequested()) {
                                break;
                            }
                            continue;
                        }

                        [$id, $message, $ttr, $attempt] = $payload;
                        $this->executeJobInIsolatedCoroutine($i, $id, $message, $ttr, $attempt, $resultChannel, $processedCount);
                    }
                } finally {
                    $workersWg->done();
                }
            });
        }

        // Producer loop runs on current coroutine so listen() stays alive indefinitely until shutdown or empty (when !repeat)
        try {
            while (!$this->isShutdownRequested()) {
                try {
                    $payload = $this->reserve($timeout);
                    if ($payload !== null) {
                        if (!$jobChannel->push($payload)) {
                            break;
                        }
                    } elseif (!$repeat) {
                        break;
                    }
                } catch (SocketException $e) {
                    Yii::warning('Redis socket error in queue producer: ' . $e->getMessage(), __METHOD__);
                    $this->redis->close();
                    if (!$repeat) {
                        break;
                    }
                    $this->sleepBriefly(0.5);
                }
            }
        } finally {
            $this->redis->close();
            $jobChannel->close();
        }

        // Wait for active workers to finish in-flight jobs within grace timeout
        $workersWg->wait($this->shutdownGraceTimeout);
        $resultChannel->close();
        $deleterWg->wait(max(1.0, $this->shutdownGraceTimeout / 2));

        $duration = max(0.001, microtime(true) - $startTime);
        Yii::info(sprintf(
            'Queue worker stopped: %d jobs processed in %.2fs (%.2f jobs/s)',
            $processedCount,
            $duration,
            $processedCount / $duration
        ), __METHOD__);

        return 0;
    }

    private function executeJobInIsolatedCoroutine(
        int $workerIndex,
        mixed $id,
        string $message,
        int|string $ttr,
        int|string $attempt,
        Channel $resultChannel,
        int &$processedCount
    ): void {
        $wg = new WaitGroup();
        $wg->add();

        // Execute each job inside its own child coroutine; register $wg->done() as the FIRST
        // defer so LIFO execution ensures all job DB/Redis defers complete before taking the next job.
        Coroutine::create(function () use ($workerIndex, $id, $message, $ttr, $attempt, $resultChannel, &$processedCount, $wg): void {
            Coroutine::defer(static function () use ($wg): void {
                $wg->done();
            });

            try {
                $success = $this->handleMessage($id, $message, $ttr, $attempt);
                if ($success) {
                    $resultChannel->push($id);
                    $processedCount++;
                }
            } catch (Throwable $e) {
                Yii::error("Worker #{$workerIndex}: Job #{$id} failed: {$e->getMessage()}", __METHOD__);
            } finally {
                $this->flushJobLogger();
            }
        });

        $wg->wait();
    }

    public function status($id)
    {
        if (!is_numeric($id) || $id <= 0) {
            throw new InvalidArgumentException("Unknown message ID: {$id}.");
        }

        if ($this->redis->hexists("{$this->channel}.attempts", $id)) {
            return self::STATUS_RESERVED;
        }
        if ($this->redis->hexists("{$this->channel}.messages", $id)) {
            return self::STATUS_WAITING;
        }

        return self::STATUS_DONE;
    }

    public function clear(): void
    {
        $retries = self::LOCK_MAX_RETRIES;
        while (!$this->redis->set("{$this->channel}.moving_lock", '1', 'NX', 'EX', self::LOCK_EXPIRE_SECONDS) && $retries-- > 0) {
            $this->sleepBriefly(self::LOCK_RETRY_SLEEP);
        }

        // Use non-blocking cursor SCAN instead of O(N) KEYS to avoid stalling Redis in production
        $cursor = '0';
        $pattern = "{$this->channel}.*";
        do {
            $reply = $this->redis->executeCommand('SCAN', [$cursor, 'MATCH', $pattern, 'COUNT', '500']);
            if (!is_array($reply) || count($reply) < 2) {
                break;
            }

            $cursor = (string) $reply[0];
            $keys = is_array($reply[1]) ? $reply[1] : [];
            if ($keys !== []) {
                $this->redis->executeCommand('DEL', $keys);
            }
        } while ($cursor !== '0');
    }

    public function remove($id): bool
    {
        $retries = self::LOCK_MAX_RETRIES;
        while (!$this->redis->set("{$this->channel}.moving_lock", '1', 'NX', 'EX', self::LOCK_EXPIRE_SECONDS) && $retries-- > 0) {
            $this->sleepBriefly(self::LOCK_RETRY_SLEEP);
        }

        if ($this->redis->hdel("{$this->channel}.messages", $id)) {
            $this->redis->zrem("{$this->channel}.delayed", $id);
            $this->redis->zrem("{$this->channel}.reserved", $id);
            $this->redis->lrem("{$this->channel}.waiting", 0, $id);
            $this->redis->hdel("{$this->channel}.attempts", $id);

            return true;
        }

        return false;
    }

    /**
     * @return array{0:string,1:string,2:int,3:int}|null
     */
    protected function reserve(int $timeout): ?array
    {
        if ($this->redis->set("{$this->channel}.moving_lock", '1', 'NX', 'EX', self::LOCK_EXPIRE_SECONDS)) {
            $this->moveExpired("{$this->channel}.delayed");
            $this->moveExpired("{$this->channel}.reserved");
        }

        $id = $this->popWaiting($timeout);
        if (!$id) {
            return null;
        }

        $payload = $this->redis->hget("{$this->channel}.messages", $id);
        if ($payload === null || $payload === false) {
            return null;
        }

        [$ttr, $message] = explode(';', (string) $payload, 2);
        $ttrInt = (int) $ttr;
        $this->redis->zadd("{$this->channel}.reserved", time() + $ttrInt, $id);
        $attempt = (int) $this->redis->hincrby("{$this->channel}.attempts", $id, 1);

        return [(string) $id, $message, $ttrInt, $attempt];
    }

    private function popWaiting(int $timeout): string|false|null
    {
        if ($timeout <= 0) {
            return $this->redis->rpop("{$this->channel}.waiting");
        }

        $elapsed = 0;
        while ($elapsed < $timeout) {
            if ($this->isShutdownRequested()) {
                return false;
            }

            $step = min(1, $timeout - $elapsed);
            $result = $this->redis->brpop("{$this->channel}.waiting", $step);
            if (is_array($result) && isset($result[1])) {
                return (string) $result[1];
            }

            $elapsed += $step;
        }

        return false;
    }

    protected function moveExpired(string $from): void
    {
        $now = time();
        $waitingKey = "{$this->channel}.waiting";

        // Atomic Lua migration prevents dropping jobs that expire between ZREVRANGEBYSCORE and ZREMRANGEBYSCORE
        $lua = <<<'LUA'
local expired = redis.call('ZREVRANGEBYSCORE', KEYS[1], ARGV[1], '-inf')
if #expired > 0 then
    redis.call('ZREMRANGEBYSCORE', KEYS[1], '-inf', ARGV[1])
    for i = 1, #expired do
        redis.call('RPUSH', KEYS[2], expired[i])
    end
end
return #expired
LUA;

        $this->redis->executeCommand('EVAL', [$lua, 2, $from, $waitingKey, (string) $now]);
    }

    protected function delete(mixed $id): void
    {
        $this->redis->zrem("{$this->channel}.reserved", $id);
        $this->redis->hdel("{$this->channel}.attempts", $id);
        $this->redis->hdel("{$this->channel}.messages", $id);
    }

    protected function pushMessage($message, $ttr, $delay, $priority)
    {
        if ($priority !== null) {
            throw new NotSupportedException('Job priority is not supported by Queue\\RedisQueue.');
        }

        $id = $this->redis->incr("{$this->channel}.message_id");
        $this->redis->hset("{$this->channel}.messages", $id, "{$ttr};{$message}");

        if (!$delay) {
            $this->redis->lpush("{$this->channel}.waiting", $id);
        } else {
            $this->redis->zadd("{$this->channel}.delayed", time() + (int) $delay, $id);
        }

        return $id;
    }

    public function getStatisticsProvider(): RedisStatisticsProvider
    {
        return $this->statisticsProviderInstance ??= new RedisStatisticsProvider($this);
    }

    private function flushJobLogger(): void
    {
        if (!Yii::$app || !Yii::$app->has('log')) {
            return;
        }

        $log = Yii::$app->getLog();
        $logger = $log->getLogger();
        if ($logger instanceof Logger) {
            $logger->flush(true);
            foreach ($log->targets as $target) {
                if ($target instanceof CoroutineTargetInterface) {
                    $target->clearCoroutineMessages();
                }
            }
            Logger::clearCoroutineMessages();
        }
    }

    private function sleepBriefly(float $seconds): void
    {
        if (Coroutine::getCid() >= 0) {
            Coroutine::sleep($seconds);
        } else {
            usleep((int) ($seconds * 1_000_000));
        }
    }
}
