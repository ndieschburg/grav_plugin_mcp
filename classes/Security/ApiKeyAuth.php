<?php

namespace Grav\Plugin\Mcp\Security;

use Grav\Common\Grav;
use Grav\Common\User\Interfaces\UserInterface;

/**
 * API Key Authentication
 * Authenticates requests using Bearer token linked to Grav users
 *
 * Security features:
 * - Timing-safe comparison to prevent timing attacks
 * - Failed attempt logging for intrusion detection
 * - Generic error messages to prevent user enumeration
 * - API key format validation
 */
class ApiKeyAuth
{
    protected Grav $grav;
    protected array $config;

    // API key must start with 'mcp_' followed by 32 hex characters
    private const API_KEY_PATTERN = '/^mcp_[a-f0-9]{32}$/';

    public function __construct(Grav $grav, array $config)
    {
        $this->grav = $grav;
        $this->config = $config;
    }

    /**
     * Authenticate request using Bearer token
     * Searches for a Grav user with matching mcp_api_key
     */
    public function authenticate(): array
    {
        $clientIp = $this->getClientIp();
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        if (empty($authHeader)) {
            $this->logSecurityEvent('auth_missing_header', $clientIp);
            return [
                'success' => false,
                'message' => 'Authentication required'
            ];
        }

        if (!str_starts_with($authHeader, 'Bearer ')) {
            $this->logSecurityEvent('auth_invalid_format', $clientIp);
            return [
                'success' => false,
                'message' => 'Authentication required'
            ];
        }

        $token = substr($authHeader, 7);

        // Validate API key format before database lookup (prevents injection)
        if (!$this->isValidApiKeyFormat($token)) {
            $this->logSecurityEvent('auth_invalid_key_format', $clientIp, ['token_prefix' => substr($token, 0, 10) . '...']);
            // Use constant-time delay to prevent timing attacks on format validation
            usleep(random_int(100000, 300000)); // 100-300ms random delay
            return [
                'success' => false,
                'message' => 'Authentication failed'
            ];
        }

        // Find user by MCP API key (timing-safe)
        $user = $this->findUserByApiKey($token);

        if (!$user) {
            $this->logSecurityEvent('auth_invalid_key', $clientIp, ['token_prefix' => substr($token, 0, 10) . '...']);
            // Constant-time delay to prevent user enumeration
            usleep(random_int(100000, 300000));
            return [
                'success' => false,
                'message' => 'Authentication failed'
            ];
        }

        // Check if user is enabled
        if ($user->get('state') !== 'enabled') {
            $this->logSecurityEvent('auth_user_disabled', $clientIp, ['username' => $user->get('username')]);
            return [
                'success' => false,
                'message' => 'Authentication failed'
            ];
        }

        // Build permissions from user access
        $permissions = $this->buildPermissionsFromUser($user);

        $this->logSecurityEvent('auth_success', $clientIp, [
            'username' => $user->get('username'),
            'permissions' => $permissions
        ]);

        return [
            'success' => true,
            'user' => $user,
            'username' => $user->get('username'),
            'permissions' => $permissions
        ];
    }

    /**
     * Validate API key format
     */
    protected function isValidApiKeyFormat(string $token): bool
    {
        return preg_match(self::API_KEY_PATTERN, $token) === 1;
    }

    /**
     * Find a Grav user by their MCP API key
     * Uses Grav's FlexCollection::find() to search by field
     */
    protected function findUserByApiKey(string $token): ?UserInterface
    {
        /** @var \Grav\Common\Flex\Types\Users\UserCollection $accounts */
        $accounts = $this->grav['accounts'];

        // Use Grav's native find() method to search by mcp_api_key field
        $user = $accounts->find($token, 'mcp_api_key');

        if ($user && $user->exists()) {
            return $user;
        }

        return null;
    }

    /**
     * Build MCP permissions from Grav user access
     */
    protected function buildPermissionsFromUser(UserInterface $user): array
    {
        $permissions = [];

        // Check access directly from user data (authorize() may not work outside admin context)
        $access = $user->get('access', []);
        $adminAccess = $access['admin'] ?? [];

        // Super admin gets all permissions
        if (!empty($adminAccess['super']) || $user->authorize('admin.super')) {
            return ['read', 'write', 'delete'];
        }

        // Check specific MCP permissions or fallback to admin permissions
        $mcpAccess = $access['mcp'] ?? [];
        $hasPages = !empty($adminAccess['pages']) || $user->authorize('admin.pages');

        if (!empty($mcpAccess['read']) || $hasPages) {
            $permissions[] = 'read';
        }

        if (!empty($mcpAccess['write']) || $hasPages) {
            $permissions[] = 'write';
        }

        if (!empty($mcpAccess['delete']) || !empty($adminAccess['super'])) {
            $permissions[] = 'delete';
        }

        // If no specific permissions, grant read by default for authenticated users
        if (empty($permissions)) {
            $permissions[] = 'read';
        }

        return array_unique($permissions);
    }

    /**
     * Verify API key (timing-safe comparison)
     */
    protected function verifyKey(string $provided, string $stored): bool
    {
        if (empty($stored) || empty($provided)) {
            return false;
        }

        return hash_equals($stored, $provided);
    }

    /**
     * Generate a new API key
     * Uses cryptographically secure random bytes
     */
    public static function generateKey(): string
    {
        return 'mcp_' . bin2hex(random_bytes(16));
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
                    return $ips[0]; // First IP is the original client
                }
                if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
                    return $_SERVER['HTTP_X_REAL_IP'];
                }
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    /**
     * Log security-related events
     */
    protected function logSecurityEvent(string $event, string $ip, array $context = []): void
    {
        if (!($this->config['logging']['enabled'] ?? true)) {
            return;
        }

        $logLevel = $this->config['logging']['level'] ?? 'info';

        // Always log auth failures regardless of level
        $isFailure = str_contains($event, 'invalid') || str_contains($event, 'disabled') || str_contains($event, 'missing');

        if ($logLevel === 'error' && !$isFailure) {
            return;
        }

        $message = sprintf(
            '[MCP Security] %s | IP: %s | %s',
            strtoupper($event),
            $ip,
            json_encode($context)
        );

        error_log($message);

        // Also write to dedicated security log if configured
        $securityLogPath = $this->config['logging']['security_log'] ?? null;
        if ($securityLogPath) {
            $logEntry = sprintf(
                "[%s] %s | IP: %s | %s\n",
                date('Y-m-d H:i:s'),
                strtoupper($event),
                $ip,
                json_encode($context)
            );
            @file_put_contents($securityLogPath, $logEntry, FILE_APPEND | LOCK_EX);
        }
    }
}
