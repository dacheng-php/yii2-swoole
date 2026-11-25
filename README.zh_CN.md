# Yii2-Swoole

![PHP >= 8.1](https://img.shields.io/badge/PHP-%3E%3D8.1-777BB4?logo=php&logoColor=white)
![Swoole >= 6.0](https://img.shields.io/badge/Swoole-%3E%3D6.0-2E8B57)
![Yii2 >= 2.0.45](https://img.shields.io/badge/Yii2-%3E%3D2.0.45-42B983)
![License MIT](https://img.shields.io/badge/License-MIT-blue)

[English](README.md) | **简体中文**

让传统 Yii2 应用通过少量、显式的配置即可稳定运行在常驻 **Swoole 6** 协程引擎之上，同时提供面向协程的并发、连接池与异步组件基座。

**设计理念**：保留原汁原味的 Yii2 编程习惯与声明式配置，一切显式化、零配置魔法——标准 `yii` 入口脚本无需修改，配置里写什么类，运行时就是什么类。

---

## 核心特性

- **1:1 镜像 Yii2 组件**：按 Yii2 原生命名空间提供 `Web`、`Db`、`Redis`、`Log`、`HttpClient`、`Queue` 协程安全实现，迁移成本低、IDE 提示友好。
- **高性能多 Worker HTTP 服务**：基于 Swoole 6 协程引擎，内置静态文件服务、开发期 `--watch` 自动重载、零停机平滑 `reload` 与内存水位保护。
- **彻底的协程状态隔离**：请求态属性与 `request`、`response`、`session`、`user`、`UploadedFile` 严格按协程隔离，告别并发串话与超全局变量污染。
- **内置弹性连接池**：为数据库（PDO）和 Redis 提供协程连接池，支持最小预热、最大并发限制、空闲缩容、借出前健康探活及协程结束自动归还。
- **开箱即用的协程工具集**：内置异步批量日志落盘（多 Worker 安全轮转）、协程 HTTP 客户端、协程 Redis 队列以及结构化并发助手 `Concurrent::run()`。
- **清晰的运维与可观测体验**：提供 `php yii swoole/check` 启动前安全预检、`start / stop / reload / status` 完整 CLI 管控及 `RuntimeStats::snapshot()` 运行时指标快照。

## 环境要求

- PHP >= 8.1（需启用 `ext-mbstring`）
- Swoole >= 6.0（`ext-swoole`）
- Yii2 >= 2.0.45（`yiisoft/yii2`）

## 安装

```bash
composer require dacheng-php/yii2-swoole
```

## 快速上手（3 步接入）

标准 Yii2 `yii` 控制台入口脚本（`new yii\console\Application($config)`）**无需任何改动**。

### 1. 适配 `config/web.php`

将应用类设为 `Web\Application`（`request`、`response`、`errorHandler`、`session`、`user` 已自动默认提供协程安全实现），并按需替换数据库、Redis、缓存、日志等组件：

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

### 2. 在 `config/console.php` 注册 Server 与 CLI 命令

> 注意：`Server\HttpServer` 只配置在 `console.php`，不要放进 `common.php` 或 `web.php`。

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

### 3. 预检并启动服务

```bash
# 1. 启动前配置安全预检（看到 "Preflight check passed." 即就绪）
php yii swoole/check

# 2. 开发模式启动（代码变更自动热重载）
php yii swoole/start --watch --workers=1

# 3. 生产模式启动（建议开启 CLI OPcache）
php -d opcache.enable_cli=1 yii swoole/start --host=0.0.0.0 --port=9501 --workers=4
```

启动后访问 `http://127.0.0.1:9501` 即可验证服务。日常运维命令：

```bash
php yii swoole/status   # 查看运行状态与 PID
php yii swoole/reload   # 零停机平滑重载 Worker（SIGUSR1）
php yii swoole/stop     # 优雅停机（SIGTERM，等待在途请求与资源释放）
```

## 组件对照表

| Yii2 原生组件 | Yii2-Swoole 协程组件 | 说明 |
| --- | --- | --- |
| `yii\web\Application` | `Dacheng\Yii2\Swoole\Web\Application` | 协程隔离应用门面（默认内置协程 `request`/`response`/`errorHandler`/`session`/`user`） |
| `yii\web\UploadedFile` | `Dacheng\Yii2\Swoole\Web\UploadedFile` | 协程安全文件上传（支持单文件、多文件与 Model 表单校验） |
| `yii\db\Connection` | `Dacheng\Yii2\Swoole\Db\Connection` | PDO 协程连接池，自动探活与断线丢弃 |
| `yii\redis\Connection` | `Dacheng\Yii2\Swoole\Redis\Connection` | Redis 协程连接池 |
| `yii\redis\Cache` | `Dacheng\Yii2\Swoole\Redis\Cache` | 协程安全 Redis 缓存 |
| `yii\redis\Session` / `yii\web\Session` | `Dacheng\Yii2\Swoole\Redis\Session` | 协程安全 Redis Session |
| `yii\httpclient\Client` | `Dacheng\Yii2\Swoole\HttpClient\Client` | 非阻塞协程 HTTP 客户端（支持批量并发） |
| `yii\queue\redis\Queue` | `Dacheng\Yii2\Swoole\Queue\RedisQueue` | 协程并发 Redis 队列（`php yii queue/listen`） |
| `yii\log\FileTarget` | `Dacheng\Yii2\Swoole\Log\FileTarget` | 异步批量落盘与多 Worker 安全轮转 |

## 常用能力速览

### 结构化并发（`Coroutine\Concurrent`）

并行拉取多路 I/O，自动限流、捕获异常并回收子协程连接：

```php
use Dacheng\Yii2\Swoole\Coroutine\Concurrent;

$data = Concurrent::run([
    'user'    => fn () => User::findOne($id),
    'profile' => fn () => Yii::$app->redis->get("profile:{$id}"),
    'remote'  => fn () => Yii::$app->httpClient->get('https://api.example.com/info')->send()->data,
], concurrency: 10);
```

### 协程安全文件上传（`Web\UploadedFile`）

完全替代依赖 `$_FILES` 的 `yii\web\UploadedFile`，直接兼容 Yii2 `FileValidator`：

```php
use Dacheng\Yii2\Swoole\Web\UploadedFile;

$file = UploadedFile::getInstanceByName('avatar');
if ($file !== null) {
    $file->saveAs('@runtime/uploads/' . $file->baseName . '.' . $file->extension);
}
```

### 运行时监控快照（`Server\RuntimeStats`）

一行代码即可在健康检查或监控接口中暴露 Worker、请求、协程与全部连接池状态：

```php
use Dacheng\Yii2\Swoole\Server\RuntimeStats;

public function actionStats(): array
{
    return RuntimeStats::snapshot();
}
```

## 示例项目

仓库内置开箱即用的完整示例（已配置为不随 Packagist 分发包打包），可直接运行体验：

- **[examples/app-api](examples/app-api/README.md)**：REST API 综合示例，覆盖连接池、Redis 缓存与 Session、协程 HTTP 客户端、异步队列、异步日志、文件上传/下载及健康检查端点。
- **[examples/app-basic](examples/app-basic)**：基于 `yii2-app-basic` 改造的经典 MVC 页面与表单示例。

快速试跑 `app-api` 示例：

```bash
cd examples/app-api
composer install
php yii swoole/check
php yii swoole/start --watch --workers=1
```

## 进阶文档

- [使用与迁移指南](docs/guide.md) — 传统 Yii2 迁移清单、协程安全开发红线、连接池容量规划与运维参数调优
- [架构设计文档](docs/architecture.md) — 设计哲学、PHP/Yii2/Swoole 边界、10 包分层架构与核心机制剖析

## 演进方向

当前版本聚焦于稳定、高效地以 Swoole 6 驱动传统 Yii2 HTTP 应用及核心协程组件。在此基础上，项目依托通用协程与连接池基座，计划逐步拓展更多高性能网络与实时场景（如 **SSE、WebSocket、TCP / UDP Server、Gateway** 等），相关排期与讨论见 [GitHub Issues](https://github.com/dacheng-php/yii2-swoole/issues)。

## License

本项目基于 [MIT](LICENSE) 协议开源。
