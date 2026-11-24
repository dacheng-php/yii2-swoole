<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Db;

use Dacheng\Yii2\Swoole\Pool\ConnectionPool;
use Dacheng\Yii2\Swoole\Pool\PoolAwareInterface;
use Dacheng\Yii2\Swoole\Pool\PoolConfig;
use Dacheng\Yii2\Swoole\Pool\PooledConnection;
use Dacheng\Yii2\Swoole\Pool\PoolRegistry;
use PDO;
use Swoole\Coroutine;
use Throwable;
use Yii;
use yii\base\InvalidConfigException;
use yii\caching\CacheInterface;
use yii\db\Connection as BaseConnection;
use yii\db\Transaction;

/**
 * Coroutine-safe, pooled Yii2 DB connection designed to be shared across all coroutines
 * inside a Swoole worker process.
 *
 * Guarantees:
 * - All mutable per-coroutine runtime state (PDO handle, pooled wrapper, active transaction,
 *   query cache stack) is isolated in Swoole coroutine context and released via Coroutine::defer().
 * - Connection-lost errors mark the underlying PDO for immediate discard via Db\Command,
 *   preventing broken connections from returning to the pool.
 * - EVENT_AFTER_OPEN fires strictly once when a new underlying PDO connection is created,
 *   not on every pool checkout.
 * - Single explicit $pool configuration (array|PoolConfig) without redundant flat pool* properties.
 */
class Connection extends BaseConnection implements PoolAwareInterface
{
    private const CONTEXT_STATE_KEY = '__yii2SwooleDbState';

    /** @var string */
    public $commandClass = Command::class;

    public bool $enableCoroutinePooling = true;

    /**
     * Explicit connection pool configuration (PoolConfig instance or array matching PoolConfig keys).
     *
     * @var array<string, mixed>|PoolConfig
     */
    public array|PoolConfig $pool = [];

    private ?PoolConfig $resolvedPoolConfig = null;

    private ?string $poolKey = null;

    /**
     * Fallback runtime state when running outside a Swoole coroutine.
     *
     * @var array{pdo:?PDO,pooled:?PooledConnection,transaction:?Transaction,queryCacheInfo:array<int,mixed>,failed:bool,deferRegistered:bool}
     */
    private array $fallbackState = [
        'pdo' => null,
        'pooled' => null,
        'transaction' => null,
        'queryCacheInfo' => [],
        'failed' => false,
        'deferRegistered' => false,
    ];

    public function init(): void
    {
        parent::init();

        if (!empty($this->masters) || !empty($this->slaves)) {
            throw new InvalidConfigException(
                static::class . ' does not support shared Yii2 masters/slaves replication properties under concurrency. '
                . 'Configure explicit separate DB components (e.g. "db" and "dbRead") instead.'
            );
        }

        $this->resolvedPoolConfig = PoolConfig::resolve($this->pool);
        unset($this->pdo);
    }

    public function __get($name)
    {
        if ($name === 'pdo') {
            return $this->getState()['pdo'];
        }

        return parent::__get($name);
    }

    public function __set($name, $value): void
    {
        if ($name === 'pdo') {
            $state = $this->getState();
            $state['pdo'] = $value;
            $this->setState($state);
            return;
        }

        parent::__set($name, $value);
    }

    public function __isset($name): bool
    {
        if ($name === 'pdo') {
            return $this->getState()['pdo'] !== null;
        }

        return parent::__isset($name);
    }

    public function __unset($name): void
    {
        if ($name === 'pdo') {
            $state = $this->getState();
            $state['pdo'] = null;
            $this->setState($state);
            return;
        }

        parent::__unset($name);
    }

    public function getIsActive(): bool
    {
        return $this->getState()['pdo'] !== null;
    }

    public function open(): void
    {
        $state = $this->getState();
        if ($state['pdo'] !== null) {
            return;
        }

        if (!$this->enableCoroutinePooling || Coroutine::getCid() < 0) {
            parent::open();
            return;
        }

        $pooled = $this->getPool()->acquire();
        $state['pooled'] = $pooled;
        $state['pdo'] = $pooled->connection;
        $state['failed'] = false;

        if (!$state['deferRegistered']) {
            $state['deferRegistered'] = true;
            $this->setState($state);
            Coroutine::defer(function (): void {
                $this->releaseCoroutineResources();
            });
        } else {
            $this->setState($state);
        }
    }

    public function close(): void
    {
        if (!$this->enableCoroutinePooling || Coroutine::getCid() < 0) {
            parent::close();
            return;
        }

        $this->releasePooledConnection();
    }

    public function markFailed(): void
    {
        $state = $this->getState();
        if ($state['pooled'] !== null) {
            $state['failed'] = true;
            $this->setState($state);
        }
    }

    public function getTransaction(): ?Transaction
    {
        $transaction = $this->getState()['transaction'];

        return $transaction instanceof Transaction && $transaction->getIsActive() ? $transaction : null;
    }

    public function beginTransaction($isolationLevel = null): Transaction
    {
        $this->open();

        if (($transaction = $this->getTransaction()) === null) {
            $transaction = new Transaction(['db' => $this]);
            $state = $this->getState();
            $state['transaction'] = $transaction;
            $this->setState($state);
        }

        $transaction->begin($isolationLevel);

        return $transaction;
    }

    public function transaction(callable $callback, $isolationLevel = null)
    {
        $transaction = $this->beginTransaction($isolationLevel);
        $level = $transaction->level;

        try {
            $result = $callback($this);
            if ($transaction->isActive && $transaction->level === $level) {
                $transaction->commit();
            }
        } catch (Throwable $e) {
            $this->rollbackTransactionOnLevel($transaction, $level);
            if (self::isConnectionLostError($e)) {
                $this->markFailed();
            }
            throw $e;
        }

        return $result;
    }

    public function cache(callable $callable, $duration = null, $dependency = null)
    {
        $state = $this->getState();
        $state['queryCacheInfo'][] = [$duration === null ? $this->queryCacheDuration : $duration, $dependency];
        $this->setState($state);

        try {
            return $callable($this);
        } finally {
            $this->popQueryCacheInfo();
        }
    }

    public function noCache(callable $callable)
    {
        $state = $this->getState();
        $state['queryCacheInfo'][] = false;
        $this->setState($state);

        try {
            return $callable($this);
        } finally {
            $this->popQueryCacheInfo();
        }
    }

    public function getQueryCacheInfo($duration, $dependency)
    {
        if (!$this->enableQueryCache) {
            return null;
        }

        $stack = $this->getState()['queryCacheInfo'];
        $info = end($stack);
        if (is_array($info)) {
            if ($duration === null) {
                $duration = $info[0];
            }
            if ($dependency === null) {
                $dependency = $info[1];
            }
        }

        if ($duration === 0 || $duration > 0) {
            $cache = is_string($this->queryCache) && Yii::$app
                ? Yii::$app->get($this->queryCache, false)
                : $this->queryCache;

            if ($cache instanceof CacheInterface) {
                return [$cache, $duration, $dependency];
            }
        }

        return null;
    }

    public function getPool(): ConnectionPool
    {
        $key = $this->getPoolKey();

        return PoolRegistry::getOrCreate($key, fn (): ConnectionPool => new ConnectionPool(
            name: $key,
            factory: fn (): PDO => $this->createPdoForPool(),
            closer: static function (mixed $pdo): void {
                unset($pdo);
            },
            pinger: static function (mixed $pdo): bool {
                if (!$pdo instanceof PDO) {
                    return false;
                }

                try {
                    return $pdo->query('SELECT 1') !== false;
                } catch (Throwable) {
                    return false;
                }
            },
            config: $this->resolvedPoolConfig ??= PoolConfig::resolve($this->pool)
        ));
    }

    /**
     * @return array{name:string,capacity:int,min_idle:int,created:int,idle:int,in_use:int,waiters:int,closed:bool}
     */
    public function getPoolStats(): array
    {
        return $this->getPool()->getStats();
    }

    public static function isConnectionLostError(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());
        foreach ([
            'server has gone away',
            'lost connection',
            'broken pipe',
            'error while sending',
            'connection reset by peer',
            'no connection to the server',
            'terminating connection',
            'server closed the connection unexpectedly',
            'packets out of order',
        ] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function releaseCoroutineResources(): void
    {
        $this->releasePooledConnection();

        if (Coroutine::getCid() >= 0) {
            $context = Coroutine::getContext();
            $id = spl_object_id($this);
            if (isset($context[self::CONTEXT_STATE_KEY][$id])) {
                unset($context[self::CONTEXT_STATE_KEY][$id]);
            }
        }
    }

    private function releasePooledConnection(): void
    {
        $state = $this->getState();
        $pooled = $state['pooled'];
        $pdo = $state['pdo'];

        if ($pooled === null && $pdo === null) {
            return;
        }

        $discard = (bool) $state['failed'];
        $transaction = $state['transaction'];

        if ($transaction instanceof Transaction && $transaction->getIsActive()) {
            try {
                $transaction->rollBack();
                Yii::warning('Active DB transaction was automatically rolled back before returning connection to pool.', __CLASS__);
            } catch (Throwable $e) {
                $discard = true;
                Yii::error('Failed to roll back active DB transaction before returning connection: ' . $e->getMessage(), __CLASS__);
            }
        } elseif ($pdo instanceof PDO && $pdo->inTransaction()) {
            try {
                $pdo->rollBack();
                Yii::warning('Raw PDO transaction was automatically rolled back before returning connection to pool.', __CLASS__);
            } catch (Throwable $e) {
                $discard = true;
                Yii::error('Failed to roll back raw PDO transaction before returning connection: ' . $e->getMessage(), __CLASS__);
            }
        }

        $state['pdo'] = null;
        $state['pooled'] = null;
        $state['transaction'] = null;
        $state['queryCacheInfo'] = [];
        $state['failed'] = false;
        $this->setState($state);

        if ($pooled instanceof PooledConnection) {
            try {
                if ($discard) {
                    $this->getPool()->discard($pooled);
                } else {
                    $this->getPool()->release($pooled);
                }
            } catch (Throwable $e) {
                Yii::error('Error returning DB connection to pool: ' . $e->getMessage(), __CLASS__);
            }
        }
    }

    private function createPdoForPool(): PDO
    {
        $pdo = parent::createPdoInstance();
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($this->emulatePrepare !== null && $driver !== 'sqlsrv') {
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, $this->emulatePrepare);
        } elseif ($driver === 'mysql') {
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        }

        if ($driver === 'sqlite') {
            $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);
        }

        if ($this->charset !== null && stripos((string) $this->dsn, 'charset=') === false) {
            if (in_array($driver, ['mysql', 'pgsql', 'mysqli', 'cubrid'], true)) {
                $pdo->exec('SET NAMES ' . $pdo->quote($this->charset));
            }
        }

        // Fire EVENT_AFTER_OPEN once per newly established PDO connection (with $this->pdo temporarily bound)
        if ($this->hasEventHandlers(self::EVENT_AFTER_OPEN)) {
            $prevState = $this->getState();
            $tempState = $prevState;
            $tempState['pdo'] = $pdo;
            $this->setState($tempState);
            try {
                $this->trigger(self::EVENT_AFTER_OPEN);
            } finally {
                $this->setState($prevState);
            }
        }

        return $pdo;
    }

    private function getPoolKey(): string
    {
        return $this->poolKey ??= 'db:' . implode('|', [
            static::class,
            (string) $this->dsn,
            (string) $this->username,
            (string) $this->charset,
        ]);
    }

    private function rollbackTransactionOnLevel(Transaction $transaction, int $level): void
    {
        if ($transaction->isActive && $transaction->level === $level) {
            try {
                $transaction->rollBack();
            } catch (Throwable $e) {
                $this->markFailed();
                Yii::error($e, __METHOD__);
            }
        }
    }

    private function popQueryCacheInfo(): void
    {
        $state = $this->getState();
        array_pop($state['queryCacheInfo']);
        $this->setState($state);
    }

    /**
     * @return array{pdo:?PDO,pooled:?PooledConnection,transaction:?Transaction,queryCacheInfo:array<int,mixed>,failed:bool,deferRegistered:bool}
     */
    private function getState(): array
    {
        if (Coroutine::getCid() < 0) {
            return $this->fallbackState;
        }

        $context = Coroutine::getContext();
        $id = spl_object_id($this);

        return $context[self::CONTEXT_STATE_KEY][$id] ?? $this->defaultState();
    }

    /**
     * @param array{pdo:?PDO,pooled:?PooledConnection,transaction:?Transaction,queryCacheInfo:array<int,mixed>,failed:bool,deferRegistered:bool} $state
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

    /**
     * @return array{pdo:?PDO,pooled:?PooledConnection,transaction:?Transaction,queryCacheInfo:array<int,mixed>,failed:bool,deferRegistered:bool}
     */
    private function defaultState(): array
    {
        return [
            'pdo' => null,
            'pooled' => null,
            'transaction' => null,
            'queryCacheInfo' => [],
            'failed' => false,
            'deferRegistered' => false,
        ];
    }
}
