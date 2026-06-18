# Release Notes for Marked Down

## 1.1.0 - Unreleased
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
