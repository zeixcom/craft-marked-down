<?php

namespace zeix\craftmarkeddown\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use yii\console\ExitCode;
use zeix\craftmarkeddown\MarkedDown;

/**
 * Marked Down CLI Commands
 *
 * Provides command-line utilities for testing and managing the Marked Down plugin.
 */
class DefaultController extends Controller
{
    /**
     * @var string|null URL to convert (for convert command)
     */
    public ?string $url = null;

    /**
     * @var bool Show detailed output
     */
    public bool $verbose = false;

    /**
     * @var string Default action
     */
    public $defaultAction = 'info';

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);

        if ($actionID === 'convert') {
            $options[] = 'verbose';
        }

        return $options;
    }

    /**
     * Convert a URL to Markdown
     *
     * Fetches HTML from a URL and converts it to Markdown using the plugin's conversion logic.
     *
     * @param string $url The URL to fetch and convert
     * @return int
     */
    public function actionConvert(string $url): int
    {
        $this->stdout("Fetching URL: {$url}\n", Console::FG_BLUE);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Marked Down CLI/1.0');

        $html = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        if ($html === false || $httpCode !== 200) {
            $this->stdout("Error: Failed to fetch URL (HTTP {$httpCode}): {$error}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Fetched " . strlen($html) . " bytes\n", Console::FG_GREEN);

        if ($this->verbose) {
            $this->stdout("\nHTML Preview (first 500 chars):\n", Console::FG_YELLOW);
            $this->stdout(substr($html, 0, 500) . "...\n\n");
        }


        $this->stdout("Converting to Markdown...\n", Console::FG_BLUE);

        try {
            $plugin = MarkedDown::getInstance();
            $service = $plugin->markdownService;

            $markdown = $service->convertHtmlToMarkdown($html);

            $this->stdout("\n" . str_repeat('=', 80) . "\n", Console::FG_CYAN);
            $this->stdout("MARKDOWN OUTPUT\n", Console::FG_CYAN);
            $this->stdout(str_repeat('=', 80) . "\n\n", Console::FG_CYAN);

            $this->stdout($markdown . "\n\n");

            $this->stdout(str_repeat('=', 80) . "\n", Console::FG_CYAN);
            $this->stdout("Conversion complete!\n", Console::FG_GREEN);
            $this->stdout("Markdown length: " . strlen($markdown) . " bytes\n");
            $this->stdout("Compression: " . round((1 - strlen($markdown) / strlen($html)) * 100, 1) . "%\n", Console::FG_GREEN);

            return ExitCode::OK;
        } catch (\Throwable $e) {
            $this->stdout("\nError: " . $e->getMessage() . "\n", Console::FG_RED);
            if ($this->verbose) {
                $this->stdout("\nStack trace:\n" . $e->getTraceAsString() . "\n", Console::FG_RED);
            }
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }

    /**
     * Clear the Markdown cache
     *
     * Removes all cached Markdown conversions from the cache.
     *
     * @return int
     */
    public function actionCacheClear(): int
    {
        $this->stdout("Clearing Marked Down cache...\n", Console::FG_BLUE);

        try {
            $cache = Craft::$app->getCache();

            $cache->flush();

            $this->stdout("Cache cleared successfully!\n", Console::FG_GREEN);
            return ExitCode::OK;
        } catch (\Throwable $e) {
            $this->stdout("Error: " . $e->getMessage() . "\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }

    /**
     * Test conversion with sample HTML
     *
     * Tests the Markdown conversion with sample HTML to verify the plugin is working correctly.
     *
     * @return int
     */
    public function actionTestConversion(): int
    {
        $this->stdout("Testing Markdown conversion...\n\n", Console::FG_BLUE);

        $sampleHtml = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <title>Test Page</title>
</head>
<body>
    <nav>
        <ul><li><a href="/">Home</a></li></ul>
    </nav>

    <main>
        <h1>Test Article</h1>
        <p>This is a <strong>test article</strong> with <em>various elements</em>.</p>

        <h2>Features</h2>
        <ul>
            <li>List item 1</li>
            <li>List item 2</li>
            <li>List item 3</li>
        </ul>

        <p>Here's a <a href="https://example.com">link to example.com</a>.</p>

        <img src="/test.jpg" alt="Test Image">

        <h3>Code Example</h3>
        <pre><code>console.log('Hello World');</code></pre>

        <table>
            <thead>
                <tr><th>Column 1</th><th>Column 2</th></tr>
            </thead>
            <tbody>
                <tr><td>Data 1</td><td>Data 2</td></tr>
            </tbody>
        </table>
    </main>

    <footer>
        <p>&copy; 2024 Test Site</p>
    </footer>
</body>
</html>
HTML;

        $this->stdout("Sample HTML:\n", Console::FG_YELLOW);
        $this->stdout($sampleHtml . "\n\n");

        try {
            $plugin = MarkedDown::getInstance();
            $service = $plugin->markdownService;

            $markdown = $service->convertHtmlToMarkdown($sampleHtml);

            $this->stdout(str_repeat('=', 80) . "\n", Console::FG_CYAN);
            $this->stdout("MARKDOWN OUTPUT\n", Console::FG_CYAN);
            $this->stdout(str_repeat('=', 80) . "\n\n", Console::FG_CYAN);

            $this->stdout($markdown . "\n\n");

            $this->stdout(str_repeat('=', 80) . "\n", Console::FG_CYAN);

            $checks = [
                'Headings' => preg_match('/^#\s+Test Article/m', $markdown),
                'Bold text' => str_contains($markdown, '**test article**'),
                'Italic text' => str_contains($markdown, '_various elements_'),
                'Links' => str_contains($markdown, '[link to example.com](https://example.com)'),
                'Lists' => str_contains($markdown, '- List item 1'),
                'Images' => str_contains($markdown, '![Test Image](/test.jpg)'),
                'Tables' => str_contains($markdown, '| Column 1 | Column 2 |'),
            ];

            $this->stdout("\nConversion checks:\n", Console::FG_YELLOW);
            $allPassed = true;
            foreach ($checks as $name => $passed) {
                $status = $passed ? '✓' : '✗';
                $color = $passed ? Console::FG_GREEN : Console::FG_RED;
                $this->stdout("  {$status} {$name}\n", $color);
                if (!$passed) {
                    $allPassed = false;
                }
            }

            if ($allPassed) {
                $this->stdout("\n✓ All checks passed! Plugin is working correctly.\n", Console::FG_GREEN);
                return ExitCode::OK;
            } else {
                $this->stdout("\n✗ Some checks failed. Please review the output.\n", Console::FG_RED);
                return ExitCode::UNSPECIFIED_ERROR;
            }
        } catch (\Throwable $e) {
            $this->stdout("\nError: " . $e->getMessage() . "\n", Console::FG_RED);
            $this->stdout("\nStack trace:\n" . $e->getTraceAsString() . "\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }

    /**
     * Show plugin information and status
     *
     * Displays the current plugin configuration and status.
     *
     * @return int
     */
    public function actionInfo(): int
    {
        $plugin = MarkedDown::getInstance();
        $settings = $plugin->getSettings();

        $this->stdout("\n" . str_repeat('=', 80) . "\n", Console::FG_CYAN);
        $this->stdout("MARKED DOWN PLUGIN INFO\n", Console::FG_CYAN);
        $this->stdout(str_repeat('=', 80) . "\n\n", Console::FG_CYAN);

        $this->stdout("Status: ", Console::FG_YELLOW);
        $this->stdout($settings->enabled ? "Enabled ✓\n" : "Disabled ✗\n", $settings->enabled ? Console::FG_GREEN : Console::FG_RED);

        $this->stdout("\nCache Settings:\n", Console::FG_YELLOW);
        $this->stdout("  Enabled: " . ($settings->enableCache ? 'Yes' : 'No') . "\n");
        $this->stdout("  Duration: " . $settings->cacheDuration . " seconds (" . round($settings->cacheDuration / 3600, 1) . " hours)\n");

        $this->stdout("\nExcluded Paths:\n", Console::FG_YELLOW);
        if (!empty($settings->excludePaths)) {
            foreach ($settings->excludePaths as $path) {
                $this->stdout("  - {$path}\n");
            }
        } else {
            $this->stdout("  (none)\n");
        }

        $this->stdout("\nInclude Only Paths:\n", Console::FG_YELLOW);
        if (!empty($settings->includeOnlyPaths)) {
            foreach ($settings->includeOnlyPaths as $path) {
                $this->stdout("  - {$path}\n");
            }
        } else {
            $this->stdout("  (all paths allowed)\n");
        }

        $configPath = Craft::$app->getPath()->getConfigPath() . '/marked-down.php';
        $this->stdout("\nConfig File:\n", Console::FG_YELLOW);
        if (file_exists($configPath)) {
            $this->stdout("  ✓ Found at: {$configPath}\n", Console::FG_GREEN);
        } else {
            $this->stdout("  ✗ Not found (using defaults)\n", Console::FG_YELLOW);
        }

        $this->stdout("\n" . str_repeat('=', 80) . "\n", Console::FG_CYAN);

        return ExitCode::OK;
    }
}
