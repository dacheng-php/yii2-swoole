<?php

declare(strict_types=1);

namespace yii2swoole\debug\panels;

use yii2swoole\web\RequestContext;
use yii\debug\panels\ProfilingPanel as BaseProfilingPanel;
use yii\log\Logger;

/**
 * Coroutine-safe ProfilingPanel computing elapsed time from per-request RequestContext
 * rather than process-wide YII_BEGIN_TIME.
 */
class ProfilingPanel extends BaseProfilingPanel
{
    public function save(): array
    {
        $messages = $this->getLogMessages(Logger::LEVEL_PROFILE);
        $ctx = RequestContext::current();
        $startedAt = $ctx !== null ? $ctx->startedAt : YII_BEGIN_TIME;

        return [
            'memory' => memory_get_usage(true),
            'time' => max(0.000001, microtime(true) - $startedAt),
            'messages' => $messages,
        ];
    }
}
