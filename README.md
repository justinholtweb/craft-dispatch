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
| Custom subscriber fields | ✓ | ✓ | ✓ |
| Scheduled sending | ✓ | ✓ | ✓ |
| Drip sequences / automation | — | — | ✓ |
| A/B subject line testing | — | — | ✓ |
| Dynamic segments | — | — | ✓ |
| Webhook integrations (outgoing) | — | — | ✓ |
| Bounce & complaint webhooks (incoming) | — | — | ✓ |
| REST API | — | — | ✓ |

## Requirements

- Craft CMS 5.9+
- PHP 8.2+

## Installation

```bash
composer require justinholtweb/craft-dispatch
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
    // Pro: outgoing webhooks
    'allowPrivateIntegrationHosts' => false,
    'integrationLogDays' => 30,
];
```

## Usage

### Subscribers

Subscribers are a custom element type with full field layout support. Create them via the CP or programmatically:

```php
use justinholtweb\dispatch\elements\Subscriber;
use justinholtweb\dispatch\Plugin;

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
use justinholtweb\dispatch\elements\MailingList;

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

Schedule one for later — the scheduler (below) starts it at that time:

```php
Plugin::getInstance()->campaigns->schedule($campaignId, new DateTime('2026-11-01 09:00'));
```

### Custom subscriber fields

Add fields to subscribers under **Dispatch → Settings → Subscriber Fields** (admins, stored in project config). They appear on the subscriber edit page, can be used in campaign Twig as `{{ subscriber.yourFieldHandle }}`, and each one becomes a rule in the segment builder.

### CSV Import

Upload a CSV with at minimum an `email` column. Optional columns: `firstName` (or `first_name`), `lastName` (or `last_name`). Imports are processed in the background via the queue.

### Unsubscribe

Every email includes `List-Unsubscribe` and `List-Unsubscribe-Post` headers per RFC 8058, enabling one-click unsubscribe in supporting email clients. A subscriber preference center is also available at `/dispatch/preferences`.

## The scheduler

Scheduled campaigns, A/B test winners and drip-sequence emails all fall due at a time. Dispatch notices on its own: whenever something is given a due time it puts a delayed job on Craft's queue for that moment, and that job runs everything due and queues the next one. Nothing is ever sent twice — each item is claimed with a conditional database update before it goes out — so overlapping runs are harmless.

If your queue only runs on web requests (Craft's default), a quiet site may not process the job on time. Run the scheduler from cron as well:

```
* * * * * php /path/to/craft dispatch/scheduler/run
```

## Dynamic segments (Pro)

A segment is a saved set of rules built with Craft's condition builder, under **Dispatch → Segments**:

- **Mailing list** — on (or not on) any of the chosen lists; add the rule twice for "on A but not on B"
- **Signup date**, **Email** (e.g. ends with `@example.com`), Craft's own **Status** and **Date Created**
- **Opened campaign** / **Clicked campaign** — any of the chosen campaigns, or *any campaign*; "is not one of → any campaign" finds people who never opened anything
- **Last opened or clicked** — a date range, or *is empty* for people who never engaged
- one rule per **custom subscriber field**

Pick a segment on a campaign to send only to the subscribers who match — within the campaign's list, or, with no list, everyone the segment matches. The rules are evaluated when the campaign sends, and only active subscribers are ever mailed. The same rules are available as filters on the Subscribers index.

```php
$segment = Plugin::getInstance()->segments->getByHandle('engagedCustomers');
$subscribers = Plugin::getInstance()->segments->query($segment, activeOnly: true)->all();
```

A campaign aimed at a segment is not sent on a site that is no longer Pro (rather than going to the whole list).

## A/B subject line testing (Pro)

Turn on **Test subject lines** on a campaign, add one or more alternative subjects, and choose the test group size (default 20%), how long to wait (default 4 hours) and whether the winner is picked by **open rate** or **click rate**. When you send:

1. The test group — chosen at random from the audience — is split evenly across the subjects.
2. After the wait, the subject with the best unique open (or click) rate among its test recipients wins. Ties go to the other metric, then to the original subject.
3. Everyone else in the audience — including anyone who joined during the wait — is sent the winning subject.

Results (sent, opened, clicked and rates per subject, with the winner marked) are on the campaign. Open rates need open tracking, and some mail apps open every message automatically; click rate is the sturdier measure when the email has links.

## Drip sequences (Pro)

A sequence sends a series of campaigns to each subscriber, each a set delay after the last. Set them up under **Dispatch → Sequences**:

- **Trigger:** a subscriber *joins a mailing list*, or a *custom event* fires.
- **Steps:** a campaign (its subject and body are the email) and a wait — minutes, hours or days after the previous step (or after enrolment, for the first).

Fire a custom event from your own module or plugin — e.g. after a Commerce order:

```php
use justinholtweb\dispatch\Plugin;

Plugin::getInstance()->sequences->fire('order.completed', $order->email); // or a Subscriber / ID
```

…or from outside Craft with the REST API's `event` endpoint. Register your event names so the sequence editor lists them:

```php
use justinholtweb\dispatch\events\RegisterTriggerEventsEvent;
use justinholtweb\dispatch\services\Sequences;

Event::on(Sequences::class, Sequences::EVENT_REGISTER_TRIGGER_EVENTS, function(RegisterTriggerEventsEvent $e) {
    $e->events['order.completed'] = 'Commerce order completed';
});
```

How it behaves:

- A subscriber is enrolled in a sequence **once, ever** — rejoining the list doesn't restart it.
- Each enrolment's steps are scheduled when it starts. Editing a sequence's steps applies to people enrolled afterwards; removing a step cancels it for everyone.
- **Unsubscribing, bouncing or complaining stops** every sequence for that subscriber; leaving the trigger list stops that list's sequences. Their status is checked again just before each email.
- A step is **never sent twice**, and a subscriber is never sent a campaign they already received (from the sequence or a regular send) — that step is skipped.
- Turning a sequence off pauses it; turning it back on sends whatever fell due meanwhile. On a site that is no longer Pro, due steps wait rather than send.
- Step emails carry the usual unsubscribe link and headers, and are tracked and reported on their campaign like any send.

`Sequences::EVENT_BEFORE_ENROL` (cancelable) and `EVENT_AFTER_ENROL` let you veto or react to enrolments.

## Integrations — outgoing webhooks (Pro)

Under **Dispatch → Integrations** (admins), add endpoints that Dispatch POSTs to when things happen:

| Event | When |
|---|---|
| `subscriber.subscribed` | A subscriber joins a list |
| `subscriber.unsubscribed` | A subscriber leaves a list, or everything |
| `subscriber.bounced` | A bounce webhook marks them bounced |
| `subscriber.complained` | A complaint webhook marks them complained |
| `campaign.sent` | A campaign finishes sending |
| `email.opened` | A campaign email is opened (every open) |
| `email.clicked` | A tracked link is clicked |

The body is JSON — `{"id": "<delivery uuid>", "event": "subscriber.subscribed", "createdAt": "…", "data": {"subscriber": {…}, "list": {…}}}` — with these headers:

- `X-Dispatch-Signature: t=<unix time>,v1=<hex HMAC-SHA256 of "<t>.<raw body>" with the endpoint's secret>`
- `X-Dispatch-Event`, and `X-Dispatch-Delivery` (the same across retries — use it to deduplicate)

Verify it on your side:

```php
[$t, $v1] = sscanf($_SERVER['HTTP_X_DISPATCH_SIGNATURE'], 't=%d,v1=%s');
$ok = abs(time() - $t) < 300 && hash_equals(hash_hmac('sha256', $t . '.' . file_get_contents('php://input'), $secret), $v1);
```

Delivery is a queue job. A network error or non-2xx response is retried after 1 minute, 5 minutes, 30 minutes and 2 hours, then marked failed; each endpoint's page shows its recent deliveries, with **Redeliver** for failed ones and **Send test** for a signed `ping`. The log is trimmed after `integrationLogDays` (30).

Endpoint URLs must resolve to **public** addresses: loopback, private, link-local (cloud metadata) and other reserved ranges are refused, the connection is pinned to the checked address, and redirects are not followed. To post to an internal service, set `'allowPrivateIntegrationHosts' => true` in `config/dispatch.php`. The URL and secret accept environment variables.

`Integrations::EVENT_BEFORE_DISPATCH` lets you change an event's payload, or cancel it.

## Tracking (Lite+)

When enabled, Dispatch injects a 1×1 tracking pixel for opens and rewrites links for click tracking. All tracking URLs are HMAC-signed to prevent spoofing. View results on the **Dashboard** tab.

## Sending

Dispatch sends through Craft's mailer. Choose your provider — Amazon SES, Mailgun, Postmark, SendGrid or SMTP — under Craft's **Settings → Email**, where the API key can come from an environment variable.

## Bounce & complaint webhooks (Pro)

Dispatch accepts inbound webhooks for bounce and complaint processing (for outgoing webhooks, see Integrations above):

```
POST /dispatch/webhook/ses
POST /dispatch/webhook/mailgun
POST /dispatch/webhook/postmark
POST /dispatch/webhook/sendgrid
```

Each provider is verified with its own scheme, and its webhook is refused until the credential is set under **Dispatch → Settings → Delivery & Webhooks**:

| Provider | Credential |
|---|---|
| Mailgun | Webhook signing key (HMAC-SHA256, stale and replayed requests refused) |
| Postmark | Basic-auth username and password, put in the webhook URL (`https://USER:PASS@…`) |
| SendGrid | Signed Event Webhook verification key |
| Amazon SES | The SNS topic ARN — subscribe the URL to the topic; Dispatch confirms the subscription |

All of these accept environment variables (`$MAILGUN_WEBHOOK_KEY`); use them, since plugin settings are stored in project config.

## REST API (Pro)

Authenticate with a Bearer token — the **API key** under Delivery & Webhooks (env-able; empty turns the API off):

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
| POST | `/dispatch/api/v1/event` | Fire a drip-sequence trigger (`email`, `event`) for an existing subscriber |

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
| `dispatch:manageSettings` | View plugin settings (saving needs an admin) |
| `dispatch:manageSegments` | Create/edit/delete segments (Pro) |
| `dispatch:manageSequences` | Create/edit/delete drip sequences (Pro) |

Integrations and the subscriber field layout are admin-only.

## Events

Dispatch fires events you can listen to in a custom module or plugin:

```php
use justinholtweb\dispatch\elements\Campaign;
use justinholtweb\dispatch\events\CampaignEvent;
use justinholtweb\dispatch\events\SubscriberEvent;
use justinholtweb\dispatch\events\TrackingEvent;
use justinholtweb\dispatch\services\AbTesting;
use justinholtweb\dispatch\services\Subscribers;
use justinholtweb\dispatch\services\Tracker;
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

// A subscriber joined a list / left one (mailingList null = every list)
Event::on(Subscribers::class, Subscribers::EVENT_AFTER_SUBSCRIBE, function (SubscriberEvent $event) {
    // $event->subscriber, $event->mailingList
});
Event::on(Subscribers::class, Subscribers::EVENT_AFTER_UNSUBSCRIBE, function (SubscriberEvent $event) {});

// An A/B test's winner was picked (Pro)
Event::on(AbTesting::class, AbTesting::EVENT_AFTER_PICK_WINNER, function (CampaignEvent $event) {
    // $event->campaign->abWinner
});
```

Also `Tracker::EVENT_ON_BOUNCE` / `EVENT_ON_COMPLAINT`, and the sequence and integration events above.

## License

This plugin is released under the [Craft License](https://craftcms.github.io/license/).
