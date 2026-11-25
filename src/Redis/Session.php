<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Redis;

use ArrayIterator;
use Dacheng\Yii2\Swoole\Coroutine\ResettableInterface;
use Dacheng\Yii2\Swoole\Coroutine\Telemetry;
use Swoole\Coroutine;
use Throwable;
use Yii;
use yii\di\Instance;
use yii\redis\Connection as YiiRedisConnection;
use yii\redis\Session as BaseSession;
use yii\web\Cookie;

/**
 * Coroutine-safe Redis Session without touching PHP's global $_SESSION or session_* SAPI functions.
 *
 * Enhancements:
 * - Dirty tracking + optional lightweight EXPIRE TTL refresh on read-only requests.
 * - Safe unserialize() default ($allowedClasses = false) preventing PHP Object Injection.
 * - Full coroutine-safe regenerateID() and destroy() cookie expiration support.
 * - Safe prototype cloning via __clone() for zero-reflection per-request isolation.
 */
class Session extends BaseSession implements ResettableInterface
{
    public bool $autoCloseOnCoroutineEnd = true;

    /**
     * When true, only writes session payload back to Redis if modified or newly created.
     */
    public bool $writeOnlyWhenDirty = true;

    /**
     * When true and $writeOnlyWhenDirty skips a full write-back on read-only requests,
     * sends a lightweight Redis EXPIRE to preserve sliding session expiration.
     */
    public bool $touchTtlOnRead = true;

    /**
     * Controls PHP unserialize() allowed_classes option for session payloads.
     * Defaults to false to prevent PHP Object Injection; pass an explicit class list
     * or true if your application stores serialized objects in session.
     *
     * @var bool|class-string[]
     */
    public bool|array $allowedClasses = false;

    private ?string $sessionId = null;

    private ?bool $hasSessionIdFlag = null;

    private bool $active = false;

    private bool $dirty = false;

    private bool $deferRegistered = false;

    /**
     * @var array<string, mixed>
     */
    private array $sessionData = [];

    public function __clone()
    {
        parent::__clone();

        $this->sessionId = null;
        $this->hasSessionIdFlag = null;
        $this->active = false;
        $this->dirty = false;
        $this->deferRegistered = false;
        $this->sessionData = [];
    }

    public function init(): void
    {
        $this->redis = Instance::ensure($this->redis, YiiRedisConnection::class);

        if (Coroutine::getCid() >= 0 && !$this->redis instanceof Connection) {
            Yii::warning(sprintf(
                '%s is running in coroutine context with %s; configure %s for pooled non-blocking I/O.',
                static::class,
                get_class($this->redis),
                Connection::class
            ), __METHOD__);
        }

        if ($this->keyPrefix === null) {
            $this->keyPrefix = substr(md5(Yii::$app->id), 0, 5);
        }

        // Intentionally skip yii\web\Session::init() register_shutdown_function() registration.
        \yii\base\Component::init();
    }

    public function open(): void
    {
        if ($this->active) {
            return;
        }

        $this->ensureSessionId();
        $rawData = $this->readSession($this->getId());
        $this->sessionData = $this->decodeSessionData($rawData);
        $this->active = true;

        // Newly generated sessions or flash counter transitions mark session dirty.
        if (!$this->hasSessionIdFlag) {
            $this->dirty = true;
        }

        $this->updateFlashCounters();
        $this->ensureResponseCarriesSessionCookie();

        if ($this->autoCloseOnCoroutineEnd) {
            $this->registerCoroutineCloseHandler();
        }
    }

    public function close(): void
    {
        if (!$this->active) {
            return;
        }

        $this->active = false;

        $mode = 'noop';
        $wasDirty = $this->dirty;
        $keyCount = count($this->sessionData);

        if ($this->sessionId !== null) {
            if (!$this->writeOnlyWhenDirty || $this->dirty) {
                $mode = 'write';
                try {
                    $this->writeSession($this->sessionId, $this->encodeSessionData());
                } catch (Throwable $e) {
                    Yii::error('Failed to save session: ' . $e->getMessage(), __METHOD__);
                }
            } elseif ($this->touchTtlOnRead && $this->hasSessionIdFlag && $this->sessionData !== []) {
                $mode = 'expire';
                try {
                    $this->redis->executeCommand('EXPIRE', [
                        $this->calculateKey($this->sessionId),
                        $this->getTimeout(),
                    ]);
                } catch (Throwable $e) {
                    Yii::warning('Failed to refresh session TTL: ' . $e->getMessage(), __METHOD__);
                }
            }
        }

        if (Telemetry::isEnabled()) {
            Telemetry::recordSessionActivity($mode, $this->sessionId, $wasDirty, $keyCount);
        }

        $this->sessionData = [];
        $this->dirty = false;
        $this->deferRegistered = false;
    }

    public function destroy(): void
    {
        if (!$this->active && $this->sessionId === null) {
            return;
        }

        $destroyedId = $this->sessionId;
        if ($this->sessionId !== null) {
            try {
                $this->destroySession($this->sessionId);
            } catch (Throwable $e) {
                Yii::error('Failed to destroy session: ' . $e->getMessage(), __METHOD__);
            }
        }

        if (Telemetry::isEnabled()) {
            Telemetry::recordSessionActivity('destroy', $destroyedId, true, 0);
        }

        $this->sessionData = [];
        $this->sessionId = null;
        $this->hasSessionIdFlag = false;
        $this->active = false;
        $this->dirty = false;

        if ($this->getUseCookies() !== false) {
            $params = $this->getCookieParams();
            Yii::$app->getResponse()->getCookies()->add(new Cookie([
                'name' => $this->getName(),
                'value' => '',
                'domain' => $params['domain'] ?? '',
                'path' => $params['path'] ?? '/',
                'httpOnly' => $params['httponly'] ?? true,
                'secure' => $params['secure'] ?? false,
                'sameSite' => $params['samesite'] ?? null,
                'expire' => time() - 3600,
            ]));
        }
    }

    public function regenerateID($deleteOldSession = false): void
    {
        $wasActive = $this->active;
        $oldId = $this->sessionId;

        if (!$wasActive) {
            $this->open();
            $oldId = $this->sessionId;
        }

        $newId = $this->generateSecureSessionId();
        $this->sessionId = $newId;
        $this->dirty = true;

        if ($deleteOldSession && $oldId !== null && $oldId !== $newId) {
            try {
                $this->destroySession($oldId);
            } catch (Throwable $e) {
                Yii::warning('Failed to delete old session during regenerateID: ' . $e->getMessage(), __METHOD__);
            }
        }

        $this->ensureResponseCarriesSessionCookie(true);
    }

    public function reset(): void
    {
        $this->close();
        $this->sessionId = null;
        $this->hasSessionIdFlag = null;
    }

    public function getIsActive(): bool
    {
        return $this->active;
    }

    /**
     * Returns an immutable snapshot of the current coroutine's in-memory session payload
     * without triggering open().
     *
     * @return array<string, mixed>
     */
    public function getSessionData(): array
    {
        return $this->sessionData;
    }

    /**
     * Returns lightweight read-only metadata for SwoolePanel inspection before session close().
     *
     * @return array{mode:string,session_id_masked:?string,dirty:bool,key_count:int}
     */
    public function getDebugInfo(): array
    {
        $maskedId = null;
        if ($this->sessionId !== null && $this->sessionId !== '') {
            $maskedId = strlen($this->sessionId) > 8
                ? substr($this->sessionId, 0, 4) . '…' . substr($this->sessionId, -4)
                : $this->sessionId;
        }

        $mode = 'inactive';
        if ($this->active) {
            if (!$this->writeOnlyWhenDirty || $this->dirty) {
                $mode = 'write (pending close)';
            } elseif ($this->touchTtlOnRead && $this->hasSessionIdFlag && $this->sessionData !== []) {
                $mode = 'expire (pending close)';
            } else {
                $mode = 'noop (pending close)';
            }
        }

        return [
            'mode' => $mode,
            'session_id_masked' => $maskedId,
            'dirty' => $this->dirty,
            'key_count' => count($this->sessionData),
        ];
    }

    public function getId(): string
    {
        if ($this->sessionId === null) {
            $this->sessionId = $this->generateSecureSessionId();
        }

        return $this->sessionId;
    }

    public function setId($value): void
    {
        $this->sessionId = (string) $value;
    }

    public function getHasSessionId(): bool
    {
        if ($this->hasSessionIdFlag === null) {
            $cookieId = Yii::$app->getRequest()->getCookies()->getValue($this->getName(), '');
            $this->hasSessionIdFlag = is_string($cookieId) && $cookieId !== '' && $this->isValidSessionId($cookieId);
        }

        return $this->hasSessionIdFlag;
    }

    public function setHasSessionId($value): void
    {
        $this->hasSessionIdFlag = (bool) $value;
    }

    #[\ReturnTypeWillChange]
    public function getIterator()
    {
        $this->open();

        return new ArrayIterator($this->sessionData);
    }

    public function getCount(): int
    {
        $this->open();

        return count($this->sessionData);
    }

    #[\ReturnTypeWillChange]
    public function count(): int
    {
        return $this->getCount();
    }

    public function get($key, $defaultValue = null)
    {
        $this->open();

        return array_key_exists($key, $this->sessionData) ? $this->sessionData[$key] : $defaultValue;
    }

    public function set($key, $value): void
    {
        $this->open();
        $this->sessionData[$key] = $value;
        $this->dirty = true;
    }

    public function remove($key)
    {
        $this->open();

        if (array_key_exists($key, $this->sessionData)) {
            $value = $this->sessionData[$key];
            unset($this->sessionData[$key]);
            $this->dirty = true;

            return $value;
        }

        return null;
    }

    public function removeAll(): void
    {
        $this->open();
        if ($this->sessionData !== []) {
            $this->sessionData = [];
            $this->dirty = true;
        }
    }

    public function has($key): bool
    {
        $this->open();

        return isset($this->sessionData[$key]);
    }

    #[\ReturnTypeWillChange]
    public function offsetExists($offset): bool
    {
        return $this->has($offset);
    }

    #[\ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        return $this->get($offset);
    }

    #[\ReturnTypeWillChange]
    public function offsetSet($offset, $item): void
    {
        $this->set($offset, $item);
    }

    #[\ReturnTypeWillChange]
    public function offsetUnset($offset): void
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

    public function getAllFlashes($delete = false): array
    {
        $counters = $this->getFlashCounters();
        $flashes = [];

        foreach (array_keys($counters) as $key) {
            if (array_key_exists($key, $this->sessionData)) {
                $flashes[$key] = $this->sessionData[$key];
                if ($delete) {
                    unset($counters[$key], $this->sessionData[$key]);
                    $this->dirty = true;
                } elseif ($counters[$key] < 0) {
                    $counters[$key] = 1;
                    $this->dirty = true;
                }
            } else {
                unset($counters[$key]);
                $this->dirty = true;
            }
        }

        if ($this->dirty) {
            $this->setFlashCounters($counters);
        }

        return $flashes;
    }

    public function setFlash($key, $value = true, $removeAfterAccess = true): void
    {
        $counters = $this->getFlashCounters();
        $counters[$key] = $removeAfterAccess ? -1 : 0;
        $this->sessionData[$key] = $value;
        $this->setFlashCounters($counters);
    }

    public function addFlash($key, $value = true, $removeAfterAccess = true): void
    {
        $counters = $this->getFlashCounters();
        $counters[$key] = $removeAfterAccess ? -1 : 0;

        if (empty($this->sessionData[$key])) {
            $this->sessionData[$key] = [$value];
        } elseif (is_array($this->sessionData[$key])) {
            $this->sessionData[$key][] = $value;
        } else {
            $this->sessionData[$key] = [$this->sessionData[$key], $value];
        }

        $this->setFlashCounters($counters);
    }

    public function removeFlash($key)
    {
        $counters = $this->getFlashCounters();
        $value = isset($this->sessionData[$key], $counters[$key]) ? $this->sessionData[$key] : null;
        unset($counters[$key], $this->sessionData[$key]);
        $this->setFlashCounters($counters);

        return $value;
    }

    public function removeAllFlashes(): void
    {
        $counters = $this->getFlashCounters();
        foreach (array_keys($counters) as $key) {
            unset($this->sessionData[$key]);
        }
        unset($this->sessionData[$this->flashParam]);
        $this->dirty = true;
    }

    protected function updateFlashCounters(): void
    {
        if (!array_key_exists($this->flashParam, $this->sessionData)) {
            return;
        }

        $counters = $this->sessionData[$this->flashParam];
        if (!is_array($counters)) {
            unset($this->sessionData[$this->flashParam]);
            $this->dirty = true;
            return;
        }

        $changed = false;
        foreach ($counters as $key => $count) {
            if ($count > 0) {
                unset($counters[$key], $this->sessionData[$key]);
                $changed = true;
            } elseif ($count == 0) {
                $counters[$key]++;
                $changed = true;
            }
        }

        if ($changed) {
            $this->sessionData[$this->flashParam] = $counters;
            $this->dirty = true;
        }
    }

    private function registerCoroutineCloseHandler(): void
    {
        if (Coroutine::getCid() < 0 || $this->deferRegistered) {
            return;
        }

        $this->deferRegistered = true;
        Coroutine::defer(function (): void {
            if ($this->active) {
                $this->close();
            }
        });
    }

    private function ensureSessionId(): void
    {
        $name = $this->getName();
        $cookieId = Yii::$app->getRequest()->getCookies()->getValue($name, '');

        if (is_string($cookieId) && $cookieId !== '') {
            if ($this->isValidSessionId($cookieId)) {
                $this->sessionId = $cookieId;
                $this->hasSessionIdFlag = true;
                return;
            }

            Yii::warning('Invalid session ID in request cookie; generating a new session ID.', __METHOD__);
        }

        if ($this->sessionId === null) {
            $this->sessionId = $this->generateSecureSessionId();
        }
        $this->hasSessionIdFlag = false;
    }

    private function ensureResponseCarriesSessionCookie(bool $force = false): void
    {
        if ($this->getUseCookies() === false) {
            return;
        }

        if (!$force && $this->hasSessionIdFlag) {
            return;
        }

        $cookies = Yii::$app->getResponse()->getCookies();
        $name = $this->getName();
        $currentId = $this->getId();

        $existing = $cookies->get($name, false);
        if (!$force && $existing instanceof Cookie && (string) $existing->value === $currentId) {
            return;
        }

        $params = $this->getCookieParams();
        $lifetime = (int) ($params['lifetime'] ?? 0);

        $cookies->add(new Cookie([
            'name' => $name,
            'value' => $currentId,
            'domain' => $params['domain'] ?? '',
            'path' => $params['path'] ?? '/',
            'httpOnly' => $params['httponly'] ?? true,
            'secure' => $params['secure'] ?? false,
            'sameSite' => $params['samesite'] ?? null,
            'expire' => $lifetime === 0 ? 0 : (time() + $lifetime),
        ]));
    }

    private function generateSecureSessionId(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function isValidSessionId(string $id): bool
    {
        $length = strlen($id);

        return $length >= 20 && $length <= 128 && preg_match('/^[a-zA-Z0-9,\-]+$/', $id) === 1;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeSessionData(string|false|null $data): array
    {
        if ($data === '' || $data === false || $data === null) {
            return [];
        }

        $decoded = @unserialize($data, ['allowed_classes' => $this->allowedClasses]);
        if (is_array($decoded)) {
            return $decoded;
        }

        Yii::warning('Corrupted session payload in Redis; resetting to an empty session.', __METHOD__);

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
        $this->dirty = true;
    }
}
