# Grav MCP Plugin

Expose your Grav CMS blog via Model Context Protocol (MCP) for AI assistants like Claude.

## Features

- Full MCP protocol support via HTTP (JSON-RPC 2.0)
- Create, read, update, delete posts
- Multi-language support with translation workflow
- Media upload and management
- API key authentication with granular permissions
- Rate limiting
- Session management

## Requirements

- Grav 1.7+
- PHP 8.1+
- Composer

## Installation

### Manual

1. Download or clone to `/user/plugins/mcp`
2. Run `composer install` in the plugin directory
3. Enable in Admin > Plugins > MCP

## Configuration

Edit `user/plugins/mcp/mcp.yaml`:

```yaml
enabled: true

# MCP endpoint route
route: /mcp

# Allowed languages for translations
allowed_languages:
  - en
  - fr

# Blog route in Grav
blog_route: /blog

# Default template for new posts
default_template: item

# Security settings
security:
  rate_limit:
    enabled: true
    max_requests: 100
    window_seconds: 60
```

## Authentication Setup

API keys are managed per-user through the Grav admin interface. Each user can have their own MCP API key, and permissions are derived from the user's Grav access rights.

### Generate an API Key

1. Go to **Admin > Accounts > Users**
2. Edit your user account
3. Scroll down to the **"MCP API Access"** section
4. Click **"Generate New API Key"**
5. Copy the generated key (format: `mcp_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx`)

### Permissions

MCP permissions are automatically derived from Grav user access:

| Grav Access | MCP Permissions |
|-------------|-----------------|
| `admin.super` | `read`, `write`, `delete` (full access) |
| `admin.pages` | `read`, `write` |
| `mcp.read` | `read` |
| `mcp.write` | `write` |
| `mcp.delete` | `delete` |

If no specific permissions are set, authenticated users get `read` access by default.

### Manual Key Generation (CLI)

If needed, you can generate a key manually and add it to a user's YAML file:

```bash
php -r "echo 'mcp_' . bin2hex(random_bytes(16)) . PHP_EOL;"
```

Then add to `user/accounts/username.yaml`:

```yaml
mcp_api_key: mcp_your_generated_key_here
```

## Claude Desktop Setup

Add to your Claude Desktop config:

**macOS**: `~/Library/Application Support/Claude/claude_desktop_config.json`
**Windows**: `%APPDATA%\Claude\claude_desktop_config.json`

```json
{
  "mcpServers": {
    "my-blog": {
      "type": "http",
      "url": "https://yourblog.com/mcp",
      "headers": {
        "Authorization": "Bearer YOUR_API_KEY"
      }
    }
  }
}
```

## Usage Examples

Once configured, you can ask Claude:

- "List my recent blog posts"
- "Create a new post about Docker with tags docker and devops"
- "Translate my Home Assistant article to English"
- "Upload this screenshot to my latest post"
- "Delete the draft post 'test-article'"

## Available Tools

### Content Management

#### `list_posts`
List blog posts with filters and pagination.

| Parameter | Type | Description |
|-----------|------|-------------|
| `lang` | string | Filter by language code (e.g., "en", "fr") |
| `status` | string | Filter by status: `published`, `draft`, or `all` |
| `limit` | integer | Maximum results (1-100, default: 20) |
| `offset` | integer | Offset for pagination (default: 0) |
| `tag` | string | Filter by tag name |
| `order_by` | string | Order by: `date`, `title`, or `slug` |
| `order_dir` | string | Order direction: `asc` or `desc` |

**Permission required**: `read`

---

#### `get_post`
Get full content of a single post including frontmatter, content, and media.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `slug` | string | Yes | Post slug (URL identifier) |
| `lang` | string | No | Language version to retrieve |

**Returns**: Title, content (HTML and raw Markdown), frontmatter, translations, media files, word count, reading time.

**Permission required**: `read`

---

#### `create_post`
Create a new blog post.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `slug` | string | Yes | URL-friendly slug (lowercase, hyphens only) |
| `title` | string | Yes | Post title |
| `content` | string | Yes | Markdown content |
| `lang` | string | No | Language code (uses default if omitted) |
| `tags` | array | No | List of tag names |
| `category` | string | No | Category name |
| `status` | string | No | `published` or `draft` (default: draft) |
| `date` | string | No | ISO 8601 date (default: now) |
| `hero_image` | string | No | Hero image filename |
| `template` | string | No | Page template name (default: item) |

**Permission required**: `write`

---

#### `update_post`
Update an existing post. Only provided fields are updated.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `slug` | string | Yes | Post slug to update |
| `lang` | string | No | Language version to update |
| `title` | string | No | New title |
| `content` | string | No | New Markdown content |
| `tags` | array | No | New tags (replaces existing) |
| `category` | string | No | New category |
| `status` | string | No | New status |
| `date` | string | No | New date |
| `hero_image` | string | No | New hero image |

**Permission required**: `write`

---

#### `delete_post`
Delete a post or a specific language translation.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `slug` | string | Yes | Post slug to delete |
| `lang` | string | No | Language to delete (omit to delete all) |
| `confirm` | boolean | Yes | Must be `true` to confirm deletion |

**Permission required**: `delete`

---

### Translation Management

#### `list_translations`
List available and missing translations for a post.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `slug` | string | Yes | Post slug |

**Returns**: Available translations with title and status, missing languages, configured languages.

**Permission required**: `read`

---

#### `create_translation`
Create a translation for an existing post.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `slug` | string | Yes | Post slug |
| `source_lang` | string | Yes | Source language code |
| `target_lang` | string | Yes | Target language code |
| `title` | string | Yes | Translated title |
| `content` | string | Yes | Translated Markdown content |
| `tags` | array | No | Translated tags |

**Note**: Grav multilingual must be configured in `system.yaml` with `languages.supported`.

**Permission required**: `write`

---

### Media Management

#### `upload_media`
Upload a media file (image, PDF, etc.) to a post's folder.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `slug` | string | Yes | Post slug |
| `filename` | string | Yes | Filename with extension |
| `content_base64` | string | Yes | Base64-encoded file content |
| `overwrite` | boolean | No | Overwrite if exists (default: false) |

**Allowed extensions**: jpg, jpeg, png, gif, webp, svg, pdf, zip, mp4, webm

**Max file size**: 10 MB

**Returns**: File path, size, MIME type, Markdown snippets for embedding.

**Permission required**: `write`

---

### Direct Upload REST Endpoint

The standard MCP protocol requires SSE sessions, which can be problematic for some tools (like Claude Code CLI). A direct REST endpoint is available for media uploads:

#### `POST /mcp/upload`

Direct file upload without MCP session. Useful for CLI tools and scripts.

**Endpoint**: `POST https://yourblog.com/mcp/upload`

**Authentication**: Bearer token (same as MCP)

**Formats accepted**:

##### Option 1: multipart/form-data (recommended for large files)

```bash
curl -X POST "https://yourblog.com/mcp/upload" \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -F "slug=my-post" \
  -F "file=@/path/to/image.png" \
  -F "overwrite=false"
```

##### Option 2: application/json (base64)

```bash
curl -X POST "https://yourblog.com/mcp/upload" \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "slug": "my-post",
    "filename": "image.png",
    "content_base64": "iVBORw0KGgo...",
    "overwrite": false
  }'
```

**Response**:
```json
{
  "success": true,
  "data": {
    "filename": "image.png",
    "path": "user/pages/01.blog/my-post/image.png",
    "size": 12345,
    "type": "image/png",
    "markdown_image": "![image.png](image.png)",
    "markdown_link": "[image.png](image.png)"
  }
}
```

**Permission required**: `write`

---

#### `delete_media`
Delete a media file from a post.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `slug` | string | Yes | Post slug |
| `filename` | string | Yes | Filename to delete |
| `confirm` | boolean | Yes | Must be `true` to confirm |

**Permission required**: `delete`

---

### Site Information

#### `list_tags`
List all tags used across the site with post counts.

| Parameter | Type | Description |
|-----------|------|-------------|
| `lang` | string | Filter by language (optional) |

**Returns**: Array of tags sorted by usage count.

**Permission required**: `read`

---

#### `get_site_info`
Get general information about the site and MCP plugin.

**Returns**:
- Site title, description, URL
- Default language and supported languages
- Post count, draft count, tag count
- Plugin version and capabilities

**Permission required**: `read`

---

#### `clear_cache`
Clear Grav's cache.

| Parameter | Type | Description |
|-----------|------|-------------|
| `type` | string | Cache type: `all`, `cache`, or `images` |

**Permission required**: `write`

---

## Security

### Authentication
All requests must include a valid API key as a Bearer token:
```
Authorization: Bearer mcp_your_api_key_here
```

API keys are linked to Grav user accounts. When a request is authenticated, the MCP plugin operates with that user's permissions.

### Permissions
Permissions are derived from the Grav user's access rights:
- `read` - List and view posts, translations, tags, site info
- `write` - Create/update posts, translations, media, clear cache
- `delete` - Delete posts and media

Super admins (`admin.super`) automatically get all permissions. Users with `admin.pages` access get `read` and `write`.

### Rate Limiting
Default: 100 requests per 60 seconds per user.

Response headers:
- `X-RateLimit-Limit`: Maximum requests allowed
- `X-RateLimit-Remaining`: Requests remaining
- `X-RateLimit-Reset`: Unix timestamp when limit resets

## Error Handling

All errors return a consistent format:
```json
{
  "success": false,
  "error": {
    "code": "ERROR_CODE",
    "message": "Human-readable description"
  }
}
```

Common error codes:
- `NOT_FOUND` - Post or resource not found
- `INVALID_SLUG` - Invalid slug format
- `SLUG_EXISTS` - Post with this slug already exists
- `FORBIDDEN` - Missing required permission
- `VALIDATION_ERROR` - Invalid input data
- `MULTILINGUAL_NOT_ENABLED` - Grav multilingual not configured

## License

MIT License
