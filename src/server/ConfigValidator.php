<?php

declare(strict_types=1);

namespace yii2swoole\server;

use yii2swoole\{coroutine, db, debug, httpclient, log, queue, redis, web};
use Throwable;

/**
 * Preflight diagnostics for `php yii swoole/check`.
 *
 * Audits PHP/Swoole runtime capabilities, server topology, and the target Yii2 web
 * configuration for explicit coroutine-safety invariants before starting the server.
 */
final class ConfigValidator
{
    /**
     * @return array<int, array{level:'ok'|'warn'|'error',check:string,message:string}>
     */
    public function validate(HttpServer $server): array
    {
        $results = [];

        // 1. PHP & Swoole versions
        $results[] = version_compare(PHP_VERSION, '8.1.0', '>=')
            ? $this->ok('php', 'PHP ' . PHP_VERSION)
            : $this->error('php', 'PHP >= 8.1.0 is required, found ' . PHP_VERSION);

        if (!extension_loaded('swoole')) {
            $results[] = $this->error('swoole', 'Swoole extension is not loaded.');
            return $results;
        }

        $swooleVersion = (string) phpversion('swoole');
        $results[] = version_compare($swooleVersion, '6.0.0', '>=')
            ? $this->ok('swoole', 'Swoole ' . $swooleVersion)
            : $this->error('swoole', 'Swoole >= 6.0.0 is required, found ' . $swooleVersion);

        $opcacheCli = extension_loaded('Zend OPcache') && filter_var(ini_get('opcache.enable_cli'), FILTER_VALIDATE_BOOL);
        $results[] = $opcacheCli
            ? $this->ok('php.opcache_cli', 'opcache.enable_cli is enabled.')
            : $this->warn('php.opcache_cli', 'opcache.enable_cli is disabled; enable it (e.g. php -d opcache.enable_cli=1) for peak resident throughput.');

        // 2. Server topology audit
        $workerNum = $server->resolveWorkerCount();
        $modeLabel = $server->mode === SWOOLE_PROCESS ? 'SWOOLE_PROCESS' : 'SWOOLE_BASE';
        if ($server->mode === SWOOLE_BASE && $workerNum === 1) {
            $results[] = $this->warn(
                'server.topology',
                'SWOOLE_BASE with workers=1 runs without a Manager process: `swoole/reload` and automatic `maxMemoryMb` worker recycling are unavailable. Use workers >= 2 or SWOOLE_PROCESS if hot reload/recycling is needed.'
            );
        } else {
            $results[] = $this->ok('server.topology', "Mode={$modeLabel}, workers={$workerNum}");
        }

        // 3. Load web config
        $config = $this->loadWebConfig($server->webConfig, $results);
        if ($config === null) {
            return $results;
        }

        // 4. Application class (must be explicitly web\Application or subclass)
        $appClass = $config['class'] ?? null;
        if (is_string($appClass) && is_a($appClass, web\Application::class, true)) {
            $results[] = $this->ok('app.class', "Application class: {$appClass}");
        } else {
            $results[] = $this->error(
                'app.class',
                sprintf(
                    'Explicitly set "class" => %s in web config (found "%s").',
                    web\Application::class,
                    (string) ($appClass ?? 'unset')
                )
            );
        }

        // 5. Shared vs request-scoped component collision check
        $sharedIds = $config['sharedComponentIds'] ?? [
            'urlManager', 'i18n', 'formatter', 'security', 'log', 'db', 'redis', 'cache', 'queue', 'httpClient',
        ];
        $requestScopedIds = $config['requestScopedComponentIds'] ?? [
            'request', 'response', 'session', 'user', 'view', 'assetManager', 'errorHandler',
        ];
        $overlap = array_intersect($sharedIds, $requestScopedIds);
        if ($overlap !== []) {
            $results[] = $this->error(
                'app.sharedComponentIds',
                'Request-scoped components listed as shared: ' . implode(', ', $overlap)
            );
        } else {
            $results[] = $this->ok('app.sharedComponentIds', 'No request-scoped components leaked into sharedComponentIds.');
        }

        // 6. Inspect component definitions
        $components = $config['components'] ?? [];
        $this->auditCoreComponent($components, 'request', web\Request::class, $results);
        $this->auditCoreComponent($components, 'response', web\Response::class, $results);
        $this->auditCoreComponent($components, 'errorHandler', web\ErrorHandler::class, $results);

        if (isset($components['user'])) {
            $this->auditCoreComponent($components, 'user', web\User::class, $results);
        }

        if (isset($components['session'])) {
            $sessionDef = $components['session'];
            $hasYii2Redis = class_exists(\yii\redis\Session::class);
            $sessionClass = is_array($sessionDef)
                ? ($sessionDef['class'] ?? ($hasYii2Redis ? redis\Session::class : null))
                : (is_object($sessionDef) ? get_class($sessionDef) : (is_string($sessionDef) ? $sessionDef : null));

            if ($sessionClass === null) {
                $results[] = $this->error(
                    'components.session',
                    'Session component is configured without "class" and "yiisoft/yii2-redis" is not installed. Install "yiisoft/yii2-redis" or explicitly set a coroutine-safe "class".'
                );
            } elseif ($sessionClass === redis\Session::class && !$hasYii2Redis) {
                $results[] = $this->error(
                    'components.session',
                    sprintf('Session component uses %s, which requires installing "yiisoft/yii2-redis".', redis\Session::class)
                );
            } elseif ($hasYii2Redis && is_a($sessionClass, redis\Session::class, true)) {
                $results[] = $this->ok('components.session', "Session uses {$sessionClass}");
            } elseif (is_a($sessionClass, coroutine\ResettableInterface::class, true)) {
                $results[] = $this->ok('components.session', "Session uses coroutine-safe {$sessionClass}");
            } else {
                $results[] = $this->error(
                    'components.session',
                    sprintf('Session component uses "%s"; native PHP $_SESSION is unsafe under concurrency. Set "class" => %s or a custom coroutine-safe %s implementation.', $sessionClass, redis\Session::class, coroutine\ResettableInterface::class)
                );
            }
        }

        foreach ($components as $id => $definition) {
            if ($id === 'session' || !is_array($definition)) {
                continue;
            }
            $class = $definition['class'] ?? null;

            if ($class === \yii\db\Connection::class) {
                $results[] = $this->warn(
                    "components.{$id}",
                    sprintf('Uses unpooled yii\\db\\Connection. Configure "class" => %s for coroutine-safe pooling.', db\Connection::class)
                );
            } elseif ($class === 'yii\redis\Connection') {
                $results[] = $this->warn(
                    "components.{$id}",
                    sprintf('Uses unpooled yii\\redis\\Connection. Configure "class" => %s for coroutine-safe pooling.', redis\Connection::class)
                );
            } elseif ($class === 'yii\redis\Cache') {
                $results[] = $this->warn(
                    "components.{$id}",
                    sprintf('Uses yii\\redis\\Cache. Configure "class" => %s to avoid cross-coroutine replica/hash-tag races.', redis\Cache::class)
                );
            } elseif ($class === 'yii\httpclient\Client') {
                $results[] = $this->warn(
                    "components.{$id}",
                    sprintf('Uses blocking yii\\httpclient\\Client. Configure "class" => %s for non-blocking coroutine HTTP I/O.', httpclient\Client::class)
                );
            } elseif ($class === 'yii\queue\redis\Queue') {
                $results[] = $this->warn(
                    "components.{$id}",
                    sprintf('Uses yii\\queue\\redis\\Queue. Configure "class" => %s for coroutine queue workers.', queue\RedisQueue::class)
                );
            } elseif (in_array($class, [redis\Connection::class, redis\Cache::class, redis\Session::class], true) && !class_exists(\yii\redis\Connection::class)) {
                $results[] = $this->error(
                    "components.{$id}",
                    sprintf('Component uses %s, which requires installing "yiisoft/yii2-redis".', $class)
                );
            } elseif ($class === httpclient\Client::class && !class_exists(\yii\httpclient\Client::class)) {
                $results[] = $this->error(
                    "components.{$id}",
                    sprintf('Component uses %s, which requires installing "yiisoft/yii2-httpclient".', $class)
                );
            } elseif ($class === queue\RedisQueue::class && !class_exists(\yii\queue\cli\Queue::class)) {
                $results[] = $this->error(
                    "components.{$id}",
                    sprintf('Component uses %s, which requires installing "yiisoft/yii2-queue" (^2.3.7).', $class)
                );
            } elseif (is_string($class) && is_a($class, db\Connection::class, true)) {
                if (!empty($definition['masters']) || !empty($definition['slaves'])) {
                    $results[] = $this->error(
                        "components.{$id}",
                        'masters/slaves replication is unsafe on a shared db\\Connection; split into separate explicit components (e.g. "db" and "dbRead").'
                    );
                } else {
                    $results[] = $this->ok("components.{$id}", "Pooled DB uses {$class}");
                }
            }
        }

        // 7. Log targets audit
        if (isset($components['log']['targets']) && is_array($components['log']['targets'])) {
            foreach ($components['log']['targets'] as $idx => $target) {
                $targetClass = is_array($target) ? ($target['class'] ?? null) : (is_object($target) ? get_class($target) : null);
                if (is_string($targetClass) && is_a($targetClass, log\CoroutineTargetInterface::class, true)) {
                    $results[] = $this->ok("log.targets.{$idx}", "Log target uses {$targetClass}");
                } else {
                    $results[] = $this->error(
                        "log.targets.{$idx}",
                        sprintf('Log target "%s" must implement %s.', (string) ($targetClass ?? 'unknown'), log\CoroutineTargetInterface::class)
                    );
                }
            }
        }

        // 8. Document root check
        if ($server->documentRoot !== null) {
            $results[] = is_dir($server->documentRoot)
                ? $this->ok('server.documentRoot', "Static documentRoot ready: {$server->documentRoot}")
                : $this->warn('server.documentRoot', "Configured documentRoot does not exist: {$server->documentRoot}");
        }

        // 9. Debug module audit (if configured)
        $modules = $config['modules'] ?? [];
        if (is_array($modules) && isset($modules['debug'])) {
            $debugDef = $modules['debug'];
            $debugClass = is_array($debugDef)
                ? ($debugDef['class'] ?? null)
                : (is_string($debugDef) ? $debugDef : (is_object($debugDef) ? get_class($debugDef) : null));

            if ($debugClass === 'yii\debug\Module' || $debugClass === '\yii\debug\Module') {
                $results[] = $this->error(
                    'modules.debug',
                    sprintf(
                        'Standard yii\\debug\\Module is unsafe in resident Swoole workers. Use %s instead.',
                        debug\Module::class
                    )
                );
            } elseif (is_string($debugClass) && is_a($debugClass, debug\Module::class, true)) {
                $results[] = $this->ok('modules.debug', "Debug toolbar uses coroutine-safe {$debugClass}");

                if (is_array($debugDef) && isset($debugDef['logTarget'])) {
                    $lt = $debugDef['logTarget'];
                    $ltClass = is_array($lt) ? ($lt['class'] ?? debug\LogTarget::class) : (is_string($lt) ? $lt : (is_object($lt) ? get_class($lt) : null));
                    if (!is_string($ltClass) || !is_a($ltClass, log\CoroutineTargetInterface::class, true)) {
                        $results[] = $this->error(
                            'modules.debug.logTarget',
                            sprintf('Debug logTarget "%s" must implement %s.', (string) $ltClass, log\CoroutineTargetInterface::class)
                        );
                    }
                }

                if (defined('YII_ENV_PROD') && YII_ENV_PROD) {
                    $results[] = $this->warn(
                        'modules.debug.env',
                        'Debug module is enabled while YII_ENV_PROD is true; disable it in production for security and performance.'
                    );
                }

                if ($server->documentRoot === null) {
                    $results[] = $this->warn(
                        'modules.debug.assets',
                        'Debug module is enabled without server.documentRoot; ensure /assets static files are served by Swoole or a reverse proxy.'
                    );
                }
            }
        }

        return $results;
    }

    /**
     * @param array<int, array{level:'ok'|'warn'|'error',check:string,message:string}> $results
     */
    public static function hasErrors(array $results): bool
    {
        foreach ($results as $item) {
            if ($item['level'] === 'error') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $components
     * @param array<int, array{level:'ok'|'warn'|'error',check:string,message:string}> $results
     */
    private function auditCoreComponent(array $components, string $id, string $expectedClass, array &$results): void
    {
        if (!isset($components[$id])) {
            $results[] = $this->ok("components.{$id}", "Defaults to {$expectedClass} via web\\Application::coreComponents().");
            return;
        }

        $def = $components[$id];
        $class = is_array($def) ? ($def['class'] ?? $expectedClass) : (is_object($def) ? get_class($def) : null);
        if (is_string($class) && is_a($class, $expectedClass, true)) {
            $results[] = $this->ok("components.{$id}", "Uses {$class}");
        } else {
            $results[] = $this->error(
                "components.{$id}",
                sprintf('Component "%s" uses "%s" instead of %s.', $id, (string) $class, $expectedClass)
            );
        }
    }

    /**
     * @param string|array<string, mixed>|null $webConfig
     * @param array<int, array{level:'ok'|'warn'|'error',check:string,message:string}> $results
     * @return array<string, mixed>|null
     */
    private function loadWebConfig(string|array|null $webConfig, array &$results): ?array
    {
        if (is_array($webConfig)) {
            $results[] = $this->ok('server.webConfig', 'Loaded inline webConfig array.');
            return $webConfig;
        }

        if (!is_string($webConfig) || !is_file($webConfig)) {
            $results[] = $this->error('server.webConfig', 'Web config file not found: ' . (string) $webConfig);
            return null;
        }

        try {
            $loaded = require $webConfig;
        } catch (Throwable $e) {
            $results[] = $this->error('server.webConfig', "Failed to load webConfig: {$e->getMessage()}");
            return null;
        }

        if (!is_array($loaded)) {
            $results[] = $this->error('server.webConfig', "Web config file must return an array: {$webConfig}");
            return null;
        }

        $results[] = $this->ok('server.webConfig', "Loaded webConfig: {$webConfig}");

        return $loaded;
    }

    /**
     * @return array{level:'ok',check:string,message:string}
     */
    private function ok(string $check, string $message): array
    {
        return ['level' => 'ok', 'check' => $check, 'message' => $message];
    }

    /**
     * @return array{level:'warn',check:string,message:string}
     */
    private function warn(string $check, string $message): array
    {
        return ['level' => 'warn', 'check' => $check, 'message' => $message];
    }

    /**
     * @return array{level:'error',check:string,message:string}
     */
    private function error(string $check, string $message): array
    {
        return ['level' => 'error', 'check' => $check, 'message' => $message];
    }
}
