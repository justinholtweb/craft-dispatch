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

    /**
     * Let outgoing webhooks (Integrations, Pro) reach private and loopback addresses. Off: an
     * endpoint URL typed into the CP must resolve to public addresses only. Set it in
     * `config/dispatch.php` — deliberately not on the settings screen.
     */
    public bool $allowPrivateIntegrationHosts = false;

    /** Delivery log entries for outgoing webhooks are kept this many days. */
    public int $integrationLogDays = 30;

    /**
     * Sign-up forms (5.3): a new address is emailed a confirmation link and only becomes a
     * subscriber once it is used. Off: it is subscribed straight away (single opt-in).
     */
    public bool $doubleOptIn = true;

    /** How long a confirmation link works, in hours. */
    public int $confirmationExpiryHours = 48;

    /** The confirmation email's subject. `{siteName}` and `{lists}` are filled in. */
    public string $confirmationSubject = 'Please confirm your subscription to {siteName}';

    /**
     * The confirmation email's text. Plain text, not Twig: `{link}`, `{siteName}`, `{lists}` and
     * `{hours}` are filled in, and `{link}` has to be there.
     */
    public string $confirmationBody = "Thanks for signing up to {lists} from {siteName}.\n\nPlease confirm your address by opening this link:\n\n{link}\n\nThe link works for {hours} hours. If you didn't sign up, ignore this email and you won't hear from us again.";

    /**
     * A site template for the confirmation page, instead of Dispatch's own plain one. It gets
     * `state` (`ask`, `confirmed` or `invalid`), `code`, `lists` and `siteName`.
     */
    public string $confirmationTemplate = '';

    /**
     * The craft-lock purpose a confirmed sign-up's consent is recorded under, when Lock is
     * installed. Blank to not record it there.
     */
    public string $lockPurpose = 'marketing';

    /** Sign-ups one address may submit in a minute (the whole site gets 20 times this). */
    public int $signupsPerMinute = 5;

    /** Setting names an admin may change from the settings screens. */
    public const EDITABLE = [
        'defaultFromName', 'defaultFromEmail', 'defaultReplyToEmail', 'defaultTemplateLayout',
        'sendBatchSize', 'sendRateLimit', 'enableTracking', 'trackOpens', 'trackClicks',
        'unsubscribeUrl', 'userGroupSync', 'apiKey', 'mailgunWebhookSigningKey',
        'postmarkWebhookUsername', 'postmarkWebhookPassword', 'sendgridWebhookPublicKey', 'sesTopicArn',
        'doubleOptIn', 'confirmationExpiryHours', 'confirmationSubject', 'confirmationBody',
        'confirmationTemplate', 'lockPurpose', 'signupsPerMinute',
    ];

    public function defineRules(): array
    {
        return [
            [['defaultFromName', 'defaultFromEmail', 'defaultReplyToEmail'], 'string'],
            [['defaultFromEmail', 'defaultReplyToEmail'], 'email', 'skipOnEmpty' => true],
            [['sendBatchSize'], 'integer', 'min' => 1, 'max' => 500],
            [['sendRateLimit'], 'integer', 'min' => 0],
            [['enableTracking', 'trackOpens', 'trackClicks', 'allowPrivateIntegrationHosts'], 'boolean'],
            [['integrationLogDays'], 'integer', 'min' => 1],
            [['doubleOptIn'], 'boolean'],
            [['confirmationExpiryHours'], 'integer', 'min' => 1, 'max' => 720],
            [['signupsPerMinute'], 'integer', 'min' => 1, 'max' => 1000],
            [['confirmationSubject'], 'string', 'max' => 255],
            [['confirmationBody'], 'string', 'max' => 5000],
            [['confirmationBody'], function(string $attribute) {
                if (trim($this->confirmationBody) !== '' && !str_contains($this->confirmationBody, '{link}')) {
                    $this->addError($attribute, \Craft::t('dispatch', 'The email has to include {link}, or nobody can confirm.', ['link' => '{link}']));
                }
            }],
            [['confirmationTemplate'], 'string', 'max' => 500],
            [['lockPurpose'], 'match', 'pattern' => '/^[A-Za-z0-9_-]{0,64}$/'],
            [['apiKey', 'mailgunWebhookSigningKey', 'postmarkWebhookUsername', 'postmarkWebhookPassword', 'sendgridWebhookPublicKey', 'sesTopicArn'], 'string'],
        ];
    }

    /** A setting's value with any `$ENV_VAR` / `@alias` resolved. */
    public function resolved(string $name): string
    {
        return trim((string)App::parseEnv((string)($this->$name ?? '')));
    }
}
