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

Marked Down serves the same entry as Markdown when a client asks for it, and as normal HTML otherwise. A request is served Markdown when **any** of these is true:

1. **`.md` URL suffix** — append `.md` to any entry URL (e.g. `/blog/my-article.md`).
2. **`Accept: text/markdown`** header — standard HTTP content negotiation.
3. **AI bot User-Agent** — the request comes from a known crawler (GPTBot, ClaudeBot, PerplexityBot, and others; configurable).

Everything else gets HTML, untouched. On HTML pages the plugin also injects a `<link rel="alternate" type="text/markdown">` tag so Markdown-aware clients can discover the `.md` version, and Markdown responses carry `X-Robots-Tag: noindex` so they don't compete with your canonical HTML in search.

### Quick Test

```bash
# .md suffix
curl https://yoursite.com/blog/my-article.md

# Accept header
curl -H "Accept: text/markdown" https://yoursite.com/blog/my-article

# As an AI bot
curl -A "GPTBot/1.0" https://yoursite.com/blog/my-article

# Normal browser request — unchanged HTML
curl https://yoursite.com/blog/my-article
```

Each Markdown response is prefixed with YAML front matter (title, date, author, canonical URL, section) and has navigation, headers, footers, and scripts stripped out.

## Configuration

### Plugin Settings

Navigate to **Settings → Plugins → Marked Down**:

- **Enable Marked Down**: Toggle the plugin on/off
- **Excluded Paths**: Paths that always serve HTML (e.g., `/admin`, `/actions/*`)
- **Include Only Paths**: (Optional) Restrict Markdown to specific paths. Leave empty to allow all paths — if set, **only** matching paths serve Markdown.
- **AI Bot User-Agents**: The crawler patterns that trigger Markdown (matched case-insensitively against the User-Agent).
- **Caching**: Enable caching and set cache duration (default: 24 hours)

### Per-section settings

Each section can be configured independently (stored in project config, keyed by section UID so it survives renames and syncs across environments):

- **Enabled**: Serve Markdown for this section at all. Sections are opt-in and start disabled. A stored entry that has no `enabled` key counts as enabled, which keeps configs written before opt-in working.
- **AI-bots-only**: Serve Markdown *only* to detected AI bots. Humans hitting the `.md` URL or sending `Accept: text/markdown` still get HTML — useful when you want crawlers fed but the `.md` URLs kept out of public reach.
- **Dedicated template**: Point a section at a Twig template that outputs raw Markdown directly, bypassing HTML→Markdown conversion entirely. Receives the `entry` variable.
- **Front matter**: Toggle the YAML front-matter block per section.

### llms.txt

Marked Down publishes a `/llms.txt` site index following the [llms.txt spec](https://llmstxt.org/) — a per-section list of entries linking to their `.md` URLs, so AI tools can discover your Markdown content from one file. Configure the number of entries per section in the plugin settings (`llmsTxtEntriesPerSection`, default 20).

- **Title and summary:** set `llmsTxtTitle` and `llmsTxtSummary` per site in the plugin settings. The title defaults to the site name. The summary is the optional blockquote under the title. Both are keyed by site handle, so you can also set them in `config/marked-down.php`, for example `'llmsTxtTitle' => ['default' => 'Example AG', 'en' => 'Example']`.
- **Order:** structure sections keep their structure order. Other sections list the newest entries first, then sort by title.
- **Empty sections** are left out. Only enabled sections are listed, so a new install shows no sections until you turn some on.

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

### Caching & front matter

- **Body conversion is cached** (keyed per URL, `cacheDuration` default 24h) and
  **automatically invalidated when the entry is saved or deleted**. Editing an
  entry refreshes its Markdown immediately; editing the *Twig template* does not
  fire a save, so run `marked-down/default/cache-clear` (or re-save the entry) to
  pick up template changes.
- **YAML front matter is generated fresh on every request** (it is not cached),
  so title/date/author always reflect the entry's current state.
- The `author` field is included **only when the entry has an author assigned** —
  empty fields are omitted to keep the front matter valid and clean.

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
