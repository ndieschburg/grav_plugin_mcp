<?php

declare(strict_types=1);

namespace Grav\Plugin\Mcp;

use Grav\Common\Grav;
use Grav\Plugin\Mcp\Security\ApiKeyAuth;
use Grav\Plugin\Mcp\Security\RateLimiter;
use Mcp\Server\Server;
use Mcp\Server\HttpServerRunner;
use Mcp\Server\Transport\Http\StandardPhpAdapter;
use Mcp\Server\Transport\Http\FileSessionStore;
use Psr\Log\LoggerInterface;

/**
 * MCP HTTP Controller
 * Handles incoming MCP requests via HTTP using the logiscape/mcp-sdk-php
 *
 * Security features:
 * - API key authentication with timing-safe comparison
 * - Per-user and per-IP rate limiting
 * - Brute-force protection (blocks IPs after failed attempts)
 * - Security headers (CORS, CSP, etc.)
 */
class McpController
{
    protected Grav $grav;
    protected array $config;
    protected ApiKeyAuth $auth;
    protected RateLimiter $rateLimiter;
    protected ?LoggerInterface $logger;

    public function __construct(Grav $grav)
    {
        $this->grav = $grav;
        $this->config = $grav['config']->get('plugins.mcp', []);
        $this->auth = new ApiKeyAuth($grav, $this->config);
        $this->rateLimiter = new RateLimiter($this->config);
        $this->logger = null;
    }

    /**
     * Handle incoming MCP request
     */
    public function handle(): void
    {
        // Set security headers first (before any output)
        $this->sendSecurityHeaders();

        // Set JSON content type
        header('Content-Type: application/json');

        // Get client IP for rate limiting
        $clientIp = $this->getClientIp();

        // Check IP-based rate limit BEFORE authentication (prevents brute-force)
        $ipRateLimit = $this->rateLimiter->checkIp($clientIp);
        if (!$ipRateLimit['allowed']) {
            http_response_code(429);
            echo json_encode([
                'error' => 'Too Many Requests',
                'message' => 'Rate limit exceeded'
            ]);
            return;
        }

        // Check if IP is blocked due to too many failed auth attempts
        if ($this->rateLimiter->checkFailedAuth($clientIp)) {
            http_response_code(429);
            echo json_encode([
                'error' => 'Too Many Requests',
                'message' => 'Too many failed authentication attempts. Try again later.'
            ]);
            return;
        }

        // Check HTTP method
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (!in_array($method, ['GET', 'POST', 'DELETE'])) {
            http_response_code(405);
            header('Allow: GET, POST, DELETE');
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }

        // Authenticate using Bearer token
        $authResult = $this->auth->authenticate();
        if (!$authResult['success']) {
            // Record failed auth attempt for brute-force protection
            $this->rateLimiter->recordFailedAuth($clientIp);

            http_response_code(401);
            header('WWW-Authenticate: Bearer');
            echo json_encode([
                'error' => 'Unauthorized',
                'message' => $authResult['message']
            ]);
            return;
        }

        // Rate limiting per user (use username as identifier)
        $rateLimitResult = $this->rateLimiter->check($authResult['username']);
        $this->sendRateLimitHeaders($rateLimitResult);

        if (!$rateLimitResult['allowed']) {
            http_response_code(429);
            echo json_encode([
                'error' => 'Too Many Requests',
                'message' => 'Rate limit exceeded'
            ]);
            return;
        }

        // Create MCP server with registered tools
        $server = $this->createMcpServer($authResult['permissions']);

        // Configure HTTP options
        $httpOptions = [
            'session_timeout' => 1800, // 30 minutes
            'max_queue_size' => 500,
            'enable_sse' => false,
            'shared_hosting' => true,
            'server_header' => 'Grav-MCP-Server/1.0',
        ];

        try {
            // Create session store
            $sessionDir = GRAV_ROOT . '/cache/mcp-sessions';
            if (!is_dir($sessionDir)) {
                mkdir($sessionDir, 0755, true);
            }
            $fileStore = new FileSessionStore($sessionDir);

            // Create runner
            $runner = new HttpServerRunner(
                $server,
                $server->createInitializationOptions(),
                $httpOptions,
                $this->logger,
                $fileStore
            );

            // Create adapter and handle request
            $adapter = new StandardPhpAdapter($runner);
            $adapter->handle();
        } catch (\Exception $e) {
            $this->logError('MCP Server error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'error' => 'Internal Server Error',
                'message' => $this->isDebug() ? $e->getMessage() : 'An error occurred'
            ]);
        }
    }

    /**
     * Create and configure the MCP server with all tools
     */
    protected function createMcpServer(array $permissions): Server
    {
        $server = new Server('grav-mcp-server', $this->logger);

        // Register tool handlers
        $toolsRegistrar = new McpToolsRegistrar($this->grav, $this->config, $permissions);
        $toolsRegistrar->registerAll($server);

        return $server;
    }

    /**
     * Send rate limit headers
     */
    protected function sendRateLimitHeaders(array $result): void
    {
        header('X-RateLimit-Limit: ' . $result['limit']);
        header('X-RateLimit-Remaining: ' . $result['remaining']);
        header('X-RateLimit-Reset: ' . $result['reset']);
    }

    /**
     * Log error message
     */
    protected function logError(string $message): void
    {
        if ($this->config['logging']['enabled'] ?? true) {
            error_log('[MCP] ' . $message);
        }
    }

    /**
     * Check if debug mode is enabled
     */
    protected function isDebug(): bool
    {
        return $this->grav['config']->get('system.debugger.enabled', false);
    }

    /**
     * Send security headers
     */
    protected function sendSecurityHeaders(): void
    {
        // Prevent MIME type sniffing
        header('X-Content-Type-Options: nosniff');

        // Prevent clickjacking
        header('X-Frame-Options: DENY');

        // XSS protection (legacy, but still useful)
        header('X-XSS-Protection: 1; mode=block');

        // Referrer policy
        header('Referrer-Policy: strict-origin-when-cross-origin');

        // Content Security Policy - restrict to JSON API only
        header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");

        // CORS - configurable allowed origins
        $allowedOrigins = $this->config['security']['cors_origins'] ?? [];
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

        if (!empty($allowedOrigins) && in_array($origin, $allowedOrigins, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, Authorization, mcp-session-id');
            header('Access-Control-Max-Age: 86400');
        }

        // Handle preflight requests
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            exit;
        }

        // Cache control - no caching for API responses
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
    }

    /**
     * Handle direct media upload (REST API without MCP session)
     *
     * Accepts:
     * - multipart/form-data with fields: slug, file, [overwrite]
     * - application/json with fields: slug, filename, content_base64, [overwrite]
     *
     * This endpoint bypasses MCP session requirements for easier integration
     * with tools that can't maintain SSE sessions (like Claude Code).
     */
    public function handleDirectUpload(): void
    {
        // Set security headers
        $this->sendSecurityHeaders();

        // Set JSON content type for response
        header('Content-Type: application/json');

        // Get client IP for rate limiting
        $clientIp = $this->getClientIp();

        // Check IP-based rate limit
        $ipRateLimit = $this->rateLimiter->checkIp($clientIp);
        if (!$ipRateLimit['allowed']) {
            http_response_code(429);
            echo json_encode([
                'success' => false,
                'error' => ['code' => 'RATE_LIMIT', 'message' => 'Rate limit exceeded']
            ]);
            return;
        }

        // Check for blocked IP
        if ($this->rateLimiter->checkFailedAuth($clientIp)) {
            http_response_code(429);
            echo json_encode([
                'success' => false,
                'error' => ['code' => 'BLOCKED', 'message' => 'Too many failed attempts']
            ]);
            return;
        }

        // Only POST allowed
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            header('Allow: POST');
            echo json_encode([
                'success' => false,
                'error' => ['code' => 'METHOD_NOT_ALLOWED', 'message' => 'Only POST allowed']
            ]);
            return;
        }

        // Authenticate
        $authResult = $this->auth->authenticate();
        if (!$authResult['success']) {
            $this->rateLimiter->recordFailedAuth($clientIp);
            http_response_code(401);
            header('WWW-Authenticate: Bearer');
            echo json_encode([
                'success' => false,
                'error' => ['code' => 'UNAUTHORIZED', 'message' => $authResult['message']]
            ]);
            return;
        }

        // Check permissions (write permission allows media upload)
        if (!in_array('write', $authResult['permissions'])) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'error' => ['code' => 'FORBIDDEN', 'message' => 'Missing write permission']
            ]);
            return;
        }

        // Rate limit per user
        $rateLimitResult = $this->rateLimiter->check($authResult['username']);
        $this->sendRateLimitHeaders($rateLimitResult);
        if (!$rateLimitResult['allowed']) {
            http_response_code(429);
            echo json_encode([
                'success' => false,
                'error' => ['code' => 'RATE_LIMIT', 'message' => 'Rate limit exceeded']
            ]);
            return;
        }

        // Parse request
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

        try {
            if (str_contains($contentType, 'multipart/form-data')) {
                $args = $this->parseMultipartUpload();
            } elseif (str_contains($contentType, 'application/json')) {
                $args = $this->parseJsonUpload();
            } else {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => ['code' => 'INVALID_CONTENT_TYPE', 'message' => 'Use multipart/form-data or application/json']
                ]);
                return;
            }

            // Validate required fields
            if (empty($args['slug']) || empty($args['filename']) || empty($args['content_base64'])) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => ['code' => 'MISSING_FIELDS', 'message' => 'Required: slug, filename, content_base64 (or file for multipart)']
                ]);
                return;
            }

            // Call MediaManager
            $mediaManager = new Services\MediaManager($this->grav);
            $result = $mediaManager->upload($args);

            if ($result['success']) {
                http_response_code(201);
            } else {
                http_response_code(400);
            }

            echo json_encode($result);

        } catch (\Exception $e) {
            $this->logError('Direct upload error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => ['code' => 'INTERNAL_ERROR', 'message' => $this->isDebug() ? $e->getMessage() : 'Upload failed']
            ]);
        }
    }

    /**
     * Parse multipart/form-data upload
     */
    protected function parseMultipartUpload(): array
    {
        $slug = $_POST['slug'] ?? '';
        $overwrite = filter_var($_POST['overwrite'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('No file uploaded or upload error');
        }

        $file = $_FILES['file'];
        $filename = basename($file['name']);
        $content = file_get_contents($file['tmp_name']);

        if ($content === false) {
            throw new \RuntimeException('Failed to read uploaded file');
        }

        return [
            'slug' => $slug,
            'filename' => $filename,
            'content_base64' => base64_encode($content),
            'overwrite' => $overwrite
        ];
    }

    /**
     * Parse application/json upload
     */
    protected function parseJsonUpload(): array
    {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Invalid JSON: ' . json_last_error_msg());
        }

        return [
            'slug' => $data['slug'] ?? '',
            'filename' => $data['filename'] ?? '',
            'content_base64' => $data['content_base64'] ?? '',
            'overwrite' => $data['overwrite'] ?? false
        ];
    }

    /**
     * Get client IP address (handles proxies)
     */
    protected function getClientIp(): string
    {
        // Check for proxy headers (only trust if behind known proxy)
        $trustedProxies = $this->config['security']['trusted_proxies'] ?? [];

        if (!empty($trustedProxies)) {
            $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

            if (in_array($remoteAddr, $trustedProxies, true)) {
                // Trust X-Forwarded-For from known proxies
                if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                    $ips = array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']));
                    return $ips[0];
                }
                if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
                    return $_SERVER['HTTP_X_REAL_IP'];
                }
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}
