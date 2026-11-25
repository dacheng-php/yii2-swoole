<?php

declare(strict_types=1);

namespace yii2swoole\debug\actions;

use yii2swoole\debug\Module;
use yii\debug\actions\db\ExplainAction as BaseExplainAction;
use yii\debug\panels\DbPanel;
use yii\web\HttpException;

/**
 * Coroutine-safe DB EXPLAIN action resolving the hydrated per-coroutine DbPanel
 * loaded by DefaultController::loadData() instead of the shared Module prototype.
 */
class ExplainAction extends BaseExplainAction
{
    public function run($seq, $tag)
    {
        $this->controller->loadData($tag);

        $panel = $this->panel;
        if ($this->controller->module instanceof Module) {
            $coroutinePanel = $this->controller->module->getCoroutinePanel('db');
            if ($coroutinePanel instanceof DbPanel) {
                $panel = $coroutinePanel;
            }
        }

        $timings = $panel->calculateTimings();
        if (!isset($timings[$seq])) {
            throw new HttpException(404, 'Log message not found.');
        }

        $query = $timings[$seq]['info'];
        $results = $panel->getDb()->createCommand('EXPLAIN ' . $query)->queryAll();
        if ($results === []) {
            return '<p>No EXPLAIN output returned.</p>';
        }

        $output = ['<table class="table"><thead><tr>' . implode(array_map(
            static fn ($key): string => '<th>' . htmlspecialchars((string) $key, ENT_QUOTES, 'UTF-8') . '</th>',
            array_keys($results[0])
        )) . '</tr></thead><tbody>'];

        foreach ($results as $result) {
            $output[] = '<tr>' . implode(array_map(
                static fn ($value): string => '<td>' . ($value === null || $value === '' ? 'NULL' : htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8')) . '</td>',
                $result
            )) . '</tr>';
        }
        $output[] = '</tbody></table>';

        return implode($output);
    }
}
