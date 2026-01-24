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
