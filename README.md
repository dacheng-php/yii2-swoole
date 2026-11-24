# Yii2-Swoole

**Yii2-Swoole** 是面向生产环境的 Yii2 × Swoole 6 协程运行时适配层与协程组件扩展包。

让既有 Yii2 应用通过**少量、显式、1:1 镜像 Yii2 风格的配置适配**即可安全、稳定、高性能地运行在常驻 Swoole HTTP Server 之上；拒绝隐式配置篡改与隐藏控制流，保持简洁、直接、实用。

## 核心特性

- **1:1 镜像 Yii2 包结构**：`Web\*`、`Db\*`、`Redis\*`、`Log\*`、`HttpClient\*`、`Queue\*` 直接对应 Yii2 官方命名空间，配以 `Server\*`、`Console\*`、`Coroutine\*`、`Pool\*` 四个基础设施包，单层扁平零冗余。
- **高性能 `SWOOLE_BASE` 协程引擎**：零 Master→Worker IPC 拷贝、内核 `SO_REUSEPORT` 多核负载均衡、`workers=1..N` 弹性伸缩、C 级静态文件与压缩、内置 `reload_async` 无损热重载与 `maxMemoryMb` 内存水位守卫。
- **彻底的协程状态隔离与热路径优化**：
  - `Web\Application` 将 `Yii::$app` 的请求态属性（`$controller`、`$requestedRoute`、`$state`、`$language`、`$layout`、`$loadedModules`、`$homeUrl`）与请求级组件全量协程化。
  - **三层组件模型 + O(1) 共享缓存 + 原型克隆**：共享组件启动期预热并缓存于 `$sharedInstances` O(1) 直达；请求级组件原型克隆免去每请求反射。
- **完整且安全的 Yii2 Web 语义桥接**：
  - `Web\Request` 完全脱离超全局变量，支持 `trustedHosts` 代理头安全过滤、原型期预实例化 `JsonParser` 与 Cookie HMAC 校验。
  - `Web\UploadedFile` 协程化文件上传，消除 `UploadedFile::$_files` 静态竞态。
  - `Web\Response` 完整保留多值 Header、签名 Cookie、大流 `write()` 分块与零拷贝 `sendfile()`。
  - `Web\ErrorHandler` 完整支持 `errorAction` 路由与 JSON/XML/HTML/RAW 渲染。
- **LIFO 弹性连接池（`Pool\ConnectionPool` + `Pool\PoolRegistry`）**：
  - 纯 PHP 内存栈 LIFO 热路径（零 Channel 阻塞调用，热连接局部性最优、冷连接自然过期缩容）；
  - `PooledConnection` 显式状态机杜绝 double-release 双借串话；
  - `Db\Command` 断线即时标记丢弃，`Coroutine::defer()` 自动回滚事务并归还连接。
- **显式、零魔法的 DX 与运维工具链**：
  - 标准 Yii2 `yii` 入口脚本零改动、无隐藏配置重写器；
  - `php yii swoole/{start,stop,reload,status,check}` 完整进程管控与启动前安全审计；
  - `php yii swoole/start --watch` 开发模式文件变更自动平滑重载；
  - `Coroutine\Concurrent` 结构化并发工具箱与 `Server\RuntimeStats` 可观测快照。

## 环境要求

- PHP >= 8.1
- ext-swoole >= 6.0
- yiisoft/yii2 >= 2.0

## 安装

```bash
composer require dacheng-php/yii2-swoole
```

## 快速开始（3 步显式适配）

标准 Yii2 `yii` 入口脚本（`new yii\console\Application($config)`）**无需修改**。只需按以下 3 步显式声明组件类：

### 1. 适配 `config/web.php`

显式指定 `'class' => Web\Application::class`，并将需要协程化/池化的组件替换为对应的协程类（`request`、`response`、`errorHandler`、`user` 已由 `Web\Application::coreComponents()` 默认提供协程安全实现）：

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

### 2. 在 `config/console.php` 显式注册 `SwooleController` 与 `HttpServer`

`Server\HttpServer` 仅配置在 `console.php`，绝不泄漏到 Web Worker 进程：

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
            'host' => '0.0.0.0',
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
# 1. 启动前安全预检（审计 PHP/Swoole 版本、拓扑模式与组件协程安全配置）
php yii swoole/check

# 2. 开发模式启动（代码变更自动平滑重载）
php yii swoole/start --watch --workers=1

# 3. 生产模式启动（指定 Worker 数与监听地址）
php -d opcache.enable_cli=1 yii swoole/start --host=0.0.0.0 --port=9501 --workers=4

# 4. 运维管控
php yii swoole/status   # 查看运行状态与 PID
php yii swoole/reload   # 零停机平滑重载 Worker（SIGUSR1）
php yii swoole/stop     # 优雅停机（SIGTERM，等待在途请求与资源释放）
```

## 业务层协程原生能力

### 结构化并发（`Coroutine\Concurrent`）

自动捕获异常、限制并发度，子协程内借出的 DB/Redis 连接退出即由 `Coroutine::defer()` 自动归还：

```php
use Dacheng\Yii2\Swoole\Coroutine\Concurrent;

$data = Concurrent::run([
    'user'    => fn () => User::findOne($id),
    'profile' => fn () => Yii::$app->redis->get("profile:{$id}"),
    'remote'  => fn () => Yii::$app->httpClient->get('https://api.example.com/info')->send()->data,
], concurrency: 10);
```

### 协程安全文件上传（`Web\UploadedFile`）

```php
use Dacheng\Yii2\Swoole\Web\UploadedFile;

$file = UploadedFile::getInstanceByName('avatar');
if ($file !== null) {
    $file->saveAs('@runtime/uploads/' . $file->baseName . '.' . $file->extension);
}
```

### 运行时指标与连接池监控（`Server\RuntimeStats`）

```php
use Dacheng\Yii2\Swoole\Server\RuntimeStats;

public function actionStats(): array
{
    return RuntimeStats::snapshot();
}
```

## 文档

- [架构设计与显式化演进说明](docs/architecture.md)
- [迁移指南、协程安全规范与运维手册](docs/guide.md)

## License

MIT
