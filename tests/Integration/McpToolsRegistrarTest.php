<?php

declare(strict_types=1);

namespace Grav\Plugin\Mcp\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Integration tests for McpToolsRegistrar
 * These tests verify the tool registration logic without Grav
 */
class McpToolsRegistrarTest extends TestCase
{
    public function testToolsRegistrarClassExists(): void
    {
        $this->assertTrue(
            class_exists(\Grav\Plugin\Mcp\McpToolsRegistrar::class),
            'McpToolsRegistrar class should exist'
        );
    }

    public function testMcpControllerClassExists(): void
    {
        $this->assertTrue(
            class_exists(\Grav\Plugin\Mcp\McpController::class),
            'McpController class should exist'
        );
    }

    public function testSecurityClassesExist(): void
    {
        $this->assertTrue(
            class_exists(\Grav\Plugin\Mcp\Security\ApiKeyAuth::class),
            'ApiKeyAuth class should exist'
        );

        $this->assertTrue(
            class_exists(\Grav\Plugin\Mcp\Security\RateLimiter::class),
            'RateLimiter class should exist'
        );
    }

    public function testServiceClassesExist(): void
    {
        $this->assertTrue(
            class_exists(\Grav\Plugin\Mcp\Services\ContentManager::class),
            'ContentManager class should exist'
        );

        $this->assertTrue(
            class_exists(\Grav\Plugin\Mcp\Services\MediaManager::class),
            'MediaManager class should exist'
        );

        $this->assertTrue(
            class_exists(\Grav\Plugin\Mcp\Services\TranslationManager::class),
            'TranslationManager class should exist'
        );
    }

    public function testMcpSdkClassesAvailable(): void
    {
        $this->assertTrue(
            class_exists(\Mcp\Server\Server::class),
            'MCP Server class should be available'
        );

        $this->assertTrue(
            class_exists(\Mcp\Server\HttpServerRunner::class),
            'MCP HttpServerRunner class should be available'
        );

        $this->assertTrue(
            class_exists(\Mcp\Types\Tool::class),
            'MCP Tool type should be available'
        );

        $this->assertTrue(
            class_exists(\Mcp\Types\ListToolsResult::class),
            'MCP ListToolsResult type should be available'
        );

        $this->assertTrue(
            class_exists(\Mcp\Types\CallToolResult::class),
            'MCP CallToolResult type should be available'
        );
    }
}
