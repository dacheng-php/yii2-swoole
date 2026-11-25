<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Debug\Panels;

use yii\debug\models\UserSwitch;
use yii\debug\Panel;
use yii\debug\panels\UserPanel as BaseUserPanel;

/**
 * Coroutine-safe UserPanel that defers UserSwitch / filterModel resolution to request context
 * instead of evaluating Yii::$app->user->isGuest once during onWorkerStart bootstrap.
 */
class UserPanel extends BaseUserPanel
{
    public function init(): void
    {
        // Skip BaseUserPanel::init(): during onWorkerStart no HTTP request exists so $user->isGuest
        // is always true, and attaching AccessControl with a fixed mainUser to the shared Module
        // would leak identity across requests.
        Panel::init();
    }

    public function __clone()
    {
        parent::__clone();

        $this->userSwitch = null;
        if (is_object($this->filterModel)) {
            $this->filterModel = clone $this->filterModel;
        }
    }

    public function getSummary()
    {
        $this->ensureRequestHelpersInitialized();

        return parent::getSummary();
    }

    public function getUsersFilterModel()
    {
        $this->ensureRequestHelpersInitialized();

        return parent::getUsersFilterModel();
    }

    public function canSwitchUser(): bool
    {
        $user = $this->getUser();
        if ($user === null || $user->isGuest) {
            return false;
        }

        $this->ensureRequestHelpersInitialized();

        return parent::canSwitchUser();
    }

    public function canSearchUsers(): bool
    {
        $this->ensureRequestHelpersInitialized();

        return parent::canSearchUsers();
    }

    public function getDetail()
    {
        $this->ensureRequestHelpersInitialized();

        return parent::getDetail();
    }

    private function ensureRequestHelpersInitialized(): void
    {
        if ($this->userSwitch === null) {
            $this->userSwitch = new UserSwitch(['userComponent' => $this->userComponent]);
        }

        if ($this->filterModel === null) {
            $user = $this->getUser();
            if ($user !== null && $user->identityClass && is_subclass_of($user->identityClass, 'yii\db\ActiveRecord')) {
                $this->filterModel = new \yii\debug\models\search\User();
            }
        } elseif (
            is_string($this->filterModel)
            && class_exists($this->filterModel)
            && in_array('yii\debug\models\search\UserSearchInterface', class_implements($this->filterModel) ?: [], true)
        ) {
            $this->filterModel = new $this->filterModel();
        }
    }
}
