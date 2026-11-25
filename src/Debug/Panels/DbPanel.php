<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Debug\Panels;

use Dacheng\Yii2\Swoole\Debug\Actions\ExplainAction;
use yii\debug\panels\DbPanel as BaseDbPanel;

/**
 * Coroutine-safe DbPanel registering the coroutine-aware ExplainAction.
 *
 * Because Module clones Panel prototypes per coroutine, private caches ($_models, $_timings,
 * $_profileLogs) stay strictly isolated to each request/replay.
 */
class DbPanel extends BaseDbPanel
{
    public function init(): void
    {
        $this->actions['db-explain'] = [
            'class' => ExplainAction::class,
            'panel' => $this,
        ];
    }
}
