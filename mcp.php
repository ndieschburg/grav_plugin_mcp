<?php

namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Grav\Common\Plugin;
use Grav\Plugin\Mcp\Security\ApiKeyAuth;
use RocketTheme\Toolbox\Event\Event;

/**
 * Class McpPlugin
 * @package Grav\Plugin
 */
class McpPlugin extends Plugin
{
    /**
     * @return array
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onPluginsInitialized' => [
                ['autoload', 100000],
                ['onPluginsInitialized', 0]
            ],
            'onAdminSave' => ['onAdminSave', 0],
        ];
    }

    /**
     * Composer autoload
     * Prepend = false to avoid overriding Grav's dependencies
     * @return ClassLoader
     */
    public function autoload(): ClassLoader
    {
        $loader = require __DIR__ . '/vendor/autoload.php';
        // Unregister and re-register with prepend=false to not override Grav classes
        $loader->unregister();
        $loader->register(false);
        return $loader;
    }

    /**
     * Initialize the plugin
     */
    public function onPluginsInitialized(): void
    {
        if ($this->isAdmin()) {
            return;
        }

        $this->enable([
            'onPagesInitialized' => ['onPagesInitialized', 0]
        ]);
    }

    /**
     * Handle MCP endpoint
     */
    public function onPagesInitialized(): void
    {
        $route = $this->config->get('plugins.mcp.route', '/mcp');
        $uri = $this->grav['uri'];

        // Direct upload endpoint (REST API without MCP session)
        if ($uri->path() === $route . '/upload') {
            $controller = new \Grav\Plugin\Mcp\McpController($this->grav);
            $controller->handleDirectUpload();
            exit;
        }

        // Main MCP endpoint
        if ($uri->path() === $route) {
            $controller = new \Grav\Plugin\Mcp\McpController($this->grav);
            $controller->handle();
            exit;
        }
    }

    /**
     * Auto-generate MCP API key when saving user if empty
     */
    public function onAdminSave(Event $event): void
    {
        $obj = $event['object'];

        // Only process user objects
        if (!$obj instanceof \Grav\Common\User\Interfaces\UserInterface) {
            return;
        }

        // Generate key if empty
        $currentKey = $obj->get('mcp_api_key');
        if (empty($currentKey)) {
            $newKey = ApiKeyAuth::generateKey();
            $obj->set('mcp_api_key', $newKey);
        }
    }
}
