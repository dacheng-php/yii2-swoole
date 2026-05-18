# Yii2-Swoole

Yii2-Swoole 是 Yii2 的 Swoole 协程扩展。安装并配置后，传统 Yii2 应用可以运行在 Swoole HTTP Server 之上，业务代码进入 coroutine-native 运行模式，同时可以直接使用连接池、协程 Redis、协程队列、协程 HTTP 客户端等能力。

它有两个核心目标：

- **运行既有 Yii2 应用**：尽量少改业务代码，把 Yii2 应用迁移到 Swoole 协程 HTTP 服务。
- **提供协程原生组件**：用 Yii2 的组件风格封装 Swoole 协程能力，供业务代码深度使用。

## 能力

- 单进程协程 HTTP Server
- MySQL / Redis 连接池
- 协程安全的 DB、Redis、Cache、Queue、Log、Session、User 组件
- 基于 Swoole 协程的 HTTP Client
- 请求结束自动清理请求状态并归还连接

## 要求

- PHP >= 8.1
- Swoole >= 6.0
- Yii2 >= 2.0

## 安装

```bash
composer require dacheng-php/yii2-swoole
```

## 配置

在应用配置中引入 `Bootstrap`、HTTP Server 和需要协程化的组件：

```php
<?php

return [
    'bootstrap' => [
        [
            'class' => \Dacheng\Yii2\Swoole\Bootstrap::class,
            'componentId' => 'swooleHttpServer',
            'hookFlags' => SWOOLE_HOOK_ALL,
        ],
    ],
    'components' => [
        'swooleHttpServer' => [
            'class' => \Dacheng\Yii2\Swoole\Server\HttpServer::class,
            'host' => '127.0.0.1',
            'port' => 9501,
            'documentRoot' => __DIR__ . '/../web',
            'dispatcher' => new \Dacheng\Yii2\Swoole\Server\RequestDispatcher(__DIR__ . '/web.php'),
            'settings' => [
                'max_coroutine' => 100000,
            ],
        ],
        'db' => [
            'class' => \Dacheng\Yii2\Swoole\Db\CoroutineDbConnection::class,
            'dsn' => 'mysql:host=127.0.0.1;dbname=myapp',
            'username' => 'root',
            'password' => '',
            'charset' => 'utf8mb4',
            'poolMaxActive' => 20,
            'poolWaitTimeout' => 5.0,
        ],
        'redis' => [
            'class' => \Dacheng\Yii2\Swoole\Redis\CoroutineRedisConnection::class,
            'hostname' => '127.0.0.1',
            'port' => 6379,
            'poolMaxActive' => 20,
            'poolWaitTimeout' => 5.0,
        ],
        'cache' => [
            'class' => \Dacheng\Yii2\Swoole\Cache\CoroutineRedisCache::class,
            'redis' => 'redis',
        ],
        'queue' => [
            'class' => \Dacheng\Yii2\Swoole\Queue\CoroutineRedisQueue::class,
            'redis' => 'redis',
            'channel' => 'queue',
            'concurrency' => 10,
        ],
    ],
];
```

如果控制台应用没有自动加载同一份配置，可以手动注册启动命令：

```php
'controllerMap' => [
    'swoole' => [
        'class' => \Dacheng\Yii2\Swoole\Console\SwooleController::class,
        'serverComponent' => 'swooleHttpServer',
    ],
],
```

## 启动

```bash
php yii swoole/start
```

访问：

```text
http://127.0.0.1:9501
```

停止服务：

```bash
php yii swoole/stop
```

## 在业务代码中使用

配置完成后，业务层仍按 Yii2 组件方式访问能力：

```php
$rows = Yii::$app->db->createCommand('SELECT * FROM user WHERE status = :status', [
    ':status' => 1,
])->queryAll();

Yii::$app->redis->set('demo:key', 'value');

Yii::$app->queue->push(new SendEmailJob([
    'email' => 'user@example.com',
]));
```

这些调用运行在 Swoole 协程环境中，并通过对应的协程组件和连接池执行。

## 适用场景

适合：

- API 服务
- 微服务
- 高并发 I/O 密集应用
- 希望保留 Yii2 编程模型，同时使用 Swoole 协程能力的项目

不适合：

- CPU 密集型任务
- 不需要常驻进程的简单低流量站点
- 期望完整替代 Yii2 或完整封装所有 Swoole 能力的项目

## License

MIT
