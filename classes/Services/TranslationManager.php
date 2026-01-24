<?php

namespace Grav\Plugin\Mcp\Services;

use Grav\Common\Grav;
use Grav\Common\Page\Page;
use SplFileInfo;

/**
 * Translation Manager Service
 * Handles multilingual content operations using Grav's native Page methods
 */
class TranslationManager
{
    protected Grav $grav;
    protected array $config;

    public function __construct(Grav $grav)
    {
        $this->grav = $grav;
        $this->config = $grav['config']->get('plugins.mcp', []);
    }

    /**
     * Create a translation for an existing post
     * Requires Grav multilingual to be properly configured
     */
    public function createTranslation(array $args): array
    {
        $slug = $args['slug'];
        $sourceLang = $args['source_lang'];
        $targetLang = $args['target_lang'];
        $title = $args['title'];
        $content = $args['content'];
        $tags = $args['tags'] ?? null;

        // Check if multilingual is enabled in Grav
        $languages = $this->grav['config']->get('system.languages.supported', []);
        if (empty($languages)) {
            return [
                'success' => false,
                'error' => [
                    'code' => 'MULTILINGUAL_NOT_ENABLED',
                    'message' => 'Grav multilingual is not configured. Add languages.supported to system.yaml'
                ]
            ];
        }

        // Validate target language
        $allowedLanguages = $this->config['allowed_languages'] ?? ['en', 'fr'];
        if (!in_array($targetLang, $allowedLanguages)) {
            return [
                'success' => false,
                'error' => [
                    'code' => 'INVALID_LANG',
                    'message' => "Language not configured: {$targetLang}"
                ]
            ];
        }

        // Find source post
        $blogRoute = $this->config['blog_route'] ?? '/blog';
        $sourcePage = $this->grav['pages']->find($blogRoute . '/' . $slug);

        if (!$sourcePage) {
            return [
                'success' => false,
                'error' => [
                    'code' => 'SOURCE_NOT_FOUND',
                    'message' => "Source post not found: {$slug}"
                ]
            ];
        }

        $postDir = $sourcePage->path();

        // Find the source file - try with language code first, then without
        $sourceFile = $this->findSourceFile($postDir, $sourceLang);
        if (!$sourceFile) {
            // Try to find any .md file as source
            $files = glob("{$postDir}/*.md");
            $sourceFile = !empty($files) ? $files[0] : null;
        }

        if (!$sourceFile) {
            return [
                'success' => false,
                'error' => [
                    'code' => 'SOURCE_FILE_NOT_FOUND',
                    'message' => "Source file not found for post: {$slug}"
                ]
            ];
        }

        // Extract template from source filename (e.g., 'item' from 'item.en.md' or 'item.md')
        $filename = basename($sourceFile);
        $parts = explode('.', $filename);
        $template = $parts[0];
        $targetFile = $postDir . '/' . $template . '.' . $targetLang . '.md';

        // Check if translation exists
        if (file_exists($targetFile)) {
            return [
                'success' => false,
                'error' => [
                    'code' => 'TRANSLATION_EXISTS',
                    'message' => "Translation already exists for language: {$targetLang}"
                ]
            ];
        }

        // Get source header for copying common properties
        $sourceHeader = (array)$sourcePage->header();

        // Build target header
        $header = [
            'title' => $title,
            'date' => $sourceHeader['date'] ?? date('Y-m-d H:i'),
            'published' => $sourceHeader['published'] ?? true,
            'taxonomy' => []
        ];

        // Use provided tags or copy from source
        if ($tags !== null) {
            $header['taxonomy']['tag'] = $tags;
        } elseif (isset($sourceHeader['taxonomy']['tag'])) {
            $header['taxonomy']['tag'] = $sourceHeader['taxonomy']['tag'];
        }

        if (isset($sourceHeader['taxonomy']['category'])) {
            $header['taxonomy']['category'] = $sourceHeader['taxonomy']['category'];
        }

        if (isset($sourceHeader['hero_image'])) {
            $header['hero_image'] = $sourceHeader['hero_image'];
        }

        // Create new Page for translation
        $translationPage = new Page();
        $translationPage->filePath($targetFile);
        $translationPage->folder($slug);
        $translationPage->header((object)$header);
        $translationPage->rawMarkdown($content);

        // Save using Grav's native method
        $translationPage->save(false);

        // Clear cache
        $this->grav['cache']->clearCache('all');

        return [
            'success' => true,
            'data' => [
                'slug' => $slug,
                'source_lang' => $sourceLang,
                'target_lang' => $targetLang,
                'path' => $translationPage->filePathClean(),
                'url' => $this->grav['uri']->base() . '/' . $targetLang . $blogRoute . '/' . $slug
            ]
        ];
    }

    /**
     * List translations for a post using Grav's native methods
     */
    public function listTranslations(string $slug): array
    {
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

        $allowedLanguages = $this->config['allowed_languages'] ?? ['en', 'fr'];

        // Use Grav's native translatedLanguages() - returns array of lang => route
        $translatedLanguages = $page->translatedLanguages(false);
        $untranslatedLanguages = $page->untranslatedLanguages(false);

        $available = [];
        $pagePath = $page->path();
        $template = $page->template();

        foreach ($translatedLanguages as $lang => $route) {
            if (in_array($lang, $allowedLanguages)) {
                // Load the language-specific file directly to get accurate info
                $langFile = $pagePath . '/' . $template . '.' . $lang . '.md';
                if (file_exists($langFile)) {
                    $langPage = new Page();
                    $langPage->init(new SplFileInfo($langFile), '.' . $lang . '.md');
                    $available[$lang] = [
                        'route' => $route,
                        'title' => $langPage->title(),
                        'status' => $langPage->published() ? 'published' : 'draft',
                    ];
                } else {
                    // Fallback if file doesn't match expected pattern
                    $available[$lang] = [
                        'route' => $route,
                        'title' => null,
                        'status' => null,
                    ];
                }
            }
        }

        $missing = array_values(array_intersect($untranslatedLanguages, $allowedLanguages));

        return [
            'success' => true,
            'data' => [
                'slug' => $slug,
                'available' => $available,
                'missing' => $missing,
                'configured_languages' => $allowedLanguages
            ]
        ];
    }

    /**
     * Find the source language file in a post directory
     */
    protected function findSourceFile(string $postDir, string $lang): ?string
    {
        // Look for files with this language
        $files = glob("{$postDir}/*.{$lang}.md");
        if (!empty($files)) {
            return $files[0];
        }

        return null;
    }
}
