<?php

declare(strict_types=1);

namespace Grav\Plugin\Mcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Grav\Plugin\Mcp\Security\ApiKeyAuth;
use Grav\Plugin\Mcp\Security\RateLimiter;

class SecurityTest extends TestCase
{
    public function testApiKeyGeneration(): void
    {
        $key = ApiKeyAuth::generateKey();

        $this->assertStringStartsWith('mcp_', $key);
        $this->assertEquals(36, strlen($key)); // mcp_ (4) + 32 hex chars
    }

    public function testApiKeyGenerationIsUnique(): void
    {
        $key1 = ApiKeyAuth::generateKey();
        $key2 = ApiKeyAuth::generateKey();

        $this->assertNotEquals($key1, $key2);
    }

    public function testApiKeyFormat(): void
    {
        $key = ApiKeyAuth::generateKey();

        // Should match pattern: mcp_ followed by 32 hex characters
        $this->assertMatchesRegularExpression('/^mcp_[a-f0-9]{32}$/', $key);
    }

    public function testRateLimiterAllowsRequests(): void
    {
        $config = [
            'security' => [
                'rate_limit' => [
                    'enabled' => true,
                    'max_requests' => 10,
                    'window_seconds' => 60
                ]
            ]
        ];

        $rateLimiter = new RateLimiter($config);

        // First request should be allowed
        $result = $rateLimiter->check('test-client-' . uniqid());

        $this->assertTrue($result['allowed']);
        $this->assertEquals(10, $result['limit']);
        $this->assertGreaterThanOrEqual(8, $result['remaining']);
    }

    public function testRateLimiterDisabled(): void
    {
        $config = [
            'security' => [
                'rate_limit' => [
                    'enabled' => false,
                    'max_requests' => 10,
                    'window_seconds' => 60
                ]
            ]
        ];

        $rateLimiter = new RateLimiter($config);
        $result = $rateLimiter->check('test-client');

        $this->assertTrue($result['allowed']);
    }

    public function testRateLimiterExhaustsLimit(): void
    {
        $config = [
            'security' => [
                'rate_limit' => [
                    'enabled' => true,
                    'max_requests' => 3,
                    'window_seconds' => 60
                ]
            ]
        ];

        $rateLimiter = new RateLimiter($config);
        $clientId = 'test-exhaust-' . uniqid();

        // Make requests up to limit
        for ($i = 0; $i < 3; $i++) {
            $result = $rateLimiter->check($clientId);
            $this->assertTrue($result['allowed']);
        }

        // Next request should be denied
        $result = $rateLimiter->check($clientId);
        $this->assertFalse($result['allowed']);
        $this->assertEquals(0, $result['remaining']);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }
}
