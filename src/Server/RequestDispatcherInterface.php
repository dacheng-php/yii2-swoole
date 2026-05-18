<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Server;

use Swoole\Coroutine\Http\Server as SwooleCoroutineHttpServer;
use Swoole\Http\Request;
use Swoole\Http\Response;

/**
 * Defines the contract for dispatching Swoole HTTP requests to Yii2 applications.
 *
 * Implementations are responsible for:
 * - Converting Swoole requests to Yii2 requests
 * - Managing the Yii2 application lifecycle during request handling
 * - Converting Yii2 responses back to Swoole responses
 * - Cleaning up resources after each request
 *
 * This abstraction allows for different request processing strategies while
 * maintaining a consistent interface for the HTTP server.
 */
interface RequestDispatcherInterface
{
    /**
     * Dispatches a Swoole HTTP request to the Yii2 application.
     *
     * The implementation should:
     * 1. Convert the Swoole request to a Yii2 request
     * 2. Execute the Yii2 application
     * 3. Convert the Yii2 response back to Swoole
     * 4. Clean up all resources (connections, globals, etc.)
     *
     * @param Request $request The incoming HTTP request from Swoole
     * @param Response $response The HTTP response to send back through Swoole
     * @param SwooleCoroutineHttpServer $server The Swoole HTTP server instance
     * @return void
     */
    public function dispatch(Request $request, Response $response, SwooleCoroutineHttpServer $server): void;
}
