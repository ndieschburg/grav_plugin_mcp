<?php

namespace Grav\Plugin\Mcp\Services;

use Grav\Common\Grav;

/**
 * Media Manager Service
 * Handles media file operations for posts
 *
 * Security features:
 * - Extension whitelist validation
 * - Magic bytes (file signature) validation
 * - Path traversal prevention
 * - File size limits
 * - Sanitized filenames
 */
class MediaManager
{
    protected Grav $grav;
    protected array $config;

    protected array $allowedExtensions = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'pdf', 'zip', 'mp4', 'webm'
    ];

    // Magic bytes for file type validation
    // https://en.wikipedia.org/wiki/List_of_file_signatures
    protected array $magicBytes = [
        'jpg'  => ["\xFF\xD8\xFF"],
        'jpeg' => ["\xFF\xD8\xFF"],
        'png'  => ["\x89\x50\x4E\x47\x0D\x0A\x1A\x0A"],
        'gif'  => ["\x47\x49\x46\x38\x37\x61", "\x47\x49\x46\x38\x39\x61"],  // GIF87a, GIF89a
        'webp' => ["\x52\x49\x46\x46"],  // RIFF (WebP starts with RIFF)
        'pdf'  => ["\x25\x50\x44\x46"],  // %PDF
        'zip'  => ["\x50\x4B\x03\x04", "\x50\x4B\x05\x06", "\x50\x4B\x07\x08"],  // PK signatures
        'mp4'  => ["\x00\x00\x00\x18\x66\x74\x79\x70", "\x00\x00\x00\x1C\x66\x74\x79\x70", "\x00\x00\x00\x20\x66\x74\x79\x70"],  // ftyp variations
        'webm' => ["\x1A\x45\xDF\xA3"],  // EBML header
        // SVG is XML-based, handled separately
        'svg'  => [],
    ];

    protected int $maxFileSize = 10 * 1024 * 1024; // 10 MB

    public function __construct(Grav $grav)
    {
        $this->grav = $grav;
        $this->config = $grav['config']->get('plugins.mcp', []);
    }

    /**
     * Upload a media file
     */
    public function upload(array $args): array
    {
        $slug = $args['slug'];
        $filename = $args['filename'];
        $contentBase64 = $args['content_base64'];
        $overwrite = $args['overwrite'] ?? false;

        // Validate extension
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($extension, $this->allowedExtensions)) {
            return [
                'success' => false,
                'error' => [
                    'code' => 'INVALID_MEDIA_TYPE',
                    'message' => "File type not allowed: {$extension}"
                ]
            ];
        }

        // Validate filename (no path traversal)
        if (str_contains($filename, '..') || str_contains($filename, '/') || str_contains($filename, '\\')) {
            return [
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Invalid filename'
                ]
            ];
        }

        // Decode content
        $content = base64_decode($contentBase64, true);
        if ($content === false) {
            return [
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Invalid base64 content'
                ]
            ];
        }

        // Check file size
        $size = strlen($content);
        if ($size > $this->maxFileSize) {
            return [
                'success' => false,
                'error' => [
                    'code' => 'MEDIA_TOO_LARGE',
                    'message' => "File too large (max {$this->maxFileSize} bytes)"
                ]
            ];
        }

        // Validate file content matches extension (magic bytes check)
        if (!$this->validateFileContent($content, $extension)) {
            return [
                'success' => false,
                'error' => [
                    'code' => 'INVALID_FILE_CONTENT',
                    'message' => "File content does not match extension: {$extension}"
                ]
            ];
        }

        // Sanitize filename (remove any potentially dangerous characters)
        $filename = $this->sanitizeFilename($filename);

        // Find post directory
        $blogRoute = $this->config['blog_route'] ?? '/blog';
        $page = $this->grav['pages']->find($blogRoute . '/' . $slug);

        if (!$page) {
            return [
                'success' => false,
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => "Post not found: {$slug}"
                ]
            ];
        }

        $postDir = $page->path();
        $filepath = $postDir . '/' . $filename;

        // Check if exists
        if (file_exists($filepath) && !$overwrite) {
            return [
                'success' => false,
                'error' => [
                    'code' => 'FILE_EXISTS',
                    'message' => "File already exists: {$filename}"
                ]
            ];
        }

        // Write file
        if (file_put_contents($filepath, $content) === false) {
            return [
                'success' => false,
                'error' => [
                    'code' => 'FILE_WRITE_ERROR',
                    'message' => 'Failed to write file'
                ]
            ];
        }

        // Get mime type
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($filepath);

        return [
            'success' => true,
            'data' => [
                'filename' => $filename,
                'path' => str_replace(GRAV_ROOT . '/', '', $filepath),
                'size' => $size,
                'type' => $mimeType,
                'markdown_image' => "![{$filename}]({$filename})",
                'markdown_link' => "[{$filename}]({$filename})"
            ]
        ];
    }

    /**
     * Delete a media file
     */
    public function delete(string $slug, string $filename): array
    {
        // Validate filename (no path traversal)
        if (str_contains($filename, '..') || str_contains($filename, '/') || str_contains($filename, '\\')) {
            return [
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Invalid filename'
                ]
            ];
        }

        // Find post directory
        $blogRoute = $this->config['blog_route'] ?? '/blog';
        $page = $this->grav['pages']->find($blogRoute . '/' . $slug);

        if (!$page) {
            return [
                'success' => false,
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => "Post not found: {$slug}"
                ]
            ];
        }

        $filepath = $page->path() . '/' . $filename;

        if (!file_exists($filepath)) {
            return [
                'success' => false,
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => "File not found: {$filename}"
                ]
            ];
        }

        if (!unlink($filepath)) {
            return [
                'success' => false,
                'error' => [
                    'code' => 'FILE_WRITE_ERROR',
                    'message' => 'Failed to delete file'
                ]
            ];
        }

        return [
            'success' => true,
            'data' => [
                'filename' => $filename,
                'deleted' => true
            ]
        ];
    }

    /**
     * Validate file content matches the declared extension using magic bytes
     */
    protected function validateFileContent(string $content, string $extension): bool
    {
        // SVG is XML-based, needs special handling
        if ($extension === 'svg') {
            return $this->validateSvgContent($content);
        }

        // Check if we have magic bytes defined for this extension
        if (!isset($this->magicBytes[$extension]) || empty($this->magicBytes[$extension])) {
            // Unknown extension without magic bytes - allow but log
            return true;
        }

        $signatures = $this->magicBytes[$extension];

        foreach ($signatures as $signature) {
            $sigLength = strlen($signature);
            if (strlen($content) >= $sigLength && substr($content, 0, $sigLength) === $signature) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate SVG content (XML-based, no magic bytes)
     * Checks for valid XML structure and SVG root element
     * Also sanitizes against XSS attacks
     */
    protected function validateSvgContent(string $content): bool
    {
        // Check for XML declaration or SVG tag
        $trimmed = ltrim($content);

        // Must start with XML declaration or SVG tag
        if (!preg_match('/^(<\?xml|<svg)/i', $trimmed)) {
            return false;
        }

        // Check for potentially dangerous content (XSS prevention)
        $dangerousPatterns = [
            '/<script/i',
            '/javascript:/i',
            '/on\w+\s*=/i',  // onclick, onload, etc.
            '/<foreignObject/i',
            '/xlink:href\s*=\s*["\']?\s*data:/i',
        ];

        foreach ($dangerousPatterns as $pattern) {
            if (preg_match($pattern, $content)) {
                return false;
            }
        }

        // Try to parse as XML to ensure valid structure
        libxml_use_internal_errors(true);
        $doc = simplexml_load_string($content);
        libxml_clear_errors();

        if ($doc === false) {
            return false;
        }

        // Verify root element is SVG
        $rootName = strtolower($doc->getName());
        return $rootName === 'svg';
    }

    /**
     * Sanitize filename to remove dangerous characters
     * Preserves extension and replaces unsafe chars with underscores
     */
    protected function sanitizeFilename(string $filename): string
    {
        // Get extension
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $basename = pathinfo($filename, PATHINFO_FILENAME);

        // Remove or replace dangerous characters
        // Allow only alphanumeric, dash, underscore, and dot
        $basename = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $basename);

        // Remove multiple consecutive underscores
        $basename = preg_replace('/_+/', '_', $basename);

        // Trim underscores from start/end
        $basename = trim($basename, '_');

        // Ensure basename is not empty
        if (empty($basename)) {
            $basename = 'file_' . bin2hex(random_bytes(4));
        }

        // Limit length (255 chars max for most filesystems, minus extension)
        $maxBasenameLength = 240 - strlen($extension);
        if (strlen($basename) > $maxBasenameLength) {
            $basename = substr($basename, 0, $maxBasenameLength);
        }

        return $basename . '.' . $extension;
    }
}
