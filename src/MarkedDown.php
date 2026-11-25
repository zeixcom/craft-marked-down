<?php

namespace zeix\craftmarkeddown;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin;
use craft\elements\Entry;
use craft\events\ConfigEvent;
use craft\events\ElementEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\TemplateEvent;
use craft\services\Elements;
use craft\services\ProjectConfig;
use craft\web\Request;
use craft\web\Response;
use craft\web\UrlManager;
use craft\web\View;
use yii\base\Event;
use yii\caching\TagDependency;
use zeix\craftmarkeddown\models\Settings;
use zeix\craftmarkeddown\services\FrontMatterBuilder;
use zeix\craftmarkeddown\services\LlmsTxtService;
use zeix\craftmarkeddown\services\MarkdownService;

/**
 * Marked Down plugin
 *
 * @method static MarkedDown getInstance()
 * @method Settings getSettings()
 * @property-read MarkdownService $markdownService
 * @property-read FrontMatterBuilder $frontMatterBuilder
 * @property-read LlmsTxtService $llmsTxtService
 * @author Fabian Häfliger
 * @copyright https://zeix.com
 * @license MIT
 */
class MarkedDown extends Plugin
{
    public string $schemaVersion = '1.1.0';
    public bool $hasCpSettings = true;

    /**
     * Set by RenderController when a `.md` URL is being served. The response
     * handler treats this as a hard "respond with Markdown" signal that
     * bypasses Accept-header and User-Agent detection.
     */
    public bool $forceMarkdown = false;

    public static function config(): array
    {
        return [
            'components' => [
                'markdownService' => MarkdownService::class,
                'frontMatterBuilder' => FrontMatterBuilder::class,
                'llmsTxtService' => LlmsTxtService::class,
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['subnav'] = [
            'settings' => ['label' => 'Settings', 'url' => 'marked-down/settings'],
        ];
        return $item;
    }

    /**
     * @inheritdoc
     */
    public function controllerNamespace(): string
    {
        if (Craft::$app instanceof \craft\console\Application) {
            return 'zeix\\craftmarkeddown\\console\\controllers';
        }

        return 'zeix\\craftmarkeddown\\controllers';
    }

    public function init(): void
    {
        parent::init();

        // Single registration only — calling attachEventHandlers twice (as v1
        // did, once eager + once on Craft::onInit) double-binds every event
        // listener.
        Craft::$app->onInit(function () {
            $this->attachEventHandlers();
        });
    }

    protected function createSettingsModel(): ?Model
    {
        return Craft::createObject(Settings::class);
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('marked-down/settings', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
        ]);
    }

    private function attachEventHandlers(): void
    {
        // /llms.txt → controller. Static rule, stable.
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules['llms.txt'] = 'marked-down/render/llms-txt';
            }
        );

        // .md URL handling: instead of a catch-all URL rule (which segfaults
        // when combined with a re-entrant parseRequest() inside the controller),
        // detect the suffix BEFORE Craft routes the request. Strip `.md` from
        // pathInfo, set forceMarkdown=true, and let normal routing dispatch to
        // the template renderer. The Response::EVENT_AFTER_PREPARE hook then
        // converts the HTML to Markdown.
        Craft::$app->on(
            \yii\base\Application::EVENT_BEFORE_REQUEST,
            function () {
                $request = Craft::$app->getRequest();
                if ($request->getIsConsoleRequest() || $request->getIsCpRequest()) {
                    return;
                }
                $path = $request->getPathInfo();
                if (!str_ends_with($path, '.md')) {
                    return;
                }
                $newPath = self::resolveMarkdownPath(substr($path, 0, -3));

                // Craft\web\Request::getPathInfo() returns the private _path
                // field, ignoring Yii's _pathInfo. We have to mutate _path
                // directly to make Craft route to the bare URI.
                $ref = new \ReflectionClass(\craft\web\Request::class);
                $prop = $ref->getProperty('_path');
                $prop->setAccessible(true);
                $prop->setValue($request, $newPath);

                $this->forceMarkdown = true;
            }
        );

        Event::on(
            View::class,
            View::EVENT_AFTER_RENDER_TEMPLATE,
            function (TemplateEvent $event) {
                $request = Craft::$app->getRequest();
                if (!$request->getIsConsoleRequest() && !$request->getIsCpRequest()) {
                    $url = $request->getAbsoluteUrl();
                    $cacheKey = 'marked-down-template:' . md5($url);

                    Craft::$app->getCache()->set($cacheKey, $event->template, 60);
                }
            }
        );

        Event::on(
            Response::class,
            Response::EVENT_AFTER_PREPARE,
            function ($event) {
                $plugin = self::getInstance();
                if ($plugin) {
                    $plugin->handleResponse($event);
                }
            }
        );

        // Invalidate this entry's Markdown cache and the /llms.txt cache
        // whenever an entry is saved or deleted. TagDependency on the cache
        // writes means we don't need to enumerate keys — the backend handles
        // it.
        $invalidate = function (ElementEvent $event) {
            $element = $event->element;
            if (!$element instanceof Entry) {
                return;
            }
            TagDependency::invalidate(Craft::$app->getCache(), [
                'marked-down:entry:' . $element->id,
                'marked-down:llms',
            ]);
        };
        Event::on(Elements::class, Elements::EVENT_AFTER_SAVE_ELEMENT, $invalidate);
        Event::on(Elements::class, Elements::EVENT_AFTER_DELETE_ELEMENT, $invalidate);

        // When a section is removed from project config (e.g. via a deploy
        // applying project.yaml from another environment), drop its settings.
        Craft::$app->getProjectConfig()
            ->onRemove('sections.{uid}', function (ConfigEvent $event) {
                $uid = $event->tokenMatches[0] ?? null;
                if (!$uid) {
                    return;
                }
                $plugin = self::getInstance();
                if (!$plugin) {
                    return;
                }
                $settings = $plugin->getSettings();
                if (isset($settings->sectionSettings[$uid])) {
                    unset($settings->sectionSettings[$uid]);
                    Craft::$app->getPlugins()->savePluginSettings($plugin, $settings->toArray());
                }
            });
    }


    /**
     * Handle response and convert to Markdown if needed
     *
     * @param Event $event
     */
    protected function handleResponse(Event $event): void
    {
        $request = Craft::$app->getRequest();
        $response = Craft::$app->getResponse();

        if ($response->format === Response::FORMAT_RAW && isset($response->data) && is_string($response->data)) {
            $dataPreview = substr($response->data, 0, 10);
            if (!str_starts_with($dataPreview, '<')) {
                return;
            }
        }

        $settings = $this->getSettings();

        if (!$settings->enabled) {
            return;
        }

        if ($request->getIsCpRequest()) {
            return;
        }

        $format = $response->format ?? null;
        if ($format === Response::FORMAT_RAW) {
            return;
        }
        if ($format !== Response::FORMAT_HTML && $format !== 'template' && $format !== null) {
            return;
        }

        if (!$this->shouldServeMarkdown($request)) {
            // We're serving HTML — opportunistically inject the discovery link.
            $this->maybeInjectDiscoveryLink($request, $response);
            return;
        }

        if ($this->isExcludedPath($request->getPathInfo())) {
            return;
        }

        if (!$this->isIncludedPath($request->getPathInfo())) {
            return;
        }

        if (!$this->isSectionAllowed()) {
            return;
        }

        try {
            $html = $response->content ?? '';

            if (empty($html) || strlen(trim($html)) < 10) {
                return;
            }

            $url = $request->getAbsoluteUrl();
            $cacheKey = md5($url);

            $templatePath = Craft::$app->getCache()->get('marked-down-template:' . $cacheKey);

            $matched = Craft::$app->getUrlManager()->getMatchedElement();
            $entryId = $matched instanceof Entry ? $matched->id : null;

            // Phase 6: when the matched entry's section has a dedicated
            // Markdown template, render that directly and skip HTML→Markdown
            // conversion entirely.
            $servedVia = 'Markdown-Served';
            $markdown = null;
            $cfg = null;
            if ($matched instanceof Entry) {
                $section = $matched->getSection();
                if ($section) {
                    $cfg = $settings->getSectionSettings($section->uid);
                    if (!empty($cfg->dedicatedTemplate)) {
                        $view = Craft::$app->getView();
                        $markdown = $view->renderTemplate(
                            $cfg->dedicatedTemplate,
                            ['entry' => $matched],
                            $view::TEMPLATE_MODE_SITE
                        );
                        // Twig auto-escapes {{ entry.title }} etc. to HTML
                        // entities; decode them since this is Markdown, not HTML.
                        // (The HTML→Markdown path already decodes in cleanMarkdown.)
                        $markdown = html_entity_decode($markdown, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                        $servedVia = 'Markdown-Served-Template';
                    }
                }
            }

            if ($markdown === null) {
                $markdownService = $this->markdownService;
                $markdown = $markdownService->convertHtmlToMarkdown($html, $cacheKey, $templatePath, $entryId);
            }

            if ($matched instanceof Entry) {
                if ($cfg === null || $cfg->includeFrontMatter) {
                    $frontMatter = $this->frontMatterBuilder->build($matched);
                    if ($frontMatter !== '') {
                        $markdown = $frontMatter . $markdown;
                    }
                }
            }

            $response->content = $markdown;
            $response->format = Response::FORMAT_RAW;

            $response->headers->set('Content-Type', 'text/markdown; charset=UTF-8');
            $response->headers->set('X-Marked-Down', $servedVia);
            // Different headers/UAs produce different bodies; warn shared caches.
            $response->headers->set('Vary', 'Accept, User-Agent');
            if ($settings->noIndexMarkdown) {
                // Keep the Markdown copy out of search results so it doesn't
                // compete with the canonical HTML page.
                $response->headers->set('X-Robots-Tag', 'noindex');
            }
        } catch (\Throwable $e) {
            Craft::error(
                'Marked Down: Failed to convert HTML to Markdown: ' . $e->getMessage(),
                __METHOD__
            );
        }
    }

    /**
     * Check if the request should be served as Markdown
     * Only serves Markdown when Accept: text/markdown header is present
     *
     * @param Request $request
     * @return bool
     */
    protected function shouldServeMarkdown(Request $request): bool
    {
        if ($this->forceMarkdown) {
            return true;
        }

        if ($this->isAiBot($request)) {
            return true;
        }

        $acceptHeader = $request->getHeaders()->get('Accept', '');
        if (empty($acceptHeader) && isset($_SERVER['HTTP_ACCEPT'])) {
            $acceptHeader = $_SERVER['HTTP_ACCEPT'];
        }

        return !empty($acceptHeader) && stripos($acceptHeader, 'text/markdown') !== false;
    }

    public function afterSaveSettings(): void
    {
        parent::afterSaveSettings();
        TagDependency::invalidate(Craft::$app->getCache(), ['marked-down:llms']);
    }

    /**
     * Build the Markdown alternate URL for a page URL of the current site.
     * The site homepage maps to `<base>/index.md`: appending `.md` to the bare
     * host would produce a different domain (`example.ch.md`), and `/.md` is
     * blocked by dotfile rules in common web server configs. Query string and
     * fragment are dropped.
     */
    public static function markdownUrl(string $url): string
    {
        $clean = rtrim((string)strtok($url, '?#'), '/');
        $basePath = trim((string)parse_url((string)Craft::$app->getSites()->getCurrentSite()->getBaseUrl(), PHP_URL_PATH), '/');
        $path = trim((string)parse_url($clean, PHP_URL_PATH), '/');

        return $path === $basePath ? $clean . '/index.md' : $clean . '.md';
    }

    /**
     * Map a path with its `.md` suffix removed back to the routable path.
     * Craft has already removed the site base path, so `index` is the homepage.
     */
    public static function resolveMarkdownPath(string $path): string
    {
        return $path === 'index' ? '' : $path;
    }

    /**
     * On an HTML response, inject <link rel="alternate" type="text/markdown">
     * into <head> so Markdown-aware clients can find the .md alternate.
     */
    protected function maybeInjectDiscoveryLink(Request $request, Response $response): void
    {
        $settings = $this->getSettings();
        if (!$settings->injectDiscoveryLink) {
            return;
        }
        if (!$this->isSectionAllowed()) {
            return;
        }
        if ($this->isExcludedPath($request->getPathInfo())) {
            return;
        }
        if (!$this->isIncludedPath($request->getPathInfo())) {
            return;
        }

        $html = $response->content ?? '';
        if ($html === '' || stripos($html, '</head>') === false) {
            return;
        }

        $mdUrl = self::markdownUrl($request->getAbsoluteUrl());
        $tag = '<link rel="alternate" type="text/markdown" href="'
            . htmlspecialchars($mdUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8')
            . '">';

        // Replace the first </head> only — defensive against literal </head>
        // appearing later in the body.
        $response->content = preg_replace(
            '/<\/head>/i',
            $tag . '</head>',
            $html,
            1
        );
    }

    /**
     * Returns true when the request's User-Agent matches a configured AI bot
     * pattern (case-insensitive substring match).
     */
    public function isAiBot(Request $request): bool
    {
        $ua = $request->getUserAgent();
        if (empty($ua)) {
            return false;
        }

        foreach ($this->getSettings()->aiBotPatterns as $pattern) {
            $pattern = trim($pattern);
            if ($pattern === '') {
                continue;
            }
            if (stripos($ua, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * If the request resolved to an Entry, check the matching section's
     * per-section settings. Returns false to block Markdown for this request.
     *
     * Precedence:
     *  - section disabled         → block
     *  - section is aiOnly        → only AI bots pass; .md URL and Accept
     *                                header are deliberately ignored here
     *                                so a curious human cannot bypass the
     *                                section's intent.
     */
    protected function isSectionAllowed(): bool
    {
        $matched = Craft::$app->getUrlManager()->getMatchedElement();
        if (!$matched instanceof Entry) {
            // Non-entry responses (template-only listings, custom routes) are
            // gated only by the global path filters, not by section settings.
            return true;
        }

        $section = $matched->getSection();
        if (!$section) {
            return true;
        }

        $cfg = $this->getSettings()->getSectionSettings($section->uid);
        if (!$cfg->enabled) {
            return false;
        }

        if ($cfg->aiOnly && !$this->isAiBot(Craft::$app->getRequest())) {
            return false;
        }

        return true;
    }

    /**
     * Check if the path should be excluded from Markdown conversion
     *
     * @param string $pathInfo
     * @return bool
     */
    protected function isExcludedPath(string $pathInfo): bool
    {
        $settings = $this->getSettings();
        $excludePaths = $settings->excludePaths ?? [];

        $pathInfo = '/' . ltrim($pathInfo, '/');

        foreach ($excludePaths as $excludePath) {
            $excludePath = trim($excludePath);
            if (empty($excludePath)) {
                continue;
            }

            $excludePath = '/' . ltrim($excludePath, '/');

            if (str_ends_with($excludePath, '*')) {
                $prefix = rtrim($excludePath, '*');
                if (str_starts_with($pathInfo, $prefix)) {
                    return true;
                }
            } else {
                if ($pathInfo === $excludePath || str_starts_with($pathInfo, $excludePath . '/')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if the path should be included (if include-only list is configured)
     *
     * @param string $pathInfo
     * @return bool
     */
    protected function isIncludedPath(string $pathInfo): bool
    {
        $settings = $this->getSettings();
        $includeOnlyPaths = $settings->includeOnlyPaths;

        if ($includeOnlyPaths === null || empty($includeOnlyPaths)) {
            return true;
        }

        $pathInfo = '/' . ltrim($pathInfo, '/');

        foreach ($includeOnlyPaths as $includePath) {
            $includePath = trim($includePath);
            if (empty($includePath)) {
                continue;
            }

            $includePath = '/' . ltrim($includePath, '/');

            if (str_ends_with($includePath, '*')) {
                $prefix = rtrim($includePath, '*');
                if (str_starts_with($pathInfo, $prefix)) {
                    return true;
                }
            } else {
                if ($pathInfo === $includePath || str_starts_with($pathInfo, $includePath . '/')) {
                    return true;
                }
            }
        }

        return false;
    }
}
