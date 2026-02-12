<?php

namespace zeix\craftmarkeddown\models;

use craft\base\Model;

/**
 * Marked Down settings
 */
class Settings extends Model
{
    /**
     * @var bool Whether the plugin is enabled
     */
    public bool $enabled = true;

    /**
     * @var array Paths to exclude from Markdown conversion
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
     * @var array|null Paths to include (whitelist). If null, all paths are included (except excluded ones)
     */
    public ?array $includeOnlyPaths = null;

    /**
     * @var bool Whether to enable caching
     */
    public bool $enableCache = true;

    /**
     * @var int Cache duration in seconds (default: 24 hours)
     */
    public int $cacheDuration = 86400;

    /**
     * @inheritdoc
     */
    public function fields(): array
    {
        $fields = parent::fields();

        $fields['excludePathsString'] = 'excludePaths';
        $fields['includeOnlyPathsString'] = 'includeOnlyPaths';

        return $fields;
    }

    /**
     * @inheritdoc
     */
    public function rules(): array
    {
        return [
            [['enabled', 'enableCache'], 'boolean'],
            [['cacheDuration'], 'integer', 'min' => 0],
            [['excludePaths', 'includeOnlyPaths'], 'safe'],
            [['excludePathsString', 'includeOnlyPathsString'], 'safe'],
        ];
    }

    /**
     * @inheritdoc
     */
    public function setAttributes($values, $safeOnly = true): void
    {
        parent::setAttributes($values, $safeOnly);

        if (isset($values['excludePathsString'])) {
            $this->setExcludePathsString($values['excludePathsString']);
        }

        if (isset($values['includeOnlyPathsString'])) {
            $this->setIncludeOnlyPathsString($values['includeOnlyPathsString'] ?? null);
        }
    }

    /**
     * Get exclude paths as a newline-separated string
     */
    public function getExcludePathsString(): string
    {
        return implode("\n", $this->excludePaths);
    }

    /**
     * Set exclude paths from a newline-separated string
     */
    public function setExcludePathsString(string|array $value): void
    {
        if (is_array($value)) {
            $this->excludePaths = array_values($value);
            return;
        }

        $paths = array_filter(
            array_map('trim', explode("\n", $value)),
            fn($path) => !empty($path)
        );
        $this->excludePaths = array_values($paths);
    }

    /**
     * Get include-only paths as a newline-separated string
     */
    public function getIncludeOnlyPathsString(): ?string
    {
        if ($this->includeOnlyPaths === null) {
            return null;
        }
        return implode("\n", $this->includeOnlyPaths);
    }

    /**
     * Set include-only paths from a newline-separated string
     */
    public function setIncludeOnlyPathsString(string|array|null $value): void
    {
        if ($value === null || $value === '') {
            $this->includeOnlyPaths = null;
            return;
        }

        if (is_array($value)) {
            $this->includeOnlyPaths = empty($value) ? null : array_values($value);
            return;
        }

        $paths = array_filter(
            array_map('trim', explode("\n", $value)),
            fn($path) => !empty($path)
        );
        $this->includeOnlyPaths = empty($paths) ? null : array_values($paths);
    }
}
