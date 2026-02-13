<?php

namespace justinholtweb\dispatch\services;

use Craft;
use craft\base\Component;
use justinholtweb\dispatch\models\Edition;
use justinholtweb\dispatch\Plugin;

class Transports extends Component
{
    public function getAvailableTransports(): array
    {
        $transports = [
            'craft' => [
                'name' => 'Craft Mailer',
                'description' => 'Use the default Craft CMS email transport.',
                'edition' => 'free',
            ],
        ];

        if (Edition::isLite()) {
            $transports['ses'] = [
                'name' => 'Amazon SES',
                'description' => 'Send via Amazon Simple Email Service.',
                'edition' => 'lite',
            ];
            $transports['mailgun'] = [
                'name' => 'Mailgun',
                'description' => 'Send via Mailgun.',
                'edition' => 'lite',
            ];
            $transports['postmark'] = [
                'name' => 'Postmark',
                'description' => 'Send via Postmark.',
                'edition' => 'lite',
            ];
            $transports['sendgrid'] = [
                'name' => 'SendGrid',
                'description' => 'Send via SendGrid.',
                'edition' => 'lite',
            ];
        }

        return $transports;
    }

    public function configureTransport(string $type, array $settings): bool
    {
        Edition::requiresLite('Custom transport configuration');

        $plugin = Plugin::getInstance();
        $pluginSettings = $plugin->getSettings();
        $pluginSettings->transportType = $type;
        $pluginSettings->transportSettings[$type] = $settings;

        return Craft::$app->getPlugins()->savePluginSettings($plugin, $pluginSettings->toArray());
    }

    public function testConnection(?string $type = null): array
    {
        Edition::requiresLite('Transport testing');

        $settings = Plugin::getInstance()->getSettings();
        $type = $type ?? $settings->transportType;

        try {
            // Send a test email to the current user
            $user = Craft::$app->getUser()->getIdentity();
            if (!$user) {
                return ['success' => false, 'message' => 'No user logged in.'];
            }

            $message = Craft::$app->getMailer()->compose()
                ->setTo($user->email)
                ->setSubject('Dispatch Transport Test')
                ->setHtmlBody('<p>This is a test email from Dispatch to verify your email transport configuration.</p>')
                ->setTextBody('This is a test email from Dispatch to verify your email transport configuration.');

            $sent = $message->send();

            return [
                'success' => $sent,
                'message' => $sent ? 'Test email sent successfully.' : 'Failed to send test email.',
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Transport error: ' . $e->getMessage(),
            ];
        }
    }
}
