# app-api Example

API example application demonstrating **yii2-swoole** extension features.

## What is yii2-swoole?

A Yii2 extension that provides:
- **Single-process Coroutine HTTP Server** - High-concurrency request handling without worker processes
- **Connection Pools** - Efficient database and Redis connection management
- **Coroutine-safe Components** - Session, User, Queue, and Log components for concurrent execution
- **Non-blocking I/O** - All I/O operations run in coroutines without blocking

## Quick Start

### Installation

```bash
# Install dependencies
composer install

# Make console executable
chmod +x yii
```

### Start Server

```bash
./yii swoole/start
```

Server runs on `http://127.0.0.1:9501`

### Stop Server

```bash
./yii swoole/stop
# or press Ctrl+C
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

## Architecture

```
┌─────────────────────────────────────────────────┐
│  Swoole Coroutine HTTP Server (Single Process)  │
├─────────────────────────────────────────────────┤
│  Request → Coroutine → Application → Response   │
├─────────────────────────────────────────────────┤
│  Connection Pools:                              │
│  ├─ Database Pool (max: 10 connections)        │
│  └─ Redis Pool (max: 20 connections)           │
├─────────────────────────────────────────────────┤
│  Background Workers:                            │
│  ├─ Queue Worker (coroutine-based)             │
│  └─ Log Worker (coroutine channel)             │
└─────────────────────────────────────────────────┘
```

## Configuration

Key configuration files:
- `config/common.php` - Shared configuration (pools, queue, Swoole server)
- `config/web.php` - Web application (session, user, routes)
- `config/console.php` - Console commands

### Environment Variables

```bash
# Redis
YII_REDIS_HOST=127.0.0.1
YII_REDIS_PORT=6379
YII_REDIS_DATABASE=0
YII_REDIS_POOL_MAX_ACTIVE=20

# Database
YII_DB_DSN="mysql:host=127.0.0.1;dbname=yii2swoole"
YII_DB_USERNAME=root
YII_DB_PASSWORD=
YII_DB_POOL_MAX_ACTIVE=10

# Queue
YII_QUEUE_CONCURRENCY=10

# Log
YII_LOG_BATCH_INTERVAL=100
YII_LOG_MAX_BUFFER_SIZE=100000
YII_LOG_BUFFER_WARNING_THRESHOLD=90000
YII_LOG_MAX_BUFFER_WARNINGS=10

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
