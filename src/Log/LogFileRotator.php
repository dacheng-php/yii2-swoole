<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Log;

/**
 * Shared, multi-worker-safe log file rotation using an advisory lock file.
 *
 * Uses a non-blocking LOCK_EX on a dedicated sibling lock file so concurrent
 * Swoole workers never stomp on each other's rename() sequence, and re-checks
 * the file size after acquiring the lock to avoid duplicate rotations.
 */
final class LogFileRotator
{
    public static function rotate(
        string $logFile,
        int $maxLogFiles,
        ?int $fileMode = null,
        int $maxFileSizeBytes = 0
    ): void {
        $lockFile = $logFile . '.rotate.lock';
        $lockFp = @fopen($lockFile, 'c');
        if ($lockFp === false) {
            return;
        }

        if (!@flock($lockFp, LOCK_EX | LOCK_NB)) {
            @fclose($lockFp);
            return;
        }

        try {
            if ($maxFileSizeBytes > 0) {
                clearstatcache(true, $logFile);
                $currentSize = @filesize($logFile);
                if ($currentSize === false || $currentSize <= $maxFileSizeBytes) {
                    return;
                }
            }

            for ($i = $maxLogFiles; $i >= 0; --$i) {
                $rotateFile = $logFile . ($i === 0 ? '' : '.' . $i);

                if (!is_file($rotateFile)) {
                    continue;
                }

                if ($i === $maxLogFiles) {
                    @unlink($rotateFile);
                    continue;
                }

                $newFile = $logFile . '.' . ($i + 1);

                if ($i === 0) {
                    if (@rename($rotateFile, $newFile)) {
                        if ($fileMode !== null) {
                            @chmod($newFile, $fileMode);
                        }
                        self::createEmptyFile($rotateFile, $fileMode);
                    }
                } else {
                    if (@rename($rotateFile, $newFile) && $fileMode !== null) {
                        @chmod($newFile, $fileMode);
                    }
                }
            }
        } finally {
            @flock($lockFp, LOCK_UN);
            @fclose($lockFp);
        }
    }

    private static function createEmptyFile(string $file, ?int $fileMode = null): void
    {
        $fp = @fopen($file, 'a');
        if ($fp !== false) {
            @fclose($fp);
            if ($fileMode !== null) {
                @chmod($file, $fileMode);
            }
        }
    }
}
