<?php
/**
 * Marked Down Plugin Configuration File
 *
 * Copy this file to your Craft config directory as config/marked-down.php
 * to configure template-specific exclusions.
 *
 * This file allows you to exclude specific CSS selectors from the Markdown output
 * based on templates or globally.
 */

return [
    /**
     * Global exclusions - CSS selectors that should always be excluded from Markdown output
     * 
     * Supported selectors:
     * - #id (ID selector)
     * - .class (Class selector)
     * - element (Element selector)
     * - element#id (Element with ID)
     * - element.class (Element with class)
     * 
     * @var array
     */
    'globalExclusions' => [
        // Example: '.sidebar',
        // Example: '#comments',
        // Example: '.related-posts',
    ],

    /**
     * Template-specific exclusions
     *
     * Map template paths (relative to templates/ directory) to arrays of CSS selectors to exclude.
     * Template paths can be specified with or without the .twig extension.
     *
     * The plugin automatically tracks which template was rendered and applies the appropriate exclusions.
     * These exclusions are applied IN ADDITION to globalExclusions.
     *
     * Examples:
     * - 'blog/_entry' => ['#comments', '.author-bio']
     *   Excludes comments and author bio only on blog entry pages
     *
     * - 'news/_entry' => ['.share-buttons', '.related-posts']
     *   Excludes share buttons and related posts only on news entry pages
     *
     * - '_layouts/article' => ['.sidebar']
     *   Excludes sidebar for any page using the article layout
     *
     * Template matching is flexible and supports:
     * - Exact matches: 'blog/_entry' matches 'blog/_entry.twig'
     * - Partial matches: '_entry' matches any template ending with '_entry'
     * - Path matches: '_layouts/article' matches templates in _layouts folder
     *
     * @var array
     */
    'templateExclusions' => [
        // 'blog/_entry' => ['#comments', '.author-bio'],
        // 'news/_entry' => ['.share-buttons'],
        // '_layouts/article' => ['.sidebar'],
    ],
];
