<?php

namespace Grav\Plugin\Mcp\Security;

/**
 * Rate Limiter
 *
 * Security features:
 * - Per-user rate limiting
 * - Per-IP rate limiting (for brute-force protection)
 * - File locking to prevent race conditions
 * - Automatic cleanup of old entries
 */
class RateLimiter
{
    protected array $config;
    protected string $cacheDir;

    // Separate limits for IP-based rate limiting (stricter for failed auth)
    private const IP_MAX_REQUESTS = 30;
    private const IP_WINDOW_SECONDS = 60;
    private const IP_FAILED_AUTH_MAX = 5;
    private const IP_FAILED_AUTH_WINDOW = 300; // 5 minutes

    public function __construct(array $config, ?string $cacheDir = null)
    {
        $this->config = $config;

        // Use provided cache dir, or GRAV_ROOT if available, or system temp
        if ($cacheDir !== null) {
            $this->cacheDir = $cacheDir;
        } elseif (defined('GRAV_ROOT')) {
            $this->cacheDir = GRAV_ROOT . '/cache/mcp-ratelimit';
        } else {
            $this->cacheDir = sys_get_temp_dir() . '/mcp-ratelimit';
        }

        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }

    /**
     * Check if request is allowed (for authenticated users)
     */
    public function check(string $identifier): array
    {
        $enabled = $this->config['security']['rate_limit']['enabled'] ?? true;
        $maxRequests = $this->config['security']['rate_limit']['max_requests'] ?? 100;
        $windowSeconds = $this->config['security']['rate_limit']['window_seconds'] ?? 60;

        if (!$enabled) {
            return [
                'allowed' => true,
                'limit' => $maxRequests,
                'remaining' => $maxRequests,
                'reset' => time() + $windowSeconds
            ];
        }

        return $this->checkLimit('user_' . $identifier, $maxRequests, $windowSeconds);
    }

    /**
     * Check IP-based rate limit (for unauthenticated/failed requests)
     */
    public function checkIp(string $ip): array
    {
        $enabled = $this->config['security']['rate_limit']['enabled'] ?? true;

        if (!$enabled) {
            return ['allowed' => true, 'limit' => self::IP_MAX_REQUESTS, 'remaining' => self::IP_MAX_REQUESTS, 'reset' => time() + self::IP_WINDOW_SECONDS];
        }

        return $this->checkLimit('ip_' . $ip, self::IP_MAX_REQUESTS, self::IP_WINDOW_SECONDS);
    }

    /**
     * Check if IP should be blocked due to failed auth attempts
     * Returns true if IP should be blocked (does NOT increment counter)
     */
    public function checkFailedAuth(string $ip): bool
    {
        // Just read the count, don't increment
        $result = $this->peekLimit('failed_' . $ip, self::IP_FAILED_AUTH_MAX, self::IP_FAILED_AUTH_WINDOW);
        return !$result['allowed'];
    }

    /**
     * Record a failed authentication attempt
     */
    public function recordFailedAuth(string $ip): void
    {
        $this->checkLimit('failed_' . $ip, self::IP_FAILED_AUTH_MAX, self::IP_FAILED_AUTH_WINDOW, true);
    }

    /**
     * Peek at rate limit count without incrementing (read-only)
     */
    protected function peekLimit(string $identifier, int $maxRequests, int $windowSeconds): array
    {
        $cacheFile = $this->cacheDir . '/' . hash('sha256', $identifier) . '.json';
        $now = time();
        $windowStart = $now - $windowSeconds;

        // Just read the file, no locking needed for peek
        $requests = [];
        if (file_exists($cacheFile)) {
            $content = file_get_contents($cacheFile);
            if ($content !== false) {
                $data = json_decode($content, true);
                $requests = $data['requests'] ?? [];
            }
        }

        // Filter requests within window
        $requests = array_values(array_filter($requests, fn($ts) => $ts > $windowStart));
        $count = count($requests);

        return [
            'allowed' => $count < $maxRequests,
            'limit' => $maxRequests,
            'remaining' => max(0, $maxRequests - $count),
            'reset' => $windowStart + $windowSeconds
        ];
    }

    /**
     * Generic rate limit check with file locking
     */
    protected function checkLimit(string $identifier, int $maxRequests, int $windowSeconds, bool $alwaysIncrement = false): array
    {
        $cacheFile = $this->cacheDir . '/' . hash('sha256', $identifier) . '.json';
        $now = time();
        $windowStart = $now - $windowSeconds;

        // Use file locking to prevent race conditions
        $lockFile = $cacheFile . '.lock';
        $lock = fopen($lockFile, 'c');

        if (!flock($lock, LOCK_EX)) {
            // Could not acquire lock, fail open (allow request)
            fclose($lock);
            return [
                'allowed' => true,
                'limit' => $maxRequests,
                'remaining' => 0,
                'reset' => $now + $windowSeconds
            ];
        }

        try {
            // Load existing requests
            $requests = [];
            if (file_exists($cacheFile)) {
                $content = file_get_contents($cacheFile);
                if ($content !== false) {
                    $data = json_decode($content, true);
                    $requests = $data['requests'] ?? [];
                }
            }

            // Filter requests within window
            $requests = array_values(array_filter($requests, fn($ts) => $ts > $windowStart));

            // Check limit
            $count = count($requests);
            $allowed = $count < $maxRequests;

            // Increment counter if allowed OR if alwaysIncrement is true (for failed auth tracking)
            if ($allowed || $alwaysIncrement) {
                $requests[] = $now;

                // Atomic write with temp file
                $tempFile = $cacheFile . '.tmp.' . getmypid();
                if (file_put_contents($tempFile, json_encode(['requests' => $requests, 'updated' => $now])) !== false) {
                    rename($tempFile, $cacheFile);
                }
            }

            return [
                'allowed' => $allowed,
                'limit' => $maxRequests,
                'remaining' => max(0, $maxRequests - $count - ($allowed ? 1 : 0)),
                'reset' => $windowStart + $windowSeconds
            ];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Clean up old rate limit files (call periodically)
     */
    public function cleanup(): int
    {
        $cleaned = 0;
        $maxAge = 3600; // 1 hour

        $files = glob($this->cacheDir . '/*.json');
        foreach ($files as $file) {
            if (filemtime($file) < time() - $maxAge) {
                @unlink($file);
                @unlink($file . '.lock');
                $cleaned++;
            }
        }

        return $cleaned;
    }
}
