<?php

declare(strict_types=1);

namespace yii2swoole\queue;

use yii2swoole\log\Logger;
use yii2swoole\pool\PoolRegistry;
use Swoole\Coroutine;
use Swoole\Process;
use Throwable;
use Yii;
use yii\console\Exception;
use yii\queue\cli\Command as CliCommand;
use yii\queue\cli\InfoAction;

/**
 * Console command for managing Queue\RedisQueue workers.
 *
 * Explicitly enters a Swoole Coroutine\run() scheduler inside worker actions
 * (actionRun / actionListen), so standard yii\console\Application works without
 * any hidden argv-sniffing ConsoleApplication wrapper.
 */
class RedisCommand extends CliCommand
{
    /** @var RedisQueue */
    public $queue;

    /** @var string */
    public $defaultAction = 'info';

    /** @var bool */
    public $isolate = false;

    public int $hookFlags = SWOOLE_HOOK_ALL;

    private bool $shutdownRequested = false;

    public function actions(): array
    {
        return [
            'info' => InfoAction::class,
        ];
    }

    protected function isWorkerAction($actionID): bool
    {
        return in_array($actionID, ['run', 'listen'], true);
    }

    public function actionRun(): ?int
    {
        return $this->runInCoroutine(function (): ?int {
            $this->registerSignalHandlers();
            $this->queue->setShutdownCallback(fn (): bool => $this->shutdownRequested);

            $this->stdout("Processing waiting jobs (concurrency={$this->queue->concurrency})...\n");

            try {
                return $this->queue->run(false, 0);
            } finally {
                $this->performGracefulShutdown();
            }
        });
    }

    public function actionListen($timeout = 3): ?int
    {
        if (!is_numeric($timeout) || (int) $timeout < 1) {
            throw new Exception('Timeout must be a positive integer.');
        }

        return $this->runInCoroutine(function () use ($timeout): ?int {
            $this->registerSignalHandlers();
            $this->queue->setShutdownCallback(fn (): bool => $this->shutdownRequested);

            $cid = extension_loaded('swoole') ? Coroutine::getCid() : -1;
            $this->stdout(sprintf(
                "Queue listener started (coroutine=%s, concurrency=%d, timeout=%ds). Press Ctrl+C to stop.\n",
                $cid >= 0 ? "cid:{$cid}" : 'disabled',
                $this->queue->concurrency,
                (int) $timeout
            ));

            try {
                return $this->queue->run(true, (int) $timeout);
            } finally {
                $this->performGracefulShutdown();
            }
        });
    }

    public function actionClear(): void
    {
        if ($this->confirm('Are you sure you want to clear the queue?')) {
            $this->queue->clear();
            $this->stdout("Queue cleared.\n");
        }
    }

    public function actionRemove($id): void
    {
        if (!$this->queue->remove($id)) {
            throw new Exception("Job #{$id} not found.");
        }
        $this->stdout("Job #{$id} removed.\n");
    }

    /**
     * Explicitly runs a worker action inside a Swoole coroutine scheduler when available.
     *
     * @param callable(): (?int) $callback
     */
    private function runInCoroutine(callable $callback): ?int
    {
        if (!extension_loaded('swoole') || Coroutine::getCid() >= 0) {
            return $callback();
        }

        if (!Yii::getLogger() instanceof Logger) {
            Yii::setLogger(new Logger());
        }

        Coroutine::set(['hook_flags' => $this->hookFlags]);

        $exitCode = 0;
        Coroutine\run(static function () use ($callback, &$exitCode): void {
            $exitCode = $callback();
        });

        return $exitCode;
    }

    protected function registerSignalHandlers(): void
    {
        if (!extension_loaded('swoole')) {
            return;
        }

        $handler = function (int $signo): void {
            $this->shutdownRequested = true;
            $this->queue->requestShutdown();
            $this->stdout("\nReceived signal {$signo}, stopping queue worker gracefully...\n");
        };

        Process::signal(SIGTERM, $handler);
        Process::signal(SIGINT, $handler);
    }

    protected function performGracefulShutdown(): void
    {
        if (extension_loaded('swoole')) {
            Process::signal(SIGTERM, null);
            Process::signal(SIGINT, null);
        }

        if (Yii::$app && Yii::$app->has('log')) {
            try {
                $log = Yii::$app->getLog();
                $log->getLogger()->flush(true);
                foreach ($log->targets as $target) {
                    if (method_exists($target, 'shutdown')) {
                        $target->shutdown();
                    }
                }
            } catch (Throwable) {
                // Best-effort flush on exit.
            }
        }

        PoolRegistry::shutdownAll();
    }
}
