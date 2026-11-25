<?php

namespace zeix\craftmarkeddown\migrations;

use Craft;
use craft\db\Migration;
use craft\helpers\ProjectConfig as ProjectConfigHelper;

/**
 * Sections used to be enabled unless configured otherwise. New sections now
 * start disabled, so existing sections without stored settings are written
 * out as enabled to keep serving Markdown after the update.
 */
class m261125_100000_enable_existing_sections extends Migration
{
    private const SETTINGS_PATH = 'plugins.marked-down.settings.sectionSettings';

    public function safeUp(): bool
    {
        $projectConfig = Craft::$app->getProjectConfig();

        // With admin changes off, project config is read-only. With changes
        // pending, an incoming project.yaml already holds these entries and
        // may set some sections to disabled on purpose.
        if (!Craft::$app->getConfig()->getGeneral()->allowAdminChanges || $projectConfig->areChangesPending()) {
            $message = 'Skipped enabling existing sections: project config is read-only or has pending changes. '
                . 'Run `craft up` where admin changes are allowed and deploy project.yaml, or sections stay off.';
            Craft::warning($message, __METHOD__);
            echo "    > $message\n";
            return true;
        }

        // Read the stored value, not the plugin model. The model merges in
        // config/marked-down.php overrides, which must not reach project config.
        // get() returns the packed form, so unpack it to look sections up by UID.
        $stored = ProjectConfigHelper::unpackAssociativeArray($projectConfig->get(self::SETTINGS_PATH) ?? []);
        $changed = false;
        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            if (isset($stored[$section->uid])) {
                continue;
            }
            $stored[$section->uid] = [
                'enabled' => true,
                'aiOnly' => false,
                'dedicatedTemplate' => null,
                'includeFrontMatter' => true,
            ];
            $changed = true;
        }

        if ($changed) {
            $projectConfig->set(
                self::SETTINGS_PATH,
                ProjectConfigHelper::packAssociativeArray($stored),
                'Enable existing sections for Marked Down'
            );
        }

        return true;
    }

    public function safeDown(): bool
    {
        return true;
    }
}
