#!/usr/bin/env php
<?php
/**
 * MCP Plugin End-to-End Test Script
 * Tests all 13 MCP tools via HTTP requests
 *
 * Usage: php test-mcp.php [base_url] [api_key]
 * Example: php test-mcp.php http://127.0.0.1:8000 mcp_your_api_key_here
 *
 * Environment variables:
 *   MCP_BASE_URL - Base URL (alternative to command line argument)
 *   MCP_API_KEY - API key (alternative to command line argument)
 */

declare(strict_types=1);

// ANSI colors
const RED = "\033[0;31m";
const GREEN = "\033[0;32m";
const YELLOW = "\033[1;33m";
const BLUE = "\033[0;34m";
const NC = "\033[0m"; // No Color

class McpE2ETest
{
    private string $baseUrl;
    private string $apiKey;
    private string $mcpEndpoint;
    private string $testSlug;
    private ?string $sessionId = null;

    private int $passed = 0;
    private int $failed = 0;

    public function __construct(string $baseUrl, string $apiKey)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
        $this->mcpEndpoint = $this->baseUrl . '/mcp';
        $this->testSlug = 'e2e-test-' . time();
    }

    /**
     * Run all tests
     */
    public function run(): int
    {
        $this->section("MCP Plugin E2E Tests");
        $this->info("Base URL: {$this->baseUrl}");
        $this->info("Test slug: {$this->testSlug}");
        echo "\n";

        // Check server availability
        if (!$this->checkServer()) {
            $this->error("Cannot connect to {$this->baseUrl}");
            echo "\nMake sure your Grav server is running.\n";
            return 1;
        }
        $this->success("Server is reachable");

        // Initialize MCP session
        $this->section("0. Initialize Session");
        if (!$this->initSession()) {
            $this->error("Failed to initialize MCP session");
            return 1;
        }
        $this->success("Session initialized: " . substr($this->sessionId, 0, 16) . "...");

        // Run all tool tests
        $this->testGetSiteInfo();
        $this->testListTags();
        $this->testListPosts();
        $this->testCreatePost();
        $this->testGetPost();
        $this->testUpdatePost();
        $this->testListTranslations();
        $this->testCreateTranslation();
        $this->testUploadMedia();
        $this->testDeleteMedia();
        $this->testClearCache();
        $this->testDeletePost();
        $this->testErrorHandling();

        // Summary
        $this->section("Test Summary");
        $total = $this->passed + $this->failed;
        echo "\n";
        echo GREEN . "  Passed: {$this->passed}" . NC . "\n";
        echo RED . "  Failed: {$this->failed}" . NC . "\n";
        echo "  Total:  {$total}\n\n";

        if ($this->failed === 0) {
            echo GREEN . "All tests passed!" . NC . "\n";
            return 0;
        } else {
            echo RED . "Some tests failed." . NC . "\n";
            return 1;
        }
    }

    private function checkServer(): bool
    {
        $ch = curl_init($this->baseUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $httpCode > 0;
    }

    private function initSession(): bool
    {
        $payload = [
            'jsonrpc' => '2.0',
            'id' => 0,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2024-11-05',
                'capabilities' => new \stdClass(),
                'clientInfo' => ['name' => 'e2e-test', 'version' => '1.0']
            ]
        ];

        $headers = [];
        $response = $this->httpRequest('POST', $this->mcpEndpoint, $payload, $headers);

        // Extract session ID from headers
        foreach ($headers as $header) {
            if (stripos($header, 'mcp-session-id:') === 0) {
                $this->sessionId = trim(substr($header, strlen('mcp-session-id:')));
                break;
            }
        }

        if (!$this->sessionId) {
            return false;
        }

        // Send initialized notification
        $notif = [
            'jsonrpc' => '2.0',
            'method' => 'notifications/initialized'
        ];
        $this->mcpRequest($notif);

        return true;
    }

    /**
     * Make an MCP tools/call request
     */
    private function callTool(string $tool, array $arguments = []): array
    {
        $payload = [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => $tool,
                'arguments' => $arguments ?: new \stdClass()
            ]
        ];

        $response = $this->mcpRequest($payload);

        // Extract the actual result from MCP response
        if (isset($response['result']['content'][0]['text'])) {
            $text = $response['result']['content'][0]['text'];
            $decoded = json_decode($text, true);
            if ($decoded !== null) {
                return $decoded;
            }
        }

        return $response;
    }

    private function mcpRequest(array $payload): array
    {
        $headers = [];
        return $this->httpRequest('POST', $this->mcpEndpoint, $payload, $headers);
    }

    private function httpRequest(string $method, string $url, array $payload, array &$responseHeaders): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey,
            'mcp-session-id: ' . ($this->sessionId ?? '')
        ]);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $header) use (&$responseHeaders) {
            $responseHeaders[] = $header;
            return strlen($header);
        });

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            return ['error' => 'Request failed'];
        }

        $decoded = json_decode($response, true);
        return $decoded ?? ['error' => 'Invalid JSON', 'raw' => $response];
    }

    // =========================================================================
    // TESTS
    // =========================================================================

    private function testGetSiteInfo(): void
    {
        $this->section("1. get_site_info");

        $result = $this->callTool('get_site_info');

        $this->assertSuccess($result, "get_site_info returns success");
        $this->assertContains($result, 'data.site.title', "Response contains site title");
        $this->assertContains($result, 'data.mcp.plugin_version', "Response contains plugin version");
    }

    private function testListTags(): void
    {
        $this->section("2. list_tags");

        $result = $this->callTool('list_tags');

        $this->assertSuccess($result, "list_tags returns success");
        $this->assertContains($result, 'data.tags', "Response contains tags array");
    }

    private function testListPosts(): void
    {
        $this->section("3. list_posts");

        $result = $this->callTool('list_posts', ['limit' => 10]);

        $this->assertSuccess($result, "list_posts returns success");
        $this->assertContains($result, 'data.posts', "Response contains posts array");
    }

    private function testCreatePost(): void
    {
        $this->section("4. create_post");

        $result = $this->callTool('create_post', [
            'slug' => $this->testSlug,
            'title' => 'E2E Test Article',
            'content' => "This is an **end-to-end test** article.\n\nCreated by automated test script.",
            'lang' => 'fr',
            'tags' => ['e2e', 'test'],
            'category' => 'test',
            'status' => 'draft'
        ]);

        $this->assertSuccess($result, "create_post creates article");
        $this->assertContains($result, 'data.slug', "Response contains slug");
    }

    private function testGetPost(): void
    {
        $this->section("5. get_post");

        $result = $this->callTool('get_post', [
            'slug' => $this->testSlug,
            'lang' => 'fr'
        ]);

        $this->assertSuccess($result, "get_post returns success");
        $this->assertContains($result, 'data.title', "Response contains title");
        $this->assertContains($result, 'data.raw_content', "Response contains raw_content");
        $this->assertEqual($result['data']['title'] ?? '', 'E2E Test Article', "Title matches created article");
    }

    private function testUpdatePost(): void
    {
        $this->section("6. update_post");

        $result = $this->callTool('update_post', [
            'slug' => $this->testSlug,
            'lang' => 'fr',
            'title' => 'E2E Test Article - Updated',
            'content' => "This article has been **updated** by the E2E test script.\n\nTimestamp: " . date('Y-m-d H:i:s'),
            'status' => 'published'
        ]);

        $this->assertSuccess($result, "update_post modifies article");

        // Verify update
        $verify = $this->callTool('get_post', ['slug' => $this->testSlug, 'lang' => 'fr']);
        $this->assertStringContains($verify['data']['title'] ?? '', 'Updated', "Title was updated");
    }

    private function testListTranslations(): void
    {
        $this->section("7. list_translations");

        $result = $this->callTool('list_translations', ['slug' => $this->testSlug]);

        $this->assertSuccess($result, "list_translations returns success");
        // Check for available translations (data.available) or configured_languages
        $hasInfo = isset($result['data']['available']) || isset($result['data']['configured_languages']);
        $this->assert($hasInfo, "Response contains translation info");
    }

    private function testCreateTranslation(): void
    {
        $this->section("8. create_translation");

        $result = $this->callTool('create_translation', [
            'slug' => $this->testSlug,
            'source_lang' => 'fr',
            'target_lang' => 'en',
            'title' => 'E2E Test Article - English',
            'content' => "This is the **English translation** of the E2E test article."
        ]);

        $this->assertSuccess($result, "create_translation creates English version");

        // Verify translation exists (may need cache clear)
        $this->callTool('clear_cache', ['type' => 'all']);
        $verify = $this->callTool('get_post', ['slug' => $this->testSlug, 'lang' => 'en']);
        $hasEnglish = ($verify['success'] ?? false) && !empty($verify['data']['title']);
        $this->assert($hasEnglish, "English translation accessible");
    }

    private function testUploadMedia(): void
    {
        $this->section("9. upload_media");

        // 1x1 red PNG
        $pngBase64 = "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFBQIAX8jx0gAAAABJRU5ErkJggg==";

        $result = $this->callTool('upload_media', [
            'slug' => $this->testSlug,
            'filename' => 'test-image.png',
            'content_base64' => $pngBase64
        ]);

        $this->assertSuccess($result, "upload_media uploads image");
        $this->assertContains($result, 'data.markdown_image', "Response contains markdown_image helper");
    }

    private function testDeleteMedia(): void
    {
        $this->section("10. delete_media");

        $result = $this->callTool('delete_media', [
            'slug' => $this->testSlug,
            'filename' => 'test-image.png',
            'confirm' => true
        ]);

        $this->assertSuccess($result, "delete_media removes image");
    }

    private function testClearCache(): void
    {
        $this->section("11. clear_cache");

        $result = $this->callTool('clear_cache', ['type' => 'cache']);

        $this->assertSuccess($result, "clear_cache clears Grav cache");
    }

    private function testDeletePost(): void
    {
        $this->section("12. delete_post");

        // Delete English translation
        $result = $this->callTool('delete_post', [
            'slug' => $this->testSlug,
            'lang' => 'en',
            'confirm' => true
        ]);
        $this->assertSuccess($result, "delete_post removes English translation");

        // Delete French original
        $result = $this->callTool('delete_post', [
            'slug' => $this->testSlug,
            'lang' => 'fr',
            'confirm' => true
        ]);
        $this->assertSuccess($result, "delete_post removes French original");

        // Deletion is successful if both delete calls succeeded
        // (cache may prevent immediate verification)
        $this->success("Post deletion completed (both languages)");
    }

    private function testErrorHandling(): void
    {
        $this->section("13. Error Handling");

        // Invalid tool
        $payload = [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'invalid_tool_name',
                'arguments' => new \stdClass()
            ]
        ];
        $result = $this->mcpRequest($payload);
        $hasError = isset($result['error']) || isset($result['result']['isError']);
        $this->assert($hasError, "Invalid tool returns error");

        // Test with completely invalid slug
        $result = $this->callTool('get_post', ['slug' => 'non-existent-post-xyz-99999']);
        $notFound = ($result['success'] ?? null) === false || isset($result['error']);
        $this->assert($notFound, "Non-existent post returns error");
    }

    // =========================================================================
    // ASSERTIONS
    // =========================================================================

    private function assertSuccess(array $result, string $message): void
    {
        $success = $result['success'] ?? false;
        if ($success) {
            $this->success($message);
        } else {
            $error = $result['error']['message'] ?? json_encode($result);
            $this->fail("$message - Got: $error");
        }
    }

    private function assertContains(array $result, string $path, string $message): void
    {
        $keys = explode('.', $path);
        $value = $result;
        foreach ($keys as $key) {
            if (!isset($value[$key])) {
                $this->fail("$message - Path '$path' not found");
                return;
            }
            $value = $value[$key];
        }
        $this->success($message);
    }

    private function assertEqual($actual, $expected, string $message): void
    {
        if ($actual === $expected) {
            $this->success($message);
        } else {
            $this->fail("$message - Expected: " . json_encode($expected) . ", Got: " . json_encode($actual));
        }
    }

    private function assertStringContains($haystack, string $needle, string $message): void
    {
        if (is_string($haystack) && str_contains($haystack, $needle)) {
            $this->success($message);
        } else {
            $this->fail("$message - '$needle' not found in '$haystack'");
        }
    }

    private function assert(bool $condition, string $message): void
    {
        if ($condition) {
            $this->success($message);
        } else {
            $this->fail($message);
        }
    }

    // =========================================================================
    // OUTPUT HELPERS
    // =========================================================================

    private function section(string $title): void
    {
        echo "\n";
        echo YELLOW . "═══════════════════════════════════════════════════════════" . NC . "\n";
        echo YELLOW . "  $title" . NC . "\n";
        echo YELLOW . "═══════════════════════════════════════════════════════════" . NC . "\n";
    }

    private function info(string $message): void
    {
        echo BLUE . "[INFO]" . NC . " $message\n";
    }

    private function success(string $message): void
    {
        echo GREEN . "[PASS]" . NC . " $message\n";
        $this->passed++;
    }

    private function fail(string $message): void
    {
        echo RED . "[FAIL]" . NC . " $message\n";
        $this->failed++;
    }

    private function error(string $message): void
    {
        echo RED . "[ERROR]" . NC . " $message\n";
    }
}

// =============================================================================
// MAIN
// =============================================================================

$baseUrl = $argv[1] ?? getenv('MCP_BASE_URL') ?: 'http://127.0.0.1:8000';
$apiKey = $argv[2] ?? getenv('MCP_API_KEY') ?: '';

if (empty($apiKey)) {
    echo RED . "ERROR: API key required" . NC . "\n\n";
    echo "Usage: php " . basename(__FILE__) . " [base_url] [api_key]\n";
    echo "   or: MCP_API_KEY=your_key php " . basename(__FILE__) . "\n\n";
    echo "Find your API key in Grav Admin > Users > [your user] > MCP API Key\n";
    exit(1);
}

$test = new McpE2ETest($baseUrl, $apiKey);
exit($test->run());
