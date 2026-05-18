<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Log;

use Swoole\Timer;

/**
 * LogWorker handles asynchronous file writing using a direct buffer approach.
 *
 * Messages are pushed directly to an internal buffer, and a timer periodically
 * flushes the buffer to disk. This design avoids Channel operations and deadlock risks.
 *
 * Features:
 * - Hard buffer limit with overflow protection
 * - Automatic warning when buffer is full
 * - Graceful degradation under heavy load
 * - Configurable overflow callback for custom monitoring
 *
 * Configuration example:
 * ```php
 * 'log' => [
 *     'targets' => [
 *         [
 *             'class' => CoroutineFileTarget::class,
 *             'logWorkerConfig' => [
 *                 'batchInterval' => 200,        // ms, default: 100
 *                 'maxBufferSize' => 50000,      // default: 100000
 *                 'bufferWarningThreshold' => 45000, // default: 90000
 *                 'maxBufferWarnings' => 5,      // default: 10
 *                 'onBufferOverflow' => function(int $dropped, int $current, int $max): void {
 *                     Yii::warning("Log buffer overflow: {$dropped} messages dropped", 'log-worker');
 *                 },
 *             ],
 *         ],
 *     ],
 * ],
 * ```
 */
class LogWorker
{
    /**
     * Timer interval for batch writing (milliseconds)
     * @var int
     */
    public int $batchInterval = 100;

    /**
     * Hard buffer limit - maximum messages to buffer
     * When reached, oldest messages are dropped to prevent memory exhaustion
     * @var int
     */
    public int $maxBufferSize = 100000;

    /**
     * Warning threshold - when buffer exceeds this size, log a warning
     * @var int
     */
    public int $bufferWarningThreshold = 90000;

    /**
     * Maximum number of buffer-full warnings to log (prevents log spam)
     * @var int
     */
    public int $maxBufferWarnings = 10;

    /**
     * @var callable|null Callback invoked when buffer overflow occurs.
     *                    Signature: function(int $droppedCount, int $currentSize, int $maxSize): void
     *                    If null, falls back to error_log().
     */
    public $onBufferOverflow = null;

    private string $logFile;
    private bool $enableRotation;
    private int $maxFileSize;
    private int $maxLogFiles;
    private ?int $fileMode;
    private int $dirMode;
    private bool $running = false;
    private ?int $writeTimer = null;
    private array $messageBuffer = [];
    private int $droppedMessages = 0;
    private int $bufferWarningCount = 0;
    private int $totalDropped = 0;

    /**
     * Creates a new LogWorker instance.
     *
     * @param string $logFile Path to the log file
     * @param bool $enableRotation Whether to enable log rotation
     * @param int $maxFileSize Maximum file size in KB before rotation
     * @param int $maxLogFiles Maximum number of rotated log files to keep
     * @param int|null $fileMode Octal file mode for log files, or null to use system default
     * @param int $dirMode Octal directory mode for log directory creation
     * @param array $config Optional configuration for buffer settings
     */
    public function __construct(
        string $logFile,
        bool $enableRotation,
        int $maxFileSize,
        int $maxLogFiles,
        ?int $fileMode,
        int $dirMode,
        array $config = []
    ) {
        $this->logFile = $logFile;
        $this->enableRotation = $enableRotation;
        $this->maxFileSize = $maxFileSize;
        $this->maxLogFiles = $maxLogFiles;
        $this->fileMode = $fileMode;
        $this->dirMode = $dirMode;

        // Apply configuration
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
    }

    /**
     * Starts the log worker.
     *
     * Creates the log directory if needed and starts a timer that periodically
     * flushes buffered messages to disk. If already started, this method does nothing.
     *
     * @return void
     */
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

        $this->writeTimer = Timer::tick($this->batchInterval, function () {
            if (!$this->running) {
                return;
            }
            $this->writeBufferedMessages();
        });
    }

    /**
     * Stops the log worker.
     *
     * Flushes any remaining buffered messages to disk and stops the timer.
     * Logs warnings if messages remain in buffer or were dropped.
     *
     * @return void
     */
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

        $this->writeBufferedMessages();

        if (!empty($this->messageBuffer)) {
            // Use error_log here to avoid recursion in logging system
            error_log('[LogWorker] Warning: ' . count($this->messageBuffer) . ' messages remain after stop');
        }

        if ($this->totalDropped > 0) {
            // Use error_log here to avoid recursion in logging system
            error_log('[LogWorker] Warning: ' . $this->totalDropped . ' messages were dropped (buffer full)');
        }
    }

    /**
     * Pushes log messages to the internal buffer.
     *
     * Messages will be written to disk during the next flush cycle.
     * Partially accepts messages when buffer is nearly full to minimize drops.
     *
     * When buffer reaches MAX_BUFFER_SIZE:
     * - Oldest messages are dropped (FIFO eviction)
     * - Warning is logged (up to MAX_BUFFER_WARNINGS times)
     * - New messages replace old ones to prevent memory exhaustion
     *
     * @param array<string> $messages Array of log message strings to buffer
     * @return int Number of messages actually buffered
     */
    public function pushMessages(array $messages): int
    {
        if (!$this->running) {
            return 0;
        }

        $currentSize = count($this->messageBuffer);

        // Check if we need to evict old messages
        if ($currentSize + count($messages) > $this->maxBufferSize) {
            $this->evictOldMessages(count($messages));
        }

        // Log buffer warning if approaching limit
        if ($currentSize > $this->bufferWarningThreshold && $this->bufferWarningCount < $this->maxBufferWarnings) {
            $this->bufferWarningCount++;
            $this->logBufferWarning($currentSize);
        }

        $accepted = 0;
        foreach ($messages as $message) {
            if (count($this->messageBuffer) >= $this->maxBufferSize) {
                $this->droppedMessages++;
                $this->totalDropped++;
                break;
            }
            $this->messageBuffer[] = $message;
            $accepted++;
        }

        return $accepted;
    }

    /**
     * Evicts old messages from buffer to make room for new ones.
     *
     * @param int $needed Number of slots needed
     */
    private function evictOldMessages(int $needed): void
    {
        $toRemove = min(count($this->messageBuffer), $needed);
        $this->messageBuffer = array_slice($this->messageBuffer, $toRemove);
        $this->totalDropped += $toRemove;
    }

    /**
     * Logs a buffer capacity warning.
     * Invokes callback if configured, otherwise falls back to error_log.
     *
     * @param int $currentSize Current buffer size
     */
    private function logBufferWarning(int $currentSize): void
    {
        if ($this->onBufferOverflow !== null) {
            ($this->onBufferOverflow)($this->totalDropped, $currentSize, $this->maxBufferSize);
            return;
        }

        // Fallback to error_log when no callback is configured
        $warning = sprintf(
            '[LogWorker] Warning: Buffer at %d/%d messages (%.1f%% full). %d total messages dropped.',
            $currentSize,
            $this->maxBufferSize,
            ($currentSize / $this->maxBufferSize) * 100,
            $this->totalDropped
        );
        error_log($warning);
    }

    private function writeBufferedMessages(): void
    {
        if (empty($this->messageBuffer)) {
            return;
        }

        $messages = $this->messageBuffer;
        $this->messageBuffer = [];

        $this->writeMessages($messages);
    }

    private function writeMessages(array $messages): void
    {
        if (empty($messages)) {
            return;
        }

        $text = implode("\n", $messages) . "\n";

        if (trim($text) === '') {
            return;
        }

        $success = @file_put_contents($this->logFile, $text, FILE_APPEND | LOCK_EX);

        if ($success === false) {
            // Use error_log here to avoid recursion in logging system
            error_log("LogWorker: Unable to write to log file: {$this->logFile}");
            return;
        }

        if ($this->fileMode !== null) {
            @chmod($this->logFile, $this->fileMode);
        }

        if ($this->enableRotation) {
            clearstatcache();
            $fileSize = @filesize($this->logFile);
            if ($fileSize !== false && $fileSize > $this->maxFileSize * 1024) {
                $this->rotateFiles();
            }
        }
    }

    private function rotateFiles(): void
    {
        LogFileRotator::rotate($this->logFile, $this->maxLogFiles, $this->fileMode);
    }
}
