<?php

declare(strict_types=1);

namespace yii2swoole\server;

use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Swoole\Coroutine\System;
use Swoole\Http\Server as SwooleHttpServer;
use Swoole\Process;

/**
 * Portable development file watcher that triggers graceful Swoole worker reloads
 * when application PHP files change (no inotify/fswatch extension required).
 *
 * Uses RecursiveCallbackFilterIterator to prune ignored directories (vendor, runtime, .git)
 * before descending and Swoole's coroutine System::waitSignal(SIGTERM, interval) for
 * zero-deprecation, instant graceful shutdown without timers or polling sleeps.
 */
final class FileWatcher
{
    /** @var array<string, true> */
    private readonly array $extMap;

    /** @var array<string, true> */
    private readonly array $ignoredMap;

    /**
     * @param string[] $directories Directories to watch
     * @param string[] $extensions File extensions to monitor
     * @param string[] $ignoredDirs Directory names to prune during traversal
     */
    public function __construct(
        private readonly array $directories,
        array $extensions = ['php'],
        array $ignoredDirs = ['vendor', 'runtime', '.git', 'node_modules', 'web'],
        private readonly float $intervalSeconds = 0.8
    ) {
        $this->extMap = array_fill_keys($extensions, true);
        $this->ignoredMap = array_fill_keys($ignoredDirs, true);
    }

    public function attach(SwooleHttpServer $server): void
    {
        $process = new Process(function () use ($server): void {
            $snapshot = $this->scan();

            while (true) {
                if (System::waitSignal([SIGTERM, SIGINT], $this->intervalSeconds) !== false) {
                    return;
                }

                $current = $this->scan();
                $changed = $this->diff($snapshot, $current);

                if ($changed !== []) {
                    $snapshot = $current;
                    $sample = implode(', ', array_slice($changed, 0, 3));
                    $extra = count($changed) > 3 ? sprintf(' (+%d more)', count($changed) - 3) : '';
                    fwrite(STDOUT, sprintf("[watch] Change detected (%s%s), reloading workers...\n", $sample, $extra));
                    $server->reload();
                }
            }
        }, false, 0, true);

        $server->addProcess($process);
    }

    /**
     * @return array<string, string> Map of filepath => "mtime:size"
     */
    private function scan(): array
    {
        $state = [];
        $ignoredMap = $this->ignoredMap;

        foreach ($this->directories as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            $dirIterator = new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS);
            $filter = new RecursiveCallbackFilterIterator(
                $dirIterator,
                static function (SplFileInfo $current) use ($ignoredMap): bool {
                    if ($current->isDir()) {
                        return !isset($ignoredMap[$current->getFilename()]);
                    }
                    return true;
                }
            );
            $iterator = new RecursiveIteratorIterator($filter);

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if (!$file->isFile() || !isset($this->extMap[$file->getExtension()])) {
                    continue;
                }

                $path = $file->getPathname();
                clearstatcache(false, $path);
                $state[$path] = $file->getMTime() . ':' . $file->getSize();
            }
        }

        return $state;
    }

    /**
     * @param array<string, string> $previous
     * @param array<string, string> $current
     * @return string[]
     */
    private function diff(array $previous, array $current): array
    {
        $changed = [];

        foreach ($current as $path => $sig) {
            if (!isset($previous[$path]) || $previous[$path] !== $sig) {
                $changed[] = basename($path);
            }
        }

        foreach ($previous as $path => $sig) {
            if (!isset($current[$path])) {
                $changed[] = basename($path) . ' (deleted)';
            }
        }

        return $changed;
    }
}
