<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Log;

use Swoole\Coroutine;
use Swoole\Coroutine\System;
use Swoole\Timer;
use yii\base\InvalidConfigException;

/**
 * Asynchronous batch log file writer backed by a bounded memory buffer and a Swoole timer.
 *
 * Guards disk flush & rotation with a re-entrancy lock so overlapping timer ticks cannot
 * corrupt file rotation.
 */
class LogWorker
{
    public int $batchInterval = 100;

    public int $maxBufferSize = 100000;

    public int $bufferWarningThreshold = 90000;

    public int $maxBufferWarnings = 10;

    /**
     * @var callable|null Signature: function(int $droppedCount, int $currentSize, int $maxSize): void
     */
    public $onBufferOverflow = null;

    private bool $running = false;

    private bool $flushing = false;

    private ?int $writeTimer = null;

    /** @var string[] */
    private array $messageBuffer = [];

    private int $bufferWarningCount = 0;

    private int $totalDropped = 0;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly string $logFile,
        private readonly bool $enableRotation,
        private readonly int $maxFileSize,
        private readonly int $maxLogFiles,
        private readonly ?int $fileMode,
        private readonly int $dirMode,
        array $config = []
    ) {
        if (isset($config['batchInterval'])) {
            $this->batchInterval = (int) $config['batchInterval'];
        }
        if (isset($config['maxBufferSize'])) {
            $this->maxBufferSize = (int) $config['maxBufferSize'];
        }
        if (isset($config['bufferWarningThreshold'])) {
            $this->bufferWarningThreshold = (int) $config['bufferWarningThreshold'];
        }
        if (isset($config['maxBufferWarnings'])) {
            $this->maxBufferWarnings = (int) $config['maxBufferWarnings'];
        }
        if (isset($config['onBufferOverflow']) && is_callable($config['onBufferOverflow'])) {
            $this->onBufferOverflow = $config['onBufferOverflow'];
        }

        $this->validateConfig();
    }

    public function start(): void
    {
        if ($this->running) {
            return;
        }

        $this->running = true;

        $logPath = dirname($this->logFile);
        if (!is_dir($logPath)) {
            @mkdir($logPath, $this->dirMode, true);
        }

        $this->writeTimer = Timer::tick($this->batchInterval, function (): void {
            if (!$this->running || $this->flushing || $this->messageBuffer === []) {
                return;
            }
            Coroutine::create(function (): void {
                $this->writeBufferedMessages();
            });
        });
    }

    public function stop(): void
    {
        if (!$this->running) {
            return;
        }

        $this->running = false;

        if ($this->writeTimer !== null) {
            Timer::clear($this->writeTimer);
            $this->writeTimer = null;
        }

        // Wait briefly if an async flush coroutine is currently writing to disk
        if ($this->flushing && Coroutine::getCid() >= 0) {
            $deadline = microtime(true) + 2.0;
            while ($this->flushing && microtime(true) < $deadline) {
                Coroutine::sleep(0.005);
            }
        }

        $this->writeBufferedMessages();
    }

    /**
     * @param string[] $messages
     */
    public function pushMessages(array $messages): int
    {
        if (!$this->running) {
            return 0;
        }

        $incomingCount = count($messages);
        $currentSize = count($this->messageBuffer);

        if ($currentSize + $incomingCount > $this->maxBufferSize) {
            $toRemove = min($currentSize, ($currentSize + $incomingCount) - $this->maxBufferSize);
            if ($toRemove > 0) {
                $this->messageBuffer = array_slice($this->messageBuffer, $toRemove);
                $this->totalDropped += $toRemove;
                $currentSize = count($this->messageBuffer);
            }
        }

        if ($currentSize > $this->bufferWarningThreshold && $this->bufferWarningCount < $this->maxBufferWarnings) {
            $this->bufferWarningCount++;
            $this->logBufferWarning($currentSize);
        }

        $accepted = 0;
        foreach ($messages as $message) {
            if (count($this->messageBuffer) >= $this->maxBufferSize) {
                $this->totalDropped++;
                break;
            }
            $this->messageBuffer[] = $message;
            $accepted++;
        }

        return $accepted;
    }

    /**
     * @return array{buffered:int,dropped:int,max_buffer_size:int,running:bool}
     */
    public function getStats(): array
    {
        return [
            'buffered' => count($this->messageBuffer),
            'dropped' => $this->totalDropped,
            'max_buffer_size' => $this->maxBufferSize,
            'running' => $this->running,
        ];
    }

    private function validateConfig(): void
    {
        if ($this->batchInterval < 1) {
            throw new InvalidConfigException('"batchInterval" must be >= 1 ms.');
        }
        if ($this->maxBufferSize < 1) {
            throw new InvalidConfigException('"maxBufferSize" must be >= 1.');
        }
        if ($this->bufferWarningThreshold < 0 || $this->bufferWarningThreshold > $this->maxBufferSize) {
            throw new InvalidConfigException('"bufferWarningThreshold" must be between 0 and "maxBufferSize".');
        }
        if ($this->maxBufferWarnings < 0) {
            throw new InvalidConfigException('"maxBufferWarnings" must be >= 0.');
        }
    }

    private function logBufferWarning(int $currentSize): void
    {
        if ($this->onBufferOverflow !== null) {
            ($this->onBufferOverflow)($this->totalDropped, $currentSize, $this->maxBufferSize);
            return;
        }

        error_log(sprintf(
            '[LogWorker] Buffer at %d/%d (%.1f%%). %d total messages dropped.',
            $currentSize,
            $this->maxBufferSize,
            ($currentSize / $this->maxBufferSize) * 100,
            $this->totalDropped
        ));
    }

    private function writeBufferedMessages(): void
    {
        if ($this->flushing || $this->messageBuffer === []) {
            return;
        }

        $this->flushing = true;
        try {
            while ($this->messageBuffer !== []) {
                $batch = $this->messageBuffer;
                $this->messageBuffer = [];
                $this->writeMessages($batch);
            }
        } finally {
            $this->flushing = false;
        }
    }

    /**
     * @param string[] $messages
     */
    private function writeMessages(array $messages): void
    {
        $text = implode("\n", $messages) . "\n";
        if (trim($text) === '') {
            return;
        }

        if (Coroutine::getCid() >= 0) {
            $success = System::writeFile($this->logFile, $text, FILE_APPEND) !== false;
        } else {
            $success = @file_put_contents($this->logFile, $text, FILE_APPEND | LOCK_EX) !== false;
        }

        if (!$success) {
            error_log("LogWorker: Unable to write to log file: {$this->logFile}");
            return;
        }

        if ($this->fileMode !== null) {
            @chmod($this->logFile, $this->fileMode);
        }

        if ($this->enableRotation) {
            clearstatcache(true, $this->logFile);
            $maxBytes = $this->maxFileSize * 1024;
            $fileSize = @filesize($this->logFile);
            if ($fileSize !== false && $fileSize > $maxBytes) {
                LogFileRotator::rotate($this->logFile, $this->maxLogFiles, $this->fileMode, $maxBytes);
            }
        }
    }
}
