<?php

namespace zeix\craftmarkeddown\models;

use craft\base\Model;

/**
 * Marked Down settings.
 */
class Settings extends Model
{
    /**
     * Default AI bot User-Agent substring patterns. Case-insensitive match.
     * Sources: OpenAI, Anthropic, Perplexity, CommonCrawl, Google AI Overview,
     * Bytespider, Meta, Apple, etc.
     */
    public const DEFAULT_AI_BOT_PATTERNS = [
        'GPTBot',
        'ChatGPT-User',
        'OAI-SearchBot',
        'ClaudeBot',
        'Claude-Web',
        'anthropic-ai',
        'PerplexityBot',
        'Perplexity-User',
        'CCBot',
        'Google-Extended',
        'Bytespider',
        'Amazonbot',
        'Applebot-Extended',
        'Meta-ExternalAgent',
        'cohere-ai',
        'YouBot',
        'Diffbot',
    ];

    /**
     * @var bool Master switch. When off, the plugin is inert.
     */
    public bool $enabled = true;

    /**
     * @var array<string,SectionSettings> Per-section settings, keyed by section UID.
     */
    public array $sectionSettings = [];

    /**
     * @var array Paths that always serve HTML. Wildcards supported via "*".
     */
    public array $excludePaths = [
        '/admin',
        '/cms',
        '/access',
        '/cp',
        '/graphql',
        '/api',
        '/actions',
        '/cpresources',
    ];

    /**
     * @var array|null When set, Markdown is only served on paths in this list.
     */
    public ?array $includeOnlyPaths = null;

    /**
     * @var array User-Agent patterns used to detect AI bots.
     */
    public array $aiBotPatterns = self::DEFAULT_AI_BOT_PATTERNS;

    /**
     * @var bool Whether to auto-inject <link rel="alternate" type="text/markdown"> into HTML.
     */
    public bool $injectDiscoveryLink = true;

    /**
     * @var bool Whether to send X-Robots-Tag: noindex on Markdown responses.
     */
    public bool $noIndexMarkdown = true;

    /**
     * @var bool Whether to expose /llms.txt.
     */
    public bool $llmsTxtEnabled = true;

    /**
     * @var int Number of recent entries per section to include in /llms.txt.
     */
    public int $llmsTxtEntriesPerSection = 20;

    /**
     * @var array<string,string> H1 of /llms.txt per site, keyed by site handle.
     * Falls back to the site name when empty.
     */
    public array $llmsTxtTitle = [];

    /**
     * @var array<string,string> Summary blockquote of /llms.txt per site,
     * keyed by site handle. Omitted when empty.
     */
    public array $llmsTxtSummary = [];

    /**
     * @var bool Whether to cache Markdown output.
     */
    public bool $enableCache = true;

    /**
     * @var int Cache TTL in seconds. Default 24h.
     */
    public int $cacheDuration = 86400;

    public function rules(): array
    {
        return [
            [['enabled', 'enableCache', 'injectDiscoveryLink', 'noIndexMarkdown', 'llmsTxtEnabled'], 'boolean'],
            [['cacheDuration', 'llmsTxtEntriesPerSection'], 'integer', 'min' => 0],
            [['excludePaths', 'includeOnlyPaths', 'aiBotPatterns', 'sectionSettings', 'llmsTxtTitle', 'llmsTxtSummary'], 'safe'],
            [['excludePathsString', 'includeOnlyPathsString', 'aiBotPatternsString'], 'safe'],
        ];
    }

    /**
     * Explicit field list for project-config serialization.
     *
     * Without this override, Craft's project-config sync filters out
     * properties whose values look like sparse arrays, which can drop our
     * array properties (excludePaths, includeOnlyPaths, aiBotPatterns).
     *
     * Note: the *String aliases are deliberately omitted — they are POST-input
     * adapters only, not persisted state.
     */
    public function fields(): array
    {
        return [
            'enabled' => 'enabled',
            'sectionSettings' => 'sectionSettings',
            'excludePaths' => 'excludePaths',
            'includeOnlyPaths' => 'includeOnlyPaths',
            'aiBotPatterns' => 'aiBotPatterns',
            'injectDiscoveryLink' => 'injectDiscoveryLink',
            'noIndexMarkdown' => 'noIndexMarkdown',
            'llmsTxtEnabled' => 'llmsTxtEnabled',
            'llmsTxtEntriesPerSection' => 'llmsTxtEntriesPerSection',
            'llmsTxtTitle' => 'llmsTxtTitle',
            'llmsTxtSummary' => 'llmsTxtSummary',
            'enableCache' => 'enableCache',
            'cacheDuration' => 'cacheDuration',
        ];
    }

    public function setAttributes($values, $safeOnly = true): void
    {
        // The textarea fields submit newline-separated strings under the
        // canonical attribute names. Normalize string → array BEFORE the
        // parent typed-property assignment runs. This avoids the v1 pattern
        // of separate `*String` POST keys, which made Craft's project-config
        // serialization (`toArray(array_keys($post))`) drop the typed values.
        if (isset($values['excludePaths']) && is_string($values['excludePaths'])) {
            $values['excludePaths'] = $this->splitLines($values['excludePaths']);
        }
        if (array_key_exists('includeOnlyPaths', $values) && is_string($values['includeOnlyPaths'])) {
            $raw = $values['includeOnlyPaths'];
            if ($raw === '') {
                $values['includeOnlyPaths'] = null;
            } else {
                $lines = $this->splitLines($raw);
                $values['includeOnlyPaths'] = empty($lines) ? null : $lines;
            }
        }
        if (isset($values['aiBotPatterns']) && is_string($values['aiBotPatterns'])) {
            $lines = $this->splitLines($values['aiBotPatterns']);
            $values['aiBotPatterns'] = empty($lines) ? self::DEFAULT_AI_BOT_PATTERNS : $lines;
        }
        // Skip empty per-site values so a save does not write empty keys for
        // every site into project config.
        foreach (['llmsTxtTitle', 'llmsTxtSummary'] as $key) {
            if (isset($values[$key]) && is_array($values[$key])) {
                $values[$key] = array_filter($values[$key], fn($v) => is_string($v) && trim($v) !== '');
            }
        }
        if (isset($values['sectionSettings']) && is_array($values['sectionSettings'])) {
            // Convert nested arrays into SectionSettings models so the
            // typed-property assignment in parent gets the right shape.
            $this->setSectionSettings($values['sectionSettings']);
            unset($values['sectionSettings']);
        }

        parent::setAttributes($values, $safeOnly);
    }

    public function setSectionSettings(array $values): void
    {
        $out = [];
        foreach ($values as $uid => $data) {
            if (!is_string($uid) || $uid === '') {
                continue;
            }
            if ($data instanceof SectionSettings) {
                $out[$uid] = $data;
                continue;
            }
            if (!is_array($data)) {
                continue;
            }
            $model = new SectionSettings();
            $model->setAttributes([
                'enabled' => (bool)($data['enabled'] ?? true),
                'aiOnly' => (bool)($data['aiOnly'] ?? false),
                'dedicatedTemplate' => $data['dedicatedTemplate'] ?? null,
                'includeFrontMatter' => (bool)($data['includeFrontMatter'] ?? true),
            ], false);
            $out[$uid] = $model;
        }
        $this->sectionSettings = $out;
    }

    public function getSectionSettings(string $uid): SectionSettings
    {
        return $this->sectionSettings[$uid] ?? new SectionSettings();
    }

    public function getExcludePathsString(): string
    {
        return implode("\n", $this->excludePaths);
    }

    public function setExcludePathsString(string|array $value): void
    {
        $this->excludePaths = $this->splitLines($value);
    }

    public function getIncludeOnlyPathsString(): ?string
    {
        return $this->includeOnlyPaths === null ? null : implode("\n", $this->includeOnlyPaths);
    }

    public function setIncludeOnlyPathsString(string|array|null $value): void
    {
        if ($value === null || $value === '') {
            $this->includeOnlyPaths = null;
            return;
        }
        $lines = $this->splitLines($value);
        $this->includeOnlyPaths = empty($lines) ? null : $lines;
    }

    public function getAiBotPatternsString(): string
    {
        return implode("\n", $this->aiBotPatterns);
    }

    public function setAiBotPatternsString(string|array $value): void
    {
        $lines = $this->splitLines($value);
        $this->aiBotPatterns = empty($lines) ? self::DEFAULT_AI_BOT_PATTERNS : $lines;
    }

    /**
     * @param string|array<int,string> $value
     * @return array<int,string>
     */
    private function splitLines(string|array $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map('trim', $value), fn($v) => $v !== ''));
        }
        return array_values(array_filter(
            array_map('trim', explode("\n", $value)),
            fn($v) => $v !== ''
        ));
    }
}
