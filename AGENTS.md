# AGENTS.md

## 项目定位

Yii2-Swoole 让传统 Yii2 应用通过少量显式配置适配后，安全、稳定、高效地运行在 PHP Swoole 协程引擎之上。既能无缝运行既有 Yii2 业务，也能以 Yii2 风格直接使用连接池、结构化并发、协程队列和协程 HTTP 客户端等能力。

本项目不是重写 Yii2，也不是做完整 Swoole 框架；它是 Yii2 与 Swoole 协程运行时之间的生产级适配层与协程组件扩展。

## 核心目标

- **显式适配既有 Yii2 应用**：1:1 镜像 Yii2 原生命名空间与声明式配置习惯，吸收 Zig 编程哲学（一切显式化、不隐藏、不暗含、简洁直接实用），拒绝隐式配置篡改与隐藏控制流。
- **最大化释放 Swoole 性能与资源效率**：基于 `Swoole\Http\Server`（默认 `SWOOLE_BASE` 模式 + 协程 + `SO_REUSEPORT`），共享组件 O(1) 缓存直达、请求级组件零反射原型克隆、LIFO 纯内存栈连接池热路径、C 级静态文件与压缩、平滑 `reload_async` 与 `maxMemoryMb` 内存水位守卫。
- **极致安全与稳定**：严格协程状态隔离、`trustedHosts` 代理头过滤、安全反序列化默认值、断线即时丢弃坏连接、多 Worker 日志轮转锁与原子队列迁移。
- **优秀且克制的 DX**：标准 `yii` 入口脚本零改动，提供 `Server\ConfigValidator` 配置预检、`--watch` 开发热重载、CLI 运维管控（`start/stop/reload/status/check`）、`Coroutine\Concurrent` 结构化并发原语和 `Server\RuntimeStats` 可观测快照。
- 保留 Yii2 源码一贯的可读、可理解、可扩展风格。

## 非目标

- 不优化 CPU 密集型计算；CPU 密集型任务应交给异步队列、独立 Worker 或外部服务。
- 不追求覆盖所有 Swoole 协议（如内置 WebSocket/gRPC 框架）；聚焦 HTTP、协程 I/O、连接池与常用 Yii2 组件。
- 不支持 PHP < 8.1、Swoole < 6.0。
- 不引入隐式配置重写器、隐藏 `ini_set` 或靠 `argv` 猜测控制流的魔法层。
- 不为了“更现代”而改写 Yii2 的核心编程模型。

## 目录职责（10 个扁平包，单向依赖）

- `src/`: 核心源码（单层扁平包，无深层子目录嵌套）。
  - `Console/`: `SwooleController`（CLI 运维入口，隔离 `yii\console` 依赖）。
  - `Server/`: `HttpServer` (`SWOOLE_BASE`/`SWOOLE_PROCESS`)、`RequestHandler`、`ProcessManager`、`FileWatcher`、`ConfigValidator`、`RuntimeStats`。
  - `Web/`: `Application`、`Request`、`Response`、`UploadedFile`、`ErrorHandler`、`User`、`RequestContext`（1:1 镜像 `yii\web\*`）。
  - `Coroutine/`: `Concurrent`、`Context`、`Container`、`ResettableInterface`（纯协程并发原语与隔离契约）。
  - `Pool/`: `ConnectionPool` (LIFO)、`PoolConfig`、`PooledConnection`、`PoolAwareInterface`、`PoolRegistry`、`PoolException` 异常族。
  - `Db/`: `Connection`、`Command`（1:1 镜像 `yii\db\*`）。
  - `Redis/`: `Connection`、`Cache`、`Session`（1:1 镜像 `yii\redis\*`）。
  - `Log/`: `Logger`、`FileTarget`、`CoroutineTargetInterface`、`LogWorker`、`LogFileRotator`（1:1 镜像 `yii\log\*`）。
  - `HttpClient/`: `Client`、`Transport`（1:1 镜像 `yii\httpclient\*`）。
  - `Queue/`: `RedisQueue`、`RedisCommand`、`RedisStatisticsProvider`、`Loop`（1:1 镜像 `yii\queue\*`）。
- `examples/`: 可运行示例（`app-api`, `app-basic`, `native-swoole` 基准对照），不应承载核心逻辑。
- `docs/`: 面向使用者和维护者的架构与使用文档，必须与当前实现一致。

## 设计原则

1. **Yii2 优先 + 显式化控制**
   - 沿用 Yii2 的组件、配置、命名、事件、异常、日志与生命周期风格；配置所见即运行所得。
   - 能通过继承 Yii2 基类完成的适配，优先继承；避免反射篡改私有属性。
   - 错误与非法配置 fail-fast 显式暴露，不静默吞错，不静默重试非幂等操作。

2. **严格协程安全与 Fork 边界**
   - 禁止用全局状态、静态属性或超全局变量（`$_GET`/`$_POST`/`$_COOKIE`/`$_FILES`/`$_SERVER`/`$_SESSION`）保存请求级数据。
   - `Yii::$app` 是进程内常驻代理，其请求态属性（`controller`, `requestedRoute`, `requestedAction`, `requestedParams`, `state`, `language`, `layout`, `loadedModules`, `homeUrl`）与请求级组件（`request`, `response`, `session`, `user`, `view`, `assetManager`, `errorHandler`）必须严格按协程隔离。
   - 严守 Fork 边界：Yii2 `Web\Application` 和所有连接池仅在 `onWorkerStart` 之后初始化，绝不在 Master 启动前预建连接；`Server\HttpServer` 仅配置在 `console.php`。

3. **非阻塞 I/O 与自动资源管理**
   - 协程内依赖 `SWOOLE_HOOK_ALL` 与协程安全组件，禁止在热点路径引入阻塞调用或无界等待。
   - 连接借出必须通过 `Coroutine::defer()` 自动兜底归还（覆盖主请求协程、子协程、异常与超时路径），并通过 `PooledConnection` 状态机防止 double-release。
   - `onWorkerExit` 必须立即清理所有周期性 Timer 与后台 `Log\LogWorker`，保障 `reload_async` 快速无损退出。

4. **热点路径极简与故障隔离**
   - 共享组件在 `warmup()` 实例化并缓存于 `$sharedInstances` O(1) 直达；请求级组件采用原型克隆（预实例化无状态 parsers/formatters）绕过每请求反射。
   - 单个请求、协程、连接或队列任务失败不应影响整个 Worker。

5. **架构演进原则**
   - 架构演进或局部重构时，不做兼容保护代码；以代码清洁、职责清晰和长期可维护为目标，直接更新实现、文档和示例到新的架构语义。

## 验证要求

- 涉及代码变更时，至少执行语法检查（`php -l`）和真实 Swoole 运行时并发/故障/生命周期验证。
- 涉及协程、连接池、资源释放的改动，必须验证成功路径、异常路径、超时/耗尽路径与子协程自动归还。
- 无法执行完整验证时，必须说明原因、已做的替代检查和残余风险。

## Agent 工作规则

- 默认用中文与用户沟通；代码、类名、方法名、配置 key、路径和日志字段保持英文。
- 开始实质改动前，先明确目标、范围、非目标和关键假设。
- 修改前读取相关源码和文档，不基于猜测改关键路径。
- 对未解释的现有改动，默认视为用户或协作者工作；不得覆盖、回退或丢弃。
- 交付时说明已完成、已验证、跳过项和残余风险。
- DO NOT write any tests, even TDD requesting for that.
- ALWAYS working on the main workspace, do not introduce worktree.
