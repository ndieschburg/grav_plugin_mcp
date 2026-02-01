<?php

declare(strict_types=1);

namespace Grav\Plugin\Mcp;

use Grav\Common\Grav;
use Mcp\Server\Server;
use Mcp\Types\Tool;
use Mcp\Types\ToolInputSchema;
use Mcp\Types\ToolInputProperties;
use Mcp\Types\ListToolsResult;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use Grav\Plugin\Mcp\Services\ContentManager;
use Grav\Plugin\Mcp\Services\TranslationManager;
use Grav\Plugin\Mcp\Services\MediaManager;

/**
 * MCP Tools Registrar
 * Registers all available tools with the MCP server
 */
class McpToolsRegistrar
{
    protected Grav $grav;
    protected array $config;
    protected array $permissions;
    protected ContentManager $contentManager;
    protected TranslationManager $translationManager;
    protected MediaManager $mediaManager;

    public function __construct(Grav $grav, array $config, array $permissions)
    {
        $this->grav = $grav;
        $this->config = $config;
        $this->permissions = $permissions;
        $this->contentManager = new ContentManager($grav);
        $this->translationManager = new TranslationManager($grav);
        $this->mediaManager = new MediaManager($grav);
    }

    /**
     * Register all tools with the MCP server
     */
    public function registerAll(Server $server): void
    {
        $server->registerHandler('tools/list', fn($params) => $this->listTools());
        $server->registerHandler('tools/call', fn($params) => $this->callTool($params));
    }

    /**
     * List all available tools
     */
    protected function listTools(): ListToolsResult
    {
        $tools = [];

        // Post tools (read permission)
        if ($this->hasPermission('read')) {
            $tools[] = $this->createTool(
                'list_posts',
                'List blog posts with filters and pagination',
                [
                    'lang' => ['type' => 'string', 'description' => 'Filter by language (fr, en)'],
                    'status' => ['type' => 'string', 'description' => 'Filter by status: published, draft, all', 'enum' => ['published', 'draft', 'all']],
                    'limit' => ['type' => 'integer', 'description' => 'Max results (1-100)', 'default' => 20],
                    'offset' => ['type' => 'integer', 'description' => 'Offset for pagination', 'default' => 0],
                    'tag' => ['type' => 'string', 'description' => 'Filter by tag'],
                    'order_by' => ['type' => 'string', 'description' => 'Order by: date, title, slug', 'enum' => ['date', 'title', 'slug']],
                    'order_dir' => ['type' => 'string', 'description' => 'Order direction: asc, desc', 'enum' => ['asc', 'desc']]
                ]
            );

            $tools[] = $this->createTool(
                'get_post',
                'Get full content of a post',
                [
                    'slug' => ['type' => 'string', 'description' => 'Post slug (required)'],
                    'lang' => ['type' => 'string', 'description' => 'Language version to retrieve']
                ],
                ['slug']
            );

            $tools[] = $this->createTool(
                'list_translations',
                'List available translations for a post',
                [
                    'slug' => ['type' => 'string', 'description' => 'Post slug (required)']
                ],
                ['slug']
            );

            $tools[] = $this->createTool(
                'list_tags',
                'List all tags used on the site',
                [
                    'lang' => ['type' => 'string', 'description' => 'Filter by language']
                ]
            );

            $tools[] = $this->createTool(
                'get_site_info',
                'Get information about the site and MCP plugin',
                []
            );
        }

        // Write tools (write permission)
        if ($this->hasPermission('write')) {
            $tools[] = $this->createTool(
                'create_post',
                'Create a new blog post',
                [
                    'slug' => ['type' => 'string', 'description' => 'URL-friendly slug (required)'],
                    'title' => ['type' => 'string', 'description' => 'Post title (required)'],
                    'content' => ['type' => 'string', 'description' => 'Markdown content (required)'],
                    'lang' => ['type' => 'string', 'description' => 'Language code'],
                    'tags' => ['type' => 'array', 'description' => 'List of tags', 'items' => ['type' => 'string']],
                    'category' => ['type' => 'string', 'description' => 'Category name'],
                    'status' => ['type' => 'string', 'description' => 'published or draft', 'enum' => ['published', 'draft']],
                    'date' => ['type' => 'string', 'description' => 'ISO 8601 date'],
                    'hero_image' => ['type' => 'string', 'description' => 'Hero image filename'],
                    'template' => ['type' => 'string', 'description' => 'Page template name']
                ],
                ['slug', 'title', 'content']
            );

            $tools[] = $this->createTool(
                'update_post',
                'Update an existing post',
                [
                    'slug' => ['type' => 'string', 'description' => 'Post slug to update (required)'],
                    'lang' => ['type' => 'string', 'description' => 'Language version to update'],
                    'title' => ['type' => 'string', 'description' => 'New title'],
                    'content' => ['type' => 'string', 'description' => 'New markdown content'],
                    'tags' => ['type' => 'array', 'description' => 'New tags', 'items' => ['type' => 'string']],
                    'category' => ['type' => 'string', 'description' => 'New category'],
                    'status' => ['type' => 'string', 'description' => 'New status', 'enum' => ['published', 'draft']],
                    'date' => ['type' => 'string', 'description' => 'New date'],
                    'hero_image' => ['type' => 'string', 'description' => 'New hero image']
                ],
                ['slug']
            );

            $tools[] = $this->createTool(
                'create_translation',
                'Create a translation for an existing post',
                [
                    'slug' => ['type' => 'string', 'description' => 'Post slug (required)'],
                    'source_lang' => ['type' => 'string', 'description' => 'Source language (required)'],
                    'target_lang' => ['type' => 'string', 'description' => 'Target language (required)'],
                    'title' => ['type' => 'string', 'description' => 'Translated title (required)'],
                    'content' => ['type' => 'string', 'description' => 'Translated content (required)'],
                    'tags' => ['type' => 'array', 'description' => 'Translated tags', 'items' => ['type' => 'string']]
                ],
                ['slug', 'source_lang', 'target_lang', 'title', 'content']
            );

            $tools[] = $this->createTool(
                'upload_media',
                'Upload a media file to a post',
                [
                    'slug' => ['type' => 'string', 'description' => 'Post slug (required)'],
                    'filename' => ['type' => 'string', 'description' => 'Filename with extension (required)'],
                    'content_base64' => ['type' => 'string', 'description' => 'Base64 encoded file content (required)'],
                    'overwrite' => ['type' => 'boolean', 'description' => 'Overwrite if exists', 'default' => false]
                ],
                ['slug', 'filename', 'content_base64']
            );

            $tools[] = $this->createTool(
                'clear_cache',
                'Clear Grav cache',
                [
                    'type' => ['type' => 'string', 'description' => 'Cache type: all, cache, images', 'enum' => ['all', 'cache', 'images']]
                ]
            );

            // Only register webmention tool if bridgyfed plugin is enabled
            if ($this->grav['config']->get('plugins.bridgyfed.enabled', false)) {
                $tools[] = $this->createTool(
                    'send_webmention',
                    'Send a webmention to Bridgy Fed to publish a post on Fediverse',
                    [
                        'slug' => ['type' => 'string', 'description' => 'Post slug (required)'],
                        'lang' => ['type' => 'string', 'description' => 'Language version (default: fr)', 'default' => 'fr']
                    ],
                    ['slug']
                );
            }
        }

        // Delete tools (delete permission)
        if ($this->hasPermission('delete')) {
            $tools[] = $this->createTool(
                'delete_post',
                'Delete a post or specific translation',
                [
                    'slug' => ['type' => 'string', 'description' => 'Post slug (required)'],
                    'lang' => ['type' => 'string', 'description' => 'Language to delete (omit for all)'],
                    'confirm' => ['type' => 'boolean', 'description' => 'Must be true to confirm (required)']
                ],
                ['slug', 'confirm']
            );

            $tools[] = $this->createTool(
                'delete_media',
                'Delete a media file from a post',
                [
                    'slug' => ['type' => 'string', 'description' => 'Post slug (required)'],
                    'filename' => ['type' => 'string', 'description' => 'Filename to delete (required)'],
                    'confirm' => ['type' => 'boolean', 'description' => 'Must be true to confirm (required)']
                ],
                ['slug', 'filename', 'confirm']
            );
        }

        return new ListToolsResult($tools);
    }

    /**
     * Call a tool by name with arguments
     */
    protected function callTool(object $params): CallToolResult
    {
        $name = $params->name ?? '';
        $arguments = (array)($params->arguments ?? []);

        try {
            $result = match ($name) {
                // Read tools
                'list_posts' => $this->requirePermission('read') ?? $this->contentManager->listPosts($arguments),
                'get_post' => $this->requirePermission('read') ?? $this->contentManager->getPost($arguments['slug'] ?? '', $arguments['lang'] ?? null),
                'list_translations' => $this->requirePermission('read') ?? $this->translationManager->listTranslations($arguments['slug'] ?? ''),
                'list_tags' => $this->requirePermission('read') ?? $this->listTags($arguments),
                'get_site_info' => $this->requirePermission('read') ?? $this->getSiteInfo(),

                // Write tools
                'create_post' => $this->requirePermission('write') ?? $this->contentManager->createPost($arguments),
                'update_post' => $this->requirePermission('write') ?? $this->contentManager->updatePost($arguments),
                'create_translation' => $this->requirePermission('write') ?? $this->translationManager->createTranslation($arguments),
                'upload_media' => $this->requirePermission('write') ?? $this->mediaManager->upload($arguments),
                'clear_cache' => $this->requirePermission('write') ?? $this->clearCache($arguments),
                'send_webmention' => $this->requirePermission('write') ?? $this->handleSendWebmention($arguments),

                // Delete tools
                'delete_post' => $this->requirePermission('delete') ?? $this->deletePost($arguments),
                'delete_media' => $this->requirePermission('delete') ?? $this->deleteMedia($arguments),

                default => ['success' => false, 'error' => ['code' => 'UNKNOWN_TOOL', 'message' => "Unknown tool: {$name}"]]
            };

            return $this->createToolResult($result);
        } catch (\Exception $e) {
            return new CallToolResult(
                content: [new TextContent(text: json_encode([
                    'success' => false,
                    'error' => ['code' => 'INTERNAL_ERROR', 'message' => $e->getMessage()]
                ]))],
                isError: true
            );
        }
    }

    /**
     * Create a Tool object with input schema
     */
    protected function createTool(string $name, string $description, array $properties, array $required = []): Tool
    {
        $props = ToolInputProperties::fromArray($properties);
        $schema = new ToolInputSchema(properties: $props, required: $required);

        return new Tool(name: $name, description: $description, inputSchema: $schema);
    }

    /**
     * Create a CallToolResult from the result array
     */
    protected function createToolResult(array $result): CallToolResult
    {
        $isError = !($result['success'] ?? true);

        return new CallToolResult(
            content: [new TextContent(text: json_encode($result))],
            isError: $isError
        );
    }

    /**
     * Check if current request has required permission
     */
    protected function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions);
    }

    /**
     * Require a permission, return error array if not allowed
     */
    protected function requirePermission(string $permission): ?array
    {
        if (!$this->hasPermission($permission)) {
            return [
                'success' => false,
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => "Permission '{$permission}' required for this operation"
                ]
            ];
        }
        return null;
    }

    /**
     * List all tags
     */
    protected function listTags(array $args): array
    {
        $taxonomy = $this->grav['taxonomy'];
        $tags = $taxonomy->taxonomy()['tag'] ?? [];

        $result = [];
        foreach ($tags as $tag => $pages) {
            $result[] = [
                'name' => $tag,
                'count' => count($pages)
            ];
        }

        usort($result, fn($a, $b) => $b['count'] <=> $a['count']);

        return [
            'success' => true,
            'data' => ['tags' => $result]
        ];
    }

    /**
     * Get site information
     */
    protected function getSiteInfo(): array
    {
        $config = $this->grav['config'];
        $pages = $this->grav['pages'];

        $allPages = $pages->all();
        $publishedCount = 0;
        $draftCount = 0;

        foreach ($allPages as $page) {
            if ($page->published()) {
                $publishedCount++;
            } else {
                $draftCount++;
            }
        }

        $taxonomy = $this->grav['taxonomy'];
        $tags = $taxonomy->taxonomy()['tag'] ?? [];

        return [
            'success' => true,
            'data' => [
                'site' => [
                    'title' => $config->get('site.title'),
                    'description' => $config->get('site.metadata.description'),
                    'url' => $this->grav['uri']->base(),
                    'default_language' => $config->get('system.languages.default', 'en'),
                    'languages' => $config->get('system.languages.supported', ['en'])
                ],
                'content' => [
                    'post_count' => $publishedCount,
                    'draft_count' => $draftCount,
                    'tag_count' => count($tags)
                ],
                'mcp' => [
                    'plugin_version' => '1.0.0',
                    'api_version' => '1.0',
                    'capabilities' => ['posts', 'translations', 'media', 'tags']
                ]
            ]
        ];
    }

    /**
     * Clear cache
     */
    protected function clearCache(array $args): array
    {
        $type = $args['type'] ?? 'all';

        try {
            $cache = $this->grav['cache'];

            switch ($type) {
                case 'images':
                    $cache->clearCache('images');
                    break;
                case 'cache':
                    $cache->clearCache('standard');
                    break;
                default:
                    $cache->clearCache('all');
            }

            return [
                'success' => true,
                'data' => [
                    'cleared' => $type,
                    'message' => 'Cache cleared successfully'
                ]
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => ['code' => 'CACHE_ERROR', 'message' => $e->getMessage()]
            ];
        }
    }

    /**
     * Delete post with confirmation
     */
    protected function deletePost(array $args): array
    {
        if (!($args['confirm'] ?? false)) {
            return [
                'success' => false,
                'error' => ['code' => 'CONFIRMATION_REQUIRED', 'message' => 'confirm must be true']
            ];
        }

        return $this->contentManager->deletePost($args['slug'] ?? '', $args['lang'] ?? null);
    }

    /**
     * Delete media with confirmation
     */
    protected function deleteMedia(array $args): array
    {
        if (!($args['confirm'] ?? false)) {
            return [
                'success' => false,
                'error' => ['code' => 'CONFIRMATION_REQUIRED', 'message' => 'confirm must be true']
            ];
        }

        return $this->mediaManager->delete($args['slug'] ?? '', $args['filename'] ?? '');
    }

    /**
     * Handle send_webmention - check if bridgyfed plugin is enabled
     */
    protected function handleSendWebmention(array $args): array
    {
        if (!$this->grav['config']->get('plugins.bridgyfed.enabled', false)) {
            return [
                'success' => false,
                'error' => ['code' => 'PLUGIN_NOT_ENABLED', 'message' => 'bridgyfed plugin is not enabled']
            ];
        }

        return $this->sendWebmention($args);
    }

    /**
     * Send webmention to Bridgy Fed for Fediverse publishing
     */
    protected function sendWebmention(array $args): array
    {
        $slug = $args['slug'] ?? '';
        $lang = $args['lang'] ?? 'fr';

        if (empty($slug)) {
            return [
                'success' => false,
                'error' => ['code' => 'MISSING_SLUG', 'message' => 'Post slug is required']
            ];
        }

        // Build the source URL
        $baseUrl = $this->grav['uri']->rootUrl(true);
        $sourceUrl = "{$baseUrl}/{$lang}/blog/{$slug}";

        // Send webmention to Bridgy Fed
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://fed.brid.gy/webmention',
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'source' => $sourceUrl,
                'target' => 'https://fed.brid.gy/'
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded']
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return [
                'success' => false,
                'error' => ['code' => 'CURL_ERROR', 'message' => $error]
            ];
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            return [
                'success' => true,
                'data' => [
                    'message' => 'Webmention sent successfully to Bridgy Fed',
                    'source_url' => $sourceUrl,
                    'response' => $response
                ]
            ];
        }

        return [
            'success' => false,
            'error' => ['code' => 'HTTP_ERROR', 'message' => "HTTP {$httpCode}: {$response}"]
        ];
    }
}
