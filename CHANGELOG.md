# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2025-01-08

### Added
- Initial release
- MCP protocol support via HTTP (JSON-RPC 2.0) using `logiscape/mcp-sdk-php`
- 13 MCP tools:
  - `list_posts` - List blog posts with filters and pagination
  - `get_post` - Get full content of a post
  - `create_post` - Create a new blog post
  - `update_post` - Update an existing post
  - `delete_post` - Delete a post or translation
  - `list_translations` - List available translations for a post
  - `create_translation` - Create a translation for an existing post
  - `upload_media` - Upload media files to a post
  - `delete_media` - Delete media files from a post
  - `list_tags` - List all tags with usage counts
  - `get_site_info` - Get site and plugin information
  - `clear_cache` - Clear Grav cache
- API key authentication linked to Grav user accounts
- Permission system derived from Grav user access rights
- Rate limiting (per-user and per-IP)
- Brute-force protection for authentication
- Magic bytes validation for uploaded files
- SVG sanitization (XSS prevention)
- Admin panel configuration with blueprints
- Multi-language support (en, fr)
- Comprehensive test suite (PHPUnit + E2E)
