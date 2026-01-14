# Marked Down

A Craft CMS plugin that serves Markdown content to requests with the `Accept: text/markdown` header, while browsers continue to receive HTML.

## Why?

When LLMs, APIs, or scraping tools request your site, they typically receive full HTML with navigation, headers, footers, and styling—adding unnecessary complexity and tokens. Marked Down serves clean Markdown instead:

- **Reduced token usage**: Markdown is typically 10-15x smaller than HTML
- **Better for LLMs**: Clean, structured content without HTML noise
- **Standard compliant**: Uses HTTP content negotiation (`Accept` header)
- **Non-breaking**: Browsers continue to receive HTML as normal

Perfect for documentation sites, blogs, and any content that needs to work well with modern AI tools while maintaining a great browser experience.

## Installation

```bash
composer require zeix/craft-marked-down
./craft plugin/install marked-down
```

## Usage

Add the `Accept: text/markdown` header to your HTTP requests:

```bash
curl -H "Accept: text/markdown" https://yoursite.com/
```

Normal browser requests continue to receive HTML. Only requests with the `Accept: text/markdown` header get Markdown.

## Configuration

### Plugin Settings

Navigate to **Settings → Plugins → Marked Down**:

- **Enable Marked Down**: Toggle the plugin on/off
- **Excluded Paths**: Paths that always serve HTML (e.g., `/admin`, `/actions/*`)
- **Include Only Paths**: (Optional) Restrict Markdown to specific paths
- **Caching**: Enable caching and set cache duration (default: 24 hours)

### Config File

Create `config/marked-down.php` to exclude CSS selectors from Markdown output:

```php
<?php
return [
    'globalExclusions' => [
        '.sidebar',
        '#comments',
    ],
    'templateExclusions' => [
        'home/_entry' => ['.sidebar', '.related-posts'],
        'blog/_entry' => ['#comments', '.author-bio'],
    ],
];
```

Template paths should match your template file structure (e.g., `home/_entry` for `templates/home/_entry.twig`). See `config.example.php` for a complete example.

## Requirements

- Craft CMS 5.8.0+
- PHP 8.2+

## License

MIT
