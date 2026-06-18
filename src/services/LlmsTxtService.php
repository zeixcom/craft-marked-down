<?php

namespace zeix\craftmarkeddown\services;

use Craft;
use craft\elements\Entry;
use yii\base\Component;
use zeix\craftmarkeddown\MarkedDown;

/**
 * Builds the /llms.txt site index following https://llmstxt.org/.
 *
 * Output shape:
 *
 *   # {Site name}
 *
 *   > Optional summary blockquote.
 *
 *   ## {Section name}
 *
 *   - [Entry title]({entry-url}.md): optional summary
 *   - ...
 */
class LlmsTxtService extends Component
{
    public function build(): string
    {
        $plugin = MarkedDown::getInstance();
        $settings = $plugin->getSettings();
        $site = Craft::$app->getSites()->getCurrentSite();
        $sectionsService = Craft::$app->getEntries();

        $lines = [];
        $lines[] = '# ' . $site->getName();
        $lines[] = '';

        $summary = $this->summary();
        if ($summary !== '') {
            $lines[] = '> ' . $summary;
            $lines[] = '';
        }

        $perSection = max(0, (int)$settings->llmsTxtEntriesPerSection);

        foreach ($sectionsService->getAllSections() as $section) {
            $cfg = $settings->getSectionSettings($section->uid);
            if (!$cfg->enabled) {
                continue;
            }

            // Skip sections without URIs in this site (e.g. structure with no
            // siteSettings or sections explicitly disabled per-site).
            $siteSettings = $section->getSiteSettings()[$site->id] ?? null;
            if (!$siteSettings || !$siteSettings->hasUrls) {
                continue;
            }

            $lines[] = '## ' . $section->name;
            $lines[] = '';

            $entries = Entry::find()
                ->section($section)
                ->siteId($site->id)
                ->status(Entry::STATUS_LIVE)
                ->orderBy(['postDate' => SORT_DESC])
                ->limit($perSection)
                ->all();

            if (empty($entries)) {
                $lines[] = '_No entries yet._';
                $lines[] = '';
                continue;
            }

            foreach ($entries as $entry) {
                $url = $entry->getUrl();
                if (!$url) {
                    continue;
                }
                // Append `.md`, never trim. For `https://site/foo`  → `…/foo.md`.
                // For homepage `https://site/`  → `https://site/.md` (the
                // EVENT_BEFORE_REQUEST handler strips `.md` from path, leaving
                // empty path, which Craft routes to the homepage entry).
                $mdUrl = $url . '.md';
                $lines[] = '- [' . $this->escape($entry->title) . '](' . $mdUrl . ')';
            }
            $lines[] = '';
        }

        return rtrim(implode("\n", $lines)) . "\n";
    }

    /**
     * Optional one-line site summary. Override or extend if you want a real
     * blurb. Returning '' suppresses the blockquote.
     */
    protected function summary(): string
    {
        return '';
    }

    private function escape(string $text): string
    {
        // Markdown link text cannot contain unescaped ] — escape the few
        // characters that would break the [link](url) syntax.
        return strtr($text, [
            '[' => '\\[',
            ']' => '\\]',
        ]);
    }
}
