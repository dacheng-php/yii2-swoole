<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Debug\Panels;

use Dacheng\Yii2\Swoole\Debug\Module;
use Dacheng\Yii2\Swoole\Web\RequestContext;
use Yii;
use yii\debug\panels\TimelinePanel as BaseTimelinePanel;

/**
 * Coroutine-safe TimelinePanel anchored on per-request RequestContext::startedAt
 * and reading profiling timings from the coroutine-cloned ProfilingPanel.
 */
class TimelinePanel extends BaseTimelinePanel
{
    /** @var array<int, mixed>|null */
    private ?array $coroutineModels = null;

    private float $coroutineStart = 0.0;

    private float $coroutineEnd = 0.0;

    private float $coroutineDuration = 0.001;

    private int $coroutineMemory = 1;

    public function save(): array
    {
        $ctx = RequestContext::current();
        $startedAt = $ctx !== null ? $ctx->startedAt : YII_BEGIN_TIME;
        $endedAt = microtime(true);
        if ($endedAt <= $startedAt) {
            $endedAt = $startedAt + 0.000001;
        }

        return [
            'start' => $startedAt,
            'end' => $endedAt,
            'memory' => memory_get_usage(true),
        ];
    }

    public function load($data): void
    {
        $this->data = $data;
        $this->coroutineModels = null;

        if (!isset($data['start']) || empty($data['start'])) {
            throw new \RuntimeException('Unable to determine request start time');
        }
        $this->coroutineStart = (float) $data['start'] * 1000.0;

        if (!isset($data['end']) || empty($data['end'])) {
            throw new \RuntimeException('Unable to determine request end time');
        }
        $this->coroutineEnd = (float) $data['end'] * 1000.0;

        $profilingPanel = $this->resolveProfilingPanel();
        if ($profilingPanel !== null && isset($profilingPanel->data['time'])) {
            $this->coroutineDuration = max(0.001, (float) $profilingPanel->data['time'] * 1000.0);
        } else {
            $this->coroutineDuration = max(0.001, $this->coroutineEnd - $this->coroutineStart);
        }

        $this->coroutineMemory = max(1, (int) ($data['memory'] ?? memory_get_usage(true)));
    }

    public function getStart(): float
    {
        return $this->coroutineStart;
    }

    public function getDuration(): float
    {
        return $this->coroutineDuration;
    }

    public function getMemory(): int
    {
        return $this->coroutineMemory;
    }

    protected function getModels($refresh = false): array
    {
        if ($this->coroutineModels === null || $refresh) {
            $this->coroutineModels = [];
            $profilingPanel = $this->resolveProfilingPanel();
            if ($profilingPanel !== null && isset($profilingPanel->data['messages'])) {
                $this->coroutineModels = Yii::getLogger()->calculateTimings($profilingPanel->data['messages']);
            }
        }

        return $this->coroutineModels;
    }

    private function resolveProfilingPanel(): ?\yii\debug\Panel
    {
        if ($this->module instanceof Module) {
            return $this->module->getCoroutinePanel('profiling');
        }

        return $this->module->panels['profiling'] ?? null;
    }
}
