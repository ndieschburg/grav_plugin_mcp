<?php

declare(strict_types=1);

namespace Grav\Plugin\Mcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Grav\Plugin\Mcp\Services\MediaManager;
use Grav\Common\Grav;
use Grav\Common\Config\Config;
use Grav\Common\Page\Pages;
use Grav\Common\Page\Page;

/**
 * Unit tests for MediaManager
 * Tests media upload, delete, and validation
 */
class MediaManagerTest extends TestCase
{
    private string $tempDir;

    // Valid JPEG magic bytes (FFD8FF) followed by some content
    private const VALID_JPEG_CONTENT = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01test";

    // Valid PNG magic bytes
    private const VALID_PNG_CONTENT = "\x89\x50\x4E\x47\x0D\x0A\x1A\x0Atest";

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/mcp-media-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        // Clean up temp directory
        if (is_dir($this->tempDir)) {
            $files = glob($this->tempDir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($this->tempDir);
        }
    }

    /**
     * Test upload validates file extension
     */
    public function testUploadValidatesFileExtension(): void
    {
        $grav = $this->createMockGrav();
        $manager = new MediaManager($grav);

        $result = $manager->upload([
            'slug' => 'test-post',
            'filename' => 'malware.exe',
            'content_base64' => base64_encode('malware content')
        ]);

        $this->assertFalse($result['success']);
        $this->assertEquals('INVALID_MEDIA_TYPE', $result['error']['code']);
    }

    /**
     * Test upload accepts valid extensions
     */
    public function testUploadAcceptsValidExtensions(): void
    {
        $validExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'pdf', 'zip', 'mp4', 'webm'];

        foreach ($validExtensions as $ext) {
            $grav = $this->createMockGravWithPage();
            $manager = new MediaManager($grav);

            $result = $manager->upload([
                'slug' => 'test-post',
                'filename' => "test.{$ext}",
                'content_base64' => base64_encode('test content')
            ]);

            // Should not fail on extension validation
            $this->assertNotEquals('INVALID_MEDIA_TYPE', $result['error']['code'] ?? '');
        }
    }

    /**
     * Test upload prevents path traversal in filename
     */
    public function testUploadPreventsPathTraversal(): void
    {
        $grav = $this->createMockGrav();
        $manager = new MediaManager($grav);

        $maliciousFilenames = [
            '../../../etc/passwd.jpg',
            '..\\..\\windows\\system32\\config.jpg',
            'test/../../etc/passwd.jpg',
            'image\\..\\..\\.jpg'
        ];

        foreach ($maliciousFilenames as $filename) {
            $result = $manager->upload([
                'slug' => 'test-post',
                'filename' => $filename,
                'content_base64' => base64_encode('test content')
            ]);

            $this->assertFalse($result['success'], "Should reject filename: {$filename}");
            $this->assertEquals('VALIDATION_ERROR', $result['error']['code']);
        }
    }

    /**
     * Test upload validates base64 content
     */
    public function testUploadValidatesBase64Content(): void
    {
        $grav = $this->createMockGrav();
        $manager = new MediaManager($grav);

        $result = $manager->upload([
            'slug' => 'test-post',
            'filename' => 'image.jpg',
            'content_base64' => 'not-valid-base64!!!'
        ]);

        $this->assertFalse($result['success']);
        $this->assertEquals('VALIDATION_ERROR', $result['error']['code']);
    }

    /**
     * Test upload checks file size limit
     */
    public function testUploadChecksFileSizeLimit(): void
    {
        $grav = $this->createMockGrav();
        $manager = new MediaManager($grav);

        // Create content larger than 10MB
        $largeContent = str_repeat('x', 11 * 1024 * 1024);

        $result = $manager->upload([
            'slug' => 'test-post',
            'filename' => 'large.jpg',
            'content_base64' => base64_encode($largeContent)
        ]);

        $this->assertFalse($result['success']);
        $this->assertEquals('MEDIA_TOO_LARGE', $result['error']['code']);
    }

    /**
     * Test upload returns error when post not found
     */
    public function testUploadReturnsErrorWhenPostNotFound(): void
    {
        $mockPages = $this->createMock(Pages::class);
        $mockPages->method('find')->willReturn(null);

        $grav = $this->createMockGrav(['pages' => $mockPages]);
        $manager = new MediaManager($grav);

        $result = $manager->upload([
            'slug' => 'non-existent-post',
            'filename' => 'image.jpg',
            'content_base64' => base64_encode(self::VALID_JPEG_CONTENT)
        ]);

        $this->assertFalse($result['success']);
        $this->assertEquals('NOT_FOUND', $result['error']['code']);
    }

    /**
     * Test upload prevents overwrite by default
     */
    public function testUploadPreventsOverwriteByDefault(): void
    {
        // Create existing file
        file_put_contents($this->tempDir . '/existing.jpg', 'existing content');

        $mockPage = $this->createMock(Page::class);
        $mockPage->method('path')->willReturn($this->tempDir);

        $mockPages = $this->createMock(Pages::class);
        $mockPages->method('find')->willReturn($mockPage);

        $grav = $this->createMockGrav(['pages' => $mockPages]);
        $manager = new MediaManager($grav);

        $result = $manager->upload([
            'slug' => 'test-post',
            'filename' => 'existing.jpg',
            'content_base64' => base64_encode(self::VALID_JPEG_CONTENT),
            'overwrite' => false
        ]);

        $this->assertFalse($result['success']);
        $this->assertEquals('FILE_EXISTS', $result['error']['code']);
    }

    /**
     * Test upload allows overwrite when specified
     */
    public function testUploadAllowsOverwriteWhenSpecified(): void
    {
        // Create existing file
        file_put_contents($this->tempDir . '/existing.jpg', 'existing content');

        $mockPage = $this->createMock(Page::class);
        $mockPage->method('path')->willReturn($this->tempDir);

        $mockPages = $this->createMock(Pages::class);
        $mockPages->method('find')->willReturn($mockPage);

        $grav = $this->createMockGrav(['pages' => $mockPages]);
        $manager = new MediaManager($grav);

        $result = $manager->upload([
            'slug' => 'test-post',
            'filename' => 'existing.jpg',
            'content_base64' => base64_encode(self::VALID_JPEG_CONTENT),
            'overwrite' => true
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals(self::VALID_JPEG_CONTENT, file_get_contents($this->tempDir . '/existing.jpg'));
    }

    /**
     * Test upload returns success with correct data
     */
    public function testUploadReturnsSuccessWithCorrectData(): void
    {
        $mockPage = $this->createMock(Page::class);
        $mockPage->method('path')->willReturn($this->tempDir);

        $mockPages = $this->createMock(Pages::class);
        $mockPages->method('find')->willReturn($mockPage);

        $grav = $this->createMockGrav(['pages' => $mockPages]);
        $manager = new MediaManager($grav);

        $content = self::VALID_JPEG_CONTENT;
        $result = $manager->upload([
            'slug' => 'test-post',
            'filename' => 'test-image.jpg',
            'content_base64' => base64_encode($content)
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals('test-image.jpg', $result['data']['filename']); // dashes are allowed, no sanitization needed
        $this->assertEquals(strlen($content), $result['data']['size']);
        $this->assertStringContainsString('![test-image.jpg]', $result['data']['markdown_image']);
        $this->assertStringContainsString('[test-image.jpg]', $result['data']['markdown_link']);

        // Verify file was created
        $this->assertFileExists($this->tempDir . '/test-image.jpg');
    }

    /**
     * Test delete prevents path traversal in filename
     */
    public function testDeletePreventsPathTraversal(): void
    {
        $grav = $this->createMockGrav();
        $manager = new MediaManager($grav);

        $maliciousFilenames = [
            '../../../etc/passwd',
            '..\\..\\important.txt',
            'sub/../../../etc/passwd'
        ];

        foreach ($maliciousFilenames as $filename) {
            $result = $manager->delete('test-post', $filename);

            $this->assertFalse($result['success'], "Should reject filename: {$filename}");
            $this->assertEquals('VALIDATION_ERROR', $result['error']['code']);
        }
    }

    /**
     * Test delete returns error when post not found
     */
    public function testDeleteReturnsErrorWhenPostNotFound(): void
    {
        $mockPages = $this->createMock(Pages::class);
        $mockPages->method('find')->willReturn(null);

        $grav = $this->createMockGrav(['pages' => $mockPages]);
        $manager = new MediaManager($grav);

        $result = $manager->delete('non-existent-post', 'image.jpg');

        $this->assertFalse($result['success']);
        $this->assertEquals('NOT_FOUND', $result['error']['code']);
    }

    /**
     * Test delete returns error when file not found
     */
    public function testDeleteReturnsErrorWhenFileNotFound(): void
    {
        $mockPage = $this->createMock(Page::class);
        $mockPage->method('path')->willReturn($this->tempDir);

        $mockPages = $this->createMock(Pages::class);
        $mockPages->method('find')->willReturn($mockPage);

        $grav = $this->createMockGrav(['pages' => $mockPages]);
        $manager = new MediaManager($grav);

        $result = $manager->delete('test-post', 'non-existent.jpg');

        $this->assertFalse($result['success']);
        $this->assertEquals('NOT_FOUND', $result['error']['code']);
    }

    /**
     * Test delete successfully removes file
     */
    public function testDeleteSuccessfullyRemovesFile(): void
    {
        // Create file to delete
        file_put_contents($this->tempDir . '/to-delete.jpg', 'content');

        $mockPage = $this->createMock(Page::class);
        $mockPage->method('path')->willReturn($this->tempDir);

        $mockPages = $this->createMock(Pages::class);
        $mockPages->method('find')->willReturn($mockPage);

        $grav = $this->createMockGrav(['pages' => $mockPages]);
        $manager = new MediaManager($grav);

        $result = $manager->delete('test-post', 'to-delete.jpg');

        $this->assertTrue($result['success']);
        $this->assertEquals('to-delete.jpg', $result['data']['filename']);
        $this->assertTrue($result['data']['deleted']);
        $this->assertFileDoesNotExist($this->tempDir . '/to-delete.jpg');
    }

    /**
     * Test allowed extensions list
     */
    public function testAllowedExtensionsList(): void
    {
        $grav = $this->createMockGrav();
        $manager = new MediaManager($grav);

        // Use reflection to get protected property
        $reflection = new \ReflectionClass($manager);
        $prop = $reflection->getProperty('allowedExtensions');
        $prop->setAccessible(true);
        $extensions = $prop->getValue($manager);

        // Verify expected extensions are allowed
        $expected = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'pdf', 'zip', 'mp4', 'webm'];
        foreach ($expected as $ext) {
            $this->assertContains($ext, $extensions, "Extension {$ext} should be allowed");
        }

        // Verify dangerous extensions are NOT allowed
        $dangerous = ['exe', 'sh', 'php', 'js', 'html', 'htaccess'];
        foreach ($dangerous as $ext) {
            $this->assertNotContains($ext, $extensions, "Extension {$ext} should NOT be allowed");
        }
    }

    /**
     * Test max file size constant
     */
    public function testMaxFileSizeConstant(): void
    {
        $grav = $this->createMockGrav();
        $manager = new MediaManager($grav);

        $reflection = new \ReflectionClass($manager);
        $prop = $reflection->getProperty('maxFileSize');
        $prop->setAccessible(true);
        $maxSize = $prop->getValue($manager);

        // Should be 10 MB
        $this->assertEquals(10 * 1024 * 1024, $maxSize);
    }

    /**
     * Test upload validates magic bytes (file content matches extension)
     */
    public function testUploadValidatesMagicBytes(): void
    {
        $mockPage = $this->createMock(Page::class);
        $mockPage->method('path')->willReturn($this->tempDir);

        $mockPages = $this->createMock(Pages::class);
        $mockPages->method('find')->willReturn($mockPage);

        $grav = $this->createMockGrav(['pages' => $mockPages]);
        $manager = new MediaManager($grav);

        // Try to upload PNG content with JPG extension
        $result = $manager->upload([
            'slug' => 'test-post',
            'filename' => 'fake.jpg',
            'content_base64' => base64_encode(self::VALID_PNG_CONTENT)
        ]);

        $this->assertFalse($result['success']);
        $this->assertEquals('INVALID_FILE_CONTENT', $result['error']['code']);
    }

    /**
     * Test upload accepts valid magic bytes
     */
    public function testUploadAcceptsValidMagicBytes(): void
    {
        $mockPage = $this->createMock(Page::class);
        $mockPage->method('path')->willReturn($this->tempDir);

        $mockPages = $this->createMock(Pages::class);
        $mockPages->method('find')->willReturn($mockPage);

        $grav = $this->createMockGrav(['pages' => $mockPages]);
        $manager = new MediaManager($grav);

        // Upload valid JPEG
        $result = $manager->upload([
            'slug' => 'test-post',
            'filename' => 'valid.jpg',
            'content_base64' => base64_encode(self::VALID_JPEG_CONTENT)
        ]);

        $this->assertTrue($result['success']);
    }

    /**
     * Test SVG validation rejects malicious content
     */
    public function testSvgValidationRejectsMaliciousContent(): void
    {
        $mockPage = $this->createMock(Page::class);
        $mockPage->method('path')->willReturn($this->tempDir);

        $mockPages = $this->createMock(Pages::class);
        $mockPages->method('find')->willReturn($mockPage);

        $grav = $this->createMockGrav(['pages' => $mockPages]);
        $manager = new MediaManager($grav);

        $maliciousSvgs = [
            '<svg><script>alert("xss")</script></svg>',
            '<svg onload="alert(1)"></svg>',
            '<svg><a href="javascript:alert(1)">click</a></svg>',
        ];

        foreach ($maliciousSvgs as $svg) {
            $result = $manager->upload([
                'slug' => 'test-post',
                'filename' => 'malicious.svg',
                'content_base64' => base64_encode($svg)
            ]);

            $this->assertFalse($result['success'], "Should reject: " . substr($svg, 0, 50));
            $this->assertEquals('INVALID_FILE_CONTENT', $result['error']['code']);
        }
    }

    /**
     * Test SVG validation accepts safe content
     */
    public function testSvgValidationAcceptsSafeContent(): void
    {
        $mockPage = $this->createMock(Page::class);
        $mockPage->method('path')->willReturn($this->tempDir);

        $mockPages = $this->createMock(Pages::class);
        $mockPages->method('find')->willReturn($mockPage);

        $grav = $this->createMockGrav(['pages' => $mockPages]);
        $manager = new MediaManager($grav);

        $safeSvg = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"><rect width="100" height="100"/></svg>';

        $result = $manager->upload([
            'slug' => 'test-post',
            'filename' => 'safe.svg',
            'content_base64' => base64_encode($safeSvg)
        ]);

        $this->assertTrue($result['success']);
    }

    /**
     * Test filename sanitization
     */
    public function testFilenameSanitization(): void
    {
        $mockPage = $this->createMock(Page::class);
        $mockPage->method('path')->willReturn($this->tempDir);

        $mockPages = $this->createMock(Pages::class);
        $mockPages->method('find')->willReturn($mockPage);

        $grav = $this->createMockGrav(['pages' => $mockPages]);
        $manager = new MediaManager($grav);

        // Test various filenames get sanitized
        $result = $manager->upload([
            'slug' => 'test-post',
            'filename' => 'my file (copy).jpg',
            'content_base64' => base64_encode(self::VALID_JPEG_CONTENT)
        ]);

        $this->assertTrue($result['success']);
        // Spaces, parentheses should be replaced with underscores
        $this->assertEquals('my_file_copy.jpg', $result['data']['filename']);
    }

    /**
     * Create a mock Grav instance
     */
    private function createMockGrav(array $overrides = []): Grav
    {
        $mockConfig = $this->createMock(Config::class);
        $mockConfig->method('get')->willReturnCallback(function ($key, $default = null) {
            $values = [
                'plugins.mcp' => ['blog_route' => '/blog'],
            ];
            return $values[$key] ?? $default;
        });

        $mockPages = $overrides['pages'] ?? $this->createMock(Pages::class);

        $mockGrav = $this->createMock(Grav::class);
        $mockGrav->method('offsetGet')->willReturnCallback(
            function ($key) use ($mockConfig, $mockPages) {
                return match ($key) {
                    'config' => $mockConfig,
                    'pages' => $mockPages,
                    default => null,
                };
            }
        );

        return $mockGrav;
    }

    /**
     * Create mock Grav with a page that returns temp directory
     */
    private function createMockGravWithPage(): Grav
    {
        $mockPage = $this->createMock(Page::class);
        $mockPage->method('path')->willReturn($this->tempDir);

        $mockPages = $this->createMock(Pages::class);
        $mockPages->method('find')->willReturn($mockPage);

        return $this->createMockGrav(['pages' => $mockPages]);
    }
}
