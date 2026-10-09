<?php

namespace justinholtweb\dispatch\models;

use craft\base\Model;
use craft\helpers\App;

/**
 * An outgoing webhook endpoint (Pro, "Integrations"): a URL that is POSTed a signed JSON body
 * when the chosen Dispatch events happen. Unrelated to the incoming bounce webhooks.
 */
class Endpoint extends Model
{
    public const EVENT_SUBSCRIBED = 'subscriber.subscribed';
    public const EVENT_UNSUBSCRIBED = 'subscriber.unsubscribed';
    public const EVENT_BOUNCED = 'subscriber.bounced';
    public const EVENT_COMPLAINED = 'subscriber.complained';
    public const EVENT_CAMPAIGN_SENT = 'campaign.sent';
    public const EVENT_OPENED = 'email.opened';
    public const EVENT_CLICKED = 'email.clicked';

    public const EVENTS = [
        self::EVENT_SUBSCRIBED,
        self::EVENT_UNSUBSCRIBED,
        self::EVENT_BOUNCED,
        self::EVENT_COMPLAINED,
        self::EVENT_CAMPAIGN_SENT,
        self::EVENT_OPENED,
        self::EVENT_CLICKED,
    ];

    public ?int $id = null;
    public string $name = '';
    public string $url = '';
    /** HMAC signing secret. May be an environment variable reference (`$DISPATCH_HOOK_SECRET`). */
    public string $secret = '';
    /** @var string[] */
    public array $events = [];
    public bool $enabled = true;
    public ?string $uid = null;

    public function resolvedSecret(): string
    {
        return trim((string)App::parseEnv($this->secret));
    }

    public function resolvedUrl(): string
    {
        return trim((string)App::parseEnv($this->url));
    }

    protected function defineRules(): array
    {
        return [
            [['name', 'url', 'secret'], 'required'],
            [['name', 'secret'], 'string', 'max' => 255],
            [['url'], 'string', 'max' => 2048],
            [['url'], 'validateUrl'],
            [['events'], 'validateEvents', 'skipOnEmpty' => false],
        ];
    }

    public function validateUrl(string $attribute): void
    {
        $url = $this->resolvedUrl();
        $parts = parse_url($url);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));

        if (!in_array($scheme, ['http', 'https'], true) || empty($parts['host'])) {
            $this->addError($attribute, \Craft::t('dispatch', 'Enter an http:// or https:// URL.'));
        } elseif (isset($parts['user']) || isset($parts['pass'])) {
            $this->addError($attribute, \Craft::t('dispatch', 'The URL may not carry a username or password.'));
        }
    }

    public function validateEvents(string $attribute): void
    {
        $this->events = array_values(array_intersect(self::EVENTS, $this->events));

        if ($this->events === []) {
            $this->addError($attribute, \Craft::t('dispatch', 'Choose at least one event.'));
        }
    }
}
