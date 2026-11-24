<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Console;

use Dacheng\Yii2\Swoole\Server\ConfigValidator;
use Dacheng\Yii2\Swoole\Server\HttpServer;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\di\Instance;
use yii\helpers\Console;

/**
 * CLI operations controller for Yii2-Swoole HTTP Server.
 *
 * Commands:
 * - php yii swoole/start [--host=127.0.0.1] [--port=9501] [--workers=4] [--watch] [--daemon]
 * - php yii swoole/stop
 * - php yii swoole/reload
 * - php yii swoole/status
 * - php yii swoole/check
 */
class SwooleController extends Controller
{
    public string $serverComponent = 'swooleHttpServer';

    public ?string $host = null;

    public ?int $port = null;

    public ?int $workers = null;

    public ?string $mode = null;

    public bool $watch = false;

    public bool $daemon = false;

    private ?HttpServer $server = null;

    public function options($actionID): array
    {
        $common = array_merge(parent::options($actionID), ['serverComponent']);

        if ($actionID === 'start') {
            return array_merge($common, ['host', 'port', 'workers', 'mode', 'watch', 'daemon']);
        }

        return $common;
    }

    public function optionAliases(): array
    {
        return array_merge(parent::optionAliases(), [
            'c' => 'serverComponent',
            'h' => 'host',
            'p' => 'port',
            'w' => 'workers',
            'd' => 'daemon',
        ]);
    }

    /**
     * Starts the Swoole HTTP server.
     */
    public function actionStart(?string $host = null, ?string $port = null): int
    {
        $server = $this->resolveServer();
        if ($server === null) {
            return ExitCode::CONFIG;
        }

        $listenHost = $host ?? $this->host ?? $server->host;
        $server->host = $listenHost;

        $listenPort = $port ?? $this->port ?? $server->port;
        if (!is_numeric($listenPort) || (int) $listenPort < 1 || (int) $listenPort > 65535) {
            $this->stderr("Invalid port: must be an integer between 1 and 65535.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }
        $server->port = (int) $listenPort;

        if ($this->workers !== null) {
            if ($this->workers < 1) {
                $this->stderr("Invalid --workers: must be >= 1.\n", Console::FG_RED);
                return ExitCode::USAGE;
            }
            $server->workers = $this->workers;
        }

        if ($this->mode !== null) {
            $server->mode = match (strtolower($this->mode)) {
                'base' => SWOOLE_BASE,
                'process' => SWOOLE_PROCESS,
                default => throw new \yii\console\Exception("Unknown mode '{$this->mode}'. Supported: base, process."),
            };
        }

        if ($this->watch) {
            $server->watch = true;
        }

        if ($this->daemon) {
            $server->daemonize = true;
        }

        try {
            $server->start();
        } catch (\Throwable $e) {
            $this->stderr("Failed to start server: {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    /**
     * Gracefully stops the running Swoole HTTP server via SIGTERM.
     */
    public function actionStop(): int
    {
        $server = $this->resolveServer();
        if ($server === null) {
            return ExitCode::CONFIG;
        }

        $pm = $server->getProcessManager();
        $status = $pm->status();
        if (!$status['running']) {
            $this->stdout("Swoole HTTP server is not running.\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        $this->stdout(sprintf("Stopping Swoole HTTP server (PID %d)...\n", $status['pid']));
        if ($pm->stop()) {
            $this->stdout("Swoole HTTP server stopped gracefully.\n", Console::FG_GREEN);
            return ExitCode::OK;
        }

        $this->stderr("Timed out waiting for Swoole HTTP server to stop.\n", Console::FG_RED);
        return ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Gracefully reloads worker processes via SIGUSR1 without dropping active requests.
     */
    public function actionReload(): int
    {
        $server = $this->resolveServer();
        if ($server === null) {
            return ExitCode::CONFIG;
        }

        $pm = $server->getProcessManager();
        $status = $pm->status();
        if (!$status['running']) {
            $this->stderr("Swoole HTTP server is not running.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        if ($pm->reload()) {
            $this->stdout(sprintf("Reload signal (SIGUSR1) sent to server PID %d.\n", $status['pid']), Console::FG_GREEN);
            return ExitCode::OK;
        }

        $this->stderr("Failed to send reload signal.\n", Console::FG_RED);
        return ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Shows current server status and PID.
     */
    public function actionStatus(): int
    {
        $server = $this->resolveServer();
        if ($server === null) {
            return ExitCode::CONFIG;
        }

        $status = $server->getProcessManager()->status();
        if ($status['running']) {
            $this->stdout(sprintf(
                "Status: RUNNING (PID %d, pidFile=%s, target=http://%s:%d)\n",
                $status['pid'],
                $status['pid_file'],
                $server->host,
                $server->port
            ), Console::FG_GREEN);
            return ExitCode::OK;
        }

        $this->stdout(sprintf("Status: STOPPED (pidFile=%s)\n", $status['pid_file']), Console::FG_YELLOW);
        return ExitCode::OK;
    }

    /**
     * Audits PHP/Swoole runtime and Yii2 web configuration for coroutine safety.
     */
    public function actionCheck(): int
    {
        $server = $this->resolveServer();
        if ($server === null) {
            return ExitCode::CONFIG;
        }

        $validator = new ConfigValidator();
        $results = $validator->validate($server);

        foreach ($results as $item) {
            [$tag, $color] = match ($item['level']) {
                'ok' => ['[OK]   ', Console::FG_GREEN],
                'warn' => ['[WARN] ', Console::FG_YELLOW],
                'error' => ['[ERROR]', Console::FG_RED],
            };
            $this->stdout("{$tag} ", $color);
            $this->stdout(sprintf("%-26s %s\n", $item['check'], $item['message']));
        }

        if (ConfigValidator::hasErrors($results)) {
            $this->stderr("\nPreflight check failed. Fix the [ERROR] items above before starting the server.\n", Console::FG_RED);
            return ExitCode::CONFIG;
        }

        $this->stdout("\nPreflight check passed.\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    protected function resolveServer(): ?HttpServer
    {
        if ($this->server !== null) {
            return $this->server;
        }

        if (!Yii::$app->has($this->serverComponent)) {
            $this->stderr(sprintf("Server component '%s' is not configured.\n", $this->serverComponent), Console::FG_RED);
            return null;
        }

        /** @var HttpServer $server */
        $server = Instance::ensure(Yii::$app->get($this->serverComponent), HttpServer::class);

        return $this->server = $server;
    }
}
