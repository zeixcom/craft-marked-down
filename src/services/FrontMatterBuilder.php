<?php

namespace zeix\craftmarkeddown\services;

use craft\elements\Entry;
use yii\base\Component;

/**
 * Builds YAML front matter for an Entry.
 *
 * Used by MarkdownService (entry → Markdown) and by the /llms.txt generator
 * for /llms.txt. Output is conservative: every string value is single-quoted
 * with internal "'" escaped as "''", which keeps any YAML parser happy.
 */
class FrontMatterBuilder extends Component
{
    /**
     * Build the front matter block for an entry, including the leading and
     * trailing "---" delimiters and a trailing newline. Returns an empty
     * string when the entry yields no fields.
     */
    public function build(Entry $entry): string
    {
        $fields = $this->fieldsFor($entry);

        $fields = array_filter(
            $fields,
            fn($value) => $value !== null && $value !== '' && $value !== []
        );

        if (empty($fields)) {
            return '';
        }

        $lines = ['---'];
        foreach ($fields as $key => $value) {
            $lines[] = $key . ': ' . $this->encodeValue($value);
        }
        $lines[] = '---';
        $lines[] = '';

        return implode("\n", $lines) . "\n";
    }

    /**
     * Returns the field map for the given entry.
     *
     * This is the design surface — what counts as "useful metadata" depends
     * on the project. The default implementation covers the fields named in
     * the v1.1 spec: title, date, author, canonical URL, section. Override
     * fieldsFor() in a subclass (or via a Craft component config) to extend.
     *
     * @return array<string,scalar|array<int,string>|null>
     */
    protected function fieldsFor(Entry $entry): array
    {
        return [
            'title' => $entry->title,
            'date' => $entry->postDate?->format('c'),
            'updated' => $entry->dateUpdated?->format('c'),
            'author' => $entry->getAuthor()?->fullName,
            'canonical' => $entry->getUrl(),
            'section' => $entry->getSection()?->handle,
        ];
    }

    /**
     * Encode a single value as a YAML scalar or flow-style list.
     *
     * @param scalar|array<int,string> $value
     */
    protected function encodeValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        if (is_array($value)) {
            $parts = array_map(fn($v) => $this->encodeValue((string)$v), $value);
            return '[' . implode(', ', $parts) . ']';
        }
        $str = (string)$value;
        return "'" . str_replace("'", "''", $str) . "'";
    }
}
