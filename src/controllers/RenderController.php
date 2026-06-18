<?php

namespace zeix\craftmarkeddown\controllers;

use Craft;
use craft\web\Controller;
use yii\caching\TagDependency;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use zeix\craftmarkeddown\MarkedDown;

/**
 * Serves /llms.txt.
 *
 * The `.md` URL suffix is handled in MarkedDown::attachEventHandlers() via
 * Application::EVENT_BEFORE_REQUEST — it mutates the request path before
 * Craft routes, so no controller action is involved.
 */
class RenderController extends Controller
{
    protected array|bool|int $allowAnonymous = true;

    public $enableCsrfValidation = false;

    public function actionLlmsTxt(): Response
    {
        $plugin = MarkedDown::getInstance();
        if (!$plugin || !$plugin->getSettings()->enabled || !$plugin->getSettings()->llmsTxtEnabled) {
            throw new NotFoundHttpException();
        }

        $site = Craft::$app->getSites()->getCurrentSite();
        $cacheKey = 'marked-down:llms.txt:site:' . $site->id;
        $cache = Craft::$app->getCache();
        $cached = $cache->get($cacheKey);

        if ($cached === false) {
            $cached = $plugin->llmsTxtService->build();
            $ttl = $plugin->getSettings()->enableCache
                ? $plugin->getSettings()->cacheDuration
                : 0;
            if ($ttl > 0) {
                $dependency = new TagDependency([
                    'tags' => ['marked-down', 'marked-down:llms'],
                ]);
                $cache->set($cacheKey, $cached, $ttl, $dependency);
            }
        }

        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_RAW;
        $response->content = $cached;
        $response->headers->set('Content-Type', 'text/plain; charset=UTF-8');
        $response->headers->set('X-Marked-Down', 'llms.txt');
        if ($plugin->getSettings()->noIndexMarkdown) {
            $response->headers->set('X-Robots-Tag', 'noindex');
        }

        return $response;
    }
}
