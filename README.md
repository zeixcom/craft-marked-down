# Marked Down

A Craft CMS plugin that intelligently serves Markdown content to AI tools, APIs, and command-line clients, while browsers continue to receive HTML.

## Why Marked Down?

### The Problem

Modern websites are designed for humans viewing them in browsers—complete with navigation menus, sidebars, footers, analytics scripts, and styling. But when AI tools like ChatGPT, Claude scrape your site, they get the entire HTML payload.

Result: **Wasted tokens, confused AI models, cluttered API responses, and poor developer experience.**

### The Solution

Marked Down uses HTTP content negotiation to serve clean, semantic Markdown when appropriate:


**Benefits:**
- 🎯 **10-15x smaller responses** - Markdown is dramatically more compact than HTML
- 🤖 **Better AI understanding** - LLMs process clean Markdown more effectively than noisy HTML
- 🔌 **Standard HTTP** - Uses the `Accept: text/markdown` header (proper content negotiation)
- 🌐 **Zero impact on browsers** - Your website looks and works exactly the same for human visitors
- ⚡ **Built-in caching** - Fast responses with configurable cache duration
- 🎨 **Smart extraction** - Automatically finds and converts your main content, stripping navigation and boilerplate

### Real-World Use Cases

- **Documentation sites** - Let AI assistants cite your docs accurately
- **Technical blogs** - Make your content easily consumable by AI tools
- **API documentation** - Serve both human-readable HTML and machine-readable Markdown
- **Content APIs** - Provide Markdown output without building separate endpoints
- **AI training** - Offer clean content for AI model training datasets
- **Command-line tools** - Enable CLI users to read your content in their terminal

Perfect for any site where content matters more than chrome.

## Installation

### Standard Installation

```bash
composer require zeix/craft-marked-down
./craft plugin/install marked-down
```

### DDEV Installation

If you're using DDEV for local development:

```bash
ddev composer require zeix/craft-marked-down
ddev craft plugin/install marked-down
```

## How It Works

Marked Down uses **HTTP content negotiation** - a standard web protocol where clients tell servers what format they prefer. When a request includes the `Accept: text/markdown` header, the plugin automatically converts your HTML response to clean Markdown.

### Quick Test

Try it with curl:

```bash
# Get Markdown
curl -H "Accept: text/markdown" https://yoursite.com/blog/my-article

# Get HTML (default)
curl https://yoursite.com/blog/my-article
```

**What happens behind the scenes:**

1. Plugin checks if request has `Accept: text/markdown` header
2. If yes: Extracts main content, converts to Markdown
3. If no: Serves normal HTML (zero impact on regular traffic)

## Configuration

### Plugin Settings

Navigate to **Settings → Plugins → Marked Down**:

- **Enable Marked Down**: Toggle the plugin on/off
- **Excluded Paths**: Paths that always serve HTML (e.g., `/admin`, `/actions/*`)
- **Include Only Paths**: (Optional) Restrict Markdown to specific paths
- **Caching**: Enable caching and set cache duration (default: 24 hours)

### Config File

Create `config/marked-down.php` to exclude specific CSS selectors from Markdown output:

```php
<?php
return [
    // Global exclusions - applied to all pages
    'globalExclusions' => [
        '.sidebar',
        '#comments',
        '.social-share',
        'nav.secondary',
    ],

    // Template-specific exclusions - applied only to specific templates
    'templateExclusions' => [
        'blog/_entry' => ['.author-bio', '#related-posts'],
        'news/_entry' => ['#comments', '.share-buttons'],
        '_layouts/article' => ['.sidebar'],
    ],
];
```

**How it works:**
- **Global exclusions** are applied to all pages
- **Template exclusions** are only applied when a specific template is rendered
- Template paths should match your template file structure (e.g., `blog/_entry` for `templates/blog/_entry.twig`)
- Both `.twig` extension and without work (e.g., `blog/_entry` or `blog/_entry.twig`)

**Supported CSS Selectors:**
- `#id` - ID selectors
- `.class` - Class selectors
- `element` - Element selectors (e.g., `nav`, `aside`)
- `element#id` - Combined element and ID
- `element.class` - Combined element and class

**Note:** Complex selectors like descendant selectors (`.parent .child`) or pseudo-classes (`:hover`) are not currently supported. See `config.example.php` for more details.

## CLI Commands

Marked Down includes powerful command-line tools for testing and debugging:

### Test Conversion

Test the markdown conversion with sample HTML to verify everything is working:

```bash
./craft marked-down/default/test-conversion
# or with DDEV
ddev craft marked-down/default/test-conversion
```

This runs a comprehensive test that validates headings, bold, italic, links, images, lists, and tables.

### Convert URL

Fetch and convert any URL to markdown:

```bash
./craft marked-down/default/convert https://example.com
# or with DDEV
ddev craft marked-down/default/convert https://example.com
```

Add `--verbose` to see the original HTML:

```bash
./craft marked-down/default/convert https://example.com --verbose
```

### Clear Cache

Clear all cached markdown conversions:

```bash
./craft marked-down/default/cache-clear
# or with DDEV
ddev craft marked-down/default/cache-clear
```

### Plugin Info

View current plugin configuration and status:

```bash
./craft marked-down
# or with DDEV
ddev craft marked-down
```

Shows enabled status, cache settings, excluded/included paths, and config file location.

## Requirements

- Craft CMS 5.8.0+
- PHP 8.2+

## Credits

Marked Down is built with:

- [league/html-to-markdown](https://github.com/thephpleague/html-to-markdown) - The excellent HTML to Markdown conversion library by The PHP League

## License

MIT

---
