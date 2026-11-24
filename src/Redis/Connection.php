<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Redis;

use Dacheng\Yii2\Swoole\Pool\ConnectionPool;
use Dacheng\Yii2\Swoole\Pool\PoolAwareInterface;
use Dacheng\Yii2\Swoole\Pool\PoolConfig;
use Dacheng\Yii2\Swoole\Pool\PooledConnection;
use Dacheng\Yii2\Swoole\Pool\PoolRegistry;
use Swoole\Coroutine;
use Throwable;
use Yii;
use yii\db\Exception;
use yii\redis\Connection as BaseRedisConnection;
use yii\redis\SocketException;

/**
 * Coroutine-safe, pooled Yii2 Redis connection designed to be shared across all coroutines
 * inside a Swoole worker process.
 *
 * Guarantees:
 * - Zero shared-property mutation during retries (eliminates parent::$retries concurrency race).
 * - Explicit borrow semantics: single commands acquire & immediately release back to the LIFO pool;
 *   explicit open() or sticky commands (MULTI/WATCH/SUBSCRIBE) pin the socket until close() or
 *   sticky termination, with Coroutine::defer() guaranteed cleanup on coroutine exit.
 * - EVENT_AFTER_OPEN fires strictly once when a new underlying socket is established.
 * - Single explicit $pool configuration (array|PoolConfig) without redundant flat pool* properties.
 */
class Connection extends BaseRedisConnection implements PoolAwareInterface
{
    private const CONTEXT_STATE_KEY = '__yii2SwooleRedisState';

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
     * Fallback state outside Swoole coroutine context.
     *
     * @var array{socket:mixed,pooled:?PooledConnection,failed:bool,explicitOpen:bool,sticky:bool,dirtySession:bool,deferRegistered:bool}
     */
    private array $fallbackState = [
        'socket' => null,
        'pooled' => null,
        'failed' => false,
        'explicitOpen' => false,
        'sticky' => false,
        'dirtySession' => false,
        'deferRegistered' => false,
    ];

    /**
     * Redis commands that establish connection-scoped state requiring the same socket
     * across subsequent commands until explicitly ended or discarded.
     *
     * @var array<string, true>
     */
    private const STICKY_START_COMMANDS = [
        'MULTI' => true,
        'WATCH' => true,
        'SUBSCRIBE' => true,
        'PSUBSCRIBE' => true,
        'SSUBSCRIBE' => true,
        'MONITOR' => true,
    ];

    /**
     * Redis commands that terminate a transaction/watch sticky session.
     *
     * @var array<string, true>
     */
    private const STICKY_END_COMMANDS = [
        'EXEC' => true,
        'DISCARD' => true,
        'UNWATCH' => true,
    ];

    /**
     * Redis commands that mutate connection-level state; socket must be discarded rather than reused.
     *
     * @var array<string, true>
     */
    private const SESSION_MUTATING_COMMANDS = [
        'SELECT' => true,
        'AUTH' => true,
        'CLIENT' => true,
    ];

    public function init(): void
    {
        parent::init();
        $this->resolvedPoolConfig = PoolConfig::resolve($this->pool);
    }

    public function executeCommand($name, $params = [])
    {
        if (!$this->enableCoroutinePooling || Coroutine::getCid() < 0) {
            return parent::executeCommand($name, $params);
        }

        $commandName = strtoupper(trim((string) $name));
        $primary = explode(' ', $commandName, 2)[0];

        $isStickyStart = isset(self::STICKY_START_COMMANDS[$primary]);
        $isStickyEnd = isset(self::STICKY_END_COMMANDS[$primary]);
        $isSessionMutating = isset(self::SESSION_MUTATING_COMMANDS[$primary]);

        $state = $this->getState();
        if (!is_resource($state['socket']) || $state['failed']) {
            $state = $this->acquireForCoroutine(explicit: false);
        }

        if ($isStickyStart) {
            $state['sticky'] = true;
            $this->setState($state);
        } elseif ($isSessionMutating) {
            $state['dirtySession'] = true;
            $this->setState($state);
        }

        $frame = $this->encodeCommand($commandName, $params);
        Yii::debug("Executing Redis Command: {$commandName}", __METHOD__);

        // Only retry non-sticky commands on socket failure using a local counter (no shared $this->retries mutation)
        $remainingRetries = (!$state['sticky'] && !$isStickyStart && !$isStickyEnd && $this->retries > 0)
            ? (int) $this->retries
            : 0;

        try {
            while (true) {
                $currentState = $this->getState();
                $socket = $currentState['socket'];
                if (!is_resource($socket) || $currentState['failed']) {
                    $currentState = $this->acquireForCoroutine(explicit: $currentState['explicitOpen']);
                    $socket = $currentState['socket'];
                }

                try {
                    return $this->writeAndReadFrame($socket, $frame, $commandName);
                } catch (SocketException $e) {
                    $this->markFailed();
                    $this->releasePooledSocket();

                    if ($remainingRetries <= 0) {
                        throw $e;
                    }

                    $remainingRetries--;
                    Yii::warning("Retrying Redis command '{$commandName}' after socket error: {$e->getMessage()}", __METHOD__);
                    if ($this->retryInterval > 0) {
                        Coroutine::sleep($this->retryInterval / 1_000_000);
                    }
                }
            }
        } finally {
            $state = $this->getState();
            if ($isStickyEnd && $state['sticky']) {
                $state['sticky'] = false;
                $this->setState($state);
            }

            // Release non-explicit connections immediately once outside a sticky session
            if (!$state['explicitOpen'] && !$state['sticky']) {
                $this->releasePooledSocket();
            }
        }
    }

    /**
     * @return resource|false
     */
    public function getSocket()
    {
        if (!$this->enableCoroutinePooling || Coroutine::getCid() < 0) {
            return parent::getSocket();
        }

        $state = $this->getState();
        if (is_resource($state['socket']) && !$state['failed']) {
            return $state['socket'];
        }

        return false;
    }

    public function getIsActive(): bool
    {
        if (!$this->enableCoroutinePooling || Coroutine::getCid() < 0) {
            return parent::getIsActive();
        }

        $state = $this->getState();

        return is_resource($state['socket']) && !$state['failed'];
    }

    public function open(): void
    {
        if (!$this->enableCoroutinePooling || Coroutine::getCid() < 0) {
            parent::open();
            return;
        }

        $state = $this->getState();
        if (is_resource($state['socket']) && !$state['failed']) {
            if (!$state['explicitOpen']) {
                $state['explicitOpen'] = true;
                $this->setState($state);
            }
            return;
        }

        $this->acquireForCoroutine(explicit: true);
    }

    public function close(): void
    {
        if (!$this->enableCoroutinePooling || Coroutine::getCid() < 0) {
            parent::close();
            return;
        }

        $this->releasePooledSocket();
    }

    public function markFailed(): void
    {
        $state = $this->getState();
        if ($state['pooled'] !== null) {
            $state['failed'] = true;
            $this->setState($state);
        }
    }

    public function getPool(): ConnectionPool
    {
        $key = $this->getPoolKey();

        return PoolRegistry::getOrCreate($key, fn (): ConnectionPool => new ConnectionPool(
            name: $key,
            factory: fn () => $this->createSocketForPool(),
            closer: static function (mixed $socket): void {
                if (is_resource($socket)) {
                    @fclose($socket);
                }
            },
            pinger: fn (mixed $socket): bool => $this->pingSocket($socket),
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

    /**
     * @return array{socket:mixed,pooled:?PooledConnection,failed:bool,explicitOpen:bool,sticky:bool,dirtySession:bool,deferRegistered:bool}
     */
    private function acquireForCoroutine(bool $explicit): array
    {
        $state = $this->getState();
        $pooled = $this->getPool()->acquire();
        $state['pooled'] = $pooled;
        $state['socket'] = $pooled->connection;
        $state['failed'] = false;
        $state['explicitOpen'] = $explicit;

        if (!$state['deferRegistered']) {
            $state['deferRegistered'] = true;
            $this->setState($state);
            Coroutine::defer(function (): void {
                $this->releaseCoroutineResources();
            });
        } else {
            $this->setState($state);
        }

        return $state;
    }

    private function releaseCoroutineResources(): void
    {
        $this->releasePooledSocket();

        if (Coroutine::getCid() >= 0) {
            $context = Coroutine::getContext();
            $id = spl_object_id($this);
            if (isset($context[self::CONTEXT_STATE_KEY][$id])) {
                unset($context[self::CONTEXT_STATE_KEY][$id]);
            }
        }
    }

    private function releasePooledSocket(): void
    {
        $state = $this->getState();
        $pooled = $state['pooled'];

        if ($pooled === null) {
            return;
        }

        $discard = (bool) $state['failed']
            || (bool) $state['sticky']
            || (bool) $state['dirtySession']
            || !is_resource($state['socket']);

        $state['socket'] = null;
        $state['pooled'] = null;
        $state['failed'] = false;
        $state['explicitOpen'] = false;
        $state['sticky'] = false;
        $state['dirtySession'] = false;
        $this->setState($state);

        try {
            if ($discard) {
                $this->getPool()->discard($pooled);
            } else {
                $this->getPool()->release($pooled);
            }
        } catch (Throwable $e) {
            Yii::error('Error returning Redis socket to pool: ' . $e->getMessage(), __CLASS__);
        }
    }

    /**
     * @return resource
     */
    private function createSocketForPool()
    {
        $connection = $this->connectionString . ', database=' . $this->database;

        $socket = @stream_socket_client(
            $this->connectionString,
            $errorNumber,
            $errorDescription,
            $this->connectionTimeout ?: (float) ini_get('default_socket_timeout'),
            $this->socketClientFlags,
            stream_context_create($this->contextOptions)
        );

        if (!is_resource($socket)) {
            $message = (defined('YII_DEBUG') && YII_DEBUG)
                ? "Failed to create redis socket ($connection): $errorNumber - $errorDescription"
                : 'Failed to create redis connection.';
            Yii::error("Failed to create redis socket ($connection): $errorNumber - $errorDescription", __CLASS__);
            throw new SocketException($message, (int) $errorNumber);
        }

        if ($this->dataTimeout !== null) {
            $seconds = (int) $this->dataTimeout;
            $microseconds = (int) (($this->dataTimeout - $seconds) * 1_000_000);
            stream_set_timeout($socket, $seconds, $microseconds);
        }

        if ($this->useSSL) {
            if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                @fclose($socket);
                throw new SocketException("Failed to enable TLS on redis socket ($connection).");
            }
        }

        try {
            $this->authenticateSocket($socket);

            // Fire EVENT_AFTER_OPEN once per newly created socket
            if ($this->hasEventHandlers(self::EVENT_AFTER_OPEN)) {
                $prevState = $this->getState();
                $tempState = $prevState;
                $tempState['socket'] = $socket;
                $tempState['explicitOpen'] = true;
                $this->setState($tempState);
                try {
                    $this->initConnection();
                } finally {
                    $this->setState($prevState);
                }
            }
        } catch (Throwable $e) {
            @fclose($socket);
            throw $e;
        }

        return $socket;
    }

    /**
     * @param resource $socket
     */
    private function authenticateSocket($socket): void
    {
        if ($this->password !== null && $this->password !== '') {
            $params = $this->username !== null && $this->username !== ''
                ? [$this->username, $this->password]
                : [$this->password];
            $this->writeAndReadFrame($socket, $this->encodeCommand('AUTH', $params), 'AUTH');
        }

        if ($this->database !== null) {
            $this->writeAndReadFrame($socket, $this->encodeCommand('SELECT', [(string) $this->database]), 'SELECT');
        }
    }

    private function pingSocket(mixed $socket): bool
    {
        if (!is_resource($socket) || get_resource_type($socket) !== 'stream') {
            return false;
        }

        $meta = @stream_get_meta_data($socket);
        if (!is_array($meta) || ($meta['eof'] ?? false)) {
            return false;
        }

        try {
            $reply = $this->writeAndReadFrame($socket, "*1\r\n\$4\r\nPING\r\n", 'PING');

            return $reply === true || $reply === 'PONG';
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param array<int, mixed> $params
     */
    private function encodeCommand(string $name, array $params = []): string
    {
        $args = array_merge(explode(' ', $name), $params);
        $command = '*' . count($args) . "\r\n";
        foreach ($args as $arg) {
            $arg = (string) ($arg ?? '');
            $command .= '$' . strlen($arg) . "\r\n" . $arg . "\r\n";
        }

        return $command;
    }

    /**
     * @param resource $socket
     */
    private function writeAndReadFrame($socket, string $frame, string $commandName): mixed
    {
        $totalLen = strlen($frame);
        $written = 0;
        while ($written < $totalLen) {
            $chunk = $written === 0 ? $frame : substr($frame, $written);
            $bytes = @fwrite($socket, $chunk);
            if ($bytes === false || $bytes === 0) {
                throw new SocketException("Failed to write Redis command '{$commandName}' to socket.");
            }
            $written += $bytes;
        }

        return $this->parseResponseFromSocket($socket, $commandName);
    }

    /**
     * @param resource $socket
     */
    private function parseResponseFromSocket($socket, string $commandName): mixed
    {
        $line = @fgets($socket);
        if ($line === false || $line === '') {
            throw new SocketException("Failed to read Redis response for '{$commandName}'.");
        }

        $type = $line[0];
        $payload = substr($line, 1, -2);

        return match ($type) {
            '+' => ($payload === 'OK' || $payload === 'PONG') ? true : $payload,
            '-' => throw new Exception("Redis error for '{$commandName}': {$payload}"),
            ':' => $payload,
            '$' => $this->readBulkReply($socket, (int) $payload, $commandName),
            '*' => $this->readMultiBulkReply($socket, (int) $payload, $commandName),
            default => throw new Exception("Unexpected Redis response prefix '{$type}' for '{$commandName}'."),
        };
    }

    /**
     * @param resource $socket
     */
    private function readBulkReply($socket, int $length, string $commandName): ?string
    {
        if ($length === -1) {
            return null;
        }

        $remaining = $length + 2;
        $data = '';
        while ($remaining > 0) {
            $block = @fread($socket, $remaining);
            if ($block === false || $block === '') {
                throw new SocketException("Failed to read bulk reply for '{$commandName}'.");
            }
            $data .= $block;
            $remaining -= strlen($block);
        }

        return substr($data, 0, -2);
    }

    /**
     * @param resource $socket
     * @return array<int, mixed>|null
     */
    private function readMultiBulkReply($socket, int $count, string $commandName): ?array
    {
        if ($count === -1) {
            return null;
        }

        $data = [];
        for ($i = 0; $i < $count; $i++) {
            $data[] = $this->parseResponseFromSocket($socket, $commandName);
        }

        return $data;
    }

    private function getPoolKey(): string
    {
        $authHash = ($this->password !== null && $this->password !== '')
            ? substr(hash('sha256', (string) $this->password), 0, 12)
            : '';

        return $this->poolKey ??= 'redis:' . implode('|', [
            static::class,
            (string) $this->hostname,
            (string) $this->port,
            (string) ($this->unixSocket ?? ''),
            (string) ($this->database ?? ''),
            (string) ($this->username ?? ''),
            $authHash,
            $this->useSSL ? 'tls' : 'tcp',
        ]);
    }

    /**
     * @return array{socket:mixed,pooled:?PooledConnection,failed:bool,explicitOpen:bool,sticky:bool,dirtySession:bool,deferRegistered:bool}
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
     * @param array{socket:mixed,pooled:?PooledConnection,failed:bool,explicitOpen:bool,sticky:bool,dirtySession:bool,deferRegistered:bool} $state
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
     * @return array{socket:mixed,pooled:?PooledConnection,failed:bool,explicitOpen:bool,sticky:bool,dirtySession:bool,deferRegistered:bool}
     */
    private function defaultState(): array
    {
        return [
            'socket' => null,
            'pooled' => null,
            'failed' => false,
            'explicitOpen' => false,
            'sticky' => false,
            'dirtySession' => false,
            'deferRegistered' => false,
        ];
    }
}
