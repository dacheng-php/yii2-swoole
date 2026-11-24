<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Server;

use Swoole\Process;

/**
 * Manages a running Swoole HTTP server via its PID file and POSIX signals.
 */
final class ProcessManager
{
    public function __construct(private readonly string $pidFile)
    {
    }

    public function getPidFile(): string
    {
        return $this->pidFile;
    }

    public function readPid(): ?int
    {
        if (!is_file($this->pidFile)) {
            return null;
        }

        $raw = trim((string) @file_get_contents($this->pidFile));
        if ($raw === '' || !ctype_digit($raw)) {
            return null;
        }

        $pid = (int) $raw;

        return $pid > 0 ? $pid : null;
    }

    public function isRunning(?int $pid = null): bool
    {
        $pid ??= $this->readPid();
        if ($pid === null || $pid <= 0) {
            return false;
        }

        return Process::kill($pid, 0);
    }

    /**
     * Sends SIGTERM and waits until the server exits cleanly.
     */
    public function stop(float $timeout = 15.0): bool
    {
        $pid = $this->readPid();
        if ($pid === null || !$this->isRunning($pid)) {
            $this->cleanupStalePidFile();
            return false;
        }

        Process::kill($pid, SIGTERM);

        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            if (!$this->isRunning($pid)) {
                $this->cleanupStalePidFile();
                return true;
            }
            usleep(100_000);
        }

        return !$this->isRunning($pid);
    }

    /**
     * Sends SIGUSR1 to gracefully reload all worker processes with zero dropped requests.
     */
    public function reload(): bool
    {
        $pid = $this->readPid();
        if ($pid === null || !$this->isRunning($pid)) {
            return false;
        }

        return Process::kill($pid, SIGUSR1);
    }

    /**
     * @return array{running:bool,pid:?int,pid_file:string}
     */
    public function status(): array
    {
        $pid = $this->readPid();
        $running = $pid !== null && $this->isRunning($pid);

        return [
            'running' => $running,
            'pid' => $running ? $pid : null,
            'pid_file' => $this->pidFile,
        ];
    }

    public function cleanupStalePidFile(): void
    {
        if (is_file($this->pidFile)) {
            @unlink($this->pidFile);
        }
    }
}
