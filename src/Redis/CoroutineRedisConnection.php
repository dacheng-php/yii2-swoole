<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Redis;

use Dacheng\Yii2\Swoole\Pools\PoolManager;
use Dacheng\Yii2\Swoole\Pools\RedisConnectionPool;
use Swoole\Coroutine;
use yii\redis\Connection as BaseRedisConnection;
use yii\redis\Exception;
use yii\redis\SocketException;

/**
 * CoroutineRedisConnection provides connection pooling for Redis in Swoole coroutine context.
 *
 * This class extends yii2-redis Connection and manages a pool of socket connections that are
 * shared across coroutines. Unlike the previous implementation that used reflection to inject
 * sockets into the parent's private _pool array, this implementation manages sockets directly
 * by overriding getSocket() and related methods.
 *
 * Key design decisions:
 * 1. Override getSocket() to return our managed socket - all parent methods use $this->socket
 * 2. Override getIsActive() to check our managed socket state
 * 3. No reflection - direct socket management for better performance and maintainability
 * 4. AUTH/SELECT performed once during pool creation, not on every acquire
 *
 * @property-read RedisConnectionPool $pool The connection pool instance
 */
class CoroutineRedisConnection extends BaseRedisConnection
{
    use PoolManager;

    /**
     * Maximum number of connections in the pool.
     */
    public int $poolMaxActive = 20;

    /**
     * Maximum time to wait for an available connection (in seconds).
     */
    public float $poolWaitTimeout = 3.0;

    /**
     * Whether to enable connection pooling in coroutine context.
     */
    public bool $enableCoroutinePooling = true;

    /**
     * @var bool Whether to validate connections on acquire from pool
     * When enabled, the pool will verify connection health before returning it.
     */
    public bool $enableHealthCheck = false;

    /**
     * @var resource|null The current socket resource from the pool
     */
    private $currentSocket = null;

    /**
     * @var bool Whether the current socket encountered a failure during use
     */
    private bool $currentSocketFailed = false;

    /**
     * @var bool Whether the current socket has been released back to the pool
     */
    private bool $released = false;

    /**
     * Returns the socket connection resource.
     *
     * This method is called by parent class when accessing $this->socket.
     * By overriding this method, we can provide our own managed socket
     * without needing to inject into parent's private _pool array.
     *
     * @return resource|false The socket resource or false if not connected
     */
    public function getSocket()
    {
        if ($this->currentSocket !== null && !$this->released) {
            return $this->currentSocket;
        }

        return false;
    }

    /**
     * Returns whether the Redis connection is established.
     *
     * @return bool Whether the Redis connection is established
     */
    public function getIsActive(): bool
    {
        return $this->currentSocket !== null && !$this->released && !$this->currentSocketFailed;
    }

    /**
     * Opens the Redis connection.
     *
     * If pooling is enabled and we're in a coroutine context, acquires a socket from the pool.
     * Otherwise falls back to the parent implementation.
     */
    public function open(): void
    {
        if ($this->getSocket() !== false) {
            return;
        }

        if (!$this->enableCoroutinePooling || Coroutine::getCid() < 0) {
            parent::open();
            return;
        }

        // Acquire socket from coroutine pool
        $socket = $this->acquireSocket();
        $this->currentSocket = $socket;
        $this->currentSocketFailed = false;
        $this->released = false;

        // Trigger application-level init hook
        // Note: AUTH/SELECT already done during pool creation
        try {
            $this->initConnection();
        } catch (\Throwable $e) {
            $this->close();
            throw $e;
        }
    }

    /**
     * Closes the Redis connection.
     *
     * If pooling is enabled, returns the socket to the pool instead of closing it.
     */
    public function close(): void
    {
        if (!$this->enableCoroutinePooling || Coroutine::getCid() < 0) {
            parent::close();
            return;
        }

        if ($this->released || $this->currentSocket === null) {
            return;
        }

        $socket = $this->currentSocket;
        $failed = $this->currentSocketFailed;

        $this->currentSocket = null;
        $this->currentSocketFailed = false;
        $this->released = true;

        // Return to coroutine pool or discard on failure
        if ($failed) {
            $this->ensurePool()->discard($socket);
        } else {
            $this->releaseSocket($socket);
        }
    }

    /**
     * Resets the connection by closing it.
     */
    public function reset(): void
    {
        $this->close();
    }

    /**
     * Returns the connection pool instance.
     *
     * @return RedisConnectionPool
     */
    public function getPool(): RedisConnectionPool
    {
        return $this->ensurePool();
    }

    /**
     * Acquires a socket from the coroutine pool.
     *
     * @return resource
     */
    private function acquireSocket()
    {
        $pool = $this->ensurePool();
        return $pool->acquire();
    }

    /**
     * Returns a socket back to the coroutine pool.
     *
     * @param resource $socket
     */
    private function releaseSocket($socket): void
    {
        $this->ensurePool()->release($socket);
    }

    protected function buildPoolKey(): string
    {
        return md5(implode('|', [
            static::class,
            $this->hostname,
            $this->port,
            $this->unixSocket ?? '',
            $this->database ?? '',
            $this->username ?? '',
        ]));
    }

    protected function createPool(): RedisConnectionPool
    {
        return new RedisConnectionPool(
            fn() => $this->createSocketForPool(),
            $this->poolMaxActive,
            $this->poolWaitTimeout,
            $this->enableHealthCheck
        );
    }

    protected static function getPoolClass(): string
    {
        return RedisConnectionPool::class;
    }

    /**
     * Creates a new socket for the pool.
     * This is called by the pool when it needs to create new connections.
     *
     * IMPORTANT: AUTH and SELECT are performed HERE during socket creation,
     * not on every acquire. This is a critical performance optimization.
     *
     * @return resource
     * @throws SocketException If connection fails
     */
    private function createSocketForPool()
    {
        $connection = $this->connectionString . ', database=' . $this->database;
        \Yii::trace('Creating redis socket for pool: ' . $connection, __METHOD__);

        $socket = @stream_socket_client(
            $this->connectionString,
            $errorNumber,
            $errorDescription,
            $this->connectionTimeout ?: (float)ini_get('default_socket_timeout'),
            $this->socketClientFlags,
            stream_context_create($this->contextOptions)
        );

        if (!is_resource($socket)) {
            $message = YII_DEBUG
                ? "Failed to create redis socket ($connection): $errorNumber - $errorDescription"
                : 'Failed to create redis connection.';
            \Yii::error("Failed to create redis socket ($connection): $errorNumber - $errorDescription", __CLASS__);
            throw new SocketException($message, $errorNumber);
        }

        if ($this->dataTimeout !== null) {
            stream_set_timeout(
                $socket,
                $timeout = (int)$this->dataTimeout,
                (int)(($this->dataTimeout - $timeout) * 1_000_000)
            );
        }

        if ($this->useSSL) {
            stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        }

        // AUTH and SELECT run once per pooled socket, not on every acquire.
        $this->authenticateSocket($socket);

        return $socket;
    }

    /**
     * Authenticates a socket during creation.
     *
     * This method sends raw Redis commands directly to the socket without
     * relying on parent class methods. This avoids the need for reflection
     * while still performing authentication during pool creation.
     *
     * @param resource $socket The socket resource to authenticate
     * @return void
     * @throws SocketException If authentication fails
     */
    private function authenticateSocket($socket): void
    {
        if ($this->password !== null) {
            $params = array_filter([$this->username, $this->password]);
            $this->sendRawCommandToSocket($socket, 'AUTH', $params);
        }

        if ($this->database !== null) {
            $this->sendRawCommandToSocket($socket, 'SELECT', [$this->database]);
        }
    }

    /**
     * Sends a raw Redis command directly to a specific socket.
     *
     * This method is used during socket authentication when we need to send
     * commands to a newly created socket before it's set as the current socket.
     * It bypasses the parent class entirely, avoiding the need for reflection.
     *
     * @param resource $socket The socket to send the command to
     * @param string $name The command name
     * @param array $params The command parameters
     * @return mixed The command response
     * @throws SocketException If communication fails
     * @throws Exception If Redis returns an error
     */
    private function sendRawCommandToSocket($socket, string $name, array $params = [])
    {
        $params = array_merge(explode(' ', $name), $params);
        $command = '*' . count($params) . "\r\n";
        foreach ($params as $arg) {
            $arg = (string) ($arg ?? '');
            $command .= '$' . mb_strlen($arg, '8bit') . "\r\n" . $arg . "\r\n";
        }

        \Yii::trace("Executing Redis Command during auth: {$name}", __METHOD__);

        $written = @fwrite($socket, $command);
        if ($written === false) {
            throw new SocketException("Failed to write to socket during auth.\nRedis command was: " . $command);
        }
        if ($written !== ($len = mb_strlen($command, '8bit'))) {
            throw new SocketException("Failed to write to socket during auth. $written of $len bytes written.");
        }

        return $this->parseResponseFromSocket($socket, $params);
    }

    /**
     * Parses a Redis response from a specific socket.
     *
     * @param resource $socket The socket to read from
     * @param array $params The command parameters for error messages
     * @return mixed The parsed response
     * @throws SocketException If reading fails
     * @throws Exception If Redis returns an error
     */
    private function parseResponseFromSocket($socket, array $params)
    {
        if (($line = fgets($socket)) === false) {
            throw new SocketException("Failed to read from socket during auth.\nRedis command was: " . implode(' ', $params));
        }

        $type = $line[0];
        $line = mb_substr($line, 1, -2, '8bit');

        switch ($type) {
            case '+': // Status reply
                if ($line === 'OK' || $line === 'PONG') {
                    return true;
                }
                return $line;

            case '-': // Error reply
                throw new Exception("Redis error during auth: " . $line . "\nRedis command was: " . implode(' ', $params));

            case ':': // Integer reply
                return $line;

            case '$': // Bulk replies
                if ($line == '-1') {
                    return null;
                }
                $length = (int)$line + 2;
                $data = '';
                while ($length > 0) {
                    if (($block = fread($socket, $length)) === false) {
                        throw new SocketException("Failed to read from socket during auth.\nRedis command was: " . implode(' ', $params));
                    }
                    $data .= $block;
                    $length -= mb_strlen($block, '8bit');
                }
                return mb_substr($data, 0, -2, '8bit');

            case '*': // Multi-bulk replies
                $count = (int)$line;
                $data = [];
                for ($i = 0; $i < $count; $i++) {
                    $data[] = $this->parseResponseFromSocket($socket, $params);
                }
                return $data;

            default:
                throw new Exception('Received illegal data from redis during auth: ' . $line);
        }
    }

    /**
     * Marks the current socket as failed.
     * Called when a socket error occurs during command execution.
     */
    private function markCurrentSocketAsFailed(): void
    {
        if ($this->currentSocket !== null) {
            $this->currentSocketFailed = true;
        }
    }

    /**
     * Sends a raw command to the server.
     *
     * Overridden to capture socket errors and mark the socket as failed,
     * ensuring it gets discarded when returned to the pool.
     *
     * @param string $command The command string
     * @param array $params The command parameters
     * @return mixed The response
     * @throws SocketException If communication fails
     */
    protected function sendRawCommand($command, $params)
    {
        try {
            return parent::sendRawCommand($command, $params);
        } catch (SocketException $exception) {
            $this->markCurrentSocketAsFailed();
            throw $exception;
        }
    }
}
