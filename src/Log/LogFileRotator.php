<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Log;

/**
 * LogFileRotator provides shared log file rotation logic.
 *
 * This class eliminates code duplication between LogWorker and CoroutineFileTarget
 * by providing a single implementation of log file rotation.
 */
final class LogFileRotator
{
    /**
     * Rotates log files by renaming existing files with incrementing suffixes.
     *
     * For example, with maxLogFiles = 3:
     * - app.log.3 is deleted
     * - app.log.2 becomes app.log.3 (renamed)
     * - app.log.1 becomes app.log.2 (renamed)
     * - app.log becomes app.log.1 (renamed) and a new empty app.log is created
     *
     * Uses rename() for rotated files to avoid duplicating disk usage.
     *
     * @param string $logFile The primary log file path
     * @param int $maxLogFiles Maximum number of rotated files to keep
     * @param int|null $fileMode Optional file mode to set on new files
     */
    public static function rotate(string $logFile, int $maxLogFiles, ?int $fileMode = null): void
    {
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

            // Use rename() for efficiency (avoids doubling disk usage)
            if ($i === 0) {
                // For the current log file: rename to .1, then create new empty file
                if (@rename($rotateFile, $newFile)) {
                    if ($fileMode !== null) {
                        @chmod($newFile, $fileMode);
                    }
                    // Create new empty log file
                    self::createEmptyFile($rotateFile, $fileMode);
                }
            } else {
                // For rotated files: just rename
                if (@rename($rotateFile, $newFile) && $fileMode !== null) {
                    @chmod($newFile, $fileMode);
                }
            }
        }
    }

    /**
     * Creates an empty file with optional mode.
     *
     * @param string $file The file path to create
     * @param int|null $fileMode Optional file mode to set
     */
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
