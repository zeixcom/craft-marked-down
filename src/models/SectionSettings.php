<?php

namespace zeix\craftmarkeddown\models;

use craft\base\Model;

/**
 * Per-section Marked Down settings.
 *
 * Stored inside Settings::sectionSettings keyed by section UID so that the
 * config survives section renames and round-trips cleanly through project
 * config across environments.
 */
class SectionSettings extends Model
{
    /**
     * @var bool Whether Markdown serving is enabled for this section.
     */
    public bool $enabled = false;

    /**
     * @var bool When true, Markdown is only served to detected AI bots —
     *           humans hitting the .md URL or sending Accept: text/markdown
     *           still get HTML. Useful for sections where you want to
     *           gate access tightly.
     */
    public bool $aiOnly = false;

    /**
     * @var string|null Optional Twig template (relative to templates/) that
     *                  outputs raw Markdown for this section's entries.
     *                  When set, the plugin renders this template instead of
     *                  converting HTML → Markdown. Wired in Phase 6.
     */
    public ?string $dedicatedTemplate = null;

    /**
     * @var bool Whether to prepend YAML front matter to Markdown output.
     */
    public bool $includeFrontMatter = true;

    public function rules(): array
    {
        return [
            [['enabled', 'aiOnly', 'includeFrontMatter'], 'boolean'],
            [['dedicatedTemplate'], 'string'],
            [['dedicatedTemplate'], 'default', 'value' => null],
        ];
    }
}
