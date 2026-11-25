<?php

namespace zeix\craftmarkeddown\services;

use Craft;
use craft\elements\Entry;
use craft\models\Section;
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
        $lines[] = '# ' . ($this->singleLine($settings->llmsTxtTitle[$site->handle] ?? '') ?: $site->getName());
        $lines[] = '';

        $summary = $this->singleLine($settings->llmsTxtSummary[$site->handle] ?? '');
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

            $query = Entry::find()
                ->section($section)
                ->siteId($site->id)
                ->status(Entry::STATUS_LIVE)
                ->limit($perSection);

            // Structure sections keep their editorial order. Others list the
            // newest first and fall back to the title so entries without a
            // distinguishing post date have a stable order.
            if ($section->type === Section::TYPE_STRUCTURE) {
                $query->orderBy(['structureelements.lft' => SORT_ASC]);
            } else {
                $query->orderBy(['postDate' => SORT_DESC, 'title' => SORT_ASC]);
            }

            $entries = $query->all();
            if (empty($entries)) {
                continue;
            }

            $lines[] = '## ' . $section->name;
            $lines[] = '';

            foreach ($entries as $entry) {
                $url = $entry->getUrl();
                if (!$url) {
                    continue;
                }
                $mdUrl = MarkedDown::markdownUrl($url);
                $lines[] = '- [' . $this->escape($entry->title) . '](' . $mdUrl . ')';
            }
            $lines[] = '';
        }

        return rtrim(implode("\n", $lines)) . "\n";
    }

    /**
     * Collapse whitespace so a multi-line value cannot break the heading or
     * blockquote it is rendered into.
     */
    private function singleLine(string $text): string
    {
        return trim((string)preg_replace('/\s+/', ' ', $text));
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
