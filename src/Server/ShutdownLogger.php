<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Server;

use Yii;
use yii\log\Dispatcher;
use yii\log\FileTarget;
use yii\log\Logger;

/**
 * ShutdownLogger provides consistent logging during application shutdown.
 *
 * This logger handles the critical period when Yii's logging system may be
 * partially destroyed or unavailable. It gracefully degrades to direct file
 * writing when needed.
 *
 * Features:
 * - Tries Yii logger first, falls back to direct file write
 * - Prevents recursion when Yii log system is shutting down
 * - Thread-safe for coroutine environments
 */
class ShutdownLogger
{
    private static ?FileTarget $fallbackTarget = null;
    private static bool $yiiAvailable = true;
    private static array $fallbackMessages = [];

    /**
     * Logs a message at the specified level.
     *
     * @param string $message The message to log
     * @param int $level The Yii log level
     */
    public static function log(string $message, int $level = Logger::LEVEL_INFO): void
    {
        // Try Yii logger first
        if (self::$yiiAvailable && self::tryYiiLog($message, $level)) {
            return;
        }

        // Fallback to direct file write
        self::fallbackLog($message, $level);
    }

    /**
     * Convenience method for debug messages.
     */
    public static function debug(string $message): void
    {
        self::log($message, Logger::LEVEL_DEBUG);
    }

    /**
     * Convenience method for info messages.
     */
    public static function info(string $message): void
    {
        self::log($message, Logger::LEVEL_INFO);
    }

    /**
     * Convenience method for warning messages.
     */
    public static function warning(string $message): void
    {
        self::log($message, Logger::LEVEL_WARNING);
    }

    /**
     * Convenience method for error messages.
     */
    public static function error(string $message): void
    {
        self::log($message, Logger::LEVEL_ERROR);
    }

    /**
     * Tries to log using Yii's logger.
     *
     * @return bool True if successful, false if Yii logger is unavailable
     */
    private static function tryYiiLog(string $message, int $level): bool
    {
        if (!class_exists(Yii::class)) {
            self::$yiiAvailable = false;
            return false;
        }

        try {
            if (!Yii::$app || !Yii::$app->has('log')) {
                self::$yiiAvailable = false;
                return false;
            }

            $logger = Yii::$app->log->getLogger();
            $logger->log($message, $level);

            // Try to flush immediately for shutdown scenarios
            if (PHP_SAPI === 'cli' || self::isShuttingDown()) {
                Yii::$app->log->flush(false);
            }

            return true;
        } catch (\Throwable $e) {
            self::$yiiAvailable = false;
            return false;
        }
    }

    /**
     * Falls back to direct file logging.
     */
    private static function fallbackLog(string $message, int $level): void
    {
        // Store messages for later batch processing
        self::$fallbackMessages[] = [
            'message' => $message,
            'level' => $level,
            'time' => microtime(true),
        ];

        // Flush immediately if we have accumulated enough or during shutdown
        if (count(self::$fallbackMessages) >= 10 || self::isShuttingDown()) {
            self::flushFallbackMessages();
        }
    }

    /**
     * Flushes accumulated fallback messages to the log file.
     */
    public static function flushFallbackMessages(): void
    {
        if (empty(self::$fallbackMessages)) {
            return;
        }

        try {
            $target = self::getFallbackTarget();

            foreach (self::$fallbackMessages as $entry) {
                $target->messages[] = [
                    $entry['message'],
                    $entry['level'],
                    $entry['time'],
                    [],
                    null,
                ];
            }

            $target->export();

            self::$fallbackMessages = [];
        } catch (\Throwable $e) {
            // Last resort: write to error_log
            foreach (self::$fallbackMessages as $entry) {
                error_log(sprintf(
                    '[%s] %s: %s',
                    strtoupper(Logger::getLevelName($entry['level'])),
                    'yii2-swoole-shutdown',
                    $entry['message']
                ));
            }
            self::$fallbackMessages = [];
        }
    }

    /**
     * Gets or creates the fallback file target.
     */
    private static function getFallbackTarget(): FileTarget
    {
        if (self::$fallbackTarget === null) {
            $logFile = self::resolveLogFilePath();

            self::$fallbackTarget = new FileTarget([
                'logFile' => $logFile,
                'enableRotation' => false,
                'exportInterval' => 1,
                'logVars' => [],
            ]);
        }

        return self::$fallbackTarget;
    }

    /**
     * Resolves the fallback log file path.
     */
    private static function resolveLogFilePath(): string
    {
        // Try to use Yii's runtime path
        if (class_exists(Yii::class) && Yii::$app) {
            try {
                $runtimePath = Yii::$app->getRuntimePath();
                return $runtimePath . '/swoole-shutdown.log';
            } catch (\Throwable $e) {
                // Fall through to system temp
            }
        }

        // Use system temp directory
        return sys_get_temp_dir() . '/yii2-swoole-shutdown.log';
    }

    /**
     * Checks if we are in shutdown phase.
     */
    private static function isShuttingDown(): bool
    {
        return self::$yiiAvailable === false || connection_status() !== CONNECTION_NORMAL;
    }

    /**
     * Resets the logger state (useful for testing).
     */
    public static function reset(): void
    {
        self::$yiiAvailable = true;
        self::$fallbackMessages = [];
        self::$fallbackTarget = null;
    }
}
