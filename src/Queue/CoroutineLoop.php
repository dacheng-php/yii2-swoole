<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Queue;

use yii\base\BaseObject;
use yii\queue\cli\LoopInterface;
use yii\queue\cli\Queue;

/**
 * Simple Loop implementation for Coroutine Queue.
 *
 * Unlike SignalLoop, this loop does not handle signals itself.
 * Signal handling is done by CoroutineRedisCommand using Swoole's Process::signal().
 * This avoids conflicts between pcntl_signal() and Process::signal().
 */
class CoroutineLoop extends BaseObject implements LoopInterface
{
    /**
     * @var Queue
     */
    protected $queue;

    /**
     * @var bool Whether the loop should continue (instance-level for coroutine safety)
     */
    private bool $shouldContinue = true;

    /**
     * @param Queue $queue
     * @inheritdoc
     */
    public function __construct($queue, array $config = [])
    {
        $this->queue = $queue;
        parent::__construct($config);
    }

    /**
     * Stops the loop
     */
    public function stop(): void
    {
        $this->shouldContinue = false;
    }

    /**
     * Resets the loop state (for testing)
     */
    public function resetState(): void
    {
        $this->shouldContinue = true;
    }

    /**
     * @inheritdoc
     */
    public function canContinue()
    {
        return $this->shouldContinue;
    }
}

