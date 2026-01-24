<?php

declare(strict_types=1);

namespace Grav\Plugin\Mcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Grav\Plugin\Mcp\Services\ContentManager;

/**
 * Unit tests for ContentManager
 * Tests the logic of methods that had bugs fixed
 */
class ContentManagerTest extends TestCase
{
    /**
     * Test that isValidSlug correctly validates slug format
     */
    public function testValidSlugFormat(): void
    {
        $reflection = new \ReflectionClass(ContentManager::class);
        $method = $reflection->getMethod('isValidSlug');
        $method->setAccessible(true);

        // Create a mock ContentManager (we just need access to the protected method)
        $grav = $this->createMockGrav();
        $manager = new ContentManager($grav);

        // Valid slugs
        $this->assertTrue($method->invoke($manager, 'my-article'));
        $this->assertTrue($method->invoke($manager, 'article'));
        $this->assertTrue($method->invoke($manager, 'article-2024'));
        $this->assertTrue($method->invoke($manager, 'my-long-article-title'));
        $this->assertTrue($method->invoke($manager, 'a1b2c3'));

        // Invalid slugs
        $this->assertFalse($method->invoke($manager, 'My-Article')); // uppercase
        $this->assertFalse($method->invoke($manager, 'article_name')); // underscore
        $this->assertFalse($method->invoke($manager, 'article--name')); // double dash
        $this->assertFalse($method->invoke($manager, '-article')); // starts with dash
        $this->assertFalse($method->invoke($manager, 'article-')); // ends with dash
        $this->assertFalse($method->invoke($manager, 'article name')); // space
        $this->assertFalse($method->invoke($manager, 'article.name')); // dot
        $this->assertFalse($method->invoke($manager, '')); // empty
    }

    /**
     * Test getTranslations returns correct format (lang => url mapping, not Page objects)
     * This was a bug where we assumed translatedLanguages() returned Page objects
     */
    public function testGetTranslationsReturnsUrlMapping(): void
    {
        $grav = $this->createMockGrav();
        $manager = new ContentManager($grav);

        // Create a mock page that returns translations as lang => url (the correct format)
        $mockPage = $this->createMock(\Grav\Common\Page\Page::class);
        $mockPage->method('translatedLanguages')->willReturn([
            'fr' => '/blog/mon-article',
            'en' => '/en/blog/mon-article'
        ]);

        // Use reflection to test the protected method
        $reflection = new \ReflectionClass(ContentManager::class);
        $method = $reflection->getMethod('getTranslations');
        $method->setAccessible(true);

        $result = $method->invoke($manager, $mockPage);

        // Verify the result structure
        $this->assertIsArray($result);
        $this->assertArrayHasKey('fr', $result);
        $this->assertArrayHasKey('en', $result);

        // Each translation should have 'url' and 'exists' keys
        $this->assertArrayHasKey('url', $result['fr']);
        $this->assertArrayHasKey('exists', $result['fr']);
        $this->assertEquals('/blog/mon-article', $result['fr']['url']);
        $this->assertTrue($result['fr']['exists']);

        $this->assertArrayHasKey('url', $result['en']);
        $this->assertArrayHasKey('exists', $result['en']);
        $this->assertEquals('/en/blog/mon-article', $result['en']['url']);
        $this->assertTrue($result['en']['exists']);
    }

    /**
     * Test getTranslations handles empty translations
     */
    public function testGetTranslationsHandlesEmptyArray(): void
    {
        $grav = $this->createMockGrav();
        $manager = new ContentManager($grav);

        $mockPage = $this->createMock(\Grav\Common\Page\Page::class);
        $mockPage->method('translatedLanguages')->willReturn([]);

        $reflection = new \ReflectionClass(ContentManager::class);
        $method = $reflection->getMethod('getTranslations');
        $method->setAccessible(true);

        $result = $method->invoke($manager, $mockPage);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Test formatPostSummary returns expected structure
     */
    public function testFormatPostSummaryStructure(): void
    {
        $grav = $this->createMockGrav();
        $manager = new ContentManager($grav);

        $mockPage = $this->createMock(\Grav\Common\Page\Page::class);
        $mockPage->method('slug')->willReturn('test-article');
        $mockPage->method('title')->willReturn('Test Article');
        $mockPage->method('date')->willReturn(1704067200);
        $mockPage->method('language')->willReturn('fr');
        $mockPage->method('taxonomy')->willReturn(['tag' => ['php', 'test'], 'category' => ['blog']]);
        $mockPage->method('published')->willReturn(true);
        $mockPage->method('summary')->willReturn('Article summary...');
        $mockPage->method('url')->willReturn('http://localhost/blog/test-article');

        $reflection = new \ReflectionClass(ContentManager::class);
        $method = $reflection->getMethod('formatPostSummary');
        $method->setAccessible(true);

        $result = $method->invoke($manager, $mockPage);

        $this->assertIsArray($result);
        $this->assertEquals('test-article', $result['slug']);
        $this->assertEquals('Test Article', $result['title']);
        $this->assertEquals(1704067200, $result['date']);
        $this->assertEquals('fr', $result['lang']);
        $this->assertEquals(['php', 'test'], $result['tags']);
        $this->assertEquals('blog', $result['category']);
        $this->assertEquals('published', $result['status']);
        $this->assertEquals('Article summary...', $result['excerpt']);
    }

    /**
     * Test formatPostFull returns expected structure with all fields
     */
    public function testFormatPostFullStructure(): void
    {
        $grav = $this->createMockGrav();
        $manager = new ContentManager($grav);

        // Create mock media using anonymous class (avoids interface dependency)
        $mockMedia = new class {
            public function all(): array {
                $file = new \stdClass();
                $file->mime = 'image/jpeg';
                $file->size = 12345;
                return ['image.jpg' => $file];
            }
        };

        $mockPage = $this->createMock(\Grav\Common\Page\Page::class);
        $mockPage->method('slug')->willReturn('test-article');
        $mockPage->method('title')->willReturn('Test Article');
        $mockPage->method('content')->willReturn('<p>Full content here</p>');
        $mockPage->method('rawMarkdown')->willReturn('Full content here');
        $mockPage->method('header')->willReturn((object)['title' => 'Test Article', 'date' => '2024-01-01']);
        $mockPage->method('language')->willReturn('fr');
        $mockPage->method('taxonomy')->willReturn(['tag' => ['php']]);
        $mockPage->method('translatedLanguages')->willReturn(['fr' => '/blog/test']);
        $mockPage->method('media')->willReturn($mockMedia);
        $mockPage->method('url')->willReturn('http://localhost/blog/test-article');
        $mockPage->method('modified')->willReturn(1704067200);

        $reflection = new \ReflectionClass(ContentManager::class);
        $method = $reflection->getMethod('formatPostFull');
        $method->setAccessible(true);

        $result = $method->invoke($manager, $mockPage, null);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('slug', $result);
        $this->assertArrayHasKey('title', $result);
        $this->assertArrayHasKey('content', $result);
        $this->assertArrayHasKey('raw_content', $result);
        $this->assertArrayHasKey('frontmatter', $result);
        $this->assertArrayHasKey('lang', $result);
        $this->assertArrayHasKey('translations', $result);
        $this->assertArrayHasKey('media', $result);
        $this->assertArrayHasKey('word_count', $result);
        $this->assertArrayHasKey('reading_time', $result);
        $this->assertArrayHasKey('url', $result);
        $this->assertArrayHasKey('modified', $result);
    }

    /**
     * Test that word count and reading time are calculated correctly
     */
    public function testWordCountAndReadingTime(): void
    {
        $grav = $this->createMockGrav();
        $manager = new ContentManager($grav);

        // Create mock media using anonymous class
        $mockMedia = new class {
            public function all(): array { return []; }
        };

        // 200 words = 1 minute reading time
        $content = str_repeat('word ', 200);

        $mockPage = $this->createMock(\Grav\Common\Page\Page::class);
        $mockPage->method('slug')->willReturn('test');
        $mockPage->method('title')->willReturn('Test');
        $mockPage->method('content')->willReturn("<p>{$content}</p>");
        $mockPage->method('rawMarkdown')->willReturn($content);
        $mockPage->method('header')->willReturn((object)[]);
        $mockPage->method('language')->willReturn('en');
        $mockPage->method('taxonomy')->willReturn([]);
        $mockPage->method('translatedLanguages')->willReturn([]);
        $mockPage->method('media')->willReturn($mockMedia);
        $mockPage->method('url')->willReturn('http://localhost/test');
        $mockPage->method('modified')->willReturn(time());

        $reflection = new \ReflectionClass(ContentManager::class);
        $method = $reflection->getMethod('formatPostFull');
        $method->setAccessible(true);

        $result = $method->invoke($manager, $mockPage, null);

        $this->assertEquals(200, $result['word_count']);
        $this->assertEquals(1, $result['reading_time']);
    }

    /**
     * Test reading time rounds up correctly
     */
    public function testReadingTimeRoundsUp(): void
    {
        $grav = $this->createMockGrav();
        $manager = new ContentManager($grav);

        // Create mock media using anonymous class
        $mockMedia = new class {
            public function all(): array { return []; }
        };

        // 250 words = 1.25 minutes = rounds to 2 minutes
        $content = str_repeat('word ', 250);

        $mockPage = $this->createMock(\Grav\Common\Page\Page::class);
        $mockPage->method('slug')->willReturn('test');
        $mockPage->method('title')->willReturn('Test');
        $mockPage->method('content')->willReturn("<p>{$content}</p>");
        $mockPage->method('rawMarkdown')->willReturn($content);
        $mockPage->method('header')->willReturn((object)[]);
        $mockPage->method('language')->willReturn('en');
        $mockPage->method('taxonomy')->willReturn([]);
        $mockPage->method('translatedLanguages')->willReturn([]);
        $mockPage->method('media')->willReturn($mockMedia);
        $mockPage->method('url')->willReturn('http://localhost/test');
        $mockPage->method('modified')->willReturn(time());

        $reflection = new \ReflectionClass(ContentManager::class);
        $method = $reflection->getMethod('formatPostFull');
        $method->setAccessible(true);

        $result = $method->invoke($manager, $mockPage, null);

        $this->assertEquals(250, $result['word_count']);
        $this->assertEquals(2, $result['reading_time']); // ceil(250/200) = 2
    }

    /**
     * Create a mock Grav instance
     */
    private function createMockGrav(): \Grav\Common\Grav
    {
        $mockConfig = $this->createMock(\Grav\Common\Config\Config::class);
        $mockConfig->method('get')->willReturnCallback(function ($key, $default = null) {
            $values = [
                'plugins.mcp' => ['blog_route' => '/blog'],
                'system.languages.default' => 'en',
                'system.languages.supported' => ['fr', 'en'],
            ];
            return $values[$key] ?? $default;
        });

        $mockGrav = $this->createMock(\Grav\Common\Grav::class);
        $mockGrav->method('offsetGet')->willReturnCallback(function ($key) use ($mockConfig) {
            if ($key === 'config') {
                return $mockConfig;
            }
            return null;
        });

        return $mockGrav;
    }
}
