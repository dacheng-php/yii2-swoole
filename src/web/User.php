<?php

declare(strict_types=1);

namespace yii2swoole\web;

use yii2swoole\coroutine\ResettableInterface;
use yii\web\User as BaseUser;

/**
 * Coroutine-safe Yii2 User component.
 *
 * Isolates both authentication identity and RBAC access check cache per request,
 * ensuring clean prototype cloning and deterministic reset without touching session writes.
 */
class User extends BaseUser implements ResettableInterface
{
    /**
     * Request-local RBAC permission check cache (replaces parent's private $_access).
     *
     * @var array<string, bool>
     */
    private array $accessCache = [];

    public function __clone()
    {
        parent::__clone();

        $this->accessCache = [];
    }

    public function can($permissionName, $params = [], $allowCaching = true): bool
    {
        if ($allowCaching && empty($params) && array_key_exists($permissionName, $this->accessCache)) {
            return $this->accessCache[$permissionName];
        }

        $accessChecker = $this->getAccessChecker();
        if ($accessChecker === null) {
            return false;
        }

        $access = (bool) $accessChecker->checkAccess($this->getId(), $permissionName, $params);
        if ($allowCaching && empty($params)) {
            $this->accessCache[$permissionName] = $access;
        }

        return $access;
    }

    public function setIdentity($identity): void
    {
        parent::setIdentity($identity);
        $this->accessCache = [];
    }

    public function reset(): void
    {
        $this->setIdentity(null);
        $this->accessCache = [];
    }
}
