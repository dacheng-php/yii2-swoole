<?php

declare(strict_types=1);

namespace yii2swoole\debug\controllers;

use yii2swoole\debug\Module;
use yii\debug\controllers\UserController as BaseUserController;
use yii\debug\panels\UserPanel;
use yii\web\ForbiddenHttpException;

/**
 * Exposes /debug/user/* identity switching actions under the Swoole debug controllerNamespace,
 * enforcing UserPanel::$ruleUserSwitch per request instead of attaching a shared AccessControl
 * behavior with a fixed mainUser to the resident Module.
 */
class UserController extends BaseUserController
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $panel = $this->module instanceof Module
            ? $this->module->getCoroutinePanel('user')
            : ($this->module->panels['user'] ?? null);

        if (!$panel instanceof UserPanel || !$panel->canSwitchUser()) {
            throw new ForbiddenHttpException('You are not allowed to switch user identity.');
        }

        return true;
    }
}
