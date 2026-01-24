<?php

namespace Grav\Plugin\Mcp\Services;

use Grav\Common\Grav;
use Grav\Common\Page\Page;
use Grav\Common\Filesystem\Folder;
use RocketTheme\Toolbox\File\MarkdownFile;

/**
 * Content Manager Service
 * Handles CRUD operations for blog posts
 */
class ContentManager
{
    protected Grav $grav;
    protected array $config;

    public function __construct(Grav $grav)
    {
        $this->grav = $grav;
        $this->config = $grav['config']->get('plugins.mcp', []);
    }

    /**
     * List posts with filters
     */
    public function listPosts(array $args): array
    {
        $blogRoute = $this->config['blog_route'] ?? '/blog';
        $pages = $this->grav['pages'];

        $blog = $pages->find($blogRoute);
        if (!$blog) {
            return ['success' => false, 'error' => ['code' => 'NOT_FOUND', 'message' => 'Blog route not found']];
        }

        $collection = $blog->children();

        // Apply filters
        $status = $args['status'] ?? 'published';
        if ($status === 'published') {
            $collection = $collection->published();
        } elseif ($status === 'draft') {
            $collection = $collection->nonPublished();
        }

        // Order
        $orderBy = $args['order_by'] ?? 'date';
        $orderDir = $args['order_dir'] ?? 'desc';
        $collection = $collection->order($orderBy, $orderDir);

        // Pagination
        $limit = min(100, max(1, $args['limit'] ?? 20));
        $offset = max(0, $args['offset'] ?? 0);

        $total = count($collection);
        $posts = [];

        $i = 0;
        foreach ($collection as $page) {
            if ($i < $offset) {
                $i++;
                continue;
            }
            if (count($posts) >= $limit) {
                break;
            }

            $posts[] = $this->formatPostSummary($page);
            $i++;
        }

        return [
            'success' => true,
            'data' => [
                'posts' => $posts,
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset
            ]
        ];
    }

    /**
     * Get a single post
     */
    public function getPost(string $slug, ?string $lang = null): array
    {
        $blogRoute = $this->config['blog_route'] ?? '/blog';
        $route = $blogRoute . '/' . $slug;

        // If a specific language is requested, switch to it before finding the page
        $originalLang = null;
        if ($lang) {
            $language = $this->grav['language'];
            $originalLang = $language->getActive();
            $language->setActive($lang);
            // Re-initialize pages to load the correct language version
            $this->grav['pages']->reset();
            $this->grav['pages']->init();
        }

        $page = $this->grav['pages']->find($route);

        // Restore original language
        if ($originalLang !== null) {
            $this->grav['language']->setActive($originalLang);
        }

        if (!$page) {
            return ['success' => false, 'error' => ['code' => 'NOT_FOUND', 'message' => "Post not found: {$slug}"]];
        }

        return [
            'success' => true,
            'data' => $this->formatPostFull($page, $lang)
        ];
    }

    /**
     * Create a new post using Grav's native Page methods
     */
    public function createPost(array $args): array
    {
        $slug = $args['slug'];

        // Validate slug
        if (!$this->isValidSlug($slug)) {
            return ['success' => false, 'error' => ['code' => 'INVALID_SLUG', 'message' => 'Invalid slug format']];
        }

        // Check if exists
        if ($this->findPageBySlug($slug)) {
            return ['success' => false, 'error' => ['code' => 'SLUG_EXISTS', 'message' => 'A post with this slug already exists']];
        }

        $blogRoute = $this->config['blog_route'] ?? '/blog';
        $template = $args['template'] ?? $this->config['default_template'] ?? 'item';
        $lang = $args['lang'] ?? $this->grav['config']->get('system.languages.default', 'en');

        // Find the blog page to get its actual filesystem path
        $blog = $this->grav['pages']->find($blogRoute);
        if (!$blog) {
            return ['success' => false, 'error' => ['code' => 'NOT_FOUND', 'message' => 'Blog route not found']];
        }

        // Create directory using the blog's actual path
        $blogDir = $blog->path();
        $postDir = $blogDir . '/' . $slug;

        if (!is_dir($postDir)) {
            mkdir($postDir, 0755, true);
        }

        // Check if multilingual is enabled in Grav
        $languages = $this->grav['config']->get('system.languages.supported', []);
        $isMultilingual = !empty($languages);

        // Build filename: with language code only if multilingual is enabled
        $filename = $isMultilingual ? "{$template}.{$lang}.md" : "{$template}.md";
        $filepath = $postDir . '/' . $filename;

        // Create a new Page object and initialize it
        $page = new Page();
        $page->filePath($filepath);
        $page->folder($slug);

        // Build header
        $header = [
            'title' => $args['title'],
            'date' => $args['date'] ?? date('Y-m-d H:i'),
            'published' => ($args['status'] ?? 'draft') === 'published',
            'taxonomy' => []
        ];

        if (!empty($args['tags'])) {
            $header['taxonomy']['tag'] = $args['tags'];
        }
        if (!empty($args['category'])) {
            $header['taxonomy']['category'] = [$args['category']];
        }
        if (!empty($args['hero_image'])) {
            $header['hero_image'] = $args['hero_image'];
        }
        if (!empty($args['extra_frontmatter'])) {
            $header = array_merge($header, $args['extra_frontmatter']);
        }

        // Set page properties
        $page->header((object)$header);
        $page->rawMarkdown($args['content']);

        // Save using Grav's native method
        $page->save(false);

        // Clear cache
        $this->grav['cache']->clearCache('all');

        return [
            'success' => true,
            'data' => [
                'slug' => $slug,
                'lang' => $lang,
                'path' => $page->filePathClean(),
                'url' => $this->grav['uri']->base() . $blogRoute . '/' . $slug,
                'status' => $args['status'] ?? 'draft'
            ]
        ];
    }

    /**
     * Update an existing post using Grav's native MarkdownFile
     */
    public function updatePost(array $args): array
    {
        $slug = $args['slug'];
        $defaultLang = $this->grav['config']->get('system.languages.default', 'fr');
        $lang = $args['lang'] ?? $defaultLang;

        // Find the page to get directory path and template
        $page = $this->findPageBySlug($slug);

        if (!$page) {
            return ['success' => false, 'error' => ['code' => 'NOT_FOUND', 'message' => "Post not found: {$slug}"]];
        }

        // Build the exact file path for the requested language
        // Cannot rely on $page->file() as it returns the file based on how the page was loaded
        $path = $page->path();
        $template = $page->template();
        $filepath = "{$path}/{$template}.{$lang}.md";

        // Fallback for default language without suffix (e.g., item.md instead of item.fr.md)
        if (!file_exists($filepath) && $lang === $defaultLang) {
            $filepath = "{$path}/{$template}.md";
        }

        if (!file_exists($filepath)) {
            return ['success' => false, 'error' => ['code' => 'FILE_NOT_FOUND', 'message' => "Page file not found for: {$slug} (lang: {$lang}), tried: {$template}.{$lang}.md"]];
        }

        // Use MarkdownFile directly with the explicit path
        $file = MarkdownFile::instance($filepath);

        // Get current header from the file itself (more reliable than page object)
        $header = $file->header();
        $updatedFields = [];

        // Update header fields
        if (isset($args['title'])) {
            $header['title'] = $args['title'];
            $updatedFields[] = 'title';
        }
        if (isset($args['tags'])) {
            $header['taxonomy']['tag'] = $args['tags'];
            $updatedFields[] = 'tags';
        }
        if (isset($args['category'])) {
            $header['taxonomy']['category'] = [$args['category']];
            $updatedFields[] = 'category';
        }
        if (isset($args['status'])) {
            $header['published'] = $args['status'] === 'published';
            $updatedFields[] = 'status';
        }
        if (isset($args['date'])) {
            $header['date'] = $args['date'];
            $updatedFields[] = 'date';
        }
        if (isset($args['hero_image'])) {
            $header['hero_image'] = $args['hero_image'];
            $updatedFields[] = 'hero_image';
        }
        if (!empty($args['extra_frontmatter'])) {
            $header = array_merge($header, $args['extra_frontmatter']);
            $updatedFields[] = 'extra_frontmatter';
        }

        // Update content if provided - read from file for reliability
        $content = $file->markdown();
        if (isset($args['content'])) {
            $content = $args['content'];
            $updatedFields[] = 'content';
        }

        // Use MarkdownFile's save mechanism
        $file->header($header);
        $file->markdown($content);
        $file->save();

        // Clear cache
        $this->grav['cache']->clearCache('all');

        return [
            'success' => true,
            'data' => [
                'slug' => $slug,
                'lang' => $lang,
                'updated_fields' => $updatedFields,
                'file' => $filepath,
                'url' => $page->url(true)
            ]
        ];
    }

    /**
     * Delete a post
     */
    public function deletePost(string $slug, ?string $lang = null): array
    {
        $page = $this->findPageBySlug($slug);

        if (!$page) {
            return ['success' => false, 'error' => ['code' => 'NOT_FOUND', 'message' => "Post not found: {$slug}"]];
        }

        $postDir = $page->path();

        if ($lang) {
            // Delete specific language file
            $pattern = $postDir . '/*.' . $lang . '.md';
            $files = glob($pattern);
            foreach ($files as $file) {
                unlink($file);
            }
            $message = "Translation '{$lang}' deleted";
            $deleted = $lang;
        } else {
            // Delete entire folder
            Folder::delete($postDir);
            $message = 'Post and all translations deleted';
            $deleted = 'all';
        }

        // Clear cache
        $this->grav['cache']->clearCache('all');

        return [
            'success' => true,
            'data' => [
                'slug' => $slug,
                'deleted' => $deleted,
                'message' => $message
            ]
        ];
    }

    /**
     * Find page by slug
     */
    protected function findPageBySlug(string $slug): ?Page
    {
        $blogRoute = $this->config['blog_route'] ?? '/blog';
        $route = $blogRoute . '/' . $slug;

        return $this->grav['pages']->find($route);
    }

    /**
     * Find the actual markdown file path for a page
     * Handles both multilingual (item.en.md) and single-language (item.md) setups
     */
    protected function findPageFilePath(Page $page, string $lang): ?string
    {
        $path = $page->path();

        // Check if multilingual is enabled
        $languages = $this->grav['config']->get('system.languages.supported', []);
        $isMultilingual = !empty($languages);

        // Get all .md files in directory
        $files = glob("{$path}/*.md");
        if (empty($files)) {
            return null;
        }

        // If multilingual, prefer language-specific file
        if ($isMultilingual) {
            foreach ($files as $file) {
                if (str_ends_with($file, ".{$lang}.md")) {
                    return $file;
                }
            }
        }

        // Return first .md file found
        return $files[0];
    }

    /**
     * Validate slug format
     */
    protected function isValidSlug(string $slug): bool
    {
        return (bool)preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug);
    }

    /**
     * Format post summary for list
     */
    protected function formatPostSummary(Page $page): array
    {
        $taxonomy = $page->taxonomy();

        return [
            'slug' => $page->slug(),
            'title' => $page->title(),
            'date' => $page->date(),
            'lang' => $page->language() ?? $this->grav['config']->get('system.languages.default'),
            'tags' => $taxonomy['tag'] ?? [],
            'category' => $taxonomy['category'][0] ?? null,
            'status' => $page->published() ? 'published' : 'draft',
            'excerpt' => $page->summary(),
            'url' => $page->url(true)
        ];
    }

    /**
     * Format full post details
     */
    protected function formatPostFull(Page $page, ?string $lang = null): array
    {
        $taxonomy = $page->taxonomy();
        $header = $page->header();

        return [
            'slug' => $page->slug(),
            'title' => $page->title(),
            'content' => $page->content(),
            'raw_content' => $page->rawMarkdown(),
            'frontmatter' => (array)$header,
            'lang' => $lang ?? $page->language() ?? $this->grav['config']->get('system.languages.default'),
            'translations' => $this->getTranslations($page),
            'media' => $this->getPageMedia($page),
            'word_count' => str_word_count(strip_tags($page->content())),
            'reading_time' => (int)ceil(str_word_count(strip_tags($page->content())) / 200),
            'url' => $page->url(true),
            'modified' => $page->modified()
        ];
    }

    /**
     * Get translations for a page
     */
    protected function getTranslations(Page $page): array
    {
        $translations = [];
        $translatedLangs = $page->translatedLanguages();

        // translatedLanguages() returns array of lang => url, not Page objects
        foreach ($translatedLangs as $lang => $url) {
            $translations[$lang] = [
                'url' => $url,
                'exists' => true
            ];
        }

        return $translations;
    }

    /**
     * Get media files for a page
     */
    protected function getPageMedia(Page $page): array
    {
        $media = [];
        $pageMedia = $page->media()->all();

        foreach ($pageMedia as $filename => $file) {
            $media[] = [
                'filename' => $filename,
                'type' => $file->mime,
                'size' => $file->size
            ];
        }

        return $media;
    }

}
