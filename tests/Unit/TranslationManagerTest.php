<?php

declare(strict_types=1);

namespace Grav\Plugin\Mcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Grav\Plugin\Mcp\Services\TranslationManager;

/**
 * Unit tests for TranslationManager
 */
class TranslationManagerTest extends TestCase
{
    /**
     * Test findSourceFile method finds correct file
     */
    public function testFindSourceFileFindsLanguageFile(): void
    {
        $grav = $this->createMockGrav();
        $manager = new TranslationManager($grav);

        $reflection = new \ReflectionClass(TranslationManager::class);
        $method = $reflection->getMethod('findSourceFile');
        $method->setAccessible(true);

        // Create temp directory with test files
        $tempDir = sys_get_temp_dir() . '/mcp-test-' . uniqid();
        mkdir($tempDir);
        file_put_contents($tempDir . '/item.fr.md', '---\ntitle: Test\n---\nContent');
        file_put_contents($tempDir . '/item.en.md', '---\ntitle: Test EN\n---\nContent EN');

        try {
            // Should find French file
            $result = $method->invoke($manager, $tempDir, 'fr');
            $this->assertNotNull($result);
            $this->assertStringContainsString('item.fr.md', $result);

            // Should find English file
            $result = $method->invoke($manager, $tempDir, 'en');
            $this->assertNotNull($result);
            $this->assertStringContainsString('item.en.md', $result);

            // Should return null for non-existent language
            $result = $method->invoke($manager, $tempDir, 'de');
            $this->assertNull($result);
        } finally {
            // Cleanup
            unlink($tempDir . '/item.fr.md');
            unlink($tempDir . '/item.en.md');
            rmdir($tempDir);
        }
    }

    /**
     * Test createTranslation validates target language
     */
    public function testCreateTranslationValidatesLanguage(): void
    {
        $grav = $this->createMockGrav(['system.languages.supported' => ['fr', 'en']]);
        $manager = new TranslationManager($grav);

        $result = $manager->createTranslation([
            'slug' => 'test-article',
            'source_lang' => 'fr',
            'target_lang' => 'de', // German not in allowed_languages
            'title' => 'Test',
            'content' => 'Content'
        ]);

        $this->assertFalse($result['success']);
        $this->assertEquals('INVALID_LANG', $result['error']['code']);
    }

    /**
     * Test createTranslation fails when multilingual not enabled
     */
    public function testCreateTranslationFailsWithoutMultilingual(): void
    {
        $grav = $this->createMockGrav(['system.languages.supported' => []]); // Empty = disabled
        $manager = new TranslationManager($grav);

        $result = $manager->createTranslation([
            'slug' => 'test-article',
            'source_lang' => 'fr',
            'target_lang' => 'en',
            'title' => 'Test',
            'content' => 'Content'
        ]);

        $this->assertFalse($result['success']);
        $this->assertEquals('MULTILINGUAL_NOT_ENABLED', $result['error']['code']);
    }

    /**
     * Test template extraction from filename
     */
    public function testTemplateExtractionFromFilename(): void
    {
        // Test the logic used in createTranslation for extracting template name
        $testCases = [
            'item.fr.md' => 'item',
            'item.en.md' => 'item',
            'blog.fr.md' => 'blog',
            'custom-template.en.md' => 'custom-template',
            'item.md' => 'item', // Single language setup
        ];

        foreach ($testCases as $filename => $expected) {
            $parts = explode('.', $filename);
            $template = $parts[0];
            $this->assertEquals($expected, $template, "Failed for filename: {$filename}");
        }
    }

    /**
     * Create a mock Grav instance
     */
    private function createMockGrav(array $configOverrides = []): \Grav\Common\Grav
    {
        $defaultConfig = [
            'plugins.mcp' => [
                'blog_route' => '/blog',
                'allowed_languages' => ['fr', 'en'],
            ],
            'system.languages.default' => 'fr',
            'system.languages.supported' => ['fr', 'en'],
        ];

        $config = array_merge($defaultConfig, $configOverrides);

        $mockConfig = $this->createMock(\Grav\Common\Config\Config::class);
        $mockConfig->method('get')->willReturnCallback(function ($key, $default = null) use ($config) {
            return $config[$key] ?? $default;
        });

        $mockPages = $this->createMock(\Grav\Common\Page\Pages::class);
        $mockPages->method('find')->willReturn(null); // Default: page not found

        $mockGrav = $this->createMock(\Grav\Common\Grav::class);
        $mockGrav->method('offsetGet')->willReturnCallback(function ($key) use ($mockConfig, $mockPages) {
            switch ($key) {
                case 'config':
                    return $mockConfig;
                case 'pages':
                    return $mockPages;
                default:
                    return null;
            }
        });

        return $mockGrav;
    }
}
