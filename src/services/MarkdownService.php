<?php

namespace zeix\craftmarkeddown\services;

use Craft;
use League\HTMLToMarkdown\HtmlConverter;
use League\HTMLToMarkdown\Environment;
use League\HTMLToMarkdown\Converter\TableConverter;
use yii\base\Component;

/**
 * Markdown Service
 *
 * Handles conversion of HTML to Markdown
 */
class MarkdownService extends Component
{
    /**
     * @var HtmlConverter|null
     */
    private ?HtmlConverter $converter = null;

    /**
     * @var array|null Cached config file data
     */
    private ?array $configFileData = null;

    /**
     * Convert HTML to Markdown
     *
     * @param string $html The HTML content to convert
     * @param string|null $cacheKey Optional cache key. If not provided, will be generated from HTML hash
     * @param string|null $templatePath Optional template path for template-specific exclusions
     * @return string The Markdown content
     */
    public function convertHtmlToMarkdown(string $html, ?string $cacheKey = null, ?string $templatePath = null): string
    {
        if (empty($html)) {
            return '';
        }

        $settings = \zeix\craftmarkeddown\MarkedDown::getInstance()->getSettings();

        if ($settings->enableCache) {
            if ($cacheKey === null) {
                $cacheKey = 'marked-down:' . md5($html);
            } else {
                $cacheKey = 'marked-down:' . $cacheKey;
            }

            $cache = Craft::$app->getCache();
            $cached = $cache->get($cacheKey);

            if ($cached !== false) {
                return $cached;
            }
        }

        $html = $this->extractMainContent($html, $templatePath);

        $converter = $this->getConverter();
        $markdown = $converter->convert($html);

        $markdown = $this->cleanMarkdown($markdown);

        if ($settings->enableCache && isset($cacheKey)) {
            $cache->set($cacheKey, $markdown, $settings->cacheDuration);
        }

        return $markdown;
    }

    /**
     * Extract main content from HTML, removing navigation, headers, footers, etc.
     *
     * @param string $html The full HTML document
     * @param string|null $templatePath Optional template path for template-specific exclusions
     * @return string The extracted main content HTML
     */
    public function extractMainContent(string $html, ?string $templatePath = null): string
    {
        if (empty($html)) {
            return '';
        }

        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $dom->encoding = 'UTF-8';

        $html = mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8');
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);

        $selectors = [
            '//main',
            '//article',
            '//*[@id="content"]',
            '//*[@id="main-content"]',
            '//*[@class="content"]',
            '//*[@class="main-content"]',
            '//body'
        ];

        $contentNodes = [];
        foreach ($selectors as $selector) {
            $nodes = $xpath->query($selector);
            if ($nodes->length > 0) {
                foreach ($nodes as $node) {
                    $isNested = false;
                    foreach ($contentNodes as $existingNode) {
                        if ($node->isSameNode($existingNode)) {
                            $isNested = true;
                            break;
                        }

                        $parent = $node->parentNode;
                        while ($parent !== null) {
                            if ($parent->isSameNode($existingNode)) {
                                $isNested = true;
                                break 2;
                            }
                            $parent = $parent->parentNode;
                        }
                    }
                    if (!$isNested) {
                        $contentNodes[] = $node;
                    }
                }
                if ($selector !== '//body') {
                    break;
                }
            }
        }

        if (empty($contentNodes)) {
            return $html;
        }

        foreach ($contentNodes as $node) {
            $this->removeUnwantedElements($xpath, $node);
            $this->applyConfigExclusions($xpath, $node, $templatePath);
        }

        $resultHtml = '';
        foreach ($contentNodes as $node) {
            $nodeHtml = '';
            if ($node->nodeName === 'body') {
                foreach ($node->childNodes as $child) {
                    $nodeHtml .= $dom->saveHTML($child);
                }
            } else {
                $nodeHtml = $dom->saveHTML($node);
            }

            $lines = explode("\n", $nodeHtml);
            $lines = array_map('trim', $lines);
            $nodeHtml = implode("\n", $lines);

            $nodeHtml = preg_replace('/\n{2,}/', "\n", $nodeHtml);

            $resultHtml .= $nodeHtml . "\n";
        }

        return $resultHtml;
    }

    /**
     * Apply exclusions from config file
     *
     * @param \DOMXPath $xpath
     * @param \DOMElement|\DOMNode $context
     * @param string|null $templatePath Template path for template-specific exclusions
     */
    protected function applyConfigExclusions(\DOMXPath $xpath, $context, ?string $templatePath = null): void
    {
        $config = $this->getConfigFileData();
        if (empty($config)) {
            return;
        }

        $exclusions = [];

        if (isset($config['globalExclusions']) && is_array($config['globalExclusions'])) {
            $exclusions = array_merge($exclusions, $config['globalExclusions']);
        }

        if ($templatePath && isset($config['templateExclusions']) && is_array($config['templateExclusions'])) {
            $normalizedPath = preg_replace('/\.twig$/', '', $templatePath);

            foreach ($config['templateExclusions'] as $pattern => $selectors) {
                $normalizedPattern = preg_replace('/\.twig$/', '', $pattern);

                if ($normalizedPath === $normalizedPattern ||
                    str_ends_with($normalizedPath, '/' . $normalizedPattern) ||
                    str_contains($normalizedPath, $normalizedPattern)) {
                    if (is_array($selectors)) {
                        $exclusions = array_merge($exclusions, $selectors);
                    }
                }
            }
        }

        if (empty($exclusions)) {
            return;
        }

        $this->removeElementsBySelectors($xpath, $context, $exclusions);
    }

    /**
     * Remove elements by CSS selectors
     *
     * @param \DOMXPath $xpath
     * @param \DOMElement|\DOMNode $context
     * @param array $selectors CSS selectors to remove
     */
    protected function removeElementsBySelectors(\DOMXPath $xpath, $context, array $selectors): void
    {
        foreach ($selectors as $selector) {
            $selector = trim($selector);
            if (empty($selector)) {
                continue;
            }

            $xpathSelector = $this->cssSelectorToXPath($selector);
            if ($xpathSelector === null) {
                continue;
            }

            try {
                $elements = $xpath->query($xpathSelector, $context);
                foreach ($elements as $element) {
                    if ($element->parentNode) {
                        $element->parentNode->removeChild($element);
                    }
                }
            } catch (\Throwable $e) {
                Craft::warning(
                    'Marked Down: Invalid CSS selector in config: ' . $selector,
                    __METHOD__
                );
            }
        }
    }

    /**
     * Convert simple CSS selector to XPath
     * Supports: #id, .class, element, element#id, element.class
     *
     * @param string $selector CSS selector
     * @return string|null XPath selector or null if conversion fails
     */
    protected function cssSelectorToXPath(string $selector): ?string
    {
        $selector = trim($selector);

        if (str_starts_with($selector, '#')) {
            $id = substr($selector, 1);
            return "//*[@id='" . addslashes($id) . "']";
        }

        if (str_starts_with($selector, '.')) {
            $class = substr($selector, 1);
            return "//*[contains(concat(' ', normalize-space(@class), ' '), ' " . addslashes($class) . " ')]";
        }

        if (preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/', $selector)) {
            return '//' . $selector;
        }

        if (preg_match('/^([a-zA-Z][a-zA-Z0-9_-]*)#([a-zA-Z][a-zA-Z0-9_-]*)$/', $selector, $matches)) {
            return "//{$matches[1]}[@id='" . addslashes($matches[2]) . "']";
        }

        if (preg_match('/^([a-zA-Z][a-zA-Z0-9_-]*)\.([a-zA-Z][a-zA-Z0-9_-]*)$/', $selector, $matches)) {
            return "//{$matches[1]}[contains(concat(' ', normalize-space(@class), ' '), ' " . addslashes($matches[2]) . " ')]";
        }

        return null;
    }

    /**
     * Get config file data
     *
     * @return array
     */
    protected function getConfigFileData(): array
    {
        if ($this->configFileData !== null) {
            return $this->configFileData;
        }

        try {
            $config = Craft::$app->config->getConfigFromFile('marked-down');
            $this->configFileData = is_array($config) ? $config : [];
        } catch (\Throwable $e) {
            $this->configFileData = [];
        }

        return $this->configFileData;
    }

    /**
     * Remove unwanted elements (nav, header, footer, aside, scripts, styles)
     *
     * @param \DOMXPath $xpath
     * @param \DOMElement|\DOMNode $context
     */
    protected function removeUnwantedElements(\DOMXPath $xpath, $context): void
    {
        $unwantedSelectors = [
            './/nav',
            './/header',
            './/footer',
            './/aside',
            './/script',
            './/style',
            './/noscript',
            './/iframe',
            './/canvas',
            './/svg',
        ];

        foreach ($unwantedSelectors as $selector) {
            $elements = $xpath->query($selector, $context);
            foreach ($elements as $element) {
                if ($element->parentNode) {
                    $element->parentNode->removeChild($element);
                }
            }
        }
    }

    /**
     * Clean up the Markdown output
     *
     * @param string $markdown The raw Markdown
     * @return string The cleaned Markdown
     */
    public function cleanMarkdown(string $markdown): string
    {
        $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);
        
        $markdown = html_entity_decode($markdown, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $markdown = preg_replace('/!\s+\[/', '![', $markdown);

        $markdown = preg_replace('/\[\s+/', '[', $markdown);

        $markdown = preg_replace('/\s+\]/', ']', $markdown);

        $markdown = preg_replace('/\n{3,}/', "\n\n", $markdown);

        $markdown = preg_replace('/^#{1,6}\s*$/m', '', $markdown);

        return trim($markdown);
    }

    /**
     * Fix image formatting issues
     *
     * @param string $markdown The Markdown content
     * @return string The fixed Markdown
     */
    protected function fixImageFormatting(string $markdown): string
    {
        $markdown = preg_replace('/!\s+\[/m', '![', $markdown);

        $markdown = preg_replace_callback(
            '/!\[([^\]]+)\]\(([^)]+)\)/',
            function ($matches) {
                $alt = trim($matches[1]);
                $url = trim($matches[2]);
                return '![' . $alt . '](' . $url . ')';
            },
            $markdown
        );

        $markdown = preg_replace('/!\[\s+([^\]]+)\s+\]\(/', '![$1](', $markdown);

        $markdown = preg_replace('/!\[([^\]]+)\]\s+\(/', '![$1](', $markdown);

        return $markdown;
    }

    /**
     * Fix line breaks for headers and images
     *
     * @param string $markdown The Markdown content
     * @return string The fixed Markdown
     */
    protected function fixLineBreaks(string $markdown): string
    {
        $markdown = preg_replace('/([^\n])(#{1,6}\s+)/', "$1\n\n$2", $markdown);

        $markdown = preg_replace('/(!\[[^\]]+\]\([^)]+\))([^\n\s])/', "$1\n\n$2", $markdown);

        $markdown = preg_replace('/([^\n\s])(!\[[^\]]+\]\([^)]+\))/', "$1\n\n$2", $markdown);

        $markdown = preg_replace('/(!\[[^\]]+\]\([^)]+\))\s+(!\[[^\]]+\]\([^)]+\))/', "$1\n\n$2", $markdown);

        $markdown = preg_replace('/(!\[[^\]]+\]\([^)]+\))\s+(\[)/', "$1\n\n$2", $markdown);

        $markdown = preg_replace('/(\]\([^)]+\))\s+(#{1,6}\s+)/', "$1\n\n$2", $markdown);

        return $markdown;
    }

    /**
     * Fix list formatting to ensure list items are on new lines
     *
     * @param string $markdown The Markdown content
     * @return string The fixed Markdown
     */
    protected function fixListFormatting(string $markdown): string
    {
        $lines = explode("\n", $markdown);
        $fixedLines = [];

        foreach ($lines as $i => $line) {
            $trimmed = trim($line);

            if (empty($trimmed)) {
                $fixedLines[] = $line;
                continue;
            }

            $fixedLine = $line;

            if (preg_match('/^[-*+]\s+/', $trimmed)) {
                if ($i > 0 && !empty(trim($lines[$i - 1]))) {
                    $prevLine = trim($lines[$i - 1]);
                    if (!preg_match('/^[-*+]\s+/', $prevLine) && !preg_match('/^#{1,6}\s+/', $prevLine)) {
                        $fixedLines[count($fixedLines) - 1] = rtrim($fixedLines[count($fixedLines) - 1]) . "\n";
                    }
                }
                $fixedLines[] = $fixedLine;
            } else {
                $fixedLine = preg_replace('/([^\n])\s+([-*+]\s+)/', "$1\n$2", $fixedLine);
                $fixedLines[] = $fixedLine;
            }
        }

        $markdown = implode("\n", $fixedLines);

        $markdown = preg_replace('/([^\n])\s+([-*+]\s+)/', "$1\n$2", $markdown);

        return $markdown;
    }

    /**
     * Clean up and normalize Markdown links
     *
     * @param string $markdown The Markdown content
     * @return string The cleaned Markdown
     */
    protected function cleanLinks(string $markdown): string
    {
        $markdown = preg_replace_callback(
            '/\[((?:[^\]]|(?:\n[^\]]))*)\]\s*\(([^)]+)\)/s',
            function ($matches) {
                $linkText = $matches[1];
                $url = trim($matches[2]);

                $headers = [];
                $linkText = preg_replace_callback(
                    '/\s*(#{1,6}\s+[^\n\]]+)\s*/',
                    function ($headerMatches) use (&$headers) {
                        $headers[] = trim($headerMatches[1]);
                        return ' ';
                    },
                    $linkText
                );

                $linkText = trim(preg_replace('/\s+/', ' ', $linkText));
                $linkText = preg_replace('/\s{2,}/', ' ', $linkText);

                $result = '';
                if (!empty($headers)) {
                    $result = implode("\n\n", $headers) . "\n\n";
                }

                if (!empty($linkText)) {
                    $result .= '[' . $linkText . '](' . $url . ')';
                } else {
                    $result .= '[' . $url . '](' . $url . ')';
                }

                return $result;
            },
            $markdown
        );

        $markdown = preg_replace('/\[\s+([^\]]+?)\s+\]\(/', '[$1](', $markdown);

        $markdown = preg_replace_callback(
            '/(\[[^\]]+\]\([^)]+\))\s+\1/',
            function ($matches) {
                return $matches[1];
            },
            $markdown
        );

        $markdown = preg_replace_callback(
            '/\[\s*!\[([^\]]*)\]\(([^)]+)\)\s*([^\]]*)\]\s*\(([^)]+)\)/',
            function ($matches) {
                $alt = $matches[1];
                $imgUrl = $matches[2];
                $linkText = trim(preg_replace('/\s+/', ' ', $matches[3]));
                $linkUrl = $matches[4];

                if (empty($linkText)) {
                    return '![' . $alt . '](' . $imgUrl . ')';
                }

                return '![' . $alt . '](' . $imgUrl . ') [' . $linkText . '](' . $linkUrl . ')';
            },
            $markdown
        );

        $markdown = preg_replace('/!\s+\[/m', '![', $markdown);

        $markdown = preg_replace_callback(
            '/!\[([^\]]+)\]\(([^)]+)\)\s+\[([^\]]*(?:\n[^\]]*)*)\]\s*\(([^)]+)\)/s',
            function ($matches) {
                $imgAlt = $matches[1];
                $imgUrl = $matches[2];
                $linkText = trim(preg_replace('/\s+/', ' ', $matches[3]));
                $linkUrl = $matches[4];

                $linkText = preg_replace('/^#{1,6}\s+/', '', $linkText);
                $linkText = preg_replace('/\s+/', ' ', $linkText);

                return '![' . $imgAlt . '](' . $imgUrl . ') [' . $linkText . '](' . $linkUrl . ')';
            },
            $markdown
        );

        $markdown = preg_replace('/([^\s])\[([^\]]+)\]\(([^)]+)\)/', '$1 [$2]($3)', $markdown);
        $markdown = preg_replace('/\s{2,}\[/', ' [', $markdown);

        $markdown = $this->fixBrokenLinksWithHeaders($markdown);

        return $markdown;
    }

    /**
     * Fix links that contain headers inside them (broken markdown)
     *
     * @param string $markdown The Markdown content
     * @return string The fixed Markdown
     */
    protected function fixBrokenLinksWithHeaders(string $markdown): string
    {
        $markdown = preg_replace_callback(
            '/\[([^\]]+)\n+\s*(#{1,6}\s+[^\]]+)\]\s*\(([^)]+)\)/s',
            function ($matches) {
                $linkText = trim($matches[1]);
                $header = trim($matches[2]);
                $url = trim($matches[3]);

                $cleanLinkText = preg_replace('/\s+/', ' ', $linkText);

                $result = $header . "\n\n";
                if (!empty($cleanLinkText)) {
                    $result .= '[' . $cleanLinkText . '](' . $url . ')';
                } else {
                    $result .= '[' . $url . '](' . $url . ')';
                }

                return $result;
            },
            $markdown
        );

        return $markdown;
    }

    /**
     * Get or create the HTML converter instance
     *
     * @return HtmlConverter
     */
    protected function getConverter(): HtmlConverter
    {
        if ($this->converter === null) {
            $environment = Environment::createDefaultEnvironment([
                'strip_tags' => true,
                'use_autolinks' => true,
                'hard_break' => false,
                'header_style' => 'atx',
                'italic_style' => '_',
                'bold_style' => '**',
                'remove_nodes' => 'script style noscript iframe canvas svg map area head meta link',
            ]);

            $environment->addConverter(new TableConverter());

            $this->converter = new HtmlConverter($environment);
        }

        return $this->converter;
    }
}
