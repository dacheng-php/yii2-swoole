# Yii2-Swoole 使用与迁移指南

## 1. 传统 Yii2 应用显式迁移清单

Yii2-Swoole 不隐藏配置改写、不替换 `yii` 入口脚本。所有协程组件 1:1 镜像 Yii2 官方命名空间，推荐使用 PHP 命名空间分组导入（`use Dacheng\Yii2\Swoole\{Db, Log, Redis, Web};`）：

1. **适配 `config/web.php`**：
   - 显式声明 `'class' => Web\Application::class`。
   - `request`、`response`、`errorHandler`、`user` 会通过 `Web\Application::coreComponents()` 自动使用协程安全实现；若原配置中显式写了 `'class' => \yii\web\Request::class` 等，请更新为 `Web\Request::class` 等或删去多余的 `class` 键。
   - 若需要 JSON 请求体解析，显式配置 `'parsers' => ['application/json' => \yii\web\JsonParser::class]`。
   - 若应用部署在 Nginx/Envoy 等反向代理后，显式配置 `request.trustedHosts`（与标准 Yii2 一致），否则 `X-Forwarded-*` 头会被默认安全过滤。
   - 组件 1:1 映射表：
     - `yii\web\Application` → `Dacheng\Yii2\Swoole\Web\Application`
     - `yii\db\Connection` → `Dacheng\Yii2\Swoole\Db\Connection`（支持 `'pool' => [...]`）
     - `yii\redis\Connection` → `Dacheng\Yii2\Swoole\Redis\Connection`（支持 `'pool' => [...]`）
     - `yii\redis\Cache` → `Dacheng\Yii2\Swoole\Redis\Cache`
     - `yii\redis\Session` / `yii\web\Session` → `Dacheng\Yii2\Swoole\Redis\Session`
     - `yii\httpclient\Client` → `Dacheng\Yii2\Swoole\HttpClient\Client`
     - `yii\queue\redis\Queue` → `Dacheng\Yii2\Swoole\Queue\RedisQueue`
     - `yii\log\FileTarget` → `Dacheng\Yii2\Swoole\Log\FileTarget`
2. **适配 `config/console.php`**：
   - 显式在 `controllerMap` 注册 `'swoole' => Console\SwooleController::class`。
   - 显式在 `components` 注册 `'swooleHttpServer' => ['class' => Server\HttpServer::class, 'webConfig' => __DIR__ . '/web.php', ...]`。
   - **不要**把 `swooleHttpServer` 放进 `common.php`，确保它只存在于 Console 进程而不进入 Web Worker。
   - 标准 `yii` 入口脚本保持 `new yii\console\Application($config)` **无需改动**；队列消费命令 `php yii queue/listen` 会在 `Queue\RedisCommand` 内部显式启动 `Coroutine\run()`。
3. **运行启动前安全预检**：
   ```bash
   php yii swoole/check
   ```
   根据 `[ERROR]` / `[WARN]` 提示逐项确认。

## 2. 协程安全开发红线

| 禁止做法（PHP-FPM 习惯） | 正确做法（Coroutine-Native） |
| --- | --- |
| 直接读写 `$_GET`, `$_POST`, `$_COOKIE`, `$_FILES`, `$_SERVER` | 使用 `Yii::$app->request` 与 `Web\UploadedFile` |
| 直接读写 `$_SESSION` 或调用 `session_start()` / `session_regenerate_id()` | 使用 `Yii::$app->session`（`Redis\Session`） |
| 直接调用 `header()`, `setcookie()`, `echo`, `exit()` / `die()` | 返回响应数据或操作 `Yii::$app->response`；异常直接 `throw` |
| 用类 `static` 属性或全局数组缓存用户/请求上下文 | 使用 `Coroutine\Context::set()/get()` 或 `Web\RequestContext::current()` |
| 在共享 `Db\Connection` 上配置 `masters`/`slaves` | 显式配置独立组件（如 `db` 与 `dbRead`）各自维护独立连接池 |
| 在请求处理中执行同步 CPU 密集计算 | 卸载到 `Queue\RedisQueue` 或独立计算服务 |
| 在控制器中手动 `new PDO(...)` 不池化 | 统一使用 `Yii::$app->db` / `Yii::$app->redis` 连接池；长耗时操作前可显式 `$db->close()` 提前归还 |
| 在 Session 中依赖反序列化任意 PHP 类 | 保持 `allowedClasses = false`（仅存标量/数组），必要时显式配置允许的类白名单 |

## 3. 连接池配置与容量规划

`Db\Connection` 和 `Redis\Connection` 均通过单一的 `pool` 字段显式配置：

```php
use Dacheng\Yii2\Swoole\Db;

'db' => [
    'class' => Db\Connection::class,
    'dsn' => 'mysql:host=127.0.0.1;dbname=myapp',
    'username' => 'root',
    'password' => '',
    'charset' => 'utf8mb4',
    'pool' => [
        'maxActive' => 20,          // 单 Worker 最大连接数（总连接数上限 = workers × maxActive）
        'minIdle' => 2,             // Worker 启动预热空闲连接数
        'waitTimeout' => 3.0,       // 池满等待超时（秒）
        'maxIdleTime' => 60.0,      // 冷连接最大空闲时间（建议小于数据库 wait_timeout）
        'maxLifetime' => 3600.0,    // 连接最大生命周期（定期轮换防服务端内存碎片）
        'idlePingInterval' => 30.0, // 空闲超过 30s 借出前探活（热连接零额外 RTT）
    ],
],
```

> **容量规划提示**：在多 Worker 模式下，连接池按 Worker 隔离。例如 `workers = 4`、`pool.maxActive = 20` 时，该实例对 MySQL 的最大并发连接数为 `4 × 20 = 80`，请确保小于 MySQL `max_connections` 预留额度。

## 4. 常用运维命令

```bash
# 预检配置
php yii swoole/check

# 开发环境启动（带文件变更自动平滑重载；单 Worker 会自动启用 SWOOLE_PROCESS 以支持 reload）
php yii swoole/start --watch --workers=1

# 生产环境启动（推荐开启 CLI OPcache）
php -d opcache.enable_cli=1 yii swoole/start --host=0.0.0.0 --port=9501 --workers=4

# 后台守护模式启动
php -d opcache.enable_cli=1 yii swoole/start --daemon --workers=4

# 零停机平滑重载（发 SIGUSR1，要求存在 Manager 进程：workers >= 2 或 SWOOLE_PROCESS）
php yii swoole/reload

# 查看运行状态
php yii swoole/status

# 优雅停止（发 SIGTERM，等待在途请求、刷盘日志并关闭全部连接池）
php yii swoole/stop
```

## 5. 日志分流与队列说明

- **三类日志各司其职（默认自动写入 `@runtime/logs/`）**：
  - `@runtime/logs/app.log`：Yii2 业务日志（`Log\FileTarget` + `Log\LogWorker` 异步批量落盘 + 多 Worker 文件锁轮转；`onWorkerExit` / `onWorkerStop` 自动排空刷盘）。
  - `@runtime/logs/swoole.log`：Swoole 引擎关键日志（由 `Server\HttpServer::$logFile` 控制，默认按天轮转 `SWOOLE_LOG_ROTATION_DAILY`；设为 `''` 输出到终端）。
  - `@runtime/logs/error.log`：PHP 运行时错误、Worker 引导失败与 `error_log()` 兜底日志（由 `Server\HttpServer::$errorLogFile` 控制；设为 `''` 沿用 PHP 默认设置）。
- **协程队列**：直接运行 `php yii queue/listen` 或 `php yii queue/run`，`Queue\RedisCommand` 自动进入 Swoole 协程环境，按 `concurrency` 并发消费；过期/延迟任务通过 Redis Lua 原子迁移，每个 Job 在独立子协程中执行并在结束时自动归还 DB/Redis 连接。

## 6. 性能与稳定性推荐参数

| 场景 | 推荐配置 | 原因 |
| --- | --- | --- |
| **高吞吐多核 API（推荐默认）** | `mode => SWOOLE_BASE`、`workers => swoole_cpu_num()`（至少 2）、`maxRequest => 0`、`maxMemoryMb => 256`、`opcache.enable_cli=1` | `SWOOLE_BASE` 多 Worker 零 IPC 拷贝且有 Manager 支持 `reload` 与内存水位自动换血；关闭按请求数盲目重启避免无谓抖动。 |
| **单 Worker 容器需要零断连热重载/内存换血** | `mode => SWOOLE_PROCESS`、`workers => 1`、`maxMemoryMb => 256` | 单 Worker 的 `SWOOLE_BASE` 无 Manager 进程，无法 `reload()` 或重启 Worker；单 Worker 需要热重载或自动换血时应显式选择 `SWOOLE_PROCESS`。 |
| **系统与 Swoole 网络调优** | `settings => ['backlog' => 2048, 'open_tcp_nodelay' => true, 'buffer_output_size' => 4 * 1024 * 1024]` + `php -d memory_limit=512M -d opcache.enable_cli=1` | 内存上限与 OPcache 通过 PHP CLI 参数或 `php.ini` 显式控制，无隐藏 `ini_set` 覆盖。 |
