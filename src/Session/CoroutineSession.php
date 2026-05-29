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

    private ?bool $hasSessionId = null;

    private bool $isClosed = false;

    /**
     * Coroutine-local session payload for the active request.
     *
     * This replaces the process-global $_SESSION superglobal, which is unsafe
     * under concurrent coroutine execution.
     *
     * @var array<string, mixed>
     */
    private array $sessionData = [];

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

        // Load session data from Redis without touching the global $_SESSION store.
        $data = $this->readSession($this->getId());

        $this->sessionData = $this->decodeSessionData($data);
        $this->updateFlashCounters();
        $this->ensureResponseCarriesSessionCookie();
    }

    public function close()
    {
        if ($this->isClosed) {
            return;
        }

        $this->isClosed = true;

        if ($this->_sessionId !== null) {
            try {
                $this->writeSession($this->getId(), $this->encodeSessionData());
            } catch (\Throwable $e) {
                \Yii::error('Failed to save session: ' . $e->getMessage(), __METHOD__);
            }
        }

        $this->sessionData = [];

        $this->deferRegistered = false;
        $this->deferCoroutineId = -1;
    }

    public function destroy()
    {
        if (!$this->getIsActive()) {
            return;
        }

        if ($this->_sessionId !== null) {
            try {
                $sessionKey = $this->calculateKey($this->getId());
                $this->redis->del($sessionKey);
            } catch (\Throwable $e) {
                \Yii::error('Failed to destroy session: ' . $e->getMessage(), __METHOD__);
            }
        }

        $this->sessionData = [];

        $this->_sessionId = null;
        $this->hasSessionId = false;
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

    public function getHasSessionId()
    {
        return $this->hasSessionId ?? false;
    }

    public function setHasSessionId($value)
    {
        $this->hasSessionId = (bool) $value;
    }

    #[\ReturnTypeWillChange]
    public function getIterator()
    {
        $this->open();

        return new \ArrayIterator($this->sessionData);
    }

    public function getCount()
    {
        $this->open();

        return count($this->sessionData);
    }

    #[\ReturnTypeWillChange]
    public function count()
    {
        return $this->getCount();
    }

    public function get($key, $defaultValue = null)
    {
        $this->open();

        return isset($this->sessionData[$key]) ? $this->sessionData[$key] : $defaultValue;
    }

    public function set($key, $value)
    {
        $this->open();
        $this->sessionData[$key] = $value;
    }

    public function remove($key)
    {
        $this->open();

        if (isset($this->sessionData[$key])) {
            $value = $this->sessionData[$key];
            unset($this->sessionData[$key]);

            return $value;
        }

        return null;
    }

    public function removeAll()
    {
        $this->open();

        foreach (array_keys($this->sessionData) as $key) {
            unset($this->sessionData[$key]);
        }
    }

    public function has($key)
    {
        $this->open();

        return isset($this->sessionData[$key]);
    }

    #[\ReturnTypeWillChange]
    public function offsetExists($offset)
    {
        return $this->has($offset);
    }

    #[\ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        return $this->get($offset);
    }

    #[\ReturnTypeWillChange]
    public function offsetSet($offset, $item)
    {
        $this->set($offset, $item);
    }

    #[\ReturnTypeWillChange]
    public function offsetUnset($offset)
    {
        $this->remove($offset);
    }

    public function getFlash($key, $defaultValue = null, $delete = false)
    {
        $counters = $this->getFlashCounters();
        if (isset($counters[$key])) {
            $value = $this->get($key, $defaultValue);
            if ($delete) {
                $this->removeFlash($key);
            } elseif ($counters[$key] < 0) {
                $counters[$key] = 1;
                $this->setFlashCounters($counters);
            }

            return $value;
        }

        return $defaultValue;
    }

    public function getAllFlashes($delete = false)
    {
        $counters = $this->getFlashCounters();
        $flashes = [];

        foreach (array_keys($counters) as $key) {
            if (array_key_exists($key, $this->sessionData)) {
                $flashes[$key] = $this->sessionData[$key];
                if ($delete) {
                    unset($counters[$key], $this->sessionData[$key]);
                } elseif ($counters[$key] < 0) {
                    $counters[$key] = 1;
                }
            } else {
                unset($counters[$key]);
            }
        }

        $this->setFlashCounters($counters);

        return $flashes;
    }

    public function setFlash($key, $value = true, $removeAfterAccess = true)
    {
        $counters = $this->getFlashCounters();
        $counters[$key] = $removeAfterAccess ? -1 : 0;
        $this->sessionData[$key] = $value;
        $this->setFlashCounters($counters);
    }

    public function addFlash($key, $value = true, $removeAfterAccess = true)
    {
        $counters = $this->getFlashCounters();
        $counters[$key] = $removeAfterAccess ? -1 : 0;
        $this->setFlashCounters($counters);

        if (empty($this->sessionData[$key])) {
            $this->sessionData[$key] = [$value];
        } elseif (is_array($this->sessionData[$key])) {
            $this->sessionData[$key][] = $value;
        } else {
            $this->sessionData[$key] = [$this->sessionData[$key], $value];
        }
    }

    public function removeFlash($key)
    {
        $counters = $this->getFlashCounters();
        $value = isset($this->sessionData[$key], $counters[$key]) ? $this->sessionData[$key] : null;
        unset($counters[$key], $this->sessionData[$key]);
        $this->setFlashCounters($counters);

        return $value;
    }

    public function removeAllFlashes()
    {
        $counters = $this->getFlashCounters();
        foreach (array_keys($counters) as $key) {
            unset($this->sessionData[$key]);
        }
        unset($this->sessionData[$this->flashParam]);
    }

    protected function updateFlashCounters()
    {
        $counters = $this->getFlashCounters();
        if (is_array($counters)) {
            foreach ($counters as $key => $count) {
                if ($count > 0) {
                    unset($counters[$key], $this->sessionData[$key]);
                } elseif ($count == 0) {
                    $counters[$key]++;
                }
            }
            $this->sessionData[$this->flashParam] = $counters;

            return;
        }

        unset($this->sessionData[$this->flashParam]);
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
            if ($this->isValidSessionId($cookieId)) {
                if ($this->_sessionId !== $cookieId) {
                    $this->setId($cookieId);
                }
                $this->setHasSessionId(true);
            } else {
                \Yii::warning('Invalid session ID from cookie, generating new one', __METHOD__);
                $this->_sessionId = null;
                $this->setHasSessionId(false);
            }
            return;
        }

        if ($this->_sessionId !== null) {
            return;
        }

        $newId = $this->generateSecureSessionId();
        $this->setId($newId);

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

    /**
     * @return array<string, mixed>
     */
    private function decodeSessionData(string|false|null $data): array
    {
        if ($data === '' || $data === false || $data === null) {
            return [];
        }

        $decoded = @unserialize($data, ['allowed_classes' => true]);
        if (is_array($decoded)) {
            return $decoded;
        }

        \Yii::warning('Invalid session payload received from Redis; resetting to an empty session.', __METHOD__);

        return [];
    }

    private function encodeSessionData(): string
    {
        return serialize($this->sessionData);
    }

    /**
     * @return array<string, int>
     */
    private function getFlashCounters(): array
    {
        $counters = $this->get($this->flashParam, []);

        return is_array($counters) ? $counters : [];
    }

    /**
     * @param array<string, int> $counters
     */
    private function setFlashCounters(array $counters): void
    {
        $this->sessionData[$this->flashParam] = $counters;
    }
}
