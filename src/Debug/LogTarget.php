<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Debug;

use Dacheng\Yii2\Swoole\Debug\Panels\DbPanel;
use Dacheng\Yii2\Swoole\Debug\Panels\MailPanel;
use Dacheng\Yii2\Swoole\Log\CoroutineTargetInterface;
use Dacheng\Yii2\Swoole\Web\RequestContext;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Throwable;
use Yii;
use yii\base\InvalidConfigException;
use yii\debug\FlattenException;
use yii\debug\LogTarget as BaseDebugLogTarget;
use yii\helpers\FileHelper;

/**
 * Coroutine-safe Debug LogTarget implementing Log\CoroutineTargetInterface.
 *
 * Isolates $tag, $messages, and export state inside Swoole coroutine context so
 * concurrent requests never mix logs or overwrite each other's debug snapshots.
 * Protects index.data updates with both an in-process coroutine Channel mutex and
 * cross-worker flock(LOCK_EX), and writes per-request data files atomically.
 */
class LogTarget extends BaseDebugLogTarget implements CoroutineTargetInterface
{
    private const CONTEXT_TAG_KEY = '__yii2SwooleDebugTag';
    private const CONTEXT_MESSAGES_KEY = '__yii2SwooleDebugMessages';
    private const CONTEXT_EXPORTED_KEY = '__yii2SwooleDebugExported';

    /** @var array<int, mixed> */
    private array $fallbackMessages = [];

    private string $fallbackTag = '';

    private ?Channel $indexLock = null;

    /**
     * @param Module $module
     * @param array<string, mixed> $config
     */
    public function __construct($module, $config = [])
    {
        parent::__construct($module, $config);

        $this->fallbackTag = $this->tag;
        $this->fallbackMessages = $this->messages;

        // Unset mutable parent properties so reads/writes route through __get/__set into coroutine context.
        unset($this->tag, $this->messages);
    }

    public function __get($name)
    {
        if ($name === 'tag') {
            return $this->getCoroutineTag();
        }
        if ($name === 'messages') {
            return $this->getCoroutineMessages();
        }

        return parent::__get($name);
    }

    public function __set($name, $value): void
    {
        if ($name === 'tag') {
            $this->setCoroutineTag((string) $value);
            return;
        }
        if ($name === 'messages') {
            $this->setCoroutineMessages(is_array($value) ? $value : []);
            return;
        }

        parent::__set($name, $value);
    }

    public function __isset($name): bool
    {
        if ($name === 'tag') {
            return true;
        }
        if ($name === 'messages') {
            return true;
        }

        return parent::__isset($name);
    }

    public function collect($messages, $final): void
    {
        if ($this->module instanceof Module && $this->module->isCurrentCoroutineMuted()) {
            if ($final) {
                $this->clearCoroutineMessages();
            }
            return;
        }

        if ($messages !== []) {
            $current = $this->getCoroutineMessages();
            $this->setCoroutineMessages(array_merge($current, $messages));
        }

        if ($final && !$this->isCurrentCoroutineExported()) {
            $this->markCurrentCoroutineExported();
            $this->export();
        }
    }

    public function export(): void
    {
        $path = $this->module->dataPath;
        FileHelper::createDirectory($path, $this->module->dirMode);

        $tag = $this->getCoroutineTag();
        $panels = $this->module instanceof Module
            ? $this->module->getCoroutinePanels()
            : $this->module->panels;

        $summary = $this->collectSummaryForPanels($panels, $tag);
        $data = [];
        $exceptions = [];

        foreach ($panels as $id => $panel) {
            try {
                $panelData = $panel->save();
                if ($id === 'profiling' && is_array($panelData)) {
                    $summary['peakMemory'] = $panelData['memory'] ?? 0;
                    $summary['processingTime'] = $panelData['time'] ?? 0;
                }
                $data[$id] = serialize($panelData);
            } catch (Throwable $exception) {
                $exceptions[$id] = new FlattenException(
                    $exception instanceof \Exception
                        ? $exception
                        : new \ErrorException($exception->getMessage(), (int) $exception->getCode(), E_ERROR, $exception->getFile(), $exception->getLine(), $exception)
                );
            }
        }

        $data['summary'] = $summary;
        $data['exceptions'] = $exceptions;

        $dataFile = "{$path}/{$tag}.data";
        $tmpFile = "{$dataFile}." . getmypid() . '.' . (self::inCoroutine() ? Coroutine::getCid() : 0) . '.tmp';
        if (@file_put_contents($tmpFile, serialize($data)) !== false) {
            @rename($tmpFile, $dataFile);
        } else {
            file_put_contents($dataFile, serialize($data));
        }

        if ($this->module->fileMode !== null) {
            @chmod($dataFile, $this->module->fileMode);
        }

        $indexFile = "{$path}/index.data";
        $this->updateIndexFileSafely($indexFile, $tag, $summary);
    }

    public function loadManifest(): array
    {
        $indexFile = $this->module->dataPath . '/index.data';
        if (!is_file($indexFile)) {
            return [];
        }

        $content = '';
        $fp = @fopen($indexFile, 'r');
        if ($fp !== false) {
            @flock($fp, LOCK_SH);
            $content = stream_get_contents($fp);
            @flock($fp, LOCK_UN);
            @fclose($fp);
        }

        if (is_string($content) && $content !== '') {
            $decoded = @unserialize($content);
            if (is_array($decoded)) {
                return array_reverse($decoded, true);
            }
        }

        return [];
    }

    public function loadTagToPanels($tag): array
    {
        $dataFile = $this->module->dataPath . "/{$tag}.data";
        $raw = @file_get_contents($dataFile);
        if ($raw === false) {
            throw new \yii\web\NotFoundHttpException("Unable to read debug data file for tag '{$tag}'.");
        }

        $data = @unserialize($raw);
        if (!is_array($data)) {
            throw new \yii\web\NotFoundHttpException("Corrupted debug data file for tag '{$tag}'.");
        }

        $exceptions = $data['exceptions'] ?? [];

        // Always hydrate fresh per-coroutine clones from prototypes so concurrent debugger views
        // never collide and prototypes are never mutated or unset.
        $prototypes = $this->module instanceof Module
            ? $this->module->getPanelPrototypes()
            : $this->module->panels;

        $panels = [];
        foreach ($prototypes as $id => $prototype) {
            $panels[$id] = clone $prototype;
        }

        foreach ($panels as $id => $panel) {
            if (isset($data[$id])) {
                $panel->tag = $tag;
                $panel->load(@unserialize($data[$id]));
            } else {
                unset($panels[$id]);
                continue;
            }
            if (isset($exceptions[$id])) {
                $panel->setError($exceptions[$id]);
            }
        }

        if ($this->module instanceof Module) {
            $this->module->setCoroutinePanels($panels);
        }

        return $data;
    }

    public function clearCoroutineMessages(): void
    {
        if (!self::inCoroutine()) {
            $this->fallbackMessages = [];
            return;
        }

        $context = Coroutine::getContext();
        unset(
            $context[$this->getContextKey(self::CONTEXT_TAG_KEY)],
            $context[$this->getContextKey(self::CONTEXT_MESSAGES_KEY)],
            $context[$this->getContextKey(self::CONTEXT_EXPORTED_KEY)]
        );

        if ($this->module instanceof Module) {
            $this->module->clearCoroutinePanels();
            $this->module->unmuteCurrentCoroutine();
        }
    }

    /**
     * @param array<string, \yii\debug\Panel> $panels
     * @return array<string, mixed>
     */
    protected function collectSummaryForPanels(array $panels, string $tag): array
    {
        if (Yii::$app === null) {
            return [];
        }

        $request = Yii::$app->getRequest();
        $response = Yii::$app->getResponse();
        $ctx = RequestContext::current();
        $startedAt = $ctx !== null
            ? $ctx->startedAt
            : (isset($_SERVER['REQUEST_TIME_FLOAT']) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime(true));

        $sqlCount = 0;
        $excessiveCallersCount = 0;
        if (isset($panels['db']) && $panels['db'] instanceof \yii\debug\panels\DbPanel) {
            $profileLogs = $panels['db']->getProfileLogs();
            $sqlCount = (int) (count($profileLogs) / 2);
            $excessiveCallersCount = $panels['db']->getExcessiveCallersCount();
        }

        $summary = [
            'tag' => $tag,
            'url' => $request instanceof \yii\console\Request
                ? 'php yii ' . implode(' ', $request->getParams())
                : $request->getAbsoluteUrl(),
            'ajax' => $request instanceof \yii\console\Request ? 0 : (int) $request->getIsAjax(),
            'method' => $request instanceof \yii\console\Request ? 'COMMAND' : $request->getMethod(),
            'ip' => $request instanceof \yii\console\Request ? get_current_user() : $request->getUserIP(),
            'time' => $startedAt,
            'statusCode' => $response instanceof \yii\console\Response ? $response->exitStatus : $response->statusCode,
            'sqlCount' => $sqlCount,
            'excessiveCallersCount' => $excessiveCallersCount,
        ];

        if (isset($panels['mail']) && $panels['mail'] instanceof \yii\debug\panels\MailPanel) {
            $mailFiles = $panels['mail']->getMessagesFileName();
            $summary['mailCount'] = count($mailFiles);
            $summary['mailFiles'] = $mailFiles;
        }

        return $summary;
    }

    /**
     * Updates index.data protected by both an in-worker coroutine mutex (since POSIX flock is
     * process-scoped and does not serialize two coroutines inside the same worker) and flock(LOCK_EX)
     * across workers.
     *
     * @param array<string, mixed> $summary
     */
    private function updateIndexFileSafely(string $indexFile, string $tag, array $summary): void
    {
        $acquired = false;
        if (self::inCoroutine()) {
            $lock = $this->getIndexLock();
            $token = $lock->pop(5.0);
            if ($token === false) {
                Yii::warning('Timed out waiting for in-worker debug index lock.', __METHOD__);
                return;
            }
            $acquired = true;
        }

        try {
            if (!@touch($indexFile) || ($fp = @fopen($indexFile, 'c+')) === false) {
                throw new InvalidConfigException("Unable to open debug data index file: {$indexFile}");
            }

            try {
                @flock($fp, LOCK_EX);
                rewind($fp);
                $raw = stream_get_contents($fp);
                $manifest = (is_string($raw) && $raw !== '') ? @unserialize($raw) : [];
                if (!is_array($manifest)) {
                    $manifest = [];
                }

                $manifest[$tag] = $summary;
                $this->gc($manifest);

                ftruncate($fp, 0);
                rewind($fp);
                fwrite($fp, serialize($manifest));
                fflush($fp);
            } finally {
                @flock($fp, LOCK_UN);
                @fclose($fp);
            }

            if ($this->module->fileMode !== null) {
                @chmod($indexFile, $this->module->fileMode);
            }
        } finally {
            if ($acquired && $this->indexLock !== null) {
                $this->indexLock->push(true);
            }
        }
    }

    private function getIndexLock(): Channel
    {
        if ($this->indexLock === null) {
            $this->indexLock = new Channel(1);
            $this->indexLock->push(true);
        }

        return $this->indexLock;
    }

    private function getCoroutineTag(): string
    {
        if (!self::inCoroutine()) {
            return $this->fallbackTag;
        }

        $context = Coroutine::getContext();
        $key = $this->getContextKey(self::CONTEXT_TAG_KEY);
        if (isset($context[$key]) && is_string($context[$key]) && $context[$key] !== '') {
            return $context[$key];
        }

        $tag = uniqid('', true);
        // Strip dot for clean filename compatibility with yii2-debug tag format
        $tag = str_replace('.', '', $tag);
        $context[$key] = $tag;

        return $tag;
    }

    private function setCoroutineTag(string $tag): void
    {
        if (!self::inCoroutine()) {
            $this->fallbackTag = $tag;
            return;
        }

        Coroutine::getContext()[$this->getContextKey(self::CONTEXT_TAG_KEY)] = $tag;
    }

    /**
     * @return array<int, mixed>
     */
    private function getCoroutineMessages(): array
    {
        if (!self::inCoroutine()) {
            return $this->fallbackMessages;
        }

        $messages = Coroutine::getContext()[$this->getContextKey(self::CONTEXT_MESSAGES_KEY)] ?? [];

        return is_array($messages) ? $messages : [];
    }

    /**
     * @param array<int, mixed> $messages
     */
    private function setCoroutineMessages(array $messages): void
    {
        if (!self::inCoroutine()) {
            $this->fallbackMessages = $messages;
            return;
        }

        Coroutine::getContext()[$this->getContextKey(self::CONTEXT_MESSAGES_KEY)] = $messages;
    }

    private function isCurrentCoroutineExported(): bool
    {
        if (!self::inCoroutine()) {
            return false;
        }

        return !empty(Coroutine::getContext()[$this->getContextKey(self::CONTEXT_EXPORTED_KEY)]);
    }

    private function markCurrentCoroutineExported(): void
    {
        if (self::inCoroutine()) {
            Coroutine::getContext()[$this->getContextKey(self::CONTEXT_EXPORTED_KEY)] = true;
        }
    }

    private function getContextKey(string $prefix): string
    {
        return $prefix . ':' . spl_object_id($this);
    }

    private static function inCoroutine(): bool
    {
        return extension_loaded('swoole') && Coroutine::getCid() >= 0;
    }
}
