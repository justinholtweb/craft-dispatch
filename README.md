# Dispatch — Email Marketing for Craft CMS

Lightweight email marketing and newsletter plugin for Craft CMS 5. Manage subscribers, send campaigns, and track delivery — all natively within your control panel.

## Editions

| Feature | Free | Lite ($49) | Pro ($79) |
|---|:---:|:---:|:---:|
| Subscriber management | ✓ | ✓ | ✓ |
| Mailing lists | 1 | Unlimited | Unlimited |
| CSV import/export | ✓ | ✓ | ✓ |
| Queue-based sending | 100/mo | Unlimited | Unlimited |
| Twig email templates | ✓ | ✓ | ✓ |
| Unsubscribe handling (RFC 8058) | ✓ | ✓ | ✓ |
| Basic send tracking | ✓ | ✓ | ✓ |
| Craft User sync | — | ✓ | ✓ |
| Open/click analytics | — | ✓ | ✓ |
| Delivery dashboard | — | ✓ | ✓ |
| Custom transports (SES, Mailgun, etc.) | — | ✓ | ✓ |
| Drip sequences / automation | — | — | ✓ |
| A/B subject line testing | — | — | ✓ |
| Dynamic segments | — | — | ✓ |
| Webhook integrations | — | — | ✓ |
| REST API | — | — | ✓ |

## Requirements

- Craft CMS 5.0+
- PHP 8.2+

## Installation

```bash
composer require jholt/craft-dispatch
php craft plugin/install dispatch
```

## Configuration

All settings are available in the control panel under **Dispatch → Settings**, or you can configure them via `config/dispatch.php`:

```php
<?php

return [
    'defaultFromName' => 'My Site',
    'defaultFromEmail' => 'hello@example.com',
    'defaultReplyToEmail' => '',
    'sendBatchSize' => 50,
    'sendRateLimit' => 0,
    'enableTracking' => true,
    'trackOpens' => true,
    'trackClicks' => true,
];
```

## Usage

### Subscribers

Subscribers are a custom element type with full field layout support. Create them via the CP or programmatically:

```php
use jholt\dispatch\elements\Subscriber;
use jholt\dispatch\Plugin;

$subscriber = new Subscriber();
$subscriber->email = 'user@example.com';
$subscriber->firstName = 'Jane';
$subscriber->lastName = 'Doe';
Craft::$app->getElements()->saveElement($subscriber);

// Add to a mailing list
Plugin::getInstance()->subscribers->subscribe($subscriber->id, $listId);
```

### Mailing Lists

```php
use jholt\dispatch\elements\MailingList;

$list = new MailingList();
$list->name = 'Weekly Newsletter';
$list->handle = 'weeklyNewsletter';
Plugin::getInstance()->lists->create($list);
```

### Campaigns

Campaigns use Twig templates for email content. The template receives `campaign`, `subscriber`, and `unsubscribeUrl` variables:

```twig
{# Your email template #}
<h1>{{ campaign.subject }}</h1>
<p>Hi {{ subscriber.firstName ?? 'there' }},</p>
{{ content|raw }}
<p><a href="{{ unsubscribeUrl }}">Unsubscribe</a></p>
```

Send a campaign programmatically:

```php
Plugin::getInstance()->campaigns->send($campaignId);
```

### CSV Import

Upload a CSV with at minimum an `email` column. Optional columns: `firstName` (or `first_name`), `lastName` (or `last_name`). Imports are processed in the background via the queue.

### Unsubscribe

Every email includes `List-Unsubscribe` and `List-Unsubscribe-Post` headers per RFC 8058, enabling one-click unsubscribe in supporting email clients. A subscriber preference center is also available at `/dispatch/preferences`.

## Tracking (Lite+)

When enabled, Dispatch injects a 1×1 tracking pixel for opens and rewrites links for click tracking. All tracking URLs are HMAC-signed to prevent spoofing. View results on the **Dashboard** tab.

## Custom Transports (Lite+)

Configure sending via Amazon SES, Mailgun, Postmark, or SendGrid under **Settings → Transport**. The default `craft` transport uses your existing Craft email configuration.

## Webhooks (Pro)

Dispatch accepts inbound webhooks for bounce and complaint processing:

```
POST /dispatch/webhook/ses
POST /dispatch/webhook/mailgun
POST /dispatch/webhook/postmark
POST /dispatch/webhook/sendgrid
```

Set a `webhookSecret` in your settings for HMAC signature verification.

## REST API (Pro)

Authenticate with a Bearer token (using your `webhookSecret`):

```bash
# List subscribers
curl -H "Authorization: Bearer YOUR_TOKEN" https://example.com/dispatch/api/v1/subscribers

# Subscribe
curl -X POST -H "Authorization: Bearer YOUR_TOKEN" \
  -d "email=user@example.com&list=weeklyNewsletter" \
  https://example.com/dispatch/api/v1/subscribe

# Unsubscribe
curl -X POST -H "Authorization: Bearer YOUR_TOKEN" \
  -d "email=user@example.com" \
  https://example.com/dispatch/api/v1/unsubscribe

# Campaign stats
curl -H "Authorization: Bearer YOUR_TOKEN" \
  https://example.com/dispatch/api/v1/stats?campaignId=123
```

### Endpoints

| Method | Endpoint | Description |
|---|---|---|
| GET | `/dispatch/api/v1/subscribers` | List subscribers |
| POST | `/dispatch/api/v1/subscribers` | Create subscriber |
| GET | `/dispatch/api/v1/lists` | List mailing lists |
| GET | `/dispatch/api/v1/campaigns` | List campaigns |
| GET | `/dispatch/api/v1/stats?campaignId=` | Campaign statistics |
| POST | `/dispatch/api/v1/subscribe` | Subscribe an email to a list |
| POST | `/dispatch/api/v1/unsubscribe` | Unsubscribe an email |

## Permissions

| Permission | Description |
|---|---|
| `dispatch:accessPlugin` | Access the Dispatch CP section |
| `dispatch:manageCampaigns` | Create/edit/delete campaigns |
| `dispatch:sendCampaigns` | Trigger campaign sends |
| `dispatch:manageSubscribers` | Create/edit/delete subscribers |
| `dispatch:importSubscribers` | Import subscribers via CSV |
| `dispatch:manageLists` | Create/edit/delete mailing lists |
| `dispatch:viewDashboard` | View analytics dashboard |
| `dispatch:manageSettings` | Modify plugin settings |

## Events

Dispatch fires events you can listen to in a custom module or plugin:

```php
use jholt\dispatch\elements\Campaign;
use jholt\dispatch\events\CampaignEvent;
use jholt\dispatch\events\SubscriberEvent;
use jholt\dispatch\events\TrackingEvent;
use jholt\dispatch\services\Tracker;
use yii\base\Event;

// Before a campaign sends
Event::on(Campaign::class, 'beforeSend', function (CampaignEvent $event) {
    // $event->campaign
});

// After a campaign sends
Event::on(Campaign::class, 'afterSend', function (CampaignEvent $event) {
    // $event->campaign
});

// On email open (Lite+)
Event::on(Tracker::class, Tracker::EVENT_ON_OPEN, function (TrackingEvent $event) {
    // $event->campaignId, $event->subscriberId
});

// On link click (Lite+)
Event::on(Tracker::class, Tracker::EVENT_ON_CLICK, function (TrackingEvent $event) {
    // $event->campaignId, $event->subscriberId, $event->url
});
```

## License

This plugin is proprietary software. See edition licensing for details.
