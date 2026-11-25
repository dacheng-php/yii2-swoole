# AGENTS.md

## 项目定位与目标

**Yii2-Swoole** 是面向 PHP 8.1+、Swoole 6.0+ 与 Yii2 2.0 的生产级协程运行时适配层与网络组件扩展。

- **基础目标**：通过少量、显式、1:1 镜像 Yii2 风格的声明式配置，让传统 Yii2 应用安全、稳定、高性能地运行在常驻 Swoole 协程引擎之上，并原生使用连接池、结构化并发、协程队列与协程 HTTP 客户端等能力。
- **演进潜力**：在稳固 HTTP 与 Yii2 组件适配的基础上，依托通用的协程隔离、连接池、进程管控与异步 I/O 基座，支持开发和扩展更多高性能长连接与多协议场景——包括但不限于 **SSE、WebSocket、TCP / UDP Server、Gateway** 等。
- **设计气质**：原汁原味 Yii2 可读可扩展风格 + Zig 式显式化哲学（一切显式、不隐藏、不暗含、简洁直接实用）。

## 非目标

- 不优化 CPU 密集型计算（应卸载至异步队列、独立 Worker 或外部服务）。
- 不支持 PHP < 8.1、Swoole < 6.0。
- 不引入隐式配置重写器、隐藏 `ini_set` 或靠 `argv` 猜测控制流的魔法层。
- 不为了追逐新潮而破坏 Yii2 核心编程模型。

## 架构与权威入口

- `src/`：单层扁平包，严格单向依赖（`console\` → `server\` → 协程基座与组件包；组件层不反向依赖 `server\`）：
  - **运维与引擎层**：`console/`、`server/`（HTTP 及未来可扩展的 WebSocket / TCP / UDP / Gateway 等 Server 与进程管控）。
  - **通用协程基座**：`coroutine/`、`pool/`（跨协议复用的协程上下文隔离、并发原语、LIFO 状态机连接池）。
  - **Yii2 1:1 镜像组件**：`web/`、`db/`、`redis/`、`log/`、`httpclient/`、`queue/`、`debug/`。
- `examples/`：可运行示例与性能对照（`app-api`、`app-basic`、`native-swoole`），不承载核心逻辑。
- `docs/` 与 `README.md`：快速入口见 `README.md`，核心机制与实现细节见 `docs/architecture.md`，迁移与运维见 `docs/guide.md`。架构或配置语义变化时须同步更新文档与示例。

## 核心工程不变量

1. **显式化 & Yii2 优先**
   - 配置即真相，沿用 Yii2 组件、事件、异常与生命周期风格；优先继承 Yii2 基类，避免反射篡改私有状态。
   - 非法配置与运行故障 fail-fast 暴露，不静默吞错，不静默重试非幂等操作。

2. **严格协程隔离**
   - 禁止用静态属性、全局状态或超全局变量（`$_GET`/`$_POST`/`$_COOKIE`/`$_FILES`/`$_SERVER`/`$_SESSION`）保存请求或连接级状态。
   - `Yii::$app` 是 Worker 常驻代理门面，其可变请求态属性与请求级组件必须严格按协程隔离并确定性清理。

3. **严守 Fork 边界**
   - 所有应用实例、连接池、Socket、Timer、后台协程一律延迟至 `onWorkerStart` 之后初始化，绝不在 Master 启动前预建连接或资源。
   - `Server` 组件仅配置在 Console 入口，不进入 Worker 应用上下文。

4. **非阻塞 I/O 与确定性资源回收**
   - 协程内依赖 `SWOOLE_HOOK_ALL` 与协程安全组件，禁止在热路径引入阻塞调用或无界等待。
   - 池化连接与会话资源必须通过 `Coroutine::defer()` 自动兜底归还（覆盖主协程、子协程、异常、超时与长连接断开路径），并防止 double-release。
   - `onWorkerExit` 必须立即清理所有 Timer 与后台协程，保障 `reload_async` 快速无损退出；单请求、单连接或单任务失败不得拖垮整个 Worker。

5. **多协议扩展与清洁演进**
   - 扩展 SSE、WebSocket、TCP/UDP Server 或 Gateway 时，复用 `coroutine/` 与 `pool/` 基座，保持协议层独立、显式、可组合，不污染既有 HTTP 热路径。
   - 架构演进或局部重构时不做历史兼容保护代码，直接将实现、文档与示例更新到目标语义。

## 验证要求

- 代码变更至少执行语法检查（`php -l`）；涉及 Server 或 Web 配置时运行 `php examples/app-api/yii swoole/check`。
- 涉及协程、连接池、长连接、资源释放或并发逻辑时，必须在真实 Swoole 6 运行时验证成功路径、异常路径、超时/耗尽/断连路径及并发隔离与自动归还。
- 无法完整验证时，明确说明原因、替代检查和残余风险，不伪造通过。

## Agent 协作规则

- 默认中文沟通；代码、类名、方法名、配置 key、路径与日志字段保持英文。
- 实质改动前先读相关源码与文档，明确目标、范围与关键假设，不基于猜测改关键路径。
- 保留工作区中未解释的现有改动，不得覆盖、回退或丢弃。
- 交付时简明说明已完成项、实际验证与结果、跳过项和残余风险。
- DO NOT write any tests, even TDD requesting for that.
- ALWAYS working on the main workspace, do not introduce worktree.
