<?php

namespace justinholtweb\dispatch\migrations;

use Craft;
use craft\db\Migration;

/**
 * Scrubs the unused transport credentials from project config, and gives the REST API its own key.
 *
 * - `transportType` / `transportSettings` held SES, Mailgun, Postmark and SendGrid API keys that
 *   Dispatch never used — mail always went through Craft's mailer — written into committed
 *   project config YAML. They are removed.
 * - `webhookSecret` doubled as the REST API bearer token (the webhook check it was meant for is
 *   gone: no provider sent the header it expected). Its value moves to the new `apiKey` setting so
 *   existing API clients keep working; switch it to an environment variable reference.
 *
 * Only runs where project config is writable; elsewhere the change arrives with the YAML.
 */
class m260929_000000_move_secrets_out_of_transport_settings extends Migration
{
    public function safeUp(): bool
    {
        $projectConfig = Craft::$app->getProjectConfig();

        if ($projectConfig->getIsApplyingExternalChanges() || $projectConfig->readOnly) {
            return true;
        }

        $path = 'plugins.dispatch.settings';
        $settings = $projectConfig->get($path);

        if (!is_array($settings)) {
            return true;
        }

        $changed = false;

        if (!empty($settings['webhookSecret']) && empty($settings['apiKey'])) {
            $settings['apiKey'] = $settings['webhookSecret'];
            $changed = true;
        }

        foreach (['transportType', 'transportSettings', 'webhookSecret'] as $key) {
            if (array_key_exists($key, $settings)) {
                unset($settings[$key]);
                $changed = true;
            }
        }

        if ($changed) {
            $projectConfig->set($path, $settings, 'Remove unused Dispatch transport credentials');
        }

        return true;
    }

    public function safeDown(): bool
    {
        // The removed credentials were never used and are not restored.
        return true;
    }
}
