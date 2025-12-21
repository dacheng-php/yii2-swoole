<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Log;

use Yii;
use yii\log\Target;

/**
 * CoroutineFileTarget writes log messages asynchronously using a buffer-based worker.
 * 
 * Messages are pushed directly to a LogWorker's internal buffer, which periodically
 * flushes them to disk. This avoids blocking I/O operations in the main request flow.
 * 
 * Example configuration:
 * ```php
 * 'log' => [
 *     'targets' => [
 *         [
 *             'class' => \Dacheng\Yii2\Swoole\Log\CoroutineFileTarget::class,
 *             'levels' => ['error', 'warning', 'info'],
 *             'logFile' => '@runtime/logs/app.log',
 *             'maxFileSize' => 10240,
 *         ],
 *     ],
 * ],
 * ```
 */
class CoroutineFileTarget extends Target
{
    public $logFile;
    public $enableRotation = true;
    public $maxFileSize = 10240;
    public $maxLogFiles = 5;
    public $fileMode;
    public $dirMode = 0775;

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
            $applicationId = Yii::$app->request->getIsConsoleRequest()?'console':'app';
            $this->logFile = Yii::$app->getRuntimePath() . '/logs/'.$applicationId.'.log';
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
            $this->dirMode
        );

        $this->worker->start();
        $this->initialized = true;
    }

    public function export()
    {
        if (empty($this->messages)) {
            return;
        }

        $inCoroutine = extension_loaded('swoole') && \Swoole\Coroutine::getCid() > 0;
        
        if ($this->isShuttingDown || !$inCoroutine) {
            $this->exportSync();
            $this->messages = [];
            return;
        }

        $this->ensureInitialized();

        $formattedMessages = array_map([$this, 'formatMessage'], $this->messages);
        $this->messages = [];

        if (empty($formattedMessages)) {
            return;
        }

        try {
            $pushed = $this->worker->pushMessages($formattedMessages);
            
            if (!$pushed) {
                $this->writeFormattedMessages($formattedMessages);
            }
        } catch (\Throwable $e) {
            $this->writeFormattedMessages($formattedMessages);
        } finally {
            unset($formattedMessages);
        }
    }

    private function exportSync(): void
    {
        if (empty($this->messages)) {
            return;
        }

        $formattedMessages = array_map([$this, 'formatMessage'], $this->messages);
        $this->writeFormattedMessages($formattedMessages);
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
        $file = $this->logFile;
        for ($i = $this->maxLogFiles; $i >= 0; --$i) {
            $rotateFile = $file . ($i === 0 ? '' : '.' . $i);
            if (is_file($rotateFile)) {
                if ($i === $this->maxLogFiles) {
                    @unlink($rotateFile);
                    continue;
                }
                $newFile = $this->logFile . '.' . ($i + 1);
                @copy($rotateFile, $newFile);
                if ($this->fileMode !== null) {
                    @chmod($newFile, $this->fileMode);
                }
                if ($i === 0) {
                    if ($fp = @fopen($rotateFile, 'a')) {
                        @ftruncate($fp, 0);
                        @fclose($fp);
                    }
                }
            }
        }
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
                error_log('[CoroutineFileTarget] Error stopping worker: ' . $e->getMessage());
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
            error_log('[CoroutineFileTarget] Error during destruct: ' . $e->getMessage());
        }
    }
}
