<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Server;

use Swoole\Http\Request;
use Swoole\Http\Response;

/**
 * StaticFileServer handles serving static files in Swoole environment.
 *
 * This class encapsulates all logic for:
 * - Determining if a request should be served as a static file
 * - Securely resolving file paths within document root
 * - Serving files with appropriate headers and caching
 * - Security checks against path traversal attacks
 *
 * MIME type mapping:
 * - Defaults to common web file extensions
 * - Can be customized via constructor
 *
 * Security features:
 * - Null byte rejection
 * - Path traversal (..) detection
 * - Document root boundary enforcement
 * - Case-insensitive path comparison on Windows
 */
class StaticFileServer
{
    /**
     * Default MIME type mapping for common file extensions.
     *
     * @var array<string, string>
     */
    public const DEFAULT_MIME_TYPES = [
        'css' => 'text/css',
        'js' => 'application/javascript',
        'json' => 'application/json',
        'xml' => 'application/xml',
        'map' => 'application/json',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'eot' => 'application/vnd.ms-fontobject',
        'webp' => 'image/webp',
        'txt' => 'text/plain',
        'html' => 'text/html',
        'htm' => 'text/html',
    ];

    /**
     * @var array<string, string> MIME type mapping by extension
     */
    private array $mimeTypes;

    /**
     * @var string|null Real path to document root
     */
    private ?string $documentRoot;

    /**
     * @var string|null Normalized document root for comparison
     */
    private ?string $normalizedDocRoot;

    /**
     * @var int Maximum cache age in seconds (24 hours default)
     */
    private int $maxCacheAge = 86400;

    /**
     * @var bool Whether to send ETag headers
     */
    private bool $enableEtag = true;

    /**
     * Creates a new static file server.
     *
     * @param string|null $documentRoot Path to document root directory
     * @param array<string, string> $mimeTypes Custom MIME type mapping
     * @param int $maxCacheAge Maximum cache age in seconds
     * @param bool $enableEtag Whether to enable ETag support
     */
    public function __construct(
        ?string $documentRoot,
        array $mimeTypes = [],
        int $maxCacheAge = 86400,
        bool $enableEtag = true
    ) {
        $this->mimeTypes = !empty($mimeTypes) ? $mimeTypes : self::DEFAULT_MIME_TYPES;

        if ($documentRoot !== null) {
            $realPath = realpath($documentRoot);
            $this->documentRoot = $realPath !== false ? $realPath : null;
            $this->normalizedDocRoot = $this->normalizePathForComparison($this->documentRoot);
        }
        $this->maxCacheAge = $maxCacheAge;
        $this->enableEtag = $enableEtag;
    }

    /**
     * Attempts to serve a static file for the given request.
     *
     * @param Request $request The Swoole HTTP request
     * @param Response $response The Swoole HTTP response
     * @return bool True if a static file was served, false otherwise
     */
    public function serve(Request $request, Response $response): bool
    {
        if ($this->documentRoot === null) {
            return false;
        }

        $uri = $this->sanitizeUri($request->server['request_uri'] ?? '/');
        if ($uri === null) {
            return false;
        }

        $extension = pathinfo($uri, PATHINFO_EXTENSION);
        if (!$this->isStaticFileExtension($extension)) {
            return false;
        }

        $realPath = $this->resolveSecureFilePath($uri);
        if ($realPath === null) {
            return false;
        }

        return $this->serveFile($realPath, $extension, $request, $response);
    }

    /**
     * Sets the document root for static files.
     *
     * @param string $path Absolute path to document root
     * @return void
     */
    public function setDocumentRoot(string $path): void
    {
        $realPath = realpath($path);
        $this->documentRoot = $realPath !== false ? $realPath : null;
        $this->normalizedDocRoot = $this->normalizePathForComparison($this->documentRoot);
    }

    /**
     * Returns the real document root path.
     *
     * @return string|null
     */
    public function getDocumentRoot(): ?string
    {
        return $this->documentRoot;
    }

    /**
     * Sanitizes and validates request URI for security.
     *
     * Security checks performed:
     * - Null byte rejection (prevents null byte injection)
     * - Path traversal detection (prevents ../ attacks)
     * - Normalization of path separators
     *
     * @param string $uri The raw request URI
     * @return string|null Sanitized URI, or null if rejected
     */
    private function sanitizeUri(string $uri): ?string
    {
        // Remove query string and fragment
        if (($pos = strpos($uri, '?')) !== false) {
            $uri = substr($uri, 0, $pos);
        }
        if (($pos = strpos($uri, '#')) !== false) {
            $uri = substr($uri, 0, $pos);
        }

        // Decode URL-encoded characters to prevent encoded path traversal
        $uri = rawurldecode($uri);

        // Security: Check for null bytes and reject
        if (strpos($uri, "\0") !== false) {
            return null;
        }

        // Normalize path separators and remove consecutive slashes
        $uri = preg_replace('#/+#', '/', $uri);

        // Security: Reject URIs containing path traversal patterns
        if (strpos($uri, '..') !== false) {
            return null;
        }

        return $uri;
    }

    /**
     * Checks if a file extension should be served as static.
     *
     * @param string $extension The file extension (without dot)
     * @return bool True if this extension should be served statically
     */
    private function isStaticFileExtension(string $extension): bool
    {
        return !empty($extension) && isset($this->mimeTypes[$extension]);
    }

    /**
     * Resolves and validates file path, ensuring it's within document root.
     *
     * Performs security boundary checks to prevent directory traversal.
     *
     * @param string $uri The sanitized URI path
     * @return string|null The real file path, or null if invalid
     */
    private function resolveSecureFilePath(string $uri): ?string
    {
        if ($this->documentRoot === false || $this->documentRoot === null) {
            return null;
        }

        $filePath = rtrim($this->documentRoot, '/') . '/' . ltrim($uri, '/');
        $realPath = realpath($filePath);

        if ($realPath === false) {
            return null;
        }

        // Security: Ensure realPath is within documentRoot
        // Uses proper directory boundary checking
        $realPathNormalized = $this->normalizePathForComparison($realPath);
        $realDocRootWithSeparator = rtrim($this->normalizedDocRoot ?? '', DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        if (strpos($realPathNormalized . DIRECTORY_SEPARATOR, $realDocRootWithSeparator) !== 0) {
            return null;
        }

        if (!is_file($realPath) || !is_readable($realPath)) {
            return null;
        }

        return $realPath;
    }

    /**
     * Serves a static file with appropriate headers.
     *
     * Features:
     * - MIME type detection
     * - ETag support for caching
     * - Last-Modified header
     * - Cache-Control header
     * - 304 Not Modified responses
     *
     * @param string $realPath Absolute path to the file
     * @param string $extension File extension for MIME type
     * @param Request $request The HTTP request (for cache headers)
     * @param Response $response The HTTP response
     * @return bool True if file was served successfully
     */
    private function serveFile(string $realPath, string $extension, Request $request, Response $response): bool
    {
        $content = file_get_contents($realPath);
        if ($content === false) {
            return false;
        }

        $lastModified = filemtime($realPath);
        $eTag = $this->enableEtag ? $this->generateETag($realPath, $lastModified) : null;

        // Check if client has cached version
        $ifModifiedSince = $request->header['if-modified-since'] ?? null;
        $ifNoneMatch = $request->header['if-none-match'] ?? null;

        // Check ETag first (stronger validation)
        if ($this->enableEtag && $ifNoneMatch !== null && $ifNoneMatch === $eTag) {
            $response->status(304);
            $response->header('Cache-Control', 'public, max-age=' . $this->maxCacheAge);
            $response->end();
            return true;
        }

        // Fallback to If-Modified-Since
        if ($ifModifiedSince === null || strtotime($ifModifiedSince) < $lastModified) {
            $this->sendFileHeaders($response, $extension, $lastModified, $eTag, strlen($content));
            $response->status(200);
            $response->end($content);
            return true;
        }

        // Client has cached version
        $response->status(304);
        $response->header('Cache-Control', 'public, max-age=' . $this->maxCacheAge);
        $response->end();
        return true;
    }

    /**
     * Sends all appropriate headers for a file response.
     *
     * @param Response $response The Swoole response
     * @param string $extension File extension
     * @param int $lastModified File modification time
     * @param string|null $eTag ETag value
     * @param int $contentLength Content length
     */
    private function sendFileHeaders(Response $response, string $extension, int $lastModified, ?string $eTag, int $contentLength): void
    {
        $mimeType = $this->mimeTypes[$extension] ?? 'application/octet-stream';

        $response->header('Content-Type', $mimeType);
        $response->header('Content-Length', (string) $contentLength);
        $response->header('Last-Modified', gmdate('D, d M Y H:i:s', $lastModified) . ' GMT');
        $response->header('Cache-Control', 'public, max-age=' . $this->maxCacheAge);
        $response->header('X-Content-Type-Options', 'nosniff');

        if ($eTag !== null) {
            $response->header('ETag', $eTag);
        }
    }

    /**
     * Generates an ETag for a file.
     *
     * Uses modification time and file size for a weak ETag.
     *
     * @param string $realPath Path to the file
     * @param int $lastModified File modification timestamp
     * @return string The ETag value
     */
    private function generateETag(string $realPath, int $lastModified): string
    {
        $fileSize = filesize($realPath);
        return sprintf('"%x-%x"', $lastModified, $fileSize);
    }

    /**
     * Normalizes a path for security comparison.
     *
     * Handles case-insensitive comparison on Windows and resolves
     * any path inconsistencies.
     *
     * @param string $path The absolute path to normalize
     * @return string Normalized path for comparison
     */
    private function normalizePathForComparison(string $path): string
    {
        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);

        // On Windows, use uppercase for case-insensitive comparison
        if (DIRECTORY_SEPARATOR === '\\') {
            $normalized = strtoupper($normalized);
        }

        return $normalized;
    }
}
