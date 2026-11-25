<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Debug\Panels;

use Dacheng\Yii2\Swoole\Debug\Module;
use Swoole\Coroutine;
use yii\base\Event;
use yii\debug\Panel;
use yii\debug\panels\EventPanel as BaseEventPanel;

/**
 * Coroutine-safe EventPanel storing captured Yii events inside Swoole coroutine context
 * with a per-request cap to prevent unbounded memory growth under resident workers.
 */
class EventPanel extends BaseEventPanel
{
    private const CONTEXT_KEY = '__yii2SwooleDebugEvents';

    /**
     * Maximum number of events captured per request to avoid excessive serialization overhead.
     */
    public int $maxEvents = 2000;

    /** @var array<int, array<string, mixed>> */
    private array $fallbackEvents = [];

    private bool $listenerAttached = false;

    public function init(): void
    {
        // Intentionally skip BaseEventPanel::init() to avoid registering its non-coroutine-safe
        // Event::on('*', '*') listener that appends endlessly to a shared $this->_events array.
        Panel::init();

        if ($this->listenerAttached) {
            return;
        }
        $this->listenerAttached = true;

        $module = $this->module;
        $maxEvents = $this->maxEvents;

        Event::on('*', '*', function ($event) use ($module, $maxEvents): void {
            if ($module instanceof Module && $module->isCurrentCoroutineMuted()) {
                return;
            }

            $eventData = [
                'time' => microtime(true),
                'name' => $event->name,
                'class' => get_class($event),
                'isStatic' => is_object($event->sender) ? '0' : '1',
                'senderClass' => is_object($event->sender) ? get_class($event->sender) : $event->sender,
            ];

            if (extension_loaded('swoole') && Coroutine::getCid() >= 0) {
                $context = Coroutine::getContext();
                $events = $context[self::CONTEXT_KEY] ?? [];
                if (count($events) < $maxEvents) {
                    $events[] = $eventData;
                    $context[self::CONTEXT_KEY] = $events;
                }
                return;
            }

            if (count($this->fallbackEvents) < $maxEvents) {
                $this->fallbackEvents[] = $eventData;
            }
        });
    }

    public function save(): array
    {
        if (extension_loaded('swoole') && Coroutine::getCid() >= 0) {
            $context = Coroutine::getContext();
            $events = $context[self::CONTEXT_KEY] ?? [];
            unset($context[self::CONTEXT_KEY]);

            return is_array($events) ? $events : [];
        }

        $events = $this->fallbackEvents;
        $this->fallbackEvents = [];

        return $events;
    }
}
