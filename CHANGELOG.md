# Release Notes for Marked Down

## 1.2.0 - Unreleased
### Added
- `llmsTxtTitle` and `llmsTxtSummary` settings (per site) for the H1 and the summary blockquote of `/llms.txt`.

### Changed
- `/llms.txt` skips sections that have no live entries on the current site. It no longer prints `_No entries yet._`.
- `/llms.txt` sorts structure sections by structure order. Other sections sort by post date, then by title.
- Saving the plugin settings in the control panel now clears the `/llms.txt` cache. Before, changes showed up only after the cache expired or an entry was saved.
- `LlmsTxtService::summary()` is removed. Use the `llmsTxtSummary` setting.

## 1.1.2 - Unreleased
### Fixed
- The homepage discovery link pointed to a different domain (`https://example.ch.md`). Site homepages now use `<base>/index.md`.
- `/llms.txt` listed the homepage as `/.md`, which common web server dotfile rules block. It now uses `<base>/index.md`.
- Discovery link no longer includes the query string.

### Notes
- `index.md` is now reserved for the site homepage. An entry with the URI `index` directly under a site root cannot be served as Markdown.

## 1.1.1 - 2026-08-24
### Changed
- Updated the plugin icon: aubergine badge with a white M and down arrow.

## 1.1.0 - 2026-06-18
### Added
- Per-section settings in project config: enable/disable, AI-bots-only mode, optional dedicated Markdown template, front matter toggle.
- `.md` URL suffix routing for entries and section listing pages.
- Automatic AI bot detection via User-Agent (GPTBot, ClaudeBot, PerplexityBot, and others; configurable).
- YAML front matter with title, date, author, canonical URL, and section.
- Auto-injected `<link rel="alternate" type="text/markdown">` discovery tags.
- `X-Robots-Tag: noindex` on all Markdown responses.
- Optional dedicated `.md.twig` templates per section that bypass HTML→Markdown conversion.
- `/llms.txt` site index following the llms.txt spec, with section index + recent entries per section.
- Automatic cache invalidation when entries are saved.

### Changed
- Bumped schema to 1.1.0. The legacy global `excludePaths` / `includeOnlyPaths` settings still work as a pre-section filter.

## 1.0.1 - 2025-02-12
### Fixed
- Fixed settings `excludePaths` and `includeOnlyPaths` not persisting to project config when saving in the CP
- Fixed round-trip type error when loading path arrays back from `project.yaml`

## 1.0.0 - 2024-01-14
- Initial release
