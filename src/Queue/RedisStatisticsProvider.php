<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Queue;

use yii\base\BaseObject;
use yii\queue\interfaces\DelayedCountInterface;
use yii\queue\interfaces\DoneCountInterface;
use yii\queue\interfaces\ReservedCountInterface;
use yii\queue\interfaces\WaitingCountInterface;

/**
 * Statistics Provider for Queue\RedisQueue.
 */
class RedisStatisticsProvider extends BaseObject implements DoneCountInterface, WaitingCountInterface, DelayedCountInterface, ReservedCountInterface
{
    protected RedisQueue $queue;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(RedisQueue $queue, array $config = [])
    {
        $this->queue = $queue;
        parent::__construct($config);
    }

    public function getWaitingCount(): int
    {
        return (int) $this->queue->redis->llen("{$this->queue->channel}.waiting");
    }

    public function getDelayedCount(): int
    {
        return (int) $this->queue->redis->zcount("{$this->queue->channel}.delayed", '-inf', '+inf');
    }

    public function getReservedCount(): int
    {
        return (int) $this->queue->redis->zcount("{$this->queue->channel}.reserved", '-inf', '+inf');
    }

    public function getDoneCount(): int
    {
        $prefix = $this->queue->channel;
        $waiting = (int) $this->queue->redis->llen("{$prefix}.waiting");
        $delayed = (int) $this->queue->redis->zcount("{$prefix}.delayed", '-inf', '+inf');
        $reserved = (int) $this->queue->redis->zcount("{$prefix}.reserved", '-inf', '+inf');
        $total = (int) $this->queue->redis->get("{$prefix}.message_id");

        return max(0, $total - $waiting - $delayed - $reserved);
    }
}
