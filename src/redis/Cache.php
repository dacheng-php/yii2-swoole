<?php

declare(strict_types=1);

namespace yii2swoole\redis;

use Swoole\Coroutine;
use yii\db\Exception;
use yii\di\Instance;
use yii\redis\Cache as BaseCache;

/**
 * Coroutine-safe Redis Cache component designed to be shared across all coroutines
 * inside a Swoole worker process.
 *
 * Cluster topology detection is cached once per worker process (avoiding a CLUSTER INFO
 * round-trip on every request), while replica selection and hash-tag tracking remain
 * strictly coroutine-local.
 */
class Cache extends BaseCache
{
    private const CONTEXT_STATE_KEY = '__yii2SwooleRedisCacheState';

    /**
     * @var Connection|string|array<string, mixed>
     */
    public $redis = 'redis';

    private ?bool $detectedCluster = null;

    /**
     * @var array{replica:?Connection,hashTagAvailable:bool}
     */
    private array $fallbackState = [
        'replica' => null,
        'hashTagAvailable' => false,
    ];

    public function init(): void
    {
        parent::init();
        $this->redis = Instance::ensure($this->redis, Connection::class);
    }

    protected function getReplica()
    {
        if ($this->enableReplicas === false) {
            return $this->redis;
        }

        $state = $this->getState();
        if ($state['replica'] instanceof Connection) {
            return $state['replica'];
        }

        if (empty($this->replicas)) {
            $state['replica'] = $this->redis;
            $this->setState($state);
            return $this->redis;
        }

        $replicas = $this->replicas;
        shuffle($replicas);
        /** @var Connection $replica */
        $replica = Instance::ensure(array_shift($replicas), Connection::class);
        $state['replica'] = $replica;
        $this->setState($state);

        return $replica;
    }

    public function getIsCluster()
    {
        if ($this->forceClusterMode !== null) {
            return $this->forceClusterMode;
        }

        if ($this->detectedCluster === null) {
            $isCluster = false;
            try {
                $this->redis->executeCommand('CLUSTER INFO');
                $isCluster = true;
            } catch (Exception) {
                $isCluster = false;
            }
            $this->detectedCluster = $isCluster;
        }

        return $this->detectedCluster;
    }

    public function buildKey($key)
    {
        if (
            is_string($key)
            && $this->getIsCluster()
            && preg_match('/^(.*)({.+})(.*)$/', $key, $matches) === 1
        ) {
            $state = $this->getState();
            $state['hashTagAvailable'] = true;
            $this->setState($state);

            return parent::buildKey($matches[1] . $matches[3]) . $matches[2];
        }

        return parent::buildKey($key);
    }

    protected function getValues($keys)
    {
        $state = $this->getState();
        if ($this->getIsCluster() && !$state['hashTagAvailable']) {
            return parent::getValues($keys);
        }

        $response = $this->getReplica()->executeCommand('MGET', $keys);
        $result = [];
        $i = 0;
        foreach ($keys as $key) {
            $result[$key] = $response[$i++] ?? false;
        }

        $state['hashTagAvailable'] = false;
        $this->setState($state);

        return $result;
    }

    protected function setValues($data, $expire)
    {
        $state = $this->getState();
        if ($this->getIsCluster() && !$state['hashTagAvailable']) {
            return parent::setValues($data, $expire);
        }

        $args = [];
        foreach ($data as $key => $value) {
            $args[] = $key;
            $args[] = $value;
        }

        $failedKeys = [];
        if ($expire == 0) {
            $this->redis->executeCommand('MSET', $args);
        } else {
            $expireMs = (int) ($expire * 1000);
            $this->redis->executeCommand('MULTI');
            $this->redis->executeCommand('MSET', $args);
            $index = [];
            foreach ($data as $key => $value) {
                $this->redis->executeCommand('PEXPIRE', [$key, $expireMs]);
                $index[] = $key;
            }
            $result = $this->redis->executeCommand('EXEC');
            if (is_array($result)) {
                array_shift($result);
                foreach ($result as $i => $r) {
                    if ($r != 1 && isset($index[$i])) {
                        $failedKeys[] = $index[$i];
                    }
                }
            }
        }

        $state['hashTagAvailable'] = false;
        $this->setState($state);

        return $failedKeys;
    }

    /**
     * @return array{name:string,capacity:int,min_idle:int,created:int,idle:int,in_use:int,waiters:int,closed:bool}
     */
    public function getPoolStats(): array
    {
        return $this->redis->getPoolStats();
    }

    /**
     * @return array{replica:?Connection,hashTagAvailable:bool}
     */
    private function getState(): array
    {
        if (Coroutine::getCid() < 0) {
            return $this->fallbackState;
        }

        $context = Coroutine::getContext();
        $id = spl_object_id($this);

        return $context[self::CONTEXT_STATE_KEY][$id] ?? [
            'replica' => null,
            'hashTagAvailable' => false,
        ];
    }

    /**
     * @param array{replica:?Connection,hashTagAvailable:bool} $state
     */
    private function setState(array $state): void
    {
        if (Coroutine::getCid() < 0) {
            $this->fallbackState = $state;
            return;
        }

        $context = Coroutine::getContext();
        $states = $context[self::CONTEXT_STATE_KEY] ?? [];
        $states[spl_object_id($this)] = $state;
        $context[self::CONTEXT_STATE_KEY] = $states;
    }
}
