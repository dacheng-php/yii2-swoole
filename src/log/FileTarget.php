<?php

declare(strict_types=1);

namespace yii2swoole\log;

use Yii;
use Swoole\Coroutine;
use yii\log\Target;

/**
 * FileTarget writes log messages asynchronously using a buffer-based LogWorker.
 *
 * Example configuration:
 * ```php
 * 'log' => [
 *     'targets' => [
 *         [
 *             'class' => \yii2swoole\log\FileTarget::class,
 *             'levels' => ['error', 'warning', 'info'],
 *             'logFile' => '@runtime/logs/app.log',
 *             'maxFileSize' => 10240,
 *         ],
 *     ],
 * ],
 * ```
 */
class FileTarget extends Target implements CoroutineTargetInterface
{
    public $logFile;
    public $enableRotation = true;
    public $maxFileSize = 10240;
    public $maxLogFiles = 5;
    public $fileMode;
    public $dirMode = 0775;
    public array $logWorkerConfig = [];

    private ?LogWorker $worker = null;
    private bool $initialized = false;
    private bool $isShuttingDown = false;

    public function getWorker(): ?LogWorker
    {
        return $this->worker;
    }

    public function init()
    {
        parent::init();

        if ($this->logFile === null) {
            $this->logFile = Yii::$app->getRuntimePath() . '/logs/app.log';
        } else {
            $this->logFile = Yii::getAlias($this->logFile);
        }

        $this->maxLogFiles = max(1, $this->maxLogFiles);
        $this->maxFileSize = max(1, $this->maxFileSize);
    }

    private function ensureInitialized(): void
    {
        if ($this->initialized) {
            return;
        }

        $this->worker = new LogWorker(
            $this->logFile,
            $this->enableRotation,
            $this->maxFileSize,
            $this->maxLogFiles,
            $this->fileMode,
            $this->dirMode,
            $this->logWorkerConfig
        );

        $this->worker->start();
        $this->initialized = true;
    }

    public function export()
    {
        $messages = $this->getMessages();
        if (empty($messages)) {
            return;
        }

        $inCoroutine = extension_loaded('swoole') && Coroutine::getCid() >= 0;

        if ($this->isShuttingDown || !$inCoroutine) {
            $this->exportSync();
            $this->setMessages([]);
            return;
        }

        $this->ensureInitialized();

        $formattedMessages = array_map([$this, 'formatMessage'], $messages);
        $this->setMessages([]);

        if (empty($formattedMessages)) {
            return;
        }

        try {
            $pushed = $this->worker->pushMessages($formattedMessages);

            if ($pushed < count($formattedMessages)) {
                $this->writeFormattedMessages(array_slice($formattedMessages, $pushed));
            }
        } catch (\Throwable $e) {
            $this->writeFormattedMessages($formattedMessages);
        } finally {
            unset($formattedMessages);
        }
    }

    private function exportSync(): void
    {
        $messages = $this->getMessages();
        if (empty($messages)) {
            return;
        }

        $formattedMessages = array_map([$this, 'formatMessage'], $messages);
        $this->writeFormattedMessages($formattedMessages);
    }

    public function collect($messages, $final)
    {
        if (!$this->isCoroutineContext()) {
            parent::collect($messages, $final);

            return;
        }

        $filteredMessages = static::filterMessages($messages, $this->getLevels(), $this->categories, $this->except);
        $targetMessages = array_merge($this->getMessages(), $filteredMessages);
        $count = count($targetMessages);

        $context = Coroutine::getContext();
        $exportingKey = '__yiiSwooleLogExporting:' . spl_object_id($this);
        $isExporting = !empty($context[$exportingKey]);

        if ($count > 0 && ($final || (!$isExporting && $this->exportInterval > 0 && $count >= $this->exportInterval))) {
            if (($contextMessage = $this->getContextMessage()) !== '') {
                $targetMessages[] = [$contextMessage, \yii\log\Logger::LEVEL_INFO, 'application', microtime(true), [], 0];
            }

            // Guard re-entrancy per coroutine instead of mutating shared $this->exportInterval
            $context[$exportingKey] = true;
            try {
                $this->setMessages($targetMessages);
                $this->export();
            } finally {
                unset($context[$exportingKey]);
                $this->setMessages([]);
            }

            return;
        }

        $this->setMessages($targetMessages);
    }

    public function clearCoroutineMessages(): void
    {
        if ($this->isCoroutineContext()) {
            $this->setMessages([]);
        }
    }

    private function writeFormattedMessages(array $formattedMessages): void
    {
        if (empty($formattedMessages)) {
            return;
        }

        $text = implode("\n", $formattedMessages) . "\n";

        $logPath = dirname($this->logFile);
        if (!is_dir($logPath)) {
            @mkdir($logPath, $this->dirMode, true);
        }

        if (($fp = @fopen($this->logFile, 'a')) === false) {
            // Use error_log here to avoid recursion in logging system
            error_log("Unable to append to log file: {$this->logFile}");
            return;
        }

        @flock($fp, LOCK_EX);

        if ($this->enableRotation && @filesize($this->logFile) > $this->maxFileSize * 1024) {
            @flock($fp, LOCK_UN);
            @fclose($fp);
            $this->rotateFilesSync();

            if (($fp = @fopen($this->logFile, 'a')) === false) {
                // Use error_log here to avoid recursion in logging system
                error_log("Unable to reopen log file after rotation: {$this->logFile}");
                return;
            }
            @flock($fp, LOCK_EX);
        }

        @fwrite($fp, $text);
        @fflush($fp);
        @flock($fp, LOCK_UN);
        @fclose($fp);

        if ($this->fileMode !== null) {
            @chmod($this->logFile, $this->fileMode);
        }
    }

    private function rotateFilesSync(): void
    {
        LogFileRotator::rotate($this->logFile, $this->maxLogFiles, $this->fileMode, $this->maxFileSize * 1024);
    }

    public function shutdown(): void
    {
        if ($this->isShuttingDown) {
            return;
        }

        $this->isShuttingDown = true;

        if (!$this->initialized) {
            return;
        }

        if ($this->worker !== null) {
            try {
                $this->worker->stop();
            } catch (\Throwable $e) {
                // Use error_log here to avoid recursion in logging system
                error_log('[Log\\FileTarget] Error stopping worker: ' . $e->getMessage());
            }
            $this->worker = null;
        }

        $this->initialized = false;
    }

    public function __destruct()
    {
        try {
            $this->shutdown();
        } catch (\Throwable $e) {
            // Use error_log here to avoid recursion in logging system
            error_log('[Log\\FileTarget] Error during destruct: ' . $e->getMessage());
        }
    }

    /**
     * @return array<int, mixed>
     */
    private function getMessages(): array
    {
        if (!$this->isCoroutineContext()) {
            return $this->messages;
        }

        $context = Coroutine::getContext();
        $key = $this->getCoroutineMessagesKey();

        return $context[$key] ?? [];
    }

    /**
     * @param array<int, mixed> $messages
     */
    private function setMessages(array $messages): void
    {
        if (!$this->isCoroutineContext()) {
            $this->messages = $messages;

            return;
        }

        $context = Coroutine::getContext();
        $key = $this->getCoroutineMessagesKey();
        if ($messages === []) {
            unset($context[$key]);
        } else {
            $context[$key] = $messages;
        }
    }

    private function getCoroutineMessagesKey(): string
    {
        return '__yiiSwooleLogTargetMessages:' . spl_object_id($this);
    }

    private function isCoroutineContext(): bool
    {
        return extension_loaded('swoole') && Coroutine::getCid() >= 0;
    }
}
