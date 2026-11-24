# Yii2-Swoole 架构设计文档

## 1. 设计哲学：Yii2 风格 × Swoole 6 本质 × 显式化控制

Yii2-Swoole 的设计遵循三条互不妥协的原则：

1. **原汁原味 Yii2 风格**：1:1 镜像 Yii2 原生命名空间（`Web\*`、`Db\*`、`Redis\*`、`Log\*`、`HttpClient\*`、`Queue\*`），沿用 Yii2 声明式数组配置、组件生命周期、事件、异常与 `Yii::$app` 编程习惯。
2. **直面 Swoole 6 引擎本质**：尊重 Fork 边界、协程单线程协作式调度、`SWOOLE_BASE` 与 `SWOOLE_PROCESS` 进程拓扑差异、`reload_async` 优雅退出机制与 C 级 HTTP 能力。
3. **显式化（吸收 Zig 编程哲学）**：
   - **配置即真相**：不提供隐式重写用户配置数组的配置魔法（不做静默类替换、不偷改 `ini_set('memory_limit')`、不靠 `argv[1]` 猜测是否开协程）。配置里写什么类，运行时就是什么类，IDE 静态分析与线上行为 100% 一致。
   - **失败即显现（Fail-Fast）**：启动期配置校验与 `warmup()` 遇到非法组件定义立即抛出异常；数据库连接断线立即丢弃坏连接而不静默重试非幂等写操作；`Server\ConfigValidator` 在启动前把所有潜在竞态点明。
   - **所有权与生命周期显式化**：连接池由 `Pool\PoolAwareInterface` 显式声明，每条连接通过 `Pool\PooledConnection` 状态机严格管理 `IN_USE ↔ IDLE → CLOSED` 迁移。

## 2. PHP + Yii2 + Swoole 能力边界

1. **PHP 8.1+ 边界**
   - PHP 无法拦截静态属性访问（`Yii::$app` 永远指向 Worker 进程内同一个对象引用），因此 `Web\Application` 是常驻代理门面，而非每请求替换 `Yii::$app`。
   - 同一 Worker 进程内的所有并发协程共享静态属性与超全局变量（`$_GET`, `$_POST`, `$_COOKIE`, `$_FILES`, `$_SERVER`, `$_SESSION`），业务与组件必须严禁读写它们。
   - 多 Worker 进程之间不共享 PHP 对象内存，跨 Worker 共享数据须使用 Redis 等外部存储。

2. **Yii2 2.0 边界**
   - `yii\web\Application` 默认带有多个请求级可变属性（`$controller`, `$requestedRoute`, `$requestedAction`, `$requestedParams`, `$state`, `$language`, `$layout`, `$loadedModules`, `$_homeUrl`）。
   - `yii\web\Request::resolve()` 原版会直接写入全局 `$_GET`，`yii\web\UploadedFile` 使用私有静态属性 `$_files` 缓存上传文件，已由 `Web\Request` 与 `Web\UploadedFile` 彻底接管；`Web\Request::getHeaders()` 完整保留 Yii2 `filterHeaders()` 与 `trustedHosts` 安全边界。
   - `yii\base\Component::__clone()` 会清空 `$_events` 与 `$_behaviors`，原型克隆机制自动识别包含 `on *` / `as *` 或 `Closure` 工厂的组件定义并回退到完整实例化。
   - `yii\db\Connection` 内置的 `masters`/`slaves` 读写分离依赖共享实例属性缓存，无法在共享组件上安全复用；需读写分离时应显式配置独立组件（如 `db` 与 `dbRead`）。

3. **Swoole 6.0+ 边界**
   - `SWOOLE_HOOK_ALL` 自动将 PDO（mysqlnd/pgsql/sqlite）、PHP Stream（`yii2-redis`）、Native cURL、c-ares DNS 与 `sleep` 转为非阻塞协程 I/O。
   - **Fork 边界铁律**：多 Worker 模式下，任何 Socket、PDO、Redis、Timer、LogWorker 都不得在 Master 进程 `start()` 之前创建；所有 Web 应用与连接池初始化严格延迟至 `onWorkerStart`。`Server\HttpServer` 仅配置在 `console.php`，不进入 Web Worker。
   - **进程拓扑铁律**：`SWOOLE_BASE` 在 `workers = 1` 时运行在单进程模式（无 Manager 进程），不支持 `reload()` 与自动拉起退出的 Worker；需要零停机重载或单 Worker 内存自动回收时，使用 `workers >= 2` 或 `SWOOLE_PROCESS`（`--watch` 单 Worker 开发模式会显式提示并切换为 `SWOOLE_PROCESS`）。
   - **优雅退出铁律**：`reload_async` 启用后，Worker 退出需等待所有协程与定时器清空；`onWorkerExit` 必须立即清理维护 Timer 并停止 `Log\LogWorker`，同时保持连接池开启直至所有在途请求完成，最后在 `onWorkerStop` 统一关闭连接池。

## 3. 10 包扁平分层架构（单向依赖）

```text
Console\    : SwooleController (CLI 运维入口，隔离 yii\console 依赖)
Server\     : HttpServer, RequestHandler, ProcessManager, FileWatcher, ConfigValidator, RuntimeStats
Web\        : Application, Request, Response, UploadedFile, ErrorHandler, User, RequestContext
Coroutine\  : Concurrent, Context, Container, ResettableInterface
Pool\       : ConnectionPool (LIFO), PoolConfig, PooledConnection, PoolAwareInterface, PoolRegistry,
              PoolException, PoolClosedException, PoolCreationException, PoolExhaustedException
Db\         : Connection, Command
Redis\      : Connection, Cache, Session
Log\        : Logger, FileTarget, CoroutineTargetInterface, LogWorker, LogFileRotator
HttpClient\ : Client, Transport
Queue\      : RedisQueue, RedisCommand, RedisStatisticsProvider, Loop
```

依赖方向严格单向：`Console\` → `Server\` → `Web\` / `Coroutine\` / `Pool\` / `Db\` / `Redis\` / `Log\` / `HttpClient\` / `Queue\`；`Web\` 不反向依赖 `Server\`。

## 4. 核心机制说明

### 4.1 `Web\Application` 状态隔离、O(1) 共享直达与原型克隆

1. **属性协程化**：`init()` 中 `unset` 父类请求态属性，使所有读写自动经 `__get`/`__set` 路由至 `Coroutine::getContext()`；`loadedModules` 使用协程专属 `ArrayObject`，100% 兼容 `Module::getInstance()` / `setInstance()`。
2. **共享组件 O(1) 直达**：`warmup()` 在 `onWorkerStart` 预实例化所有已配置的 `sharedComponentIds`（遇到配置错误立即 fail-fast），并将实例缓存在 `$sharedInstances`；请求热路径访问 `Yii::$app->db` / `Yii::$app->redis` / `Yii::$app->urlManager` 仅一次数组命中直接返回。
3. **请求级组件原型克隆 + 确定性清理**：`request`、`response`、`errorHandler` 在 `warmup()` 预建干净原型（含预实例化的无状态 `parsers` 与 `formatters`），每请求按需 `clone` 零反射构造；请求结束在 `clearCoroutineContext()` 中无条件遍历当前协程组件，对所有 `ResettableInterface` 执行 `reset()`。

### 4.2 LIFO 状态机弹性连接池（`Pool\ConnectionPool`）

- **LIFO 内存栈热路径**：空闲连接保存在纯 PHP 栈 `$idleStack`，热路径 `acquire()` / `release()` 仅执行 `array_pop` / `array_push`，零 Swoole Channel C 调用、零阻塞；热连接高频复用，栈底冷连接自然超过 `maxIdleTime` 被精确缩容。
- **独立唤醒队列**：仅在池满需要挂起协程时使用专用 `$waitQueue`（`Channel(1)`），彻底消除幽灵信号对 `idle` 统计的污染。
- **状态机防双借**：`PooledConnection` 严格约束 `IN_USE ↔ IDLE → CLOSED` 状态转换，任何重复 `release()` / `discard()` 均安全幂等，杜绝同一连接被两个协程并发使用。
- **断线即时止损**：`Db\Command` 捕获断线错误立即 `markFailed()` 并丢弃连接；`Redis\Connection` 独立实现无共享状态竞态的 RESP 执行与局部重试，命令结束即刻归还连接池（或 `open()` / `MULTI` 保持至 `close()` / `Coroutine::defer()`）。

### 4.3 安全与多 Worker 稳定性保障

- **HTTP 安全边界**：`Web\Request::getHeaders()` 严格执行 Yii2 `filterHeaders()`，未配置 `trustedHosts` 时绝不信任伪造的 `X-Forwarded-Proto` / `X-Forwarded-For` / `X-Forwarded-Host`；`Web\Response::sendHeaders()` 向 Swoole 传递多值数组避免同名 Header 覆盖。
- **Session 安全与续期**：`Redis\Session::$allowedClasses` 默认 `false` 防止 PHP 反序列化对象注入；`writeOnlyWhenDirty = true` 搭配 `touchTtlOnRead = true`，只读请求仅发送轻量 `EXPIRE` 续期 TTL。
- **多 Worker 日志安全**：`Log\FileTarget` 用协程上下文防重入而非修改共享 `exportInterval`；`Log\LogFileRotator` 通过独立 `.rotate.lock` 文件锁防止多 Worker 并发轮转踩踏；`Log\LogWorker::stop()` 等待在途刷盘协程完成后再排空缓冲。
- **队列原子性**：`Queue\RedisQueue` 使用原子 Lua 脚本迁移过期/延迟任务，并用非阻塞 `SCAN` 替代 `KEYS` 清理队列；`Queue\RedisCommand` 在 worker action 内部显式进入 `Coroutine\run()`，无需替换标准 `yii\console\Application`。
