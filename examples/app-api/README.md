# app-api Example

API example application demonstrating **yii2-swoole** extension features.

## What is yii2-swoole?

A Yii2 extension that provides:
- **Multi-worker `SWOOLE_BASE` Coroutine HTTP Server** - IPC-free multi-core scaling with `SO_REUSEPORT`, `reload_async`, and memory high-watermark recycling
- **LIFO Connection Pools** - Coroutine-safe database and Redis connection pooling with automatic `Coroutine::defer()` release
- **Coroutine-safe Components** - 1:1 Yii2-mirrored Web, Db, Redis, Cache, Session, HttpClient, Queue, and Log components
- **Structured Concurrency & Observability** - `Coroutine\Concurrent`, `Server\ConfigValidator`, and `Server\RuntimeStats`

## Quick Start

### Installation

```bash
# Install dependencies
composer install

# Make console executable
chmod +x yii
```

### Preflight Check

```bash
php yii swoole/check
```

### Start Server

```bash
# Development mode (auto reload on code changes)
php yii swoole/start --watch --workers=1

# Production mode (multi-worker SWOOLE_BASE with CLI OPcache)
php -d opcache.enable_cli=1 yii swoole/start
```

Server runs on `http://127.0.0.1:9501`

### Operations

```bash
php yii swoole/status   # Check status & PID
php yii swoole/reload   # Graceful worker reload (SIGUSR1)
php yii swoole/stop     # Graceful shutdown (SIGTERM)
```

## Demo Endpoints

### Basic Features
```bash
# Homepage
curl http://localhost:9501/

# Request info
curl http://localhost:9501/site/test

# Cookie management
curl -c cookies.txt http://localhost:9501/site/set-cookie
curl -b cookies.txt http://localhost:9501/site/get-cookie

# Non-blocking coroutine sleep
curl http://localhost:9501/site/sleep?seconds=2
```

### Health Check
```bash
# Comprehensive health check (all services)
curl http://localhost:9501/health

# Liveness probe (application running)
curl http://localhost:9501/health/live

# Readiness probe (ready to serve traffic)
curl http://localhost:9501/health/ready

# Runtime telemetry snapshot (Server\RuntimeStats)
curl http://localhost:9501/health/stats
```

### Connection Pools

**Redis Pool:**
```bash
# Set/Get with connection pooling
curl http://localhost:9501/redis/set?key=test&value=hello
curl http://localhost:9501/redis/get?key=test

# Pool statistics
curl http://localhost:9501/redis/stats

# Concurrent connections test
curl http://localhost:9501/redis/concurrent?count=100
```

**Database Pool:**
```bash
# User query (uses DB pool)
curl http://localhost:9501/user/view?id=1

# Session management (uses Redis pool)
curl http://localhost:9501/session/counter
```

### Cache System
```bash
# Basic cache operations
curl http://localhost:9501/cache

# Check cache existence
curl http://localhost:9501/cache/exists

# Multi-set and multi-get
curl http://localhost:9501/cache/multiple

# Add operation (only set if not exists)
curl http://localhost:9501/cache/add

# Delete cache
curl http://localhost:9501/cache/delete

# Cache with dependencies
curl http://localhost:9501/cache/dependency

# Cache expiration test
curl http://localhost:9501/cache/expire

# Performance benchmark
curl http://localhost:9501/cache/benchmark
```

### HTTP Client
```bash
# Simple GET request
curl http://localhost:9501/http-client

# POST request with JSON
curl http://localhost:9501/http-client/post

# Batch requests (parallel execution)
curl http://localhost:9501/http-client/batch

# Custom headers
curl http://localhost:9501/http-client/headers

# Timeout handling
curl http://localhost:9501/http-client/timeout

# SSL/HTTPS request
curl http://localhost:9501/http-client/ssl
```

### Queue System
```bash
# Push job to queue
curl http://localhost:9501/queue/push?message=test

# Queue statistics
curl http://localhost:9501/queue/stats

# Batch push
curl http://localhost:9501/queue/push-batch?count=10
```

### Log Worker
```bash
# Generate test logs
curl http://localhost:9501/log/test

# Log worker statistics
curl http://localhost:9501/log/stats

# Stress test (1000 log entries)
curl http://localhost:9501/log/stress?count=1000
```

### File Upload & Download (`Web\UploadedFile`)
```bash
# List uploaded files & endpoints
curl http://localhost:9501/upload

# Single file upload (with optional form field)
curl -X POST \
  -F "file=@README.md" \
  -F "note=hello swoole" \
  http://localhost:9501/upload/single

# Multiple files upload
curl -X POST \
  -F "files[]=@README.md" \
  -F "files[]=@composer.json" \
  http://localhost:9501/upload/multiple

# Yii2 Model + FileValidator upload (single or multiple)
curl -X POST \
  -F "UploadForm[file]=@README.md" \
  -F "UploadForm[note]=model upload" \
  http://localhost:9501/upload/model

curl -X POST \
  -F "UploadForm[files][]=@README.md" \
  -F "UploadForm[files][]=@composer.json" \
  http://localhost:9501/upload/model

# Download an uploaded file via Web\Response::sendFile()
curl -O -J "http://localhost:9501/upload/download?name=<stored_name>"

# Cleanup uploaded demo files
curl -X POST http://localhost:9501/upload/cleanup
```

## Architecture

```
┌──────────────────────────────────────────────────────────┐
│  Swoole HTTP Server (SWOOLE_BASE, multi-worker)          │
├──────────────────────────────────────────────────────────┤
│  Worker Process (onWorkerStart)                          │
│  ├─ Request → Coroutine → Web\Application → Response     │
│  ├─ Connection Pools (per-worker LIFO):                  │
│  │  ├─ Db\Connection Pool (default maxActive: 10)        │
│  │  └─ Redis\Connection Pool (default maxActive: 20)     │
│  └─ Async Log\LogWorker (buffered flush + rotation lock) │
├──────────────────────────────────────────────────────────┤
│  Separate Console Worker: php yii queue/listen           │
│  └─ Queue\RedisQueue (Coroutine\run + bounded workers)   │
└──────────────────────────────────────────────────────────┘
```

## Configuration

Key configuration files:
- `config/common.php` - Shared components (`db`, `redis`, `cache`, `httpClient`, `queue`)
- `config/web.php` - Web application (`Web\Application`, `request`, `response`, `session`, `user`, `log`, `urlManager`)
- `config/console.php` - Console commands & `Server\HttpServer` (strictly kept out of Web workers)

### Environment Variables

```bash
# Swoole HTTP Server
SWOOLE_HOST=127.0.0.1
SWOOLE_PORT=9501
SWOOLE_WORKERS=0

# Redis
YII_REDIS_HOST=127.0.0.1
YII_REDIS_PORT=6379
YII_REDIS_DATABASE=0
YII_REDIS_PASSWORD=
YII_REDIS_POOL_MAX_ACTIVE=20
YII_REDIS_POOL_MIN_IDLE=0
YII_REDIS_POOL_WAIT_TIMEOUT=3.0

# Database
YII_DB_DSN="mysql:host=127.0.0.1;dbname=yii2swoole"
YII_DB_USERNAME=root
YII_DB_PASSWORD=
YII_DB_POOL_MAX_ACTIVE=10
YII_DB_POOL_MIN_IDLE=0
YII_DB_POOL_WAIT_TIMEOUT=3.0

# Queue
YII_QUEUE_CONCURRENCY=10

# Session
YII_SESSION_TIMEOUT=1440
```

## Features Demonstrated

| Feature | Controller | Description |
|---------|------------|-------------|
| HTTP Server | `SiteController` | Request handling, cookies, coroutine sleep |
| Health Check | `HealthController` | Monitor application and service health status |
| Redis Pool | `RedisController` | Connection pooling, concurrent access, benchmarks |
| Cache Pool | `CacheController` | Coroutine-safe cache with Redis backend |
| DB Pool | `UserController` | Database connection pooling, user queries |
| Session | `SessionController` | Coroutine-safe session storage via Redis (`Yii::$app->session`) |
| Queue | `QueueController` | Background job processing with coroutines |
| Logging | `LogController` | Async logging with coroutine channels |
| File Upload | `UploadController` | Coroutine-safe single/multiple/model uploads (`Web\UploadedFile`) & download (`sendFile`) |

## Performance Testing

Test concurrent request handling:
```bash
# 100 concurrent sleep requests (all complete in ~2s, not 200s)
for i in {1..100}; do
  curl -s "http://localhost:9501/site/sleep?seconds=2" &
done
wait

# Redis concurrent connections
curl "http://localhost:9501/redis/concurrent?count=1000"

# Queue batch processing
curl "http://localhost:9501/queue/push-batch?count=100"
```

## Requirements

- PHP 8.1+
- Swoole 6.0+
- Redis (for session, queue, cache)
- MySQL (optional, for database examples)

## Learn More

- Main project: [../../README.md](../../README.md)
- Swoole docs: https://wiki.swoole.com/zh-cn/
- Yii2 docs: https://www.yiiframework.com/doc/guide/2.0/
