<?php

namespace justinholtweb\dispatch\models;

use craft\base\Model;

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
    public string $transportType = 'craft';
    public array $transportSettings = [];
    public string $webhookSecret = '';

    public function defineRules(): array
    {
        return [
            [['defaultFromName', 'defaultFromEmail', 'defaultReplyToEmail'], 'string'],
            [['defaultFromEmail', 'defaultReplyToEmail'], 'email', 'skipOnEmpty' => true],
            [['sendBatchSize'], 'integer', 'min' => 1, 'max' => 500],
            [['sendRateLimit'], 'integer', 'min' => 0],
            [['enableTracking', 'trackOpens', 'trackClicks'], 'boolean'],
            [['transportType'], 'in', 'range' => ['craft', 'ses', 'mailgun', 'postmark', 'sendgrid']],
        ];
    }
}
