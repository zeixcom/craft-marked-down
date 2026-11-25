<?php

namespace zeix\craftmarkeddown\migrations;

use Craft;
use craft\db\Migration;
use zeix\craftmarkeddown\models\SectionSettings;
use zeix\craftmarkeddown\MarkedDown;

/**
 * Sections used to be enabled unless configured otherwise. New sections now
 * start disabled, so existing sections without stored settings are written
 * out as enabled to keep serving Markdown after the update.
 */
class m261125_100000_enable_existing_sections extends Migration
{
    public function safeUp(): bool
    {
        // Project config is read-only here. The environment that allows admin
        // changes writes these entries, and they reach this one via project.yaml.
        if (!Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            return true;
        }

        $plugin = MarkedDown::getInstance();
        if (!$plugin) {
            return true;
        }

        $settings = $plugin->getSettings();
        $changed = false;
        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            if (isset($settings->sectionSettings[$section->uid])) {
                continue;
            }
            $model = new SectionSettings();
            $model->enabled = true;
            $settings->sectionSettings[$section->uid] = $model;
            $changed = true;
        }

        if ($changed) {
            Craft::$app->getPlugins()->savePluginSettings($plugin, $settings->toArray());
        }

        return true;
    }

    public function safeDown(): bool
    {
        return true;
    }
}
