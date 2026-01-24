<?php

declare(strict_types=1);

namespace Grav\Plugin\Mcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Mcp\Server\Server;
use Mcp\Types\Tool;
use Mcp\Types\ToolInputSchema;
use Mcp\Types\ToolInputProperties;
use Mcp\Types\ListToolsResult;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;

class McpSdkTest extends TestCase
{
    public function testServerCanBeCreated(): void
    {
        $server = new Server('test-server');

        $this->assertInstanceOf(Server::class, $server);
    }

    public function testToolCanBeCreated(): void
    {
        $properties = ToolInputProperties::fromArray([
            'name' => [
                'type' => 'string',
                'description' => 'A name parameter'
            ]
        ]);

        $schema = new ToolInputSchema(
            properties: $properties,
            required: ['name']
        );

        $tool = new Tool(
            name: 'test_tool',
            description: 'A test tool',
            inputSchema: $schema
        );

        $this->assertEquals('test_tool', $tool->name);
        $this->assertEquals('A test tool', $tool->description);
    }

    public function testListToolsResultCanBeCreated(): void
    {
        $properties = ToolInputProperties::fromArray([]);
        $schema = new ToolInputSchema(properties: $properties, required: []);

        $tool = new Tool(
            name: 'example',
            description: 'Example tool',
            inputSchema: $schema
        );

        $result = new ListToolsResult([$tool]);

        $this->assertInstanceOf(ListToolsResult::class, $result);
    }

    public function testCallToolResultCanBeCreated(): void
    {
        $content = new TextContent(text: '{"success": true}');

        $result = new CallToolResult(
            content: [$content],
            isError: false
        );

        $this->assertInstanceOf(CallToolResult::class, $result);
        $this->assertFalse($result->isError);
    }

    public function testToolHandlerCanBeRegistered(): void
    {
        $server = new Server('test-server');

        $server->registerHandler('tools/list', function ($params) {
            return new ListToolsResult([]);
        });

        $handlers = $server->getHandlers();

        $this->assertArrayHasKey('tools/list', $handlers);
    }
}
