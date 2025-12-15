# Yii2-Swoole

Yii2 extension for Swoole: High-performance single-process asynchronous HTTP server with coroutines, database/Redis connection pools, and async job queue for building high-concurrency PHP applications.

## ✨ Features

### 🚀 Core Features
- **Single-Process Coroutine HTTP Server** - No process management overhead
- **Automatic Connection Pooling** - MySQL and Redis connection reuse
- **Async Job Queue** - Redis-based queue with concurrent processing
- **Non-blocking Logging** - Async file logging with channel buffering
- **Coroutine HTTP Client** - Non-blocking HTTP requests with parallel execution
- **Coroutine Components** - Session, User, DB, Redis, Cache all coroutine-safe
- **Graceful Shutdown** - Clean shutdown of pools, workers, and connections
- **Static File Serving** - Built-in support for CSS, JS, images, fonts

### ⚡ Performance Benefits
- **10-100x faster** than traditional PHP-FPM for I/O bound workloads
- **Persistent connections** - Database and Redis connections stay open
- **Zero copy** - Direct memory operations where possible
- **Concurrent processing** - Handle thousands of requests simultaneously

### 🛠️ Developer Experience
- **Drop-in replacement** - Minimal code changes from standard Yii2
- **Full Yii2 compatibility** - Works with existing Yii2 components
- **Easy configuration** - Simple bootstrap and component setup
- **Rich examples** - Complete example applications included

## 📋 Requirements

- PHP >= 8.1
- Swoole >= 6.0
- Yii2 >= 2.0

## 📦 Installation

```bash
composer require dacheng-php/yii2-swoole
```

## 🚀 Quick Start

### 1. Create Configuration

Create `config/swoole.php`:

```php
<?php

return [
    'bootstrap' => [
        [
            'class' => \Dacheng\Yii2\Swoole\Bootstrap::class,
            'componentId' => 'swooleHttpServer',
            'memoryLimit' => '2G',
        ],
    ],
    'components' => [
        // HTTP Server
        'swooleHttpServer' => [
            'class' => \Dacheng\Yii2\Swoole\Server\HttpServer::class,
            'host' => '127.0.0.1',
            'port' => 9501,
            'documentRoot' => __DIR__ . '/../web',
            'settings' => [
                'max_coroutine' => 100000,
                'log_level' => SWOOLE_LOG_WARNING,
            ],
            'dispatcher' => new \Dacheng\Yii2\Swoole\Server\RequestDispatcher(
                __DIR__ . '/web.php'
            ),
        ],
        
        // Database with Connection Pool
        'db' => [
            'class' => \Dacheng\Yii2\Swoole\Db\CoroutineDbConnection::class,
            'dsn' => 'mysql:host=127.0.0.1;dbname=myapp',
            'username' => 'root',
            'password' => '',
            'charset' => 'utf8mb4',
            'poolMaxActive' => 20,
            'poolWaitTimeout' => 5.0,
        ],
        
        // Redis with Connection Pool
        'redis' => [
            'class' => \Dacheng\Yii2\Swoole\Redis\CoroutineRedisConnection::class,
            'hostname' => '127.0.0.1',
            'port' => 6379,
            'database' => 0,
            'poolMaxActive' => 32,
            'poolWaitTimeout' => 3.0,
        ],
        
        // Cache with Redis Pool
        'cache' => [
            'class' => \Dacheng\Yii2\Swoole\Cache\CoroutineRedisCache::class,
            'redis' => 'redis',
        ],
        
        // Async Queue
        'queue' => [
            'class' => \Dacheng\Yii2\Swoole\Queue\CoroutineRedisQueue::class,
            'redis' => 'redis',
            'channel' => 'queue',
            'concurrency' => 10,
        ],
        
        // Async Logging
        'log' => [
            'targets' => [
                [
                    'class' => \Dacheng\Yii2\Swoole\Log\CoroutineFileTarget::class,
                    'levels' => ['error', 'warning'],
                    'logFile' => '@runtime/logs/app.log',
                    'maxFileSize' => 10240, // KB
                    'maxLogFiles' => 5,
                    'enableRotation' => true,
                ],
            ],
        ],
        
        // Coroutine-safe Session
        'session' => [
            'class' => \Dacheng\Yii2\Swoole\Session\CoroutineSession::class,
            'redis' => 'redis',
        ],
        
        // Coroutine-safe User
        'user' => [
            'class' => \Dacheng\Yii2\Swoole\User\CoroutineUser::class,
            'identityClass' => 'app\models\User',
        ],
    ],
];
```

### 2. Update Web Configuration

Modify `config/web.php`:

```php
<?php

$config = [
    'id' => 'my-app',
    'basePath' => dirname(__DIR__),
    'components' => [
        'request' => [
            //... other config
            'baseUrl' => '',
        ],
        'assetManager' => [
            //... other config
            'basePath' => dirname(__DIR__) . '/web/assets',
            'baseUrl' => '/assets',
        ],
    ],
    // ... other config
];

// Merge Swoole config
$swooleConfig = require __DIR__ . '/swoole.php';
$config = \yii\helpers\ArrayHelper::merge($swooleConfig, $config);

return $config;
```

### 3. Start the Server

```bash
php yii swoole/start
```

Your application is now running on `http://127.0.0.1:9501`

### 4. Test It

```bash
curl http://127.0.0.1:9501/
```

## 📖 Documentation

### Configuration

#### HTTP Server Options

```php
'swooleHttpServer' => [
    'class' => \Dacheng\Yii2\Swoole\Server\HttpServer::class,
    'host' => '0.0.0.0',              // Listen address
    'port' => 9501,                   // Listen port
    'documentRoot' => '@webroot',     // Static files directory
    'settings' => [
        'max_coroutine' => 100000,    // Max concurrent coroutines
        'open_tcp_nodelay' => true,   // TCP_NODELAY option
        'tcp_fastopen' => true,       // TCP Fast Open
        'log_level' => SWOOLE_LOG_WARNING,
    ],
    'staticFileExtensions' => [       // MIME types for static files
        'css' => 'text/css',
        'js' => 'application/javascript',
        // ... more types
    ],
],
```

#### Database Pool Options

```php
'db' => [
    'class' => \Dacheng\Yii2\Swoole\Db\CoroutineDbConnection::class,
    'dsn' => 'mysql:host=127.0.0.1;dbname=myapp',
    'poolMaxActive' => 20,            // Max connections in pool
    'poolWaitTimeout' => 5.0,         // Timeout waiting for connection (seconds)
    'enableCoroutinePooling' => true, // Enable/disable pooling
],
```

#### Redis Pool Options

```php
'redis' => [
    'class' => \Dacheng\Yii2\Swoole\Redis\CoroutineRedisConnection::class,
    'hostname' => '127.0.0.1',
    'port' => 6379,
    'poolMaxActive' => 32,            // Max connections in pool
    'poolWaitTimeout' => 3.0,         // Timeout waiting for connection
    'enableCoroutinePooling' => true, // Enable/disable pooling
],
```

#### Cache Options

```php
'cache' => [
    'class' => \Dacheng\Yii2\Swoole\Cache\CoroutineRedisCache::class,
    'redis' => 'redis',               // Reference to redis component
    'keyPrefix' => 'myapp:',          // Optional key prefix
],
```

#### HTTP Client Options

```php
'httpClient' => [
    'class' => \Dacheng\Yii2\Swoole\HttpClient\CoroutineClient::class,
    'baseUrl' => 'https://api.example.com',
    'transport' => [
        'class' => \Dacheng\Yii2\Swoole\HttpClient\CoroutineTransport::class,
        'connectionTimeout' => 3,     // Connection timeout (seconds)
        'requestTimeout' => 10,       // Request timeout (seconds)
        'keepAlive' => true,          // Enable keep-alive connections
    ],
],
```

#### Queue Options

```php
'queue' => [
    'class' => \Dacheng\Yii2\Swoole\Queue\CoroutineRedisQueue::class,
    'redis' => 'redis',
    'channel' => 'queue',
    'concurrency' => 10,              // Number of concurrent workers
    'executeInline' => true,          // Execute in same process (faster)
],
```

#### Logging Options

```php
'log' => [
    'targets' => [
        [
            'class' => \Dacheng\Yii2\Swoole\Log\CoroutineFileTarget::class,
            'levels' => ['error', 'warning'],
            'logFile' => '@runtime/logs/app.log',
            'maxFileSize' => 10240,    // Max file size before rotation (KB)
            'maxLogFiles' => 5,        // Number of rotated files to keep
            'enableRotation' => true,  // Enable log rotation
        ],
    ],
],
```

### Usage Examples

#### Using Database with Pool

```php
// Connection automatically acquired from pool
$users = User::find()->all();

// Connection automatically returned to pool after request
```

#### Using Redis

```php
// Get from pool
$value = Yii::$app->redis->get('key');

// Set value
Yii::$app->redis->set('key', 'value');

// Connection returned to pool automatically
```

#### Using Cache

```php
// Set cache with TTL
Yii::$app->cache->set('key', 'value', 3600);

// Get cache
$value = Yii::$app->cache->get('key');

// Multi-set
Yii::$app->cache->multiSet([
    'user:1' => ['id' => 1, 'name' => 'Alice'],
    'user:2' => ['id' => 2, 'name' => 'Bob'],
], 3600);

// Multi-get
$users = Yii::$app->cache->multiGet(['user:1', 'user:2']);

// Connection pool shared with redis component
```

#### HTTP Client

```php
use Dacheng\Yii2\Swoole\HttpClient\CoroutineClient;

// Create client instance
$client = new CoroutineClient([
    'baseUrl' => 'https://api.example.com',
]);

// GET request
$response = $client->get('users', ['page' => 1])->send();
if ($response->isOk) {
    $data = $response->data;
}

// POST request with JSON
$response = $client->post('users', ['name' => 'John'])
    ->setFormat(CoroutineClient::FORMAT_JSON)
    ->send();

// Batch requests (parallel execution with coroutines)
$requests = [
    'users' => $client->get('users'),
    'posts' => $client->get('posts'),
    'comments' => $client->get('comments'),
];
$responses = $client->batchSend($requests);

// Custom headers
$response = $client->get('protected')
    ->addHeaders([
        'Authorization' => 'Bearer token123',
        'X-Custom-Header' => 'value',
    ])
    ->send();
```

#### Queue Jobs

Define a job:

```php
namespace app\jobs;

use yii\base\BaseObject;
use yii\queue\JobInterface;

class EmailJob extends BaseObject implements JobInterface
{
    public $to;
    public $subject;
    public $body;
    
    public function execute($queue)
    {
        // Send email
        Yii::$app->mailer->compose()
            ->setTo($this->to)
            ->setSubject($this->subject)
            ->setTextBody($this->body)
            ->send();
    }
}
```

Push to queue:

```php
Yii::$app->queue->push(new EmailJob([
    'to' => 'user@example.com',
    'subject' => 'Test',
    'body' => 'Hello!',
]));
```

Process queue:

```bash
# Process all jobs and exit
php yii queue/run

# Listen for new jobs (daemon mode)
php yii queue/listen
```

#### Concurrent Processing

```php
use Swoole\Coroutine;

// Execute multiple operations concurrently
Coroutine::create(function() {
    $user = User::findOne(1);
    // Process user
});

Coroutine::create(function() {
    $posts = Post::find()->all();
    // Process posts
});

// Both queries execute concurrently using connection pool
```

## 🔧 Commands

### Server Commands

```bash
# Start server
php yii swoole/start

# Start on custom host/port
php yii swoole/start 0.0.0.0 8080

# Stop server
php yii swoole/stop
```

### Queue Commands

```bash
# Process waiting jobs once
php yii queue/run

# Listen for jobs continuously
php yii queue/listen

# Check queue status
php yii queue/info

# Clear queue
php yii queue/clear

# Remove specific job
php yii queue/remove <job-id>
```

## 📊 Performance

> TODO: Benchmark data TBD

### When to Use

✅ **Best for:**
- API servers
- Microservices
- High-concurrency applications
- I/O-bound workloads

❌ **Not ideal for:**
- CPU-intensive tasks (use workers instead)
- Simple low-traffic sites
- Shared hosting environments

## 📝 License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## 🙏 Acknowledgments

- [Swoole](https://github.com/swoole/swoole-src) - Coroutine PHP framework
- [Yii2](https://github.com/yiisoft/yii2) - High-performance PHP framework
- [yii2-queue](https://github.com/yiisoft/yii2-queue) - Queue extension for Yii2
- [yii2-redis](https://github.com/yiisoft/yii2-redis) - Redis extension for Yii2

## 💬 Support

- Create an [Issue](https://github.com/dacheng-php/yii2-swoole/issues) for bug reports
- Start a [Discussion](https://github.com/dacheng-php/yii2-swoole/discussions) for questions
- Check [Wiki](https://github.com/dacheng-php/yii2-swoole/wiki) for detailed docs

---

Made with ❤️ by the dacheng-php team

