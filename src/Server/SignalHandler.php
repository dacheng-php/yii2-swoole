<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Server;

use Swoole\Coroutine;
use Swoole\Process;

/**
 * SignalHandler manages graceful shutdown on SIGTERM/SIGINT signals.
 *
 * This handler:
 * - Registers signal handlers for SIGTERM and SIGINT
 * - Coordinates graceful shutdown of server components
 * - Ensures connection pools are closed properly
 * - Flushes logs before exit
 * - Allows in-flight requests to complete
 */
class SignalHandler
{
    private const SHUTDOWN_TIMEOUT = 30.0; // Maximum time to wait for graceful shutdown
    private const CHECK_INTERVAL = 0.1;    // Interval to check shutdown status

    private bool $shutdownRequested = false;
    private bool $forceExit = false;
    private bool $isShuttingDown = false;
    private array $shutdownCallbacks = [];
    private ?float $shutdownStartTime = null;

    /**
     * Registers signal handlers for SIGTERM and SIGINT
     */
    public function register(): void
    {
        if (!extension_loaded('pcntl')) {
            ShutdownLogger::warning('[SignalHandler] PCNTL extension not loaded, signal handling disabled');
            return;
        }

        // Use Swoole's Process::signal for coroutine-safe signal handling
        Process::signal(SIGTERM, function (int $signo) {
            $this->handleShutdownSignal($signo);
        });

        Process::signal(SIGINT, function (int $signo) {
            $this->handleShutdownSignal($signo);
        });
    }

    /**
     * Unregisters signal handlers
     */
    public function unregister(): void
    {
        if (!extension_loaded('pcntl')) {
            return;
        }

        Process::signal(SIGTERM, null);
        Process::signal(SIGINT, null);
    }

    /**
     * Registers a callback to be executed during shutdown
     *
     * @param string $name Unique name for the callback
     * @param callable $callback Callback to execute (should return void)
     * @param int $priority Priority (lower numbers execute first, default 100)
     */
    public function onShutdown(string $name, callable $callback, int $priority = 100): void
    {
        $this->shutdownCallbacks[$name] = [
            'callback' => $callback,
            'priority' => $priority,
        ];
    }

    /**
     * Checks if shutdown has been requested
     */
    public function isShutdownRequested(): bool
    {
        return $this->shutdownRequested;
    }

    /**
     * Checks if shutdown is in progress
     */
    public function isShuttingDown(): bool
    {
        return $this->isShuttingDown;
    }

    /**
     * Handles shutdown signals (SIGTERM/SIGINT)
     */
    private function handleShutdownSignal(int $signo): void
    {
        if ($this->shutdownRequested) {
            ShutdownLogger::warning('Forcing immediate exit after second signal');
            $this->forceExit = true;
            return;
        }

        $this->shutdownRequested = true;
        ShutdownLogger::info('Shutting down server...');

        // Perform graceful shutdown in a coroutine
        Coroutine::create(function () {
            $this->performGracefulShutdown();
        });
    }

    /**
     * Performs graceful shutdown sequence
     */
    private function performGracefulShutdown(): void
    {
        if ($this->isShuttingDown) {
            return;
        }

        $this->isShuttingDown = true;
        $this->shutdownStartTime = microtime(true);

        // Sort callbacks by priority
        $callbacks = $this->shutdownCallbacks;
        uasort($callbacks, function ($a, $b) {
            return $a['priority'] <=> $b['priority'];
        });

        // Execute shutdown callbacks in priority order
        foreach ($callbacks as $name => $config) {
            $elapsed = microtime(true) - $this->shutdownStartTime;

            if ($elapsed >= self::SHUTDOWN_TIMEOUT) {
                ShutdownLogger::warning("Shutdown timeout reached, skipping remaining callbacks");
                break;
            }

            try {
                $config['callback']();
            } catch (\Throwable $e) {
                ShutdownLogger::error("Error in shutdown callback '{$name}': {$e->getMessage()}");
            }
        }

        $totalTime = microtime(true) - $this->shutdownStartTime;
        ShutdownLogger::info(sprintf('Server shutdown completed in %.3f seconds', $totalTime));

        // Wait briefly for remaining coroutines to complete
        $this->waitForRemainingCoroutines(0.5);

        // Exit the event loop gracefully
        if (class_exists('Swoole\\Event', false) && method_exists('Swoole\\Event', 'exit')) {
            \Swoole\Event::exit();
        }

        // Force exit if requested (second signal received)
        if ($this->forceExit) {
            exit(1);
        }
    }

    /**
     * Waits for in-flight requests to complete
     *
     * @param callable $checkCallback Callback that returns true if requests are still in-flight
     * @param float $maxWaitTime Maximum time to wait in seconds
     */
    public function waitForInflightRequests(callable $checkCallback, float $maxWaitTime = 5.0): void
    {
        $startTime = microtime(true);

        while (microtime(true) - $startTime < $maxWaitTime) {
            if (!$checkCallback()) {
                return;
            }

            Coroutine::sleep(self::CHECK_INTERVAL);
        }

        ShutdownLogger::warning('Request timeout, proceeding with shutdown');
    }

    /**
     * Waits for remaining coroutines to complete
     *
     * @param float $maxWaitTime Maximum time to wait in seconds
     */
    private function waitForRemainingCoroutines(float $maxWaitTime): void
    {
        $startTime = microtime(true);

        while (microtime(true) - $startTime < $maxWaitTime) {
            $stats = Coroutine::stats();
            $coroutines = (int)($stats['coroutine_num'] ?? 0);

            // Allow for 1-2 coroutines (main + maybe shutdown coroutine)
            if ($coroutines <= 2) {
                return;
            }

            Coroutine::sleep(self::CHECK_INTERVAL);
        }

        $stats = Coroutine::stats();
        $remaining = (int)($stats['coroutine_num'] ?? 0);
        if ($remaining > 2) {
            ShutdownLogger::warning("Warning: {$remaining} coroutines still active");
        }
    }
}
