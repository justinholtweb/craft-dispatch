<?php

namespace justinholtweb\dispatch\models;

use craft\base\Model;
use craft\helpers\App;

/**
 * Dispatch settings.
 *
 * Every secret here may be an environment variable reference (`$MAILGUN_WEBHOOK_KEY`) and is
 * resolved with App::parseEnv() where it is used — plugin settings live in project config, which
 * is committed, so a literal key saved here ends up in git.
 *
 * Email is sent through Craft's own mailer (Settings → Email), which is where a transport and its
 * API key belong. Dispatch no longer stores transport credentials of its own; 5.1 removed them.
 */
class Settings extends Model
{
    public string $defaultFromName = '';
    public string $defaultFromEmail = '';
    public string $defaultReplyToEmail = '';
    public string $defaultTemplateLayout = '_dispatch/email/_layout';
    public int $sendBatchSize = 50;
    public int $sendRateLimit = 0;
    public bool $enableTracking = true;
    public bool $trackOpens = true;
    public bool $trackClicks = true;
    public string $unsubscribeUrl = '';
    public array $userGroupSync = [];

    /** Bearer token for the REST API (Pro). Env-able. Empty disables the API. */
    public string $apiKey = '';

    /** Mailgun webhook signing key (Mailgun → Sending → Webhooks). Env-able. */
    public string $mailgunWebhookSigningKey = '';

    /** Basic-auth credentials configured on the Postmark webhook URL. Env-able. */
    public string $postmarkWebhookUsername = '';
    public string $postmarkWebhookPassword = '';

    /** SendGrid Signed Event Webhook verification key (base64 public key). Env-able. */
    public string $sendgridWebhookPublicKey = '';

    /** ARN of the SNS topic SES publishes bounces and complaints to. Env-able. */
    public string $sesTopicArn = '';

    /** Setting names an admin may change from the settings screens. */
    public const EDITABLE = [
        'defaultFromName', 'defaultFromEmail', 'defaultReplyToEmail', 'defaultTemplateLayout',
        'sendBatchSize', 'sendRateLimit', 'enableTracking', 'trackOpens', 'trackClicks',
        'unsubscribeUrl', 'userGroupSync', 'apiKey', 'mailgunWebhookSigningKey',
        'postmarkWebhookUsername', 'postmarkWebhookPassword', 'sendgridWebhookPublicKey', 'sesTopicArn',
    ];

    public function defineRules(): array
    {
        return [
            [['defaultFromName', 'defaultFromEmail', 'defaultReplyToEmail'], 'string'],
            [['defaultFromEmail', 'defaultReplyToEmail'], 'email', 'skipOnEmpty' => true],
            [['sendBatchSize'], 'integer', 'min' => 1, 'max' => 500],
            [['sendRateLimit'], 'integer', 'min' => 0],
            [['enableTracking', 'trackOpens', 'trackClicks'], 'boolean'],
            [['apiKey', 'mailgunWebhookSigningKey', 'postmarkWebhookUsername', 'postmarkWebhookPassword', 'sendgridWebhookPublicKey', 'sesTopicArn'], 'string'],
        ];
    }

    /** A setting's value with any `$ENV_VAR` / `@alias` resolved. */
    public function resolved(string $name): string
    {
        return trim((string)App::parseEnv((string)($this->$name ?? '')));
    }
}
