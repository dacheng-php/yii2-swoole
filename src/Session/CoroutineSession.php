<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Session;

use Dacheng\Yii2\Swoole\Coroutine\ResettableInterface;
use Dacheng\Yii2\Swoole\Redis\CoroutineRedisConnection;
use Swoole\Coroutine;
use Yii;
use yii\base\InvalidConfigException;
use yii\di\Instance;
use yii\redis\Connection as RedisConnection;
use yii\redis\Session as YiiRedisSession;
use yii\web\Cookie;

class CoroutineSession extends YiiRedisSession implements ResettableInterface
{
    public bool $autoCloseOnCoroutineEnd = true;

    private bool $deferRegistered = false;

    private int $deferCoroutineId = -1;

    private ?string $_sessionId = null;

    public function init()
    {
        // Ensure redis is a valid Redis connection (accepts any yii\redis\Connection implementation)
        $this->redis = Instance::ensure($this->redis, RedisConnection::class);

        // Warn if running in coroutine context without CoroutineRedisConnection
        // This allows testing with mocks while encouraging proper coroutine-safe usage
        if (Coroutine::getCid() >= 0 && !$this->redis instanceof CoroutineRedisConnection) {
            Yii::warning(sprintf(
                '%s is running in coroutine context but redis is not %s (got %s). ' .
                'This may cause connection issues under high concurrency.',
                __CLASS__,
                CoroutineRedisConnection::class,
                get_class($this->redis)
            ), __METHOD__);
        }

        if ($this->keyPrefix === null) {
            $this->keyPrefix = substr(md5(Yii::$app->id), 0, 5);
        }

        parent::init();

        if ($this->getIsActive()) {
            Yii::warning('Session is already started', __METHOD__);
            $this->updateFlashCounters();
        }
    }

    public function open()
    {
        if ($this->getIsActive()) {
            return;
        }
        
        $this->isClosed = false;

        if ($this->autoCloseOnCoroutineEnd) {
            $this->registerCoroutineCloseHandler();
        }

        $this->ensureSessionId();

        // Load session data from Redis without calling parent::open()
        // to avoid session_set_save_handler() which fails after headers sent
        $data = $this->readSession($this->getId());

        // Unserialize session data with proper error handling
        if (!empty($data)) {
            $_SESSION = $this->unserializeSessionData($data, $this->getId());
        } else {
            $_SESSION = [];
        }
        
        $this->updateFlashCounters();
        $this->ensureResponseCarriesSessionCookie();
    }

    /**
     * @var bool Track whether session has been closed
     */
    private bool $isClosed = false;

    public function close()
    {
        // Prevent duplicate close() calls - early return pattern
        if ($this->isClosed) {
            return;
        }

        // Mark as closed FIRST to prevent race conditions
        // This ensures only one call can proceed to write session data
        $this->isClosed = true;

        // Save session data to Redis
        if (isset($_SESSION) && is_array($_SESSION) && !empty($_SESSION) && $this->_sessionId !== null) {
            try {
                // Serialize session data and write to Redis
                $data = serialize($_SESSION);
                $this->writeSession($this->getId(), $data);
            } catch (\Throwable $e) {
                \Yii::error('Failed to save session: ' . $e->getMessage(), __METHOD__);
            }
        }

        // Note: We don't close the Redis connection here because it's managed
        // by the connection pool. The connection will be returned to the pool
        // when the coroutine ends or when explicitly released by the application.

        if (isset($_SESSION) && is_array($_SESSION)) {
            $_SESSION = [];
        }

        $this->deferRegistered = false;
        $this->deferCoroutineId = -1;
    }

    public function destroy()
    {
        if (!$this->getIsActive()) {
            return;
        }

        // Remove session data from Redis
        if ($this->_sessionId !== null) {
            try {
                $sessionKey = $this->calculateKey($this->getId());
                $this->redis->del($sessionKey);
            } catch (\Throwable $e) {
                \Yii::error('Failed to destroy session: ' . $e->getMessage(), __METHOD__);
            }
        }

        // Clear session data
        if (isset($_SESSION) && is_array($_SESSION)) {
            $_SESSION = [];
        }

        // Reset session ID
        $this->_sessionId = null;
        $this->isClosed = true;
    }

    public function reset(): void
    {
        $this->close();
    }
    
    /**
     * Override setId to avoid calling session_id() which doesn't work after headers sent
     *
     * @param string $value The session ID to set
     */
    public function setId($value)
    {
        $this->_sessionId = $value;
    }

    /**
     * Override getId to avoid calling session_id() which doesn't work after headers sent
     *
     * @return string The current session ID
     */
    public function getId()
    {
        if ($this->_sessionId === null) {
            $this->_sessionId = $this->generateSecureSessionId();
        }
        return $this->_sessionId;
    }

    /**
     * Generates a cryptographically secure session ID.
     * Uses session_create_id() with proper validation and fallback.
     *
     * @return string A secure session ID
     */
    private function generateSecureSessionId(): string
    {
        try {
            $sessionId = session_create_id('');

            // Validate the generated session ID
            if ($this->isValidSessionId($sessionId)) {
                return $sessionId;
            }
        } catch (\Throwable $e) {
            \Yii::warning('Failed to generate session ID with session_create_id: ' . $e->getMessage(), __METHOD__);
        }

        // Fallback: generate a cryptographically secure random ID
        // Format: 32 hex chars (128 bits) + optional prefix
        $randomBytes = random_bytes(16);
        $sessionId = bin2hex($randomBytes);

        return $sessionId;
    }

    /**
     * Validates that a session ID meets security requirements.
     *
     * @param string $id The session ID to validate
     * @return bool True if valid, false otherwise
     */
    private function isValidSessionId(string $id): bool
    {
        // Session ID should be alphanumeric and between 20 and 128 characters
        $length = strlen($id);
        if ($length < 20 || $length > 128) {
            return false;
        }

        // Should only contain alphanumeric characters, dashes, and commas
        // This matches PHP's session.sid_allowed_characters: [a-zA-Z0-9,-]
        return preg_match('/^[a-zA-Z0-9,\-]+$/', $id) === 1;
    }
    
    /**
     * Override getIsActive to check our internal state instead of PHP session state
     */
    public function getIsActive()
    {
        return !$this->isClosed && $this->_sessionId !== null;
    }

    private function registerCoroutineCloseHandler(): void
    {
        $cid = Coroutine::getCid();
        
        if ($cid < 0) {
            return;
        }

        if ($this->deferRegistered && $this->deferCoroutineId === $cid) {
            return;
        }

        $this->deferRegistered = true;
        $this->deferCoroutineId = $cid;

        Coroutine::defer(function (): void {
            $this->deferRegistered = false;
            $this->deferCoroutineId = -1;

            if ($this->getIsActive()) {
                $this->close();
            }
        });
    }

    private function ensureSessionId(): void
    {
        $name = $this->getName();
        $request = Yii::$app->getRequest();
        $cookieId = $request->getCookies()->getValue($name, '');

        if ($cookieId !== '') {
            // Validate the cookie session ID before using it
            if ($this->isValidSessionId($cookieId)) {
                if (session_status() !== PHP_SESSION_ACTIVE && session_id() !== $cookieId) {
                    $this->setId($cookieId);
                }
                $this->setHasSessionId(true);
            } else {
                \Yii::warning('Invalid session ID from cookie, generating new one', __METHOD__);
                $this->setHasSessionId(false);
            }
            return;
        }

        if ($this->getHasSessionId()) {
            return;
        }

        // Generate new secure session ID using getId() which handles generation
        $newId = $this->generateSecureSessionId();
        if (session_status() !== PHP_SESSION_ACTIVE) {
            $this->setId($newId);
        }

        $this->setHasSessionId(false);
    }

    private function ensureResponseCarriesSessionCookie(): void
    {
        if ($this->getUseCookies() === false) {
            return;
        }

        $response = Yii::$app->getResponse();
        $cookies = $response->getCookies();
        $name = $this->getName();
        $currentId = $this->getId();

        $existing = $cookies->get($name, false);
        if ($existing instanceof Cookie && (string) $existing->value === (string) $currentId) {
            return;
        }

        $params = $this->getCookieParams();

        $cookies->add(new Cookie([
            'name' => $name,
            'value' => $currentId,
            'domain' => $params['domain'] ?? '',
            'path' => $params['path'] ?? '/',
            'httpOnly' => $params['httponly'] ?? true,
            'secure' => $params['secure'] ?? false,
            'sameSite' => $params['samesite'] ?? null,
            'expire' => $params['lifetime'] === 0 ? 0 : (time() + (int) $params['lifetime']),
        ]));
    }
}
