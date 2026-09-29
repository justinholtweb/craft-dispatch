<?php

namespace justinholtweb\dispatch\services;

use Craft;
use craft\base\Component;
use justinholtweb\dispatch\models\Edition;

/**
 * Email delivery.
 *
 * Dispatch sends through Craft's mailer, so the transport — SES, Mailgun, Postmark, SendGrid, SMTP —
 * is configured in Settings → Email with Craft's own adapters. Until 5.1 this service stored
 * per-provider API keys of its own that nothing ever used; they were removed.
 */
class Transports extends Component
{
    /**
     * Sends a test email to the current user through Craft's configured transport.
     *
     * @return array{success: bool, message: string}
     */
    public function testConnection(): array
    {
        Edition::requiresLite('Transport testing');

        try {
            $user = Craft::$app->getUser()->getIdentity();
            if (!$user) {
                return ['success' => false, 'message' => 'No user logged in.'];
            }

            $sent = Craft::$app->getMailer()->compose()
                ->setTo($user->email)
                ->setSubject('Dispatch Transport Test')
                ->setHtmlBody('<p>This is a test email from Dispatch to verify your email transport configuration.</p>')
                ->setTextBody('This is a test email from Dispatch to verify your email transport configuration.')
                ->send();

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
