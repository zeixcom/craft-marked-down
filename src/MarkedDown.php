<?php

namespace zeix\craftmarkeddown;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\events\TemplateEvent;
use craft\web\Request;
use craft\web\Response;
use craft\web\View;
use yii\base\Event;
use yii\web\Application;
use zeix\craftmarkeddown\models\Settings;
use zeix\craftmarkeddown\services\MarkdownService;

/**
 * Marked Down plugin
 *
 * @method static MarkedDown getInstance()
 * @method Settings getSettings()
 * @method MarkdownService getMarkdownService()
 * @author zeix <webmaster@zeix.com>
 * @copyright zeix
 * @license MIT
 */
class MarkedDown extends Plugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;

    public static function config(): array
    {
        return [
            'components' => [
                'markdownService' => MarkdownService::class,
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

        $this->attachEventHandlers();

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
            return;
        }

        if ($this->isExcludedPath($request->getPathInfo())) {
            return;
        }

        if (!$this->isIncludedPath($request->getPathInfo())) {
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

            $markdownService = $this->markdownService;
            $markdown = $markdownService->convertHtmlToMarkdown($html, $cacheKey, $templatePath);

            $response->content = $markdown;
            $response->format = Response::FORMAT_RAW;

            $response->headers->set('Content-Type', 'text/markdown; charset=UTF-8');
            $response->headers->set('X-Marked-Down', 'Markdown-Served');
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
        $acceptHeader = $request->getHeaders()->get('Accept', '');
        if (empty($acceptHeader)) {
            $acceptHeader = $request->headers->get('Accept', '');
        }
        if (empty($acceptHeader) && isset($_SERVER['HTTP_ACCEPT'])) {
            $acceptHeader = $_SERVER['HTTP_ACCEPT'];
        }

        return !empty($acceptHeader) && stripos($acceptHeader, 'text/markdown') !== false;
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
