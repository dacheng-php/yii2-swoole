<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Console;

use Swoole\Coroutine;
use yii\console\Application;
use Dacheng\Yii2\Swoole\Server\HttpServer;

/**
 * Console Application with Swoole Coroutine support
 * 
 * This class wraps the standard console application to run inside a Swoole
 * coroutine environment, enabling async I/O operations for console commands.
 * 
 * Benefits:
 * - Non-blocking database queries
 * - Concurrent Redis/cache operations
 * - Parallel HTTP/API calls
 * - Better performance for I/O-bound tasks
 * 
 * Example usage in console entry script:
 * ```php
 * $config = require __DIR__ . '/config/console.php';
 * $application = new CoroutineApplication($config);
 * $exitCode = $application->run();
 * exit($exitCode);
 * ```
 */
class CoroutineApplication extends Application
{
    /**
     * @var int Swoole coroutine hook flags
     * Default: SWOOLE_HOOK_ALL for maximum compatibility
     */
    public $hookFlags = SWOOLE_HOOK_ALL;

    /**
     * @var bool Whether to enable coroutine support
     * Set to false to run in traditional blocking mode
     */
    public $enableCoroutine = true;

    /**
     * @var array Commands that should NOT run in coroutine context
     * These commands typically create their own event loops (e.g., servers)
     */
    public $excludedCommands = [
        'swoole/start',     // Swoole HTTP server
        'swoole/stop',      // Server control commands
        'swoole/restart',
        'swoole/reload',
        'serve',            // Built-in dev server
    ];

    /**
     * @var bool Whether coroutine environment is active
     */
    private bool $isCoroutineActive = false;

    /**
     * @inheritdoc
     */
    public function run()
    {
        // Check if current command should be excluded from coroutine
        if ($this->shouldExcludeFromCoroutine()) {
            return parent::run();
        }

        if (!$this->enableCoroutine) {
            // Run in traditional blocking mode
            return parent::run();
        }

        if (!extension_loaded('swoole')) {
            echo "Warning: Swoole extension not loaded. Running in blocking mode.\n";
            return parent::run();
        }

        // Check if already in coroutine context
        if (Coroutine::getCid() > 0) {
            // Already in coroutine, just run normally
            $this->isCoroutineActive = true;
            return parent::run();
        }

        // Run inside a new coroutine environment
        $exitCode = 0;
        
        Coroutine\run(function () use (&$exitCode) {
            // Enable coroutine hooks for blocking I/O functions
            Coroutine::set(['hook_flags' => $this->hookFlags]);
            
            $this->isCoroutineActive = true;
            $exitCode = parent::run();
        });

        return $exitCode;
    }

    /**
     * Checks if running in coroutine context
     * 
     * @return bool
     */
    public function isCoroutineEnabled(): bool
    {
        return $this->isCoroutineActive;
    }

    /**
     * Gets current coroutine ID
     * 
     * @return int Coroutine ID, or -1 if not in coroutine
     */
    public function getCoroutineId(): int
    {
        return $this->isCoroutineActive ? Coroutine::getCid() : -1;
    }

    /**
     * Checks if current command should be excluded from coroutine context
     * 
     * @return bool
     */
    protected function shouldExcludeFromCoroutine(): bool
    {
        // Get the route from command line arguments
        $route = $_SERVER['argv'][1] ?? '';
        
        // Check if route matches any excluded command
        foreach ($this->excludedCommands as $excluded) {
            if ($route === $excluded || strpos($route, $excluded . '/') === 0) {
                return true;
            }
        }
        
        return false;
    }

    public function coreComponents()
    {
        return array_merge(parent::coreComponents(), [
            'swooleHttpServer' => ['class' => HttpServer::class],
        ]);
    }
}
