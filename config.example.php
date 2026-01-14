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
     * Map template paths (relative to templates/ directory, without .twig extension)
     * to arrays of CSS selectors to exclude.
     * 
     * Example:
     * 'home/_entry' => ['.sidebar', '.related-posts'],
     * 'blog/_entry' => ['#comments', '.author-bio'],
     * 
     * Note: Template-based exclusions are planned for future releases.
     * Currently, only globalExclusions are supported.
     * 
     * @var array
     */
    'templateExclusions' => [
        // Example: 'home/_entry' => ['.sidebar', '.related-posts'],
        // Example: 'blog/_entry' => ['#comments', '.author-bio'],
    ],
];
