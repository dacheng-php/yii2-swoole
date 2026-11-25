# Yii2-Swoole

![PHP >= 8.1](https://img.shields.io/badge/PHP-%3E%3D8.1-777BB4?logo=php&logoColor=white)
![Swoole >= 6.0](https://img.shields.io/badge/Swoole-%3E%3D6.0-2E8B57)
![Yii2 >= 2.0.45](https://img.shields.io/badge/Yii2-%3E%3D2.0.45-42B983)
![License MIT](https://img.shields.io/badge/License-MIT-blue)

**English** | [简体中文](README.zh_CN.md)

Run traditional Yii2 applications on a resident **Swoole 6** coroutine engine with minimal, explicit configuration — plus a coroutine-native foundation for connection pooling, structured concurrency, async queues, and HTTP clients.

**Philosophy**: Stay true to idiomatic Yii2 conventions and declarative configuration, with everything explicit and zero hidden magic. Your standard `yii` console entry script stays untouched, and what you declare in config is exactly what runs.

---

## Features

- **1:1 Yii2-Mirrored Components**: Drop-in coroutine-safe namespaces (`Web`, `Db`, `Redis`, `Log`, `HttpClient`, `Queue`) that mirror Yii2 core structure for low-friction migration and clean IDE support.
- **Multi-Worker Coroutine HTTP Server**: Built on Swoole 6 with static file serving, `--watch` auto-reload for development, zero-downtime graceful `reload`, and memory high-watermark recycling.
- **Strict Coroutine Isolation**: Request-scoped application properties and `request`, `response`, `session`, `user`, and `UploadedFile` are isolated per coroutine — no cross-request leaks or superglobal pollution.
- **Elastic Connection Pools**: Built-in coroutine pools for PDO databases and Redis with `minIdle` prewarming, `maxActive` bounds, idle shrinking, health checks before checkout, and automatic `Coroutine::defer()` release.
- **Async Ecosystem Out of the Box**: Buffered async file logging with multi-worker safe rotation, non-blocking coroutine HTTP client, coroutine Redis queue worker, and `Concurrent::run()` structured concurrency.
- **Operator-Friendly CLI & Observability**: Preflight safety audit (`php yii swoole/check`), full process lifecycle commands (`start`, `stop`, `reload`, `status`), and `RuntimeStats::snapshot()` runtime telemetry.

## Requirements

- PHP >= 8.1 (with `ext-mbstring`)
- Swoole >= 6.0 (`ext-swoole`)
- Yii2 >= 2.0.45 (`yiisoft/yii2`)

## Installation

```bash
composer require dacheng-php/yii2-swoole
```

## Quick Start (3 Steps)

Your standard Yii2 `yii` console entry script (`new yii\console\Application($config)`) requires **no changes**.

### 1. Adapt `config/web.php`

Set the application class to `Web\Application` (`request`, `response`, `errorHandler`, and `user` default to coroutine-safe implementations automatically; `session` defaults to `Redis\Session` when `yiisoft/yii2-redis` is installed and never falls back to native `$_SESSION`), and swap in pooled/coroutine components as needed:

```php
<?php

use Dacheng\Yii2\Swoole\{Db, Log, Redis, Web};

return [
    'class' => Web\Application::class,
    'id' => 'my-app',
    'basePath' => dirname(__DIR__),
    'bootstrap' => ['log'],
    'components' => [
        'request' => [
            'cookieValidationKey' => 'change-me',
            'parsers' => [
                'application/json' => \yii\web\JsonParser::class,
            ],
        ],
        'db' => [
            'class' => Db\Connection::class,
            'dsn' => 'mysql:host=127.0.0.1;dbname=myapp',
            'username' => 'root',
            'password' => '',
            'charset' => 'utf8mb4',
            'pool' => [
                'maxActive' => 20,
                'minIdle' => 2,
                'waitTimeout' => 3.0,
            ],
        ],
        'redis' => [
            'class' => Redis\Connection::class,
            'hostname' => '127.0.0.1',
            'port' => 6379,
            'pool' => [
                'maxActive' => 20,
                'waitTimeout' => 3.0,
            ],
        ],
        'cache' => [
            'class' => Redis\Cache::class,
            'redis' => 'redis',
        ],
        'session' => [
            'class' => Redis\Session::class,
            'redis' => 'redis',
        ],
        'log' => [
            'targets' => [
                [
                    'class' => Log\FileTarget::class,
                    'levels' => ['error', 'warning'],
                ],
            ],
        ],
    ],
];
```

### 2. Register the Server & CLI Controller in `config/console.php`

> Note: Keep `Server\HttpServer` exclusively in `console.php` — do not place it in `common.php` or `web.php`.

```php
<?php

use Dacheng\Yii2\Swoole\{Console, Server};

return [
    'id' => 'my-console',
    'basePath' => dirname(__DIR__),
    'controllerMap' => [
        'swoole' => [
            'class' => Console\SwooleController::class,
            'serverComponent' => 'swooleHttpServer',
        ],
    ],
    'components' => [
        'swooleHttpServer' => [
            'class' => Server\HttpServer::class,
            'host' => '127.0.0.1',
            'port' => 9501,
            'workers' => 4,
            'webConfig' => __DIR__ . '/web.php',
            'documentRoot' => dirname(__DIR__) . '/web',
        ],
    ],
];
```

### 3. Run Preflight Check & Start the Server

```bash
# 1. Audit your configuration before startup (expect "Preflight check passed.")
php yii swoole/check

# 2. Start in development mode with auto-reload on file changes
php yii swoole/start --watch --workers=1

# 3. Start in production mode (CLI OPcache recommended)
php -d opcache.enable_cli=1 yii swoole/start --host=0.0.0.0 --port=9501 --workers=4
```

Visit `http://127.0.0.1:9501` to verify the server is responding. Common operations commands:

```bash
php yii swoole/status   # Inspect running status and PID
php yii swoole/reload   # Zero-downtime worker reload (SIGUSR1)
php yii swoole/stop     # Graceful shutdown (SIGTERM, drains active requests & pools)
```

## Component Mapping

| Standard Yii2 Component | Yii2-Swoole Equivalent | Notes |
| --- | --- | --- |
| `yii\web\Application` | `Dacheng\Yii2\Swoole\Web\Application` | Coroutine-isolated app facade (defaults `request`, `response`, `errorHandler`, `user`, plus `session` when `yiisoft/yii2-redis` is installed) |
| `yii\web\UploadedFile` | `Dacheng\Yii2\Swoole\Web\UploadedFile` | Coroutine-safe uploads (single, multiple, and Yii2 Model `FileValidator` support) |
| `yii\db\Connection` | `Dacheng\Yii2\Swoole\Db\Connection` | Pooled PDO connection with health checks and broken-connection discard |
| `yii\redis\Connection` | `Dacheng\Yii2\Swoole\Redis\Connection` | Pooled coroutine Redis connection |
| `yii\redis\Cache` | `Dacheng\Yii2\Swoole\Redis\Cache` | Coroutine-safe Redis cache |
| `yii\redis\Session` / `yii\web\Session` | `Dacheng\Yii2\Swoole\Redis\Session` | Coroutine-safe Redis session |
| `yii\httpclient\Client` | `Dacheng\Yii2\Swoole\HttpClient\Client` | Non-blocking coroutine HTTP client with parallel batch requests |
| `yii\queue\redis\Queue` | `Dacheng\Yii2\Swoole\Queue\RedisQueue` | Concurrent coroutine Redis queue (`php yii queue/listen`) |
| `yii\log\FileTarget` | `Dacheng\Yii2\Swoole\Log\FileTarget` | Buffered async file logging with multi-worker lock-safe rotation |
| `yii\debug\Module` | `Dacheng\Yii2\Swoole\Debug\Module` | Coroutine-safe Yii2 Debug Toolbar with built-in `SwoolePanel` (dev only) |

## Usage Highlights

### Structured Concurrency (`Coroutine\Concurrent`)

Run multiple I/O tasks in parallel with bounded concurrency, safe exception capture, and automatic connection release:

```php
use Dacheng\Yii2\Swoole\Coroutine\Concurrent;

$data = Concurrent::run([
    'user'    => fn () => User::findOne($id),
    'profile' => fn () => Yii::$app->redis->get("profile:{$id}"),
    'remote'  => fn () => Yii::$app->httpClient->get('https://api.example.com/info')->send()->data,
], concurrency: 10);
```

### Coroutine-Safe File Uploads (`Web\UploadedFile`)

Replaces `$_FILES`-dependent `yii\web\UploadedFile` while staying compatible with Yii2 `FileValidator`:

```php
use Dacheng\Yii2\Swoole\Web\UploadedFile;

$file = UploadedFile::getInstanceByName('avatar');
if ($file !== null) {
    $file->saveAs('@runtime/uploads/' . $file->baseName . '.' . $file->extension);
}
```

### Runtime Telemetry Snapshot (`Server\RuntimeStats`)

Expose worker, request, coroutine, and pool metrics on any health or monitoring endpoint:

```php
use Dacheng\Yii2\Swoole\Server\RuntimeStats;

public function actionStats(): array
{
    return RuntimeStats::snapshot();
}
```

## Examples

Runnable reference apps are included in this repository (excluded from Packagist distribution archives):

- **[examples/app-api](examples/app-api/README.md)**: REST API showcase covering DB/Redis pools, cache, sessions, coroutine HTTP client, async queue, async logging, file upload/download, and health endpoints.
- **[examples/app-basic](examples/app-basic)**: Classic MVC web app adapted from `yii2-app-basic`.

Try `app-api` locally:

```bash
cd examples/app-api
composer install
php yii swoole/check
php yii swoole/start --watch --workers=1
```

## Documentation

- [Migration & Operations Guide (zh-CN)](docs/guide.md) — Explicit migration checklist, coroutine-safety rules, pool sizing, and production tuning
- [Architecture Design (zh-CN)](docs/architecture.md) — Design philosophy, PHP/Yii2/Swoole boundaries, 10-package layout, and core internals

## Roadmap

Today, Yii2-Swoole focuses on reliably running traditional Yii2 HTTP applications and coroutine components on Swoole 6. Built on its shared coroutine and connection-pool foundation, future plans include expanding into real-time and multi-protocol workloads such as **SSE, WebSocket, TCP / UDP servers, and Gateway** use cases. Track progress and discussions in [GitHub Issues](https://github.com/dacheng-php/yii2-swoole/issues).

## License

Released under the [MIT](LICENSE) License.
